#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
composer validate --strict
composer check-platform-reqs
php bin/console lint:container
php bin/console lint:yaml config helm/university/values.yaml helm/university/values-local.yaml helm/university/values-baseline.yaml
php bin/console lint:twig templates
php bin/console app:seed-demo >/dev/null
php -d upload_max_filesize=12M -d post_max_size=12M vendor/bin/phpunit
vendor/bin/phpstan analyse --memory-limit=512M --no-progress
php bin/console doctrine:schema:validate
php bin/console api:openapi:export --output=output/openapi/openapi.json
python3 -m py_compile scripts/*.py
for script in scripts/*.sh; do bash -n "$script"; done
git diff --check
