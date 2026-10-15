#!/usr/bin/env node
// DEV ONLY — end-to-end tests for the verifier module (render sandbox, probe, harness, Station, headless verifier).
//
//   WB_DB_NAME=wb_verifier php -S 127.0.0.1:8084 index.php        (dev server, same database)
//   NODE_PATH=<dir with node_modules/playwright> WB_DB_NAME=wb_verifier node tools/dev/tests/verifier/run-tests.mjs \
//        [--base http://127.0.0.1:8084] [--only access,probe,sandbox,station,node] [--shots <dir>]
//
// Phases: access (HTTP rules + headers) · probe (every check type, top-level Playwright mode) · sandbox (escape
// attempts top-level and framed) · station (real /admin/verify page against the engine API) · node (verify.mjs).
// Exit code 0 when every hard assertion passes; "soft" assertions (timing-dependent) only warn.
import { createRequire } from 'node:module';
import { execFileSync, spawn } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, '../../../..');
const argv = process.argv.slice(2);
const opt = (k, d) => { const i = argv.indexOf('--' + k); return i >= 0 ? argv[i + 1] : d; };
const BASE = opt('base', process.env.WB_TEST_BASE || 'http://127.0.0.1:8084').replace(/\/$/, '');
const ONLY = new Set(String(opt('only', 'access,probe,sandbox,station,node')).split(','));
const SHOTS = opt('shots', '');
const ENV = { ...process.env, WB_DB_NAME: process.env.WB_DB_NAME || 'wb_verifier', WB_TEST_BASE: BASE };
const BROWSER_ARGS = ['--enable-unsafe-swiftshader', '--use-angle=swiftshader', '--ignore-gpu-blocklist'];
// The Station is used in desktop Chrome/Edge, which run sandboxed frames in their own process (site isolation).
// Test it the same way: full Chromium (new headless) with site isolation, not the stripped headless shell.
const FULL_CHROME = process.env.WB_TEST_CHROME || ['/opt/pw-browsers/chromium'].find((p) => fs.existsSync(p)) || null;
const STATION_LAUNCH = { args: [...BROWSER_ARGS, '--site-per-process', '--enable-features=IsolateSandboxedIframes'], ...(FULL_CHROME ? { executablePath: FULL_CHROME } : {}) };

let failures = 0;
let warnings = 0;
const ok = (name, cond, info = '') => {
  console.log((cond ? '  PASS ' : '  FAIL ') + name + (!cond && info ? ' — ' + info : ''));
  if (!cond) failures++;
};
const soft = (name, cond, info = '') => {
  console.log((cond ? '  PASS ' : '  WARN ') + name + (!cond && info ? ' — ' + info : ''));
  if (!cond) warnings++;
};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const within = (p, ms) => Promise.race([p.then((v) => ({ ok: true, v }), (e) => ({ ok: false, e })), sleep(ms).then(() => ({ timeout: true }))]);
const php = (script, args = []) => execFileSync('php', [path.join(HERE, script), ...args], { env: ENV, cwd: ROOT, encoding: 'utf8', maxBuffer: 64 << 20 });
const setup = (queue) => JSON.parse(php('setup.php', queue ? ['--queue'] : []));
const dump = () => JSON.parse(php('dump.php'));
const byId = (list, id) => (list || []).find((c) => c.id === id) || null;

/* ---------------------------------------------------------------- expectations (shared by all modes) */
// [checkId, expectedPass, soft?]
const EXPECT = {
  'vt-good': [['loads', true], ['no_errors', true], ['nonblank', true], ['has_button', true], ['title_text', true], ['animates', true],
    ['fps', true, 'soft'], ['responsive', true], ['keyboard', true], ['click', true], ['a11y', true], ['rtl', false], ['canvas', false]],
  'vt-broken': [['loads', true], ['no_errors', false], ['nonblank', true]],
  'vt-blank': [['loads', true], ['nonblank', false], ['animates', false], ['canvas', false]],
  'vt-hang': [['loads', false], ['no_errors', false], ['nonblank', false]],
  'vt-game': [['canvas', true], ['animates', true], ['fps', true, 'soft'], ['keyboard', true], ['click', true], ['no_errors', true]],
  'vt-webgl': [['canvas', true], ['nonblank', true], ['animates', true], ['fps', true, 'soft']],
  'vt-wide': [['responsive', false], ['nonblank', true]],
  'vt-rtl': [['rtl', true], ['a11y', true], ['greeting', true], ['responsive', true]],
  'vt-sandbox': [['loads', true]],
  'vt-forge': [['nonblank', false]],
  'vt-holdout': [['nonblank', true]],
  'vt-noout': [['loads', false], ['nonblank', false]],
};
const TESTS_EXPECT = { basic: true, empty: true, negatives: true, wrong: false, timeout: false, 'after-timeout': true, approx: true, set: true, 'class-script': true, truthy: true };

