/*!
 * WOAIBench.si — benchmark console (/admin/console).
 * Loops POST /admin/api/step while the tab is open (backs off when idle, stops after repeated errors),
 * renders live counters, campaign progress and an activity log, and drives the embedded
 * Verification Station (assets/js/verifier.js → window.WBVerifier) when it is available.
 */
(() => {
  'use strict';

  const d = document;
  const $ = (sel, ctx = d) => ctx.querySelector(sel);
  const root = $('[data-console]');
  const cfgEl = $('#console-cfg');
  if (!root || !cfgEl) return;

  const WB = window.WB || {};
  const t = (key, repl) => (WB.t ? WB.t(key, repl) : key);
  const money = (n) => '\u2066' + (WB.fmt && WB.fmt.money ? WB.fmt.money(n) : '$' + Number(n || 0).toFixed(2)) + '\u2069';
  const num = (n) => Number(n || 0).toLocaleString('en-US');
  let cfg = {};
  try { cfg = JSON.parse(cfgEl.textContent) || {}; } catch (e) { cfg = {}; }
  // Run / judgment status names ("status.*") come with the Station strings (#vs-i18n).
  let statusNames = {};
  try { statusNames = JSON.parse(($('#vs-i18n') || {}).textContent || '{}') || {}; } catch (e) { statusNames = {}; }
  const statusText = (s) => statusNames['status.' + s] || s;

  const FAST_DELAY = 300;
  const IDLE_MIN = 4000;
  const IDLE_MAX = 30000;
  const MAX_ERRORS = 5;
  const LOG_MAX = 300;

  const ui = {
    toggle: $('[data-console-toggle]', root),
    status: $('[data-console-status]', root),
    detail: $('[data-console-detail]', root),
    verify: $('[data-console-verify]', root),
    log: $('[data-console-log]'),
    clear: $('[data-console-clear]'),
    campaigns: $('[data-console-campaigns]'),
    counts: $('[data-console-counts]'),
    station: $('#station'),
    stationLog: $('[data-station-log]'),
    stationStats: $('[data-station-stats]'),
    stationState: $('[data-station-state]'),
    m: (k) => $('[data-m="' + k + '"]', root),
  };

  const state = {
    running: false,
    timer: 0,
    inflight: null,
    idleDelay: IDLE_MIN,
    errors: 0,
    steps: 0,
    units: 0,
    ok: 0,
    fails: 0,
    verified: 0,
    spentStart: null,
    spent: null,
    wakeLock: null,
    station: null,
  };

  const el = (tag, cls, text) => {
    const n = d.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined && text !== null) n.textContent = String(text);
    return n;
  };
  const clock = () => new Date().toLocaleTimeString('en-GB', { hour12: false });

  /* ------------------------------------------------------------------ rendering */
  function setMode(mode, detail) {
    root.classList.toggle('is-on', state.running);
    root.classList.toggle('is-running', mode === 'running');
    root.classList.toggle('is-idle', mode === 'idle');
    root.classList.toggle('is-error', mode === 'error');
    ui.toggle.setAttribute('aria-pressed', state.running ? 'true' : 'false');
    ui.status.textContent = t('admin.js.c_' + mode);
    if (detail !== undefined) ui.detail.textContent = detail;
  }

  function setMeter(k, v) {
    const n = ui.m(k);
    if (n) n.textContent = v;
  }

  function renderMeters() {
    setMeter('steps', num(state.steps));
    setMeter('units', num(state.units));
    setMeter('ok', num(state.ok));
    setMeter('errors', num(state.fails));
    setMeter('verified', num(state.verified));
    if (state.spent !== null) {
      setMeter('spent', money(state.spent));
      setMeter('session', t('admin.js.c_session', { s: money(Math.max(0, state.spent - (state.spentStart || 0))) }));
    }
  }

  function log(kind, text, opts = {}) {
    if (!ui.log) return;
    const empty = $('.admin-log-empty', ui.log);
    if (empty) empty.remove();
    const li = el('li', 'is-new' + (opts.fail ? ' is-fail' : '') + (opts.sys ? ' is-sys' : ''));
    li.appendChild(el('span', 'admin-log-time', clock()));
    li.appendChild(el('span', 'admin-log-kind', kind));
    const body = el('span', 'admin-log-text');
    if (opts.runId && cfg.runUrl) {
      const a = el('a', '', '#' + opts.runId);
      a.href = cfg.runUrl + opts.runId;
      body.appendChild(a);
      body.appendChild(d.createTextNode(' '));
    }
    body.appendChild(d.createTextNode(text));
    li.appendChild(body);
    li.appendChild(el('span', 'admin-log-meta', opts.meta || ''));
    ui.log.insertBefore(li, ui.log.firstChild);
    while (ui.log.children.length > LOG_MAX) ui.log.removeChild(ui.log.lastChild);
  }

  function describe(item) {
    const parts = [];
    if (item.model) parts.push(item.model);
    if (item.judge) parts.push(t('admin.js.c_judge_by', { j: item.judge }));
    if (item.task) parts.push(item.task);
    if (item.attempt) parts.push('#' + item.attempt);
    if (item.status) parts.push((d.dir === 'rtl' ? '← ' : '→ ') + statusText(item.status));
    if (item.score !== undefined && item.score !== null) parts.push(t('admin.js.c_score', { s: Number(item.score).toFixed(1) }));
    if (item.kind === 'aggregate' && item.ok) parts.push(t('admin.js.c_aggregate', { m: item.models || 0, r: item.runs || 0 }));
    if (item.error) parts.push('— ' + item.error);
    return parts.join(' · ') || item.kind;
  }

  function renderDid(list) {
    (list || []).forEach((item) => {
      if (!item || typeof item !== 'object') return;
      state.units++;
      const failed = item.ok === false;
      if (failed) state.fails++; else state.ok++;
      const meta = [];
      if (item.ms) meta.push((item.ms / 1000).toFixed(1) + ' s');
      if (item.cost) meta.push(money(item.cost));
      log(t('admin.js.k_' + item.kind) !== 'admin.js.k_' + item.kind ? t('admin.js.k_' + item.kind) : item.kind, describe(item), { runId: item.run_id, fail: failed, meta: meta.join(' · ') });
    });
  }

  function renderCounts(counts) {
    if (!ui.counts || !counts) return;
    Object.keys(counts).forEach((s) => {
      const li = $('[data-status="' + s + '"]', ui.counts);
      if (!li) return;
      const n = $('[data-n]', li);
      const v = num(counts[s]);
      if (n && n.textContent !== v) {
        n.textContent = v;
        li.classList.add('is-bump');
        setTimeout(() => li.classList.remove('is-bump'), 900);
      }
    });
  }

  function normalize(c) {
    const counts = c.counts && typeof c.counts === 'object' ? c.counts : {};
    const sum = Object.keys(counts).reduce((s, k) => s + (Number(counts[k]) || 0), 0);
    const total = c.total !== undefined ? Number(c.total) : sum;
    const done = c.done !== undefined ? Number(c.done) : (Number(counts.scored || 0) + Number(counts.failed || 0) + Number(counts.skipped || 0));
    const pct = total > 0 ? Math.min(100, done / total * 100) : 0;
    return {
      id: c.id, name: c.name || ('#' + c.id), status: c.status || '', total, done, pct,
      failed: Number(c.failed !== undefined ? c.failed : counts.failed || 0),
      spent: Number(c.spent !== undefined ? c.spent : (c.spent_usd || 0)),
      est: c.est_cost !== undefined && c.est_cost !== null ? Number(c.est_cost) : (c.est !== undefined && c.est !== null ? Number(c.est) : null),
      eta: c.eta_seconds || null,
    };
  }

  function progressBlock(p) {
    const wrap = el('div', 'admin-progress');
    wrap.setAttribute('data-campaign', p.id);
    const top = el('div', 'admin-progress-top');
    const name = el('a', 'admin-progress-name', p.name);
    name.dir = 'auto';
    name.href = (cfg.campaignUrl || '') + p.id;
    top.appendChild(name);
    top.appendChild(el('span', 'badge ' + (p.status === 'running' ? 'badge-blue' : 'badge-gold'), statusLabel(p.status)));
    top.appendChild(el('span', 'admin-progress-pct t-num', '')).setAttribute('data-pct', '');
    wrap.appendChild(top);
    const bar = el('div', 'progress');
    bar.setAttribute('role', 'progressbar');
    bar.setAttribute('aria-valuemin', '0');
    bar.setAttribute('aria-valuemax', '100');
    bar.setAttribute('aria-label', p.name);
    const fill = el('div', 'progress-bar');
    fill.setAttribute('data-bar', '');
    bar.appendChild(fill);
    wrap.appendChild(bar);
    const meta = el('div', 'admin-progress-meta t-xs t-muted');
    const done = el('span');
    done.setAttribute('data-done', '');
    meta.appendChild(done);
    const spent = el('span');
    spent.setAttribute('data-spent', '');
    meta.appendChild(spent);
    wrap.appendChild(meta);
    return wrap;
  }

  function renderCampaigns(list, opts = {}) {
    if (!ui.campaigns || !Array.isArray(list)) return;
    const seen = {};
    list.filter((c) => c && c.id).forEach((raw) => {
      const p = normalize(raw);
      seen[p.id] = true;
      let block = $('[data-campaign="' + p.id + '"]', ui.campaigns);
      if (!block) {
        const empty = $('.empty-state', ui.campaigns);
        if (empty) empty.remove();
        block = progressBlock(p);
        ui.campaigns.insertBefore(block, ui.campaigns.firstChild);
      }
      const pct = $('[data-pct]', block);
      if (pct) pct.textContent = p.pct.toFixed(1) + '%';
      const bar = $('[data-bar]', block);
      if (bar) {
        bar.style.width = p.pct.toFixed(2) + '%';
        const pb = bar.parentElement;
        if (pb) pb.setAttribute('aria-valuenow', String(Math.round(p.pct)));
      }
      const done = $('[data-done]', block);
      if (done) done.textContent = t('admin.js.c_done_of', { d: num(p.done), t: num(p.total) }) + (p.eta ? ' · ' + t('admin.js.c_eta', { m: Math.max(1, Math.round(p.eta / 60)) }) : '');
      const spent = $('[data-spent]', block);
      if (spent) spent.textContent = t('admin.js.c_spent', { s: money(p.spent) }) + (p.est !== null ? ' / ' + money(p.est) : '');
      let failed = $('[data-failed]', block);
      if (p.failed > 0) {
        if (!failed) {
          failed = el('span', 't-red');
          failed.setAttribute('data-failed', '');
          $('.admin-progress-meta', block).appendChild(failed);
        }
        failed.textContent = t('admin.js.c_failed', { n: num(p.failed) });
      }
    });
    const gone = Array.from(ui.campaigns.querySelectorAll('[data-campaign]'))
      .filter((b) => !seen[b.getAttribute('data-campaign')] && !b.classList.contains('is-finished'));
    if (gone.length && !opts.final) refreshFinished(gone.map((b) => Number(b.getAttribute('data-campaign'))));
  }

  /** Campaigns that left the active list (done / cancelled): fetch their final numbers once. */
  async function refreshFinished(ids) {
    if (!cfg.progressUrl) return;
    let res = null;
    try {
      res = WB.fetchJSON ? await WB.fetchJSON(cfg.progressUrl, { method: 'POST', body: { ids } }) : null;
    } catch (e) { res = null; }
    const list = res && Array.isArray(res.campaigns) ? res.campaigns : [];
    renderCampaigns(list, { final: true });
    ids.forEach((id) => {
      const b = $('[data-campaign="' + id + '"]', ui.campaigns);
      if (!b) return;
      b.classList.add('is-finished');
      const badge = $('.admin-progress-top .badge', b);
      const row = list.find((c) => Number(c.id) === id);
      if (badge) { badge.className = 'badge badge-green'; badge.textContent = row && row.status && row.status !== 'running' ? statusLabel(row.status) : t('admin.js.c_finished'); }
    });
  }
  function statusLabel(s) {
    const k = 'admin.js.st_' + s;
    const v = t(k);
    return v === k ? s : v;
  }

  /* ------------------------------------------------------------------ step loop */
  async function step() {
    const body = {};
    if (WB.fetchJSON) {
      try {
        return await WB.fetchJSON(cfg.stepUrl, { method: 'POST', body });
      } catch (e) {
        const err = new Error(e && e.message ? e.message : t('js.error'));
        err.status = e && e.status !== undefined ? e.status : 0;
        err.handled = true;
        throw err;
      }
    }
    const res = await fetch(cfg.stepUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': (($('meta[name="csrf-token"]') || {}).content || '') },
      body: '{}',
    });
    const data = await res.json().catch(() => null);
    if (!res.ok || !data) {
      const err = new Error((data && data.error) || 'HTTP ' + res.status);
      err.status = res.status;
      throw err;
    }
    return data;
  }

  function schedule(ms) {
    clearTimeout(state.timer);
    if (state.running) state.timer = setTimeout(loop, ms);
  }

  async function loop() {
    if (!state.running || state.inflight) return;
    let delay = FAST_DELAY;
    state.inflight = step();
    try {
      const res = await state.inflight;
      state.errors = 0;
      state.steps++;
      const did = Array.isArray(res.did) ? res.did : [];
      renderDid(did);
      renderCounts(res.counts);
      renderCampaigns(res.campaigns);
      if (typeof res.spent_usd === 'number') {
        if (state.spentStart === null) state.spentStart = res.spent_usd - (Number(res.step_spent_usd) || 0);
        state.spent = res.spent_usd;
      }
      renderMeters();
      const idle = !!res.idle && did.length === 0;
      const c = res.counts || {};
      const waiting = Number(c.awaiting_verify || 0) + Number(c.verifying || 0);
      if (idle) {
        // Runs waiting for the browser station: poll briskly so verified runs move on to judging quickly.
        const cap = waiting > 0 && state.station ? 5000 : IDLE_MAX;
        delay = Math.min(cap, state.idleDelay);
        state.idleDelay = Math.min(cap, Math.round(state.idleDelay * 1.6));
        if (state.running) {
          setMode('idle', t(waiting > 0 ? 'admin.js.c_wait_detail' : 'admin.js.c_idle_detail', { n: num(waiting), s: Math.round(delay / 1000) }));
          if (waiting > 0) ui.status.textContent = t('admin.js.c_wait');
        }
      } else {
        state.idleDelay = IDLE_MIN;
        if (state.running) setMode('running', t('admin.js.c_running_detail', { n: num(did.length), ms: num(res.ms || 0) }));
      }
    } catch (e) {
      state.errors++;
      const status = e && e.status;
      let msg = e && e.message ? e.message : t('js.error');
      if (status === 404) msg = t('admin.js.c_no_engine');
      if (status === 401 || status === 419) msg = t('admin.js.c_auth');
      log(t('admin.js.k_error'), msg, { fail: true, sys: true });
      if (status === 401 || status === 419 || status === 404 || state.errors >= MAX_ERRORS) {
        stop(true);
        setMode('error', msg);
        return;
      }
      delay = Math.min(30000, 2000 * state.errors);
      setMode('running', t('admin.js.c_retry', { n: state.errors, max: MAX_ERRORS, s: Math.round(delay / 1000) }));
    } finally {
      state.inflight = null;
    }
    schedule(delay);
  }

  /* ------------------------------------------------------------------ verification station */
  function startStation() {
    if (!ui.verify || !ui.verify.checked || !window.WBVerifier || !ui.station) return;
    try {
      state.station = window.WBVerifier.start(ui.station, {
        concurrency: 2,
        onLog: (entry) => {
          if (!ui.stationLog || !entry) return;
          const placeholder = $('.admin-log-empty', ui.stationLog);
          if (placeholder) placeholder.remove();
          const li = el('li', entry.level === 'error' || entry.level === 'warn' ? 'is-fail' : '');
          li.appendChild(el('span', 'admin-log-time', entry.time && entry.time.toLocaleTimeString ? entry.time.toLocaleTimeString('en-GB', { hour12: false }) : clock()));
          const lv = entry.level || 'info';
          const lvLabel = t('admin.js.lv_' + lv);
          li.appendChild(el('span', 'admin-log-kind', lvLabel === 'admin.js.lv_' + lv ? lv : lvLabel));
          li.appendChild(el('span', 'admin-log-text', entry.msg || ''));
          li.appendChild(el('span', 'admin-log-meta', ''));
          ui.stationLog.insertBefore(li, ui.stationLog.firstChild);
          while (ui.stationLog.children.length > 150) ui.stationLog.removeChild(ui.stationLog.lastChild);
        },
        onState: (name) => {
          const badge = ui.stationState;
          if (!badge) return;
          const variant = { running: 'green', paused: 'gold', stopping: 'blue' }[name];
          badge.hidden = !variant;
          if (!variant) return;
          badge.className = 'badge badge-' + variant;
          badge.textContent = t('admin.js.vs_state_' + name);
          if (name === 'paused') log(t('admin.js.k_station'), t('admin.js.c_station_paused'), { sys: true });
        },
        onStats: (s) => {
          if (!s) return;
          state.verified = Number(s.processed || 0);
          renderMeters();
          if (ui.stationStats) {
            ui.stationStats.textContent = t('admin.js.c_station_stats', {
              n: num(s.processed || 0),
              p: s.pass_rate === null || s.pass_rate === undefined ? '—' : s.pass_rate + '%',
              r: num(s.released || 0),
            });
          }
        },
      });
      log(t('admin.js.k_station'), t('admin.js.c_station_on'), { sys: true });
    } catch (e) {
      log(t('admin.js.k_station'), String(e && e.message ? e.message : e), { fail: true, sys: true });
    }
  }

  function stopStation() {
    try {
      if (state.station && typeof state.station.stop === 'function') state.station.stop();
      else if (window.WBVerifier && typeof window.WBVerifier.stop === 'function') window.WBVerifier.stop();
    } catch (e) { /* ignore */ }
    state.station = null;
  }

  /* ------------------------------------------------------------------ start / stop */
  async function lockScreen() {
    try {
      if ('wakeLock' in navigator && !state.wakeLock) {
        state.wakeLock = await navigator.wakeLock.request('screen');
        state.wakeLock.addEventListener('release', () => { state.wakeLock = null; });
      }
    } catch (e) { state.wakeLock = null; }
  }

  function start() {
    if (state.running) return;
    state.running = true;
    state.errors = 0;
    state.idleDelay = IDLE_MIN;
    setMode('running', t('admin.js.c_starting'));
    log(t('admin.js.k_system'), t('admin.js.c_started'), { sys: true });
    startStation();
    lockScreen();
    loop();
  }

  function stop(fromError) {
    if (!state.running) return;
    state.running = false;
    clearTimeout(state.timer);
    stopStation();
    if (state.wakeLock) { try { state.wakeLock.release(); } catch (e) { /* ignore */ } }
    if (!fromError) {
      setMode('stopped', t('admin.js.c_stopped_detail'));
      log(t('admin.js.k_system'), t('admin.js.c_stopped_log'), { sys: true });
    }
  }

  ui.toggle.addEventListener('click', () => (state.running ? stop(false) : start()));
  if (ui.verify) {
    ui.verify.addEventListener('change', () => {
      if (!state.running) return;
      if (ui.verify.checked) startStation(); else stopStation();
    });
  }
  if (ui.clear && ui.log) {
    ui.clear.addEventListener('click', () => {
      ui.log.textContent = '';
      ui.log.appendChild(el('li', 'admin-log-empty t-muted t-small', t('admin.js.c_log_empty')));
    });
  }
  d.addEventListener('visibilitychange', () => {
    if (d.visibilityState === 'visible' && state.running) {
      lockScreen();
      if (!state.inflight) { state.idleDelay = IDLE_MIN; schedule(50); }
    }
  });
  window.addEventListener('beforeunload', (ev) => {
    if (state.running) {
      ev.preventDefault();
      ev.returnValue = '';
    }
  });
  if (!cfg.verifier && ui.verify) ui.verify.checked = false;
  setMode('stopped');
})();
