#!/usr/bin/env node
// WOAIBench.si — headless browser verifier (Playwright + Chromium).
//
//   node verify.mjs --site https://woaibench.si --token <verifier API token> [--concurrency 2] [--once]
//
// Claims runs from the site's verifier API, opens each run's sandboxed render URL top-level per
// viewport, waits for the in-page probe (assets/js/probe.js) to publish window.__WB_PROBE_RESULT_JSON,
// takes a real page.screenshot() and submits everything back. See README.md for setup.
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';

// Playwright from ./node_modules (npm install), or from NODE_PATH / a global install as a fallback.
let chromium;
try {
  ({ chromium } = await import('playwright'));
} catch {
  try {
    ({ chromium } = createRequire(import.meta.url)('playwright'));
  } catch {
    console.error('Playwright is not installed. Run "npm install" in tools/verifier-node (see README.md).');
    process.exit(4);
  }
}

const VERSION = '1.0.0';
const DEFAULT_VPS = [{ name: 'desktop', w: 1280, h: 800 }, { name: 'mobile', w: 390, h: 844 }];
const SHOT_LIMIT = 3_900_000; // bytes; the API accepts ≤ 4 MB per screenshot
const TIMING_TYPES = new Set(['animates', 'fps', 'keyboard', 'click']);

/* ------------------------------------------------------------------ arguments */
function parseArgs(argv) {
  const out = {};
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i];
    if (!a.startsWith('--')) continue;
    const eq = a.indexOf('=');
    if (eq > 0) { out[a.slice(2, eq)] = a.slice(eq + 1); continue; }
    const key = a.slice(2);
    const next = argv[i + 1];
    if (next !== undefined && !next.startsWith('--')) { out[key] = next; i++; } else { out[key] = true; }
  }
  return out;
}
const args = parseArgs(process.argv.slice(2));
if (args.help || args.h) {
  console.log(`WOAIBench.si headless verifier ${VERSION}

Usage: node verify.mjs --site <url> --token <token> [options]

  --site <url>          Site origin (+ base path), e.g. https://woaibench.si   (env WB_SITE)
  --token <token>       Verifier API token from Admin → API tokens              (env WB_VERIFIER_TOKEN)
  --concurrency <n>     Parallel runs, 1-8 (default 2)
  --once                Process the queue until it is empty, then exit
  --max-runs <n>        Exit after n runs
  --idle <seconds>      Max wait between polls when the queue is empty (default 30)
  --headed              Show the browser window (debugging)
  --no-swiftshader      Do not force software WebGL (use when a real GPU is available)
  --quiet               Only log errors and per-run summaries
Environment: WB_CHROMIUM (or PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH / CHROMIUM_PATH) = explicit browser binary.`);
  process.exit(0);
}
const SITE = String(args.site || process.env.WB_SITE || '').replace(/\/+$/, '');
const TOKEN = String(args.token || process.env.WB_VERIFIER_TOKEN || '');
const CONCURRENCY = Math.max(1, Math.min(8, parseInt(args.concurrency || '2', 10) || 2));
const ONCE = !!args.once;
const MAX_RUNS = args['max-runs'] ? Math.max(1, parseInt(args['max-runs'], 10) || 1) : Infinity;
const MAX_IDLE = Math.max(3, Math.min(600, parseInt(args.idle || '30', 10) || 30)) * 1000;
const QUIET = !!args.quiet;
if (!/^https?:\/\/[^/]+/i.test(SITE) || !/^[A-Za-z0-9_-]{20,128}$/.test(TOKEN)) {
  console.error('Missing or invalid --site / --token. Run with --help for usage.');
  process.exit(2);
}
let SITE_ORIGIN;
try { SITE_ORIGIN = new URL(SITE).origin; } catch { console.error('Invalid --site URL'); process.exit(2); }

