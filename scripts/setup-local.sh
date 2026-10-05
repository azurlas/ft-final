#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
KUBECTL_BIN="${KUBECTL_BIN:-/Users/nariman/.orbstack/bin/kubectl}"
[[ -x "$KUBECTL_BIN" ]] || KUBECTL_BIN=kubectl
original_kube_context=$("$KUBECTL_BIN" config current-context 2>/dev/null || true)
restore_context() {
  if [[ -n "$original_kube_context" ]] && [[ "$("$KUBECTL_BIN" config current-context 2>/dev/null || true)" != "$original_kube_context" ]]; then
    "$KUBECTL_BIN" config use-context "$original_kube_context" >/dev/null
  fi
}
if ! "$KUBECTL_BIN" --context orbstack get nodes >/dev/null 2>&1; then
  if ! orb start k8s; then restore_context; exit 1; fi
  restore_context
fi
"$KUBECTL_BIN" --context orbstack get nodes
for ns in midterm midterm-baseline midterm-monitoring midterm-gateway; do
  "$KUBECTL_BIN" --context orbstack create namespace "$ns" --dry-run=client -o yaml | "$KUBECTL_BIN" --context orbstack apply -f - >/dev/null
done
bash scripts/setup-local-tls.sh
IMAGE_TAG="${IMAGE_TAG:-midterm/university:local-$(date +%Y%m%d%H%M%S)}"
docker build -f docker/Dockerfile -t "$IMAGE_TAG" .
mkdir -p output/secrets
chmod 700 output/secrets
# Store generated local credentials in ignored files; retain them across upgrades.
if [[ ! -f output/secrets/application.env ]]; then
  python3 - <<'PY'
import secrets,pathlib,os
p=pathlib.Path('output/secrets/application.env'); db=secrets.token_hex(16)
v={'APP_SECRET':secrets.token_hex(32),'MYSQL_PASSWORD':db,'MYSQL_ROOT_PASSWORD':secrets.token_hex(24),'DATABASE_URL':f'mysql://university:{db}@mysql:3306/university?serverVersion=8.4.8&charset=utf8mb4','S3_ACCESS_KEY':'midterm-'+secrets.token_hex(8),'S3_SECRET_KEY':secrets.token_hex(24),'MOCK_PAYMENT_TOKEN':secrets.token_hex(24)}
p.write_text(''.join(f'{k}={x}\n' for k,x in v.items()));os.chmod(p,0o600)
q=pathlib.Path('output/secrets/grafana.env');q.write_text('password='+secrets.token_urlsafe(24)+'\n');os.chmod(q,0o600)
PY
fi
if [[ ! -f config/jwt/private.pem ]]; then php bin/console lexik:jwt:generate-keypair; fi
for ns in midterm midterm-baseline; do
  python3 - "$ns" <<'PYSECRET' | "$KUBECTL_BIN" --context orbstack apply -f - >/dev/null
import sys,json,base64,pathlib
v=dict(line.split('=',1) for line in pathlib.Path('output/secrets/application.env').read_text().splitlines())
v.update({name:pathlib.Path('config/jwt/'+name).read_text() for name in ['private.pem','public.pem']})
print(json.dumps({'apiVersion':'v1','kind':'Secret','metadata':{'name':'university-secrets','namespace':sys.argv[1]},'type':'Opaque','data':{k:base64.b64encode(x.encode()).decode() for k,x in v.items()}}))
PYSECRET
done
"$KUBECTL_BIN" --context orbstack -n midterm-monitoring create secret generic grafana-secret --from-env-file=output/secrets/grafana.env --dry-run=client -o yaml | "$KUBECTL_BIN" --context orbstack apply -f - >/dev/null
helm upgrade --install midterm-envoy oci://docker.io/envoyproxy/gateway-helm --version v1.9.2 --kube-context orbstack -n midterm-gateway --wait --timeout 5m
"$KUBECTL_BIN" --context orbstack apply -f - <<'YAML'
apiVersion: gateway.networking.k8s.io/v1
kind: GatewayClass
metadata: {name: midterm-envoy}
spec:
  controllerName: gateway.envoyproxy.io/gatewayclass-controller
YAML
for ns in midterm midterm-baseline; do
  values=helm/university/values-local.yaml
  [[ "$ns" != midterm-baseline ]] || values=helm/university/values-baseline.yaml
  helm upgrade --install university helm/university --kube-context orbstack -n "$ns" -f "$values" --set-string image="$IMAGE_TAG" --wait --timeout 8m
done
helm repo add vm https://victoriametrics.github.io/helm-charts >/dev/null
helm repo update vm >/dev/null
helm upgrade --install vlogs vm/victoria-logs-single --version 0.13.10 --kube-context orbstack -n midterm-monitoring -f helm/monitoring/vlogs.yaml --wait --timeout 5m
helm upgrade --install logs-collector vm/victoria-logs-collector --version 0.3.8 --kube-context orbstack -n midterm-monitoring -f helm/monitoring/collector.yaml --wait --timeout 5m
helm upgrade --install monitoring helm/monitoring --kube-context orbstack -n midterm-monitoring --wait --timeout 5m
printf '%s\n' "$IMAGE_TAG" > output/image-tag.txt
"$KUBECTL_BIN" --context orbstack -n midterm get pods,services,pvc,jobs
