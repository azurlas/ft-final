#!/usr/bin/env bash
# One-time Argo CD install on the Contabo RKE2 cluster; Argo CD then manages itself and deploy/argocd/apps.
# Requires the API tunnel: ssh -fN -L 16443:127.0.0.1:6443 contabo-6
set -euo pipefail
cd "$(dirname "$0")/.."
export KUBECONFIG="${KUBECONFIG:-output/secrets/contabo/kubeconfig}"
version=$(awk '/targetRevision:/ {print $2; exit}' deploy/argocd/apps/argocd.yaml)
helm repo add argo https://argoproj.github.io/argo-helm >/dev/null
helm repo update argo >/dev/null
helm upgrade --install argocd argo/argo-cd --version "$version" -n argocd --create-namespace -f deploy/argocd/values.yaml --wait --timeout 10m
kubectl apply -f deploy/argocd/root.yaml