function assertRun(prefix, slug, result) {
  for (const [id, want, isSoft] of EXPECT[slug] || []) {
    const c = byId(result.checks, id);
    (isSoft ? soft : ok)(`${prefix} ${slug}: ${id} ${want ? 'passes' : 'fails'}`, !!c && c.pass === want, c ? `${c.pass} (${c.detail})` : 'missing');
  }
  if (slug === 'vt-js') {
    for (const [name, want] of Object.entries(TESTS_EXPECT)) {
      const t = (result.tests || []).find((x) => x.name === name);
      ok(`${prefix} vt-js: test "${name}" ${want ? 'passes' : 'fails'}`, !!t && t.pass === want, t ? JSON.stringify(t) : 'missing');
    }
    const to = (result.tests || []).find((x) => x.name === 'timeout');
    ok(`${prefix} vt-js: infinite loop reported as timeout`, !!to && /timeout/.test(to.error || ''), JSON.stringify(to));
    const wr = (result.tests || []).find((x) => x.name === 'wrong');
    ok(`${prefix} vt-js: wrong answer carries got`, !!wr && /\[7\]/.test(typeof wr.got === 'string' ? wr.got : JSON.stringify(wr.got)), JSON.stringify(wr));
  }
}

/* ---------------------------------------------------------------- phase: access rules */
async function phaseAccess() {
  console.log('\n== access: render route rules + headers');
  const S = setup(false);
  const get = async (u, init) => { const r = await fetch(u, init); return { status: r.status, headers: r.headers, body: await r.text() }; };
  const good = S.runs['vt-good'];
  const pub = await get(good.url);
  const csp = pub.headers.get('content-security-policy') || '';
  ok('public run renders (200)', pub.status === 200, String(pub.status));
  ok('CSP sandbox allow-scripts (no allow-same-origin)', /sandbox allow-scripts allow-pointer-lock;/.test(csp) && !/allow-same-origin/.test(csp), csp.slice(0, 80));
  ok('connect-src does not include the site itself', !/connect-src[^;]*127\.0\.0\.1/.test(csp));
  ok('frame-ancestors self, no X-Frame-Options', /frame-ancestors 'self'/.test(csp) && !pub.headers.get('x-frame-options'));
  ok('no probe without verify', !pub.body.includes('__WB_PROBE_CFG'));
  ok('html served verbatim', pub.body === fs.readFileSync(path.join(HERE, 'pages/good.html'), 'utf8'));
  ok('verify=1 without vt → 403', (await get(good.url + '?verify=1')).status === 403);
  ok('verify=1 with forged vt → 403', (await get(good.url + '?verify=1&vt=' + good.vt.replace(/.$/, (c) => (c === 'A' ? 'B' : 'A')))).status === 403);
  ok('verify=1 with another run\'s vt → 403', (await get(good.url + '?verify=1&vt=' + S.runs['vt-broken'].vt)).status === 403);
  const v = await get(good.verify_url);
  ok('verify=1 with valid vt → 200 + probe first in <head>', v.status === 200 && v.body.startsWith('<!doctype html>\n<html lang="en">\n<head><script data-wb-probe>window.__WB_PROBE_CFG='), v.body.slice(0, 120));
  ok('verify mode: no-store + probe.js + html2canvas (station)', /no-store/.test(v.headers.get('cache-control') || '') && v.body.includes('/assets/js/probe.js') && v.body.includes('html2canvas.min.js'));
  ok('playwright mode skips html2canvas', !(await get(good.verify_url + '&mode=playwright')).body.includes('html2canvas'));
  const hold = S.runs['vt-holdout'];
  ok('hold-out without vt → 404', (await get(hold.url)).status === 404);
  ok('hold-out with valid vt → 200', (await get(hold.url + '?vt=' + encodeURIComponent(hold.vt))).status === 200);
  ok('unknown run → 404', (await get(BASE + '/render/99999999')).status === 404);
  const noout = await get(S.runs['vt-noout'].url);
  ok('html run without code → neutral "no output" document', noout.status === 200 && /wb-empty/.test(noout.body));
  const ans = await get(S.runs['vt-answer'].url);
  ok('answer run → escaped raw output', ans.status === 200 && ans.body.includes('&lt;script&gt;alert(1)&lt;/script&gt;') && !ans.body.includes('<script>alert(1)'));
  const js = await get(S.runs['vt-js'].url);
  ok('js run → harness page with Run tests + harness.js', js.status === 200 && js.body.includes('id="wb-run"') && js.body.includes('/assets/js/harness.js'));
  ok('js code is escaped in the page', js.body.includes('xs.length === 1 &amp;&amp; xs[0] === 42'));
  const r = await get(BASE + '/admin/verify', { redirect: 'manual' });
  ok('/admin/verify requires login (redirect)', r.status === 302 && /\/admin\/login/.test(r.headers.get('location') || ''), String(r.status));
}

