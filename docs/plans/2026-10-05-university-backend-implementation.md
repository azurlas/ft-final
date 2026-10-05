# University backend implementation plan

Date: 2026-10-05. Design source: [approved specification](../../docs.md). Status: approved; sequential execution completed in this session. This plan was written directly with the user's approval because the brainstorming workflow's referenced `writing-plans` skill is not installed.

## Execution and completion contract

Recommended execution method: implement sequentially in this session, one coherent task at a time, without delegated agents. Preserve this plan's task status across continuations. A separate session using the same committed plan is an alternative; delegation is not authorized by this plan's recommendation.

The user has authorized application creation and deployment to the local `orbstack` Kubernetes context. Implementation starts after approval of this written plan and selection of execution method. No further routine confirmation is needed for dependency installation, local image builds, release installation, fixes, or namespace-scoped experiments within that approved scope.

Finish all twelve tasks, including a working deployed API, provisioned Grafana/logs, source/configuration, measured experiments, and updated documentation. Document actual limitations and unsuccessful runs. Do not label unexecuted checks as passed. Commit meaningful completed units with descriptive messages; do not publish a remote repository or create a pull request unless separately requested.

Use explicit `--context orbstack` and `--kube-context orbstack` on every Kubernetes/Helm command. Never switch the user's global default context. Do not overwrite another release or apply failure injection to unrelated namespaces. Perform whole-cluster outage tests only after inspection establishes that no unrelated user workloads will be interrupted; otherwise retain the guarded experiment script and report why that run needs separate authorization.

## Dependency and runtime choices

The following package families were checked against package-authored metadata during planning:

- PHP `8.3`; Symfony components `7.4.*`, using Composer's platform constraint to preserve PHP 8.3 compatibility.
- `api-platform/symfony` and `api-platform/doctrine-orm` `^4.4`.
- `doctrine/orm` `^3.7`, `doctrine/doctrine-bundle` `^2.19`, `doctrine/doctrine-migrations-bundle` `^3.7`, and compatible DBAL `^4`.
- `baldinof/roadrunner-bundle` `^3.4` and a compatible RoadRunner `2025.x` release supported by that bundle.
- `lexik/jwt-authentication-bundle` `^3.2`, `symfony/monolog-bundle` `^4.1`, and Monolog `^3`.
- `dompdf/dompdf` `^3.1` and `aws/aws-sdk-php` `^3`.
- Symfony Messenger/Redis bridge, HTTP client, security, serializer, validation, cache, Twig, and test utilities from the Symfony `7.4.*` line.
- PHPUnit `^12.5`, which supports PHP 8.3, and PHPStan `^2` for static checks.

Do not select DoctrineBundle 3.x, DoctrineMigrationsBundle 4.x, or PHPUnit 13.x: their inspected releases require PHP 8.4. Resolve all transitive dependencies with Composer, run platform checks, and commit `composer.lock`; the metadata check is not a substitute for a successful installation and runtime test.

Use MySQL 8.4, separate Redis queue/cache instances, standalone RustFS, single-node Prometheus/Grafana/VictoriaLogs, and the official VictoriaLogs collector. Resolve concrete image tags/digests and Helm dependency versions in Task 1/9 and record them in versioned configuration. Do not deploy mutable `latest` tags.

Envoy Gateway's inspected current chart is `v1.9.2`, with the v1.9 line supporting Kubernetes 1.33-1.36. Inspect the actual OrbStack server version before installation and choose a compatible maintained release from the official matrix if necessary; do not upgrade the local cluster implicitly.

## Repository layout

