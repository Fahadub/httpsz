/* WOAIBench.si installer behaviours (inlined with the CSP nonce; no external requests). */
(function () {
  'use strict';
  var T = {};
  try { T = JSON.parse(document.getElementById('setup-i18n').textContent || '{}'); } catch (e) {}
  function t(k, fb) { return T[k] || fb || k; }
  function $(s, r) { return (r || document).querySelector(s); }
  function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }

  // Theme toggle (same storage key as the site).
  $$('[data-theme-toggle]').forEach(function (b) {
    b.addEventListener('click', function () {
      var cur = document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
      document.documentElement.setAttribute('data-theme', cur);
      try { localStorage.setItem('wb_theme', cur); } catch (e) {}
    });
  });

  // Progress bar (exact width; CSS has stepped fallbacks).
  $$('[data-progress]').forEach(function (el) { el.style.width = el.getAttribute('data-progress') + '%'; });

  // Busy state on submit (prevents double installs).
  $$('form[data-busy]').forEach(function (f) {
    f.addEventListener('submit', function (ev) {
      var b = ev.submitter || $('button[type=submit]:not(.default-submit)', f);
      if (!b || b.hasAttribute('data-test-key')) { return; }
      if (f.getAttribute('data-sent')) { ev.preventDefault(); return; }
      f.setAttribute('data-sent', '1');
      b.classList.add('is-busy');
      if (b.getAttribute('data-busy-text')) {
        var label = Array.prototype.find.call(b.childNodes, function (n) { return n.nodeType === 3 && n.textContent.trim(); });
        if (label) { label.textContent = b.getAttribute('data-busy-text'); }
      }
    });
  });

  // Show / hide password fields.
  $$('[data-reveal]').forEach(function (b) {
    b.addEventListener('click', function () {
      var i = $(b.getAttribute('data-reveal'));
      if (!i) { return; }
      i.type = i.type === 'password' ? 'text' : 'password';
      b.setAttribute('aria-pressed', i.type === 'text' ? 'true' : 'false');
    });
  });

  // Password strength meter (length + character variety; mirrors the server's 12-char minimum).
  var pw = $('[data-meter-input]'), meter = $('[data-meter]');
  if (pw && meter) {
    var lbl = $('[data-meter-label]', meter), initial = lbl ? lbl.textContent : '';
    var score = function (v) {
      if (!v) { return -1; }
      if (v.length < 12) { return 0; }
      var kinds = [/[a-z]/, /[A-Z]/, /[0-9]/, /[^A-Za-z0-9]/].filter(function (r) { return r.test(v); }).length;
      var uniq = new Set(v.split('')).size;
      var s = 1 + (v.length >= 16 ? 1 : 0) + (v.length >= 20 ? 1 : 0) + (kinds >= 3 ? 1 : 0);
      if (uniq < 6) { s = 1; }
      return Math.min(4, s);
    };
    pw.addEventListener('input', function () {
      var s = score(pw.value);
      meter.setAttribute('data-level', String(Math.max(0, s)));
      if (lbl) { lbl.textContent = s < 0 ? initial : t('setup.strength_' + s); }
    });
  }

  // "Test key" without leaving the page.
  $$('[data-test-key]').forEach(function (b) {
    b.addEventListener('click', function (ev) {
      if (!window.fetch) { return; }
      ev.preventDefault();
      var f = b.form, out = $('[data-key-result]');
      var fd = new FormData();
      fd.append('_csrf', f.querySelector('[name=_csrf]').value);
      fd.append('action', 'test_key');
      fd.append('openrouter_key', (f.querySelector('[name=openrouter_key]') || {}).value || '');
      b.disabled = true;
      out.className = 'key-result';
      out.textContent = t('setup.api_testing');
      fetch(f.getAttribute('action'), { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          out.className = 'key-result ' + (j.ok ? (j.state === 'reachable' ? 'is-warn' : 'is-ok') : 'is-bad');
          out.textContent = j.message || '';
        })
        .catch(function () { out.className = 'key-result is-bad'; out.textContent = t('setup.js_network'); })
        .then(function () { b.disabled = false; });
    });
  });

  // URL-rewrite probe: before installation every app route redirects to /install/, so a redirect
  // (or any non-404 answer) proves requests reach index.php through the rewrite rules.
  $$('[data-rewrite-check]').forEach(function (row) {
    if (!window.fetch) { return; }
    var detail = $('[data-detail] bdi', row) || $('[data-detail]', row);
    var setState = function (st, text) {
      row.className = 'check st-' + st;
      var use = $('.check-icon use', row);
      if (use) { use.setAttribute('href', '#i-' + ({ ok: 'check', warn: 'alert', checking: 'info' })[st]); }
      if (detail) { detail.textContent = text; }
      var hint = $('[data-hint]', row);
      if (hint) { hint.hidden = st === 'ok'; }
    };
    setState('checking', t('setup.st_checking'));
    fetch(row.getAttribute('data-url') + '?probe=' + Date.now(), { redirect: 'manual', cache: 'no-store', credentials: 'omit' })
      .then(function (r) {
        var ok = r.type === 'opaqueredirect' || (r.status > 0 && r.status !== 404);
        setState(ok ? 'ok' : 'warn', ok ? t('setup.js_rewrite_ok') : t('setup.js_rewrite_bad'));
      })
      .catch(function () { setState('warn', t('setup.js_rewrite_unknown')); });
  });

  // Copy buttons.
  $$('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var src = $(b.getAttribute('data-copy'));
      if (!src) { return; }
      var text = src.textContent, label = $('span', b);
      var done = function () {
        b.classList.add('is-copied');
        if (label) { label.textContent = t('setup.copied'); }
        setTimeout(function () { b.classList.remove('is-copied'); if (label) { label.textContent = t('setup.copy'); } }, 1800);
      };
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(done, function () {});
      } else {
        var r = document.createRange(); r.selectNodeContents(src);
        var s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
        try { document.execCommand('copy'); done(); } catch (e) {}
      }
    });
  });
})();
