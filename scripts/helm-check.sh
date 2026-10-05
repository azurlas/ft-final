#!/usr/bin/env bash
set -euo pipefail
helm lint helm/university helm/monitoring
for values in helm/university/values-local.yaml helm/university/values-baseline.yaml; do
  helm template university helm/university -f "$values" >/dev/null
done
helm template monitoring helm/monitoring >/dev/null