```text
composer.json, composer.lock, symfony.lock
bin/console
config/{bundles.php,services.yaml,routes.yaml,packages/}
public/index.php
src/Kernel.php
src/Entity/                         Doctrine entities
src/Repository/                     Focused queries and database locking
src/Api/{Resource,State,Controller}/ API DTOs, providers/processors, binary routes
src/Security/                       Current-user and access policies
src/Course/                         Catalogue, enrollment, course lifecycle
src/Payment/{Dto,Service,Message}/   Checkout, mock adapter, attempts, recovery
src/Academic/                       Assignments, submissions, grading, reports
src/Storage/                        Private S3 and compensating cleanup
src/Observability/                  Monolog context and business metrics
src/Command/                        Seeds, outbox relay, metrics, backups
migrations/
templates/reports/course.html.twig
tests/{Unit,Integration,Functional}/
docker/{Dockerfile,entrypoint.sh,supervisord.conf,php.ini}
.rr.yaml
compose.test.yaml                   Isolated integration dependencies
helm/university/                    Application chart and profile values
helm/observability/                 Small monitoring chart and pinned dependencies
helm/envoy/values-local.yaml
monitoring/{dashboards,prometheus}/
scripts/{setup-local.sh,check.sh,smoke.py,experiments/}
fixtures/payments.json
output/{openapi,experiments,reports}/
docs.md, docs/architecture.mmd, docs/architecture.svg
Makefile, phpunit.xml.dist, phpstan.neon, .gitignore, .dockerignore
```

Keep files focused by operation; avoid a single controller or service containing the entire application. The structure may use small additional helper classes where needed without changing the approved module boundaries.

## Task 1: establish reproducible runtime and validation entrypoints

Dependencies: approved plan. Status: complete.

Create Composer/Symfony kernel configuration, console/front controller, the minimal RoadRunner configuration, Docker image/entrypoints, test dependency Compose file, and Makefile. Include `pdo_mysql`, Redis, `pcntl`, `intl`, `mbstring`, DOM/XML, and required core extensions. Configure Symfony Flex to remain on `7.4.*`. Register API Platform, Doctrine, migrations, security/JWT, RoadRunner, Twig, and Monolog bundles.

Implement Make targets `install`, `build`, `test-deps-up`, `test-deps-down`, `test-unit`, `test-integration`, `test-functional`, `lint`, `check`, `helm-check`, `setup-local`, `smoke`, and `experiments`. Define PHPUnit suites explicitly. Keep generated secrets, vendor/cache directories, uploaded files, and ephemeral test data out of Git. Keep the Composer lockfile and measured deliverables versioned.

Checks:

```sh
composer validate --strict
composer install --no-interaction
composer check-platform-reqs
php bin/console about
php bin/console lint:container
make build
```

Acceptance: PHP 8.3 image builds on the Mac's native architecture, Symfony boots in CLI and RoadRunner, and the validation targets have clear nonzero failure behavior. Pin the built image and upstream runtime source/version.

Commit: `build: establish PHP 8.3 Symfony and RoadRunner runtime`.

## Task 2: implement entities, migrations, and repeatable seed data

Dependencies: Task 1. Status: complete.

Create the eleven entity classes in `src/Entity`: User, Course, CourseEnrollment, Assignment, Submission, Grade, CoursePurchase, PaymentAttempt, OutboxMessage, MockPaymentReceipt, and PaymentCircuitState. Create typed purchase/attempt/result enums and value normalization helpers where useful.

Add the approved fields, foreign keys/indexes, unique constraints, integer/grade/file-size checks, UTC timestamps, and soft-deletion fields. Persist checkout idempotency scope/fingerprint and processing/publication/probe leases. Encode metadata as a JSON list. Use explicit migrations reviewed against MySQL rather than development-time schema auto-update.

Create repository operations for current enrollment, active purchases, user/course/purchase transaction locks, due outbox rows, stale leases, and reporting aggregates. Maintain the specified lock order and never hold a database transaction open during an HTTP provider call.

Create `SeedDemoCommand` with an idempotent administrator/student/course/assignment/circuit seed. Seeding an existing database must not silently reset changed passwords, duplicate purchases, or overwrite grades. Generate paid courses sufficient to demonstrate all fixtures independently. Add submission/report seed records after the S3 workflow is available in Task 7.

Checks:

```sh
make test-deps-up
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console doctrine:schema:validate
php bin/console app:seed-demo
php bin/console app:seed-demo
make test-integration
```

Acceptance: real MySQL enforces uniqueness/bounds, migration runs on an empty database, seeds are repeatable, and JSON/UTC data round-trips correctly. Include meaningful integrity tests rather than tests that repeat getters/setters.

Commit: `feat: model courses academic records and payment recovery state`.

## Task 3: authentication, authorization, and course/enrollment API

Dependencies: Tasks 1-2. Status: complete.

