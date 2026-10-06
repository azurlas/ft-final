#!/usr/bin/env python3
"""Pause/resume this project's OrbStack workloads without removing stored data."""
import argparse
import fcntl
import json
import os
from pathlib import Path
import shutil
import subprocess
import time

ROOT = Path(__file__).resolve().parent.parent
STATE = ROOT / 'output/deployment-state.json'
NAMESPACES = ('midterm', 'midterm-baseline', 'midterm-monitoring', 'midterm-gateway')
KINDS = 'deployment,statefulset,daemonset,cronjob,job'
BLOCKER = 'midterm.local/deployment-stopped'
KUBECTL = os.environ.get('KUBECTL_BIN', '/Users/nariman/.orbstack/bin/kubectl')
if not shutil.which(KUBECTL):
    KUBECTL = 'kubectl'


def run(args, capture=False, check=True):
    return subprocess.run(args, check=check, text=True, stdout=subprocess.PIPE if capture else None,
                          stderr=subprocess.PIPE if capture else None, timeout=60)


def kube(*args, capture=False):
    return run([KUBECTL, '--context', 'orbstack', '--request-timeout=20s', *args], capture)


def read(*args):
    output = kube(*args, '-o', 'json', capture=True).stdout
    return json.loads(output) if output.strip() else {}


def ensure_cluster(action):
    if run([KUBECTL, '--context', 'orbstack', '--request-timeout=10s', 'get', 'nodes'], True, False).returncode == 0:
        return
    if action == 'stop':
        raise RuntimeError('OrbStack Kubernetes is unavailable; no deployment changes made.')
    previous = run([KUBECTL, 'config', 'current-context'], True, False).stdout.strip()
    try:
        run(['orb', 'start', 'k8s'])
    finally:
        if previous:
            run([KUBECTL, 'config', 'use-context', previous], True)
    kube('get', 'nodes')


def inventory():
    result = []
    for ns in NAMESPACES:
        for item in read('-n', ns, 'get', KINDS, '--ignore-not-found')['items']:
            kind, spec = item['kind'], item['spec']
            if kind == 'Job' and not item.get('status', {}).get('active'):
                continue
            if kind == 'StatefulSet' and spec.get('persistentVolumeClaimRetentionPolicy', {}).get('whenScaled', 'Retain') != 'Retain':
                raise RuntimeError('Refusing to scale a StatefulSet configured to delete PVCs: ' + ns + '/' + item['metadata']['name'])
            record = {'namespace': ns, 'kind': kind, 'name': item['metadata']['name']}
            if kind in ('Deployment', 'StatefulSet'):
                record['replicas'] = spec.get('replicas', 1)
            elif kind == 'DaemonSet':
                record['nodeSelector'] = spec['template']['spec'].get('nodeSelector', {})
            else:
                record['suspend'] = spec.get('suspend', False)
            result.append(record)
    return result


def patch(record, body, patch_type='merge'):
    kube('-n', record['namespace'], 'patch', record['kind'], record['name'], '--type=' + patch_type, '-p', json.dumps(body))


def set_running(record, running):
    if 'replicas' in record:
        kube('-n', record['namespace'], 'scale', record['kind'] + '/' + record['name'], '--replicas=' + str(record['replicas'] if running else 0))
    elif record['kind'] == 'DaemonSet':
        selector = record['nodeSelector'] if running else {**record['nodeSelector'], BLOCKER: 'true'}
        patch(record, [{'op': 'add', 'path': '/spec/template/spec/nodeSelector', 'value': selector}], 'json')
    else:
        patch(record, {'spec': {'suspend': record['suspend'] if running else True}})


def wait_stopped(namespaces, timeout):
    deadline = time.monotonic() + timeout
    while True:
        active = []
        for ns in namespaces:
            active.extend(p['metadata']['name'] for p in read('-n', ns, 'get', 'pods')['items']
                          if p['status']['phase'] not in ('Succeeded', 'Failed'))
        if not active:
            return
        if time.monotonic() >= deadline:
            raise RuntimeError('Shutdown still in progress. Retry make stop; saved resume state is retained. Pods: ' + ', '.join(active))
        time.sleep(2)