/* ---------------------------------------------------------------- phase: probe (top-level, Playwright mode) */
async function probeOnce(browser, url, vp = { width: 1280, height: 800 }, budget = 45_000) {
  const ctx = await browser.newContext({ viewport: vp, deviceScaleFactor: 1 });
  const page = await ctx.newPage();
  const sess = await ctx.newCDPSession(page);
  const tid = (await sess.send('Target.getTargetInfo')).targetInfo.targetId;
  await page.goto(url, { waitUntil: 'commit' });
  const t0 = Date.now();
  const r = await within(page.waitForFunction(() => typeof window.__WB_PROBE_RESULT_JSON === 'string', null, { timeout: 0, polling: 200 })
    .then(() => page.evaluate(() => [window.__WB_PROBE_RESULT_JSON, { cfgSeen: window.__cfgSeen, cfgInDom: window.__cfgInDom, forgeErr: window.__forgeErr, objType: typeof window.__WB_PROBE_RESULT, frozen: Object.isFrozen(window.__WB_PROBE_RESULT) }])), budget);
  const out = r.ok ? { result: JSON.parse(r.v[0]), extra: r.v[1], ms: Date.now() - t0, page, ctx } : { result: null, ms: Date.now() - t0, page, ctx, timeout: !!r.timeout };
  out.close = async () => {
    if (!r.ok) { const cdp = await browser.newBrowserCDPSession(); await within(cdp.send('Target.closeTarget', { targetId: tid }), 5000); }
    await within(ctx.close(), 8000);
  };
  return out;
}
async function phaseProbe(browser) {
  console.log('\n== probe: every check type (top-level / Playwright mode)');
  const S = setup(false);
  for (const slug of Object.keys(EXPECT).concat(['vt-js'])) {
    if (slug === 'vt-hang') continue;
    const run = S.runs[slug];
    const p = await probeOnce(browser, run.verify_url + '&mode=playwright');
    if (!p.result) { ok(`probe ${slug}: result published`, false, 'timeout'); await p.close(); continue; }
    assertRun('probe', slug, p.result);
    if (slug === 'vt-good') {
      ok('probe: metrics present', ['load_ms', 'fps', 'js_errors', 'console_errors', 'dom_nodes'].every((k) => k in p.result.metrics));
      ok('probe: result object frozen and exposed', p.extra.objType === 'object' && p.extra.frozen === true);
    }
    if (slug === 'vt-broken') {
      ok('probe: errors list never leaks the vt token', !JSON.stringify(p.result.errors).includes(run.vt.split('.')[1]));
      ok('probe: resource + rejection + console errors counted', p.result.metrics.resource_errors >= 1 && p.result.metrics.js_errors >= 2 && p.result.metrics.console_errors >= 1, JSON.stringify(p.result.metrics));
    }
    if (slug === 'vt-forge') {
      ok('forge: config (nonce) hidden from page scripts', p.extra.cfgSeen === 'undefined' && p.extra.cfgInDom === false, JSON.stringify(p.extra));
      ok('forge: result property cannot be redefined', p.extra.forgeErr === 'TypeError', JSON.stringify(p.extra));
      ok('forge: genuine result kept', Array.isArray(p.result.checks) && p.result.checks.length === 1 && typeof p.result.nonce === 'string' && p.result.nonce.length === 24);
    }
    if (SHOTS) await within(p.page.screenshot({ path: path.join(SHOTS, `probe-${slug}.png`) }), 8000);
    await p.close();
  }
  // Secondary (mobile) pass: light mode evaluates responsive; interactive checks are skipped.
  for (const [slug, want] of [['vt-wide', false], ['vt-good', true], ['vt-rtl', true]]) {
    const p = await probeOnce(browser, S.runs[slug].verify_url + '&mode=playwright&vp=mobile&light=1', { width: 390, height: 844 });
    const c = p.result && byId(p.result.checks, 'responsive');
    ok(`mobile pass ${slug}: responsive ${want ? 'passes' : 'fails'}`, !!c && c.pass === want, c ? c.detail : 'no result');
    if (slug === 'vt-good') {
      const k = byId(p.result.checks, 'keyboard');
      ok('mobile light pass skips interactive checks', !!k && /not evaluated in the mobile pass/.test(k.detail), k && k.detail);
      ok('mobile light pass is fast (< 6 s)', p.ms < 6000, p.ms + ' ms');
    }
    await p.close();
  }
  // Infinite loop on the main thread: no result, but the page must stay killable.
  const h = await probeOnce(browser, S.runs['vt-hang'].verify_url + '&mode=playwright', undefined, 25_000);
  ok('hang: no result from a frozen page (verifier must time out)', h.result === null && h.timeout === true);
  await h.close();
  // Public "Run tests" button on the js harness page (no verify mode).
  const ctx = await browser.newContext({ viewport: { width: 1100, height: 900 } });
  const page = await ctx.newPage();
  await page.goto(S.runs['vt-js'].url);
  await page.click('#wb-run');
  await page.waitForSelector('#wb-results:not([hidden]) table', { timeout: 20_000 });
  const rows = await page.$$eval('#wb-results tbody tr', (trs) => trs.map((tr) => tr.children[0].textContent));
  ok('public Run tests: 10 rows, 8 passed', rows.length === 10 && rows.filter((x) => x === '✓').length === 8, JSON.stringify(rows));
  ok('public page highlights code', (await page.$$('#wb-code .k')).length > 3);
  if (SHOTS) await page.screenshot({ path: path.join(SHOTS, 'js-run-tests.png'), fullPage: true });
  await ctx.close();
}

