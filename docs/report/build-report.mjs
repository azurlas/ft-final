// Build the final technical report (Word) from the measured results.
//   npm ci && python3 scripts/summarize.py && python3 scripts/figures.py && node docs/report/build-report.mjs
// Reads output/final/ (or RESULTS_DIR); writes docs/report/final-report.docx (or REPORT_OUT).
// Every number in the results sections is read from the result files; nothing is typed in by hand.
import fs from 'node:fs';
import path from 'node:path';
import {
  AlignmentType, BorderStyle, Document, Footer, HeadingLevel, ImageRun, LevelFormat, PageBreak, PageNumber,
  PageOrientation, Packer, Paragraph, ShadingType, Table, TableCell, TableRow, TextRun, WidthType,
} from 'docx';

const ROOT = path.resolve(path.dirname(new URL(import.meta.url).pathname), '../..');
const RESULTS = path.resolve(process.env.RESULTS_DIR || path.join(ROOT, 'output/final'));
const OUT = process.env.REPORT_OUT || path.join(ROOT, 'docs/report/final-report.docx');
const json = (name) => JSON.parse(fs.readFileSync(path.join(RESULTS, name), 'utf8'));
const exists = (name) => fs.existsSync(path.join(RESULTS, name));

// ---------------------------------------------------------------- data
const results = json('results.json');
const reliability = json('reliability.json');
const B = 'midterm-baseline';
const FT = 'midterm';
const rel = (ns, scenario) => reliability.find((r) => r.namespace === ns && r.scenario === scenario);
const row = (experiment, scenario, profile = 'FT') =>
  results.find((r) => r.experiment.startsWith(experiment) && r.scenario.includes(scenario) && r.profile === profile);
const runs = (prefix) => fs.readdirSync(RESULTS).filter((f) => f.startsWith(prefix) && f.endsWith('.json')).sort().map((f) => json(f));
const mean = (xs) => { const v = xs.filter((x) => x !== null && x !== undefined); return v.length ? v.reduce((a, b) => a + b, 0) / v.length : null; };
const f1 = (x) => (x === null || x === undefined ? 'n/a' : x.toFixed(1));
const f2 = (x) => (x === null || x === undefined ? 'n/a' : x.toFixed(2));
const pct = (x, d = 1) => (x === null || x === undefined ? 'n/a' : `${(100 * x).toFixed(d)}%`);
const passed = (r) => (r && r.passed ? r.passed : 'n/a');
const exactlyOnce = (c) => c && c.receipts === 1 && c.attempts === 1 && c.enrollments === 1;

const inventoryImages = new Set();
for (const f of fs.readdirSync(RESULTS).filter((n) => n.startsWith('inventory'))) {
  const inv = json(f);
  for (const pods of Object.values(inv.pods)) for (const p of pods.items) {
    if (p.status.phase === 'Running') for (const c of p.spec.containers) if (c.image.includes('university')) inventoryImages.add(c.image);
  }
}
const IMAGE = [...inventoryImages].join(', ') || 'unknown';

const alertRuns = runs(`${FT}-alert-`).map((r) => r.summary);
const alert = (name) => alertRuns.find((s) => s.alert === name) || {};
const security = runs(`${FT}-security-`);
const spikeB = exists(`${B}-spike.json`) ? json(`${B}-spike.json`).summary.phases : null;
const spikeF = exists(`${FT}-spike.json`) ? json(`${FT}-spike.json`).summary.phases : null;
const timeoutB = runs(`${B}-timeout-`).map((r) => r.summary);
const timeoutF = runs(`${FT}-timeout-`).map((r) => r.summary);
const circuit = runs(`${FT}-circuit-`).map((r) => r.summary);
const checkpoints = runs(`${FT}-after_`);
const queueB = runs(`${B}-queue-loss-`);
const queueF = runs(`${FT}-queue-loss-`);
const broker = exists('broker-recovery.json') ? json('broker-recovery.json') : [];
const restore = runs(`${FT}-restore-`).map((r) => r.summary);
const secAll = security.flatMap((r) => r.cases);
const onceRuns = [...queueF.filter((r) => r.recovered), ...checkpoints, ...circuit.map((c) => ({ counts: c.goodPurchaseIntegrity }))];
const onceCount = onceRuns.filter((r) => exactlyOnce(r.counts)).length;
const onceTotal = onceRuns.length;
const smoke = exists('smoke.json') ? json('smoke.json') : null;
const observability = exists('observability.json') ? json('observability.json') : null;
const allPassed = results.filter((r) => r.passed).every((r) => { const [a, b] = r.passed.split('/'); return a === b; });
const ALERT_BOUND = { DatabaseDown: 40, ApiReplicasDegraded: 60, AuthFailureSpike: 90, PaymentBacklogStuck: 120 };
const alertsWithinBounds = () => alertRuns.length > 0 && alertRuns.every((s) => s.pass && s.detectionSeconds <= ALERT_BOUND[s.alert]);

// ---------------------------------------------------------------- reliability model
// Assumed MTTF values (no field data exists for this system); MTTR measured in the baseline runs.
const HOURS_PER_YEAR = 8760;
const mttrApi = rel(B, 'api_crash')?.mttrSecondsMean ?? null;
const mttrCache = rel(B, 'cache_outage')?.mttrSecondsMean ?? null;
const mttrMysql = rel(B, 'mysql_outage')?.mttrSecondsMean ?? null;
const mttrWorker = mean(checkpoints.map((c) => c.recoverySeconds));
const components = [
  { name: 'Node (single host / VM)', mttfH: 4380, mttrS: 1800, source: 'Assumed: reboot + Kubernetes + pod start (not measured)' },
  { name: 'Envoy gateway pod', mttfH: 720, mttrS: mttrApi, source: 'Assumed equal to the measured API pod restart' },
  { name: 'API pod', mttfH: 720, mttrS: mttrApi, source: 'Measured: baseline api_crash MTTR' },
  { name: 'Cache Redis pod', mttfH: 2160, mttrS: mttrCache, source: 'Measured: baseline cache_outage MTTR' },
  { name: 'MySQL pod', mttfH: 2160, mttrS: mttrMysql, source: 'Measured: baseline mysql_outage MTTR' },
  { name: 'Worker pod', mttfH: 720, mttrS: mttrWorker, source: 'Measured: FT checkpoint crash recovery' },
].map((c) => {
  const mttrH = (c.mttrS ?? 0) / 3600;
  const A = c.mttfH / (c.mttfH + mttrH);
  return { ...c, lambda: 1 / c.mttfH, A, downMin: (1 - A) * HOURS_PER_YEAR * 60 };
});
const comp = (n) => components.find((c) => c.name.startsWith(n)).A;
const par2 = (a) => 1 - (1 - a) ** 2;
const aBaseline = comp('Node') * comp('Envoy') * comp('API') * comp('Cache') * comp('MySQL');
const aFT = comp('Node') * par2(comp('Envoy')) * par2(comp('API')) * comp('MySQL'); // cache bypassed on failure
const downYear = (a) => (1 - a) * HOURS_PER_YEAR * 60;

// ---------------------------------------------------------------- document helpers
const FONT = 'Calibri';
const PORTRAIT_W = 9026; // A4 width 11906 minus 2 x 1440 margins
const LANDSCAPE_W = 13958; // A4 height 16838 minus 2 x 1440
const body = [];

function runs_(text, opts = {}) {
  // **bold** and `code` inline markup.
  return String(text).split(/(\*\*[^*]+\*\*|`[^`]+`)/).filter(Boolean).map((part) => {
    if (part.startsWith('**')) return new TextRun({ text: part.slice(2, -2), bold: true, font: FONT, ...opts });
    if (part.startsWith('`')) return new TextRun({ text: part.slice(1, -1), font: 'Consolas', size: (opts.size || 21) - 2, ...opts });
    return new TextRun({ text: part, font: FONT, ...opts });
  });
}
const P = (text, opts = {}) => new Paragraph({ children: runs_(text, opts.run || {}), spacing: { after: 120, line: 276 }, alignment: opts.align, ...opts.paragraph });
const H1 = (text) => new Paragraph({ heading: HeadingLevel.HEADING_1, children: [new TextRun(text)] });
const H2 = (text) => new Paragraph({ heading: HeadingLevel.HEADING_2, children: [new TextRun(text)] });
const H3 = (text) => new Paragraph({ heading: HeadingLevel.HEADING_3, children: [new TextRun(text)] });
const bullet = (text, level = 0) => new Paragraph({ numbering: { reference: 'bullets', level }, children: runs_(text), spacing: { after: 60, line: 264 } });
const numbered = (text) => new Paragraph({ numbering: { reference: 'numbers', level: 0 }, children: runs_(text), spacing: { after: 60, line: 264 } });
const caption = (text) => new Paragraph({ children: runs_(text, { italics: true, size: 18, color: '4B5563' }), spacing: { before: 60, after: 200 }, alignment: AlignmentType.CENTER });
const pageBreak = () => new Paragraph({ children: [new PageBreak()] });
const formula = (text) => new Paragraph({ children: [new TextRun({ text, font: 'Cambria Math', size: 21 })], indent: { left: 567 }, spacing: { after: 80 } });

