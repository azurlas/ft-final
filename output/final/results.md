# Consolidated experiment results

Environment: orbstack-local. Image(s): midterm/university:local-20261106120520.
All values are measured; means over the listed runs. "Passed" uses each scenario's built-in pass criterion; empty cells are not applicable.

| Exp. | Scenario | Profile | Runs | Passed | Success | Availability | Detection (s) | Recovery (s) | Latency | Recovery actions | Data integrity | Notes |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 1 Instance failure | api_crash | Baseline | 3 |  | 140/182 | 76.9% | 0.3 | 2.84 | max 149 ms |  |  | 3 outage(s) in 37 s observed; failure rate 0.082/s |
| 1 Instance failure | api_crash | FT | 3 | 2/3 | 179/180 | 99.4% | 0.25 | 0.2 | max 95 ms |  |  | 1 outage(s) in 37 s observed; failure rate 0.027/s |
| 2 Dependency: MySQL | mysql_outage | Baseline | 3 |  | 70/106 | 66.0% | 0.23 | 13.21 | max 3013 ms |  |  | 3 outage(s) in 57 s observed; failure rate 0.053/s |
| 2 Dependency: MySQL | mysql_outage | FT | 3 | 3/3 | 57/122 | 46.7% | 0.25 | 16.21 | max 3062 ms |  |  | 3 outage(s) in 62 s observed; failure rate 0.048/s |
| 2 Dependency: cache | cache_outage | Baseline | 3 |  | 62/200 | 31.0% | 0.23 | 10.01 | max 243 ms |  |  | 3 outage(s) in 42 s observed; failure rate 0.071/s |
| 2 Dependency: cache | cache_outage | FT | 3 | 3/3 | 189/189 | 100.0% |  | none needed | max 265 ms |  |  | 0 outage(s) in 41 s observed; failure rate 0.000/s |
| 2 Dependency: queue Redis | broker down during checkout | FT | 3 | 3/3 | 3/3 accepted and completed |  |  | 17.39 |  | relay restart + receiver backoff | 3/3 with 1 attempt | recovery measured from Redis ready |
| 3 Delay / timeout | circuit breaker | FT | 3 | 3/3 | 3/3 good purchase succeeded |  | 8.26 | 22.14 |  | 1 openings/run | 3/3 exactly-once; 0 duplicate | detection = time until open; recovery = good purchase to success |
| 3 Delay / timeout | provider 3 s vs 2 s deadline | Baseline | 3 | 3/3 | 3/3 terminal |  |  | 3.77 | attempt max 2008 ms | 1 attempts/purchase | 3/3 no receipt or enrollment | recovery = time to terminal state |
| 3 Delay / timeout | provider 3 s vs 2 s deadline | FT | 3 | 3/3 | 3/3 terminal |  |  | 35.06 | attempt max 2004 ms | 3 attempts/purchase | 3/3 no receipt or enrollment | recovery = time to terminal state |
| 4 Interrupted transaction | published message lost | Baseline | 3 |  | 0/3 recovered |  |  | never (censored) |  |  | 3 purchase(s) stuck pending | observed 30 s |
| 4 Interrupted transaction | published message lost | FT | 3 | 3/3 | 3/3 recovered |  |  | 11.47 |  | outbox reconciliation | 3/3 exactly-once; 0 duplicate |  |
| 4 Interrupted transaction | worker killed after_outbox_publish | FT | 3 | 3/3 | 3/3 recovered |  |  | 9.69 |  | lease expiry + replay | 3/3 exactly-once; 0 duplicate |  |
| 4 Interrupted transaction | worker killed after_provider_success | FT | 3 | 3/3 | 3/3 recovered |  |  | 9.14 |  | lease expiry + replay | 3/3 exactly-once; 0 duplicate |  |
| 5 Load spike | 300 req/s for 30 s | Baseline | 1 |  | 6211/9000 | 69.0% |  |  | p50 6 / p95 9 / p99 56 ms | 2789 × 429 (admission control) |  | goodput 207.0 req/s; 0 5xx/connection errors; cool-down p95 22 ms; warm-up p95 26 ms |
| 5 Load spike | 300 req/s for 30 s | FT | 1 | 1/1 | 9000/9000 | 100.0% |  |  | p50 6 / p95 150 / p99 1086 ms | 0 × 429 (admission control) |  | goodput 300.0 req/s; 0 5xx/connection errors; cool-down p95 77 ms; warm-up p95 23 ms |
| 6 Monitoring / alert | ApiReplicasDegraded (api_degraded) | FT | 1 | 1/1 | 1/1 fired, 1/1 resolved |  | 30.82 | 10.27 |  |  |  | logs correlated 1/1; bound 60 s; detection = fault to alert firing; recovery = repair to alert resolved |
| 6 Monitoring / alert | AuthFailureSpike (auth_spike) | FT | 1 | 1/1 | 1/1 fired, 1/1 resolved |  | 33.65 | 41.02 |  |  |  | logs correlated 1/1; bound 90 s; detection = fault to alert firing; recovery = repair to alert resolved |
| 6 Monitoring / alert | DatabaseDown (database_down) | FT | 1 | 1/1 | 1/1 fired, 1/1 resolved |  | 12.98 | 4.11 |  |  |  | logs correlated 1/1; bound 40 s; detection = fault to alert firing; recovery = repair to alert resolved |
| 6 Monitoring / alert | PaymentBacklogStuck (payment_backlog) | FT | 1 | 1/1 | 1/1 fired, 1/1 resolved |  | 75.42 | 6.17 |  |  | 1/1 exactly-once; 0 duplicate | bound 120 s; detection = fault to alert firing; recovery = repair to alert resolved |
| 7 Unauthorized / invalid | 10 misuse cases | FT | 3 | 3/3 | 30/30 denied as expected |  |  |  |  |  | 30/30 audited in logs | 401/403/429 also logged as security.denied |
| 8 Recovery / rollback | backup + isolated restore | FT | 3 | 3/3 | 3/3 checksum OK |  |  | 1.2 |  |  | 3/3 match source; 0 violations | recovery = dump + restore + verify (small dataset) |
