#!/usr/bin/env python3
"""Shared target configuration for experiments against the Contabo deployment.

Cluster access goes through the SSH tunnel described in the README:
    ssh -fN -L 16443:127.0.0.1:6443 contabo-6
    export KUBECONFIG=output/secrets/contabo/kubeconfig

Argo CD self-heal reverts manual drift (kubectl scale / set env) within seconds,
which would undo injected faults. Wrap every fault injection in argocd_paused().
If a run is killed hard, resume manually with: python3 scripts/env.py resume
"""
import contextlib
import json
import os
import pathlib
import subprocess
import sys

ROOT = pathlib.Path(__file__).resolve().parent.parent
KUBECTL = os.environ.get('KUBECTL_BIN', 'kubectl')
KUBECONFIG = os.environ.get('KUBECONFIG', str(ROOT / 'output/secrets/contabo/kubeconfig'))
ENVIRONMENT = 'contabo-6'

RESILIENT = 'midterm'
BASELINE = 'midterm-baseline'
NAMESPACES = (RESILIENT, BASELINE, 'midterm-monitoring', 'midterm-gateway', 'argocd')
BASE = {
    RESILIENT: os.environ.get('MIDTERM_URL', 'https://midterm.sailaubek.dev'),
    BASELINE: os.environ.get('BASELINE_URL', 'https://baseline.sailaubek.dev'),
}
GRAFANA_URL = os.environ.get('GRAFANA_URL', 'https://grafana.sailaubek.dev')
GRAFANA_ENV = ROOT / 'output/secrets/contabo/grafana.env'

OUT = pathlib.Path(os.environ.get('RESULTS_DIR', ROOT / 'output/final'))
OUT.mkdir(parents=True, exist_ok=True)

# Root first: it would otherwise restore the child Applications' sync policy from git.
ARGO_APPS = ('root', 'university', 'university-baseline')
ARGO_STATE = OUT / 'argocd-paused.json'


def kubectl(ns, *args, input=None, timeout=100):
    assert ns in NAMESPACES, ns
    r = subprocess.run([KUBECTL, '--kubeconfig', KUBECONFIG, '-n', ns, *args], input=input, text=True,
                       capture_output=True, timeout=timeout)
    if r.returncode:
        raise RuntimeError(r.stderr[-1000:])
    return r.stdout


def grafana_password():
    return GRAFANA_ENV.read_text().strip().split('=', 1)[1]


def pause_argocd():
    """Disable automated sync on the project's Applications and remember the previous policy."""
    if ARGO_STATE.exists():
        return  # Already paused by an earlier (possibly interrupted) run; keep the original policy.
    saved = {}
    for app in ARGO_APPS:
        spec = json.loads(kubectl('argocd', 'get', 'application', app, '-o', 'json'))['spec']
        saved[app] = spec.get('syncPolicy', {}).get('automated')
    ARGO_STATE.write_text(json.dumps(saved, indent=2) + '\n')
    for app in ARGO_APPS:
        kubectl('argocd', 'patch', 'application', app, '--type', 'merge', '-p',
                json.dumps({'spec': {'syncPolicy': {'automated': None}}}))
    print('argocd: automated sync paused for', ', '.join(ARGO_APPS), flush=True)


def resume_argocd():
    if not ARGO_STATE.exists():
        return
    saved = json.loads(ARGO_STATE.read_text())
    # Children first, then root, so root's next sync sees the policy that git also declares.
    for app in reversed(ARGO_APPS):
        if saved.get(app) is not None:
            kubectl('argocd', 'patch', 'application', app, '--type', 'merge', '-p',
                    json.dumps({'spec': {'syncPolicy': {'automated': saved[app]}}}))
    ARGO_STATE.unlink()
    print('argocd: automated sync restored', flush=True)


@contextlib.contextmanager
def argocd_paused():
    pause_argocd()
    try:
        yield
    finally:
        resume_argocd()


if __name__ == '__main__':
    actions = {'pause': pause_argocd, 'resume': resume_argocd}
    if len(sys.argv) != 2 or sys.argv[1] not in actions:
        sys.exit('usage: python3 scripts/env.py pause|resume')
    actions[sys.argv[1]]()
