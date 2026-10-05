.PHONY: install build test-deps-up test-deps-down test-unit test-integration test-functional lint check helm-check setup-local smoke experiments
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
helm-check:
	helm lint helm/university
	helm template university helm/university -f helm/university/values-local.yaml > /dev/null
setup-local:
	bash scripts/setup-local.sh
smoke:
	python3 scripts/smoke.py
experiments:
	python3 scripts/experiments/run.py
