#!/usr/bin/env python3
"""Final-project experiments 3, 5, 6, 7 and 8, run through scripts/experiments.py:

    python3 scripts/experiments.py --only timeout      # 3: provider delay vs. 2 s deadline (B vs FT)
    python3 scripts/experiments.py --only circuit      # 3: circuit breaker opens and defers calls (FT)
    python3 scripts/experiments.py --only spike        # 5: warm-up / spike / cool-down load (B vs FT)
    python3 scripts/experiments.py --only alerts       # 6: inject faults, measure alert detection (FT)
    python3 scripts/experiments.py --only security     # 7: misuse cases denied and audited (FT)
    python3 scripts/experiments.py --only restore      # 8: backup + isolated restore verification (FT)

Each run writes one JSON file per scenario and repetition to output/final/.
"""
import base64
import concurrent.futures
import json
import pathlib
import re
import statistics
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

from env import BASE, BASELINE, GRAFANA_URL, OUT, RESILIENT, grafana_password, kubectl
from experiments import create_user, ready, scale, sql

TERMINAL = ('succeeded', 'failed', 'declined')
EXAMPLES = pathlib.Path(__file__).resolve().parent.parent / 'docs/examples'


# --- helpers -----------------------------------------------------------------

def utc():
    return time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime())


def write(name, result):
    (OUT / f'{name}.json').write_text(json.dumps(result, indent=2) + '\n')
    print(name, json.dumps(result.get('summary', result.get('pass')), default=str)[:300], flush=True)
    return result


def http(base, method, path, body=None, headers=None, token=None, timeout=20):
    """One request; returns status, latency, the server's X-Request-ID and the decoded body."""
    h = {'Accept': 'application/json', **(headers or {})}
    if token:
        h['Authorization'] = 'Bearer ' + token
    if body is not None and not isinstance(body, bytes):
        body = json.dumps(body).encode()
        h.setdefault('Content-Type', 'application/json')
    started = time.monotonic()
    request = urllib.request.Request(base + path, data=body, headers=h, method=method)
    try:
        with urllib.request.urlopen(request, timeout=timeout) as r:
            status, headers_out, data = r.status, r.headers, r.read()
    except urllib.error.HTTPError as e:
        status, headers_out, data = e.code, e.headers, e.read()
    except OSError as e:
        return {'status': 0, 'latencyMs': (time.monotonic() - started) * 1000, 'requestId': None, 'error': type(e).__name__}
    try:
        decoded = json.loads(data) if data else None
    except ValueError:
        decoded = None
    return {'status': status, 'latencyMs': (time.monotonic() - started) * 1000,
            'requestId': headers_out.get('X-Request-ID'), 'body': decoded}


def percentile(values, p):
    if not values:
        return None
    ordered = sorted(values)
    return ordered[min(len(ordered) - 1, int(round(p / 100 * (len(ordered) - 1))))]


def course_ids(base):
    return {c['name']: c['id'] for c in http(base, 'GET', '/api/courses')['body']}


def checkout(api, course_id, fixture):
    payload = json.loads((EXAMPLES / f'payment-{fixture}.json').read_text())
    return api.ok('POST', f'/api/courses/{course_id}/purchase', payload, {'Idempotency-Key': 'exp-' + uuid.uuid4().hex})


def wait_terminal(api, purchase_id, limit=90):
    deadline = time.monotonic() + limit
    while True:
        purchase = api.ok('GET', f'/api/purchases/{purchase_id}')
        if purchase['status'] in TERMINAL or time.monotonic() > deadline:
            return purchase
        time.sleep(.5)


def integrity(ns, purchase_id):
    row = sql(ns, f'SELECT (SELECT COUNT(*) FROM mock_receipts WHERE purchase_id={purchase_id}) receipts,'
                  f'(SELECT COUNT(*) FROM enrollments WHERE purchase_id={purchase_id}) enrollments,'
                  f'(SELECT COUNT(*) FROM payment_attempts WHERE purchase_id={purchase_id}) attempts')[0]
    return {k: int(v) for k, v in row.items()}


def grafana(path, data=None):
    token = base64.b64encode(('admin:' + grafana_password()).encode()).decode()
    request = urllib.request.Request(GRAFANA_URL + path, data=data, headers={'Authorization': 'Basic ' + token})
    with urllib.request.urlopen(request, timeout=20) as r:
        return r.read().decode()


