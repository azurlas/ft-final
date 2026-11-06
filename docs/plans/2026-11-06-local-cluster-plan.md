# Local cluster plan (OrbStack Kubernetes)

Decision (Nariman, 2026-11-06): the final project does not need the VPS. Everything is deployed to, and every experiment runs on, a local single-node OrbStack Kubernetes cluster. This replaces "Contabo only" in the [main plan](2026-11-06-final-project-plan.md). The rest of that plan still applies: the work packages, the experiment matrix and the team split.

## Current state of this machine

| Item | State | Action |
| --- | --- | --- |
| OrbStack | Running, Docker context `orbstack`, VM memory 8 GiB, 8 CPUs (16 GiB host) | Enough: the midterm ran both profiles + monitoring on OrbStack |
| OrbStack Kubernetes | Disabled (`k8s.enable: false`) | `orb start k8s` (setup script does it) |
| kubectl | Installed; current context is a dead `kind-ft-midterm` cluster | Scripts always pass `--context orbstack`; optionally `kubectl config delete-context kind-ft-midterm` |
| Helm | Missing | `brew install helm` |
| PHP / Composer | Missing on the host | Not needed: build in Docker; run `make check` in a container (§2.4) |
| mkcert | Missing | Not needed: local runs use plain HTTP (§1) |
| App image | `midterm/university:dev` already built in OrbStack's Docker | OrbStack Kubernetes uses OrbStack's Docker images directly, no registry |

## Status (2026-11-06)

Steps 1–5 done on Sultan's Mac: OrbStack Kubernetes v1.36.5, Helm 4.3.1, image `midterm/university:local-20261106112858`, all 4 namespaces healthy, `smoke.py` passes against the resilient profile, `make check-docker` passes (12 tests / 140 assertions).

