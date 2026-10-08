#!/usr/bin/env python3
"""Seal Contabo secrets for Argo CD.

Plaintext values are generated once into ignored output/secrets/contabo/ and reused.
Only SealedSecrets, which only the cluster's controller can decrypt, are written to git.
Existing sealed files are kept unless named, e.g. after renewing the certificate:
    python3 deploy/seal-secrets.py wildcard-tls
"""
import base64
import json
import os
import pathlib
import secrets
import subprocess
import sys

ROOT = pathlib.Path(__file__).resolve().parent.parent
PLAIN = ROOT / 'output/secrets/contabo'
SEALED = ROOT / 'deploy/platform/secrets'
CERT = ROOT / 'deploy/sealed-secrets.pem'
CERT_HOST = os.environ.get('CERT_HOST', 'contabo-6')
LIVE = '/etc/letsencrypt/live/sailaubek.dev'
os.environ.setdefault('KUBECONFIG', str(PLAIN / 'kubeconfig'))


def env_file(path, generate):
    if not path.exists():
        path.write_text(''.join(f'{k}={v}\n' for k, v in generate().items()))
        path.chmod(0o600)
    return dict(line.split('=', 1) for line in path.read_text().splitlines() if line)


def application(namespace):
    def generate():
        db = secrets.token_hex(16)
        return {
            'APP_SECRET': secrets.token_hex(32),
            'MYSQL_PASSWORD': db,
            'MYSQL_ROOT_PASSWORD': secrets.token_hex(24),
            'DATABASE_URL': f'mysql://university:{db}@mysql:3306/university?serverVersion=8.4.8&charset=utf8mb4',
            'S3_ACCESS_KEY': 'midterm-' + secrets.token_hex(8),
            'S3_SECRET_KEY': secrets.token_hex(24),
            'MOCK_PAYMENT_TOKEN': secrets.token_hex(24),
        }
    values = env_file(PLAIN / f'{namespace}.env', generate)
    jwt = PLAIN / f'{namespace}-jwt'
    if not (jwt / 'private.pem').exists():
        jwt.mkdir(mode=0o700, exist_ok=True)
        subprocess.run(['openssl', 'genpkey', '-algorithm', 'RSA', '-pkeyopt', 'rsa_keygen_bits:4096', '-out', jwt / 'private.pem'], check=True)
        subprocess.run(['openssl', 'pkey', '-in', jwt / 'private.pem', '-pubout', '-out', jwt / 'public.pem'], check=True)
    values.update({name: (jwt / name).read_text() for name in ['private.pem', 'public.pem']})
    return values


def certificate():
    # The wildcard certificate is issued manually with certbot on the server (see README).
    tls = PLAIN / 'tls'
    tls.mkdir(mode=0o700, exist_ok=True)
    for name in ['fullchain.pem', 'privkey.pem']:
        pem = subprocess.run(['ssh', CERT_HOST, f'cat {LIVE}/{name}'], check=True, capture_output=True, text=True).stdout
        (tls / name).write_text(pem)
        (tls / name).chmod(0o600)
    return {'tls.crt': (tls / 'fullchain.pem').read_text(), 'tls.key': (tls / 'privkey.pem').read_text()}


SECRETS = {
    'university-midterm': ('midterm', 'university-secrets', 'Opaque', lambda: application('midterm')),
    'university-midterm-baseline': ('midterm-baseline', 'university-secrets', 'Opaque', lambda: application('midterm-baseline')),
    'grafana': ('midterm-monitoring', 'grafana-secret', 'Opaque',
                lambda: env_file(PLAIN / 'grafana.env', lambda: {'password': secrets.token_urlsafe(24)})),
    'wildcard-tls': ('midterm-gateway', 'wildcard-sailaubek-dev', 'kubernetes.io/tls', certificate),
}


def main(names):
    unknown = set(names) - SECRETS.keys()
    if unknown:
        sys.exit(f'unknown secrets: {", ".join(sorted(unknown))}; expected {", ".join(SECRETS)}')
    PLAIN.mkdir(mode=0o700, parents=True, exist_ok=True)
    SEALED.mkdir(parents=True, exist_ok=True)
    if not CERT.exists():
        cert = subprocess.run(['kubeseal', '--controller-name', 'sealed-secrets-controller', '--controller-namespace', 'kube-system', '--fetch-cert'],
                              check=True, capture_output=True, text=True).stdout
        CERT.write_text(cert)
    for name, (namespace, secret, kind, values) in SECRETS.items():
        target = SEALED / f'{name}.yaml'
        if target.exists() and name not in names:
            continue
        manifest = {'apiVersion': 'v1', 'kind': 'Secret', 'type': kind, 'metadata': {'name': secret, 'namespace': namespace},
                    'data': {k: base64.b64encode(v.encode()).decode() for k, v in values().items()}}
        sealed = subprocess.run(['kubeseal', '--cert', CERT, '--format', 'yaml'], input=json.dumps(manifest),
                                check=True, capture_output=True, text=True).stdout
        target.write_text(sealed)
        print(f'sealed {namespace}/{secret} -> {target.relative_to(ROOT)}')


if __name__ == '__main__':
    main(sys.argv[1:])