/* ------------------------------------------------------------------ logging */
const stamp = () => new Date().toISOString().replace('T', ' ').slice(0, 19);
const log = (...a) => { if (!QUIET) console.log(stamp(), ...a); };
const info = (...a) => console.log(stamp(), ...a);
const warn = (...a) => console.warn(stamp(), 'WARN', ...a);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
/** Replace unpaired UTF-16 surrogates with U+FFFD (PHP's json_decode rejects them in the request body). */
const wellFormed = (s) => s.replace(/[\uD800-\uDBFF](?![\uDC00-\uDFFF])|(?<![\uD800-\uDBFF])[\uDC00-\uDFFF]/g, '\uFFFD');
/** Truncate to n UTF-16 units without splitting a surrogate pair. */
const trunc = (s, n) => {
  s = String(s ?? '');
  if (s.length <= n) return s;
  let cut = n - 1;
  if (cut > 0 && /[\uD800-\uDBFF]/.test(s[cut - 1])) cut--;
  return s.slice(0, cut) + '…';
};
const num = (v, d) => (Number.isFinite(Number(v)) ? Number(v) : d);

/* ------------------------------------------------------------------ API */
class ApiError extends Error {
  constructor(message, status) { super(message); this.status = status; }
}
async function api(endpoint, body) {
  let res;
  try {
    res = await fetch(SITE + '/api/verifier/' + endpoint, {
      method: 'POST',
      headers: { Authorization: 'Bearer ' + TOKEN, 'Content-Type': 'application/json', Accept: 'application/json', 'User-Agent': 'WOAIBench-Verifier/' + VERSION },
      body: JSON.stringify(body || {}, (k, v) => (typeof v === 'string' ? wellFormed(v) : v)),
      signal: AbortSignal.timeout(60_000),
    });
  } catch (e) {
    throw new ApiError('network: ' + (e?.message || e), 0);
  }
  const text = await res.text();
  let data = null;
  try { data = JSON.parse(text); } catch { data = null; }
  if (!res.ok || !data || data.ok === false) {
    throw new ApiError((data && data.error) || ('HTTP ' + res.status), res.status);
  }
  return data;
}

/* ------------------------------------------------------------------ browser */
function candidateExecutables() {
  const out = [];
  const roots = [process.env.PLAYWRIGHT_BROWSERS_PATH, '/opt/pw-browsers', path.join(process.env.HOME || '', '.cache/ms-playwright')].filter(Boolean);
  for (const root of roots) {
    out.push(path.join(root, 'chromium'));
    try {
      for (const d of fs.readdirSync(root).filter((x) => /^chromium(-|_headless_shell-)\d+$/.test(x)).sort().reverse()) {
        out.push(path.join(root, d, 'chrome-linux', 'chrome'), path.join(root, d, 'chrome-linux', 'headless_shell'),
          path.join(root, d, 'chrome-win', 'chrome.exe'), path.join(root, d, 'chrome-mac', 'Chromium.app', 'Contents', 'MacOS', 'Chromium'));
      }
    } catch { /* not there */ }
  }
  out.push('/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/google-chrome', '/usr/bin/google-chrome-stable');
  return [...new Set(out)].filter((p) => { try { return fs.statSync(p).isFile() || fs.statSync(p).isSymbolicLink(); } catch { return false; } });
}
async function launchBrowser() {
  const flags = ['--disable-dev-shm-usage', '--no-first-run', '--no-default-browser-check', '--mute-audio', '--disable-background-timer-throttling',
    '--disable-renderer-backgrounding', '--disable-backgrounding-occluded-windows', '--autoplay-policy=no-user-gesture-required'];
  // Software WebGL only as a fallback (no --use-angle=swiftshader: forcing it also moves 2D canvas and compositing
  // onto the CPU rasteriser, which halves the frame rate of ordinary pages and fails fair fps checks).
  if (!args['no-swiftshader']) flags.push('--enable-unsafe-swiftshader', '--ignore-gpu-blocklist');
  const opts = { headless: !args.headed, args: flags };
  const explicit = process.env.WB_CHROMIUM || process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || process.env.CHROMIUM_PATH;
  if (explicit) return chromium.launch({ ...opts, executablePath: explicit });
  try {
    return await chromium.launch(opts);
  } catch (e) {
    // Bundled browser missing or of another revision: try known install locations.
    for (const exe of candidateExecutables()) {
      try {
        const b = await chromium.launch({ ...opts, executablePath: exe });
        warn('using browser at', exe);
        return b;
      } catch { /* next */ }
    }
    throw e;
  }
}

