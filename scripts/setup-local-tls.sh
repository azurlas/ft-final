#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
KUBECTL_BIN="${KUBECTL_BIN:-/Users/nariman/.orbstack/bin/kubectl}"
[[ -x "$KUBECTL_BIN" ]] || KUBECTL_BIN=kubectl
if ! command -v mkcert >/dev/null 2>&1; then
  echo 'Install mkcert first: brew install mkcert' >&2
  exit 1
fi
mkcert -install
mkdir -p output/secrets/tls
chmod 700 output/secrets output/secrets/tls
# Only the leaf certificate/key enter Kubernetes. The mkcert CA key stays on the Mac.
mkcert -cert-file output/secrets/tls/midterm.pem \
  -key-file output/secrets/tls/midterm-key.pem midterm.k8s.orb.local
chmod 600 output/secrets/tls/midterm.pem output/secrets/tls/midterm-key.pem
"$KUBECTL_BIN" --context orbstack -n midterm create secret tls university-tls \
  --cert=output/secrets/tls/midterm.pem --key=output/secrets/tls/midterm-key.pem \
  --dry-run=client -o yaml | "$KUBECTL_BIN" --context orbstack apply -f - >/dev/null
echo 'mkcert leaf certificate installed as midterm/university-tls.'
