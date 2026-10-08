# University backend

PHP 8.3, RoadRunner, Symfony/API Platform, Doctrine ORM/MySQL, Redis Messenger queues, Supervisor, RustFS S3, Envoy Gateway and Helm. Grafana includes the official RoadRunner HTTP dashboard, application metrics, and VictoriaLogs.

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

Requires Docker/OrbStack, its Kubernetes cluster, PHP 8.3 with the extensions in `composer.json`, Composer, Helm, Python 3, and `mkcert` (`brew install mkcert`). Setup starts OrbStack Kubernetes if needed and always selects `orbstack` explicitly. It restores the original global kubectl context if OrbStack startup changes it. Generated application secrets live in ignored `output/secrets/` files and are reused on upgrades. Set `KUBECTL_BIN` to override the local CLI path.

```sh
make start  # Initial setup, or resume the existing deployment without rebuilding
make stop   # Stop project workloads; preserve databases, files, metrics and secrets
```

These commands affect only `midterm`, `midterm-baseline`, `midterm-monitoring` and `midterm-gateway` in the `orbstack` context. Stop saves replica counts and scheduling settings in ignored `output/deployment-state.json`, scales Deployments/StatefulSets to zero, pauses backup jobs and the log collector, and waits for graceful shutdown. Start restores those settings and waits for readiness. Keep the state file until resuming; repeated stop preserves the original settings. The OrbStack cluster itself and unrelated namespaces remain running. Use `make setup-local` to rebuild/redeploy changes while the deployment is running.

| Endpoint | URL |
| --- | --- |
| Campus frontend | https://midterm.k8s.orb.local/ |
| API / Swagger | https://midterm.k8s.orb.local/api/docs.html |
| OpenAPI JSON | https://midterm.k8s.orb.local/api/docs.jsonopenapi |
| Baseline API | http://baseline.midterm.k8s.orb.local:8080/api/docs.html |
| Grafana | http://grafana.midterm.k8s.orb.local:3000 |

OrbStack uses separate listener ports for these local load balancers. Grafana login is `admin`; read its generated password with `cat output/secrets/grafana.env`. Application demo logins are `admin` / `AdminPass123!`, and `student` or `other` / `StudentPass123!`. These are deliberately local demo accounts.

The main Envoy gateway serves HTTP on 80 and HTTPS on 443. Setup runs `mkcert -install`, issues a certificate for `midterm.k8s.orb.local`, and installs its leaf certificate/key as the `midterm/university-tls` Secret. TLS files stay in ignored `output/secrets/tls/`; the mkcert CA key stays on your Mac. `make setup-local-tls` renews the leaf certificate and updates the existing Secret. OrbStack's domain proxy also presents its own trusted certificate at `.orb.local`, while direct gateway connections use mkcert. `make tls-check` verifies both paths without disabling certificate verification.

Campus uses separate HTML pages served directly by RoadRunner from `public/ui/`, with shared CSS/JavaScript and no runtime library or build step. Navigation uses ordinary links and full page loads. The root opens `/ui/courses.html`; `/ui/index.html` redirects to that page. Courses, assignments, submissions, grades, purchases, sign-in, registration, checkout and assignment upload each have their own URL. Students can register, enroll, purchase, submit/download files, and view grades. Administrators can manage courses/assignments, grade submissions, and download PDF reports. The mock checkout offers all six synthetic scenarios and polls pending purchases every three seconds. Login is kept in the current tab's `sessionStorage`; sign-out clears it. All API requests use the same origin.

Frontend validation uses Node.js 22+ only for development: `npm ci && make frontend-check`. Run `make frontend-smoke` to verify the deployed static assets, ETags, source protection, and the live API contracts used by student/admin forms. `make frontend-upload-check` verifies the actual page submit handler against the live HTTPS API, including a 10,000,000-byte upload and private download checksum (jsdom, not a rendered browser). `make setup-local` deploys the frontend together with the API.

Application reads and writes use Doctrine entities through repository classes in `src/Repository/`. Repository `save()` stages `persist()` without flushing. `src/Persistence/Transaction.php` flushes once at the end of a request, command batch or transactional handler and commits atomically. Payment claim and settlement are separate handlers so the attempt is durable before the provider call. Service classes are `readonly`; command dependencies are readonly properties. Mutable entities and serializer-populated DTOs remain writable. Run `php bin/console doctrine:migrations:migrate --no-interaction` before using the updated code; the new migration adds the outbox-to-purchase foreign key.

Authenticate with `POST /api/login`, JSON `{ "login": "student", "password": "StudentPass123!" }`, then use `Authorization: Bearer <token>`. In Swagger, paste the token into Authorize. Prices are integer USD cents; null or zero is free. Paid checkout requires an `Idempotency-Key` header. [Synthetic payment payloads](docs/examples) cover success, one/two transient retries, exhausted retries, decline, and timeout. These fixtures use expiry `12/2035` and CVV `123`; the payment DTO accepts only four fields.