def prometheus_alerts():
    return json.loads(grafana('/api/datasources/proxy/uid/prometheus/api/v1/alerts'))['data']['alerts']


def find_alert(name, state='firing', profile=None):
    for alert in prometheus_alerts():
        if alert['labels'].get('alertname') == name and alert['state'] == state \
                and (profile is None or alert['labels'].get('profile') == profile):
            return alert
    return None


def wait_alert(name, profile=None, timeout=180, resolved=False):
    """Poll Prometheus until the alert fires (or, with resolved=True, until it is gone)."""
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        firing = find_alert(name, 'firing', profile)
        pending = find_alert(name, 'pending', profile)
        if resolved and not firing and not pending:
            return {'observed': True, 'monotonic': time.monotonic(), 'utc': utc()}
        if not resolved and firing:
            return {'observed': True, 'monotonic': time.monotonic(), 'utc': utc(), 'activeAt': firing.get('activeAt'), 'alert': firing}
        time.sleep(2)
    return {'observed': False, 'monotonic': time.monotonic(), 'utc': utc()}


def logs_for(request_id, ns=RESILIENT, wait=45):
    """Find log records for one request ID in VictoriaLogs (ingestion takes a few seconds)."""
    query = (f'_time:30m kubernetes.pod_namespace:={ns} | unpack_json '
             f'| filter context.request_id:="{request_id}" OR _msg:"{request_id}" | limit 20')
    deadline = time.monotonic() + wait
    while True:
        lines = grafana('/api/datasources/proxy/uid/victorialogs/select/logsql/query',
                        urllib.parse.urlencode({'query': query}).encode()).strip().splitlines()
        records = [json.loads(line) for line in lines if line.strip()]
        if records or time.monotonic() > deadline:
            # RoadRunner can pack several JSON log lines into one message, where unpack_json
            # fails; read the event names from the raw text as well.
            messages = set()
            for r in records:
                if r.get('message'):
                    messages.add(r['message'])
                messages.update(re.findall(r'"message":"([\w.]+)"', r.get('_msg', '')))
            return {'requestId': request_id, 'found': bool(records), 'records': len(records), 'messages': sorted(messages)}
        time.sleep(3)


# --- experiment 3: network delay / timeout -----------------------------------

def timeout_scenario(ns, rep):
    """The timeout fixture makes the provider answer after 3 s; the caller's deadline is 2 s."""
    base = BASE[ns]
    api = create_user(base)
    started = time.monotonic()
    purchase = checkout(api, course_ids(base)['Payment Timeout'], 'timeout')
    final = wait_terminal(api, purchase['id'], limit=60)
    durations = [a['duration_ms'] for a in final['attempts'] if a['duration_ms'] is not None]
    summary = {
        'status': final['status'], 'terminal': final['status'] in TERMINAL,
        'secondsToTerminal': time.monotonic() - started, 'attempts': len(final['attempts']),
        'maxAttemptMs': max(durations) if durations else None, 'attemptErrors': [a['error_code'] for a in final['attempts']],
        'integrity': integrity(ns, purchase['id']),
    }
    summary['pass'] = (summary['terminal'] and summary['status'] == 'failed' and summary['attempts'] <= 3
                       and (summary['maxAttemptMs'] or 0) < 2600 and summary['integrity']['receipts'] == 0
                       and summary['integrity']['enrollments'] == 0)
    return write(f'{ns}-timeout-{rep}', {'type': 'timeout', 'namespace': ns, 'repetition': rep, 'utc': utc(),
                                          'purchaseId': purchase['id'], 'attempts': final['attempts'], 'summary': summary})


# --- experiment 3b: circuit breaker ------------------------------------------

def circuit_state(ns):
    # PHP and MySQL both run in UTC, so opened_until compares directly with UTC_TIMESTAMP().
    row = sql(ns, "SELECT consecutive_failures, opened_until, opened_until > UTC_TIMESTAMP() AS is_open, UTC_TIMESTAMP() AS now "
                  "FROM payment_circuit WHERE provider='mock'")
    if not row:
        return None
    return {**row[0], 'is_open': str(row[0]['is_open']) == '1'}