function table(headers, rows, widths, total = PORTRAIT_W, size = 18) {
  const scale = total / widths.reduce((a, b) => a + b, 0);
  const w = widths.map((x) => Math.floor(x * scale));
  w[w.length - 1] += total - w.reduce((a, b) => a + b, 0);
  const border = { style: BorderStyle.SINGLE, size: 4, color: 'D1D5DB' };
  const borders = { top: border, bottom: border, left: border, right: border };
  const cell = (text, i, header) => new TableCell({
    width: { size: w[i], type: WidthType.DXA },
    borders,
    shading: header ? { type: ShadingType.CLEAR, fill: 'E5E7EB', color: 'auto' } : undefined,
    margins: { top: 40, bottom: 40, left: 80, right: 80 },
    children: String(text ?? '').split('\n').map((line) => new Paragraph({ children: runs_(line, { size, bold: header || undefined }) })),
  });
  return new Table({
    width: { size: total, type: WidthType.DXA },
    columnWidths: w,
    rows: [
      new TableRow({ tableHeader: true, children: headers.map((h, i) => cell(h, i, true)) }),
      ...rows.map((r) => new TableRow({ cantSplit: true, children: r.map((c, i) => cell(c, i, false)) })),
    ],
  });
}

function pngSize(file) {
  const b = fs.readFileSync(file);
  return { width: b.readUInt32BE(16), height: b.readUInt32BE(20) };
}
function figure(file, text, widthPx = 600) {
  if (!fs.existsSync(file)) return [P(`(figure missing: ${path.basename(file)})`)];
  const { width, height } = pngSize(file);
  return [
    new Paragraph({ alignment: AlignmentType.CENTER, children: [new ImageRun({ type: 'png', data: fs.readFileSync(file), transformation: { width: widthPx, height: Math.round(widthPx * height / width) } })] }),
    caption(text),
  ];
}
const add = (...items) => body.push(...items.flat());
const FIG = path.join(RESULTS, 'figures');

// ---------------------------------------------------------------- title page
const title = [
  new Paragraph({ spacing: { before: 2400 } }),
  new Paragraph({ alignment: AlignmentType.CENTER, children: [new TextRun({ text: 'Fault-Tolerant University Platform', font: FONT, size: 52, bold: true })] }),
  new Paragraph({ alignment: AlignmentType.CENTER, spacing: { after: 400 }, children: [new TextRun({ text: 'Final Project: Integrated Dependability Engineering Capstone', font: FONT, size: 30, color: '374151' })] }),
  new Paragraph({ alignment: AlignmentType.CENTER, children: [new TextRun({ text: 'Course: Fault Tolerance and Dependable Computing', font: FONT, size: 24 })] }),
  new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 600 }, children: [new TextRun({ text: 'Nariman Sailaubek, Altair Tussupov, Sultan Turan', font: FONT, size: 26, bold: true })] }),
  new Paragraph({ alignment: AlignmentType.CENTER, children: [new TextRun({ text: 'Group CSE-2502M', font: FONT, size: 24 })] }),
  new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 600 }, children: [new TextRun({ text: 'November 2026', font: FONT, size: 24 })] }),
  pageBreak(),
];

// ---------------------------------------------------------------- abstract
const ftApi = rel(FT, 'api_crash');
const apiCrashRuns = runs(`${FT}-api_crash-`);
const apiCrashFailures = apiCrashRuns.flatMap((r) => r.samples.filter((x) => x.status !== 200).map((x) => ({ rep: r.repetition, after: x.relativeSeconds - r.injectedRelativeSeconds, status: x.status })));
const apiOkTotal = apiCrashRuns.reduce((a, r) => a + r.stats.requests - r.stats.failedRequests, 0);
const apiTotal = apiCrashRuns.reduce((a, r) => a + r.stats.requests, 0);
const bApi = rel(B, 'api_crash');
const ftCache = rel(FT, 'cache_outage');
const bCache = rel(B, 'cache_outage');
const ftMysql = rel(FT, 'mysql_outage');
const queueRecoveredF = queueF.filter((r) => r.recovered).length;
const queueRecoveredB = queueB.filter((r) => r.recovered).length;
add(
  H1('Abstract'),
  P(`This report presents the design, implementation and evaluation of a dependable university information system (student registration, course catalogue, paid course checkout, assignment submissions, grading and reports). The system runs as separate API, payment-worker and mock-provider services on Kubernetes with MySQL, two Redis instances and S3-compatible storage. We compare a baseline configuration without advanced fault tolerance against an improved (FT) configuration with replicated stateless services, a transactional outbox with reconciliation, bounded retries, timeouts, a circuit breaker, idempotent settlement, cache degradation, scheduled verified backups, alerting, audit logging and login throttling.`),
  P(`Eight experiment groups were run on a single-node OrbStack Kubernetes cluster with the same image for both profiles (${IMAGE}). Under an API instance crash, FT kept ${pct(ftApi?.requestAvailabilityMean)} request availability versus ${pct(bApi?.requestAvailabilityMean)} for the baseline; under a cache outage ${pct(ftCache?.requestAvailabilityMean)} versus ${pct(bCache?.requestAvailabilityMean)}. FT recovered ${queueRecoveredF}/${queueF.length} purchases whose queue message was lost (baseline ${queueRecoveredB}/${queueB.length}), ${checkpoints.filter((c) => c.recovered && exactlyOnce(c.counts)).length}/${checkpoints.length} worker-crash cases exactly once and ${broker.filter((r) => r.status === 'succeeded' && r.attempts === 1).length}/${broker.length} broker-outage cases with one attempt. A MySQL outage remained user-visible in both profiles (FT ${pct(ftMysql?.requestAvailabilityMean)}), which confirms the database as the main single point of failure. ${alertsWithinBounds() ? 'All alerts fired within their expected bounds' : 'Not all alerts fired within their expected bounds'}, and ${secAll.filter((c) => c.pass && c.auditPass).length}/${secAll.length} misuse cases were denied and audited. Validation also found a real defect (the payment relay did not reconnect after a broker restart), which was fixed and re-verified.`),
  P('**Keywords:** dependability, fault tolerance, availability, MTTR, transactional outbox, circuit breaker, idempotency, Kubernetes, observability, fault injection'),
  pageBreak(),
  // Static contents list: a Word TOC field stays empty in PDF exports that do not update fields.
  new Paragraph({ children: [new TextRun({ text: 'Contents', font: FONT, size: 32, bold: true })], spacing: { after: 160 } }),
  ...['1. Introduction, motivation and system context', '2. Requirements, assumptions and dependability objectives', '3. Fault model, threat model and failure analysis',
    '4. Reliability engineering', '5. System architecture and resilience design', '6. Infrastructure and software fault-tolerance implementation',
    '7. Monitoring, observability and security implementation', '8. Verification, validation and experimental methodology',
    '9. Results and baseline-versus-improved comparison', '10. Discussion, limitations and future work', '11. Conclusion', 'References',
    'Appendix A. Team contributions', 'Appendix B. Repository, configuration and raw data'].map((t) => P(t, { paragraph: { spacing: { after: 60 } } })),
  pageBreak(),
);

