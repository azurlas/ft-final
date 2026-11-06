#!/usr/bin/env python3
"""Shared target configuration for experiments against the local OrbStack cluster (scripts/setup-local.sh).

Every kubectl call names the orbstack context explicitly and is limited to the project namespaces.
URLs can be overridden with MIDTERM_URL, BASELINE_URL and GRAFANA_URL.
"""
import os
import pathlib
import subprocess

ROOT = pathlib.Path(__file__).resolve().parent.parent
KUBECTL = os.environ.get('KUBECTL_BIN', 'kubectl')
CONTEXT = 'orbstack'
ENVIRONMENT = 'orbstack-local'

RESILIENT = 'midterm'
BASELINE = 'midterm-baseline'
NAMESPACES = (RESILIENT, BASELINE, 'midterm-monitoring', 'midterm-gateway')
BASE = {
    RESILIENT: os.environ.get('MIDTERM_URL', 'http://midterm.k8s.orb.local'),
    BASELINE: os.environ.get('BASELINE_URL', 'http://baseline.midterm.k8s.orb.local:8080'),
}
GRAFANA_URL = os.environ.get('GRAFANA_URL', 'http://grafana.midterm.k8s.orb.local:3000')
GRAFANA_ENV = ROOT / 'output/secrets/grafana.env'

OUT = pathlib.Path(os.environ.get('RESULTS_DIR', ROOT / 'output/final'))
OUT.mkdir(parents=True, exist_ok=True)


def kubectl(ns, *args, input=None, timeout=100):
    assert ns in NAMESPACES, ns
    r = subprocess.run([KUBECTL, '--context', CONTEXT, '-n', ns, *args], input=input, text=True,
                       capture_output=True, timeout=timeout)
    if r.returncode:
        raise RuntimeError(r.stderr[-1000:])
    return r.stdout


def grafana_password():
    return GRAFANA_ENV.read_text().strip().split('=', 1)[1]
