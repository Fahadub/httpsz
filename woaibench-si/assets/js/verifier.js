/*!
 * WOAIBench.si Verification Station (module: verifier).
 * Runs on a same-origin admin page. Claims runs from the verifier API, loads each output in a
 * sandboxed iframe per viewport (allow-scripts allow-pointer-lock, never allow-same-origin), collects
 * the probe result + an html2canvas screenshot over postMessage and submits them.
 *
 *   WBVerifier.start(containerEl, {concurrency: 2, onLog(entry), onStats(stats)})
 *   WBVerifier.stop()            graceful: in-flight runs finish, nothing new is claimed
 *   WBVerifier.setConcurrency(n) WBVerifier.running (bool) WBVerifier.stats()
 *
 * Without onLog/onStats callbacks a compact stats bar + log is rendered inside the container
 * (used when the Station is embedded in the admin Console).
 */
(function () {
  'use strict';
  if (window.WBVerifier) { return; }

  var SCRIPT = document.currentScript;
  var BASE = (function () {
    var src = (SCRIPT && SCRIPT.src) || '';
    var m = src.match(/^(.*?)\/assets\/js\/verifier\.js(?:[?#].*)?$/);
    return m ? m[1] : '';
  })();
  var DEFAULT_VPS = [{ name: 'desktop', w: 1280, h: 800 }, { name: 'mobile', w: 390, h: 844 }];
  var HELLO_TIMEOUT = 20000;
  var SHOT_TIMEOUT = 12000;
  var TIMING_TYPES = { animates: 1, fps: 1, keyboard: 1, click: 1 };

  /* ------------------------------------------------------------ strings */
  var FALLBACK = {
    'verifier.js.idle': 'Idle', 'verifier.js.waiting': 'Waiting for runs…', 'verifier.js.claimed': 'Claimed',
    'verifier.js.loading': 'Loading', 'verifier.js.checking': 'Checking', 'verifier.js.capturing': 'Screenshot',
    'verifier.js.submitting': 'Submitting', 'verifier.js.done': 'Done', 'verifier.js.released': 'Released',
    'verifier.js.hung': 'Unresponsive', 'verifier.js.run': 'Run', 'verifier.js.tests': 'Tests',
    'verifier.js.log_claimed': 'Run #:id · :task — claimed',
    'verifier.js.log_submitted': 'Run #:id · :task — :passed/:total checks:tests in :secs s → :next',
    'verifier.js.log_released': 'Run #:id released: :error', 'verifier.js.log_hung': 'Run #:id (:vp) — page unresponsive after :secs s',
    'verifier.js.log_api_error': 'API error: :error', 'verifier.js.log_empty': 'Queue empty — checking again in :secs s',
    'verifier.js.log_hidden': 'Tab hidden — paused claiming new runs', 'verifier.js.log_started': 'Station started (concurrency :n)',
    'verifier.js.log_stopping': 'Stopping after the current runs…', 'verifier.js.log_stopped': 'Station stopped',
    'verifier.js.log_auth': 'Not authorised — sign in again', 'verifier.js.processed': 'Processed',
    'verifier.js.pass_rate': 'Checks passed', 'verifier.js.avg_time': 'Avg time', 'verifier.js.released_n': 'Released',
    'verifier.js.slot': 'Slot', 'verifier.js.frame_title': 'Sandboxed preview of run :id (:vp)'
  };
  var STR = null;
  function strings() {
    if (STR) { return STR; }
    STR = {};
    ['vs-i18n', 'wb-i18n'].forEach(function (id) {
      var el = document.getElementById(id);
      if (!el) { return; }
      try {
        var o = JSON.parse(el.textContent || '{}');
        for (var k in o) { if (Object.prototype.hasOwnProperty.call(o, k) && k.indexOf('verifier.js.') === 0) { STR[k] = o[k]; } }
      } catch (e) { /* ignore */ }
    });
    return STR;
  }
  function t(key, vars) {
    var s = strings()['verifier.js.' + key] || FALLBACK['verifier.js.' + key] || key;
    if (vars) { for (var k in vars) { if (Object.prototype.hasOwnProperty.call(vars, k)) { s = s.split(':' + k).join(String(vars[k])); } } }
    return s;
  }

  /* ------------------------------------------------------------ helpers */
  function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
  function trunc(s, n) { s = String(s == null ? '' : s); return s.length > n ? s.slice(0, n - 1) + '…' : s; }
  function num(v, dflt) { v = Number(v); return isFinite(v) ? v : dflt; }
  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text != null) { n.textContent = String(text); }
    return n;
  }
  function csrf() { var m = document.querySelector('meta[name="csrf-token"]'); return m ? m.getAttribute('content') || '' : ''; }

  /** POST JSON to the verifier API (WB.fetchJSON when present; otherwise fetch with the CSRF header). */
  function api(path, body) {
    var url = BASE + path;
    var norm = function (r) { if (!r || typeof r !== 'object') { throw new Error('invalid response'); } if (r.ok === false && r.error) { var e = new Error(String(r.error)); e.status = r.status || 0; throw e; } return r; };
    if (window.WB && typeof window.WB.fetchJSON === 'function') {
      return Promise.resolve().then(function () { return window.WB.fetchJSON(url, { method: 'POST', body: body || {} }); }).then(norm);
    }
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrf() },
      body: JSON.stringify(body || {})
    }).then(function (res) {
      return res.text().then(function (txt) {
        var j = null;
        try { j = JSON.parse(txt); } catch (e) { j = null; }
        if (!res.ok) { var err = new Error((j && j.error) || ('HTTP ' + res.status)); err.status = res.status; throw err; }
        return norm(j);
      });
    });
  }
  function withParams(url, params) {
    var u;
    try { u = new URL(url, window.location.href); } catch (e) { return url; }
    // Load frames from the page's own origin so the probe can address us as parentOrigin.
    if (u.origin !== window.location.origin && /\/render\/\d+/.test(u.pathname)) {
      u = new URL(u.pathname + u.search, window.location.origin);
    }
    for (var k in params) { if (params[k] != null) { u.searchParams.set(k, params[k]); } }
    return u.toString();
  }

  /* ------------------------------------------------------------ result sanitising + merging */
  function cleanCheck(r, def) {
    var id = String(def.id || def.type);
    if (!r || typeof r !== 'object') { return { id: id, type: String(def.type), pass: false, score: 0, detail: 'not evaluated' }; }
    var o = { id: id, type: String(def.type), pass: r.pass === true, score: Math.max(0, Math.min(1, num(r.score, 0))), detail: trunc(r.detail, 300) };
    if (typeof r.value === 'number' && isFinite(r.value)) { o.value = r.value; }
    return o;
  }
  function cleanTests(list) {
    if (!Array.isArray(list)) { return []; }
    return list.slice(0, 500).map(function (r) {
      r = r && typeof r === 'object' ? r : {};
      var o = { name: trunc(r.name, 200), pass: r.pass === true, ms: Math.max(0, num(r.ms, 0)) };
      if (r.got !== undefined) { o.got = trunc(typeof r.got === 'string' ? r.got : JSON.stringify(r.got), 300); }
      if (r.error) { o.error = trunc(r.error, 300); }
      return o;
    });
  }
  function cleanMetrics(m) {
    var o = {};
    if (!m || typeof m !== 'object') { return o; }
    Object.keys(m).slice(0, 30).forEach(function (k) {
      var v = m[k];
      if (typeof v === 'number' && isFinite(v)) { o[k] = v; } else if (typeof v === 'boolean' || v === null) { o[k] = v; } else if (typeof v === 'string') { o[k] = trunc(v, 60); }
    });
    return o;
  }
  function find(list, id) {
    if (!Array.isArray(list)) { return null; }
    for (var i = 0; i < list.length; i++) { if (list[i] && String(list[i].id) === id) { return list[i]; } }
    return null;
  }
  /** Responsive comes from the mobile pass, everything else from the primary (desktop) pass. */
  function merge(run, vps, out) {
    var primary = out[vps[0].name] || null;
    var mobile = out.mobile || (vps[1] ? out[vps[1].name] : null) || null;
    var base = primary || mobile || {};
    var checks = (run.checks || []).filter(function (c) { return c && c.type; }).map(function (c) {
      var id = String(c.id || c.type);
      var src = c.type === 'responsive' && mobile ? mobile : base;
      return cleanCheck(find(src.checks, id) || find(base.checks, id), c);
    });
    var errors = [];
    vps.forEach(function (vp, i) {
      var r = out[vp.name];
      ((r && r.errors) || []).forEach(function (e) {
        var s = trunc((i > 0 ? '[' + vp.name + '] ' : '') + e, 300);
        if (errors.length < 20 && errors.indexOf(s) < 0) { errors.push(s); }
      });
    });
    var metrics = cleanMetrics(base.metrics);
    vps.slice(1).forEach(function (vp) {
      var m = out[vp.name] && out[vp.name].metrics;
      if (m) {
        metrics[vp.name + '_load_ms'] = num(m.load_ms, null);
        metrics[vp.name + '_js_errors'] = num(m.js_errors, 0);
      }
    });
    return { checks: checks, tests: cleanTests(base.tests), errors: errors, metrics: metrics };
  }
  function hungResult(run, vp, secs) {
    var detail = 'page unresponsive: no probe result within ' + secs + ' s (' + vp.name + ')';
    return {
      checks: (run.checks || []).map(function (c) { return { id: String(c.id || c.type), type: c.type, pass: false, score: 0, detail: detail }; }),
      tests: run.tests && Array.isArray(run.tests.cases) ? run.tests.cases.map(function (c, i) { return { name: String(c.name || 'case ' + (i + 1)), pass: false, ms: 0, error: 'page unresponsive' }; }) : [],
      errors: ['Probe timeout: the page did not respond within ' + secs + ' s (infinite loop or blocked main thread)'],
      metrics: { load_ms: null, fps: 0, js_errors: 0, console_errors: 0, hung: true }
    };
  }

  /* ------------------------------------------------------------ Station */
  var station = null;

  function Station(container, opts) {
    this.container = container;
    this.opts = opts || {};
    this.concurrency = Math.max(1, Math.min(4, num(this.opts.concurrency, 2)));
    this.running = false;
    this.stopping = false;
    this.slots = [];
    this.inflight = {};
    this.logLines = [];
    this.st = { processed: 0, released: 0, hung: 0, checks_passed: 0, checks_total: 0, tests_passed: 0, tests_total: 0, total_ms: 0, active: 0, avg_ms: 0, pass_rate: null };
    this.hiddenLogged = false;
    this.build();
  }
  Station.prototype.build = function () {
    var c = this.container;
    c.classList.add('vs-host');
    this.root = el('div', 'vs-slots');
    this.root.setAttribute('aria-live', 'off');
    c.textContent = '';
    c.appendChild(this.root);
    if (!this.opts.onLog || !this.opts.onStats) {
      this.mini = el('div', 'vs-mini');
      this.miniStats = el('div', 'vs-mini-stats t-small');
      this.miniLog = el('ol', 'vs-log vs-mini-log');
      this.mini.appendChild(this.miniStats);
      this.mini.appendChild(this.miniLog);
      c.appendChild(this.mini);
    }
    var self = this;
    if ('IntersectionObserver' in window) {
      // Off-screen cross-origin frames get throttled: dock the live previews when scrolled away.
      this.io = new IntersectionObserver(function (entries) {
        var e = entries[entries.length - 1];
        var dock = self.running && e && !e.isIntersecting;
        if (dock && !self.root.classList.contains('is-docked')) { c.style.minHeight = c.offsetHeight + 'px'; }
        if (!dock) { c.style.minHeight = ''; }
        self.root.classList.toggle('is-docked', dock);
      });
      this.io.observe(c);
    }
  };
  Station.prototype.log = function (level, msg) {
    var entry = { time: new Date(), level: level, msg: msg };
    if (this.opts.onLog) { try { this.opts.onLog(entry); } catch (e) { /* ignore */ } }
    if (this.miniLog) {
      var li = el('li', 'vs-log-' + level);
      li.appendChild(el('time', 't-mono', entry.time.toLocaleTimeString()));
      li.appendChild(el('span', '', msg));
      this.miniLog.insertBefore(li, this.miniLog.firstChild);
      while (this.miniLog.children.length > 60) { this.miniLog.removeChild(this.miniLog.lastChild); }
    }
  };
  Station.prototype.emitStats = function () {
    var s = this.st;
    s.active = Object.keys(this.inflight).length;
    s.avg_ms = s.processed ? Math.round(s.total_ms / s.processed) : 0;
    s.pass_rate = s.checks_total ? Math.round(1000 * s.checks_passed / s.checks_total) / 10 : null;
    var copy = {};
    for (var k in s) { if (Object.prototype.hasOwnProperty.call(s, k)) { copy[k] = s[k]; } }
    if (this.opts.onStats) { try { this.opts.onStats(copy); } catch (e) { /* ignore */ } }
    if (this.miniStats) {
      this.miniStats.textContent = t('processed') + ': ' + s.processed + ' · ' + t('pass_rate') + ': ' + (s.pass_rate === null ? '—' : s.pass_rate + '%') +
        ' · ' + t('avg_time') + ': ' + (s.avg_ms ? (s.avg_ms / 1000).toFixed(1) + ' s' : '—') + ' · ' + t('released_n') + ': ' + s.released;
    }
  };
  Station.prototype.state = function (name) {
    if (this.opts.onState) { try { this.opts.onState(name); } catch (e) { /* ignore */ } }
  };
  Station.prototype.start = function () {
    if (this.running) { return; }
    this.running = true;
    this.stopping = false;
    this.log('info', t('log_started', { n: this.concurrency }));
    this.state('running');
    this.wake();
    this.ensureSlots();
    this.emitStats();
  };
  /** Keep exactly `concurrency` lanes active: revive parked lanes, add new ones, retire extras. */
  Station.prototype.ensureSlots = function () {
    var self = this;
    var active = function () { return self.slots.filter(function (s) { return s.active && !s.retire; }).length; };
    this.slots.forEach(function (s) {
      if (s.retire && s.active && active() < self.concurrency) { s.retire = false; }
      if (!s.active && active() < self.concurrency) { s.active = true; s.retire = false; s.step(); }
      else if (s.active && !s.retire) { s.kick(); }
    });
    while (active() < this.concurrency) {
      var slot = new Slot(this, this.slots.length);
      this.slots.push(slot);
      slot.active = true;
      slot.step();
    }
    var live = this.slots.filter(function (s) { return s.active && !s.retire; });
    for (var i = this.concurrency; i < live.length; i++) { live[i].retire = true; live[i].kick(); }
  };
  Station.prototype.setConcurrency = function (n) {
    this.concurrency = Math.max(1, Math.min(4, num(n, 2)));
    if (this.running) { this.ensureSlots(); }
  };
  Station.prototype.stop = function () {
    if (!this.running) { return; }
    this.stopping = true;
    this.running = false;
    var busy = Object.keys(this.inflight).length > 0;
    this.log('info', busy ? t('log_stopping') : t('log_stopped'));
    this.state(busy ? 'stopping' : 'idle');
    this.slots.forEach(function (s) { s.kick(); });
    if (this.lock) { try { this.lock.release(); } catch (e) { /* ignore */ } this.lock = null; }
    this.emitStats();
  };
  Station.prototype.wake = function () {
    var self = this;
    try {
      if (navigator.wakeLock && navigator.wakeLock.request) {
        navigator.wakeLock.request('screen').then(function (l) { self.lock = l; }, function () { /* ignore */ });
      }
    } catch (e) { /* ignore */ }
  };
  Station.prototype.slotDone = function () {
    if (!this.running && this.stopping && !Object.keys(this.inflight).length) {
      this.stopping = false;
      this.log('info', t('log_stopped'));
      this.state('idle');
    }
    this.emitStats();
  };
  /** Release every leased run (page unload): sendBeacon carries the CSRF token in the JSON body. */
  Station.prototype.releaseAll = function (reason) {
    var self = this;
    Object.keys(this.inflight).forEach(function (id) {
      var run = self.inflight[id];
      var body = JSON.stringify({ run_id: run.id, lock_token: run.lock_token, error: reason, _csrf: csrf() });
      try { navigator.sendBeacon(BASE + '/api/verifier/release', new Blob([body], { type: 'application/json' })); } catch (e) { /* ignore */ }
    });
    this.inflight = {};
  };

  /* ------------------------------------------------------------ Slot (one concurrent lane) */
  function Slot(stationRef, index) {
    this.s = stationRef;
    this.index = index;
    this.retire = false;
    this.active = false;
    this.busy = false;
    this.timer = null;
    this.idle = 3000;
    this.card = el('article', 'vs-slot is-idle');
    var head = el('header', 'vs-slot-head');
    this.no = el('span', 'vs-slot-no t-mono', String(index + 1).padStart(2, '0'));
    var meta = el('div', 'vs-slot-meta');
    this.title = el('div', 'vs-slot-title', t('waiting'));
    this.sub = el('div', 'vs-slot-sub t-mono t-xs', '—');
    meta.appendChild(this.title);
    meta.appendChild(this.sub);
    this.phase = el('span', 'badge badge-muted vs-slot-phase', t('idle'));
    head.appendChild(this.no);
    head.appendChild(meta);
    head.appendChild(this.phase);
    this.devices = el('div', 'vs-devices');
    var prog = el('div', 'progress vs-progress');
    this.bar = el('div', 'progress-bar');
    this.bar.style.width = '0%';
    prog.appendChild(this.bar);
    this.checks = el('ul', 'vs-checks');
    this.card.appendChild(head);
    this.card.appendChild(this.devices);
    this.card.appendChild(prog);
    this.card.appendChild(this.checks);
    this.s.root.appendChild(this.card);
    this.buildDevices(DEFAULT_VPS);
  }
  Slot.prototype.setPhase = function (label, variant, progress) {
    this.phase.textContent = label;
    this.phase.className = 'badge badge-' + (variant || 'muted') + ' vs-slot-phase';
    if (progress != null) { this.bar.style.width = Math.round(Math.max(0, Math.min(1, progress)) * 100) + '%'; }
  };
  Slot.prototype.buildDevices = function (vps) {
    this.devices.textContent = '';
    this.screens = {};
    var self = this;
    vps.forEach(function (vp) {
      var fig = el('figure', 'vs-device vs-device-' + (vp.w < 700 ? 'mobile' : 'desktop'));
      var screen = el('div', 'vs-screen');
      screen.style.aspectRatio = vp.w + ' / ' + vp.h;
      screen.appendChild(el('div', 'vs-screen-empty'));
      var cap = el('figcaption', 't-xs t-mono', vp.name + ' · ' + vp.w + '×' + vp.h);
      fig.appendChild(screen);
      fig.appendChild(cap);
      self.devices.appendChild(fig);
      self.screens[vp.name] = { fig: fig, screen: screen, vp: vp };
    });
  };
  Slot.prototype.reset = function () {
    this.card.className = 'vs-slot is-idle';
    this.title.textContent = t('waiting');
    this.sub.textContent = '—';
    this.setPhase(t('idle'), 'muted', 0);
  };
  /** Wake a lane that is sleeping between polls (start/stop/concurrency changes). */
  Slot.prototype.kick = function () {
    if (this.timer && !this.busy) {
      clearTimeout(this.timer);
      this.timer = null;
      this.idle = 3000;
      this.step();
    }
  };
  Slot.prototype.later = function (ms) {
    var self = this;
    this.timer = setTimeout(function () { self.timer = null; self.step(); }, ms);
  };
  /** One iteration of the lane loop: claim a run (or wait), process it, schedule the next step. */
  Slot.prototype.step = function () {
    var self = this;
    var st = this.s;
    if (this.busy) { return; }
    if (!st.running || this.retire) {
      this.active = false;
      this.reset();
      if (this.retire) {
        if (this.card.parentNode) { this.card.parentNode.removeChild(this.card); }
        st.slots.splice(st.slots.indexOf(this), 1);
      }
      return;
    }
    if (document.hidden) {
      if (!st.hiddenLogged) { st.hiddenLogged = true; st.log('warn', t('log_hidden')); }
      this.later(1000);
      return;
    }
    st.hiddenLogged = false;
    this.busy = true;
    api('/api/verifier/next', { agent: 'station' }).then(function (res) {
      if (!res.run) {
        self.busy = false;
        if (self.index === 0 && self.idle >= 12000) { st.log('info', t('log_empty', { secs: Math.round(self.idle / 1000) })); }
        self.reset();
        self.later(self.idle);
        self.idle = Math.min(30000, Math.round(self.idle * 1.6));
        return;
      }
      self.idle = 3000;
      return self.process(res.run).then(function () { self.busy = false; self.later(250); });
    }).catch(function (e) {
      self.busy = false;
      if (e && (e.status === 401 || e.status === 403 || e.status === 419)) {
        st.log('error', t('log_auth'));
        st.stop();
        self.step();
        return;
      }
      st.log('error', t('log_api_error', { error: e && e.message ? e.message : String(e) }));
      self.later(8000);
    });
  };
  Slot.prototype.process = function (run) {
    var self = this;
    var st = this.s;
    var t0 = performance.now();
    var vps = Array.isArray(run.viewports) && run.viewports.length ? run.viewports.slice(0, 4).map(function (v) {
      return { name: String(v.name || 'desktop').replace(/[^a-z0-9_-]/gi, '').slice(0, 20) || 'desktop', w: Math.max(200, Math.min(2560, num(v.w, 1280))), h: Math.max(200, Math.min(2000, num(v.h, 800))) };
    }) : DEFAULT_VPS;
    var taskTitle = (run.task && (run.task.title || run.task.slug)) || ('#' + run.id);
    st.inflight[run.id] = run;
    st.emitStats();
    this.card.className = 'vs-slot is-busy';
    this.title.textContent = taskTitle;
    this.sub.textContent = t('run') + ' #' + run.id + ' · ' + (run.output_type || 'html');
    this.checks.textContent = '';
    this.buildDevices(vps);
    this.setPhase(t('claimed'), 'gold', 0.03);
    st.log('info', t('log_claimed', { id: run.id, task: taskTitle }));
    var out = {};
    var shots = {};
    var i = 0;
    var finish = function () {
      delete st.inflight[run.id];
      st.slotDone();
    };
    var release = function (error) {
      self.setPhase(t('released'), 'red', 1);
      self.card.className = 'vs-slot is-error';
      st.st.released++;
      st.log('warn', t('log_released', { id: run.id, error: error }));
      return api('/api/verifier/release', { run_id: run.id, lock_token: run.lock_token, error: trunc(error, 500) })
        .catch(function (e) { st.log('error', t('log_api_error', { error: e && e.message })); })
        .then(finish);
    };
    var nextVp = function () {
      if (i >= vps.length) { return Promise.resolve(null); }
      var vp = vps[i];
      var light = i > 0;
      var idx = i++;
      return self.runViewport(run, vp, light, idx, vps.length).then(function (r) {
        if (r.status === 'infra') { return r; }
        out[vp.name] = r.result;
        if (r.shot) { shots[vp.name] = r.shot; }
        if (idx === 0) { self.showChecks(run, r.result); }
        if (r.result && r.result.metrics && r.result.metrics.hung) { return null; }
        return nextVp();
      });
    };
    return nextVp().then(function (fail) {
      if (fail) { return release(fail.error); }
      var results = merge(run, vps, out);
      var m = results.metrics;
      var timing = (run.checks || []).some(function (c) { return c && TIMING_TYPES[c.type]; });
      if (timing && (m.hidden === true || m.offscreen === true)) {
        return release('station frame was hidden or off-screen during timing checks');
      }
      self.setPhase(t('submitting'), 'blue', 0.94);
      var payload = { run_id: run.id, lock_token: run.lock_token, agent: 'station', results: results, screenshots: shots };
      var submit = function (attempt) {
        return api('/api/verifier/submit', payload).catch(function (e) {
          if (attempt < 1 && !(e && e.status && e.status < 500)) { return sleep(1500).then(function () { return submit(attempt + 1); }); }
          throw e;
        });
      };
      return submit(0).then(function (res) {
        var ms = performance.now() - t0;
        var passed = results.checks.filter(function (c) { return c.pass; }).length;
        var tPass = results.tests.filter(function (x) { return x.pass; }).length;
        st.st.processed++;
        st.st.total_ms += ms;
        st.st.checks_passed += passed;
        st.st.checks_total += results.checks.length;
        st.st.tests_passed += tPass;
        st.st.tests_total += results.tests.length;
        if (m.hung) { st.st.hung++; }
        self.setPhase(t('done'), passed === results.checks.length && tPass === results.tests.length ? 'green' : 'gold', 1);
        self.card.className = 'vs-slot is-done';
        st.log(passed === results.checks.length ? 'ok' : 'info', t('log_submitted', {
          id: run.id, task: taskTitle, passed: passed, total: results.checks.length,
          tests: results.tests.length ? ' · ' + t('tests') + ' ' + tPass + '/' + results.tests.length : '',
          secs: (ms / 1000).toFixed(1), next: (res && res.next_status) || 'ok'
        }));
        finish();
      }, function (e) {
        return release('submit failed: ' + (e && e.message ? e.message : String(e)));
      });
    }, function (e) {
      return release('station error: ' + (e && e.message ? e.message : String(e)));
    });
  };
  Slot.prototype.showChecks = function (run, result) {
    var list = this.checks;
    list.textContent = '';
    var defs = (run.checks || []).filter(function (c) { return c && c.type; });
    defs.forEach(function (c) {
      var r = find(result && result.checks, String(c.id || c.type));
      var li = el('li', 'vs-check ' + (r && r.pass ? 'is-pass' : 'is-fail'));
      li.appendChild(el('span', 'vs-check-dot'));
      li.appendChild(el('span', 'vs-check-id', c.id || c.type));
      li.title = r ? trunc(r.detail, 300) : '';
      li.setAttribute('data-tip', li.title);
      list.appendChild(li);
    });
    var tests = (result && result.tests) || [];
    if (tests.length) {
      var p = tests.filter(function (x) { return x && x.pass; }).length;
      var li = el('li', 'vs-check vs-check-tests ' + (p === tests.length ? 'is-pass' : 'is-fail'));
      li.appendChild(el('span', 'vs-check-dot'));
      li.appendChild(el('span', 'vs-check-id', t('tests') + ' ' + p + '/' + tests.length));
      list.appendChild(li);
    }
  };
  /** Load one viewport: hello → probe result → screenshot. Resolves {status:'ok'|'infra', result, shot, error}. */
  Slot.prototype.runViewport = function (run, vp, light, idx, count) {
    var self = this;
    var st = this.s;
    var slot = this.screens[vp.name];
    var span = 0.9 / count;
    var base = 0.04 + idx * span;
    return new Promise(function (resolve) {
      var frame = document.createElement('iframe');
      frame.setAttribute('sandbox', 'allow-scripts allow-pointer-lock');
      frame.setAttribute('referrerpolicy', 'no-referrer');
      frame.setAttribute('loading', 'eager');
      frame.setAttribute('scrolling', 'no');
      frame.setAttribute('tabindex', '-1');
      frame.setAttribute('title', t('frame_title', { id: run.id, vp: vp.name }));
      frame.className = 'vs-frame';
      frame.width = String(vp.w);
      frame.height = String(vp.h);
      frame.style.width = vp.w + 'px';
      frame.style.height = vp.h + 'px';
      var screen = slot.screen;
      screen.textContent = '';
      screen.classList.add('is-live');
      screen.appendChild(frame);
      var fit = function () {
        var w = screen.clientWidth || 1;
        frame.style.transform = 'scale(' + (w / vp.w) + ')';
      };
      var ro = 'ResizeObserver' in window ? new ResizeObserver(fit) : null;
      if (ro) { ro.observe(screen); }
      fit();
      var nonce = null;
      var result = null;
      var done = false;
      var timers = [];
      var later = function (fn, ms) { timers.push(setTimeout(fn, ms)); };
      var cleanup = function (shot) {
        window.removeEventListener('message', onMsg);
        timers.forEach(clearTimeout);
        if (ro) { ro.disconnect(); }
        screen.classList.remove('is-live');
        screen.textContent = '';
        if (shot) {
          var img = el('img', 'vs-shot');
          img.alt = '';
          img.src = shot;
          screen.appendChild(img);
        } else {
          screen.appendChild(el('div', 'vs-screen-empty'));
        }
      };
      var finish = function (status, payload) {
        if (done) { return; }
        done = true;
        cleanup(payload && payload.shot);
        resolve({ status: status, result: payload && payload.result, shot: payload && payload.shot, error: payload && payload.error });
      };
      var requestShot = function () {
        self.setPhase(t('capturing') + ' · ' + vp.name, 'violet', base + span * 0.8);
        later(function () { finish('ok', { result: result, shot: null }); }, SHOT_TIMEOUT);
        try { frame.contentWindow.postMessage({ type: 'wb-shot' }, '*'); } catch (e) { finish('ok', { result: result, shot: null }); }
      };
      var onMsg = function (ev) {
        if (done || ev.source !== frame.contentWindow) { return; }
        var d = ev.data;
        if (!d || typeof d !== 'object' || typeof d.type !== 'string') { return; }
        if (d.type === 'wb-probe-hello') {
          if (nonce !== null || typeof d.nonce !== 'string' || !d.nonce) { return; }
          nonce = d.nonce;
          var secs = Math.round((Math.min(160000, Math.max(5000, num(d.deadline_ms, 15000))) + 6000) / 1000);
          self.setPhase(t('checking') + ' · ' + vp.name, 'gold', base + span * 0.25);
          later(function () {
            if (result) { return; }
            st.log('warn', t('log_hung', { id: run.id, vp: vp.name, secs: secs }));
            finish('ok', { result: hungResult(run, vp, secs), shot: null });
          }, secs * 1000);
          return;
        }
        if (nonce === null || d.nonce !== nonce) { return; }
        if (d.type === 'wb-probe' && !result) {
          result = d;
          requestShot();
          return;
        }
        if (d.type === 'wb-shot' && result) {
          var shot = typeof d.dataUrl === 'string' && /^data:image\/(png|webp|jpeg);base64,[A-Za-z0-9+/=]+$/.test(d.dataUrl) && d.dataUrl.length < 5600000 ? d.dataUrl : null;
          finish('ok', { result: result, shot: shot });
        }
      };
      window.addEventListener('message', onMsg);
      later(function () {
        if (nonce === null) { finish('infra', { error: 'probe did not start in the ' + vp.name + ' frame within ' + (HELLO_TIMEOUT / 1000) + ' s' }); }
      }, HELLO_TIMEOUT);
      self.setPhase(t('loading') + ' · ' + vp.name, 'blue', base + span * 0.08);
      frame.src = withParams(run.render_url, { vp: vp.name, mode: 'station', light: light ? '1' : null });
    });
  };

  /* ------------------------------------------------------------ public API */
  var publicApi = {
    start: function (containerEl, opts) {
      if (!containerEl) { throw new Error('WBVerifier.start: container element required'); }
      if (station && station.container !== containerEl) { station.stop(); }
      if (!station || station.container !== containerEl) { station = new Station(containerEl, opts); }
      else if (opts) { station.opts = opts; station.setConcurrency(opts.concurrency); }
      station.start();
      return station;
    },
    stop: function () { if (station) { station.stop(); } },
    setConcurrency: function (n) { if (station) { station.setConcurrency(n); } },
    stats: function () { return station ? station.st : null; },
    merge: merge
  };
  Object.defineProperty(publicApi, 'running', { get: function () { return !!(station && station.running); } });
  window.WBVerifier = publicApi;
  window.addEventListener('pagehide', function () { if (station) { station.releaseAll('station page closed'); } });

  /* ------------------------------------------------------------ Station page wiring (/admin/verify) */
  function initPage() {
    var root = document.querySelector('[data-verifier-station]');
    if (!root) { return; }
    var startBtn = root.querySelector('[data-vs-start]');
    var stopBtn = root.querySelector('[data-vs-stop]');
    var conc = root.querySelector('[data-vs-concurrency]');
    var stage = root.querySelector('[data-vs-stage]');
    var logEl = root.querySelector('[data-vs-log]');
    var status = root.querySelector('[data-vs-status]');
    var empty = root.querySelector('[data-vs-empty]');
    var clearBtn = root.querySelector('[data-vs-clear]');
    var stats = {};
    Array.prototype.forEach.call(root.querySelectorAll('[data-vs-stat]'), function (n) { stats[n.getAttribute('data-vs-stat')] = n; });
    try { var saved = localStorage.getItem('wb_vs_concurrency'); if (saved && conc) { conc.value = saved; } } catch (e) { /* ignore */ }
    var setStatus = function (state) {
      root.setAttribute('data-state', state);
      if (status) { status.textContent = status.getAttribute('data-label-' + state) || state; }
      if (startBtn) { startBtn.disabled = state === 'running'; }
      if (stopBtn) { stopBtn.disabled = state !== 'running'; }
    };
    var onLog = function (entry) {
      if (!logEl) { return; }
      var li = el('li', 'vs-log-' + entry.level);
      li.appendChild(el('time', 't-mono', entry.time.toLocaleTimeString()));
      li.appendChild(el('span', '', entry.msg));
      logEl.insertBefore(li, logEl.firstChild);
      while (logEl.children.length > 200) { logEl.removeChild(logEl.lastChild); }
    };
    var onStats = function (s) {
      var put = function (k, v) { if (stats[k]) { stats[k].textContent = v; } };
      put('processed', String(s.processed));
      put('pass_rate', s.pass_rate === null ? '—' : s.pass_rate + '%');
      put('avg', s.avg_ms ? (s.avg_ms / 1000).toFixed(1) + ' s' : '—');
      put('released', String(s.released));
      put('active', String(s.active));
      put('tests', s.tests_total ? s.tests_passed + '/' + s.tests_total : '—');
    };
    if (startBtn) {
      startBtn.addEventListener('click', function () {
        if (empty) { empty.hidden = true; }
        publicApi.start(stage, { concurrency: conc ? Number(conc.value) : 2, onLog: onLog, onStats: onStats, onState: setStatus });
      });
    }
    if (stopBtn) {
      stopBtn.addEventListener('click', function () {
        publicApi.stop();
      });
    }
    if (conc) {
      conc.addEventListener('change', function () {
        try { localStorage.setItem('wb_vs_concurrency', conc.value); } catch (e) { /* ignore */ }
        publicApi.setConcurrency(Number(conc.value));
      });
    }
    if (clearBtn && logEl) { clearBtn.addEventListener('click', function () { logEl.textContent = ''; }); }
    setStatus('idle');
    if (/[?&]autostart=1\b/.test(window.location.search) && startBtn) { startBtn.click(); }
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', initPage); } else { initPage(); }
})();
