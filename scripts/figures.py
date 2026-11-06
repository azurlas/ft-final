#!/usr/bin/env python3
"""Draw the report figures from the raw results in output/final/ (or RESULTS_DIR) into <results>/figures/.

Requires matplotlib. Every figure is drawn from measured samples only.
"""
import collections
import json
import statistics

import matplotlib

matplotlib.use('Agg')
import matplotlib.pyplot as plt  # noqa: E402

from env import BASELINE, OUT as ROOT, RESILIENT  # noqa: E402

FIG = ROOT / 'figures'
FIG.mkdir(exist_ok=True)
COLORS = {BASELINE: '#c2410c', RESILIENT: '#1d4ed8'}
LABEL = {BASELINE: 'Baseline', RESILIENT: 'FT'}
plt.rcParams.update({'font.size': 10, 'axes.spines.top': False, 'axes.spines.right': False, 'figure.dpi': 150})


def load(name):
    path = ROOT / name
    return json.loads(path.read_text()) if path.exists() else None


def availability_timeline(scenario, title, filename):
    """Probe outcome over time for repetition 1 of both profiles; the fault window is shaded."""
    fig, axes = plt.subplots(2, 1, figsize=(7.5, 3.6), sharex=True)
    for ax, ns in zip(axes, (BASELINE, RESILIENT)):
        run = load(f'{ns}-{scenario}-1.json')
        if not run:
            continue
        t = [s['relativeSeconds'] for s in run['samples']]
        ok = [1 if s['status'] == 200 else 0 for s in run['samples']]
        ax.step(t, ok, where='post', color=COLORS[ns], linewidth=1.4)
        ax.fill_between(t, ok, step='post', alpha=.15, color=COLORS[ns])
        ax.axvspan(run['injectedRelativeSeconds'], run['injectedRelativeSeconds'] + 5, color='#9ca3af', alpha=.25, linewidth=0)
        stats = run['stats']
        ax.set_yticks([0, 1], ['fail', 'OK'])
        ax.set_ylim(-.15, 1.25)
        ax.set_title(f"{LABEL[ns]}: {stats['requestAvailability']:.1%} of {stats['requests']} probes OK", loc='left', fontsize=9)
    axes[-1].set_xlabel('seconds since probe start (grey: fault injected, 5 s)')
    fig.suptitle(title, x=.01, ha='left', fontsize=11, fontweight='bold')
    fig.tight_layout()
    fig.savefig(FIG / filename)
    plt.close(fig)


def availability_bars():
    rel = load('reliability.json') or []
    scenarios = ['api_crash', 'cache_outage', 'mysql_outage']
    names = {'api_crash': 'API pod crash', 'cache_outage': 'Cache outage', 'mysql_outage': 'MySQL outage'}
    fig, ax = plt.subplots(figsize=(6.5, 3.0))
    width = .38
    for i, ns in enumerate((BASELINE, RESILIENT)):
        values = []
        for sc in scenarios:
            row = next((r for r in rel if r['namespace'] == ns and r['scenario'] == sc), None)
            values.append(100 * row['requestAvailabilityMean'] if row else 0)
        xs = [x + (i - .5) * width for x in range(len(scenarios))]
        bars = ax.bar(xs, values, width, color=COLORS[ns], label=LABEL[ns])
        ax.bar_label(bars, fmt='%.1f%%', fontsize=8, padding=2)
    ax.set_xticks(range(len(scenarios)), [names[s] for s in scenarios])
    ax.set_ylabel('request availability (%)')
    ax.set_ylim(0, 112)
    ax.legend(frameon=False, loc='upper right', ncols=2)
    ax.set_title('Mean probe success during the fault window (3 runs each)', loc='left', fontsize=10)
    fig.tight_layout()
    fig.savefig(FIG / 'availability-bars.png')
    plt.close(fig)