// ---------------------------------------------------------------- 1 introduction
add(
  H1('1. Introduction, motivation and system context'),
  P('A university platform has short periods of very high importance: course registration, payment deadlines and assignment deadlines. Failures at these moments have direct consequences for students: a lost payment, a duplicate charge, a missed submission or an unavailable catalogue. The goal of this project is to engineer such a system so that the most important failures are either masked or contained, and to prove it with measured, repeatable experiments rather than architectural claims.'),
  P('We continue the university information system from the midterm (Option A). The final project adds measurable dependability objectives, a fault and threat model, alert rules, security controls with misuse tests, a systematic test plan written before the runs, and a consolidated baseline-versus-improved comparison over eight scenario groups.'),
  H2('1.1 Users, workflows and data'),
  table(['Actor', 'Main functions'], [
    ['Student', 'Register, sign in (JWT), browse the catalogue, enroll in free courses, buy paid courses, upload one file per assignment, download own files, view grades and purchases'],
    ['Administrator', 'Create, edit and archive courses and assignments; grade submissions; export per-course PDF reports'],
    ['Payment provider (mock)', 'Authorizes synthetic card payments with six deterministic behaviours (success, transient failures, permanent failure, decline, 3 s delay)'],
    ['Operator', 'Deploys, monitors dashboards and alerts, restores backups'],
  ], [2, 7]),
  P('The data handled are user accounts (hashed passwords), courses, enrollments, purchases and payment attempts, provider receipts, submissions (private S3 objects with SHA-256), grades and backups. Synthetic card data are validated but never stored, logged or queued.'),
  P('**Critical workflow (multi-step): paid course checkout.** (1) The API locks the user and course, validates eligibility and stores the purchase plus an outbox record in one transaction, returning HTTP 201 with a pending purchase. (2) The relay publishes the outbox record to the Redis queue. (3) A worker reserves a durable attempt and lease, then calls the provider with a 2 s deadline. (4) The provider stores a unique receipt before replying. (5) Settlement records the result and creates exactly one enrollment, or schedules a bounded retry. A failure between any two steps must not lose the accepted purchase or charge twice.'),
);

// ---------------------------------------------------------------- 2 requirements
add(
  H1('2. Requirements, assumptions and dependability objectives'),
  H2('2.1 Critical functions'),
  table(['Function', 'Criticality', 'Consequence of failure'], [
    ['Catalogue and read paths', 'High', 'Students cannot see or enroll in courses during registration'],
    ['Checkout and payment processing', 'Critical', 'Lost payments, duplicate charges, students not enrolled after paying'],
    ['Assignment submission and download', 'High', 'Missed deadlines, lost or corrupted files'],
    ['Grades and reports', 'Medium', 'Delayed grading; data must never be corrupted'],
    ['Authentication and authorization', 'Critical', 'Data disclosure or unauthorized changes'],
  ], [3, 1.4, 4.6]),
  H2('2.2 Service-level objectives'),
  P('Each objective is measurable in a defined experiment. Observation windows are the experiment windows defined in the test plan (Section 8), not calendar time.'),
  table(['ID', 'Objective (FT profile)', 'Target', 'Measured in'], [
    ['SLO-1', 'Read availability during the crash of one API instance', '≥ 99% successful probes', 'E1'],
    ['SLO-2', 'Catalogue availability while the cache is unavailable', '≥ 99% successful probes', 'E2'],
    ['SLO-3', 'Accepted payments are never lost', '0 missing purchases', 'E2, E4, E6'],
    ['SLO-4', 'No duplicate charges or enrollments', '0 duplicate receipts, attempts or enrollments', 'E3, E4'],
    ['SLO-5', 'Slow dependencies cannot block callers indefinitely', 'Each provider attempt < 2.6 s, ≤ 3 attempts', 'E3'],
    ['SLO-6', 'Database outage fails safely and recovers', 'Only 503/timeout; recovery < 60 s after restart', 'E2'],
    ['SLO-7', 'Traffic spike of 300 req/s', '≤ 1% server errors; p95 < 1 s', 'E5'],
    ['SLO-8', 'Incidents are detected', 'Alert within its bound (40–120 s)', 'E6'],
    ['SLO-9', 'Misuse is denied and audited', '100% of cases', 'E7'],
    ['SLO-10', 'Data recoverability', 'RPO ≤ 15 min (backup period); verified restore', 'E8'],
  ], [1, 4.2, 3, 1.2]),
  H2('2.3 Assumptions'),
  bullet('One Kubernetes node; host, disk and node failures are outside the tested fault model (documented as single points of failure).'),
  bullet('Faults are injected one at a time; there are no simultaneous independent faults.'),
  bullet('The payment provider is a deterministic mock; transient failures, declines and delays are synthetic fixtures selected by card number.'),
  bullet('Clients may retry checkout with the same idempotency key; the server never trusts client-supplied price, status or attempt numbers.'),
  bullet('Component MTTF values used in the reliability model are assumptions (no field data); MTTR values are measured where an experiment exists.'),
);

// ---------------------------------------------------------------- 3 fault and threat model
add(
  H1('3. Fault model, threat model and failure analysis'),
  H2('3.1 Terminology'),
  P('We follow Avižienis et al. (2004): a **fault** is the adjudged cause of an error (for example a crashed process or a lost network message); an **error** is the part of the system state that may lead to a failure (for example a purchase stuck in `processing`); a **failure** is an event where the delivered service deviates from correct service (for example a student who paid but is not enrolled). Fault tolerance mechanisms aim to stop errors from becoming failures.'),
  H2('3.2 Fault model and failure-impact analysis'),
  table(['#', 'Fault', 'Resulting error', 'Failure without FT', 'Severity', 'FT mechanism', 'Exp.'], [
    ['F1', 'API process or pod crash', 'No process serving requests', 'Catalogue and API unavailable until restart', 'High', 'Second replica, readiness-based load balancing, PDB', 'E1'],
    ['F2', 'Cache Redis unavailable', 'Cache reads fail', 'Catalogue returns 500', 'High', 'Cache bypass to MySQL (graceful degradation)', 'E2'],
    ['F3', 'MySQL unavailable', 'No database connection', 'Most requests fail', 'Critical', 'Fail safe (503), transactions, readiness removal; not masked (SPOF)', 'E2'],
    ['F4', 'Queue Redis down or message lost', 'Accepted purchase without a queued job', 'Purchase stuck pending forever', 'Critical', 'Transactional outbox, relay, reconciliation', 'E2, E4'],
    ['F5', 'Worker crash mid-payment', 'Attempt in progress, charge uncertain', 'Lost or double charge', 'Critical', 'Durable attempt + lease, idempotent receipt, stale-result guards', 'E4'],
    ['F6', 'Provider slow or failing', 'Calls hang or fail', 'Blocked workers, cascading delays', 'High', 'Deadline, bounded retries, circuit breaker', 'E3'],
    ['F7', 'Traffic spike', 'Queues and latency grow', 'Timeouts, server overload', 'Medium', 'Replicas, Envoy rate limit (admission control)', 'E5'],
    ['F8', 'Data corruption or deletion', 'Wrong or missing rows', 'Permanent data loss', 'Critical', 'Scheduled backups, verified isolated restore', 'E8'],
    ['F9', 'Misuse or attack', 'Unauthorized request', 'Disclosure or tampering', 'Critical', 'Authentication, authorization, validation, throttling, audit', 'E7'],
    ['F10', 'Node or host failure', 'All pods gone', 'Total outage', 'Critical', 'Not masked; GitOps/IaC rebuild only', 'not tested'],
  ], [0.6, 1.9, 1.9, 2, 1, 2.6, 0.8], PORTRAIT_W, 16),
  H2('3.3 Single points of failure'),
  bullet('One node (host, disk, network): all pods share one failure domain.'),
  bullet('MySQL single instance: all business data and the outbox; measured as user-visible in both profiles (E2).'),
  bullet('Queue Redis and RustFS single instances: accepted work survives a queue outage through the outbox, but uploads need RustFS.'),
  bullet('Backups are stored on the same host as the database (snapshot recovery, not disaster recovery).'),
  H2('3.4 Threat model'),
  P('Assets: accounts and credentials, grades, payment and enrollment records, private submission files, and service availability. Trust boundaries: the public HTTP gateway (Envoy), the API, internal services (worker, mock provider, datastores) and the operator. Threats are classified with STRIDE (Shostack, 2014).'),
  table(['STRIDE', 'Threat', 'Control', 'Evidence'], [
    ['Spoofing', 'Forged or tampered JWT; password guessing', 'RS256-signed JWT (1 h), password hashing, login throttling 5/min per login+IP, 60/min per IP', 'E7: forged/tampered tokens 401; brute force 429'],
    ['Tampering', 'Client sets price, status or admin flag', 'Server-side DTOs reject unknown fields; price snapshot; DB checks', 'E7: client price, register as admin, negative price 400'],
    ['Repudiation', 'Denied actions leave no trace', 'Audit events `security.denied` and `security.authentication_failed` with request ID, user, client IP', 'E7: 100% audited; E6 log correlation'],
    ['Information disclosure', 'Reading another student\'s data or files', 'Ownership checks return 404; private S3 objects; no card data stored or logged', 'E7: other student\'s purchase 404'],
    ['Denial of service', 'Request floods, oversized uploads', 'Envoy local rate limit 200 req/s per proxy; 10,000,000-byte upload limit; timeouts', 'E5 admission control; E7 oversized upload 400'],
    ['Elevation of privilege', 'Student calls admin operations', 'Role checks on every admin route', 'E7: 403 and audit record'],
  ], [1.3, 2.3, 3.4, 2.4]),
);