def circuit_scenario(rep):
    """Two always-failing purchases (3 attempts each) open the circuit after 5 transient failures.
    A good purchase made while it is open must be deferred without a provider call, then succeed."""
    ns, base = RESILIENT, BASE[RESILIENT]
    ids = course_ids(base)
    # Wait for a closed circuit so earlier runs do not leak into this one.
    deadline = time.monotonic() + 60
    while (state := circuit_state(ns)) and state['is_open'] and time.monotonic() < deadline:
        time.sleep(2)
    # A successful payment resets consecutive_failures to 0 (e.g. after the timeout scenario),
    # so every run starts from the same circuit state.
    reset_user = create_user(base)
    wait_terminal(reset_user, checkout(reset_user, ids['Payment Success'], 'success')['id'])
    initial = circuit_state(ns)
    failing = [create_user(base) for _ in range(2)]
    good_user = create_user(base)
    started = time.monotonic()
    failed_purchases = [checkout(a, ids['Payment Failure'], 'failure') for a in failing]
    timeline, opened_at, good = [], None, None
    while time.monotonic() - started < 90:
        state = circuit_state(ns)
        timeline.append({'t': round(time.monotonic() - started, 2), **{k: str(v) for k, v in state.items()}})
        if state['is_open'] and opened_at is None:
            opened_at = time.monotonic()
            good = checkout(good_user, ids['Payment Success'], 'success')
            good_accepted = time.monotonic()
        if good is not None:
            current = good_user.ok('GET', f"/api/purchases/{good['id']}")
            if current['status'] in TERMINAL:
                break
        time.sleep(1)
    failures = [wait_terminal(a, p['id']) for a, p in zip(failing, failed_purchases)]
    good_final = wait_terminal(good_user, good['id']) if good else None
    first_attempt = good_final['attempts'][0]['started_at'] if good_final and good_final['attempts'] else None
    open_flags = [row['is_open'] == 'True' for row in timeline]
    summary = {
        'initialConsecutiveFailures': initial['consecutive_failures'] if initial else None,
        'circuitOpened': opened_at is not None,
        # Each closed -> open transition after the first is a failed half-open probe re-opening the circuit.
        'circuitOpenings': sum(1 for prev, cur in zip([False] + open_flags, open_flags) if cur and not prev),
        'secondsToOpen': opened_at - started if opened_at else None,
        'failedPurchaseAttempts': [len(f['attempts']) for f in failures],
        'goodPurchaseStatus': good_final['status'] if good_final else None,
        'goodPurchaseAttempts': len(good_final['attempts']) if good_final else None,
        'goodPurchaseFirstAttemptUtc': first_attempt,
        'goodPurchaseSecondsToTerminal': time.monotonic() - good_accepted if good else None,
        'goodPurchaseIntegrity': integrity(ns, good['id']) if good else None,
    }
    # Failed purchases may stop short of 3 attempts: the open circuit defers, it does not consume attempts.
    summary['pass'] = (summary['circuitOpened'] and summary['goodPurchaseStatus'] == 'succeeded'
                       and summary['goodPurchaseAttempts'] == 1 and summary['goodPurchaseIntegrity'] == {'receipts': 1, 'enrollments': 1, 'attempts': 1})
    return write(f'{ns}-circuit-{rep}', {'type': 'circuit', 'namespace': ns, 'repetition': rep, 'utc': utc(),
                                          'timeline': timeline, 'summary': summary})


# --- experiment 5: load spike ------------------------------------------------

# (phase, seconds, target requests/second). Envoy's local rate limit is 200 req/s per proxy pod:
# the 300 req/s spike exceeds the baseline's single gateway but not the resilient profile's two.
PHASES = (('warmup', 20, 20), ('spike', 30, 300), ('cooldown', 20, 20))