/* ------------------------------------------------------------------ results */
function cleanCheck(r, def) {
  const id = String(def.id || def.type);
  if (!r || typeof r !== 'object') return { id, type: String(def.type), pass: false, score: 0, detail: 'not evaluated' };
  const o = { id, type: String(def.type), pass: r.pass === true, score: Math.max(0, Math.min(1, num(r.score, 0))), detail: trunc(r.detail, 300) };
  if (typeof r.value === 'number' && Number.isFinite(r.value)) o.value = r.value;
  return o;
}
function cleanTests(list) {
  if (!Array.isArray(list)) return [];
  return list.slice(0, 500).map((r) => {
    r = r && typeof r === 'object' ? r : {};
    const o = { name: trunc(r.name, 200), pass: r.pass === true, ms: Math.max(0, num(r.ms, 0)) };
    // The harness sends a JSON preview string: forward it unchanged (the API stores strings as-is).
    if (r.got !== undefined) o.got = typeof r.got === 'string' ? trunc(r.got, 300) : r.got;
    if (r.error) o.error = trunc(r.error, 300);
    return o;
  });
}
/** Out-of-memory in the page or a test Worker ("Array buffer allocation failed", heap exhausted). */
const OOM_RE = /Array buffer allocation failed|out of memory|Allocation failed - JavaScript heap/i;
function hasOom(result) {
  if (!result || typeof result !== 'object') return false;
  if (num(result.metrics?.oom, 0) > 0) return true;
  return (result.errors || []).some((e) => OOM_RE.test(String(e)))
    || (result.tests || []).some((t) => t && (t.oom || OOM_RE.test(String(t.error || ''))));
}
const findById = (list, id) => (Array.isArray(list) ? list.find((x) => x && String(x.id) === id) : null) || null;
/** Responsive comes from the mobile pass, everything else from the primary (first) viewport. */
function merge(run, vps, out) {
  const primary = out[vps[0].name] || null;
  const mobile = out.mobile || (vps[1] ? out[vps[1].name] : null) || null;
  const base = primary || mobile || {};
  const checks = (run.checks || []).filter((c) => c && c.type).map((c) => {
    const id = String(c.id || c.type);
    const src = c.type === 'responsive' && mobile ? mobile : base;
    return cleanCheck(findById(src.checks, id) || findById(base.checks, id), c);
  });
  const errors = [];
  vps.forEach((vp, i) => {
    for (const e of (out[vp.name]?.errors || [])) {
      const s = trunc((i > 0 ? `[${vp.name}] ` : '') + e, 300);
      if (errors.length < 20 && !errors.includes(s)) errors.push(s);
    }
  });
  const metrics = {};
  for (const [k, v] of Object.entries(base.metrics || {}).slice(0, 30)) {
    if ((typeof v === 'number' && Number.isFinite(v)) || typeof v === 'boolean' || v === null) metrics[k] = v;
    else if (typeof v === 'string') metrics[k] = trunc(v, 60);
  }
  for (const vp of vps.slice(1)) {
    const m = out[vp.name]?.metrics;
    if (m) { metrics[vp.name + '_load_ms'] = num(m.load_ms, null); metrics[vp.name + '_js_errors'] = num(m.js_errors, 0); }
  }
  return { checks, tests: cleanTests(base.tests), errors, metrics };
}
function hungResult(run, vp, secs) {
  const detail = `page unresponsive: no probe result within ${secs} s (${vp.name})`;
  return {
    checks: (run.checks || []).map((c) => ({ id: String(c.id || c.type), type: c.type, pass: false, score: 0, detail })),
    tests: run.tests && Array.isArray(run.tests.cases) ? run.tests.cases.map((c, i) => ({ name: String(c.name || 'case ' + (i + 1)), pass: false, ms: 0, error: 'page unresponsive' })) : [],
    errors: [`Probe timeout: the page did not respond within ${secs} s (infinite loop or blocked main thread)`],
    metrics: { load_ms: null, fps: 0, js_errors: 0, console_errors: 0, hung: true },
  };
}

function awayResult(run, vp, url) {
  let where = 'another page';
  try { where = new URL(url).origin; } catch { /* keep */ }
  const detail = `the page navigated away (${vp.name}) to ${where}`;
  return {
    checks: (run.checks || []).map((c) => ({ id: String(c.id || c.type), type: c.type, pass: false, score: 0, detail })),
    tests: run.tests && Array.isArray(run.tests.cases) ? run.tests.cases.map((c, i) => ({ name: String(c.name || 'case ' + (i + 1)), pass: false, ms: 0, error: 'page navigated away' })) : [],
    errors: [`Page navigated away to ${trunc(url, 200)}`],
    metrics: { load_ms: null, fps: 0, js_errors: 0, console_errors: 0, navigated: true },
  };
}

