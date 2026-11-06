# University backend

PHP 8.3, RoadRunner, Symfony/API Platform, Doctrine ORM/MySQL, Redis Messenger queues, Supervisor, RustFS S3, Envoy Gateway and Helm. Grafana includes the official RoadRunner HTTP dashboard, application metrics, and VictoriaLogs.

The system runs on Contabo, deployed by Argo CD (see below). The final-project plan is in [docs/plans](docs/plans/2026-11-06-final-project-plan.md). The midterm state, including its report and raw evaluation data, is preserved under the git tag `midterm`.

## Local development and tests

Requires Docker, PHP 8.3 with the extensions in `composer.json`, Composer, Helm and Python 3. Node.js 22+ is needed only for the frontend tests.

```sh
composer install
make test-deps-up
php bin/console lexik:jwt:generate-keypair
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console app:seed-demo
php bin/console app:storage-init
make check          # Composer, lint, PHPUnit, PHPStan, schema, OpenAPI export
make helm-check     # lint and render both charts with the Contabo value files
npm ci && make frontend-check
make test-deps-down
```

Application demo logins are `admin` / `AdminPass123!`, and `student` or `other` / `StudentPass123!`. These are deliberately public demo accounts for the course demo only.

## API

Campus uses separate HTML pages served directly by RoadRunner from `public/ui/`, with shared CSS/JavaScript and no runtime library or build step. Students can register, enroll, purchase, submit/download files, and view grades. Administrators can manage courses/assignments, grade submissions, and download PDF reports. The mock checkout offers all six synthetic scenarios.

Authenticate with `POST /api/login`, JSON `{ "login": "student", "password": "StudentPass123!" }`, then use `Authorization: Bearer <token>`. In Swagger, paste the token into Authorize. Prices are integer USD cents; null or zero is free. Paid checkout requires an `Idempotency-Key` header. [Synthetic payment payloads](docs/examples) cover success, one/two transient retries, exhausted retries, decline, and timeout. These fixtures use expiry `12/2035` and CVV `123`; the payment DTO accepts only four fields.

Students enroll using `POST /api/courses/{id}/enroll`, purchase using `/purchase`, list their `/api/purchases`, and submit one multipart field named `file` to `POST /api/assignments/{id}/submit`. Files may have any format, up to exactly 10,000,000 bytes; submissions cannot be replaced. Downloads require owner/admin authentication. Administrators manage `/api/courses`, `/api/assignments`, `/api/grades` and download `/api/courses/{id}/report`.

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