def spike_scenario(ns, phases=PHASES):
    """Open-loop load on the catalogue: requests are sent at a fixed rate per phase, independent of
    response times, so a slow or rejecting server cannot lower the offered load."""
    base, samples, lock = BASE[ns], [], threading.Lock()
    started = time.monotonic()

    def one(phase, scheduled):
        r = http(base, 'GET', '/api/courses', timeout=10)
        with lock:
            samples.append({'phase': phase, 't': scheduled - started, 'status': r['status'], 'latencyMs': r['latencyMs'],
                            'startDelayMs': (time.monotonic() - scheduled) * 1000 - r['latencyMs']})

    # Enough threads for 300 req/s even at ~2 s latency, so the generator never falls behind.
    with concurrent.futures.ThreadPoolExecutor(max_workers=600) as pool:
        for phase, seconds, rate in phases:
            phase_start = time.monotonic()
            for i in range(int(seconds * rate)):
                scheduled = phase_start + i / rate
                delay = scheduled - time.monotonic()
                if delay > 0:
                    time.sleep(delay)
                pool.submit(one, phase, scheduled)
            # Do not let the next phase start early if the dispatcher finished ahead of time.
            remaining = phase_start + seconds - time.monotonic()
            if remaining > 0:
                time.sleep(remaining)
    per_phase = {}
    for phase, seconds, rate in phases:
        rows = [s for s in samples if s['phase'] == phase]
        ok = [s['latencyMs'] for s in rows if s['status'] == 200]
        per_phase[phase] = {
            'seconds': seconds, 'targetRps': rate, 'requests': len(rows),
            'offeredRps': len(rows) / seconds, 'goodputRps': len(ok) / seconds, 'ok': len(ok),
            'rateLimited429': sum(s['status'] == 429 for s in rows),
            'serverErrors5xx': sum(s['status'] >= 500 for s in rows),
            'connectionErrors': sum(s['status'] == 0 for s in rows),
            'p50Ms': percentile(ok, 50), 'p95Ms': percentile(ok, 95), 'p99Ms': percentile(ok, 99),
            'meanMs': statistics.mean(ok) if ok else None,
            # Large values mean the load generator itself fell behind; the run is then not valid.
            'maxClientStartDelayMs': max((s['startDelayMs'] for s in rows), default=None),
        }
    spike = per_phase['spike']
    # Pass: no server errors, and latency of accepted requests stays below 1 s. 429s are admission
    # control (reported separately), not failures.
    summary = {'phases': per_phase,
               'pass': spike['serverErrors5xx'] + spike['connectionErrors'] <= 0.01 * max(1, spike['requests']) and (spike['p95Ms'] or 1e9) < 1000}
    return write(f'{ns}-spike', {'type': 'spike', 'namespace': ns, 'utc': utc(), 'summary': summary, 'samples': samples})


# --- experiment 6: monitoring and alert trigger ------------------------------

def alert_run(name, alert, profile, inject, restore, probe, rep, fire_timeout=180):
    """inject() causes the fault, probe() returns request IDs to correlate in logs, restore() repairs."""
    events = {'start': utc()}
    injected = time.monotonic()
    restored = None
    details = {}
    try:
        details.update(inject() or {})
        events['injected'] = utc()
        request_ids = probe() if probe else []
        fired = wait_alert(alert, profile, timeout=fire_timeout)
        events['fired'] = fired['utc']
    finally:
        details.update(restore() or {})
        restored = time.monotonic()
        events['restored'] = utc()
    resolved = wait_alert(alert, profile, timeout=240, resolved=True)
    events['resolved'] = resolved['utc']
    correlation = [logs_for(rid) for rid in request_ids[:3] if rid]
    summary = {
        'alert': alert, 'fired': fired['observed'],
        'detectionSeconds': fired['monotonic'] - injected if fired['observed'] else None,
        'ruleActiveAt': fired.get('activeAt'),
        'resolved': resolved['observed'], 'resolveSecondsAfterRestore': resolved['monotonic'] - restored if resolved['observed'] else None,
        'logsCorrelated': all(c['found'] for c in correlation) if correlation else None,
        **details,
    }
    summary['pass'] = summary['fired'] and summary['resolved'] and summary['logsCorrelated'] is not False
    return write(f'{RESILIENT}-alert-{name}-{rep}', {'type': 'alert', 'scenario': name, 'namespace': RESILIENT, 'repetition': rep,
                                                     'events': events, 'firingAlert': fired.get('alert'), 'logCorrelation': correlation, 'summary': summary})