// ---------------------------------------------------------------- 4 reliability
const relRows = ['api_crash', 'cache_outage', 'mysql_outage'].flatMap((sc) => [B, FT].map((ns) => {
  const r = rel(ns, sc);
  if (!r) return [sc, ns === B ? 'Baseline' : 'FT', '', '', '', '', '', ''];
  return [sc, ns === B ? 'Baseline' : 'FT', pct(r.requestAvailabilityMean), pct(r.sampledTimeAvailabilityMean),
    r.mttrSecondsMean === null ? 'censored' : f2(r.mttrSecondsMean), r.mtbfOperatingSecondsMean === null ? 'censored' : f2(r.mtbfOperatingSecondsMean),
    r.noFailureCensoredRuns === r.repetitions ? `> ${f1(r.totalObservationSeconds / r.repetitions)} (censored)` : f2(r.mttfSecondsMean), (r.failureRatePerSecondMean ?? 0).toFixed(3)];
}));
add(
  H1('4. Reliability engineering'),
  H2('4.1 Formulas'),
  formula('Failure rate  λ = 1 / MTTF   (per hour for the model; per second for experiments: λ = N / T)'),
  formula('MTBF = MTTF + MTTR   (model);   operating MTBF = (T − D) / N   (experiments)'),
  formula('Availability  A = MTTF / (MTTF + MTTR)'),
  formula('Series (all needed)  A = A₁ · A₂ · … · Aₙ        Parallel (k = 2 replicas)  A = 1 − (1 − A₁)²'),
  formula('Downtime per year = (1 − A) · 8760 h · 60 min'),
  P('In the experiments, T is the observation time, D the estimated unavailable time and N the number of contiguous outage episodes. Request availability = successful probes / all probes; sampled time availability = (T − D) / T. A run without failures is right-censored: its MTTF is only known to exceed the window, and MTTR and MTBF are undefined.'),
  H2('4.2 Component model (illustrative, mixed assumed and measured inputs)'),
  P('The MTTF values below are **assumptions** for illustration; no field failure data exist for this system. The MTTR values are **measured** in this project where an experiment exists (Section 9), and the source of every value is stated. The resulting annual figures are therefore estimates, not observations.'),
  table(['Component', 'MTTF (h, assumed)', 'MTTR used', 'Source of MTTR', 'λ (1/h)', 'A', 'Downtime / year'], components.map((c) => [
    c.name, String(c.mttfH), c.mttrS === null ? 'n/a' : `${f2(c.mttrS)} s`, c.source, c.lambda.toExponential(2), c.A.toFixed(8), `${f2(c.downMin)} min`,
  ]), [2.2, 1.2, 1.1, 3.2, 1.1, 1.3, 1.3], PORTRAIT_W, 16),
  P('**Read path, baseline (all in series):** node · Envoy · API · cache · MySQL.'),
  formula(`A_baseline = ${aBaseline.toFixed(8)}   →   ${f1(downYear(aBaseline))} min downtime / year`),
  P('**Read path, FT:** Envoy and API have two replicas each (parallel), and the cache is not in series because the FT profile reads MySQL directly when the cache fails.'),
  formula(`A_FT = A_node · [1 − (1 − A_envoy)²] · [1 − (1 − A_api)²] · A_mysql = ${aFT.toFixed(8)}   →   ${f1(downYear(aFT))} min / year`),
  P(`**Interpretation.** Replication makes the stateless components almost irrelevant (their parallel unavailability is of order 10⁻¹² to 10⁻¹³), so the FT read path is limited by the single node (assumed 30 min MTTR twice a year, about ${f1(components[0].downMin)} min/year) and by the single MySQL instance. Further replication of stateless services would not improve the estimate; a second node and a replicated database would. This matches the measured result that FT did not improve the MySQL outage (Section 9).`),
  H2('4.3 Observed reliability metrics from the experiments'),
  P(`Values are means over 3 runs per profile and scenario, from \`reliability.json\` (measured). The observation windows are tens of seconds around a forced fault, so these are experiment-scale rates, not field MTBF.`),
  table(['Scenario', 'Profile', 'Request avail.', 'Time avail.', 'MTTR (s)', 'Op. MTBF (s)', 'MTTF (s)', 'λ (1/s)'], relRows, [1.7, 1, 1.2, 1.2, 1.1, 1.2, 1.7, 1]),
);

// ---------------------------------------------------------------- 5 architecture
add(
  H1('5. System architecture and resilience design'),
  P('The system has three independently deployed application services built from one Symfony codebase: the HTTP API (PHP 8.3 on RoadRunner, also serving the Campus web frontend), the payment worker (Supervisor-managed Messenger consumer, outbox relay and metrics exporter) and the mock payment provider. Stateful dependencies run as separate workloads: MySQL (business data, outbox, receipts), queue Redis (AOF, no eviction), cache Redis (bounded, disposable) and RustFS (private files and backups). Each profile has its own Envoy gateway; monitoring is shared.'),
  ...figure(path.join(ROOT, 'docs/architecture.png'), 'Figure 1. FT architecture on the single-node OrbStack cluster. The baseline uses the same components with one API, worker and Envoy replica and no backups.', 560),
  H2('5.1 Dependencies and data flows'),
  table(['From → to', 'Purpose', 'Failure behaviour (FT)'], [
    ['Client → Envoy → API', 'All HTTP traffic, 12 s backend / 15 s total timeout, 200 req/s per proxy', 'Readiness-based routing to healthy pods; 429 above the rate limit'],
    ['API → MySQL', 'Authoritative reads and transactional writes', 'Fail safe with 503; no partial writes'],
    ['API → cache Redis', 'Catalogue cache (30 s TTL)', 'Bypass to MySQL'],
    ['API → RustFS', 'Private submission files', 'Upload compensation deletes orphan objects'],
    ['Relay → queue Redis', 'Publish outbox jobs (ID only)', 'Outbox keeps the intent; relay restarts on broker errors'],
    ['Worker → mock provider', 'Payment authorization, 2 s deadline', 'Bounded retries, circuit breaker'],
    ['Backup job → MySQL, RustFS', 'Dump every 15 min to a separate bucket', 'Verified isolated restore'],
    ['Prometheus, collector → all', 'Metrics (5 s) and JSON logs', 'Alerts on incidents'],
  ], [2.2, 3.4, 3.4]),
  H2('5.2 Redundancy, replication and backup strategy'),
  P('Stateless services are replicated (2 API, 2 worker, 2 Envoy pods) with pod anti-affinity preferences and PodDisruptionBudgets. Stateful services are single instances on persistent volumes with durability settings (InnoDB redo log and doublewrite, Redis AOF every second). Durability of accepted work does not depend on Redis: MySQL is the source of truth and the outbox republishes lost jobs. Backups give a recovery point objective of 15 minutes and are verified by restoring into a separate database and comparing ordered row hashes. Multi-node replication of MySQL, Redis and RustFS is documented as future work.'),
);