def wait_ready(records, timeout):
    deadline = time.monotonic() + timeout
    for record in records:
        if record['kind'] not in ('Deployment', 'StatefulSet', 'DaemonSet'):
            continue
        while True:
            item = read('-n', record['namespace'], 'get', record['kind'], record['name'])
            status = item['status']
            expected = status.get('desiredNumberScheduled', 0) if record['kind'] == 'DaemonSet' else record['replicas']
            ready = status.get('numberReady', 0) if record['kind'] == 'DaemonSet' else status.get('readyReplicas', 0)
            updated = status.get('updatedNumberScheduled', 0) if record['kind'] == 'DaemonSet' else status.get('updatedReplicas', 0)
            if status.get('observedGeneration', 0) >= item['metadata']['generation'] and ready >= expected and updated >= expected:
                break
            if time.monotonic() >= deadline:
                raise RuntimeError('Startup timed out at ' + record['namespace'] + '/' + record['name'] + '; retry make start.')
            time.sleep(2)
        print('Ready: ' + record['namespace'] + '/' + record['name'], flush=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=['start', 'stop'])
    parser.add_argument('--timeout', type=int, default=600, help='Readiness/shutdown timeout in seconds')
    args = parser.parse_args()
    os.chdir(ROOT)
    STATE.parent.mkdir(exist_ok=True)
    with (STATE.parent / '.deployment.lock').open('w') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        ensure_cluster(args.action)
        if STATE.exists():
            records = json.loads(STATE.read_text())
        elif args.action == 'start':
            installed = read('-n', 'midterm', 'get', 'deployment', 'api', '--ignore-not-found')
            if not installed:
                # Initial installation/build remains in the existing Helm setup workflow.
                subprocess.run(['bash', 'scripts/setup-local.sh'], check=True)
                return
            records = inventory()
        else:
            records = inventory()
            if not records:
                print('No project deployment is installed.')
                return
            # Persist before the first mutation; repeated stop never overwrites original counts.
            temporary = STATE.with_suffix('.tmp')
            temporary.write_text(json.dumps(records, indent=2) + '\n')
            temporary.replace(STATE)
        controllers = [r for r in records if r['namespace'] == 'midterm-gateway' and r['name'] == 'envoy-gateway']
        scheduled = [r for r in records if r['kind'] in ('CronJob', 'Job')]
        workloads = [r for r in records if r not in controllers + scheduled]
        if args.action == 'stop':
            # Prevent Envoy reconciliation and new backup jobs while scaling down.
            for r in controllers + scheduled:
                set_running(r, False)
            # Wait for the controller to exit before changing its generated proxy Deployments.
            for r in controllers:
                kube('-n', r['namespace'], 'wait', '--for=delete', 'pod', '-l', 'control-plane=envoy-gateway', '--timeout=40s')
            nodes = read('get', 'nodes')['items']
            if any(node['metadata'].get('labels', {}).get(BLOCKER) == 'true' for node in nodes):
                raise RuntimeError('DaemonSet pause label unexpectedly exists on a node.')
            for r in workloads:
                set_running(r, False)
            print('Waiting for graceful pod shutdown…', flush=True)
            wait_stopped(NAMESPACES, args.timeout)
            print('Deployment stopped. Volumes, secrets and Helm releases retained.', flush=True)
        else:
            # Restore data/services first, then controllers, and resume scheduled jobs last.
            for r in workloads + controllers:
                set_running(r, True)
            wait_ready(workloads + controllers, args.timeout)
            for r in scheduled:
                set_running(r, True)
            STATE.unlink(missing_ok=True)
            print('Deployment running: https://midterm.k8s.orb.local/', flush=True)


if __name__ == '__main__':
    try:
        main()
    except (RuntimeError, subprocess.CalledProcessError, subprocess.TimeoutExpired, BlockingIOError) as error:
        raise SystemExit(str(error))