def alerts_scenario(rep):
    ns, base = RESILIENT, BASE[RESILIENT]
    student = create_user(base)
    results = []

    def stop(kind, name, component):
        scale(ns, kind, name, 0)
        kubectl(ns, 'wait', '--for=delete', 'pod', '-l', 'component=' + component, '--timeout=90s')

    def start(kind, name, replicas=1):
        scale(ns, kind, name, replicas)
        ready(ns, kind, name)

    # MySQL down: /api/me needs the database, so it fails with a correlatable request ID.
    results.append(alert_run('database_down', 'DatabaseDown', 'resilient',
                             lambda: stop('statefulset', 'mysql', 'mysql'),
                             lambda: start('statefulset', 'mysql'),
                             lambda: [http(base, 'GET', '/api/me', token=student.token)['requestId']], rep))

    # Queue Redis down: purchases are accepted but cannot progress, so the oldest pending age grows.
    pending = {}

    def queue_down():
        stop('statefulset', 'redis-queue', 'redis-queue')
        pending['purchase'] = checkout(student, course_ids(base)['Payment Success'], 'success')
        return {'purchaseId': pending['purchase']['id']}

    def queue_up():
        start('statefulset', 'redis-queue')
        final = wait_terminal(student, pending['purchase']['id'], limit=120) if pending else None
        return {'purchaseStatusAfterRecovery': final['status'] if final else None,
                'integrity': integrity(ns, pending['purchase']['id']) if pending else None}

    results.append(alert_run('payment_backlog', 'PaymentBacklogStuck', 'resilient', queue_down, queue_up, None, rep, fire_timeout=240))

    # One API instance left: service continues, but redundancy is lost.
    def one_api():
        scale(ns, 'deployment', 'api', 1)

    results.append(alert_run('api_degraded', 'ApiReplicasDegraded', None, one_api,
                             lambda: start('deployment', 'api', 2),
                             lambda: [http(base, 'GET', '/api/courses')['requestId']], rep))

    # Burst of unauthenticated requests (about 3/s) until the denial-rate alert fires.
    burst = {'stop': threading.Event(), 'ids': [], 'count': 0}

    def flood():
        while not burst['stop'].is_set():
            r = http(base, 'GET', '/api/me')
            burst['count'] += 1
            if r['requestId'] and len(burst['ids']) < 3:
                burst['ids'].append(r['requestId'])
            burst['stop'].wait(.33)

    thread = threading.Thread(target=flood)

    def begin_flood():
        thread.start()

    def end_flood():
        burst['stop'].set()
        thread.join()
        return {'deniedRequestsSent': burst['count']}

    def flood_ids():
        time.sleep(3)  # Let the flood collect a few request IDs to look up in the logs.
        return list(burst['ids'])

    results.append(alert_run('auth_spike', 'AuthFailureSpike', None, begin_flood, end_flood, flood_ids, rep))
    return results


# --- experiment 7: unauthorized and invalid operations -----------------------

def security_scenario(rep):
    ns, base = RESILIENT, BASE[RESILIENT]
    ids = course_ids(base)
    owner, other = create_user(base), create_user(base)
    admin_token = http(base, 'POST', '/api/login', {'login': 'admin', 'password': 'AdminPass123!'})['body']['token']
    purchase = checkout(owner, ids['Payment Success'], 'success')
    header, payload, signature = owner.token.split('.')
    claims = json.loads(base64.urlsafe_b64decode(payload + '=' * (-len(payload) % 4)))
    claims['login'] = 'admin'
    tampered = '.'.join([header, base64.urlsafe_b64encode(json.dumps(claims).encode()).decode().rstrip('='), signature])
    card = json.loads((EXAMPLES / 'payment-success.json').read_text())
    assignment = None
    owner.ok('POST', f"/api/courses/{ids['Free Computing']}/enroll")
    assignments = owner.ok('GET', f"/api/courses/{ids['Free Computing']}/assignments")
    if assignments:
        assignment = assignments[0]['id']
    boundary = 'exp' + uuid.uuid4().hex
    oversized = (f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="big.bin"\r\n'
                 f'Content-Type: application/octet-stream\r\n\r\n').encode() + b'x' * 10_000_001 + f'\r\n--{boundary}--\r\n'.encode()

    cases = [
        ('no_token', 401, lambda: http(base, 'GET', '/api/purchases')),
        ('forged_jwt', 401, lambda: http(base, 'GET', '/api/purchases', token='eyJhbGciOiJSUzI1NiJ9.eyJsb2dpbiI6ImFkbWluIn0.forged')),
        ('tampered_jwt_claims', 401, lambda: http(base, 'GET', '/api/me', token=tampered)),
        ('register_as_admin', 400, lambda: http(base, 'POST', '/api/register', {'login': 'esc-' + uuid.uuid4().hex[:10], 'username': 'Escalation', 'password': 'StudentPass123!', 'isAdmin': True})),
        ('student_calls_admin_endpoint', 403, lambda: http(base, 'POST', '/api/courses', {'name': 'Unauthorized course'}, token=owner.token)),
        ('read_other_students_purchase', 404, lambda: http(base, 'GET', f"/api/purchases/{purchase['id']}", token=other.token)),
        ('client_sets_price', 400, lambda: http(base, 'POST', f"/api/courses/{ids['Payment Success']}/purchase", {**card, 'amount': 1}, {'Idempotency-Key': 'exp-' + uuid.uuid4().hex}, token=other.token)),
        ('admin_negative_price', 400, lambda: http(base, 'POST', '/api/courses', {'name': 'Invalid price', 'price': -100}, token=admin_token)),
        ('oversized_upload', 400, lambda: http(base, 'POST', f'/api/assignments/{assignment}/submit', oversized, {'Content-Type': 'multipart/form-data; boundary=' + boundary}, token=owner.token, timeout=60)),
    ]
    results = []
    for name, expected, call in cases:
        r = call()
        results.append({'case': name, 'expected': expected, 'status': r['status'], 'pass': r['status'] == expected, 'requestId': r['requestId'], 'latencyMs': r['latencyMs']})

    # Brute force: five wrong passwords, then even the right one is throttled.
    target = 'brute-' + uuid.uuid4().hex[:10]
    http(base, 'POST', '/api/register', {'login': target, 'username': 'Brute Force Target', 'password': 'StudentPass123!'})
    statuses, last = [], None
    for attempt in range(5):
        last = http(base, 'POST', '/api/login', {'login': target, 'password': f'wrong-{attempt}'})
        statuses.append(last['status'])
    last = http(base, 'POST', '/api/login', {'login': target, 'password': 'StudentPass123!'})
    statuses.append(last['status'])
    results.append({'case': 'brute_force_login', 'expected': [401] * 5 + [429], 'status': statuses,
                    'pass': statuses == [401] * 5 + [429], 'requestId': last['requestId']})

    for row in results:
        row['audit'] = logs_for(row['requestId']) if row['requestId'] else {'found': False}
        denied = row['expected'] in (401, 403) or isinstance(row['expected'], list)
        row['auditPass'] = row['audit']['found'] and (not denied or any('security.denied' in m for m in row['audit']['messages']))
    summary = {'cases': len(results), 'passed': sum(r['pass'] for r in results), 'audited': sum(r['auditPass'] for r in results)}
    summary['pass'] = summary['passed'] == summary['cases'] and summary['audited'] == summary['cases']
    return write(f'{ns}-security-{rep}', {'type': 'security', 'namespace': ns, 'repetition': rep, 'utc': utc(), 'cases': results, 'summary': summary})


