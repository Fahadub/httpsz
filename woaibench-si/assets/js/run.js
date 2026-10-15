/* Run page: accessible tabs, render-frame toolbar (device widths, reload, fullscreen), code copy/download. Module: public. */
(function () {
  'use strict';

  var strings = {};
  try { strings = JSON.parse((document.getElementById('pub-i18n') || {}).textContent || '{}'); } catch (e) { strings = {}; }
  function t(key) { return strings['pub.js.' + key] || key; }
  function toast(msg, type) { if (window.WB && WB.toast) WB.toast(msg, type || 'info'); }

  /* ---------- tabs ---------- */
  var root = document.querySelector('[data-run-tabs]');
  if (root) {
    var tabs = Array.prototype.slice.call(root.querySelectorAll('[data-run-tab]'));
    var select = function (key, focus, push) {
      tabs.forEach(function (tab) {
        var on = tab.getAttribute('data-run-tab') === key;
        tab.classList.toggle('active', on);
        tab.setAttribute('aria-selected', on ? 'true' : 'false');
        tab.tabIndex = on ? 0 : -1;
        if (on && focus) tab.focus();
        if (on && window.WB && WB.centerInScroller) WB.centerInScroller(tab.parentNode, tab);
      });
      root.querySelectorAll('[data-run-panel]').forEach(function (p) { p.hidden = p.getAttribute('data-run-panel') !== key; });
      if (push && window.history && history.replaceState) {
        var u = new URL(location.href);
        u.searchParams.set('tab', key);
        history.replaceState(null, '', u.pathname + u.search + u.hash);
      }
      if (key === 'preview') sizeFrame();
    };
    tabs.forEach(function (tab, i) {
      tab.addEventListener('click', function (e) { e.preventDefault(); select(tab.getAttribute('data-run-tab'), false, true); });
      tab.addEventListener('keydown', function (e) {
        var rtl = document.documentElement.dir === 'rtl';
        var next = { ArrowRight: rtl ? -1 : 1, ArrowLeft: rtl ? 1 : -1 }[e.key];
        if (e.key === 'Home') next = -i;
        if (e.key === 'End') next = tabs.length - 1 - i;
        if (next === undefined) return;
        e.preventDefault();
        var target = tabs[(i + next + tabs.length) % tabs.length];
        select(target.getAttribute('data-run-tab'), true, true);
      });
    });
  }

  /* ---------- render frame (device switching itself is handled by app.js .device-toggle) ---------- */
  var frame = document.querySelector('[data-run-frame]');
  var iframe = document.querySelector('[data-run-iframe]');
  var sizeEl = document.querySelector('[data-run-size]');
  function sizeFrame() {
    if (!iframe || !sizeEl) return;
    requestAnimationFrame(function () {
      var r = iframe.getBoundingClientRect();
      if (r.width) sizeEl.textContent = Math.round(r.width) + ' × ' + Math.round(r.height);
    });
  }
  if (frame && iframe) {
    frame.addEventListener('click', function (e) {
      if (e.target.closest('.device-toggle [data-device]')) setTimeout(sizeFrame, 450);
    });
    var reload = frame.querySelector('[data-run-reload]');
    if (reload) reload.addEventListener('click', function () {
      // Re-assigning src reloads the sandboxed document (we never touch its contentWindow).
      var src = iframe.getAttribute('src');
      iframe.setAttribute('src', 'about:blank');
      setTimeout(function () { iframe.setAttribute('src', src); }, 30);
    });
    var fs = frame.querySelector('[data-run-fullscreen]');
    if (fs) {
      if (!frame.requestFullscreen) fs.hidden = true;
      fs.addEventListener('click', function () {
        if (document.fullscreenElement) document.exitFullscreen();
        else if (frame.requestFullscreen) frame.requestFullscreen().catch(function () { toast(t('fullscreen_error'), 'error'); });
      });
      document.addEventListener('fullscreenchange', function () {
        frame.classList.toggle('is-full', document.fullscreenElement === frame);
        sizeFrame();
      });
    }
    // Phones: the frame is phone-sized anyway, so the toggle says "Mobile" instead of claiming "Desktop".
    if (window.innerWidth < 600 && frame.getAttribute('data-device') === 'desktop') {
      frame.setAttribute('data-device', 'mobile');
      Array.prototype.forEach.call(frame.querySelectorAll('.device-toggle [data-device]'), function (b) {
        b.setAttribute('aria-pressed', b.getAttribute('data-device') === 'mobile' ? 'true' : 'false');
      });
    }
    window.addEventListener('resize', sizeFrame);
    iframe.addEventListener('load', sizeFrame);
    sizeFrame();
  }

  /* ---------- code copy / download ---------- */
  var codeEl = document.querySelector('[data-run-code]');
  function codeText() {
    if (!codeEl) return '';
    var lines = Array.prototype.map.call(codeEl.querySelectorAll('.ln'), function (l) { return l.textContent; });
    return lines.join('\n');
  }
  var copyBtn = document.querySelector('[data-run-copy]');
  if (copyBtn && codeEl) copyBtn.addEventListener('click', function () {
    var text = codeText();
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () { toast(t('copied'), 'success'); }, function () { toast(t('copy_failed'), 'error'); });
    } else {
      var range = document.createRange();
      range.selectNodeContents(codeEl);
      var sel = window.getSelection();
      sel.removeAllRanges();
      sel.addRange(range);
      toast(t('select_copy'), 'info');
    }
  });
  var dl = document.querySelector('[data-run-download]');
  if (dl && codeEl) dl.addEventListener('click', function () {
    var blob = new Blob([codeText()], { type: (dl.getAttribute('data-mime') || 'text/plain') + ';charset=utf-8' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = dl.getAttribute('data-filename') || 'output.txt';
    document.body.appendChild(a);
    a.click();
    setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
  });
})();
