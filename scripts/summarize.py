#!/usr/bin/env python3
"""Aggregate the raw experiment files in output/final/ (or RESULTS_DIR) into:

- requests.csv      every availability probe sample (raw data for plots)
- reliability.csv   availability / MTTR / MTBF / MTTF / failure rate per profile and scenario (+ .json)
- results.csv       one consolidated row per experiment, scenario and profile (baseline vs FT)
- results.md        the same table in Markdown, ready for the report

Every value is computed from measured runs; nothing is estimated. Empty cells mean "not applicable".
"""
import collections
import csv
import json
import statistics

from env import BASELINE, ENVIRONMENT, OUT as ROOT

PROFILE = {BASELINE: 'Baseline', 'midterm': 'FT'}


def load():
    files = collections.defaultdict(list)
    for path in sorted(ROOT.glob('*.json')):
        if path.name.startswith(('summary', 'inventory', 'observability', 'smoke')):
            continue
        data = json.loads(path.read_text())
        if path.name == 'broker-recovery.json':
            files['broker'].extend(data)
        elif isinstance(data, dict) and 'type' in data:
            files[data['type']].append(data)
    return files


def mean(values):
    values = [v for v in values if v is not None]
    return statistics.mean(values) if values else None


def fmt(value, digits=2, unit=''):
    if value is None:
        return ''
    if isinstance(value, float):
        value = int(round(value)) if digits == 0 else round(value, digits)
    return f'{value}{unit}'


def pct(part, total):
    return f'{part / total:.1%}' if total else ''


def counts_text(runs):
    """Data-consistency check over payment runs: every recovered purchase must have exactly one
    receipt, attempt and enrollment; anything else is a missing or duplicate critical record."""
    counts = [r['counts'] for r in runs if r.get('counts')]
    if not counts:
        return ''
    exact = sum(c == {'receipts': 1, 'enrollments': 1, 'attempts': 1} for c in counts)
    duplicates = sum(any(v > 1 for v in c.values()) for c in counts)
    return f'{exact}/{len(counts)} exactly-once; {duplicates} duplicate'


def row(exp, scenario, profile, runs, passed=None, **values):
    return {'experiment': exp, 'scenario': scenario, 'profile': profile, 'runs': runs,
            'passed': '' if passed is None else f'{passed}/{runs}', **values}


COLUMNS = ['experiment', 'scenario', 'profile', 'runs', 'passed', 'success', 'availability', 'detection_s',
           'recovery_s', 'latency', 'recovery_actions', 'integrity', 'notes']


def availability_pass(scenario, run):
    """FT criteria from docs/experiments.md (E1, E2)."""
    stats = run['stats']
    if scenario in ('api_crash', 'cache_outage'):
        return stats['requestAvailability'] >= 0.99
    # MySQL: fail safe (only 503 or client timeout) and recover within 60 s.
    failed = {s['status'] for s in run['samples'] if s['status'] != 200}
    return failed <= {0, 503} and not stats['recoveryRightCensored'] and (stats['mttrSeconds'] or 0) < 60