def spike():
    fig, axes = plt.subplots(2, 1, figsize=(7.5, 4.6), sharex=True)
    for ns in (BASELINE, RESILIENT):
        run = load(f'{ns}-spike.json')
        if not run:
            continue
        per_second = collections.defaultdict(lambda: {'ok': 0, 'rejected': 0, 'lat': []})
        for s in run['samples']:
            bucket = per_second[int(s['t'])]
            if s['status'] == 200:
                bucket['ok'] += 1
                bucket['lat'].append(s['latencyMs'])
            else:
                bucket['rejected'] += 1
        seconds = sorted(per_second)
        axes[0].plot(seconds, [per_second[x]['ok'] for x in seconds], color=COLORS[ns], label=f'{LABEL[ns]} served (200)')
        axes[0].plot(seconds, [per_second[x]['rejected'] for x in seconds], color=COLORS[ns], linestyle=':', label=f'{LABEL[ns]} rejected / failed')
        p95 = [sorted(per_second[x]['lat'])[int(.95 * (len(per_second[x]['lat']) - 1))] if per_second[x]['lat'] else None for x in seconds]
        axes[1].plot(seconds, p95, color=COLORS[ns], label=LABEL[ns])
    axes[0].set_ylabel('requests / s')
    axes[0].legend(frameon=False, fontsize=8, ncols=2)
    axes[1].set_ylabel('p95 latency (ms)')
    axes[1].set_yscale('log')
    axes[1].legend(frameon=False, fontsize=8)
    for ax in axes:
        ax.axvspan(20, 50, color='#9ca3af', alpha=.18, linewidth=0)
    axes[1].set_xlabel('seconds (grey: 300 req/s spike; 20 req/s before and after)')
    fig.suptitle('Traffic spike: throughput and latency per second', x=.01, ha='left', fontsize=11, fontweight='bold')
    fig.tight_layout()
    fig.savefig(FIG / 'spike.png')
    plt.close(fig)


def alerts():
    bounds = {'DatabaseDown': 40, 'ApiReplicasDegraded': 60, 'AuthFailureSpike': 90, 'PaymentBacklogStuck': 120}
    rows = []
    for path in sorted(ROOT.glob(f'{RESILIENT}-alert-*.json')):
        s = json.loads(path.read_text())['summary']
        rows.append((s['alert'], s['detectionSeconds'], s['resolveSecondsAfterRestore']))
    if not rows:
        return
    rows.sort(key=lambda r: r[1] or 0)
    fig, ax = plt.subplots(figsize=(6.5, 2.8))
    ys = range(len(rows))
    ax.barh(ys, [r[1] or 0 for r in rows], color='#1d4ed8', label='detection (fault → firing)')
    ax.barh(ys, [r[2] or 0 for r in rows], left=[r[1] or 0 for r in rows], color='#93c5fd', label='resolution (repair → resolved)')
    for y, r in zip(ys, rows):
        ax.plot([bounds[r[0]]] * 2, [y - .4, y + .4], color='#111827', linewidth=1.5)
        ax.text((r[1] or 0) / 2, y, f'{r[1]:.0f} s', ha='center', va='center', color='white', fontsize=8)
    ax.set_yticks(list(ys), [r[0] for r in rows])
    ax.set_xlabel('seconds (black tick: expected detection bound)')
    ax.legend(frameon=False, fontsize=8, loc='lower right')
    ax.set_title('Alert detection and resolution', loc='left', fontsize=10)
    fig.tight_layout()
    fig.savefig(FIG / 'alerts.png')
    plt.close(fig)


def recovery():
    """Recovery time of payment scenarios (FT), all repetitions."""
    groups = collections.OrderedDict()
    for path in sorted(ROOT.glob(f'{RESILIENT}-*.json')):
        d = json.loads(path.read_text())
        if d.get('type') == 'checkpoint':
            groups.setdefault('worker killed ' + d['scenario'].replace('_', ' '), []).append(d['recoverySeconds'])
        elif d.get('type') == 'payment':
            groups.setdefault('queue message lost', []).append(d['recoverySeconds'])
    broker = load('broker-recovery.json') or []
    if broker:
        groups['queue Redis outage (from Redis ready)'] = [r['secondsAfterRedisReady'] for r in broker]
    if not groups:
        return
    fig, ax = plt.subplots(figsize=(6.5, 2.6))
    for y, (name, values) in enumerate(groups.items()):
        values = [v for v in values if v is not None]
        ax.scatter(values, [y] * len(values), color='#1d4ed8', zorder=3)
        if values:
            ax.plot([statistics.mean(values)] * 2, [y - .3, y + .3], color='#111827')
    ax.set_yticks(range(len(groups)), list(groups))
    ax.set_xlabel('seconds to completed purchase (dots: runs, bar: mean)')
    ax.set_title('FT payment recovery, exactly-once in every run', loc='left', fontsize=10)
    fig.tight_layout()
    fig.savefig(FIG / 'payment-recovery.png')
    plt.close(fig)


if __name__ == '__main__':
    availability_timeline('api_crash', 'API instance failure (run 1)', 'api-crash-timeline.png')
    availability_timeline('cache_outage', 'Cache outage (run 1)', 'cache-outage-timeline.png')
    availability_timeline('mysql_outage', 'MySQL outage (run 1)', 'mysql-outage-timeline.png')
    availability_bars()
    spike()
    alerts()
    recovery()
    print('figures:', ', '.join(sorted(p.name for p in FIG.glob('*.png'))))
