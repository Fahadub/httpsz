/*!
 * WOAIBench.si JS test harness (module: verifier).
 * Runs model-written JavaScript against task test cases inside a Worker created from a Blob (within the
 * sandboxed render document). Each case has a hard timeout: a runaway case terminates the worker, which
 * is recreated for the next case. Comparison semantics match tools/dev/validate-tasks.mjs:
 *   deep (structural, NaN-aware, -0 == 0) · approx (absolute tol, recursive) · set / sorted
 *   (order-insensitive multiset at the top level) · truthy.
 * API: window.WBHarness.run(code, tests, {budgetMs}) → Promise<[{name, pass, ms, got?, error?}]>,
 *      WBHarness.render(results), WBHarness.mode ('worker' | 'worker-data' | 'main').
 * The same file powers the public "Run tests" button and the code view on js render pages.
 */
(function (global) {
  'use strict';
  if (global.WBHarness) { return; }
  var D = global.document;

  /**
   * Self-contained core (no outer references): serialised into the Worker, and also callable on the
   * main thread as a last-resort fallback.
   */
  function harnessCore() {
    var hasOwn = Object.prototype.hasOwnProperty;
    var quiet = function () {};
    var QUIET_CONSOLE = { log: quiet, info: quiet, warn: quiet, error: quiet, debug: quiet, table: quiet, dir: quiet, trace: quiet, group: quiet, groupEnd: quiet, time: quiet, timeEnd: quiet, assert: quiet, count: quiet };
    var clock = (typeof performance !== 'undefined' && performance.now) ? function () { return performance.now(); } : function () { return Date.now(); };

    function canon(v) {
      if (v === undefined) { return 'u'; }
      if (typeof v === 'number') { return v !== v ? 'NaN' : (Object.is(v, -0) ? '0' : String(v)); }
      if (v === null || typeof v !== 'object') { var j = JSON.stringify(v); return j === undefined ? String(v) : j; }
      if (Array.isArray(v)) { return '[' + v.map(canon).join(',') + ']'; }
      return '{' + Object.keys(v).filter(function (k) { return v[k] !== undefined; }).sort().map(function (k) { return JSON.stringify(k) + ':' + canon(v[k]); }).join(',') + '}';
    }
    function deepEq(a, b, numEq) {
      if (typeof a === 'number' && typeof b === 'number') { return numEq(a, b); }
      if (a === b) { return true; }
      if (a === null || b === null || typeof a !== 'object' || typeof b !== 'object') { return false; }
      var aa = Array.isArray(a);
      if (aa !== Array.isArray(b)) { return false; }
      var i;
      if (aa) {
        if (a.length !== b.length) { return false; }
        for (i = 0; i < a.length; i++) { if (!deepEq(a[i], b[i], numEq)) { return false; } }
        return true;
      }
      var ka = Object.keys(a).filter(function (k) { return a[k] !== undefined; });
      var kb = Object.keys(b).filter(function (k) { return b[k] !== undefined; });
      if (ka.length !== kb.length) { return false; }
      for (i = 0; i < ka.length; i++) {
        if (!hasOwn.call(b, ka[i]) || !deepEq(a[ka[i]], b[ka[i]], numEq)) { return false; }
      }
      return true;
    }
    function exactNum(a, b) { return a === b || (a !== a && b !== b); }
    function compare(got, expect, cmp, tol) {
      tol = typeof tol === 'number' ? tol : 1e-6;
      switch (cmp || 'deep') {
        case 'approx':
          return deepEq(got, expect, function (a, b) { return exactNum(a, b) || Math.abs(a - b) <= tol; });
        case 'set':
        case 'sorted': {
          if (!Array.isArray(got) || !Array.isArray(expect) || got.length !== expect.length) { return false; }
          var g = got.map(canon).sort();
          var e = expect.map(canon).sort();
          for (var i = 0; i < g.length; i++) { if (g[i] !== e[i]) { return false; } }
          return true;
        }
        case 'truthy':
          return Boolean(got) === Boolean(expect === undefined ? true : expect);
        default:
          return deepEq(got, expect, exactNum);
      }
    }
    function preview(v) {
      var s;
      try {
        s = typeof v === 'string' ? JSON.stringify(v) : (v === undefined ? 'undefined' : JSON.stringify(v, function (k, x) {
          if (typeof x === 'number' && !isFinite(x)) { return String(x); }
          if (typeof x === 'bigint') { return x + 'n'; }
          return x;
        }));
      } catch (e) { s = String(v); }
      if (s === undefined) { s = String(v); }
      return s.length > 300 ? s.slice(0, 297) + '...' : s;
    }
    function errText(e) {
      if (e && e.name && e.message !== undefined) { return String(e.name) + ': ' + String(e.message); }
      try { return String(e); } catch (x) { return 'error'; }
    }
    /** Tolerate ES-module / CommonJS packaging of an otherwise plain script. */
    function prepare(code) {
      code = String(code || '');
      code = code.replace(/^[ \t]*export[ \t]+default[ \t]+(?=(async[ \t]+)?function\b|class\b)/gm, '')
        .replace(/^[ \t]*export[ \t]+(?=(async[ \t]+)?function\b|class\b|const\b|let\b|var\b)/gm, '')
        .replace(/^[ \t]*export[ \t]*\{[^}]*\}[ \t]*;?[ \t]*$/gm, '');
      var cjs = /\bmodule\.exports\b|\bexports\.[A-Za-z_$]/.test(code) && !/\b(?:const|let|var|class|function)\s+(?:module|exports)\b/.test(code);
      return { code: code, cjs: cjs };
    }
    function validEntry(entry) { return /^[A-Za-z_$][\w$]*(?:\.[A-Za-z_$][\w$]*)*$/.test(String(entry || '')); }

    /** Run one case. Resolves to {name, pass, ms, got?, error?}. */
    function runCase(code, entry, c, timeoutMs) {
      var name = String(c && c.name != null ? c.name : 'case');
      var prep = prepare(code);
      var params = prep.cjs ? ['console', '__wb_args', 'module', 'exports'] : ['console', '__wb_args'];
      var mod = { exports: {} };
      var t0 = clock();
      var value;
      try {
        var body;
        if (typeof c.script === 'string') {
          body = prep.code + '\n;return (function(){ ' + c.script + '\n})();';
        } else {
          if (!validEntry(entry)) { throw new TypeError('invalid entry name'); }
          var lookup = '(typeof ' + entry + " === 'function') ? " + entry + ' : undefined';
          if (prep.cjs) {
            lookup = '(' + lookup + ') || (module.exports && typeof module.exports.' + entry.split('.')[0] + " === 'function' ? module.exports." + entry.split('.')[0] +
              " : (typeof module.exports === 'function' ? module.exports : undefined))";
          }
          body = prep.code + '\n;var __wb_f = ' + lookup + ";\nif (!__wb_f) { throw new ReferenceError('entry function " + entry + " not defined'); }\nreturn __wb_f.apply(null, __wb_args);";
        }
        var fn = Function.apply(null, params.concat([body]));
        var args = Array.isArray(c.args) ? JSON.parse(JSON.stringify(c.args)) : [];
        value = prep.cjs ? fn(QUIET_CONSOLE, args, mod, mod.exports) : fn(QUIET_CONSOLE, args);
      } catch (e) {
        return Promise.resolve({ name: name, pass: false, ms: round(clock() - t0), error: errText(e) });
      }
      var finish = function (v) {
        var ms = clock() - t0;
        var pass = compare(v, c.expect, c.cmp || 'deep', typeof c.tol === 'number' ? c.tol : 1e-6) && ms <= timeoutMs;
        var r = { name: name, pass: pass, ms: round(ms) };
        if (!pass) { r.got = preview(v); }
        if (ms > timeoutMs) { r.error = 'timeout'; }
        return r;
      };
      if (value && typeof value.then === 'function') {
        var left = Math.max(1, timeoutMs - (clock() - t0));
        return new Promise(function (resolve) {
          var timer = setTimeout(function () { resolve({ name: name, pass: false, ms: round(clock() - t0), error: 'timeout (async)' }); }, left);
          Promise.resolve(value).then(function (v) { clearTimeout(timer); resolve(finish(v)); }, function (e) {
            clearTimeout(timer);
            resolve({ name: name, pass: false, ms: round(clock() - t0), error: errText(e) });
          });
        });
      }
      return Promise.resolve(finish(value));
    }
    function round(ms) { return Math.round(ms * 100) / 100; }

    /** Worker message loop. */
    function listen(scope) {
      scope.onmessage = function (ev) {
        var d = ev.data || {};
        if (d.type !== 'case') { return; }
        runCase(d.code, d.entry, d.c || {}, d.timeout).then(function (r) {
          scope.postMessage({ type: 'result', id: d.id, r: r });
        });
      };
      scope.postMessage({ type: 'ready' });
    }
    return { runCase: runCase, compare: compare, listen: listen };
  }

  var WORKER_SRC = '(' + harnessCore.toString() + ')().listen(self);';
  var core = null;

  /* ------------------------------------------------------------ worker management */
  var methods = ['blob', 'data', 'main'];
  var methodIdx = 0;
  var api = { mode: null };

  function makeWorker(method) {
    if (method === 'blob') {
      var url = URL.createObjectURL(new Blob([WORKER_SRC], { type: 'text/javascript' }));
      var w = new Worker(url);
      w.__wbUrl = url;
      return w;
    }
    return new Worker('data:text/javascript;charset=utf-8,' + encodeURIComponent(WORKER_SRC));
  }
  function killWorker(w) {
    if (!w) { return; }
    try { w.terminate(); } catch (e) { /* ignore */ }
    if (w.__wbUrl) { try { URL.revokeObjectURL(w.__wbUrl); } catch (e) { /* ignore */ } }
  }
  /** Resolve to a ready Worker, or null when only main-thread execution is possible. */
  function spawn() {
    return new Promise(function (resolve) {
      var tryNext = function () {
        var method = methods[methodIdx];
        if (method === 'main') { api.mode = 'main'; resolve(null); return; }
        var w;
        try { w = makeWorker(method); } catch (e) { methodIdx++; tryNext(); return; }
        var settled = false;
        var fail = function () {
          if (settled) { return; }
          settled = true;
          killWorker(w);
          methodIdx++;
          tryNext();
        };
        var timer = setTimeout(fail, 4000);
        w.onerror = function (ev) { if (ev && ev.preventDefault) { ev.preventDefault(); } clearTimeout(timer); fail(); };
        w.onmessage = function (ev) {
          if (ev.data && ev.data.type === 'ready' && !settled) {
            settled = true;
            clearTimeout(timer);
            api.mode = method === 'blob' ? 'worker' : 'worker-data';
            resolve(w);
          }
        };
      };
      tryNext();
    });
  }

  var seq = 0;
  function runInWorker(w, code, entry, c, timeoutMs, graceMs) {
    return new Promise(function (resolve) {
      var id = ++seq;
      var done = false;
      var t0 = Date.now();
      var finish = function (r, dead) { if (done) { return; } done = true; clearTimeout(timer); resolve({ r: r, dead: dead }); };
      var timer = setTimeout(function () {
        finish({ name: String(c.name != null ? c.name : 'case'), pass: false, ms: timeoutMs, error: 'timeout (exceeded ' + timeoutMs + ' ms, worker terminated)' }, true);
      }, timeoutMs + graceMs);
      w.onmessage = function (ev) { if (ev.data && ev.data.type === 'result' && ev.data.id === id) { finish(ev.data.r, false); } };
      w.onerror = function (ev) {
        if (ev && ev.preventDefault) { ev.preventDefault(); }
        finish({ name: String(c.name != null ? c.name : 'case'), pass: false, ms: Date.now() - t0, error: 'worker error: ' + (ev && ev.message ? ev.message : 'crashed') }, true);
      };
      try {
        w.postMessage({ type: 'case', id: id, code: code, entry: entry, c: c, timeout: timeoutMs });
      } catch (e) {
        finish({ name: String(c.name != null ? c.name : 'case'), pass: false, ms: 0, error: 'could not send case: ' + (e && e.message) }, true);
      }
    });
  }
  function runOnMain(code, entry, c, timeoutMs) {
    core = core || harnessCore();
    return new Promise(function (resolve) { setTimeout(resolve, 0); }).then(function () {
      return core.runCase(code, entry, c, timeoutMs);
    });
  }

  /**
   * Run every case of a test suite. tests = {entry, timeout_ms, cases:[…]}; opts.budgetMs caps the total
   * time (cases not reached fail with "not run: time budget exhausted").
   */
  function run(code, tests, opts) {
    opts = opts || {};
    tests = tests || {};
    var cases = Array.isArray(tests.cases) ? tests.cases.slice(0, 500) : [];
    var entry = String(tests.entry || 'solve');
    var timeoutMs = Math.max(50, Math.min(30000, Number(tests.timeout_ms) || 2000));
    var budget = Number(opts.budgetMs) > 0 ? Number(opts.budgetMs) : Infinity;
    var started = Date.now();
    var results = [];
    var worker = null;
    var fresh = true;
    var i = 0;
    var next = function () {
      if (i >= cases.length) { killWorker(worker); return Promise.resolve(results); }
      var c = cases[i] && typeof cases[i] === 'object' ? cases[i] : {};
      var name = String(c.name != null ? c.name : 'case ' + (i + 1));
      i++;
      if (Date.now() - started > budget) {
        results.push({ name: name, pass: false, ms: 0, error: 'not run: time budget exhausted' });
        return next();
      }
      var caseTimeout = Number(c.timeout_ms) > 0 ? Math.min(30000, Number(c.timeout_ms)) : timeoutMs;
      var ready = worker || api.mode === 'main' ? Promise.resolve(worker) : spawn().then(function (w) { worker = w; fresh = true; return w; });
      return ready.then(function (w) {
        if (!w) { return runOnMain(code, entry, c, caseTimeout).then(function (r) { return { r: r, dead: false }; }); }
        var grace = fresh ? 600 : 250;
        fresh = false;
        return runInWorker(w, code, entry, c, caseTimeout, grace);
      }).then(function (out) {
        var r = out.r || {};
        r.name = name;
        results.push(r);
        if (out.dead) { killWorker(worker); worker = null; }
        return next();
      });
    };
    return next();
  }

  /* ------------------------------------------------------------ rendering (public page + verify mode) */
  function data() {
    var el = D && D.getElementById('wb-harness-data');
    if (!el) { return null; }
    try { return JSON.parse(el.textContent); } catch (e) { return null; }
  }
  function txt(s) { return String(s == null ? '' : s); }
  function el(tag, cls, text) {
    var n = D.createElement(tag);
    if (cls) { n.className = cls; }
    if (text != null) { n.textContent = txt(text); }
    return n;
  }
  function render(results) {
    var box = D.getElementById('wb-results');
    if (!box) { return; }
    var i18n = (data() || {}).i18n || {};
    var passed = results.filter(function (r) { return r && r.pass; }).length;
    box.textContent = '';
    var head = el('div', 'wb-panel-head');
    var sum = el('span', 'wb-sum ' + (passed === results.length ? 'ok' : 'bad'), txt(i18n.passed || ':p / :n passed').replace(':p', passed).replace(':n', results.length));
    head.appendChild(sum);
    head.appendChild(el('span', '', api.mode === 'main' ? txt(i18n.no_worker) : ''));
    box.appendChild(head);
    var table = el('table');
    var thead = el('thead');
    var hr = el('tr');
    ['', i18n.case || 'Case', i18n.time || 'Time', i18n.result || 'Result'].forEach(function (h) { hr.appendChild(el('th', '', h)); });
    thead.appendChild(hr);
    table.appendChild(thead);
    var tb = el('tbody');
    results.forEach(function (r) {
      var tr = el('tr');
      var st = el('td', r.pass ? 'wb-ok' : 'wb-bad', r.pass ? '✓' : '✗');
      st.setAttribute('aria-label', r.pass ? txt(i18n.pass || 'pass') : txt(i18n.fail || 'fail'));
      tr.appendChild(st);
      tr.appendChild(el('td', '', r.name));
      tr.appendChild(el('td', 'ms', (Math.round((Number(r.ms) || 0) * 10) / 10) + ' ms'));
      var td = el('td');
      if (r.error) { td.appendChild(el('code', 'wb-bad', r.error)); }
      if (r.got !== undefined) {
        if (r.error) { td.appendChild(D.createTextNode(' ')); }
        td.appendChild(el('code', '', '→ ' + r.got));
      }
      if (!r.error && r.got === undefined) { td.appendChild(el('span', 'wb-ok', i18n.pass || 'pass')); }
      tr.appendChild(td);
      tb.appendChild(tr);
    });
    table.appendChild(tb);
    box.appendChild(table);
    box.hidden = false;
  }

  /** Lightweight JS syntax colouring of the code view (DOM nodes built with textContent only). */
  var TOKEN = /(\/\/[^\n]*|\/\*[\s\S]*?\*\/)|("(?:\\.|[^"\\\n])*"|'(?:\\.|[^'\\\n])*'|`(?:\\[\s\S]|[^`\\])*`)|(\b(?:0[xX][\da-fA-F]+|\d[\d_]*(?:\.\d+)?(?:[eE][+-]?\d+)?n?)\b)|(\b(?:const|let|var|function|return|if|else|for|while|do|break|continue|new|class|extends|super|this|import|export|from|default|switch|case|throw|try|catch|finally|typeof|instanceof|in|of|async|await|yield|null|undefined|true|false|delete|void|static|get|set)\b)/g;
  function highlight() {
    var pre = D.getElementById('wb-code');
    if (!pre || pre.getAttribute('data-hl')) { return; }
    var src = pre.textContent;
    pre.setAttribute('data-hl', '1');
    var tokens = [];
    if (src.length <= 300000) {
      var last = 0;
      var m;
      TOKEN.lastIndex = 0;
      while ((m = TOKEN.exec(src))) {
        if (m.index > last) { tokens.push(['', src.slice(last, m.index)]); }
        tokens.push([m[1] ? 'c' : m[2] ? 's' : m[3] ? 'n' : 'k', m[0]]);
        last = TOKEN.lastIndex;
      }
      if (last < src.length) { tokens.push(['', src.slice(last)]); }
    } else {
      tokens.push(['', src]);
    }
    var frag = D.createDocumentFragment();
    var line = el('span', 'l');
    tokens.forEach(function (t) {
      var parts = t[1].split('\n');
      parts.forEach(function (part, idx) {
        if (idx > 0) {
          frag.appendChild(line);
          frag.appendChild(D.createTextNode('\n'));
          line = el('span', 'l');
        }
        if (part !== '') { line.appendChild(t[0] ? el('span', t[0], part) : D.createTextNode(part)); }
      });
    });
    frag.appendChild(line);
    var code = el('code');
    code.appendChild(frag);
    pre.textContent = '';
    pre.appendChild(code);
  }
  function mount() {
    highlight();
    var d = data();
    var btn = D.getElementById('wb-run');
    if (!d || !btn || !d.tests || d.auto) { return; }
    var i18n = d.i18n || {};
    btn.addEventListener('click', function () {
      btn.disabled = true;
      btn.textContent = txt(i18n.running || 'Running…');
      run(d.code, d.tests).then(function (r) {
        render(r);
        btn.disabled = false;
        btn.textContent = txt(i18n.run || 'Run tests');
      });
    });
  }

  api.run = run;
  api.render = render;
  api.compare = function (got, expect, cmp, tol) { core = core || harnessCore(); return core.compare(got, expect, cmp, tol); };
  api.highlight = highlight;
  global.WBHarness = api;
  if (D) {
    if (D.readyState === 'loading') { D.addEventListener('DOMContentLoaded', mount); } else { mount(); }
  }
})(window);
