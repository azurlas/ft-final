.PHONY: start stop install build test-deps-up test-deps-down test-unit test-integration test-functional lint check frontend-check frontend-smoke frontend-upload-check helm-check setup-local setup-local-tls tls-check smoke experiments
install:
	composer install --no-interaction
build:
	docker build -f docker/Dockerfile -t midterm/university:dev .
test-deps-up:
	docker compose -f compose.test.yaml up -d --wait
test-deps-down:
	docker compose -f compose.test.yaml down
test-unit:
	php -d upload_max_filesize=12M -d post_max_size=12M vendor/bin/phpunit --testsuite Unit
test-integration:
	php -d upload_max_filesize=12M -d post_max_size=12M vendor/bin/phpunit --testsuite Integration
test-functional:
	php -d upload_max_filesize=12M -d post_max_size=12M vendor/bin/phpunit --testsuite Functional
lint:
	php bin/console lint:container
	php bin/console lint:yaml config
	vendor/bin/phpstan analyse --memory-limit=512M --no-progress
check:
	bash scripts/check.sh
frontend-check:
	npm run check
frontend-smoke:
	python3 scripts/frontend-smoke.py
frontend-upload-check: tls-check
	NODE_EXTRA_CA_CERTS=output/secrets/tls/verification-ca.pem node scripts/frontend-upload-check.mjs
helm-check:
	bash scripts/helm-check.sh
start:
	python3 scripts/deployment.py start
stop:
	python3 scripts/deployment.py stop
setup-local:
	bash scripts/setup-local.sh
setup-local-tls:
	bash scripts/setup-local-tls.sh
tls-check:
	python3 scripts/check-local-tls.py
smoke:
	python3 scripts/smoke.py
experiments:
	python3 scripts/experiments.py