def availability_rows(runs):
    groups = collections.defaultdict(list)
    for r in runs:
        groups[r['scenario'], r['namespace']].append(r)
    rows, reliability, samples = [], [], []
    exp = {'api_crash': '1 Instance failure', 'cache_outage': '2 Dependency: cache', 'mysql_outage': '2 Dependency: MySQL'}
    for (scenario, ns), group in sorted(groups.items(), key=lambda g: (g[0][0], g[0][1] != BASELINE)):
        stats = [r['stats'] for r in group]
        ok = sum(s['requests'] - s['failedRequests'] for s in stats)
        total = sum(s['requests'] for s in stats)
        # Detection: from the injected fault to the first failed probe (bounded below by the 0.2 s probe interval).
        detection = [s['mttfSeconds'] - r['injectedRelativeSeconds'] for r, s in zip(group, stats) if not s['mttfRightCensored']]
        mttr = mean([s['mttrSeconds'] for s in stats])
        failures = sum(s['outageCount'] for s in stats)
        window = sum(s['windowSeconds'] for s in stats)
        rows.append(row(exp[scenario], scenario, PROFILE[ns], len(group),
                        None if ns == BASELINE else sum(availability_pass(scenario, r) for r in group),
                        success=f'{ok}/{total}', availability=pct(ok, total),
                        detection_s=fmt(mean(detection)), recovery_s=fmt(mttr) if mttr is not None else ('none needed' if not failures else 'censored'),
                        latency=f"max {fmt(max(s['maxLatencyMs'] for s in stats), 0)} ms",
                        notes=f'{failures} outage(s) in {window:.0f} s observed; failure rate {failures / window:.3f}/s' if window else ''))
        reliability.append({
            'namespace': ns, 'profile': PROFILE[ns], 'scenario': scenario, 'repetitions': len(group),
            'requestAvailabilityMean': mean([s['requestAvailability'] for s in stats]),
            'sampledTimeAvailabilityMean': mean([s['sampledTimeAvailability'] for s in stats]),
            'mttrSecondsMean': mttr, 'mtbfOperatingSecondsMean': mean([s['mtbfOperatingSeconds'] for s in stats]),
            'mttfSecondsMean': mean([s['mttfSeconds'] for s in stats]),
            'failureRatePerSecondMean': mean([s['failureRatePerSecond'] for s in stats]),
            'requests': total, 'failedRequests': total - ok, 'totalObservationSeconds': window,
            'noFailureCensoredRuns': sum(s['mttfRightCensored'] for s in stats),
        })
        for r in group:
            samples.extend({'namespace': ns, 'scenario': scenario, 'repetition': r['repetition'], **s} for s in r['samples'])
    return rows, reliability, samples


def payment_rows(files):
    rows = []
    by_ns = collections.defaultdict(list)
    for r in files['payment']:
        by_ns[r['namespace']].append(r)
    for ns in sorted(by_ns, key=lambda n: n != BASELINE):
        group = by_ns[ns]
        recovered = [r for r in group if r['recovered']]
        # The baseline has no recovery mechanism for this case, so it has no pass criterion.
        rows.append(row('4 Interrupted transaction', 'published message lost', PROFILE[ns], len(group),
                        None if ns == BASELINE else len(recovered),
                        success=f'{len(recovered)}/{len(group)} recovered',
                        recovery_s=fmt(mean([r['recoverySeconds'] for r in recovered])) or 'never (censored)',
                        recovery_actions='outbox reconciliation' if ns != BASELINE else '',
                        integrity=counts_text(recovered) if recovered else f'{len(group)} purchase(s) stuck pending',
                        notes='' if recovered else f"observed {fmt(mean([r['observationSeconds'] for r in group]), 0)} s"))
    by_hook = collections.defaultdict(list)
    for r in files['checkpoint']:
        by_hook[r['scenario']].append(r)
    for hook, group in sorted(by_hook.items()):
        rows.append(row('4 Interrupted transaction', f'worker killed {hook}', 'FT', len(group), sum(r['recovered'] for r in group),
                        success=f"{sum(r['recovered'] for r in group)}/{len(group)} recovered",
                        recovery_s=fmt(mean([r['recoverySeconds'] for r in group])),
                        recovery_actions='lease expiry + replay', integrity=counts_text(group)))
    if files['broker']:
        group = files['broker']
        ok = [r for r in group if r['status'] == 'succeeded']
        rows.append(row('2 Dependency: queue Redis', 'broker down during checkout', 'FT', len(group), len(ok),
                        success=f'{len(ok)}/{len(group)} accepted and completed',
                        recovery_s=fmt(mean([r['secondsAfterRedisReady'] for r in ok])),
                        recovery_actions='relay restart + receiver backoff',
                        integrity=f"{sum(r['attempts'] == 1 for r in ok)}/{len(ok)} with 1 attempt",
                        notes='recovery measured from Redis ready'))
    return rows