Create registration/login/current-user resources, Symfony JSON login, Lexik JWT configuration, user provider, and owner/admin/enrollment access policies. Use a local Kubernetes Secret for JWT keys and never add the private key to source control. Reject attempts to pass administrator attributes during public registration.

Implement API Platform course resources and focused providers/processors for catalogue, admin CRUD, free enrollment, and enrollment listing. Filter archived courses from public catalogue. Use server-owned identity and pricing. Serialize access to active checkout/course state; prevent deletion with an active purchase and prevent free enrollment racing a pending checkout.

Cache only the public catalogue with a short TTL (30 seconds) and invalidate on administration. Authorization, price checks, and enrollment always query authoritative data. Resilient cache errors bypass caching; baseline errors surface so the experiment has a measurable contrast.

Add problem-response normalization and pagination limits. Register all intended API operations explicitly; internal entities do not expose automatic unrestricted CRUD.

Checks: functional tests for login/registration, password serialization, rejected privilege escalation, admin-only mutations, own-record filtering, archived data, repeated/concurrent enrollment, paid-course enrollment rejection, and cache outage behavior. Smoke-test two users successively through the same RoadRunner worker.

Acceptance: access decisions come from current server records and no user/security state leaks between requests.

Commit: `feat: add JWT course administration and student enrollment`.

## Task 4: implement payment DTO, fixtures, and isolated mock endpoint

Dependencies: Tasks 1-3. Status: complete.

Create `PaymentDetailsDto`, `PaymentContext`, `PaymentResult`, `PaymentServiceInterface`, `MockPaymentService`, and `HttpPaymentService`. The details DTO has exactly beneficiaryName, cardNumber, expiryDate, and cvv. The mock class owns the six approved hard-coded synthetic credentials and outcome rules.

Resolve fixture identity at checkout validation and provide a reconstruction method that creates the synthetic DTO for a queued call. Do not persist/log raw credentials. Generate `fixtures/payments.json` from that same catalogue. Validate extra/unknown attributes and reject a client-provided scenario/amount/attempt.

Create a mock-only internal route using a role-specific runtime entrypoint and service token. It checks purchase/context consistency and preserves a unique successful receipt before returning. Repeat calls return the original receipt. Temporary responses depend deterministically on fixture and stable attempt number. The timeout fixture exceeds the resilient HTTP deadline. The API-facing deployment must not make this internal route callable as an ordinary public resource.

Checks: table-driven unit tests of all six fixtures and invalid inputs; HTTP integration tests for a persisted repeated receipt, changed amount/context rejection, internal authentication, and timeout behavior.

Acceptance: each request fixture has exactly the documented behavior; the payment interface receives the requested DTO; successful receipts are durable and idempotent.

Commit: `feat: add DTO payment interface and hard-coded mock scenarios`.

## Task 5: checkout, attempts, and bounded business retries

Dependencies: Tasks 2-4. Status: complete.

Create `CheckoutService`, checkout/status API resources, `ProcessPurchase` message, handler, and focused settlement/attempt repositories. Save purchase, price/currency snapshot, metadata list, idempotency fingerprint, and initial outbox row in one transaction in the resilient profile. Baseline dispatches after committing its pending purchase without an outbox.

On delivery, claim a bounded lease, reject terminal/due-time conflicts, recover an unfinished attempt, and perform the HTTP call outside a database transaction. Mark success and insert enrollment atomically with the prescribed user/course/purchase lock order. For temporary failure, persist the outcome and due outbox job. Delay the two permitted retries with exponential backoff/jitter. Permanent declines stop immediately; exhausted payments remain terminal.

Keep broker delivery retries separate from durable business attempts. An infrastructure handler exception may trigger Messenger retries; a persisted business outcome is acknowledged and recovered through its scheduled record. Do not issue a fourth distinct attempt after duplicate delivery or manual failed-message replay. Return sanitized purchase details and attempt history to authorized users.

Checks: real MySQL/Redis integration tests for idempotency-key reuse/payload mismatch, one active checkout, price snapshot, all fixture outcomes, attempt ceilings, concurrent settlement, duplicate delivery, and worker interruption after mock success.

