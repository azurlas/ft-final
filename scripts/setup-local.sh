#!/usr/bin/env bash
# Build the image and deploy both profiles plus monitoring to OrbStack Kubernetes.
# Every kubectl/helm call names the orbstack context explicitly. Re-running upgrades in place.
set -euo pipefail
cd "$(dirname "$0")/.."
CTX=orbstack
k() { kubectl --context "$CTX" "$@"; }

if ! k get nodes >/dev/null 2>&1; then
  orb start k8s
  for _ in $(seq 1 60); do k get nodes 2>/dev/null | grep -q ' Ready' && break; sleep 3; done
fi
k get nodes
for ns in midterm midterm-baseline midterm-monitoring midterm-gateway; do
  k create namespace "$ns" --dry-run=client -o yaml | k apply -f - >/dev/null
done

# OrbStack Kubernetes runs images straight from OrbStack's Docker; no registry needed.
IMAGE_TAG="${IMAGE_TAG:-midterm/university:local-$(date +%Y%m%d%H%M%S)}"
docker --context orbstack build -f docker/Dockerfile -t "$IMAGE_TAG" .

# Generated credentials live in ignored files and are reused on every upgrade.
mkdir -p output/secrets config/jwt
chmod 700 output/secrets
if [[ ! -f output/secrets/application.env ]]; then
  python3 - <<'PY'
import os, pathlib, secrets
db = secrets.token_hex(16)
values = {
    'APP_SECRET': secrets.token_hex(32), 'MYSQL_PASSWORD': db, 'MYSQL_ROOT_PASSWORD': secrets.token_hex(24),
    'DATABASE_URL': f'mysql://university:{db}@mysql:3306/university?serverVersion=8.4.8&charset=utf8mb4',
    'S3_ACCESS_KEY': 'midterm-' + secrets.token_hex(8), 'S3_SECRET_KEY': secrets.token_hex(24),
    'MOCK_PAYMENT_TOKEN': secrets.token_hex(24),
}
p = pathlib.Path('output/secrets/application.env')
p.write_text(''.join(f'{k}={v}\n' for k, v in values.items())); os.chmod(p, 0o600)
q = pathlib.Path('output/secrets/grafana.env')
q.write_text('password=' + secrets.token_urlsafe(24) + '\n'); os.chmod(q, 0o600)
PY
fi
# JWT signing keys (no passphrase, matching JWT_PASSPHRASE= in .env); no host PHP needed.
if [[ ! -f config/jwt/private.pem ]]; then
  openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:4096 -out config/jwt/private.pem 2>/dev/null
  openssl pkey -in config/jwt/private.pem -pubout -out config/jwt/public.pem
fi
for ns in midterm midterm-baseline; do
  python3 - "$ns" <<'PY' | k apply -f - >/dev/null
import base64, json, pathlib, sys
v = dict(line.split('=', 1) for line in pathlib.Path('output/secrets/application.env').read_text().splitlines())
v.update({name: pathlib.Path('config/jwt/' + name).read_text() for name in ['private.pem', 'public.pem']})
print(json.dumps({'apiVersion': 'v1', 'kind': 'Secret', 'metadata': {'name': 'university-secrets', 'namespace': sys.argv[1]},
                  'type': 'Opaque', 'data': {k: base64.b64encode(x.encode()).decode() for k, x in v.items()}}))
PY
done
k -n midterm-monitoring create secret generic grafana-secret --from-env-file=output/secrets/grafana.env \
  --dry-run=client -o yaml | k apply -f - >/dev/null

helm upgrade --install midterm-envoy oci://docker.io/envoyproxy/gateway-helm --version v1.9.2 \
  --kube-context "$CTX" -n midterm-gateway --wait --timeout 5m
k apply -f - <<'YAML'
apiVersion: gateway.networking.k8s.io/v1
kind: GatewayClass
metadata: {name: midterm-envoy}
spec:
  controllerName: gateway.envoyproxy.io/gatewayclass-controller
YAML

helm upgrade --install university helm/university --kube-context "$CTX" -n midterm \
  -f helm/university/values-local.yaml --set-string image="$IMAGE_TAG" --wait --timeout 8m
helm upgrade --install university helm/university --kube-context "$CTX" -n midterm-baseline \
  -f helm/university/values-local.yaml -f helm/university/values-baseline.yaml --set-string image="$IMAGE_TAG" --wait --timeout 8m

helm repo add vm https://victoriametrics.github.io/helm-charts >/dev/null
helm repo update vm >/dev/null
helm upgrade --install vlogs vm/victoria-logs-single --version 0.13.10 --kube-context "$CTX" -n midterm-monitoring \
  -f helm/monitoring/vlogs.yaml --wait --timeout 5m
helm upgrade --install logs-collector vm/victoria-logs-collector --version 0.3.8 --kube-context "$CTX" -n midterm-monitoring \
  -f helm/monitoring/collector.yaml --wait --timeout 5m
helm upgrade --install monitoring helm/monitoring --kube-context "$CTX" -n midterm-monitoring --wait --timeout 5m

mkdir -p output
printf '%s\n' "$IMAGE_TAG" > output/image-tag.txt
k -n midterm get pods,services,pvc,jobs
echo "Deployed $IMAGE_TAG"
echo "Campus:   http://midterm.k8s.orb.local/"
echo "Baseline: http://baseline.midterm.k8s.orb.local:8080/"
echo "Grafana:  http://grafana.midterm.k8s.orb.local:3000/ (admin / see output/secrets/grafana.env)"
