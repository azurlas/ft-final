# University backend

PHP 8.3, RoadRunner, Symfony/API Platform, Doctrine ORM/MySQL, Redis Messenger queues, Supervisor, RustFS S3, Envoy Gateway and Helm. Grafana includes the official RoadRunner HTTP dashboard, application metrics, alert rules, and VictoriaLogs.

The final project runs on a local single-node OrbStack Kubernetes cluster. Plans: [final project](docs/plans/2026-11-06-final-project-plan.md), [local cluster](docs/plans/2026-11-06-local-cluster-plan.md). The midterm state, including its report and raw evaluation data, is preserved under the git tag `midterm`.

## Deploy locally

Requires OrbStack (Docker + Kubernetes), Helm (`brew install helm`), Python 3 and `kubectl`. No PHP is needed on the host.

```sh
make setup-local
```

The script starts OrbStack Kubernetes if needed, builds the image `midterm/university:local-<timestamp>` (recorded in `output/image-tag.txt`), generates ignored secrets in `output/secrets/`, and installs Envoy Gateway, both application profiles and monitoring. Every command names the `orbstack` context explicitly. Re-run it to deploy code changes.

| Namespace | Profile | URL |
| --- | --- | --- |
| `midterm` | Resilient: 2 API, 2 workers, 2 Envoy, backups | http://midterm.k8s.orb.local/ (Swagger: `/api/docs.html`) |
| `midterm-baseline` | Baseline: 1 API, 1 worker, 1 Envoy, no backups | http://baseline.midterm.k8s.orb.local:8080/ |
| `midterm-monitoring` | Prometheus, Grafana, VictoriaLogs | http://grafana.midterm.k8s.orb.local:3000/ |
| `midterm-gateway` | Envoy Gateway controller | — |

Grafana login is `admin`; the password is in `output/secrets/grafana.env`. Prometheus alerts: Grafana → Explore, or `kubectl --context orbstack -n midterm-monitoring port-forward svc/prometheus 9090` and open http://localhost:9090/alerts. Application demo logins are `admin` / `AdminPass123!`, and `student` or `other` / `StudentPass123!`.

`orb stop k8s` pauses the whole cluster and keeps all volumes; `orb start k8s` resumes it. Traffic is plain HTTP locally.

## Experiments

```sh
python3 scripts/smoke.py                                    # payment fixtures, files, grades, PDF
python3 scripts/experiments.py --only availability          # API crash, cache and MySQL outage (B vs FT)
python3 scripts/experiments.py --only queue                 # lost queue message (B vs FT)
python3 scripts/experiments.py --only checkpoints           # worker crash at payment checkpoints (FT)
python3 scripts/broker-recovery.py                          # queue Redis outage during checkout (FT)
python3 scripts/experiments.py --only timeout               # slow provider vs 2 s deadline (B vs FT)
python3 scripts/experiments.py --only circuit               # circuit breaker opens and defers calls (FT)
python3 scripts/experiments.py --only spike                 # load spike (B vs FT)
python3 scripts/experiments.py --only security              # misuse cases denied and audited (FT)
python3 scripts/experiments.py --only alerts --repetitions 1  # alert detection and log correlation (FT)
python3 scripts/experiments.py --only restore              # backup + isolated restore verification (FT)
python3 scripts/observability-check.py
python3 scripts/summarize.py
```

Results go to `output/final/`. The scripts only touch the four project namespaces and restore changed replica counts in `finally` blocks. `--only restore` runs these two commands and records their timing; to run them by hand:

```sh
kubectl --context orbstack -n midterm exec deploy/api -c api -- php bin/console app:backup
kubectl --context orbstack -n midterm exec deploy/api -c api -- php bin/console app:restore-verify mysql/<returned-key>.sql.gz
```

## Tests

```sh
make check-docker   # Composer, lint, PHPUnit, PHPStan, schema, OpenAPI export, inside the app image
make helm-check     # lint and render both charts (local and Contabo value files)
npm ci && make frontend-check
make test-deps-down
```

`make check-docker` starts the `compose.test.yaml` dependencies (MySQL, two Redis, RustFS) and runs `scripts/check.sh` in the PHP 8.3 image, so only Docker is needed. With PHP 8.3 and Composer on the host, `make test-deps-up && make check` does the same.

## API

Campus uses separate HTML pages served directly by RoadRunner from `public/ui/`, with shared CSS/JavaScript and no runtime library or build step. Students can register, enroll, purchase, submit/download files, and view grades. Administrators can manage courses/assignments, grade submissions, and download PDF reports. The mock checkout offers all six synthetic scenarios.

Authenticate with `POST /api/login`, JSON `{ "login": "student", "password": "StudentPass123!" }`, then use `Authorization: Bearer <token>`. In Swagger, paste the token into Authorize. Prices are integer USD cents; null or zero is free. Paid checkout requires an `Idempotency-Key` header. [Synthetic payment payloads](docs/examples) cover success, one/two transient retries, exhausted retries, decline, and timeout. These fixtures use expiry `12/2035` and CVV `123`; the payment DTO accepts only four fields.

Students enroll using `POST /api/courses/{id}/enroll`, purchase using `/purchase`, list their `/api/purchases`, and submit one multipart field named `file` to `POST /api/assignments/{id}/submit`. Files may have any format, up to exactly 10,000,000 bytes; submissions cannot be replaced. Downloads require owner/admin authentication. Administrators manage `/api/courses`, `/api/assignments`, `/api/grades` and download `/api/courses/{id}/report`.

## Optional: public deployment on Contabo (Argo CD)

Not used for the final-project experiments. It shows the production-like setup: GitOps rebuild from git, SealedSecrets, and TLS at a shared edge gateway.

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