/* ------------------------------------------------------------------ one viewport */
function frameUrl(renderUrl, params) {
  const u = new URL(renderUrl, SITE + '/');
  for (const [k, v] of Object.entries(params)) if (v != null) u.searchParams.set(k, String(v));
  return u.toString();
}
/**
 * Playwright's own timeouts do not fire while a renderer is stuck in an infinite loop, so every page
 * interaction is raced against a wall-clock timer: → {ok, value} | {ok:false, error} | {timeout:true}.
 */
function within(promise, ms) {
  return Promise.race([
    promise.then((value) => ({ ok: true, value }), (error) => ({ ok: false, error })),
    sleep(ms).then(() => ({ timeout: true })),
  ]);
}
/** Close a page whose renderer may be hung: the browser process tears the target down. */
async function killPage(b, targetId) {
  if (!targetId) return;
  try {
    const cdp = await b.newBrowserCDPSession();
    await within(cdp.send('Target.closeTarget', { targetId }), 8000);
    await cdp.detach().catch(() => {});
  } catch { /* already gone */ }
}
async function screenshot(page) {
  let buf = await page.screenshot({ type: 'png', timeout: 15_000, animations: 'allow', caret: 'initial' });
  let mime = 'image/png';
  if (buf.length > SHOT_LIMIT) {
    buf = await page.screenshot({ type: 'jpeg', quality: 80, timeout: 15_000 });
    mime = 'image/jpeg';
  }
  return buf.length <= SHOT_LIMIT ? `data:${mime};base64,${buf.toString('base64')}` : null;
}
/** → {status:'ok', result, shot} | {status:'infra', error} */
async function runViewport(b, run, vp, light) {
  const ctx = await b.newContext({
    viewport: { width: vp.w, height: vp.h }, deviceScaleFactor: 1, serviceWorkers: 'block', acceptDownloads: false,
    locale: 'en-US', timezoneId: 'UTC', colorScheme: 'dark',
  });
  let targetId = null;
  let hung = false;
  try {
    const page = await ctx.newPage();
    try {
      const session = await ctx.newCDPSession(page);
      targetId = (await session.send('Target.getTargetInfo')).targetInfo.targetId;
      await session.detach().catch(() => {});
    } catch { targetId = null; }
    let probeStatus = null;
    let leftTo = null;
    let onAway = () => {};
    const away = new Promise((resolve) => { onAway = resolve; });
    const renderPath = new URL(run.render_url, SITE + '/').pathname;
    page.on('response', (r) => { if (/\/assets\/js\/probe\.js/.test(r.url())) probeStatus = r.status(); });
    page.on('framenavigated', (f) => {
      if (f !== page.mainFrame()) return;
      let p = '';
      try { p = new URL(f.url()).pathname; } catch { p = ''; }
      if (p !== renderPath) { leftTo = f.url(); onAway(); }
    });
    page.on('dialog', (d) => d.dismiss().catch(() => {}));
    const nav = await within(page.goto(frameUrl(run.render_url, { vp: vp.name, mode: 'playwright', light: light ? '1' : null }), { waitUntil: 'commit', timeout: 30_000 }), 35_000);
    if (!nav.ok) return { status: 'infra', error: 'navigation failed: ' + (nav.timeout ? 'timeout' : trunc(String(nav.error?.message || nav.error).split('\n')[0], 200)) };
    if (!nav.value || nav.value.status() >= 400) return { status: 'infra', error: 'render responded HTTP ' + (nav.value ? nav.value.status() : '?') };

    let deadline = num(run.timeout_ms, 15_000);
    const ready = await within(page.waitForFunction(() => window.__WB_PROBE_READY === true, null, { timeout: 0, polling: 100 }), 20_000);
    if (ready.ok) {
      const d = await within(page.evaluate(() => window.__WB_PROBE_DEADLINE), 5000);
      if (d.ok) deadline = num(d.value, deadline);
    } else if (probeStatus !== 200) {
      return { status: 'infra', error: 'probe did not load (probe.js HTTP ' + (probeStatus ?? 'n/a') + ')' };
    }
    // probe.js loaded but the page may never let us in again: the page itself blocks the main thread.
    const secs = Math.round((Math.min(160_000, Math.max(5000, deadline)) + 6000) / 1000);
    const res = await within(Promise.race([
      page.waitForFunction(() => typeof window.__WB_PROBE_RESULT_JSON === 'string', null, { timeout: 0, polling: 250 })
        .then(() => page.evaluate(() => window.__WB_PROBE_RESULT_JSON)),
      away.then(() => null),
    ]), secs * 1000);
    if (res.ok && res.value === null && leftTo !== null) {
      warn(`run #${run.id} (${vp.name}): the page navigated away to ${trunc(leftTo, 120)}`);
      return { status: 'ok', result: awayResult(run, vp, leftTo), shot: null };
    }
    if (!res.ok) {
      if (res.timeout) {
        hung = true;
        warn(`run #${run.id} (${vp.name}): page unresponsive after ${secs} s`);
        return { status: 'ok', result: hungResult(run, vp, secs), shot: null };
      }
      return { status: 'infra', error: 'probe result unavailable: ' + trunc(String(res.error?.message || res.error).split('\n')[0], 200) };
    }
    let result;
    try { result = JSON.parse(res.value); } catch { return { status: 'infra', error: 'probe result is not valid JSON' }; }
    const shot = await within(screenshot(page), 25_000);
    if (!shot.ok) {
      warn(`run #${run.id} (${vp.name}): screenshot failed: ${shot.timeout ? 'timeout' : String(shot.error?.message || shot.error).split('\n')[0]}`);
      hung = hung || !!shot.timeout;
    }
    return { status: 'ok', result, shot: shot.ok ? shot.value : null };
  } finally {
    if (hung) await killPage(b, targetId);
    await within(ctx.close(), 10_000);
  }
}