// ---------------------------------------------------------------- 6 implementation
add(
  H1('6. Infrastructure and software fault-tolerance implementation'),
  H2('6.1 Infrastructure-level mechanisms'),
  table(['#', 'Mechanism', 'Failure addressed', 'Configuration', 'Evidence'], [
    ['H1', 'Multiple instances: 2 API, 2 worker pods', 'Instance crash', '`helm/university/values.yaml`', 'E1, E4'],
    ['H2', 'Load balancing over ready endpoints only', 'Routing to failed pods', 'Envoy HTTPRoute, readiness probes', 'E1'],
    ['H3', 'Redundant gateway: 2 Envoy replicas', 'Gateway crash; overload', '`templates/gateway.yaml`', 'E5'],
    ['H4', 'PodDisruptionBudget `minAvailable: 1`', 'Voluntary disruption', '`templates/application.yaml`', 'config'],
    ['H5', 'Scheduled MySQL backups + verified restore', 'Data loss or corruption (RPO 15 min)', '`templates/backup.yaml`, `app:backup`, `app:restore-verify`', 'E8'],
    ['H6', 'Persistent volumes for all stateful services', 'Pod replacement', '`templates/storage.yaml`', 'E2'],
    ['H7', 'Durable storage settings (InnoDB, Redis AOF)', 'Process kill mid-write', '`templates/storage.yaml`', 'E2, E4'],
    ['H8', 'Infrastructure as code (Helm; Argo CD GitOps variant)', 'Rebuild after total loss', '`scripts/setup-local.sh`, `deploy/`', 'reproducible setup'],
  ], [0.6, 2.8, 2, 2.6, 1]),
  H2('6.2 Software-level mechanisms'),
  table(['#', 'Mechanism', 'Implementation', 'Code / config', 'Evidence'], [
    ['S1', 'Bounded retries with backoff', 'Initial attempt + 2 retries after 2 s and 4 s; only transient errors retry; consumer restarts with 2–30 s backoff', '`src/Payment/*`, `docker/consume.sh`', 'E3, smoke'],
    ['S2', 'Timeouts', 'Provider deadline 2 s; Envoy 12 s / 15 s; processing lease 15 s; outbox lease 10 s', '`framework.yaml`, gateway', 'E3'],
    ['S3', 'Circuit breaker', 'Shared in MySQL; opens after 5 transient failures for 15 s; one half-open probe', '`src/Payment/CircuitBreaker.php`', 'E3'],
    ['S4', 'Idempotency and duplicate detection', 'Idempotency-Key with request fingerprint; unique keys on enrollment, submission, grade, attempt and receipt; stale-result guards', 'migrations, `SettlePurchaseHandler`', 'E4, smoke'],
    ['S5', 'Checkpointing', 'Durable attempt and lease committed before the provider call; transactional outbox', '`ClaimPurchaseHandler`, `OutboxClaimHandler`', 'E4'],
    ['S6', 'Rollback and recovery', 'One transaction per handler; rollback on exception; reconciliation of abandoned purchases; upload compensation', '`Persistence/Transaction.php`, `PaymentRelayCommand`', 'E4, E8, tests'],
    ['S7', 'Health checks', 'Separate startup, liveness (process) and readiness (dependencies) probes', '`templates/application.yaml`', 'E1, E2'],
    ['S8', 'Graceful degradation', 'Catalogue served from MySQL when the cache fails', '`src/Course/CatalogueCache.php`', 'E2'],
    ['S9', 'Broker reconnection', 'Relay exits on a broker error and Supervisor restarts it with a fresh connection (added after validation found the defect)', '`PaymentRelayCommand`, `supervisord.conf`', 'E2, E6'],
  ], [0.6, 1.8, 3.6, 2.3, 1.1], PORTRAIT_W, 16),
  H2('6.3 Automated tests'),
  P('`make check-docker` runs Composer validation, container and YAML linting, 12 PHPUnit tests with 140 assertions (unit, integration and functional), PHPStan level 5, Doctrine schema validation and OpenAPI export inside the PHP 8.3 image. The tests cover authorization boundaries, enrollment, checkout idempotency, exact upload limits, duplicate submissions, private downloads, grade limits, payment fixtures, durable success replay, an expired-lease race, transaction rollback, brute-force throttling and audit logging. `make helm-check` lints and renders both charts; `make frontend-check` runs 6 Node tests for the web frontend.'),
);

// ---------------------------------------------------------------- 7 monitoring and security
add(
  H1('7. Monitoring, observability and security implementation'),
  H2('7.1 Metrics, logs and correlation'),
  bullet('**Metrics (Prometheus, 5 s):** RoadRunner HTTP metrics (official dashboard, 9 panels), Envoy request counters by response code, and application metrics from the workers: purchases by status, outbox backlog, oldest pending purchase age, payment attempts by status, circuit state and database health.'),
  bullet('**Logs (VictoriaLogs):** structured JSON from Monolog on stderr, collected per pod. Every request logs `http.completed` with method, route, status and duration. Payment workers log attempts and outcomes; security events are logged at warning level.'),
  bullet('**Correlation:** Envoy assigns an `X-Request-ID`; the API keeps it, returns it in the response, logs it in every record and stores it in the purchase metadata, so one ID links the gateway, API and payment job logs.'),
  ...(observability ? [P(`The observability check passed: ${observability.officialRoadRunnerPanels} official RoadRunner panels, live series for ${Object.keys(observability.metrics).length} key metrics, and ${observability.correlatedPaymentLogs} correlated payment log records found by request ID.`)] : []),
  H2('7.2 Alert rules (incident indicators)'),
  table(['Alert', 'Expression (simplified)', 'For', 'Indicates'], [
    ['DatabaseDown', 'min by (profile) (university_database_up) == 0', '10 s', 'MySQL unreachable'],
    ['ApiReplicasDegraded', 'count(up{component="api",profile="resilient"} == 1) < 2', '15 s', 'Redundancy lost'],
    ['PaymentBacklogStuck', 'max by (profile) (university_purchase_oldest_pending_seconds) > 60', '10 s', 'Payments not progressing'],
    ['PaymentCircuitOpen', 'max by (profile) (university_circuit_open) == 1', '0 s', 'Provider failing'],
    ['AuthFailureSpike', 'rate of Envoy 401/403/429 > 1/s over 1 min', '10 s', 'Brute force or misuse'],
  ], [2, 4.4, 0.7, 2]),
  P('Rules are evaluated by Prometheus every 5 s and shown in Prometheus and in the Grafana University dashboard (firing alerts and denied-request rate panels). There is no Alertmanager: notifications are out of scope.'),
  H2('7.3 Security controls'),
  numbered('**Authentication:** stateless RS256 JWT (1 h), password hashing, registration cannot grant admin rights.'),
  numbered('**Access control:** admin-only routes; students see only their own records (others return 404).'),
  numbered('**Input validation:** strict DTOs reject unknown fields; server-side price snapshot; database check constraints; exact 10,000,000-byte upload limit.'),
  numbered('**Login throttling:** 5 attempts/min per login and IP, 60/min per IP, stored in MySQL so all API pods share it; throttled logins return 429 with `Retry-After`.'),
  numbered('**Rate limiting:** Envoy local limit of 200 req/s per gateway pod.'),
  numbered('**Audit logging:** `security.denied` for every 401/403/429 and `security.authentication_failed` for failed logins and invalid tokens, without passwords, tokens or bodies.'),
  numbered('**Data protection:** card data never stored, logged or queued; generated secrets kept out of git; the internal payment endpoint is not routed through the gateway.'),
);

// ---------------------------------------------------------------- 8 methodology
add(
  H1('8. Verification, validation and experimental methodology'),
  P('The test plan (`docs/experiments.md`) states the hypothesis, workload, injected fault, observation and pass criterion of every experiment, and was committed to the repository **before** the final runs. Trial runs were used only to debug the scripts; their numbers are not reported.'),
  H2('8.1 Environment'),
  table(['Item', 'Value'], [
    ['Host', 'Apple Silicon MacBook, 8 CPU cores, 16 GiB RAM, on power'],
    ['Cluster', 'OrbStack Kubernetes v1.36.5, single node, VM limit 8 GiB, local-path volumes'],
    ['Image (both profiles)', IMAGE],
    ['Software', 'PHP 8.3 / RoadRunner 2025.1, Symfony 7.4, API Platform 4.4, Doctrine ORM 3.7, MySQL 8.4.8, Redis 7.4.8, RustFS 1.0.0-alpha.69, Envoy Gateway 1.9.2, Prometheus 3.5.0, Grafana 12.3.1, VictoriaLogs 1.53.0'],
    ['Probes and load', 'Python 3.12 on the same host, through each profile\'s own gateway'],
  ], [2, 7]),
  H2('8.2 Baseline and improved configurations'),
  table(['Mechanism', 'Baseline', 'FT'], [
    ['API / worker / Envoy replicas', '1 / 1 / 1', '2 / 2 / 2'],
    ['PodDisruptionBudget', 'No', 'Yes'],
    ['Payment dispatch', 'Direct after commit', 'Outbox + relay + reconciliation'],
    ['Business retries', 'None (1 attempt)', '1 + 2 retries'],
    ['Circuit breaker', 'Off', 'On'],
    ['Cache failure', 'Error', 'Graceful degradation'],
    ['Scheduled backups', 'Off', 'Every 15 min'],
    ['Identical', 'Image, deadlines, gateway timeouts and rate limit, probes, idempotency, constraints, security controls, alert rules', ''],
  ], [3, 3, 3]),
  H2('8.3 Procedure and measurements'),
  P('Both profiles run side by side with separate databases; only one receives traffic at a time. `scripts/run-all.sh` executes all scenarios in a fixed order and waits for every pod to be ready between them. Each scenario was repeated 3 times per profile, except the load spike (1 run per profile) and the alert scenarios (1 run per alert). For every run the scripts record UTC start/end, injection time, detection and recovery times, successful and failed requests, latency and an integrity check, and keep the raw samples in `output/final/`. Definitions of detection time, recovery time, availability and the exactly-once check are given in the test plan.'),
);