# --- experiment 8: backup and isolated restore -------------------------------

def console_json(*args):
    """Run a console command in the resilient API pod and return its last JSON line."""
    out = kubectl(RESILIENT, 'exec', 'deploy/api', '-c', 'api', '--', 'php', 'bin/console', *args, timeout=300)
    lines = [line for line in out.splitlines() if line.startswith('{') and '"level_name"' not in line]
    return json.loads(lines[-1])


def restore_scenario(rep):
    """Take a fresh MySQL dump, restore it into a new isolated database and compare row hashes."""
    started = time.monotonic()
    backup = console_json('app:backup')
    dumped = time.monotonic()
    restored = console_json('app:restore-verify', backup['key'])
    finished = time.monotonic()
    summary = {
        'backupKey': backup['key'], 'backupBytes': backup.get('bytes'),
        'backupSeconds': dumped - started, 'restoreVerifySeconds': finished - dumped,
        'checksumVerified': restored.get('checksumVerified'), 'matchesCurrentSource': restored.get('matchesCurrentSource'),
        'counts': restored.get('counts'), 'violations': restored.get('violations'),
        'restoreDatabase': restored.get('database'),
    }
    summary['pass'] = bool(summary['checksumVerified'] and summary['matchesCurrentSource'] and not summary['violations'])
    return write(f'{RESILIENT}-restore-{rep}', {'type': 'restore', 'namespace': RESILIENT, 'repetition': rep, 'utc': utc(),
                                                 'backup': backup, 'restore': restored, 'summary': summary})


def run(only, repetitions):
    results = []
    for rep in range(1, repetitions + 1):
        if only == 'timeout':
            results += [timeout_scenario(ns, rep) for ns in (BASELINE, RESILIENT)]
        elif only == 'circuit':
            results.append(circuit_scenario(rep))
        elif only == 'alerts':
            results += alerts_scenario(rep)
        elif only == 'security':
            results.append(security_scenario(rep))
        elif only == 'restore':
            results.append(restore_scenario(rep))
    if only == 'spike':
        results += [spike_scenario(ns) for ns in (BASELINE, RESILIENT)]
    return results