/* ---------------------------------------------------------------- phase: sandbox */
async function phaseSandbox(browser) {
  console.log('\n== sandbox: escape attempts');
  const S = setup(false);
  const run = S.runs['vt-sandbox'];
  // Top-level (as Playwright and direct visitors see it).
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  await page.goto(BASE + '/healthz'); // obtain a session cookie on the site origin first
  await page.goto(run.url);
  await page.waitForFunction(() => window.__SANDBOX && window.__SANDBOX.done, null, { timeout: 15_000 });
  const R = await page.evaluate(() => window.__SANDBOX);
  ok('top-level: document.cookie blocked', /^blocked:SecurityError/.test(R.cookie), R.cookie);
  ok('top-level: localStorage blocked', /^blocked:SecurityError/.test(R.localStorage), R.localStorage);
  ok('top-level: opaque origin', R.origin === 'allowed:null', R.origin);
  ok('top-level: fetch to /admin blocked', /^blocked/.test(R.fetchAdmin), R.fetchAdmin);
  ok('top-level: XHR to verifier API blocked', /^blocked/.test(R.xhr), R.xhr);
  ok('top-level: popups blocked', R.openPopup === 'allowed:null' || /^blocked/.test(R.openPopup), R.openPopup);
  await ctx.close();
  // Framed inside a same-origin page (as in the Station / public run page).
  const ctx2 = await browser.newContext();
  const host = await ctx2.newPage();
  await host.goto(BASE + '/admin/login');
  const before = host.url();
  const report = await host.evaluate((src) => new Promise((resolve) => {
    const f = document.createElement('iframe');
    f.setAttribute('sandbox', 'allow-scripts allow-pointer-lock');
    f.src = src;
    window.addEventListener('message', (ev) => { if (ev.source === f.contentWindow && ev.data && ev.data.type === 'sandbox-report') resolve({ r: ev.data.r, origin: ev.origin }); });
    document.body.appendChild(f);
    setTimeout(() => resolve(null), 15000);
  }), run.url);
  await sleep(800);
  ok('framed: report received from opaque origin "null"', !!report && report.origin === 'null', JSON.stringify(report && report.origin));
  if (report) {
    const R2 = report.r;
    ok('framed: parent document not readable', /^blocked:SecurityError/.test(R2.parentDoc), R2.parentDoc);
    ok('framed: top location not readable', /^blocked/.test(R2.topLocationRead), R2.topLocationRead);
    ok('framed: cookie + storage blocked', /^blocked/.test(R2.cookie) && /^blocked/.test(R2.localStorage));
    ok('framed: fetch /admin blocked', /^blocked/.test(R2.fetchAdmin), R2.fetchAdmin);
  }
  ok('framed: top-level navigation blocked (host URL unchanged)', host.url() === before, host.url());
  await ctx2.close();
}