Students enroll using `POST /api/courses/{id}/enroll`, purchase using `/purchase`, list their `/api/purchases`, and submit one multipart field named `file` to `POST /api/assignments/{id}/submit`. Files may have any format, up to exactly 10,000,000 bytes; submissions cannot be replaced. Downloads require owner/admin authentication. Administrators manage `/api/courses`, `/api/assignments`, `/api/grades` and download `/api/courses/{id}/report`.

Run `python3 scripts/experiments.py` for the namespace-scoped fault demonstrations (several minutes). It restores changed replica counts. Whole-cluster interruption is separately guarded because this OrbStack cluster also runs unrelated applications. The detailed design, limitations, observed results, formulas, and architecture are in [docs.md](docs.md); the implementation plan is in [docs/plans](docs/plans/2026-10-05-university-backend-implementation.md).

For a port-forward fallback:

```sh
kubectl --context orbstack -n midterm port-forward svc/api 8081:8080
kubectl --context orbstack -n midterm-monitoring port-forward svc/grafana 3001:3000
```

Swagger becomes `http://localhost:8081/api/docs.html`; Grafana becomes `http://localhost:3001`. These forwards bypass Envoy, so use the public gateway URLs for gateway/fault experiments. `make test-deps-down` stops only this project's Docker test dependencies. Helm uninstall removes workloads; StatefulSet PVCs remain for deliberate recovery. Do not delete PVCs to upgrade or restart.

## Contabo deployment (Argo CD)

The public deployment runs on the single-node RKE2 cluster `contabo-6` (169.58.83.121) and is driven by Argo CD from `main` of this repository.

| Endpoint | URL |
| --- | --- |
| Campus frontend / API | https://midterm.sailaubek.dev/ (Swagger: `/api/docs.html`) |
| Baseline profile | https://baseline.sailaubek.dev/ |
| Grafana | https://grafana.sailaubek.dev/ |
| Argo CD | https://argocd.sailaubek.dev/ |

`deploy/argocd/root.yaml` is an app of apps over `deploy/argocd/apps/`, ordered by sync wave: Sealed Secrets, local-path storage (`Retain`), Envoy Gateway with the Gateway API CRDs, then `deploy/platform/` (namespaces, edge Gateway, Argo CD route, SealedSecrets), both university profiles, and monitoring. Argo CD also manages itself with `deploy/argocd/values.yaml`. RKE2 runs with `ingress-controller: none` and `enable-servicelb: true` (`/etc/rancher/rke2/config.yaml`). The Envoy edge Gateway owns ports 80/443, redirects HTTP to HTTPS, and terminates TLS with a Let's Encrypt `*.sailaubek.dev` certificate. The charts' `externalGateway` value attaches their HTTPRoutes to that shared Gateway instead of creating per-release gateways, so rate limits and request buffering still apply per route.

Each push to `main` that changes application code runs `.github/workflows/image.yaml`. It publishes `ghcr.io/fugikzl/ft-midterm:<sha>` and commits that tag to `deploy/image.yaml`, which Argo CD then rolls out. Helm, deploy and docs changes sync without a rebuild.

Secrets in git are SealedSecrets encrypted with `deploy/sealed-secrets.pem`; only the in-cluster controller can decrypt them. `python3 deploy/seal-secrets.py` generates any missing plaintext values into ignored `output/secrets/contabo/` and seals them. Pass a name (`university-midterm`, `university-midterm-baseline`, `grafana`, `wildcard-tls`) to reseal it. The controller's private key is backed up to `output/secrets/contabo/sealed-secrets-key.yaml`; without it, a rebuilt cluster cannot decrypt the committed secrets.

Local cluster access goes through an SSH tunnel:

```sh
ssh -fN -L 16443:127.0.0.1:6443 contabo-6
export KUBECONFIG=output/secrets/contabo/kubeconfig   # copy of /etc/rancher/rke2/rke2.yaml pointed at 127.0.0.1:16443
kubectl -n argocd get secret argocd-initial-admin-secret -o jsonpath='{.data.password}' | base64 -d   # Argo CD admin password
cat output/secrets/contabo/grafana.env                                                                # Grafana admin password
```

To rebuild from an empty cluster, apply the RKE2 config above, run `bash deploy/bootstrap.sh`, then restore the Sealed Secrets key with `kubectl apply -f output/secrets/contabo/sealed-secrets-key.yaml` and restart the controller.

The wildcard certificate (expires 2027-01-05) is issued manually because DNS is edited by hand in Cloudflare. To renew it, run `certbot certonly --manual --preferred-challenges dns -d '*.sailaubek.dev'` on `contabo-6` and publish the shown `_acme-challenge` TXT record. Then run `python3 deploy/seal-secrets.py wildcard-tls` and commit the result.
