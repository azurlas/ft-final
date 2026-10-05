#!/usr/bin/env bash
set -euo pipefail
# Whole-node failures affect unrelated applications. This is deliberately a separately guarded demonstration.
if [[ "${MIDTERM_ALLOW_WHOLE_CLUSTER_OUTAGE:-}" != yes ]]; then
  printf '%s\n' 'Not run: set MIDTERM_ALLOW_WHOLE_CLUSTER_OUTAGE=yes only after authorizing interruption of every OrbStack workload.' >&2
  exit 2
fi
orb stop k8s
trap 'orb start k8s' EXIT INT TERM
sleep 10
orb start k8s
trap - EXIT INT TERM