/* ---------------------------------------------------------------- phase: Station (real admin page + engine API) */
async function login(page, S, lang = 'en') {
  await page.goto(BASE + '/admin/login?lang=' + lang);
  await page.fill('input[name=email]', S.admin.email);
  await page.fill('input[name=password]', S.admin.password);
  await Promise.all([page.waitForNavigation({ timeout: 15_000 }), page.click('form button[type=submit]')]);
}
function settled(d) {
  return Object.values(d).every((r) => !['awaiting_verify', 'verifying'].includes(r.status));
}
async function phaseStation() {
  console.log('\n== station: /admin/verify against the engine verifier API' + (FULL_CHROME ? ' (full Chromium)' : ''));
  const browser = await chromium.launch(STATION_LAUNCH);
  try { await stationRun(browser); } finally { await within(browser.close(), 10_000); }
}
async function stationRun(browser) {
  const S = setup(true);
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 }, colorScheme: 'dark' });
  const page = await ctx.newPage();
  await login(page, S, 'en');
  await page.goto(BASE + '/admin/verify?lang=en');
  // Errors of the admin page itself (Playwright's pageerror also reports the sandboxed frames).
  await page.evaluate(() => { window.__adminErrors = []; window.addEventListener('error', (e) => window.__adminErrors.push(e.message)); window.addEventListener('unhandledrejection', (e) => window.__adminErrors.push(String(e.reason))); });
  ok('station page renders', (await page.$('[data-verifier-station]')) !== null);
  if (SHOTS) await within(page.screenshot({ path: path.join(SHOTS, 'station-idle-en.png'), fullPage: true }), 15_000);
  await page.selectOption('[data-vs-concurrency]', '2');
  await page.click('[data-vs-start]');
  const t0 = Date.now();
  let shotTaken = false;
  let maxLag = 0;
  let sawHang = false;
  while (Date.now() - t0 < 300_000) {
    await sleep(1000);
    const lagStart = Date.now();
    const r = await within(page.evaluate(() => document.querySelectorAll('.vs-slot.is-busy').length), 10_000);
    maxLag = Math.max(maxLag, Date.now() - lagStart);
    const titles = await within(page.$$eval('.vs-slot-title', (els) => els.map((e) => e.textContent)), 5000);
    if (titles.ok && titles.v.some((t) => /vt-hang/.test(t))) sawHang = true;
    if (SHOTS && !shotTaken && r.ok && r.v >= 1 && Date.now() - t0 > 2500) {
      shotTaken = (await within(page.screenshot({ path: path.join(SHOTS, 'station-running-en.png'), fullPage: false }), 15_000)).ok === true;
    }
    if (settled(dump())) break;
  }
  const d = dump();
  ok('station: every queued run verified within 5 min', settled(d), JSON.stringify(Object.fromEntries(Object.entries(d).map(([k, v]) => [k, v.status]))));
  ok('station: admin page stays responsive while outputs run (max evaluate lag < 2 s)', maxLag < 2000, maxLag + ' ms');
  soft('station: hang run was observed in a lane', sawHang);
  for (const [slug, r] of Object.entries(d)) {
    if (!r.verified && slug !== 'vt-answer') { ok(`station ${slug}: verified`, false, r.status + ' ' + (r.error || '')); continue; }
    ok(`station ${slug}: verified_by station`, r.verified_by === 'station' || slug === 'vt-answer', r.verified_by);
    if (EXPECT[slug] || slug === 'vt-js') assertRun('station', slug, r.browser);
  }
  ok('station: desktop + mobile screenshots stored (good)', d['vt-good'].screenshot_bytes > 5000 && d['vt-good'].screenshot_mobile_bytes > 2000, `${d['vt-good'].screenshot_bytes}/${d['vt-good'].screenshot_mobile_bytes}`);
  ok('station: WebGL screenshot captured (preserveDrawingBuffer)', d['vt-webgl'].screenshot_bytes > 3000, String(d['vt-webgl'].screenshot_bytes));
  ok('station: hang run recorded as page failure', /unresponsive/.test(byId(d['vt-hang'].browser.checks, 'loads')?.detail || ''));
  if (SHOTS) {
    await sleep(500);
    await within(page.screenshot({ path: path.join(SHOTS, 'station-done-en.png'), fullPage: true }), 15_000);
  }
  await page.click('[data-vs-stop]');
  await sleep(500);
  const adminErrors = await page.evaluate(() => window.__adminErrors);
  ok('station: no errors on the admin page itself', adminErrors.length === 0, adminErrors.join(' | '));
  await ctx.close();
}