Acceptance: resilient fixtures yield attempt counts `1, 2, 3, 3, 1, 3` respectively; one successful purchase gives exactly one receipt and enrollment. Baseline performs one business attempt.

Commit: `feat: process queued purchases with bounded retries and atomic enrollment`.

## Task 6: outbox recovery, circuit breaker, and worker lifecycle

Dependencies: Task 5. Status: complete.

Create `OutboxRelay`, `PurchaseReconciler`, `PaymentCircuitBreaker`, `RunPaymentRelayCommand`, and failure checkpoint support limited to the experiment profile. Lease publication rows, mark Redis acceptance, recover an expired publisher, and safely republish unfinished/due work even after broker loss. Do not automatically revive a terminal failed/declined purchase.

Implement the shared MySQL circuit: five consecutive transient failures, 15-second cooldown, one leased half-open probe. A paused provider call consumes no new business attempt. Reset the circuit on success; distinguish expected declines from transient failures.

Configure Messenger Redis streams, failed transport, two infrastructure retries, 120-second abandoned-message timeout, and unique consumer names. Supervisor runs consumers, relay/reconciliation, and business metrics exporter in the foreground with process-group termination and bounded shutdown. API liveness remains independent of dependencies; readiness checks MySQL.

Checks: kill relay between Redis acceptance and publication marking; delete/drop test queue contents; reclaim a dead worker's message; verify deferred circuit behavior across worker replicas; stop/restart workers; inspect that MySQL or Redis outage does not produce hot restart/retry loops or unbounded attempt growth.

Acceptance: accepted resilient purchases recover once required dependencies return, duplicate publications are harmless, and all terminal states remain stable.

Commit: `feat: recover payment work with outbox reconciliation and circuit breaker`.

## Task 7: assignments, private submissions, and grading

Dependencies: Tasks 2-3. Status: complete.

Create assignment API resources/processors, `SubmissionService`, `S3Storage`, `CleanupOrphanObjectsCommand`, grade resources/processors, and authorized metadata/download providers. Configure AWS S3 endpoint/path-style signing for RustFS and distinct file/backup buckets.

Enforce active course/assignment, authoritative enrollment, pre-deadline acceptance, exactly one multipart file, actual 10,000,000-byte maximum, and one submission per student/assignment. Calculate checksum, use opaque object keys, write the object, then recheck/commit metadata. Compensate failed commits with object deletion and provide a cautious aged-orphan cleanup.

Require submitted work for grading, bound grade to 0-100, preserve optional comments, and authorize downloads by owner/admin. Use safe attachment filenames. Add real submission/grade demo fixtures without bypassing the storage constraints.

Checks: exact size boundary and one-byte overflow, empty/arbitrary-format files, multiple upload parts, enrollment/ownership, deadline edge, concurrent duplicates, archived assignment behavior, storage failure, database failure after upload, orphan cleanup, grade CRUD/bounds, and binary download checksum.

Acceptance: no successful submission points at a missing object; other students cannot read it; grade/report data retain historical integrity.

Commit: `feat: add private assignment submissions and administrator grading`.

## Task 8: PDF report and complete OpenAPI contract

Dependencies: Tasks 3, 5, 7. Status: complete.

Create `CourseReportService`, report/download controller/resource, and `templates/reports/course.html.twig`. Render escaped names/comments, student-assignment rows, submission status, grades, averages of recorded grades, and UTC generation time with Dompdf. Keep assets/fonts local and disable remote fetching.

Add generated OpenAPI descriptions for JWT auth, idempotency header, payment DTO, file upload, expected problem responses, pagination, and binary report/download output. Export `output/openapi/openapi.json` from actual resource metadata rather than maintain a second hand-written contract.

Checks:

```sh
php bin/console api:openapi:export --output=output/openapi/openapi.json
make test-functional
```

Verify every required route appears in the exported contract, credentials/hash fields do not appear in output models, report rows/averages agree with MySQL, missing grades stay ungraded, and HTML-like student names/comments are escaped. Render a generated report and inspect page layout; correct clipping/overflow before delivery.

Acceptance: Swagger can exercise the documented business flows and the PDF is readable and correct.

Commit: `feat: export course PDF reports and generated API documentation`.

## Task 9: application Helm chart and local deployment scripts

Dependencies: Tasks 1-8. Status: complete.

