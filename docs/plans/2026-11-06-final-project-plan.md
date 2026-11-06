# Final project implementation plan

Branch: `feat/final`. Source: `FINAL PROJECT FTR.docx.pdf` (Integrated Dependability Engineering Capstone). Option A: we continue the university system from the midterm.

Decisions so far:

- **Environment (changed 2026-11-06):** run all experiments and the live demo on a local OrbStack Kubernetes cluster. The VPS is not needed. See [the local cluster plan](2026-11-06-local-cluster-plan.md); it overrides the Contabo-specific parts below. The Contabo/Argo CD files stay as an optional deployment.
- **Report:** a Word / Google Doc written outside the repo. The repo holds the README, the code, the scripts, the raw data and one Markdown results table that we copy into the report.
- **Team contributions:** still open. The team will provide the split (see the last section).

Goal: meet every rubric line with measured evidence, and add as little new code as possible. Most of the fault-tolerance work is already done. The gaps are alerting, security evidence, three missing experiments, and running everything again on one environment with one image.

## 1. What the midterm already covers vs. what is missing

| Work package (rubric weight) | Already done in the midterm | Missing for the final |
| --- | --- | --- |
| A. Requirements & dependability model (15%, shared with B) | Functional requirements, 8 non-functional targets, 9 failure scenarios | Measurable **SLO table** (target, window, how measured), fault → error → failure terminology, a failure-impact table |
| B. Reliability engineering | Component MTTF/MTTR/λ/A table, series/parallel availability, per-run MTTR/MTBF/MTTF | Recompute with Contabo-measured MTTR; mark every value as *measured*, *assumed* or *illustrative*; state the units and observation windows |
| C. Architecture & infrastructure resilience (15%) | Diagram, H1–H8 (replicas, LB, 2 Envoy, PDB, backups, PVC Retain, AOF/InnoDB, GitOps), SPOF list | Update the diagram for Contabo (shared edge gateway, Argo CD, SealedSecrets) |
| D. Software fault tolerance (20%) | S1–S7: retry/backoff, timeouts, circuit breaker, idempotency/duplicate detection, checkpoint/outbox, rollback/reconciliation, health checks, cache degradation | Nothing new. Map each mechanism → code → test → experiment |
| E. Cloud & microservices resilience | Helm profiles, probes, PDB, resources, bounded restarts | Write down the restart/replica behaviour and the resource/load assumptions for Contabo |
| F. Monitoring & observability (15%, shared with G) | Prometheus, Grafana (2 dashboards), VictoriaLogs JSON logs, `request_id` correlation, app metrics | **≥3 alert rules** (none exist yet), an alert-firing test, a log-correlation sample |
| G. Security & intrusion tolerance | JWT, admin role + ownership checks (404 for others' data), DTO validation, upload limit, Envoy rate limit, TLS, SealedSecrets, internal mock service not routed | **Threat model**, **security audit logging**, login throttling, **≥2 misuse tests with denial/log evidence** |
| H. Verification & fault injection (20%) | PHPUnit (10 tests), PHPStan, smoke, experiment scripts, 3 repetitions | Test plan with hypotheses and acceptance criteria; the **8-scenario matrix** (3 scenarios are new); consolidated baseline-vs-FT table |
| Deliverables | — | Report (Word), slides, 4-scenario demo, contribution statement |

## 2. What to remove

Before deleting anything, tag the current `main` as `midterm` so the midterm evidence stays reachable.

| Remove | Why |
| --- | --- |
| `scripts/setup-local.sh`, `scripts/setup-local-tls.sh`, `scripts/check-local-tls.py`, `scripts/deployment.py` (start/stop), `scripts/node-outage.sh` | OrbStack-only. Contabo is set up with `deploy/bootstrap.sh` + Argo CD |
| `helm/university/values-local.yaml`; the `orb.local` hostnames and `tls:` block in `helm/university/values.yaml` / `values-baseline.yaml` / `helm/monitoring/values.yaml` | Local profile only. Keep neutral defaults; Contabo values files override them |
| Makefile targets `start`, `stop`, `setup-local`, `setup-local-tls`, `tls-check`, `frontend-upload-check`, `frontend-smoke` | Their scripts are removed |
| `scripts/frontend-upload-check.mjs`, `scripts/frontend-smoke.py` | Frontend verification beyond what the final asks for. Keep `smoke.py` (API) and the Node unit tests in `tests/Frontend` |
| `output/evaluation/**` (58 midterm files, including `orm/`, `frontend*`, `tls.json`, `deployment-lifecycle.json`, `plugin-version.json`) | Mixed images and OrbStack data. Replaced by new Contabo runs in `output/final/`. The old files stay under the `midterm` tag |
| `output/reports/deployed-rendered/`, `scripts/render-pdf.swift` | Midterm report rendering |
| `docs.md` (319 lines), `docs/plans/2026-10-05-university-backend-implementation.md` | The report now lives in Word. Replace them with `docs/experiments.md` (test plan + results) |
| README OrbStack sections (local URLs, mkcert, port-forward, start/stop) | Keep only Contabo + local unit-test setup |

Keep all application features (UI, PDF reports, payments) and the baseline profile. Removing working code adds risk and saves little.

## 3. What to add or edit

### 3.1 Make the scripts target Contabo (prerequisite for everything else)

- Done: `scripts/env.py` holds one config: `KUBECONFIG=output/secrets/contabo/kubeconfig` (SSH tunnel), no hard-coded `--context orbstack`, and no hard-coded `/Users/nariman/...` kubectl path. Base URLs: `https://midterm.sailaubek.dev`, `https://baseline.sailaubek.dev`, `https://grafana.sailaubek.dev`. Keep the namespace allowlist.
- **Argo CD self-heal will undo the fault injection.** `kubectl scale` and `kubectl set env` drift from git, and `selfHeal: true` reverts them within seconds. That would corrupt the measurements. Add `pause_argocd()` / `resume_argocd()` as a context manager that patches `syncPolicy.automated` off on `root`, `university` and `university-baseline` (root first, because it would restore the child Applications), and restores it in `finally`.
- Update `experiments.py`, `smoke.py`, `broker-recovery.py`, `observability-check.py` and `summarize.py` to use `env.py`, and write to `output/final/`.
- Note in the report: on Contabo both profiles share the 2-replica edge Envoy gateway. That differs from the midterm, where the baseline had 1 gateway.

### 3.2 Monitoring: alert rules (F)

Done: `rules.yml` in the `prometheus-config` ConfigMap (`helm/monitoring/templates/config.yaml`), checked with `promtool`. We skip Alertmanager: alerts are visible in Prometheus `/alerts` and in a Grafana alert-list panel, which is enough evidence. Five small rules:

| Alert | Expression (sketch) | Incident it indicates |
| --- | --- | --- |
| `DatabaseDown` | `university_database_up == 0` for 15s | MySQL unavailable |
| `ApiReplicasDegraded` | `count(up{component="api",profile="resilient"} == 1) < 2` for 15s | Lost API instance (redundancy reduced) |
| `PaymentBacklogStuck` | `university_purchase_oldest_pending_seconds > 60` | Workers or queue down; purchases not progressing |
| `PaymentCircuitOpen` | `university_circuit_open == 1` | Provider failing |
| `AuthFailureSpike` | rate of Envoy 401/403 responses > 1/s over 1m | Brute force or misuse |

Check the exact label names against the live series before finalizing. Add an "Active alerts" panel and a "401/403 rate" panel to the University dashboard.

### 3.3 Security (G)

- **Audit logging (done):** `RequestLoggingSubscriber` logs `security.denied` at `warning` level for 401/403/429 responses (request_id, method, path, status, user if any, client IP). `src/Security/LoginFailureLogger.php` logs `security.authentication_failed` for failed logins and invalid bearer tokens (login, reason, client IP). Passwords, tokens and bodies are never logged.
- **Login throttling (done):** `login_throttling` on the `login` firewall: 5 attempts per minute per login + IP, 60 per minute per IP. Throttled logins return 429 with `Retry-After` (`src/Security/LoginFailureHandler.php`). The limiter state is stored in MySQL (`cache_items`, migration `Version20261106060117`), not the cache Redis, so a cache outage cannot break login and all API pods share one counter. `trusted_proxies: private_ranges` makes Symfony read the client IP from Envoy's `X-Forwarded-For`. **To verify on Contabo:** RKE2 ServiceLB may hide the real client IP (SNAT). If every request shows the same IP, the per-IP limit becomes global.
- **Tests (done):** `tests/Functional/SecurityTest.php` covers brute force (5 × 401, then 429 even with the correct password; other users unaffected), no token, a forged JWT, and a student calling an admin endpoint. Each case asserts the status code and its audit record.
- **Threat model (goes in the report):** a small STRIDE table covering about 8 threats → control → evidence. Examples: stolen/forged JWT, IDOR on purchases/files, privilege escalation via registration, brute-force login, oversized-upload DoS, request flooding, secrets in git, card-data leakage.

### 3.4 Experiments (H): the 8-scenario matrix

Run each with 3 repetitions (unless noted), on **baseline vs FT** where the comparison makes sense. Record the UTC start/end, injection time, detection time, recovery time, success/fail counts, latency and the integrity result. Write each hypothesis and its pass criterion in `docs/experiments.md` *before* running.

| # | Scenario | Script / status | Baseline vs FT | Pass criterion (FT) |
| --- | --- | --- | --- | --- |
| 1 | API instance failure | `availability api_crash`, exists | yes | ≥ 99% probe success; 0 data impact |
| 2 | Dependency unavailable: cache and MySQL | `cache_outage`, `mysql_outage`, exist; add a post-run integrity query (no partial rows) | yes | Cache: 100% catalogue success. MySQL: fails safe (503, no 500 corruption), recovers < 60s, 0 integrity violations |
| 3 | Network delay / timeout | **done** `--only timeout` (timeout fixture: 3s provider vs 2s deadline) and `--only circuit` (2 always-failing purchases open the breaker; a good purchase made while it is open must be deferred, then succeed with 1 attempt) | timeout: yes; circuit: FT only | Each attempt ≤ ~2.6s; ≤ 3 attempts; terminal state; 0 receipts/enrollments. Circuit opens; good purchase succeeds with exactly 1 receipt/attempt/enrollment |
| 4 | Interrupted multi-step transaction | `queue`, `checkpoints`, exist | queue-loss: yes; checkpoints: FT only | 0 lost, 0 duplicate receipts/enrollments |
| 5 | Load / traffic spike | **done** `--only spike` (replaces the old 300-request `load`): closed-loop clients on `/api/courses`, 20s at concurrency 5 → 30s at 40 → 20s at 5, 1 run per profile | yes | Per phase: throughput, p50/p95/p99, 429/5xx/connection errors. FT spike: 5xx + errors ≤ 1%, p95 < 1s |
| 6 | Monitoring & alert trigger | **done** `--only alerts` (run with `--repetitions 1`; ~15 min): MySQL → 0 (`DatabaseDown`), queue Redis → 0 + a purchase (`PaymentBacklogStuck`), API → 1 replica (`ApiReplicasDegraded`), unauthenticated burst ~3/s (`AuthFailureSpike`). Polls Prometheus `/api/v1/alerts` through Grafana and looks up the failed requests' IDs in VictoriaLogs | FT | Alert fires and resolves after repair; detection time recorded; logs found for the failed requests; the backlog purchase still succeeds |
| 7 | Unauthorized / invalid operation | **done** `--only security`: no token, forged JWT, tampered JWT claims (401); register as admin (400); student → admin endpoint (403); another student's purchase (404); client sets price (400); admin negative price (400); oversized upload (400); brute-force login (5×401 then 429) | FT | Every case returns the expected code and has a log record with its request ID; 401/403/429 also have `security.denied` |
| 8 | Recovery / rollback | `app:backup` + `app:restore-verify`, exists; plus the PHPUnit transaction-rollback test as code evidence | FT | Checksum OK; all tables match; 0 violations; RTO measured |

Local check (2026-11-06, API + worker + mock against the compose test dependencies; Grafana, Prometheus and the cluster not available): `security` 10/10 cases passed and audited; `timeout` failed after 3 attempts of ≤ 2.0s with no receipt or enrollment; `circuit` opened, re-opened 3 times after failed half-open probes (taken by the still-failing purchases), and the good purchase succeeded with 1 attempt after ~76s; `spike` ran end to end. `alerts` is untested until it runs on Contabo.

**Done:** `summarize.py` produces `output/final/results.csv` and a Markdown consolidated table (`results.md`): success ratio, failure rate, MTTR, detection time, retries, circuit openings, missing/duplicate records, baseline vs FT. Mark measured values separately from theoretical ones.

### 3.5 Report-supporting artefacts in the repo

- `docs/experiments.md`: test plan (hypothesis, workload, duration, criteria), how to reproduce, the consolidated results table, and limitations/anomalies.
- `docs/architecture.mmd`: updated for Contabo; re-render the SVG/PNG.
- README: rewritten around Contabo access (SSH tunnel), `make check`, and `python3 scripts/experiments.py --only <scenario>`.

## 4. Order of work

1. Tag `midterm`; do the removals (§2); `make check` + `make helm-check` still pass.
2. Scripts → Contabo + Argo CD pause (§3.1). Smoke-test `api_crash` once.
3. Alert rules + dashboard panels (§3.2). Security logging + throttling + tests (§3.3). Merge to `main` so Argo CD deploys it, then run `smoke.py`.
4. Write the hypotheses and criteria in `docs/experiments.md`. Freeze the image tag.
5. Run all 8 scenarios on that image (§3.4). Write `inventory.json` with the image and config hashes.
6. Summaries, Grafana/Prometheus screenshots, log samples.
7. Report (Word): reuse the midterm text for architecture and mechanisms. Write the new SLO, fault-model, threat-model, reliability, results and discussion sections. Add references and the contribution table.
8. Slides + demo rehearsal (4 scenarios: API pod kill, cache outage degradation, worker crash at the checkpoint, misuse burst → alert + logs). Record a backup video in case the live demo fails.

## 5. Report outline (required sections → where content comes from)

| Section | Source |
| --- | --- |
| Abstract, keywords, introduction, system context | New, short |
| Requirements, assumptions, SLOs | Midterm §2 + new SLO table |
| Fault model, threat model, failure analysis | New fault/impact table + STRIDE table + SPOF list |
| Reliability calculations | Midterm §3, recomputed with Contabo MTTR, values labelled |
| Architecture & resilience design | Midterm §4–5, updated diagram |
| Infrastructure & software FT implementation | Midterm §5–6, with code references |
| Monitoring, observability, security | New: alerts, logs, controls |
| Verification & methodology | `docs/experiments.md` |
| Results, baseline vs improved | `summarize.py` table |
| Discussion, limitations, future work | SPOFs (single node, single MySQL), trade-offs (FT p95 latency higher, complexity), negative results (FT did not help with a MySQL outage) |
| Conclusion, references, appendix (repo link, scripts, raw data) | — |

## 6. Team contributions

Each person owns one area end to end: the code, the experiments, the report sections and the demo scenarios for that area. That way everyone can defend their own part. Each pull request is reviewed by one other member.

| | Nariman Sailaubek | Sultan Turan | Altair Tussupov |
| --- | --- | --- | --- |
| Area | Infrastructure & recovery | Observability & security | Requirements, verification & report |
| Code / config | §2 removals + `midterm` tag; §3.1: `scripts/env.py`, Argo CD pause/resume, port the existing scripts to Contabo; update `architecture.mmd` | §3.2 alert rules + dashboard panels; §3.3 audit logging, login throttling, PHPUnit security tests; the new `timeout`/circuit scenario script and the extended `load` spike script; `summarize.py` consolidated table | No coding. Rewrites the README; specifies scenarios 3 and 5 (hypothesis, workload, duration, pass criteria) and the consolidated table's columns, and Sultan implements them |
| Experiments (§3.4) | 1 API instance failure, 2 cache/MySQL outage, 4 interrupted transaction, 8 backup/restore | 6 alert trigger, 7 unauthorized/invalid operations | 3 network delay/timeout, 5 load spike (designs, runs with Sultan's scripts, interprets); owns `docs/experiments.md` (hypotheses, criteria, results) |
| Report sections | Reliability calculations (B); architecture & infrastructure resilience (C); cloud/microservices resilience (E) | Monitoring & observability (F); threat model & security (G) | Abstract/intro; requirements, SLOs, fault model (A); software FT mapping (D); methodology & results (H); discussion, conclusion, references; final editing |
| Presentation / demo | API pod kill (live); worker crash at the checkpoint | Misuse burst → 401/403/429 + alert firing + log lookup by `request_id` | Cache outage degradation; opening/closing slides; recorded backup video |

Shared work: everyone reviews the full report, prepares for questions on the whole system, and attends the dry run.

## 7. Risks

- Public-internet probing of Contabo adds latency noise. Probe from one fixed machine and record its location. The midterm results were local, so they are not directly comparable.
- Single node: node loss and MySQL loss remain SPOFs. Report them honestly as negative results.
- Demo deadlines in the seeds expire after 7 days; refresh them before the defense.
- The wildcard certificate expires 2027-01-05, after the course ends.