/* ---------------------------------------------------------------- phase: headless verifier (verify.mjs) */
async function phaseNode() {
  console.log('\n== node: tools/verifier-node/verify.mjs --once');
  const S = setup(true);
  const script = path.join(ROOT, 'tools/verifier-node/verify.mjs');
  const out = await new Promise((resolve) => {
    const p = spawn(process.execPath, [script, '--site', BASE, '--token', S.token, '--once', '--concurrency', '2'], { env: ENV });
    let buf = '';
    p.stdout.on('data', (d) => { buf += d; });
    p.stderr.on('data', (d) => { buf += d; });
    const killer = setTimeout(() => p.kill('SIGKILL'), 300_000);
    p.on('close', (code) => { clearTimeout(killer); resolve({ code, buf }); });
  });
  ok('verify.mjs exits 0', out.code === 0, out.buf.slice(-800));
  const d = dump();
  ok('node: every queued run verified', settled(d));
  for (const [slug, r] of Object.entries(d)) {
    if (slug === 'vt-answer') continue;
    ok(`node ${slug}: verified_by playwright`, r.verified_by === 'playwright', r.verified_by + ' ' + r.status);
    if (EXPECT[slug] || slug === 'vt-js') assertRun('node', slug, r.browser);
  }
  ok('node: real screenshots stored', d['vt-good'].screenshot_bytes > 5000 && d['vt-webgl'].screenshot_bytes > 3000);
  // Bad token → exit code 3 without touching the queue.
  const bad = await new Promise((resolve) => {
    const p = spawn(process.execPath, [script, '--site', BASE, '--token', 'x'.repeat(40), '--once'], { env: ENV });
    p.on('close', (code) => resolve(code));
  });
  ok('verify.mjs: invalid token → exit 3', bad === 3, String(bad));
}

/* ---------------------------------------------------------------- main */
if (SHOTS) fs.mkdirSync(SHOTS, { recursive: true });
console.log(`verifier tests against ${BASE} (db ${ENV.WB_DB_NAME})`);
console.log('\n== unit: app/render/render.php');
try { process.stdout.write(php('inject-test.php').replace(/^/gm, '  ')); } catch (e) { failures++; console.log(String(e.stdout || e.message)); }
const browser = await chromium.launch({ args: BROWSER_ARGS });
try {
  if (ONLY.has('access')) await phaseAccess();
  if (ONLY.has('probe')) await phaseProbe(browser);
  if (ONLY.has('sandbox')) await phaseSandbox(browser);
  if (ONLY.has('station')) await phaseStation();
  if (ONLY.has('node')) await phaseNode();
} finally {
  await within(browser.close(), 10_000);
}
console.log(`\n${failures === 0 ? 'ALL HARD ASSERTIONS PASSED' : failures + ' FAILED'}${warnings ? ` · ${warnings} soft warning(s)` : ''}`);
process.exit(failures === 0 ? 0 : 1);