Findings:
- **Alert labels:** correct. All 5 rules load and are inactive. The live series carry `envoy_response_code`, `envoy_cluster_name="httproute/midterm/university/rule/0"` and `profile`.
- **Client IP:** every local request arrives as `192.168.194.1` (OrbStack's host gateway), so the per-IP login limit is one shared limit locally. Mention this in the report.
- **Envoy request IDs:** Envoy assigns UUID `X-Request-ID`s; the app keeps them, so log correlation uses the gateway's ID end to end.
- **Bug fixed: Grafana had no VictoriaLogs plugin.** `GF_PLUGINS_PREINSTALL_DISABLED=true` also skipped `GF_PLUGINS_PREINSTALL_SYNC`, so the logs datasource, the dashboard's log panel and every log query returned "Unable to find datasource plugin". The chart now drops that variable (the Contabo deployment had the same bug).
- **Security trial on the cluster:** 10/10 cases returned the expected status and were found in VictoriaLogs by request ID.
- The first `smoke.py` run right after deploy failed once with "No route to host" while the `.orb.local` route warmed up; later runs passed. Wait about a minute after deploy before experiments.

## 1. Target setup

Same layout as the midterm, so the midterm comparison still holds:

| Namespace | Contents | URL |
| --- | --- | --- |
| `midterm` | Resilient profile: 2 API, 2 workers, 2 Envoy replicas, backups, PDB | `http://midterm.k8s.orb.local` |
| `midterm-baseline` | Baseline profile: 1 API, 1 worker, 1 Envoy, no backups | `http://baseline.midterm.k8s.orb.local:8080` |
| `midterm-monitoring` | Prometheus (with the new alert rules), Grafana, VictoriaLogs + collector | `http://grafana.midterm.k8s.orb.local:3000` |
| `midterm-gateway` | Envoy Gateway controller 1.9.2 | — |

Each profile gets its own Envoy gateway again (`externalGateway` empty). This restores the midterm's difference: the baseline has 1 gateway replica and the resilient profile has 2.

Simplifications compared with the midterm, to keep the defense easy:
- **No TLS / mkcert.** Local traffic is plain HTTP. The Contabo manifests (`deploy/platform/gateway.yaml`) show the production TLS setup, and the threat model cites them.
- **No `make start/stop`.** `orb stop k8s` / `orb start k8s` pause and resume the whole cluster and keep all volumes.
- **No Argo CD locally.** Remove the Argo CD pause from `scripts/env.py`: with no Argo CD there is no self-heal to fight, so the code would only add things to explain.

Contabo files (`deploy/`, `values-contabo*.yaml`, the image workflow) stay in the repo untouched as the optional public/GitOps deployment (mechanism H8). No experiment depends on them. If the team would rather delete them, that is a separate small change.

## 2. Changes

### 2.1 Deployment script — restore and simplify (Nariman)

Restore `scripts/setup-local.sh` from the `midterm` tag (`git show midterm:scripts/setup-local.sh`) and change:
- Use `kubectl` from PATH and keep the explicit `--context orbstack` everywhere; drop `/Users/nariman/...`.
- Remove the `setup-local-tls.sh` call.
- Generate JWT keys with `openssl` (no host PHP), only if `config/jwt/*.pem` is missing.
- Keep: secret generation into ignored `output/secrets/`, the Envoy Gateway Helm install + GatewayClass, both university releases, VictoriaLogs, the collector, monitoring, and writing `output/image-tag.txt`.
- Image tag `midterm/university:local-<timestamp>`, so every deploy is traceable in the results inventory.

Add `helm/university/values-local.yaml` (image + `storageClass: local-path`, no TLS). Point the defaults back at local hostnames: `values.yaml` → `midterm.k8s.orb.local`, `values-baseline.yaml` → `baseline.midterm.k8s.orb.local` with `gatewayPort: 8080`, `helm/monitoring/values.yaml` → `grafana.midterm.k8s.orb.local`. `values-contabo*.yaml` already override the hostnames, so the Contabo render stays identical. Check this with the render diff used in step 1.

Makefile: add back `setup-local`. `helm-check` renders the local value files and the Contabo ones.

### 2.2 Experiment scripts → local (Nariman)

`scripts/env.py`:
- Context `orbstack` (`--context orbstack`), no kubeconfig file.
- URLs above (HTTP); Grafana password from `output/secrets/grafana.env`.
- `ENVIRONMENT = 'orbstack-local'`; namespace allowlist without `argocd`.
- Delete `pause_argocd` / `resume_argocd` / `argocd_paused`, and their call sites in `experiments.py` and `broker-recovery.py`.

No changes are needed in `scenarios.py`: it reads everything from `env.py`.

### 2.3 Things to re-check on the local cluster (Sultan)

- **Alert labels:** Envoy's `envoy_cluster_upstream_rq{envoy_response_code=...}` and the `envoy_cluster_name` label exist as written in `AuthFailureSpike`. Check in Prometheus right after the first deploy.
- **Client IP:** through OrbStack's load balancer every request probably arrives from the same host IP. Then the per-IP login limit (60/min) is effectively global. That is fine for the experiments (they log in far less often), but say so in the report.
- **Spike vs. Envoy rate limit:** each Envoy pod allows 200 requests/s. The spike at 40 clients will probably hit it locally (latency is low, so request rates are high). Report the 429s as admission control. If they hide the latency picture, lower the spike concurrency.

### 2.4 Checks without host PHP (Sultan)

Add `scripts/check-docker.sh` + `make check-docker`. It starts `compose.test.yaml`, runs `composer install` + migrations + seed + `scripts/check.sh` inside the app image on the compose network (the same commands used to verify the security work), and stops nothing by default. This lets all three team members run the PHP checks.

### 2.5 README and docs (Altair)

Make the README local-first: prerequisites (OrbStack with Kubernetes, Helm, Python 3), `make setup-local`, the URLs and logins, the Grafana password, `orb stop/start k8s`, `make check-docker`, and how to run each experiment. Keep a short "Optional: public deployment via Argo CD" section. In the main plan, change the environment decision and the §2 removal table: OrbStack setup is restored and mkcert, TLS and start/stop stay removed.

## 3. Order of work

| # | Step | Owner | Done when |
| --- | --- | --- | --- |
| 1 | `brew install helm`; `orb start k8s` | Sultan | `kubectl --context orbstack get nodes` shows Ready |
| 2 | §2.1 setup script, values, Makefile; render diff for Contabo is empty | Nariman | `make setup-local` deploys all 4 namespaces; Campus, both Swagger UIs and Grafana answer |
| 3 | §2.2 env.py → local | Nariman | `python3 scripts/smoke.py` passes against the resilient profile |
| 4 | §2.3 checks; fix alert labels if needed | Sultan | Prometheus `/alerts` lists the 5 rules, all inactive |
| 5 | §2.4 `make check-docker` | Sultan | 12 tests pass in the container |
| 6 | Write the hypotheses and criteria in `docs/experiments.md` **before** running | Altair | Reviewed by one teammate |
| 7 | Short trial run of each scenario (`--repetitions 1`) | Nariman + Sultan | Every scenario writes its JSON; fix script bugs |
| 8 | Final runs on one image tag (see below) | Nariman + Sultan | All JSON files in `output/final/`, image tag recorded |
| 9 | `summarize.py` consolidated table (all 8 experiments, B vs FT); screenshots of Grafana with alerts firing, the Prometheus alerts page and a VictoriaLogs request-ID search | Sultan | Table pasted into the report |
| 10 | README + docs | Altair | A teammate can follow it on a clean checkout |
| 11 | Report, slides, demo rehearsal; record a backup demo video | All | Dry run done |

Final run order (about 60–75 minutes, laptop on power, nothing else heavy running):

```
python3 scripts/experiments.py --only availability      # exp 1, 2 (B vs FT, 3 reps)
python3 scripts/experiments.py --only queue             # exp 4 (B vs FT, 3 reps)
python3 scripts/experiments.py --only checkpoints       # exp 4 (FT, 3 reps)
python3 scripts/broker-recovery.py                      # exp 2/4 queue dependency (FT, 3 reps)
python3 scripts/experiments.py --only timeout           # exp 3 (B vs FT, 3 reps)
python3 scripts/experiments.py --only circuit           # exp 3 (FT, 3 reps), run after timeout settles
python3 scripts/experiments.py --only spike             # exp 5 (B vs FT, 1 run each)
python3 scripts/experiments.py --only security          # exp 7 (FT, 3 reps)
python3 scripts/experiments.py --only alerts --repetitions 1   # exp 6 (FT, ~15 min)
kubectl --context orbstack -n midterm exec deploy/api -- php bin/console app:backup            # exp 8
kubectl --context orbstack -n midterm exec deploy/api -- php bin/console app:restore-verify <key>
python3 scripts/observability-check.py
python3 scripts/summarize.py
```

## 4. What changes in the report

- Environment: one Mac, OrbStack Kubernetes (single node), both profiles deployed side by side, the same image for both. Record the image tag, Kubernetes version and host specs.
- Limitation: single node, so whole-node failure is not tested. The local client IP and the shared CPU between both profiles and the load generator are confounding factors, and the report should say so.
- Midterm vs. final numbers are comparable (same environment type), but the final runs are the ones reported.
- The Contabo/Argo CD deployment is described as an optional production-like setup (GitOps rebuild, TLS at the edge), not as an experiment environment.

## 5. Risks

| Risk | Mitigation |
| --- | --- |
| 8 GiB OrbStack VM runs out of memory with both profiles + monitoring | Same footprint as the midterm. If pods are evicted, raise OrbStack memory to 10–12 GiB (`orbctl config set memory_mib 12288`) |
| Seed deadlines expire 7 days after first seeding | Re-seed or update the deadlines before the defense |
| The circuit breaker failure counter carries over between scenarios | Run `circuit` alone, after `timeout`; its result records the starting count |
| The laptop sleeps during runs | Disable sleep while running; the scripts restore replicas in `finally` blocks |