def timeout_rows(files):
    rows = []
    by_ns = collections.defaultdict(list)
    for r in files['timeout']:
        by_ns[r['namespace']].append(r['summary'])
    for ns in sorted(by_ns, key=lambda n: n != BASELINE):
        group = by_ns[ns]
        no_side_effects = sum(s['integrity']['receipts'] == 0 and s['integrity']['enrollments'] == 0 for s in group)
        rows.append(row('3 Delay / timeout', 'provider 3 s vs 2 s deadline', PROFILE[ns], len(group), sum(s['pass'] for s in group),
                        success=f"{sum(s['terminal'] for s in group)}/{len(group)} terminal",
                        recovery_s=fmt(mean([s['secondsToTerminal'] for s in group])),
                        latency=f"attempt max {fmt(max(s['maxAttemptMs'] or 0 for s in group), 0)} ms",
                        recovery_actions=f"{fmt(mean([s['attempts'] for s in group]), 1)} attempts/purchase",
                        integrity=f'{no_side_effects}/{len(group)} no receipt or enrollment',
                        notes='recovery = time to terminal state'))
    group = [r['summary'] for r in files['circuit']]
    if group:
        rows.append(row('3 Delay / timeout', 'circuit breaker', 'FT', len(group), sum(s['pass'] for s in group),
                        success=f"{sum(s['goodPurchaseStatus'] == 'succeeded' for s in group)}/{len(group)} good purchase succeeded",
                        detection_s=fmt(mean([s['secondsToOpen'] for s in group])),
                        recovery_s=fmt(mean([s['goodPurchaseSecondsToTerminal'] for s in group])),
                        recovery_actions=f"{fmt(mean([s['circuitOpenings'] for s in group]), 1)} openings/run",
                        integrity=counts_text([{'counts': s['goodPurchaseIntegrity']} for s in group]),
                        notes='detection = time until open; recovery = good purchase to success'))
    return rows


def spike_rows(files):
    rows = []
    for r in sorted(files['spike'], key=lambda r: r['namespace'] != BASELINE):
        phases = r['summary']['phases']
        spike = phases['spike']
        errors = spike['serverErrors5xx'] + spike['connectionErrors']
        # Valid only if the load generator kept to its schedule (docs/experiments.md, E5).
        valid = all((p.get('maxClientStartDelayMs') or 0) < 100 for p in phases.values())
        rows.append(row('5 Load spike', f"{spike['targetRps']} req/s for {spike['seconds']} s", PROFILE[r['namespace']], 1,
                        None if r['namespace'] == BASELINE else int(r['summary']['pass'] and valid),
                        success=f"{spike['ok']}/{spike['requests']}", availability=pct(spike['ok'], spike['requests']),
                        latency=f"p50 {fmt(spike['p50Ms'], 0)} / p95 {fmt(spike['p95Ms'], 0)} / p99 {fmt(spike['p99Ms'], 0)} ms",
                        recovery_actions=f"{spike['rateLimited429']} × 429 (admission control)",
                        notes=f"goodput {fmt(spike['goodputRps'], 1)} req/s; {errors} 5xx/connection errors; "
                              f"cool-down p95 {fmt(phases['cooldown']['p95Ms'], 0)} ms; warm-up p95 {fmt(phases['warmup']['p95Ms'], 0)} ms"
                              + ('' if valid else '; INVALID: load generator fell behind')))
    return rows


# Expected detection upper bounds (docs/experiments.md, E6): rule window + scrape/evaluation delay.
ALERT_BOUND_S = {'DatabaseDown': 40, 'ApiReplicasDegraded': 60, 'AuthFailureSpike': 90, 'PaymentBacklogStuck': 120}


def alert_rows(files):
    rows = []
    by_scenario = collections.defaultdict(list)
    for r in files['alert']:
        by_scenario[r['scenario']].append(r['summary'])
    for scenario, group in sorted(by_scenario.items()):
        correlated = [s['logsCorrelated'] for s in group if s['logsCorrelated'] is not None]
        integrity = [s['integrity'] for s in group if s.get('integrity')]
        bound = ALERT_BOUND_S.get(group[0]['alert'], 1e9)
        passed = sum(s['pass'] and (s['detectionSeconds'] or 1e9) <= bound for s in group)
        rows.append(row('6 Monitoring / alert', f"{group[0]['alert']} ({scenario})", 'FT', len(group), passed,
                        success=f"{sum(s['fired'] for s in group)}/{len(group)} fired, {sum(s['resolved'] for s in group)}/{len(group)} resolved",
                        detection_s=fmt(mean([s['detectionSeconds'] for s in group])),
                        recovery_s=fmt(mean([s['resolveSecondsAfterRestore'] for s in group])),
                        integrity=counts_text([{'counts': i} for i in integrity]) if integrity else '',
                        notes=(f'logs correlated {sum(correlated)}/{len(correlated)}; ' if correlated else '')
                              + f'bound {bound} s; detection = fault to alert firing; recovery = repair to alert resolved'))
    return rows


