#!/usr/bin/env bash
# Run scripts/check.sh (Composer, lint, PHPUnit, PHPStan, schema, OpenAPI) inside the PHP 8.3 app image,
# against the compose.test.yaml dependencies, so the host needs only Docker.
# Stop the dependencies afterwards with: make test-deps-down
set -euo pipefail
cd "$(dirname "$0")/.."
IMAGE="${CHECK_IMAGE:-midterm/university:dev}"
docker image inspect "$IMAGE" >/dev/null 2>&1 || docker build -f docker/Dockerfile -t "$IMAGE" .
docker compose -f compose.test.yaml up -d --wait
docker run --rm -i --network midterm-tests_default -v "$PWD":/app -w /app \
  -e APP_ENV=dev -e COMPOSER_ALLOW_SUPERUSER=1 \
  -e 'DATABASE_URL=mysql://university:university@mysql:3306/university?serverVersion=8.4.0&charset=utf8mb4' \
  -e REDIS_QUEUE_DSN=redis://redis-queue:6379/payments -e REDIS_CACHE_URL=redis://redis-cache:6379 \
  -e S3_ENDPOINT=http://rustfs:9000 \
  --entrypoint sh "$IMAGE" -c '
    set -e
    composer install --no-interaction --quiet
    [ -f config/jwt/private.pem ] || php bin/console lexik:jwt:generate-keypair
    php bin/console doctrine:migrations:migrate --no-interaction -q
    php bin/console app:storage-init
    APP_ENV=test php bin/console cache:clear -q
    bash scripts/check.sh'
