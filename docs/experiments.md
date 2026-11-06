# Test plan and acceptance criteria

Written and committed **before** the final runs (2026-11-06). **Final results:** [output/final/results.md](../output/final/results.md); 9 of 10 objectives met in every run, SLO-1 in 2 of 3 runs (one failed probe while the API pod was being killed). Results are generated into `output/final/results.md` by `scripts/summarize.py`. The trial runs that shaped the scripts are summarized in the [local cluster plan](plans/2026-11-06-local-cluster-plan.md#trial-run-2026-11-06-1-repetition-each-image-local-20261106120520-after-fixes); their numbers are not reported as results.

## 1. Environment

| Item | Value |
| --- | --- |
| Host | MacBook (Apple Silicon, 8 CPU cores, 16 GiB RAM), on power |
| Cluster | OrbStack Kubernetes v1.36.5, single node, VM limit 8 GiB, `local-path` volumes |
| Image | `midterm/university:local-20261106120520`, the same for both profiles (frozen; recorded in `inventory-<scenario>.json`) |
| Load generator / probes | Python 3.12 on the same host, HTTP through each profile's own Envoy gateway |
| Monitoring | Prometheus 3.5.0 (5 s scrape and rule evaluation), Grafana 12.3.1, VictoriaLogs v1.53.0 |

Both profiles run at the same time in separate namespaces with separate databases. Only one profile receives test traffic at a time; the other stays idle. Experiments run one after another, never in parallel.

## 2. Baseline vs. improved (FT) configuration

| Mechanism | Baseline (`midterm-baseline`) | FT (`midterm`) |
| --- | --- | --- |
| API / worker / Envoy replicas | 1 / 1 / 1 | 2 / 2 / 2 |
| PodDisruptionBudget | No | `minAvailable: 1` for API and worker |
| Payment dispatch | Direct dispatch after commit | Transactional outbox + relay + reconciliation |
| Business retries | None (1 attempt) | Initial attempt + 2 retries (2 s, 4 s backoff) |
| Circuit breaker | Off | Opens after 5 transient failures for 15 s, half-open probe |
| Catalogue cache failure | Error (500) | Graceful degradation: read MySQL directly |
| Scheduled backups | Off | Every 15 min, verified isolated restore |
| Same in both | 2 s provider deadline, Envoy timeouts (12 s backend / 15 s total) and 200 req/s local rate limit per proxy, health probes, idempotency keys, unique constraints, JWT auth, validation, audit logging, login throttling, alert rules |

## 3. Definitions

- **Probe:** host-side HTTP GET every 0.2 s; success = HTTP 200. `/health/ready` for crash and database scenarios, `/api/courses` for the cache scenario.
- **Request availability** = successful probes / all probes in the observation window.
- **Detection time** = injected fault → first failed probe (bounded below by the 0.2 s probe interval). For alerts: fault → alert observed as `firing` in Prometheus (2 s poll).
- **Recovery time (MTTR)** = first failed probe → first successful probe after it. For payments: fault/checkpoint → purchase reaches a terminal state. For alerts: repair → alert resolved.
- **Exactly-once check** = per purchase after recovery: exactly 1 provider receipt, 1 payment attempt, 1 enrollment. More than 1 of any is a duplicate; a purchase not completed is a missing record.
- **Admission control:** HTTP 429 from Envoy's rate limit or login throttling is reported separately and is not a server failure.
- A run that does not recover within its observation limit is **right-censored**, and is reported as such, not as a number.

## 4. Experiments

Repetitions: 3 per profile and scenario unless stated. The pass criterion applies to the FT profile; the baseline is measured for comparison and has no pass criterion unless stated.

### E1. Application instance failure (`--only availability`, scenario `api_crash`)
- **Fault:** force-delete one API pod (`--grace-period=0`), wait 5 s, wait for the Deployment to be ready again, 5 s tail.
- **Hypothesis:** the baseline is unavailable until its only replacement pod is ready; FT keeps serving from the second replica.
- **Pass (FT):** request availability ≥ 99% in every run.

### E2. Database or dependency unavailable (`availability`: `cache_outage`, `mysql_outage`; `broker-recovery.py`)
- **Faults:** cache Redis scaled to 0 for 5 s; MySQL scaled to 0 for 5 s; queue Redis scaled to 0 while a purchase is accepted.
- **Hypotheses:** FT degrades gracefully when the cache fails. MySQL is a single point of failure in both profiles: requests fail safely (503 or client timeout, never another 5xx) and recover after restart. A queue outage does not lose accepted purchases in FT.
- **Pass (FT):** cache: availability ≥ 99%. MySQL: every failed probe is 503 or a timeout, and recovery is observed (not censored) within 60 s. Queue: every purchase accepted during the outage completes with exactly 1 attempt.

### E3. Network delay / timeout (`--only timeout`, `--only circuit`)
- **Faults:** the provider answers after 3 s while the caller's deadline is 2 s; the always-failing provider fixture repeated until the circuit opens.
- **Hypotheses:** every attempt is bounded by the deadline; retries are bounded (baseline 1, FT 3); a timed-out payment never charges or enrolls. The circuit stops calls while open; a good payment made meanwhile is deferred without using an attempt and then succeeds.
- **Pass:** timeout (both profiles): terminal state, ≤ 3 attempts, each attempt < 2.6 s, 0 receipts and 0 enrollments. Circuit (FT): circuit opens, and the good purchase succeeds with exactly 1 receipt, attempt and enrollment.

### E4. Interrupted multi-step transaction (`--only queue`, `--only checkpoints`)
- **Faults:** the published queue message is deleted (FLUSHALL) after the outbox marked it published; the workers are killed right after the provider succeeded (before settlement) and right after publishing (before the outbox acknowledgement).
- **Hypotheses:** the baseline loses the purchase (stays pending). FT recovers every purchase exactly once through reconciliation and lease expiry.
- **Pass (FT):** 100% recovered, all exactly-once (0 missing, 0 duplicate).

### E5. High load / traffic spike (`--only spike`, 1 run per profile)
- **Workload:** open-loop GET `/api/courses` at 20 req/s for 20 s, 300 req/s for 30 s, 20 req/s for 20 s. 300 req/s exceeds one Envoy pod's 200 req/s limit but not two.
- **Hypotheses:** the baseline sheds load above about 200 req/s with fast 429s; FT serves the full rate with higher latency. Neither returns server errors at scale.
- **Pass (FT):** 5xx + connection errors ≤ 1% of spike requests, and p95 latency of accepted requests < 1 s. Valid only if the generator's maximum start delay is < 100 ms.

### E6. Monitoring and alert trigger (`--only alerts`, 1 run per alert)
- **Faults:** MySQL → 0 (`DatabaseDown`); queue Redis → 0 with a purchase pending (`PaymentBacklogStuck`); API → 1 replica (`ApiReplicasDegraded`); unauthenticated burst at about 3 req/s (`AuthFailureSpike`).
- **Hypothesis:** each incident is detected by its rule within the rule's window plus scrape/evaluation delay, resolves after repair, and the failed requests can be found in VictoriaLogs by request ID.
- **Pass:** the alert fires, then resolves; request IDs found in logs (where applicable); the backlog purchase completes exactly once. Expected detection upper bounds: DatabaseDown 40 s, ApiReplicasDegraded 60 s, AuthFailureSpike 90 s, PaymentBacklogStuck 120 s.

### E7. Unauthorized or invalid operation (`--only security`)
- **Cases:** no token, forged JWT, tampered JWT claims (401); register as admin (400); student calls an admin endpoint (403); read another student's purchase (404); client sets the price (400); negative course price (400); oversized upload (400); brute-force login (5 × 401, then 429).
- **Hypothesis:** every misuse is denied with the expected status and leaves an audit record with the request ID; 401/403/429 also produce `security.denied`.
- **Pass:** 10/10 cases with the expected status and an audit record, in every run.

### E8. Recovery / rollback (`--only restore`)
- **Procedure:** take a fresh MySQL dump, restore it into a new isolated database, verify the SHA-256 and compare ordered row hashes with the live database. Code-level rollback is covered by `RepositoryPersistenceTest` (an aborted transaction discards staged entities).
- **Hypothesis:** the latest backup restores to an identical, consistent copy.
- **Pass:** checksum verified, restored rows match the live source, 0 integrity violations.

### Supporting checks
`scripts/smoke.py` (all six payment fixtures, files, ownership, grades, PDF) and `scripts/observability-check.py` (datasources, 9 official RoadRunner panels, live metrics, correlated payment logs) must pass before and after the experiment block.

## 5. Procedure

`bash scripts/run-all.sh` runs exactly this sequence and waits for all pods to be ready between steps:

```sh
make setup-local           # only if the image changes; otherwise reuse the frozen deployment
python3 scripts/smoke.py
python3 scripts/experiments.py --only availability
python3 scripts/experiments.py --only queue
python3 scripts/experiments.py --only checkpoints
python3 scripts/broker-recovery.py
python3 scripts/experiments.py --only timeout
python3 scripts/experiments.py --only circuit
python3 scripts/experiments.py --only spike --repetitions 1
python3 scripts/experiments.py --only security
python3 scripts/experiments.py --only alerts --repetitions 1
python3 scripts/experiments.py --only restore
python3 scripts/observability-check.py
python3 scripts/summarize.py
```

Between scenarios, check `kubectl --context orbstack get pods -A` and wait for all pods to be ready. If a scenario fails because of the environment (not the system under test), keep its files, record why, and rerun it; both appear in the report.

## 6. Known limitations and confounders

- Single node: node, disk and host failures are not tested. MySQL, queue Redis and RustFS are single instances.
- The load generator, the probes and both profiles share one machine's CPU.
- Locally every client has the same IP (`192.168.194.1`), so per-IP login limits act as one shared limit.
- Short observation windows (tens of seconds) give experiment-scale rates, not annual MTBF.
- Small dataset: backup/restore times do not predict production sizes.