Create the application chart, ordinary/resilient/baseline/local values, templates for API/worker/mock workloads, MySQL/RustFS/queue Redis stateful storage, cache Redis, services, config/secrets, migration/seed/bucket-init jobs, health checks, graceful rollout/disruption controls, Gateway API routes, and namespace-scoped RBAC where needed.

Queue Redis uses AOF and no eviction; cache Redis has bounded memory and an eviction policy. RustFS/MySQL/queue volumes survive pod replacement. Keep optional topology spread compatible with OrbStack's one node. Configure per-operation deadlines and request limits with multipart overhead; only safe reads may get Envoy retries.

Create `scripts/setup-local.sh`, `scripts/check.sh`, and environment/version inventory helpers. Setup checks the explicit context, starts local Kubernetes if needed, inspects existing workloads/storage/server version, resolves/pins compatible Envoy/images/charts, builds a local tagged image, and installs releases without changing the global context. Generate local secrets and pass them through files/Kubernetes Secrets without echoing them into logs. Skip or reuse only exact matching owned resources.

Use idempotent deployment jobs and ensure migrations complete before API/worker readiness. Verify Gateway/HTTPRoute status and LoadBalancer host routing. Provide documented port-forward alternatives.

Checks:

```sh
helm lint helm/university
helm template university helm/university -f helm/university/values-local.yaml
make helm-check
make setup-local
kubectl --context orbstack -n midterm get pods,services,pvc,jobs
make smoke
```

Acceptance: the resilient release actually runs on `orbstack`, all required persistence is bound, two stateless replicas are ready, seeded API flows work, and no deployment touched the user's other context.

Commit: `deploy: add Helm profiles and OrbStack setup`.

## Task 10: provision logs, metrics, Grafana, and official dashboard

Dependencies: Tasks 1, 6, 9. Status: complete.

Create Monolog JSON configuration, resettable request/job correlation processors, Symfony request logging, and sanctioned outcome events. Add the business metrics exporter and health/backlog indicators; avoid user/purchase IDs as metric labels.

Create a small observability chart with pinned Grafana, Prometheus, VictoriaLogs single, and VictoriaLogs collector configuration/dependencies. Set seven-day retention, persistence, project-namespace log collection, and bounded collector disk buffering. Ensure Monolog JSON fields are queryable through ingestion parsing or explicit LogsQL JSON unpacking, and verify the actual field names.

Configure Prometheus Kubernetes discovery/RBAC and scrape each RoadRunner pod on 2112 plus Envoy and worker metrics. Provision datasource UIDs and the VictoriaLogs plugin. Extract the official RoadRunner HTTP dashboard at the pinned source commit from `docs.md`, save its JSON/source/license, adjust datasource/pod/profile selectors and mislabeled rate/time units, and create focused payment/log dashboards.

Expose Grafana through the approved Envoy host route. Verify dashboards/Explore through the product-native T3 collaborative preview tools, beginning with `preview_status` and opening the preview if needed. Use API checks as well as UI checks. Never substitute an unrelated browser merely because the preview starts closed.

Acceptance: no manual datasource/import step is needed; metric panels show real series from all replicas; a known API/purchase request ID is searchable; logs remain after replacing the originating pod; credentials/payment details do not leak into logs.

Commit: `observability: provision RoadRunner Grafana dashboards and VictoriaLogs`.

## Task 11: backup, restore, and controlled failure experiments

Dependencies: Tasks 6-10. Status: complete.

Create `BackupDatabaseCommand`, isolated restore verification, a 15-minute backup CronJob, retention cleanup, and checksum/snapshot inventory. Never overwrite the live database for a demonstration. Restore to a temporary database/workload and verify schema/record/grade/enrollment invariants.

Create host-side Python workload/probe tools and guarded namespace-scoped injection scripts for application crash, MySQL outage, mock outage/timeout, interrupted payment/outbox publication, high load, queue/cache outage, and backup restore. Create a separately guarded OrbStack node-outage script and inspect unrelated workloads before deciding whether it can run in this authorized environment.

