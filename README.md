# University backend

PHP 8.3, RoadRunner, Symfony/API Platform, Doctrine/MySQL, Redis Messenger queues, Supervisor, RustFS S3, Envoy Gateway and Helm. Grafana includes the official RoadRunner HTTP dashboard, application metrics, and VictoriaLogs.

```sh
composer install
make test-deps-up
php bin/console lexik:jwt:generate-keypair
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console app:seed-demo
php bin/console app:storage-init
make check
make setup-local
make smoke
```

Requires Docker/OrbStack, its Kubernetes cluster, PHP 8.3 with the extensions in `composer.json`, Composer, Helm, and Python 3. Setup starts OrbStack Kubernetes if needed and always selects `orbstack` explicitly. It never changes the global kubectl context. Generated secrets live in ignored `output/secrets/` files and are reused on upgrades. Set `KUBECTL_BIN` to override the local CLI path.

| Endpoint | URL |
| --- | --- |
| API / Swagger | http://midterm.k8s.orb.local/api/docs.html |
| OpenAPI JSON | http://midterm.k8s.orb.local/api/docs.jsonopenapi |
| Baseline API | http://baseline.midterm.k8s.orb.local:8080/api/docs.html |
| Grafana | http://grafana.midterm.k8s.orb.local:3000 |

OrbStack uses separate listener ports for these local load balancers. Grafana login is `admin`; read its generated password with `cat output/secrets/grafana.env`. Application demo logins are `admin` / `AdminPass123!`, and `student` or `other` / `StudentPass123!`. These are deliberately local demo accounts.

Authenticate with `POST /api/login`, JSON `{ "login": "student", "password": "StudentPass123!" }`, then use `Authorization: Bearer <token>`. In Swagger, paste the token into Authorize. Prices are integer USD cents; null or zero is free. Paid checkout requires an `Idempotency-Key` header. [Synthetic payment payloads](docs/examples) cover success, one/two transient retries, exhausted retries, decline, and timeout. These fixtures use expiry `12/2035` and CVV `123`; the payment DTO accepts only four fields.

Students enroll using `POST /api/courses/{id}/enroll`, purchase using `/purchase`, list their `/api/purchases`, and submit one multipart field named `file` to `POST /api/assignments/{id}/submit`. Files may have any format, up to exactly 10,000,000 bytes; submissions cannot be replaced. Downloads require owner/admin authentication. Administrators manage `/api/courses`, `/api/assignments`, `/api/grades` and download `/api/courses/{id}/report`.

Run `python3 scripts/experiments.py` for the namespace-scoped fault demonstrations (several minutes). It restores changed replica counts. Whole-cluster interruption is separately guarded because this OrbStack cluster also runs unrelated applications. The detailed design, limitations, observed results, formulas, and architecture are in [docs.md](docs.md); the implementation plan is in [docs/plans](docs/plans/2026-10-05-university-backend-implementation.md).

For a port-forward fallback:

```sh
kubectl --context orbstack -n midterm port-forward svc/api 8081:8080
kubectl --context orbstack -n midterm-monitoring port-forward svc/grafana 3001:3000
```

Swagger becomes `http://localhost:8081/api/docs.html`; Grafana becomes `http://localhost:3001`. These forwards bypass Envoy, so use the public gateway URLs for gateway/fault experiments. `make test-deps-down` stops only this project's Docker test dependencies. Helm uninstall removes workloads; StatefulSet PVCs remain for deliberate recovery. Do not delete PVCs to upgrade or restart.
