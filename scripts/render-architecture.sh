#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
npx --yes @mermaid-js/mermaid-cli@12.0.0 -i docs/architecture.mmd -o docs/architecture.svg -b '#f8fafc'
npx --yes @mermaid-js/mermaid-cli@12.0.0 -i docs/architecture.mmd -o docs/architecture.png -b '#f8fafc' --size 1800