/* ------------------------------------------------------------------ one run */
const stats = { processed: 0, released: 0, hung: 0, checks: 0, passed: 0, ms: 0 };
const inflight = new Map();
async function processRun(browser, run) {
  const t0 = Date.now();
  inflight.set(run.id, run);
  const title = run.task?.title || run.task?.slug || '#' + run.id;
  log(`run #${run.id} · ${title} — claimed`);
  const vps = Array.isArray(run.viewports) && run.viewports.length
    ? run.viewports.slice(0, 4).map((v) => ({ name: String(v.name || 'desktop').replace(/[^a-z0-9_-]/gi, '').slice(0, 20) || 'desktop', w: Math.max(200, Math.min(2560, num(v.w, 1280))), h: Math.max(200, Math.min(2000, num(v.h, 800))) }))
    : DEFAULT_VPS;
  const out = {};
  const shots = {};
  try {
    for (let i = 0; i < vps.length; i++) {
      const r = await runViewport(browser, run, vps[i], i > 0);
      if (r.status === 'infra') { await release(run, r.error); return; }
      out[vps[i].name] = r.result;
      if (r.shot) shots[vps[i].name] = r.shot;
      if (r.result?.metrics?.hung) break;
    }
    const results = merge(run, vps, out);
    if (Object.values(out).some(hasOom)) {
      // Memory exhaustion is usually the machine (other pages, leftovers): release so the run is retried
      // later in fresh contexts. Only when it reproduces on the last allowed try is it the output's own failure.
      const attempt = num(run.attempt, 1);
      const max = num(run.max_attempts, 3);
      if (attempt < max) {
        await release(run, `out of memory in the browser (try ${attempt}/${max}) — retried in a fresh context`);
        return;
      }
      results.metrics.oom = 1;
      results.errors.unshift(`Out of memory on ${max} separate tries in fresh browser contexts: counted as the output's failure`);
    }
    const timing = (run.checks || []).some((c) => c && TIMING_TYPES.has(c.type));
    if (timing && (results.metrics.hidden === true || results.metrics.offscreen === true)) {
      await release(run, 'page was hidden during timing checks');
      return;
    }
    let res;
    for (let attempt = 0; ; attempt++) {
      try {
        res = await api('submit', { run_id: run.id, lock_token: run.lock_token, agent: 'playwright', results, screenshots: shots });
        break;
      } catch (e) {
        if (attempt >= 1 || (e.status >= 400 && e.status < 500)) throw e;
        await sleep(2000);
      }
    }
    const passed = results.checks.filter((c) => c.pass).length;
    const tPass = results.tests.filter((x) => x.pass).length;
    stats.processed++;
    stats.checks += results.checks.length;
    stats.passed += passed;
    stats.ms += Date.now() - t0;
    if (results.metrics.hung) stats.hung++;
    for (const w of res.warnings || []) warn(`run #${run.id}: ${w}`);
    info(`run #${run.id} · ${title} — ${passed}/${results.checks.length} checks${results.tests.length ? ` · tests ${tPass}/${results.tests.length}` : ''} in ${((Date.now() - t0) / 1000).toFixed(1)} s → ${res.next_status || 'ok'}`);
  } catch (e) {
    await release(run, 'verifier error: ' + (e?.message || e));
  } finally {
    inflight.delete(run.id);
  }
}
async function release(run, error) {
  stats.released++;
  warn(`run #${run.id} released: ${error}`);
  try { await api('release', { run_id: run.id, lock_token: run.lock_token, error: trunc(error, 300) }); } catch (e) { warn(`release of #${run.id} failed: ${e.message}`); }
}

