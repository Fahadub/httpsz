/*!
 * WOAIBench.si probe (module: verifier).
 * Runs first inside the sandboxed render document (opaque origin), before any model code.
 * Collects errors, waits for load, evaluates the task's browser checks (ARCHITECTURE §5.1), runs JS
 * tests through WBHarness, then posts {type:'wb-probe', …} to the parent (Station) and exposes
 * window.__WB_PROBE_RESULT / __WB_PROBE_RESULT_JSON (Playwright). Must never hang or navigate away.
 */
(function () {
  'use strict';
  var W = window;
  var D = document;
  if (W.__WB_PROBE_READY) { return; }

  /* ------------------------------------------------------------ primordials (before any page script)
   * html outputs run in this same realm and may patch built-ins (Object.prototype.toJSON / then, Array.prototype.map,
   * Math.max, Promise.prototype.then …) to read the message nonce or rewrite results. Everything that decides,
   * serialises or posts the result uses the originals captured here; built-in methods the checks rely on are
   * locked (non-writable) and internal records have no prototype. A page that adds index or accessor properties
   * to Object/Array.prototype (the remaining way to intercept array writes) is reported as tampering. */
  var OBJ = W.Object;
  var Object = OBJ;
  var Array = W.Array;
  var apply = W.Reflect.apply;
  var objKeys = OBJ.keys;
  var objCreate = OBJ.create;
  var ownNames = OBJ.getOwnPropertyNames;
  var ownDesc = OBJ.getOwnPropertyDescriptor;
  var freeze = OBJ.freeze;
  var defProp = OBJ.defineProperty;
  var hasOwnFn = OBJ.prototype.hasOwnProperty;
  var isArray = W.Array.isArray;
  var Number = W.Number;
  var String = W.String;
  var isFinite = W.isFinite;
  var JSON = { stringify: W.JSON.stringify, parse: W.JSON.parse };
  var jsonStr = JSON.stringify;
  var Math = (function (m) { return { max: m.max, min: m.min, round: m.round, pow: m.pow, floor: m.floor, abs: m.abs }; })(W.Math);
  var Promise = W.Promise;
  var pResolve = Promise.resolve;
  function hasOwn(o, k) { return apply(hasOwnFn, o, [k]); }
  function lockOwn(obj, key) {
    try {
      var d = ownDesc(obj, key);
      if (d && hasOwn(d, 'value')) { defProp(obj, key, { value: d.value, writable: false, enumerable: d.enumerable, configurable: false }); }
    } catch (e) { /* already locked */ }
  }
  function lockAll(obj, keys) { for (var i = 0; i < keys.length; i++) { lockOwn(obj, keys[i]); } }
  lockAll(W.Array.prototype, ['filter', 'map', 'forEach', 'slice', 'sort', 'indexOf', 'join', 'concat', 'some', 'reduce', 'push', 'splice']);
  lockAll(W.String.prototype, ['slice', 'trim', 'toLowerCase', 'toUpperCase', 'indexOf', 'split', 'replace', 'charAt', 'charCodeAt', 'normalize']);
  lockAll(W.RegExp.prototype, ['test', 'exec', W.Symbol.replace, W.Symbol.split, W.Symbol.match]);
  lockAll(W.Function.prototype, ['call', 'apply', 'bind']);
  // Promise resolution looks up then / constructor / species at run time: keep the originals.
  lockAll(Promise.prototype, ['then', 'catch', 'finally', 'constructor']);
  try { defProp(Promise, W.Symbol.species, { value: Promise, configurable: false }); } catch (e) { /* ignore */ }
  function presolve(v) { return apply(pResolve, Promise, [v]); }
  /** Object without a prototype: lookups never reach page-patched Object.prototype (getters, then, setters). */
  function rec(src) {
    var o = objCreate(null);
    if (src) { var ks = objKeys(src); for (var i = 0; i < ks.length; i++) { o[ks[i]] = src[ks[i]]; } }
    return o;
  }
  /** Append without [[Set]]: index accessors a page could put on Array.prototype are never consulted. */
  function append(arr, v) { defProp(arr, arr.length, { value: v, writable: true, enumerable: true, configurable: true }); return arr; }
  /** Deep copy of JSON-like data into prototype-less objects and plain arrays. */
  function clean(v, depth) {
    if (v === null || typeof v !== 'object' || depth > 12) { return typeof v === 'object' ? null : v; }
    var i;
    if (isArray(v)) { var a = []; for (i = 0; i < v.length; i++) { append(a, clean(v[i], depth + 1)); } return a; }
    var o = objCreate(null);
    var ks = objKeys(v);
    for (i = 0; i < ks.length; i++) { o[ks[i]] = clean(v[ks[i]], depth + 1); }
    return o;
  }
  /** Numeric keys or accessors added to Object/Array.prototype after start (they intercept plain array writes). */
  var PROTO_BASE = rec(null);
  (function () { var ps = [OBJ.prototype, W.Array.prototype]; for (var i = 0; i < ps.length; i++) { var n = ownNames(ps[i]); for (var j = 0; j < n.length; j++) { PROTO_BASE[i + ':' + n[j]] = 1; } } })();
  var tampered = null;
  function checkTamper() {
    if (tampered) { return tampered; }
    var ps = [OBJ.prototype, W.Array.prototype];
    for (var i = 0; i < ps.length; i++) {
      var n = ownNames(ps[i]);
      for (var j = 0; j < n.length; j++) {
        if (PROTO_BASE[i + ':' + n[j]]) { continue; }
        var d = ownDesc(ps[i], n[j]);
        if (/^\d+$/.test(n[j]) || (d && (d.get || d.set))) { tampered = (i ? 'Array' : 'Object') + '.prototype[' + trunc(n[j], 40) + ']'; return tampered; }
      }
    }
    return null;
  }

  /* ------------------------------------------------------------ capture config & built-ins first */
  var CFG = clean(W.__WB_PROBE_CFG || {}, 0) || rec(null);
  var PRE = W.__WB_PRE || { raf: [], ctx: [], rafOrig: null };
  try { delete W.__WB_PROBE_CFG; } catch (e) { W.__WB_PROBE_CFG = undefined; }
  try { delete W.__WB_PRE; } catch (e) { W.__WB_PRE = undefined; }
  try {
    // The config holds the nonce: take it out of the DOM before page scripts could read it.
    var own = D.querySelectorAll('script[data-wb-probe]');
    for (var oi = 0; oi < own.length; oi++) { if (!own[oi].src) { own[oi].parentNode.removeChild(own[oi]); } }
  } catch (e) { /* ignore */ }

  var perf = W.performance;
  var perfNow = perf.now;
  var now = function () { return apply(perfNow, perf, []); };
  var setT = W.setTimeout.bind(W);
  var clrT = W.clearTimeout.bind(W);
  var rafO = PRE.rafOrig || (W.requestAnimationFrame ? W.requestAnimationFrame.bind(W) : function (f) { return setT(function () { f(now()); }, 16); });
  var gcs = W.getComputedStyle.bind(W);
  var MO = W.MutationObserver;
  var IO = W.IntersectionObserver;
  var PARENT = W.parent;
  var IS_TOP = PARENT === W;
  var ET = W.EventTarget && W.EventTarget.prototype;
  var addO = ET.addEventListener;
  var remO = ET.removeEventListener;
  var dispatch = ET.dispatchEvent;
  var aSlice = W.Array.prototype.slice;
  var qsaFn = W.Document.prototype.querySelectorAll;
  var qsaElFn = W.Element.prototype.querySelectorAll;
  var qsa = function (sel, root) { root = root || D; return apply(aSlice, apply(root === D ? qsaFn : qsaElFn, root, [sel]), []); };
  // Station screenshots: canvas / serialisation primitives the page cannot swap for a fake image.
  var CV = W.HTMLCanvasElement.prototype;
  var CX = W.CanvasRenderingContext2D.prototype;
  var cvGetContext = CV.getContext;
  var cvToDataURL = CV.toDataURL;
  var cxDrawImage = CX.drawImage;
  var cxGetImageData = CX.getImageData;
  var cxFillRect = CX.fillRect;
  var cxClearRect = CX.clearRect;
  var setterOf = function (proto, key) { var d = ownDesc(proto, key); return d && d.set ? d.set : null; };
  var cxFillStyle = setterOf(CX, 'fillStyle');
  var cloneNodeFn = W.Node.prototype.cloneNode;
  var serializeFn = W.XMLSerializer.prototype.serializeToString;
  var XMLS = W.XMLSerializer;
  var ImageC = W.Image;
  var imgSrcSet = setterOf(W.HTMLImageElement.prototype, 'src');
  var encURI = W.encodeURIComponent;

  var NONCE = String(CFG.nonce || '');
  var VP = String(CFG.viewport || 'desktop');
  var LIGHT = !!CFG.light;
  var CHECKS = [];
  if (isArray(CFG.checks)) { for (var ci0 = 0; ci0 < CFG.checks.length; ci0++) { var c0 = CFG.checks[ci0]; if (c0 && typeof c0.type === 'string') { append(CHECKS, c0); } } }
  var TESTS = CFG.tests && typeof CFG.tests === 'object' && isArray(CFG.tests.cases) ? CFG.tests : null;
  var LOAD_TIMEOUT = clampN(CFG.load_timeout_ms, 1000, 30000, 8000);
  var SETTLE = clampN(CFG.settle_ms, 0, 5000, 600);
  var DEFAULT_KEYS = [' ', 'Enter', 'ArrowUp', 'ArrowRight', 'ArrowDown', 'ArrowLeft', 'w', 'a', 's', 'd'];
  var LIGHT_TYPES = rec({ loads: 1, no_errors: 1, nonblank: 1, selector: 1, text: 1, responsive: 1, rtl: 1, a11y_basic: 1, canvas: 1 });
  var T0 = now();

  function clampN(v, lo, hi, dflt) { v = Number(v); if (!isFinite(v)) { v = dflt; } return Math.max(lo, Math.min(hi, v)); }
  function trunc(s, n) {
    s = String(s == null ? '' : s);
    if (s.length <= n) { return s; }
    var cut = /[\uD800-\uDBFF]/.test(s.charAt(n - 2)) ? n - 2 : n - 1; // never split a surrogate pair
    return s.slice(0, cut) + '…';
  }
  function sleep(ms) { return new Promise(function (r) { setT(r, ms); }); }
  function round(n, d) { var f = Math.pow(10, d || 0); return Math.round(n * f) / f; }

  /* Time budget: base + interactive checks + tests (reported to the verifier in the hello message). */
  var TESTS_BUDGET = 0;
  if (TESTS && !LIGHT && CFG.output_type === 'js') {
    var perCase = clampN(TESTS.timeout_ms, 50, 30000, 2000);
    TESTS_BUDGET = Math.min(60000, 1500 + TESTS.cases.length * Math.min(perCase + 120, 4000));
  }
  var CHECKS_BUDGET = 3500;
  for (var cb = 0; cb < CHECKS.length; cb++) {
    var cc = CHECKS[cb];
    if (LIGHT && !LIGHT_TYPES[cc.type]) { continue; }
    if (cc.type === 'animates' || cc.type === 'fps') { CHECKS_BUDGET += 2600; }
    if (cc.type === 'keyboard') { CHECKS_BUDGET += 900 + Math.min(12, (isArray(cc.keys) ? cc.keys : DEFAULT_KEYS).length) * 620; }
    if (cc.type === 'click') { CHECKS_BUDGET += 1800; }
  }
  var DEADLINE = Math.min(150000, Math.max(clampN(CFG.timeout_ms, 3000, 120000, 15000), LOAD_TIMEOUT + SETTLE + CHECKS_BUDGET + TESTS_BUDGET));

  /* ------------------------------------------------------------ messaging + result exposure */
  /**
   * Messages go to the parent's origin only. postMessage structured-clones own data properties (no toJSON,
   * no getters), and the nonce only ever appears in message objects built right here — never in anything the page
   * can reach (window.__WB_PROBE_RESULT has no nonce), so page code can neither read it nor forge a message.
   */
  function post(msg) {
    if (IS_TOP || !PARENT) { return; }
    try { PARENT.postMessage(msg, CFG.parentOrigin || '*'); } catch (e) { /* ignore */ }
  }
  var RESULT = null;
  var RESULT_JSON = null;
  try {
    defProp(W, '__WB_PROBE_READY', { value: true, writable: false, configurable: false });
    defProp(W, '__WB_PROBE_DEADLINE', { value: DEADLINE, writable: false, configurable: false });
    defProp(W, '__WB_PROBE_RESULT', { get: function () { return RESULT; }, configurable: false });
    defProp(W, '__WB_PROBE_RESULT_JSON', { get: function () { return RESULT_JSON; }, configurable: false });
  } catch (e) { /* ignore */ }
  post({ type: 'wb-probe-hello', nonce: NONCE, viewport: VP, deadline_ms: DEADLINE });

  /* ------------------------------------------------------------ error collection */
  var errs = [];
  var counts = { js: 0, rejection: 0, console: 0, resource: 0, csp: 0 };
  function addErr(kind, msg) {
    counts[kind] = (counts[kind] || 0) + 1;
    if (errs.length < 50) { append(errs, trunc('[' + kind + '] ' + msg, 300)); }
  }
  var DOC_URL = String(W.location.href).split('#')[0];
  function shortUrl(u) {
    u = String(u || '');
    if (u.split('#')[0] === DOC_URL) { return 'page'; }
    u = u.split('?')[0];
    return u.length > 80 ? '…' + u.slice(-79) : u;
  }
  addO.call(W, 'error', function (ev) {
    var t = ev && ev.target;
    if (t && t !== W && t.nodeType === 1) {
      if (t.hasAttribute && t.hasAttribute('data-wb-probe')) { return; }
      addErr('resource', 'Failed to load <' + t.tagName.toLowerCase() + '> ' + shortUrl(t.currentSrc || t.src || t.href || ''));
      return;
    }
    var m = (ev && ev.message) || 'Error';
    if (/ResizeObserver loop/i.test(m)) { return; }
    addErr('js', m + (ev && ev.filename ? ' (' + shortUrl(ev.filename) + ':' + (ev.lineno || 0) + ')' : ''));
  }, true);
  addO.call(W, 'unhandledrejection', function (ev) {
    var r = ev && ev.reason;
    addErr('rejection', 'Unhandled rejection: ' + (r && r.message ? r.message : String(r)));
  });
  addO.call(D, 'securitypolicyviolation', function (ev) {
    addErr('csp', 'Blocked by CSP: ' + shortUrl(ev.blockedURI || '') + ' (' + (ev.effectiveDirective || ev.violatedDirective || '') + ')');
  });
  try {
    var cerr = W.console && W.console.error;
    if (cerr) {
      W.console.error = function () {
        try {
          addErr('console', Array.prototype.map.call(arguments, function (a) {
            if (a && a.message) { return a.message; }
            if (typeof a === 'object') { try { return jsonStr(a); } catch (e) { return String(a); } }
            return String(a);
          }).join(' '));
        } catch (e) { /* ignore */ }
        return apply(cerr, this, arguments);
      };
    }
  } catch (e) { /* ignore */ }

  /* ------------------------------------------------------------ interaction listener registry */
  var INTERACT = rec({ keydown: 'key', keyup: 'key', keypress: 'key', click: 'click', dblclick: 'click', mousedown: 'click',
    mouseup: 'click', pointerdown: 'click', pointerup: 'click', touchstart: 'click', touchend: 'click' });
  var registry = { key: [], click: [] };
  var synthetic = 0;
  var hits = 0;
  var wrapMap = typeof WeakMap === 'function' ? new WeakMap() : null;
  function capFlag(o) { return !!(o === true || (o && typeof o === 'object' && o.capture)); }
  if (wrapMap) {
    ET.addEventListener = function (type, listener, opts) {
      var kind = INTERACT[type];
      if (kind && listener && (typeof listener === 'function' || typeof listener.handleEvent === 'function')) {
        var m = wrapMap.get(listener);
        if (!m) { m = {}; wrapMap.set(listener, m); }
        var key = type + (capFlag(opts) ? '1' : '0');
        if (!m[key]) {
          m[key] = function (ev) {
            if (synthetic > 0) { hits++; }
            return typeof listener === 'function' ? listener.apply(this, arguments) : listener.handleEvent(ev);
          };
        }
        if (registry[kind].length < 200 && registry[kind].indexOf(this) < 0) { registry[kind].push(this); }
        return addO.call(this, type, m[key], opts);
      }
      return addO.call(this, type, listener, opts);
    };
    ET.removeEventListener = function (type, listener, opts) {
      if (INTERACT[type] && listener) {
        var m = wrapMap.get(listener);
        var w = m && m[type + (capFlag(opts) ? '1' : '0')];
        if (w) { return remO.call(this, type, w, opts); }
      }
      return remO.call(this, type, listener, opts);
    };
  }
  // Synthetic clicks must never navigate the frame away (links) or submit forms.
  addO.call(W, 'click', function (ev) {
    if (synthetic <= 0) { return; }
    var t = ev.target;
    var a = t && t.closest ? t.closest('a[href]') : null;
    if (a) {
      var h = a.getAttribute('href') || '';
      if (h.charAt(0) !== '#' && !/^javascript:/i.test(h)) { ev.preventDefault(); }
    }
  }, true);
  addO.call(W, 'submit', function (ev) { if (synthetic > 0) { ev.preventDefault(); } }, true);

  /* ------------------------------------------------------------ visibility (station throttling) */
  var flags = { hidden: D.visibilityState === 'hidden', offscreen: false, starved: false };
  addO.call(D, 'visibilitychange', function () { if (D.visibilityState === 'hidden') { flags.hidden = true; } });
  var inView = true;
  function watchViewport() {
    if (IS_TOP || !IO) { return; }
    try {
      var io = new IO(function (entries) {
        var e = entries[entries.length - 1];
        inView = !!(e && e.isIntersecting);
      });
      io.observe(D.documentElement);
    } catch (e) { /* ignore */ }
  }
  function noteOffscreen() { if (!inView) { flags.offscreen = true; } }

  /* ------------------------------------------------------------ long tasks (page busy vs. machine starved) */
  var longTasks = [];
  try {
    new W.PerformanceObserver(function (list) {
      list.getEntries().forEach(function (e) { if (longTasks.length < 1000) { longTasks.push([e.startTime, e.startTime + e.duration]); } });
    }).observe({ type: 'longtask', buffered: true });
  } catch (e) { /* unsupported */ }
  function longTaskMs(t0, t1) {
    var sum = 0;
    for (var i = 0; i < longTasks.length; i++) { sum += Math.max(0, Math.min(t1, longTasks[i][1]) - Math.max(t0, longTasks[i][0])); }
    return sum;
  }

  /* ------------------------------------------------------------ load tracking */
  var times = { dom: null, load: null };
  addO.call(D, 'DOMContentLoaded', function () { times.dom = now(); });
  addO.call(W, 'load', function () { times.load = now(); setT(start, 0); });
  setT(start, LOAD_TIMEOUT);
  setT(function () { finalize('deadline'); }, DEADLINE);
  // The page navigating itself away (location.href = …) ends the probe: report what we have.
  addO.call(W, 'pagehide', function () { finalize('navigated'); });

  /* ------------------------------------------------------------ DOM helpers */
  function ownEl(el) { return !!(el && el.hasAttribute && el.hasAttribute('data-wb-probe')); }
  function rectOf(el) { try { return el.getBoundingClientRect(); } catch (e) { return null; } }
  function isVisible(el, minSize) {
    if (!el || el.nodeType !== 1 || ownEl(el)) { return false; }
    var r = rectOf(el);
    var min = minSize == null ? 2 : minSize;
    if (!r || r.width < min || r.height < min) { return false; }
    if (r.right <= 0 || r.bottom <= 0) { return false; }
    if (el.checkVisibility) {
      try { if (!el.checkVisibility({ opacityProperty: true, visibilityProperty: true, checkOpacity: true, checkVisibilityCSS: true })) { return false; } } catch (e) { /* ignore */ }
    } else {
      var s = gcs(el);
      if (s.display === 'none' || s.visibility === 'hidden' || Number(s.opacity) === 0) { return false; }
    }
    return true;
  }
  function area(el) { var r = rectOf(el); return r ? Math.max(0, r.width) * Math.max(0, r.height) : 0; }
  function describe(el) {
    if (!el || !el.tagName) { return String(el); }
    var s = el.tagName.toLowerCase();
    if (el.id) { s += '#' + el.id; }
    if (typeof el.className === 'string' && el.className.trim()) { s += '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.'); }
    return trunc(s, 60);
  }
  function fnv(str) {
    var h = 0x811c9dc5;
    for (var i = 0; i < str.length; i++) { h ^= str.charCodeAt(i); h = (h + ((h << 1) + (h << 4) + (h << 7) + (h << 8) + (h << 24))) >>> 0; }
    return h.toString(36);
  }
  function bodyText() {
    var b = D.body;
    var t = '';
    try { t = b ? (b.innerText || b.textContent || '') : (D.documentElement.textContent || ''); } catch (e) { t = ''; }
    return t;
  }

  /* ------------------------------------------------------------ canvas sampling */
  var SC = null;
  var SX = null;
  function sampler() {
    if (!SC) {
      SC = D.createElement('canvas');
      SC.width = 24;
      SC.height = 24;
      SX = apply(cvGetContext, SC, ['2d', { willReadFrequently: true }]);
      var i = PRE.ctx.indexOf(SC);
      if (i >= 0) { PRE.ctx.splice(i, 1); }
    }
    return SX;
  }
  function pageCanvases() {
    return qsa('canvas').filter(function (c) { return c !== SC && isVisible(c, 4); })
      .sort(function (a, b) { return area(b) - area(a); }).slice(0, 4);
  }
  /** 24×24 RGBA sample of a canvas, or null when unreadable (tainted / zero-sized). */
  function sampleCanvas(c) {
    var x = sampler();
    if (!x || !c.width || !c.height) { return null; }
    try {
      apply(cxClearRect, x, [0, 0, 24, 24]);
      apply(cxDrawImage, x, [c, 0, 0, 24, 24]);
      return apply(cxGetImageData, x, [0, 0, 24, 24]).data;
    } catch (e) {
      SC = null;
      SX = null;
      return null;
    }
  }
  /** {ink:boolean, readable:boolean}: ink = some non-transparent pixel and not one flat colour. */
  function canvasInk(c) {
    var d = sampleCanvas(c);
    if (!d) { return { ink: false, readable: false }; }
    var opaque = 0;
    var r0 = d[0], g0 = d[1], b0 = d[2], a0 = d[3];
    var varied = false;
    for (var i = 0; i < d.length; i += 4) {
      if (d[i + 3] > 8) { opaque++; }
      if (!varied && (Math.abs(d[i] - r0) > 10 || Math.abs(d[i + 1] - g0) > 10 || Math.abs(d[i + 2] - b0) > 10 || Math.abs(d[i + 3] - a0) > 10)) { varied = true; }
    }
    return { ink: opaque > 0 && (varied || a0 > 8), readable: true, varied: varied };
  }
  function canvasCells() {
    var out = [];
    pageCanvases().forEach(function (c, ci) {
      var d = sampleCanvas(c);
      if (!d) { return; }
      for (var i = 0; i < d.length; i += 4) { out.push(ci + ':' + ((d[i] >> 4) + ',' + (d[i + 1] >> 4) + ',' + (d[i + 2] >> 4) + ',' + (d[i + 3] >> 5))); }
    });
    return out;
  }

  /* ------------------------------------------------------------ state snapshots */
  function styleList() {
    var out = [];
    var els = D.body ? D.body.getElementsByTagName('*') : [];
    for (var i = 0; i < els.length && out.length < 300; i++) {
      var el = els[i];
      if (ownEl(el) || el.tagName === 'SCRIPT' || el.tagName === 'STYLE') { continue; }
      var r = rectOf(el);
      if (!r) { continue; }
      var s = gcs(el);
      out.push(Math.round(r.left * 2) + ',' + Math.round(r.top * 2) + ',' + Math.round(r.width * 2) + ',' + Math.round(r.height * 2) + '|' +
        s.opacity + '|' + s.transform + '|' + s.color + '|' + s.backgroundColor + '|' + s.visibility);
    }
    return out;
  }
  function domHash() {
    var b = D.body;
    if (!b) { return '0'; }
    var html = '';
    try { html = b.innerHTML; } catch (e) { html = ''; }
    if (html.length > 1500000) { html = html.slice(0, 750000) + html.slice(-750000); }
    var vals = '';
    qsa('input,select,textarea').slice(0, 200).forEach(function (el) { vals += '|' + el.value + (el.checked ? '1' : '0'); });
    return fnv(html + vals) + ':' + html.length;
  }
  function vec() { return { dom: domHash(), cells: canvasCells(), els: styleList() }; }
  /** Set of changed "units" between two snapshots: canvas cells, element styles and the DOM. */
  function changedSet(a, b) {
    var s = objCreate(null);
    var n = 0;
    var i;
    var len = Math.max(a.cells.length, b.cells.length);
    for (i = 0; i < len; i++) { if (a.cells[i] !== b.cells[i]) { s['c' + i] = 1; n++; } }
    len = Math.max(a.els.length, b.els.length);
    for (i = 0; i < len; i++) { if (a.els[i] !== b.els[i]) { s['e' + i] = 1; n++; } }
    if (a.dom !== b.dom) { s.dom = 1; n++; }
    return rec({ set: s, size: n });
  }
  function novelCount(k, base) {
    var n = 0;
    var ks = objKeys(k.set);
    for (var i = 0; i < ks.length; i++) { if (!base.set[ks[i]]) { n++; } }
    return n;
  }
  /** Changed units over `ms` without any input (control interval for animated pages). */
  function measure(ms) {
    var a = vec();
    return sleep(ms).then(function () { return changedSet(a, vec()); });
  }
  /** A change is attributable to input when it touches new regions AND is larger than the control. */
  function attributable(ch, ctl) {
    if (!ctl) { return ch.size > 0; }
    return novelCount(ch, ctl) >= 3 && ch.size >= ctl.size + 3;
  }
  /** The input stopped the page's own animation (pause buttons): far fewer changes than the control interval. */
  function stoppedAnim(ch, ctl) {
    return !!ctl && ctl.size >= 4 && ch.size * 4 <= ctl.size;
  }

  /* ------------------------------------------------------------ observation window (animates + fps) */
  var observation = null;
  var lastFps = null;
  /** One observation window: DOM mutations, running animations, pixel/style changes, frame rate. */
  function observeOnce(ms) {
    return new Promise(function (resolve) {
      var mut = 0;
      var mo = null;
      try {
        mo = new MO(function (list) { mut += list.length; });
        mo.observe(D.documentElement, { subtree: true, childList: true, attributes: true, characterData: true });
      } catch (e) { mo = null; }
      var a = vec();
      var anims = 0;
      try { anims = D.getAnimations ? D.getAnimations().filter(function (x) { return x.playState === 'running'; }).length : 0; } catch (e) { anims = 0; }
      var t0 = now();
      var frames = [];
      var live = true;
      var tick = function (ts) { if (!live) { return; } frames.push(ts); rafO(tick); };
      rafO(tick);
      noteOffscreen();
      setT(function () {
        live = false;
        if (mo) { try { mut += mo.takeRecords().length; mo.disconnect(); } catch (e) { /* ignore */ } }
        noteOffscreen();
        var t1 = now();
        var b = vec();
        var pageTs = PRE.raf.filter(function (x) { return x >= t0 && x <= t1; });
        var sec = Math.max(0.001, (t1 - t0) / 1000);
        var usePage = pageTs.length >= 5;
        var ts = usePage ? pageTs : frames;
        var gaps = [];
        for (var i = 1; i < ts.length; i++) { gaps.push(ts[i] - ts[i - 1]); }
        gaps.sort(function (x, y) { return x - y; });
        var ch = changedSet(a, b);
        var canvasChanged = false;
        var styleChanged = false;
        var ks = objKeys(ch.set);
        for (var q = 0; q < ks.length; q++) {
          if (ks[q].charAt(0) === 'c') { canvasChanged = true; }
          if (ks[q].charAt(0) === 'e') { styleChanged = true; }
        }
        resolve(rec({
          ms: t1 - t0, mutations: mut, anims: anims, canvasChanged: canvasChanged, styleChanged: styleChanged,
          fps: ts.length / sec, pageLoop: usePage, p95: gaps.length ? gaps[Math.min(gaps.length - 1, Math.floor(gaps.length * 0.95))] : null,
          probeFps: frames.length / sec, longFrac: longTaskMs(t0, t1) / Math.max(1, t1 - t0)
        }));
      }, ms);
    });
  }
  /**
   * Shared observation for animates + fps. When the browser itself produces almost no frames while the
   * page is not busy (no long tasks) the machine is starved (e.g. a loaded host): retry up to twice and
   * keep the best window, so external load is not blamed on the model.
   */
  function observe(ms) {
    if (observation) { return observation; }
    var attempt = function (n, best) {
      return observeOnce(ms).then(function (o) {
        var m = best ? rec({
          ms: best.ms + o.ms, mutations: best.mutations + o.mutations, anims: Math.max(best.anims, o.anims),
          canvasChanged: best.canvasChanged || o.canvasChanged, styleChanged: best.styleChanged || o.styleChanged,
          fps: Math.max(best.fps, o.fps), pageLoop: o.fps >= best.fps ? o.pageLoop : best.pageLoop,
          p95: o.fps >= best.fps ? o.p95 : best.p95, probeFps: Math.max(best.probeFps, o.probeFps), longFrac: o.longFrac
        }) : o;
        var starved = o.probeFps < 10 && o.longFrac < 0.3;
        if (starved && n < 2 && !finalized) { return sleep(800).then(function () { return attempt(n + 1, m); }); }
        m.starved = starved;
        flags.starved = starved;
        lastFps = round(m.fps, 1);
        return m;
      });
    };
    observation = attempt(0, null);
    return observation;
  }

  /* ------------------------------------------------------------ synthetic input */
  var KEYCODES = rec({ ' ': 32, Enter: 13, Escape: 27, Tab: 9, Backspace: 8, ArrowLeft: 37, ArrowUp: 38, ArrowRight: 39, ArrowDown: 40, Shift: 16, Control: 17 });
  var KEYALIAS = rec({ space: ' ', spacebar: ' ', up: 'ArrowUp', down: 'ArrowDown', left: 'ArrowLeft', right: 'ArrowRight', esc: 'Escape', enter: 'Enter', return: 'Enter' });
  function normKey(k) {
    k = String(k);
    var a = KEYALIAS[k.toLowerCase()];
    return a || k;
  }
  function keyCodeOf(k) {
    if (KEYCODES[k]) { return KEYCODES[k]; }
    if (k.length === 1) { return k.toUpperCase().charCodeAt(0); }
    return 0;
  }
  function codeOf(k) {
    if (k === ' ') { return 'Space'; }
    if (/^[a-z]$/i.test(k)) { return 'Key' + k.toUpperCase(); }
    if (/^[0-9]$/.test(k)) { return 'Digit' + k; }
    return k;
  }
  function keyEvent(type, k) {
    var ev = new KeyboardEvent(type, { key: k, code: codeOf(k), bubbles: true, cancelable: true, composed: true, view: W });
    var kc = keyCodeOf(k);
    try {
      defProp(ev, 'keyCode', { get: function () { return type === 'keypress' && k.length === 1 ? k.charCodeAt(0) : kc; } });
      defProp(ev, 'which', { get: function () { return type === 'keypress' && k.length === 1 ? k.charCodeAt(0) : kc; } });
      defProp(ev, 'charCode', { get: function () { return type === 'keypress' && k.length === 1 ? k.charCodeAt(0) : 0; } });
    } catch (e) { /* ignore */ }
    return ev;
  }
  function fire(target, ev) {
    synthetic++;
    try { dispatch.call(target, ev); } catch (e) { addErr('js', 'Handler threw on synthetic ' + ev.type + ': ' + (e && e.message)); } finally { synthetic--; }
  }
  /** The element keys are sent to: c.sel (focused first) when given, else the largest element with key listeners, else the focused element. */
  function keyTarget(c) {
    if (c && c.sel) {
      var list = qsa(String(c.sel));
      var el = list.filter(function (x) { return isVisible(x); })[0] || list[0] || null;
      if (el) {
        try { el.scrollIntoView({ block: 'nearest', inline: 'nearest' }); } catch (e) { /* ignore */ }
        try { if (typeof el.focus === 'function') { el.focus({ preventScroll: true }); } } catch (e) { /* ignore */ }
        // A non-focusable target (e.g. "body" for a page-wide shortcut): make sure no text field keeps focus.
        var ae = D.activeElement;
        if (ae && ae !== el && ae !== D.body && !el.contains(ae)) { try { ae.blur(); } catch (e) { /* ignore */ } }
      }
      return el;
    }
    var els = registry.key.filter(function (t) { return t && t.nodeType === 1 && t !== D.body && t !== D.documentElement && isVisible(t); })
      .sort(function (a, b) { return area(b) - area(a); });
    if (els.length) {
      try { els[0].focus({ preventScroll: true }); } catch (e) { /* ignore */ }
      return els[0];
    }
    var ae = D.activeElement;
    if (ae && ae !== D.body && ae.nodeType === 1 && D.documentElement.contains(ae)) { return ae; }
    return D.body || D.documentElement;
  }
  function hasPropHandler(target, names) {
    var nodes = [W, D, D.body, target];
    for (var i = 0; i < nodes.length; i++) {
      for (var j = 0; j < names.length; j++) {
        try { if (nodes[i] && typeof nodes[i][names[j]] === 'function') { return true; } } catch (e) { /* ignore */ }
      }
    }
    return false;
  }
  function pointerSeq(el, x, y) {
    var base = { bubbles: true, cancelable: true, composed: true, view: W, clientX: x, clientY: y, screenX: x, screenY: y, button: 0 };
    var P = W.PointerEvent || W.MouseEvent;
    var pe = function (type, buttons) {
      var o = {};
      for (var k in base) { o[k] = base[k]; }
      o.buttons = buttons;
      o.pointerId = 1;
      o.pointerType = 'mouse';
      o.isPrimary = true;
      return new P(type, o);
    };
    var me = function (type, buttons) {
      var o = {};
      for (var k in base) { o[k] = base[k]; }
      o.buttons = buttons;
      o.detail = 1;
      return new MouseEvent(type, o);
    };
    fire(el, pe('pointerover', 0));
    fire(el, me('mouseover', 0));
    fire(el, pe('pointermove', 0));
    fire(el, me('mousemove', 0));
    fire(el, pe('pointerdown', 1));
    fire(el, me('mousedown', 1));
    try { if (typeof el.focus === 'function') { el.focus({ preventScroll: true }); } } catch (e) { /* ignore */ }
    fire(el, pe('pointerup', 0));
    fire(el, me('mouseup', 0));
    fire(el, me('click', 0));
  }
  var CLICKABLE = 'button,[role="button"],input[type="button"],input[type="submit"],input[type="checkbox"],input[type="radio"],summary,label[for],select';
  function clickTarget(c) {
    if (c.sel) {
      var list = qsa(String(c.sel));
      var vis = list.filter(function (el) { return isVisible(el); });
      return vis[0] || list[0] || null;
    }
    var tiers = [
      registry.click.filter(function (t) { return t && t.nodeType === 1 && t !== D.body && t !== D.documentElement; }).concat(qsa('[onclick]')),
      qsa(CLICKABLE),
      qsa('canvas').filter(function (x) { return x !== SC; }),
      qsa('a[href],[tabindex]:not([tabindex="-1"])')
    ];
    for (var i = 0; i < tiers.length; i++) {
      var vis2 = tiers[i].filter(function (el) { return isVisible(el, 6); }).sort(function (a, b) { return area(b) - area(a); });
      if (vis2.length) { return vis2[0]; }
    }
    return null;
  }

  /* ------------------------------------------------------------ check implementations */
  /** One check result (prototype-less: it travels through promises and must not look like a page thenable). */
  function res(c, pass, score, detail, extra) {
    var o = rec({ id: String(c.id || c.type), type: c.type, pass: !!pass, score: round(Math.max(0, Math.min(1, Number(score) || 0)), 3), detail: trunc(detail, 300) });
    if (extra) { var ks = objKeys(extra); for (var i = 0; i < ks.length; i++) { o[ks[i]] = extra[ks[i]]; } }
    return o;
  }
  function contentChildren() {
    var b = D.body;
    if (!b) { return 0; }
    var n = 0;
    for (var i = 0; i < b.children.length; i++) {
      var t = b.children[i].tagName;
      if (t !== 'SCRIPT' && t !== 'STYLE' && t !== 'NOSCRIPT' && t !== 'TEMPLATE' && !ownEl(b.children[i])) { n++; }
    }
    return n;
  }
  var CHECKERS = rec({
    loads: function (c) {
      var kids = contentChildren();
      if (times.load !== null) {
        return res(c, kids > 0, kids > 0 ? 1 : 0.3, 'load event at ' + Math.round(times.load) + ' ms' + (kids > 0 ? '' : ', but <body> has no content elements'));
      }
      return res(c, false, times.dom !== null ? 0.5 : 0, times.dom !== null ? 'DOM ready, but the load event did not fire within ' + Math.round(LOAD_TIMEOUT / 1000) + ' s' : 'document did not finish parsing');
    },
    nonblank: function (c) {
      var chars = 0;
      var media = [];
      var tw = D.createTreeWalker(D.body || D.documentElement, 4, null);
      var node;
      var budget = 8000;
      var seen = [];
      while ((node = tw.nextNode()) && budget-- > 0 && chars < 400) {
        var t = node.nodeValue;
        if (!t || !t.trim()) { continue; }
        var p = node.parentElement;
        if (!p || /^(SCRIPT|STYLE|NOSCRIPT|TEMPLATE|TITLE)$/.test(p.tagName)) { continue; }
        if (seen.indexOf(p) < 0) {
          if (!isVisible(p, 1)) { continue; }
          if (seen.length < 50) { seen.push(p); }
        }
        chars += t.trim().length;
      }
      qsa('img,svg,video,canvas,input,button,select,textarea,iframe,object,embed').forEach(function (el) {
        if (media.length >= 6 || el === SC || !isVisible(el, 4)) { return; }
        var tag = el.tagName.toLowerCase();
        if (tag === 'img' && !(el.complete && el.naturalWidth > 0)) { return; }
        if (tag === 'svg' && (el.ownerSVGElement || !el.querySelector('path,rect,circle,ellipse,line,polyline,polygon,text,use,image,g'))) { return; }
        if (tag === 'canvas') { var ink = canvasInk(el); if (ink.readable && !ink.ink) { return; } }
        media.push(tag);
      });
      var pass = chars > 0 || media.length > 0;
      var detail = pass ? (chars > 0 ? (chars >= 400 ? '400+' : chars) + ' visible text chars' : 'no visible text') + (media.length ? ' · ' + media.join(', ') : '') : 'nothing visible was rendered';
      return res(c, pass, pass ? 1 : 0, detail);
    },
    canvas: function (c) {
      var list = qsa('canvas').filter(function (x) { return x !== SC; });
      if (!list.length) { return res(c, false, 0, 'no <canvas> element'); }
      var vis = list.filter(function (x) { return isVisible(x, 4); });
      if (!vis.length) { return res(c, false, 0.25, list.length + ' canvas element(s), none visible'); }
      for (var i = 0; i < vis.length; i++) {
        var ink = canvasInk(vis[i]);
        if (ink.ink) { return res(c, true, 1, describe(vis[i]) + ' ' + vis[i].width + '×' + vis[i].height + ' has drawn content'); }
        if (!ink.readable && PRE.ctx.indexOf(vis[i]) >= 0) { return res(c, true, 0.8, describe(vis[i]) + ' has a context (pixels not readable)'); }
      }
      return res(c, false, 0.5, vis.length + ' visible canvas(es), but empty');
    },
    selector: function (c) {
      var sel = String(c.sel || '');
      var min = Math.max(1, Number(c.min) || 1);
      var n;
      try { n = D.querySelectorAll(sel).length; } catch (e) { return res(c, false, 0, 'invalid selector: ' + sel); }
      return res(c, n >= min, Math.min(1, n / min), n + ' × ' + sel + ' (min ' + min + ')');
    },
    text: function (c) {
      var needles = Array.isArray(c.contains) ? c.contains : [c.contains];
      needles = needles.filter(function (x) { return x != null && String(x) !== ''; }).map(String);
      if (!needles.length) { return res(c, false, 0, 'no text to look for'); }
      var hay = bodyText() + '\n' + (D.title || '');
      var norm = function (s) { s = String(s); try { s = s.normalize('NFC'); } catch (e) { /* ignore */ } return c.ci ? s.toLowerCase() : s; };
      hay = norm(hay).replace(/\s+/g, ' ');
      var found = needles.filter(function (n) { return hay.indexOf(norm(n).replace(/\s+/g, ' ')) >= 0; });
      return res(c, found.length === needles.length, found.length / needles.length,
        found.length === needles.length ? 'found “' + trunc(needles.join('”, “'), 120) + '”' : 'missing “' + trunc(needles.filter(function (n) { return found.indexOf(n) < 0; }).join('”, “'), 120) + '”');
    },
    responsive: function (c) {
      var iw = W.innerWidth;
      var sw = Math.max(D.documentElement.scrollWidth, D.body ? D.body.scrollWidth : 0);
      var pass = sw <= iw + 2;
      var detail = 'scrollWidth ' + sw + ' / viewport ' + iw + ' (' + VP + ')';
      if (!pass) {
        var worst = null;
        var wr = 0;
        var els = D.body ? D.body.getElementsByTagName('*') : [];
        for (var i = 0; i < els.length && i < 4000; i++) {
          var r = rectOf(els[i]);
          if (r && r.right > wr && !ownEl(els[i])) { wr = r.right; worst = els[i]; }
        }
        if (worst) { detail += ' · widest: ' + describe(worst); }
      }
      return res(c, pass, pass ? 1 : Math.max(0, 1 - (sw - iw) / iw), detail);
    },
    rtl: function (c) {
      var html = D.documentElement;
      var b = D.body || html;
      var rootRtl = gcs(b).direction === 'rtl' || gcs(html).direction === 'rtl';
      var blocks = qsa('p,h1,h2,h3,h4,h5,h6,li,td,th,label,button,a,span,div').filter(function (el) {
        for (var i = 0; i < el.childNodes.length; i++) { var n = el.childNodes[i]; if (n.nodeType === 3 && n.nodeValue.trim().length > 1) { return true; } }
        return false;
      }).slice(0, 200);
      var rtlBlocks = blocks.filter(function (el) { return gcs(el).direction === 'rtl'; }).length;
      var majority = blocks.length > 0 && rtlBlocks / blocks.length >= 0.6;
      var lang = (html.getAttribute('lang') || '').toLowerCase();
      var langOk = /^(ar|fa|he|ur|ps|ckb|yi)\b/.test(lang);
      var arabic = /[؀-ۿݐ-ݿࢠ-ࣿﭐ-﷿ﹰ-﻿]/.test(bodyText().slice(0, 20000));
      var dirOk = rootRtl || majority;
      var score = (dirOk ? 0.6 : 0) + (langOk ? 0.2 : 0) + (arabic ? 0.2 : 0);
      return res(c, dirOk, score, 'direction ' + (rootRtl ? 'rtl (root)' : (majority ? 'rtl (' + rtlBlocks + '/' + blocks.length + ' blocks)' : 'ltr')) +
        ' · lang ' + (lang || '—') + ' · Arabic text ' + (arabic ? 'yes' : 'no'));
    },
    a11y_basic: function (c) {
      var parts = [];
      var detail = [];
      var rendered = function (el) { return area(el) > 0 || el.getClientRects().length > 0; };
      var txt = function (s) { return String(s || '').replace(/\s+/g, ' ').trim(); };
      var byIds = function (ids) { return txt(String(ids || '').split(/\s+/).map(function (id) { var n = id && D.getElementById(id); return n ? n.textContent : ''; }).join(' ')); };
      var imgs = qsa('img').filter(rendered);
      if (imgs.length) {
        var okImg = imgs.filter(function (i) { return i.hasAttribute('alt') || i.getAttribute('role') === 'presentation' || i.getAttribute('aria-hidden') === 'true' || txt(i.getAttribute('aria-label')); }).length;
        parts.push(okImg / imgs.length);
        detail.push('img alt ' + okImg + '/' + imgs.length);
      }
      var ctrls = qsa('input:not([type=hidden]):not([type=submit]):not([type=button]):not([type=reset]):not([type=image]),select,textarea').filter(rendered);
      if (ctrls.length) {
        var lab = 0;
        ctrls.forEach(function (el) {
          if ((el.labels && el.labels.length && txt(Array.prototype.map.call(el.labels, function (l) { return l.textContent; }).join(' '))) ||
            txt(el.getAttribute('aria-label')) || byIds(el.getAttribute('aria-labelledby')) || txt(el.getAttribute('title'))) { lab += 1; }
          else if (txt(el.getAttribute('placeholder'))) { lab += 0.5; }
        });
        parts.push(lab / ctrls.length);
        detail.push('labels ' + round(lab, 1) + '/' + ctrls.length);
      }
      var btns = qsa('button,[role="button"],a[href],input[type=submit],input[type=button],input[type=reset],input[type=image]').filter(rendered);
      if (btns.length) {
        var named = btns.filter(function (b) {
          if (txt(b.getAttribute('aria-label')) || byIds(b.getAttribute('aria-labelledby')) || txt(b.getAttribute('title'))) { return true; }
          if (b.tagName === 'INPUT') { return !!(txt(b.value) || txt(b.getAttribute('alt'))); }
          if (txt(b.textContent)) { return true; }
          return qsa('img[alt],svg title', b).some(function (x) { return txt(x.getAttribute('alt') || x.textContent); });
        }).length;
        parts.push(named / btns.length);
        detail.push('names ' + named + '/' + btns.length);
      }
      var lang = txt(D.documentElement.getAttribute('lang'));
      parts.push(lang ? 1 : 0);
      detail.push('lang ' + (lang || 'missing'));
      var score = parts.reduce(function (a, b) { return a + b; }, 0) / parts.length;
      return res(c, score >= 0.85, score, detail.join(' · '));
    },
    animates: function (c) {
      return observe(1500).then(function (o) {
        var sig = [];
        if (o.canvasChanged) { sig.push('canvas pixels changed'); }
        if (o.styleChanged) { sig.push('element positions/styles changed'); }
        if (o.mutations > 0) { sig.push(o.mutations + ' DOM mutations'); }
        if (o.anims > 0) { sig.push(o.anims + ' running CSS/Web animations'); }
        var pass = sig.length > 0;
        return res(c, pass, pass ? 1 : 0, pass ? sig.join(' · ') : 'nothing changed over ' + round(o.ms / 1000, 1) + ' s' + (o.starved ? ' (browser produced few frames)' : ''));
      });
    },
    fps: function (c) {
      var min = Math.max(1, Number(c.min) || 30);
      return observe(1500).then(function (o) {
        var fps = round(o.fps, 1);
        return res(c, fps >= min, Math.min(1, fps / min), fps + ' fps (' + (o.pageLoop ? 'page rAF loop' : 'browser frames') + (o.p95 ? ', p95 frame ' + Math.round(o.p95) + ' ms' : '') + ', min ' + min + (o.starved ? ', host starved' : '') + ')', { value: fps });
      });
    },
    keyboard: function (c) {
      var keys = (Array.isArray(c.keys) && c.keys.length ? c.keys : DEFAULT_KEYS).slice(0, 12).map(normKey);
      var target;
      try { target = keyTarget(c); } catch (e) { return res(c, false, 0, 'invalid selector: ' + c.sel); }
      if (!target) { return res(c, false, 0, 'no element matches ' + c.sel); }
      var v0 = vec();
      return sleep(400).then(function () {
        var animated = changedSet(v0, vec()).size > 0;
        hits = 0;
        var changedBy = null;
        var novel = 0;
        var paused = false;
        var i = 0;
        var step = function () {
          if (i >= keys.length || changedBy) { return presolve(); }
          var k = keys[i++];
          // Animated pages: a control interval without input right before each key press.
          return (animated ? measure(260) : presolve(null)).then(function (ctl) {
            var before = vec();
            fire(target, keyEvent('keydown', k));
            if (k.length === 1 || k === 'Enter') { fire(target, keyEvent('keypress', k)); }
            return sleep(170).then(function () {
              fire(target, keyEvent('keyup', k));
              return sleep(90);
            }).then(function () {
              var ch = changedSet(before, vec());
              if (!animated && ch.size > 0) { changedBy = k; }
              if (animated && attributable(ch, ctl)) { novel = novelCount(ch, ctl); changedBy = k; }
              if (animated && !changedBy && stoppedAnim(ch, ctl)) { paused = true; changedBy = k; }
              return step();
            });
          });
        };
        return step().then(function () {
          var handlers = hits > 0 || hasPropHandler(target, ['onkeydown', 'onkeyup', 'onkeypress']);
          var where = describe(target);
          var label = changedBy === ' ' ? 'Space' : changedBy;
          if (!animated) {
            if (changedBy) { return res(c, true, 1, 'state changed after “' + label + '” (target ' + where + ')'); }
            return res(c, false, handlers ? 0.4 : 0, handlers ? 'key handlers ran but nothing visible changed' : 'no key handlers and no visible change (target ' + where + ')');
          }
          if (handlers && changedBy && paused) { return res(c, true, 1, 'key handlers ran; “' + label + '” stopped the ongoing animation'); }
          if (handlers && changedBy) { return res(c, true, 1, 'key handlers ran; “' + label + '” changed ' + novel + ' regions beyond the ongoing animation'); }
          if (handlers) { return res(c, true, 0.75, 'key handlers ran (effect not separable from the ongoing animation)'); }
          return res(c, false, 0, 'page animates on its own, but no key handlers responded');
        });
      });
    },
    click: function (c) {
      var el;
      try { el = clickTarget(c); } catch (e) { return res(c, false, 0, 'invalid selector: ' + c.sel); }
      if (!el) { return res(c, false, 0, c.sel ? 'no element matches ' + c.sel : 'no visible interactive element'); }
      var r0 = rectOf(el);
      if (r0 && (r0.top < 0 || r0.bottom > W.innerHeight || r0.left < 0 || r0.right > W.innerWidth)) {
        try { el.scrollIntoView({ block: 'center', inline: 'center' }); } catch (e) { /* ignore */ }
      }
      return sleep(120).then(function () {
        var v0 = vec();
        return sleep(300).then(function () {
          var animated = changedSet(v0, vec()).size > 0;
          return (animated ? measure(380) : presolve(null)).then(function (ctl) {
            var r = rectOf(el) || { left: 0, top: 0, width: 0, height: 0 };
            var x = Math.max(1, Math.min(W.innerWidth - 2, r.left + r.width / 2));
            var y = Math.max(1, Math.min(W.innerHeight - 2, r.top + r.height / 2));
            var hit = D.elementFromPoint(x, y) || el;
            var before = vec();
            hits = 0;
            pointerSeq(hit, x, y);
            return sleep(380).then(function () {
              var ch = changedSet(before, vec());
              var native = !!(hit.closest && hit.closest('[onclick],summary,label,select,input,a[href^="#"]'));
              var handlers = hits > 0 || hasPropHandler(hit, ['onclick', 'onmousedown', 'onpointerdown', 'onmouseup']) || native;
              var where = describe(hit) + (hit !== el && !el.contains(hit) ? ' (over ' + describe(el) + ')' : '');
              if (!animated) {
                if (ch.size > 0) { return res(c, true, 1, 'clicked ' + where + ' → state changed'); }
                return res(c, false, handlers ? 0.4 : 0, 'clicked ' + where + (handlers ? ' → handlers ran, nothing visible changed' : ' → no handlers, no change'));
              }
              if (handlers && attributable(ch, ctl)) { return res(c, true, 1, 'clicked ' + where + ' → handlers ran, ' + novelCount(ch, ctl) + ' regions changed beyond the animation'); }
              if (handlers && stoppedAnim(ch, ctl)) { return res(c, true, 1, 'clicked ' + where + ' → handlers ran, the ongoing animation stopped'); }
              if (handlers) { return res(c, true, 0.75, 'clicked ' + where + ' → handlers ran (effect not separable from the animation)'); }
              return res(c, false, 0, 'clicked ' + where + ' → no handlers responded');
            });
          });
        });
      });
    }
  });
  function noErrorsResult(c) {
    var weighted = counts.js + counts.rejection + 0.5 * (counts.console + counts.resource + counts.csp);
    var total = counts.js + counts.rejection + counts.console + counts.resource + counts.csp;
    var parts = [];
    if (counts.js) { parts.push(counts.js + ' uncaught'); }
    if (counts.rejection) { parts.push(counts.rejection + ' rejected promises'); }
    if (counts.console) { parts.push(counts.console + ' console.error'); }
    if (counts.resource + counts.csp) { parts.push((counts.resource + counts.csp) + ' failed/blocked resources'); }
    return res(c, total === 0, Math.max(0, 1 - weighted / 3), total === 0 ? 'no errors' : parts.join(' · ') + (errs[0] ? ' — ' + errs[0] : ''));
  }

  /* ------------------------------------------------------------ main sequence */
  var started = false;
  var finalized = false;
  var results = objCreate(null);
  var testResults = null;
  var harnessMode = null;
  var ORDER = ['loads', 'nonblank', 'canvas', 'selector', 'text', 'rtl', 'a11y_basic', 'responsive', 'animates', 'fps', 'keyboard', 'click'];

  function withTimeout(p, ms, label) {
    return new Promise(function (resolve, reject) {
      var t = setT(function () { reject(new Error(label + ' timed out')); }, ms);
      presolve(p).then(function (v) { clrT(t); resolve(v); }, function (e) { clrT(t); reject(e); });
    });
  }
  function settle() {
    return new Promise(function (resolve) {
      var done = false;
      var fin = function () { if (!done) { done = true; resolve(); } };
      rafO(function () { rafO(function () { setT(fin, SETTLE); }); });
      setT(fin, SETTLE + 400);
    });
  }
  function runTests() {
    if (!TESTS || LIGHT || CFG.output_type !== 'js') { return presolve(); }
    var H = W.WBHarness;
    var dataEl = D.getElementById('wb-harness-data');
    var code = '';
    try { code = JSON.parse(dataEl.textContent).code || ''; } catch (e) { code = ''; }
    if (!H || typeof H.run !== 'function') {
      testResults = [];
      for (var i = 0; i < TESTS.cases.length; i++) { append(testResults, rec({ name: String(TESTS.cases[i].name || 'case ' + (i + 1)), pass: false, ms: 0, error: 'harness unavailable' })); }
      return presolve();
    }
    return withTimeout(H.run(code, TESTS, { budgetMs: TESTS_BUDGET - 500 }), TESTS_BUDGET + 2000, 'tests').then(function (r) {
      testResults = r;
      harnessMode = H.mode || null;
      try { if (typeof H.render === 'function') { H.render(r); } } catch (e) { /* ignore */ }
    }, function (e) {
      addErr('js', 'Test harness: ' + (e && e.message));
    });
  }
  /** One check, run in its turn: its result lands in the prototype-less results map. */
  function runCheck(c, type) {
    return function () {
      if (finalized) { return; }
      checkTamper();
      if (LIGHT && !LIGHT_TYPES[type]) {
        results[c.id] = res(c, false, 0, 'not evaluated in the ' + VP + ' pass', { skipped: true });
        return;
      }
      var fn = CHECKERS[type];
      return withTimeout(presolve().then(function () { return fn(c); }), 12000, type).then(function (r) {
        results[c.id] = r;
      }, function (e) {
        results[c.id] = res(c, false, 0, 'probe error: ' + (e && e.message ? e.message : String(e)));
      });
    };
  }
  function start() {
    if (started) { return; }
    started = true;
    watchViewport();
    try { var leftover = qsa('script[data-wb-probe]'); for (var li = 0; li < leftover.length; li++) { leftover[li].parentNode.removeChild(leftover[li]); } } catch (e) { /* ignore */ }
    settle().then(function () {
      if (CFG.no_output) { return; }
      var chain = runTests();
      for (var oi = 0; oi < ORDER.length; oi++) {
        for (var ck = 0; ck < CHECKS.length; ck++) {
          if (CHECKS[ck].type === ORDER[oi]) { chain = chain.then(runCheck(CHECKS[ck], ORDER[oi])); }
        }
      }
      if (CFG.output_type === 'html' && !LIGHT) {
        // Frame-rate / animation metrics are always recorded for live pages (one shared 1.5 s window).
        chain = chain.then(function () { return finalized ? null : withTimeout(observe(1500), 5000, 'observe').catch(function () { return null; }); });
      }
      return chain.then(function () { return sleep(150); });
    }).then(function () { finalize('done'); }, function (e) {
      addErr('js', 'probe: ' + (e && e.message));
      finalize('error');
    });
  }

  function finalize(reason) {
    if (finalized) { return; }
    finalized = true;
    var tamper = checkTamper();
    if (tamper) { addErr('js', 'probe: the page modified built-in prototypes (' + tamper + '); browser checks are not trusted'); }
    var i;
    var checks = [];
    for (i = 0; i < CHECKS.length; i++) {
      var c = CHECKS[i];
      var r;
      if (tamper) { r = res(c, false, 0, 'not trusted: the page modified built-in prototypes (' + tamper + ')'); }
      else if (CFG.no_output) { r = res(c, false, 0, 'no renderable output'); }
      else if (c.type === 'no_errors') { r = noErrorsResult(c); }
      else if (results[c.id]) { r = results[c.id]; }
      else if (!CHECKERS[c.type]) { r = res(c, false, 0, 'unknown check type “' + c.type + '”'); }
      else if (reason === 'navigated') { r = res(c, false, 0, 'the page navigated away before this check ran'); }
      else { r = res(c, false, 0, reason === 'deadline' ? 'not evaluated before the probe deadline (' + Math.round(DEADLINE / 1000) + ' s)' : 'not evaluated'); }
      append(checks, r);
    }
    var tests = null;
    if (isArray(testResults)) {
      tests = [];
      for (i = 0; i < testResults.length; i++) { append(tests, clean(testResults[i], 0)); }
    } else if (TESTS && CFG.output_type === 'js' && !LIGHT) {
      tests = [];
      for (i = 0; i < TESTS.cases.length; i++) {
        append(tests, rec({ name: String(TESTS.cases[i].name || 'case ' + (i + 1)), pass: false, ms: 0, error: CFG.no_output ? 'no code' : (reason === 'navigated' ? 'page navigated away' : 'not run before the probe deadline') }));
      }
    }
    // Out-of-memory errors (tests or page) — the verifier re-runs such pages in a fresh frame/context.
    var oom = 0;
    var OOM_RE = /Array buffer allocation failed|out of memory|Allocation failed - JavaScript heap/i;
    for (i = 0; tests && i < tests.length; i++) { var t = tests[i]; if (t && (t.oom || OOM_RE.test(String(t.error || '')))) { oom++; } }
    for (i = 0; i < errs.length; i++) { if (OOM_RE.test(errs[i])) { oom++; } }
    var errors = [];
    for (i = 0; i < errs.length && i < 20; i++) { append(errors, errs[i]); }
    var metrics = rec({
      load_ms: times.load !== null ? Math.round(times.load) : null,
      dom_ready_ms: times.dom !== null ? Math.round(times.dom) : null,
      fps: lastFps,
      js_errors: counts.js + counts.rejection,
      console_errors: counts.console,
      resource_errors: counts.resource + counts.csp,
      dom_nodes: D.getElementsByTagName('*').length,
      probe_ms: Math.round(now() - T0),
      finished: reason,
      hidden: flags.hidden,
      offscreen: flags.offscreen,
      starved: flags.starved,
      oom: oom,
      harness: harnessMode,
      tampered: !!tamper
    });
    var viewport = rec({ name: VP, w: W.innerWidth, h: W.innerHeight, light: LIGHT });
    var runId = CFG.run_id || null;
    // Exposed to the page realm (Playwright reads it): no nonce in here.
    var out = rec({ type: 'wb-probe', run_id: runId, viewport: viewport, checks: checks, tests: tests || [], errors: errors, metrics: metrics });
    try { RESULT_JSON = toJson(out); } catch (e) { RESULT_JSON = null; }
    RESULT = deepFreeze(out);
    post({ type: 'wb-probe', nonce: NONCE, run_id: runId, viewport: viewport, checks: checks, tests: tests || [], errors: errors, metrics: metrics });
  }
  function deepFreeze(o) {
    if (o && typeof o === 'object') {
      var ks = objKeys(o);
      for (var i = 0; i < ks.length; i++) { deepFreeze(o[ks[i]]); }
      freeze(o);
    }
    return o;
  }
  /**
   * JSON text of a result value: own enumerable data only, never toJSON (JSON.stringify would call a page-defined
   * Object.prototype.toJSON with the result in hand). Strings are concatenated, never collected in arrays.
   */
  function toJson(v) {
    if (v === null || v === undefined) { return 'null'; }
    var t = typeof v;
    if (t === 'string') { return jsonStr(v); }
    if (t === 'number') { return isFinite(v) ? jsonStr(v) : 'null'; }
    if (t === 'boolean') { return v ? 'true' : 'false'; }
    if (t !== 'object') { return 'null'; }
    var s = '';
    var i;
    if (isArray(v)) {
      for (i = 0; i < v.length; i++) { s += (i ? ',' : '') + toJson(v[i]); }
      return '[' + s + ']';
    }
    var ks = objKeys(v);
    for (i = 0; i < ks.length; i++) {
      var x = v[ks[i]];
      if (x === undefined || typeof x === 'function') { continue; }
      s += (s ? ',' : '') + jsonStr(ks[i]) + ':' + toJson(x);
    }
    return '{' + s + '}';
  }

  /* ------------------------------------------------------------ screenshots (Station mode)
   * Canvas, serialisation and image primitives are the originals captured at start, so a page cannot hand the
   * Station a fake picture by patching toDataURL / drawImage / XMLSerializer / Image.src. */
  function ctx2d(cv) { return apply(cvGetContext, cv, ['2d']); }
  function drawOn(g, src, x, y, w, h) { apply(cxDrawImage, g, w === undefined ? [src, x, y] : [src, x, y, w, h]); }
  function pageBackground() {
    var pick = function (el) {
      if (!el) { return null; }
      var b = gcs(el).backgroundColor;
      return b && b !== 'transparent' && !/rgba\(0, 0, 0, 0\)/.test(b) ? b : null;
    };
    return pick(D.documentElement) || pick(D.body) || '#ffffff';
  }
  function readable(c) {
    try {
      var x = D.createElement('canvas'); x.width = 1; x.height = 1;
      var g = ctx2d(x); drawOn(g, c, 0, 0, 1, 1); apply(cxGetImageData, g, [0, 0, 1, 1]);
      return true;
    } catch (e) { return false; }
  }
  function compose(cv) {
    var g = ctx2d(cv);
    var list = qsa('canvas');
    for (var i = 0; i < list.length; i++) {
      var c = list[i];
      if (c === SC || c === cv || !isVisible(c, 4) || !readable(c)) { continue; }
      var r = rectOf(c);
      try { drawOn(g, c, r.left, r.top, r.width, r.height); } catch (e) { /* ignore */ }
    }
  }
  function encode(cv) {
    var u = apply(cvToDataURL, cv, ['image/png']);
    if (u.length > 2800000) {
      var w = apply(cvToDataURL, cv, ['image/webp', 0.86]);
      if (w.indexOf('data:image/webp') === 0) { u = w; }
    }
    if (u.length > 5300000) { u = apply(cvToDataURL, cv, ['image/jpeg', 0.78]); }
    return u;
  }
  function blankCanvas(w, h, bg) {
    var cv = D.createElement('canvas');
    cv.width = w;
    cv.height = h;
    var g = ctx2d(cv);
    if (cxFillStyle) { apply(cxFillStyle, g, [bg]); } else { g.fillStyle = bg; }
    apply(cxFillRect, g, [0, 0, w, h]);
    return cv;
  }
  /**
   * DOM → SVG <foreignObject> → <img> → canvas. Works inside the opaque-origin sandbox (html2canvas cannot:
   * it clones the page into a child iframe, which is cross-origin there, so it is not loaded at all).
   * Scripts are dropped, form values copied; canvases are drawn on top afterwards by compose().
   */
  function svgCapture(w, h, bg) {
    var root = apply(cloneNodeFn, D.documentElement, [true]);
    var drop = qsa('script,noscript,iframe,object,embed,[data-wb-probe]', root);
    for (var d = 0; d < drop.length; d++) { if (drop[d].parentNode) { drop[d].parentNode.removeChild(drop[d]); } }
    var live = qsa('input,textarea,select');
    var copy = qsa('input,textarea,select', root);
    for (var i = 0; i < live.length && i < copy.length; i++) {
      var a = live[i];
      var b = copy[i];
      if (a.tagName === 'TEXTAREA') { b.textContent = a.value; continue; }
      if (a.tagName === 'SELECT') {
        for (var k = 0; k < b.options.length; k++) { if (k === a.selectedIndex) { b.options[k].setAttribute('selected', ''); } else { b.options[k].removeAttribute('selected'); } }
        continue;
      }
      if (a.type === 'checkbox' || a.type === 'radio') { if (a.checked) { b.setAttribute('checked', ''); } else { b.removeAttribute('checked'); } continue; }
      if (a.type !== 'file' && a.type !== 'password') { b.setAttribute('value', a.value); }
    }
    root.style.width = W.innerWidth + 'px';
    root.style.minHeight = W.innerHeight + 'px';
    var xml = apply(serializeFn, new XMLS(), [root]);
    var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' + w + '" height="' + h + '"><foreignObject x="0" y="0" width="' + W.innerWidth + '" height="' + Math.max(h, W.innerHeight) + '">' + xml + '</foreignObject></svg>';
    return new Promise(function (resolve, reject) {
      var img = new ImageC();
      apply(addO, img, ['load', function () {
        try {
          var cv = blankCanvas(w, h, bg);
          var g = ctx2d(cv);
          drawOn(g, img, 0, 0);
          apply(cxGetImageData, g, [0, 0, 1, 1]); // throws when the browser tainted the canvas
          resolve(cv);
        } catch (e) { reject(e); }
      }]);
      apply(addO, img, ['error', function () { reject(new Error('SVG snapshot could not be rendered')); }]);
      var url = 'data:image/svg+xml;charset=utf-8,' + encURI(svg);
      if (imgSrcSet) { apply(imgSrcSet, img, [url]); } else { img.src = url; }
    });
  }
  function capture() {
    try { W.scrollTo(0, 0); } catch (e) { /* ignore */ }
    var w = Math.max(16, Math.min(W.innerWidth, 1600));
    var h = Math.max(16, Math.min(W.innerHeight, 1400));
    var bg = pageBackground();
    var finish = function (cv) { compose(cv); return encode(cv); };
    // Fallback when the SVG snapshot fails: page background + the page's own canvases (games, WebGL).
    var fallback = function () { return finish(blankCanvas(w, h, bg)); };
    return withTimeout(svgCapture(w, h, bg), 8000, 'snapshot').then(function (cv) {
      try { return finish(cv); } catch (e) { return fallback(); }
    }, fallback);
  }
  var shooting = false;
  addO.call(W, 'message', function (ev) {
    if (ev.source !== PARENT || IS_TOP) { return; }
    var d = ev.data;
    if (!d || typeof d !== 'object' || d.type !== 'wb-shot') { return; }
    if (ev.stopImmediatePropagation) { ev.stopImmediatePropagation(); }
    if (shooting) { return; }
    shooting = true;
    capture().then(function (url) {
      post({ type: 'wb-shot', nonce: NONCE, dataUrl: String(url) });
    }, function (e) {
      post({ type: 'wb-shot', nonce: NONCE, error: String(e && e.message || e) });
    }).then(function () { shooting = false; });
  }, true);
})();
