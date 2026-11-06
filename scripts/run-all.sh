#!/usr/bin/env bash
# Run the complete experiment procedure from docs/experiments.md (section 5) against the deployed
# OrbStack cluster, in order, waiting for all project pods between scenarios. About 75 minutes.
# Output: output/final/ (or RESULTS_DIR), plus a timestamped log of the whole run.
set -uo pipefail
cd "$(dirname "$0")/.."
OUT="${RESULTS_DIR:-output/final}"
mkdir -p "$OUT"
LOG="$OUT/run-$(date -u +%Y%m%dT%H%M%SZ).log"

settle() {
  for ns in midterm midterm-baseline midterm-monitoring; do
    kubectl --context orbstack -n "$ns" wait --for=condition=Ready pod --field-selector=status.phase=Running --all --timeout=300s >/dev/null
  done
  sleep 10
}

step() {
  echo "=== $* start $(date -u +%H:%M:%S)" | tee -a "$LOG"
  "$@" >>"$LOG" 2>&1
  local code=$?
  echo "=== $* exit $code $(date -u +%H:%M:%S)" | tee -a "$LOG"
  settle
}

kubectl --context orbstack get nodes | tee -a "$LOG"
settle
step python3 scripts/smoke.py
step python3 scripts/experiments.py --only availability
step python3 scripts/experiments.py --only queue
step python3 scripts/experiments.py --only checkpoints
step python3 scripts/broker-recovery.py
step python3 scripts/experiments.py --only timeout
step python3 scripts/experiments.py --only circuit
step python3 scripts/experiments.py --only spike --repetitions 1
step python3 scripts/experiments.py --only security
step python3 scripts/experiments.py --only alerts --repetitions 1
step python3 scripts/experiments.py --only restore
step python3 scripts/observability-check.py
step python3 scripts/summarize.py
echo "Done. Results: $OUT/results.md; log: $LOG" | tee -a "$LOG"