/* ------------------------------------------------------------------ main loop */
let stopping = false;
let claimed = 0;
let browser = null;
let relaunching = null;
async function getBrowser() {
  if (browser && browser.isConnected()) return browser;
  if (!relaunching) {
    relaunching = launchBrowser().then((b) => {
      browser = b;
      b.on('disconnected', () => { if (!stopping) warn('browser disconnected — relaunching on next run'); });
      return b;
    }).finally(() => { relaunching = null; });
  }
  return relaunching;
}
async function lane(n) {
  let idle = 2000;
  while (!stopping && claimed < MAX_RUNS) {
    let next;
    try {
      next = await api('next', { agent: 'playwright' });
    } catch (e) {
      if (e.status === 401 || e.status === 403) { console.error('Unauthorised: check --token (scope "verifier") and --site.'); stopping = true; process.exitCode = 3; return; }
      warn(`lane ${n}: API error: ${e.message}`);
      await sleep(Math.min(MAX_IDLE, 10_000));
      continue;
    }
    if (!next.run) {
      if (ONCE) return;
      log(`lane ${n}: queue empty — next poll in ${Math.round(idle / 1000)} s`);
      await sleep(idle);
      idle = Math.min(MAX_IDLE, Math.round(idle * 1.6));
      continue;
    }
    idle = 2000;
    claimed++;
    const b = await getBrowser();
    await processRun(b, next.run);
  }
}
async function shutdown(signal) {
  if (stopping) {
    warn('second signal — releasing in-flight runs and exiting');
    await Promise.all([...inflight.values()].map((r) => release(r, 'verifier stopped')));
    process.exit(130);
  }
  stopping = true;
  info(`${signal}: finishing ${inflight.size} in-flight run(s); press Ctrl+C again to abort`);
}
process.on('SIGINT', () => { shutdown('SIGINT'); });
process.on('SIGTERM', () => { shutdown('SIGTERM'); });

info(`WOAIBench verifier ${VERSION} → ${SITE_ORIGIN} · concurrency ${CONCURRENCY}${ONCE ? ' · once' : ''}`);
try {
  await getBrowser();
  log('browser', browser.version());
} catch (e) {
  console.error('Could not launch Chromium:', e.message.split('\n')[0]);
  console.error('Install it with "npx playwright install chromium", or set WB_CHROMIUM to a Chromium/Chrome binary.');
  process.exit(4);
}
await Promise.all(Array.from({ length: CONCURRENCY }, (_, i) => lane(i + 1)));
info(`done · ${stats.processed} verified (${stats.passed}/${stats.checks} checks passed${stats.hung ? `, ${stats.hung} unresponsive` : ''}) · ${stats.released} released` +
  (stats.processed ? ` · avg ${(stats.ms / stats.processed / 1000).toFixed(1)} s/run` : ''));
stopping = true;
if (browser) await Promise.race([browser.close().catch(() => {}), sleep(10_000)]);