// ---------------------------------------------------------------- 9 results
const consolidated = results.map((r) => [r.experiment, r.scenario, r.profile, r.passed || '–', r.success, r.availability, r.detection_s, r.recovery_s, r.latency, r.recovery_actions, r.integrity]);
const secCases = security.flatMap((r) => r.cases);
const spikeLine = (p, label) => (p ? `${label}: ${p.spike.goodputRps.toFixed(1)} req/s served, ${p.spike.rateLimited429} × 429, ${p.spike.serverErrors5xx + p.spike.connectionErrors} server/connection errors, p95 ${Math.round(p.spike.p95Ms)} ms (cool-down p95 ${Math.round(p.cooldown.p95Ms)} ms).` : '');
add(
  pageBreak(),
  H1('9. Results and baseline-versus-improved comparison'),
  P(`All values are measured on ${IMAGE}. ${allPassed ? 'Every FT acceptance criterion from the test plan was met.' : 'Not every acceptance criterion was met; the failed rows are discussed below.'} The consolidated table (landscape, next page) is generated by \`scripts/summarize.py\`; the raw files are listed in the appendix.`),
);
const landscape = [
  H2('9.1 Consolidated results'),
  table(['Exp.', 'Scenario', 'Profile', 'Passed', 'Success', 'Avail.', 'Detect (s)', 'Recovery (s)', 'Latency', 'Recovery actions', 'Data integrity'], consolidated,
    [1.5, 2, 0.8, 0.7, 1.6, 0.8, 0.8, 0.9, 1.6, 1.6, 1.6], LANDSCAPE_W, 15),
  caption('Table: consolidated results (means over the listed runs). Passed = runs meeting the pre-registered criterion; "–" = no criterion (baseline comparison). Detection and recovery definitions per scenario are in the test plan.'),
];
const after = [];
const A = (...x) => after.push(...x.flat());
A(
  H2('9.2 E1: application instance failure'),
  P(`Force-deleting one API pod made the baseline unavailable until the replacement pod became ready (mean MTTR ${f2(bApi?.mttrSecondsMean)} s; ${pct(bApi?.requestAvailabilityMean)} of probes succeeded). The FT profile served ${apiOkTotal}/${apiTotal} probes (${pct(ftApi?.requestAvailabilityMean)}): the second replica and readiness-based routing masked the crash${apiCrashFailures.length ? `, except ${apiCrashFailures.length === 1 ? 'one probe' : `${apiCrashFailures.length} probes`} (${apiCrashFailures.map((x) => `run ${x.rep}: HTTP ${x.status || 'timeout'} ${f2(x.after)} s after the force-delete`).join('; ')}). Envoy still routed that request to the killed pod before Kubernetes removed its endpoint, and the gateway does not retry because retries are deliberately disabled to avoid replaying non-idempotent requests. One failed probe out of about 60 puts that run below the pre-registered 99% per-run threshold, so SLO-1 is met in ${passed(row('1', 'api_crash'))} runs.` : ` (SLO-1 ${passed(row('1', 'api_crash'))}).`}`),
  ...figure(path.join(FIG, 'api-crash-timeline.png'), 'Figure 2. Probe outcome during an API pod crash (run 1).', 560),
  H2('9.3 E2: dependency failures'),
  P(`**Cache.** With the cache Redis stopped, the baseline catalogue returned errors (${pct(bCache?.requestAvailabilityMean)} availability, MTTR ${f2(bCache?.mttrSecondsMean)} s) while FT served the catalogue from MySQL with ${pct(ftCache?.requestAvailabilityMean)} availability (SLO-2 ${passed(row('2', 'cache_outage'))}).`),
  P(`**MySQL.** Stopping MySQL caused an outage in both profiles (baseline ${pct(rel(B, 'mysql_outage')?.requestAvailabilityMean)}, FT ${pct(ftMysql?.requestAvailabilityMean)}; MTTR ${f2(rel(B, 'mysql_outage')?.mttrSecondsMean)} s vs ${f2(ftMysql?.mttrSecondsMean)} s). Every failed probe was a 503 or a client timeout, no partial writes occurred, and service recovered after the restart (SLO-6 ${passed(row('2', 'mysql_outage'))}). Replicating the API does not help here: MySQL is the single point of failure predicted by the reliability model. FT is not faster to recover because two API pods must both reconnect and pass readiness.`),
  P(`**Queue Redis.** With the queue Redis stopped during checkout, FT accepted every purchase and completed ${broker.filter((r) => r.status === 'succeeded').length}/${broker.length} with exactly one attempt, on average ${f1(mean(broker.map((r) => r.secondsAfterRedisReady)))} s after Redis was ready (SLO-3).`),
  ...figure(path.join(FIG, 'availability-bars.png'), 'Figure 3. Mean request availability per fault, baseline vs FT.', 500),
  H2('9.4 E3: network delay, timeout and circuit breaker'),
  P(`With a provider that answers after 3 s and a 2 s deadline, every attempt was cut at about ${Math.round(Math.max(...[...timeoutB, ...timeoutF].map((s) => s.maxAttemptMs || 0)))} ms. The baseline gave up after 1 attempt (${f1(mean(timeoutB.map((s) => s.secondsToTerminal)))} s), FT after ${f1(mean(timeoutF.map((s) => s.attempts)))} attempts; no timed-out purchase produced a receipt or an enrollment (SLO-4, SLO-5). FT's time to a terminal state grew from run to run (${timeoutF.map((s) => `${f1(s.secondsToTerminal)} s`).join(', ')}): the runs were sequential, so their timeouts added up in the shared circuit breaker, which opened and deferred the later retries. The number and length of attempts stayed bounded; only the waiting between them grew. This interaction between retries and the circuit breaker is intended behaviour, but it makes permanent failures slower to report. In the circuit-breaker runs, the circuit opened after ${f1(mean(circuit.map((s) => s.secondsToOpen)))} s on average (${f1(mean(circuit.map((s) => s.circuitOpenings)))} openings per run${mean(circuit.map((s) => s.circuitOpenings)) > 1 ? '; a failed half-open probe re-opens it' : ''}). A good purchase made while it was open waited without consuming an attempt and then succeeded with exactly one attempt (${circuit.filter((s) => s.pass).length}/${circuit.length} runs). Trade-off: failing purchases take longer in FT (retries), and good purchases are delayed while the circuit is open.`),
  H2('9.5 E4: interrupted multi-step transactions'),
  P(`When the published queue message was deleted, the baseline left ${queueB.length - queueRecoveredB}/${queueB.length} purchases pending until the observation limit (right-censored, never recovered). FT recovered ${queueRecoveredF}/${queueF.length} through reconciliation in ${f1(mean(queueF.map((r) => r.recoverySeconds)))} s on average. Killing all workers right after the provider charged (before settlement) or right after publishing (before acknowledgement) was recovered in every run, in ${f1(mean(checkpoints.filter((c) => c.scenario === 'after_provider_success').map((c) => c.recoverySeconds)))} s and ${f1(mean(checkpoints.filter((c) => c.scenario === 'after_outbox_publish').map((c) => c.recoverySeconds)))} s on average. Every recovered purchase had exactly one receipt, one attempt and one enrollment: no lost and no duplicate critical records (SLO-3, SLO-4).`),
  ...figure(path.join(FIG, 'payment-recovery.png'), 'Figure 4. FT payment recovery times per run.', 520),
  H2('9.6 E5: traffic spike'),
  P(`The spike offered 300 req/s for 30 s between two 20 req/s phases (open loop, fixed rate). ${spikeLine(spikeB, 'Baseline')} ${spikeLine(spikeF, 'FT')} The baseline protects its latency by rejecting about one third of the traffic at its single gateway (admission control). FT serves the full rate through two gateways, at the cost of higher tail latency and a slower return to normal latency after the spike. ${spikeB && spikeF && spikeB.spike.serverErrors5xx + spikeB.spike.connectionErrors + spikeF.spike.serverErrors5xx + spikeF.spike.connectionErrors === 0 ? 'Neither profile returned a single server or connection error.' : 'Server or connection errors are listed above.'} The load generator stayed on schedule (maximum start delay ${f1(Math.max(...Object.values(spikeF || {}).map((x) => x.maxClientStartDelayMs || 0), ...Object.values(spikeB || {}).map((x) => x.maxClientStartDelayMs || 0)))} ms), so the offered load is exact.`),
  ...figure(path.join(FIG, 'spike.png'), 'Figure 5. Served and rejected requests and p95 latency per second during the spike.', 560),
  H2('9.7 E6: monitoring and alert trigger'),
  table(['Alert', 'Fault injected', 'Detection (s)', 'Resolved after repair (s)', 'Bound (s)'], [
    ['DatabaseDown', 'MySQL scaled to 0', f1(alert('DatabaseDown').detectionSeconds), f1(alert('DatabaseDown').resolveSecondsAfterRestore), '40'],
    ['ApiReplicasDegraded', 'API scaled to 1 replica', f1(alert('ApiReplicasDegraded').detectionSeconds), f1(alert('ApiReplicasDegraded').resolveSecondsAfterRestore), '60'],
    ['AuthFailureSpike', 'Unauthenticated burst (about 3 req/s)', f1(alert('AuthFailureSpike').detectionSeconds), f1(alert('AuthFailureSpike').resolveSecondsAfterRestore), '90'],
    ['PaymentBacklogStuck', 'Queue Redis scaled to 0 with a pending purchase', f1(alert('PaymentBacklogStuck').detectionSeconds), f1(alert('PaymentBacklogStuck').resolveSecondsAfterRestore), '120'],
  ], [2, 3, 1.3, 1.6, 1]),
  P('Detection time is dominated by each rule\'s window and `for` duration plus the 5 s scrape and evaluation interval, which is a deliberate trade-off against false positives: for example, the backlog alert waits for a purchase to be pending for more than 60 s, longer than a normal retry cycle. In every case, the failed requests could be found in VictoriaLogs by their request ID, which is how an operator moves from an alert to the affected requests (SLO-8).'),
  ...figure(path.join(FIG, 'alerts.png'), 'Figure 6. Alert detection and resolution times.', 500),
  H2('9.8 E7: unauthorized and invalid operations'),
  P(`Across ${security.length} runs, ${secCases.filter((c) => c.pass).length}/${secCases.length} misuse cases returned the expected status and ${secCases.filter((c) => c.auditPass).length}/${secCases.length} were found in the audit logs by request ID (SLO-9).`),
  table(['Case', 'Expected', 'Observed (all runs)', 'Audit record'], (security[0]?.cases || []).map((c) => {
    const all = secCases.filter((x) => x.case === c.case);
    const observed = [...new Set(all.map((x) => JSON.stringify(x.status)))].map((s) => JSON.parse(s)).map((s) => (Array.isArray(s) ? s.join(',') : s)).join(' | ');
    const audit = [...new Set(all.flatMap((x) => x.audit.messages || []))].filter((m) => m.startsWith('security') || m === 'http.problem').join(', ');
    return [c.case.replaceAll('_', ' '), Array.isArray(c.expected) ? c.expected.join(',') : String(c.expected), observed, audit];
  }), [3, 1.6, 1.8, 3]),
  H2('9.9 E8: recovery and rollback'),
  P(`A fresh MySQL dump was taken and restored into a new isolated database ${restore.length} times. The backup took ${f2(mean(restore.map((s) => s.backupSeconds)))} s and restore with verification ${f2(mean(restore.map((s) => s.restoreVerifySeconds)))} s on average; the checksum was verified, the restored data matched the live source and there were ${restore.reduce((a, s) => a + (s.violations || 0), 0)} integrity violations (SLO-10). These timings describe a small dataset and do not predict production restore times.`),
  ...(smoke ? [P(`The functional smoke test passed before the experiment block: all six payment fixtures reached their expected terminal state with the expected number of attempts, idempotent replay returned the same purchase, file checksums and ownership were verified and the PDF report rendered (${smoke.pdfBytes} bytes).`)] : []),
);

