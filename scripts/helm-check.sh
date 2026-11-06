#!/usr/bin/env bash
set -euo pipefail
helm lint helm/university helm/monitoring
# Local OrbStack profiles (scripts/setup-local.sh).
helm template university helm/university -f helm/university/values-local.yaml >/dev/null
helm template university helm/university -f helm/university/values-local.yaml -f helm/university/values-baseline.yaml >/dev/null
# Optional Contabo deployment: the exact value-file combinations Argo CD uses.
helm template university helm/university -f helm/university/values-contabo.yaml -f deploy/image.yaml >/dev/null
helm template university helm/university -f helm/university/values-baseline.yaml -f helm/university/values-contabo-baseline.yaml -f deploy/image.yaml >/dev/null
helm template monitoring helm/monitoring >/dev/null
helm template monitoring helm/monitoring -f helm/monitoring/values-contabo.yaml >/dev/null