def security_rows(files):
    group = files['security']
    if not group:
        return []
    cases = [c for r in group for c in r['cases']]
    failed = sorted({c['case'] for c in cases if not c['pass']})
    return [row('7 Unauthorized / invalid', f"{len(group[0]['cases'])} misuse cases", 'FT', len(group), sum(r['summary']['pass'] for r in group),
                success=f"{sum(c['pass'] for c in cases)}/{len(cases)} denied as expected",
                integrity=f"{sum(c['auditPass'] for c in cases)}/{len(cases)} audited in logs",
                notes='failed: ' + ', '.join(failed) if failed else '401/403/429 also logged as security.denied')]


def restore_rows(files):
    group = [r['summary'] for r in files['restore']]
    if not group:
        return []
    return [row('8 Recovery / rollback', 'backup + isolated restore', 'FT', len(group), sum(s['pass'] for s in group),
                success=f"{sum(bool(s['checksumVerified']) for s in group)}/{len(group)} checksum OK",
                recovery_s=fmt(mean([s['backupSeconds'] + s['restoreVerifySeconds'] for s in group])),
                integrity=f"{sum(bool(s['matchesCurrentSource']) for s in group)}/{len(group)} match source; "
                          f"{sum(s['violations'] or 0 for s in group)} violations",
                notes='recovery = dump + restore + verify (small dataset)')]


def images():
    """Application images of running pods, from every experiments.py inventory."""
    found = set()
    for path in ROOT.glob('inventory*.json'):
        inventory = json.loads(path.read_text())
        for pods in inventory['pods'].values():
            for pod in pods['items']:
                if pod['status'].get('phase') == 'Running':
                    found.update(c['image'] for c in pod['spec']['containers'] if 'university' in c['image'])
    return sorted(found)


def write_csv(name, rows, fields=None):
    if not rows:
        return
    with (ROOT / name).open('w', newline='') as file:
        writer = csv.DictWriter(file, fieldnames=fields or list(rows[0]), lineterminator='\n')
        writer.writeheader()
        writer.writerows(rows)


def main():
    files = load()
    rows, reliability, samples = availability_rows(files['availability'])
    rows += payment_rows(files) + timeout_rows(files) + spike_rows(files) + alert_rows(files) + security_rows(files) + restore_rows(files)
    rows.sort(key=lambda r: (r['experiment'][0], r['experiment'], r['scenario'], r['profile'] != 'Baseline'))
    rows = [{c: r.get(c, '') for c in COLUMNS} for r in rows]

    write_csv('requests.csv', samples)
    write_csv('reliability.csv', reliability)
    (ROOT / 'reliability.json').write_text(json.dumps(reliability, indent=2) + '\n')
    write_csv('results.csv', rows, COLUMNS)

    app_images = images()
    header = ['Exp.', 'Scenario', 'Profile', 'Runs', 'Passed', 'Success', 'Availability', 'Detection (s)',
              'Recovery (s)', 'Latency', 'Recovery actions', 'Data integrity', 'Notes']
    lines = [
        '# Consolidated experiment results', '',
        f'Environment: {ENVIRONMENT}. Image(s): {", ".join(app_images) or "unknown"}'
        + (' **(more than one application image: results are not from a single build)**' if len(app_images) > 1 else '') + '.',
        'All values are measured; means over the listed runs. "Passed" uses each scenario\'s built-in pass criterion; '
        'empty cells are not applicable.', '',
        '| ' + ' | '.join(header) + ' |', '|' + '---|' * len(header),
    ]
    for r in rows:
        lines.append('| ' + ' | '.join(str(r[c]).replace('|', '/') for c in COLUMNS) + ' |')
    (ROOT / 'results.md').write_text('\n'.join(lines) + '\n')
    print('\n'.join(lines))


if __name__ == '__main__':
    main()