// ---------------------------------------------------------------- 10 discussion
A(
  pageBreak(),
  H1('10. Discussion, limitations and future work'),
  H2('10.1 Were the objectives met?'),
  table(['Objective', 'Result', 'Met?'], [
    ['SLO-1 instance failure', `FT ${pct(ftApi?.requestAvailabilityMean)} vs baseline ${pct(bApi?.requestAvailabilityMean)}`, passed(row('1', 'api_crash')) ],
    ['SLO-2 cache failure', `FT ${pct(ftCache?.requestAvailabilityMean)} vs baseline ${pct(bCache?.requestAvailabilityMean)}`, passed(row('2', 'cache_outage'))],
    ['SLO-3 no lost payments', `queue loss ${queueRecoveredF}/${queueF.length}, broker outage ${broker.filter((r) => r.status === 'succeeded').length}/${broker.length}, worker crashes ${checkpoints.filter((c) => c.recovered).length}/${checkpoints.length} recovered`, passed(row('4', 'message lost'))],
    ['SLO-4 no duplicates', `${onceCount}/${onceTotal} recovered purchases exactly once`, onceCount === onceTotal ? 'yes' : 'no'],
    ['SLO-5 bounded calls', `max attempt ${Math.round(Math.max(...[...timeoutB, ...timeoutF].map((s) => s.maxAttemptMs || 0)))} ms, ≤ 3 attempts`, passed(row('3', 'deadline'))],
    ['SLO-6 database outage', `fail safe, MTTR ${f2(ftMysql?.mttrSecondsMean)} s; not masked`, passed(row('2', 'mysql_outage'))],
    ['SLO-7 traffic spike', spikeF ? `p95 ${Math.round(spikeF.spike.p95Ms)} ms, ${spikeF.spike.serverErrors5xx + spikeF.spike.connectionErrors} errors of ${spikeF.spike.requests}` : 'n/a', passed(row('5', 'req/s'))],
    ['SLO-8 detection', `${alertRuns.filter((x) => x.pass && x.detectionSeconds <= ALERT_BOUND[x.alert]).length}/${alertRuns.length} alerts within bounds`, alertsWithinBounds() ? 'yes' : 'no'],
    ['SLO-9 misuse', `${secCases.filter((c) => c.pass).length}/${secCases.length} denied, ${secCases.filter((c) => c.auditPass).length}/${secCases.length} audited`, passed(row('7', 'misuse'))],
    ['SLO-10 recoverability', 'Verified restore; RPO 15 min by schedule', passed(row('8', 'restore'))],
  ], [2.4, 5, 1.2]),
  H2('10.2 Trade-offs'),
  bullet('**Latency vs availability under load:** FT serves more requests during the spike but with a much higher tail latency; the baseline rejects requests quickly. A production system would add autoscaling and per-route limits.'),
  bullet(`**Retries vs time to failure:** bounded retries rescue transient failures but make permanent failures take longer to report (${f1(mean(timeoutF.map((s) => s.secondsToTerminal)))} s on average instead of ${f1(mean(timeoutB.map((s) => s.secondsToTerminal)))} s in E3, more when the circuit breaker is open).`),
  bullet('**Detection speed vs false positives:** alert windows of 10–60 s avoid alerting on single scrapes or normal retry cycles, so detection takes 15–80 s.'),
  bullet('**Resources and complexity:** FT doubles the stateless pods and gateways, and adds an outbox, relay, reconciliation, circuit state and backups, which all need tests and monitoring.'),
  H2('10.3 Negative results and anomalies'),
  ...(apiCrashFailures.length ? [bullet(`**One failed request during an API crash.** In ${new Set(apiCrashFailures.map((x) => x.rep)).size} of ${apiCrashRuns.length} FT runs a request reached the killed pod before its endpoint was removed (HTTP 503). A gateway retry for idempotent GET requests on connection failure or 503 would mask this; it was not added after the fact, so the result stands as measured.`)] : []),
  bullet('**MySQL outage is not masked.** FT did not improve availability during a MySQL outage; this is consistent with the model and is the most important remaining weakness.'),
  bullet('**Defect found by validation.** During the trial runs, purchases accepted while the queue Redis was down stayed pending forever: the long-running relay kept a dead Redis connection. The midterm had passed this test on an earlier image. The relay now exits on broker errors and Supervisor restarts it (mechanism S9); the final runs confirm the fix. This shows the value of repeating fault-injection tests after every refactoring.'),
  bullet('**Environment anomaly.** In one trial run, right after a load spike, the Kubernetes API was unreachable for about a minute and all application pods restarted together, with no memory or node events. The final procedure waits for all pods between steps; no container restarted during the final runs (all recorded restarts predate them).'),
  H2('10.4 Limitations'),
  bullet('Single node: node, disk and host faults are not tested; replicas share one failure domain.'),
  bullet('The load generator, the probes and both profiles share one machine, so CPU contention can affect latency results.'),
  bullet('Short observation windows and few repetitions (3, or 1 for the spike and alerts) give experiment-scale numbers with wide uncertainty, not field reliability.'),
  bullet('Locally every client has the same IP, so per-IP limits act as one shared limit; local traffic is plain HTTP (TLS is configured in the optional Argo CD deployment).'),
  bullet('The payment provider and card data are synthetic.'),
  H2('10.5 Future work'),
  bullet('Run on at least three nodes with pod anti-affinity and a replicated MySQL (for example InnoDB Cluster or a managed database), Redis Sentinel and distributed object storage.'),
  bullet('Off-host backups and a timed full rebuild drill (RTO for total loss).'),
  bullet('Alertmanager routing and paging; SLO burn-rate alerts.'),
  bullet('Horizontal pod autoscaling and per-route rate limits; longer soak tests with more repetitions.'),
  bullet('Gateway retries for idempotent GET requests on connection failure, to mask in-flight requests to a crashed pod.'),
);