Run identical seed/workload configurations sequentially against baseline and resilient profiles. Perform three repetitions of automated scenarios. Include scenario checkpoint identity, image/config hashes, UTC/monotonic timestamps, request status/latency, detection/recovery boundaries, and post-run consistency results in raw JSONL/CSV. Restore every modified replica count/setting in cleanup handlers. Bound each experiment and handle Ctrl-C cleanup.

Calculate measured request/time availability, outage counts, MTTR, operating-time MTBF, first-failure MTTF with censoring, failure rate, and unique recovered operation counts. Preserve unavailable/undefined estimates rather than divide by zero or invent numbers. Scope each statistic to the actual experiment window and define expected 4xx/decline/rate-limit responses separately.

Acceptance: datasets substantiate at least three failure/recovery demonstrations; the interrupted-payment and outbox scenarios recover without duplicates; backup restore is checked; node outage is either measured truthfully or explicitly identified as unrun for a concrete environmental reason.

Commit: `test: add measured fault injection and backup recovery experiments`.

## Task 12: final verification, documentation, and handoff

Dependencies: Tasks 1-11. Status: complete.

Run the agreed checks once against the completed implementation and rerun only affected checks after fixes. Complete the README/setup quick path, update `docs.md` from approved design into the assignment's final technical report, record resolved versions/runtime settings/limitations, and link actual experiment data and dashboard/report evidence. Retain the approved design/plan history in Git.

Generate `docs/architecture.svg` from the final diagram and verify it reflects the deployed components; keep Mermaid and rendered output consistent. Document seed credentials and generated secret access without committing generated secret values. Provide a short demo recipe for pod crash, interrupted payment, and queue/cache recovery, with backup and whole-cluster recovery as additional demonstrations where measured.

Final checks:

```sh
make check
make helm-check
make smoke
composer validate --strict
composer check-platform-reqs
php bin/console doctrine:schema:validate
git diff --check
kubectl --context orbstack -n midterm get pods,jobs,pvc
```

Verify exported OpenAPI matches deployed routes, Grafana metrics/logs remain available, report output is readable, the final architecture matches the actual cluster, and all measured claims link to raw evidence. End with the API/Swagger/Grafana URLs, setup/test commands, key files, verification results, and any material unfinished environmental experiment. Do not claim that single-node replicas survived whole-node loss.

Commit: `docs: publish setup architecture and experimental evaluation`.

## Dependencies and review checkpoints

Tasks 1-3 establish runtime/data/access; Tasks 4-6 establish payments and recovery; Tasks 7-8 complete academic flows and the contract; Tasks 9-10 establish deployment/observability; Tasks 11-12 evaluate and deliver. The recommended execution follows this order even where a task is technically independent.

Within implementation, checkpoints are technical verification points, not repeated permission gates. Fix routine failures autonomously. If a library or cluster constraint forces a material scope/interface change, explain the concrete issue and update the design/plan; do not quietly remove a requirement.

## Planning references

- [RoadRunner Symfony bundle and reset/reboot integration](https://github.com/Baldinof/roadrunner-bundle).
- [API Platform Symfony package metadata](https://packagist.org/packages/api-platform/symfony).
- [DoctrineBundle package metadata](https://packagist.org/packages/doctrine/doctrine-bundle).
- [Doctrine migrations bundle package metadata](https://packagist.org/packages/doctrine/doctrine-migrations-bundle).
- [PHPUnit package metadata](https://packagist.org/packages/phpunit/phpunit).
- [Envoy Gateway compatibility matrix](https://gateway.envoyproxy.io/news/releases/matrix/).
- [Envoy Gateway Helm quickstart](https://gateway.envoyproxy.io/docs/tasks/quickstart/).
- [VictoriaLogs Kubernetes collector](https://docs.victoriametrics.com/helm/victoria-logs-collector/).
- Additional component references are recorded in the approved specification.

## Delivery record

Tasks 1–8 were implemented and verified together in the application commit; deployment/monitoring, resilience checks, and final artifacts follow in grouped commits. Final file paths and measured results are indexed in [docs.md](../../docs.md). Eight tests / 98 assertions, static checks, Helm checks, live payment/file/report smoke, three repetitions of availability and queue/checkpoint failures, and isolated restore passed. The node-outage branch was not executed because unrelated OrbStack workloads were present; the separately guarded script and limitation are delivered as specified.
