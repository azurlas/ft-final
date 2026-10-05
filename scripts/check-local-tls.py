#!/usr/bin/env python3
"""Verify the public OrbStack TLS proxy and the mkcert-backed Envoy listener."""
import datetime
import hashlib
import http.client
import json
import pathlib
import socket
import ssl
import subprocess
import urllib.request
from experiments import kubectl

host = 'midterm.k8s.orb.local'
base = 'https://' + host
gateway = json.loads(kubectl('midterm', 'get', 'gateway', 'university', '-o', 'json'))
listener = next(item for item in gateway['status']['listeners'] if item['name'] == 'https')
assert listener['attachedRoutes'] == 1
assert all(next(c['status'] for c in listener['conditions'] if c['type'] == key) == 'True' for key in ['Accepted', 'Programmed', 'ResolvedRefs'])
address = gateway['status']['addresses'][0]['value']
ca_root = pathlib.Path(subprocess.check_output(['mkcert', '-CAROOT'], text=True).strip())
mkcert_context = ssl.create_default_context(cafile=str(ca_root / 'rootCA.pem'))
with socket.create_connection((address, 443), timeout=15) as raw:
    with mkcert_context.wrap_socket(raw, server_hostname=host) as secured:
        direct_cert = secured.getpeercert(binary_form=True)
        issued_cert = ssl.PEM_cert_to_DER_cert(pathlib.Path('output/secrets/tls/midterm.pem').read_text())
        assert direct_cert == issued_cert, 'Envoy must serve the issued mkcert certificate'
        direct_version = secured.version()
        secured.sendall(f'GET /ui/courses.html HTTP/1.1\r\nHost: {host}\r\nConnection: close\r\n\r\n'.encode())
        response = http.client.HTTPResponse(secured)
        response.begin()
        assert response.status == 200
        assert response.getheader('X-Static-Server') == 'RoadRunner'
        assert response.read() == pathlib.Path('public/ui/courses.html').read_bytes()

# Python's OpenSSL trust store may differ from macOS Keychain; load the public CA certificate.
orb_ca = subprocess.check_output(['security', 'find-certificate', '-c', 'OrbStack Development Root CA', '-p'], text=True)
public_context = ssl.create_default_context()
public_context.load_verify_locations(cadata=orb_ca)
public_context.load_verify_locations(cafile=str(ca_root / 'rootCA.pem'))
# Public certificates only; reusable trust bundle for Node/Python live checks.
pathlib.Path('output/secrets/tls/verification-ca.pem').write_text((ca_root / 'rootCA.pem').read_text() + orb_ca)
with socket.create_connection((host, 443), timeout=15) as raw:
    with public_context.wrap_socket(raw, server_hostname=host) as secured:
        public_cert = secured.getpeercert(binary_form=True)
        public_version = secured.version()
checks = {}
for path in ['/ui/courses.html', '/ui/submit.html', '/ui/assignments.html', '/ui/main.js', '/ui/app.js', '/ui/api.js', '/ui/format.js', '/ui/styles.css', '/api/courses', '/health/ready']:
    with urllib.request.urlopen(base + path, context=public_context, timeout=20) as response:
        assert response.status == 200
        response.read()
        checks[path] = response.status
native_result = subprocess.check_output(['curl', '--fail', '--silent', '--show-error', '--max-time', '20', '--output', '/dev/null', '--write-out', '%{http_code} %{ssl_verify_result}', base + '/ui/courses.html'], text=True)
assert native_result == '200 0', 'macOS curl must trust the public certificate'
report = {'checkedAt': datetime.datetime.now(datetime.timezone.utc).isoformat(), 'url': base, 'gatewayAddress': address, 'httpsListener': listener, 'certificateVerificationEnabled': True, 'macOSNativeTrustVerified': True, 'directEnvoy': {'matchesIssuedMkcertCertificate': True, 'tlsVersion': direct_version, 'sha256': hashlib.sha256(direct_cert).hexdigest()}, 'publicOrbStackProxy': {'tlsVersion': public_version, 'sha256': hashlib.sha256(public_cert).hexdigest()}, 'checks': checks}
pathlib.Path('output/evaluation/tls.json').write_text(json.dumps(report, indent=2) + '\n')
print(json.dumps(report, indent=2))