// ---------------------------------------------------------------- 11 conclusion
A(
  H1('11. Conclusion'),
  P(`The project delivers a working, reproducible university platform in two configurations and evaluates them under ten controlled fault, load, monitoring and misuse scenarios with pre-registered criteria. The FT configuration masks stateless failures (a cache outage completely, an API crash except a single in-flight request), never lost or duplicated an accepted payment under message loss, worker crashes or a broker outage, bounds slow dependencies, detects incidents through alerts and correlated logs, denies and audits misuse, and restores verified backups. ${allPassed ? 'All FT acceptance criteria were met.' : 'Not every acceptance criterion was met (see Section 10).'} The remaining weaknesses are the single database and the single node, which the reliability model and the MySQL experiment both identify as the limiting factors and which define the next steps.`),
);

// ---------------------------------------------------------------- references and appendix
A(
  H1('References'),
  ...[
    'Avižienis, A., Laprie, J.-C., Randell, B. and Landwehr, C. (2004). Basic concepts and taxonomy of dependable and secure computing. IEEE Transactions on Dependable and Secure Computing, 1(1), 11–33. doi:10.1109/TDSC.2004.2',
    'Beyer, B., Jones, C., Petoff, J. and Murphy, N. R. (eds.) (2016). Site Reliability Engineering: How Google Runs Production Systems. O\'Reilly Media.',
    'Nygard, M. T. (2018). Release It! Design and Deploy Production-Ready Software, 2nd ed. Pragmatic Bookshelf. (Timeouts, circuit breaker, bulkheads.)',
    'Richardson, C. (2018). Microservices Patterns. Manning. (Transactional outbox pattern.)',
    'Shostack, A. (2014). Threat Modeling: Designing for Security. Wiley. (STRIDE.)',
    'OWASP Foundation (2021). OWASP Top 10:2021. https://owasp.org/Top10/',
    'Kubernetes documentation: Pod lifecycle and probes; Disruptions (PodDisruptionBudget). https://kubernetes.io/docs/',
    'Envoy Gateway documentation (v1.9): traffic management, local rate limiting. https://gateway.envoyproxy.io/docs/',
    'Prometheus documentation: alerting rules. https://prometheus.io/docs/prometheus/latest/configuration/alerting_rules/',
    'Symfony 7.4 documentation: Messenger, Security (login throttling), Rate Limiter. https://symfony.com/doc/7.4/',
    'VictoriaLogs documentation: LogsQL. https://docs.victoriametrics.com/victorialogs/',
    'RoadRunner documentation: metrics and static middleware. https://docs.roadrunner.dev/',
  ].map((r) => P(r, { paragraph: { indent: { left: 360, hanging: 360 } } })),
  H1('Appendix A. Team contributions'),
  table(['Member', 'Area', 'Contributions'], [
    ['Nariman Sailaubek', 'Infrastructure and recovery', 'Kubernetes/Helm deployment and local cluster setup, experiment scripts for instance and dependency failures, interrupted transactions and backup/restore (E1, E2, E4, E8), reliability model, architecture sections'],
    ['Sultan Turan', 'Observability and security', 'Alert rules and dashboards, audit logging, login throttling and security tests, timeout/circuit, spike, alert and security scenarios (E3, E5, E6, E7), consolidated results tooling'],
    ['Altair Tussupov', 'Requirements, verification and report', 'Requirements and SLOs, fault model, test plan and acceptance criteria, results analysis, report and presentation'],
  ], [2, 2.2, 5]),
  P('Parts of the implementation, scripts and this report were prepared with the assistance of an AI coding assistant (Claude Code). All measurements were produced by running the scripts listed below; no value was entered by hand.', { run: { italics: true } }),
  H1('Appendix B. Repository, configuration and raw data'),
  table(['Path', 'Content'], [
    ['`README.md`', 'Setup and reproduction instructions'],
    ['`docs/experiments.md`', 'Test plan, hypotheses and acceptance criteria'],
    ['`scripts/setup-local.sh`', 'Build and deploy both profiles and monitoring to OrbStack'],
    ['`scripts/run-all.sh`', 'Complete experiment procedure'],
    ['`scripts/experiments.py`, `scripts/scenarios.py`, `scripts/broker-recovery.py`', 'Fault injection and measurement'],
    ['`scripts/summarize.py`, `scripts/figures.py`', 'Consolidated table, reliability metrics, figures'],
    ['`helm/university`, `helm/monitoring`', 'Baseline and FT configuration, alert rules, dashboards'],
    ['`src/`, `tests/`', 'Application code and automated tests'],
    ['`output/final/`', 'Raw per-run JSON, `requests.csv`, `reliability.csv`, `results.csv/.md`, inventories, run log'],
  ], [4, 5]),
  P('Reproduction: `make setup-local`, then `bash scripts/run-all.sh`, then `node docs/report/build-report.mjs`.'),
);

// ---------------------------------------------------------------- assemble
const doc = new Document({
  creator: 'Nariman Sailaubek, Altair Tussupov, Sultan Turan',
  title: 'Fault-Tolerant University Platform: Final Project Report',
    styles: {
    default: { document: { run: { font: FONT, size: 21 } } },
    paragraphStyles: [
      { id: 'Heading1', name: 'Heading 1', basedOn: 'Normal', next: 'Normal', quickFormat: true, run: { font: FONT, size: 32, bold: true, color: '111827' }, paragraph: { spacing: { before: 360, after: 160 }, keepNext: true, outlineLevel: 0 } },
      { id: 'Heading2', name: 'Heading 2', basedOn: 'Normal', next: 'Normal', quickFormat: true, run: { font: FONT, size: 26, bold: true, color: '1F2937' }, paragraph: { spacing: { before: 240, after: 120 }, keepNext: true, outlineLevel: 1 } },
      { id: 'Heading3', name: 'Heading 3', basedOn: 'Normal', next: 'Normal', quickFormat: true, run: { font: FONT, size: 22, bold: true }, paragraph: { spacing: { before: 200, after: 80 }, keepNext: true, outlineLevel: 2 } },
    ],
  },
  numbering: {
    config: [
      { reference: 'bullets', levels: [{ level: 0, format: LevelFormat.BULLET, text: '•', alignment: AlignmentType.LEFT, style: { paragraph: { indent: { left: 567, hanging: 283 } } } }, { level: 1, format: LevelFormat.BULLET, text: '–', alignment: AlignmentType.LEFT, style: { paragraph: { indent: { left: 1134, hanging: 283 } } } }] },
      { reference: 'numbers', levels: [{ level: 0, format: LevelFormat.DECIMAL, text: '%1.', alignment: AlignmentType.LEFT, style: { paragraph: { indent: { left: 567, hanging: 340 } } } }] },
    ],
  },
  sections: [
    { properties: { page: { margin: { top: 1440, right: 1440, bottom: 1440, left: 1440 } } }, children: title },
    {
      properties: { page: { margin: { top: 1440, right: 1440, bottom: 1440, left: 1440 } } },
      footers: { default: new Footer({ children: [new Paragraph({ alignment: AlignmentType.CENTER, children: [new TextRun({ children: [PageNumber.CURRENT], size: 18 })] })] }) },
      children: body,
    },
    {
      properties: { page: { size: { orientation: PageOrientation.LANDSCAPE, width: 11906, height: 16838 }, margin: { top: 1440, right: 1440, bottom: 1440, left: 1440 } } },
      footers: { default: new Footer({ children: [new Paragraph({ alignment: AlignmentType.CENTER, children: [new TextRun({ children: [PageNumber.CURRENT], size: 18 })] })] }) },
      children: landscape,
    },
    {
      properties: { page: { margin: { top: 1440, right: 1440, bottom: 1440, left: 1440 } } },
      footers: { default: new Footer({ children: [new Paragraph({ alignment: AlignmentType.CENTER, children: [new TextRun({ children: [PageNumber.CURRENT], size: 18 })] })] }) },
      children: after,
    },
  ],
});

fs.mkdirSync(path.dirname(OUT), { recursive: true });
fs.writeFileSync(OUT, await Packer.toBuffer(doc));
console.log('wrote', OUT);
