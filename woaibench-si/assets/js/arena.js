/* WOAIBench.si — Arena: blind duel voting + reveal, and the Arena leaderboard scatter chart. */
(function () {
  'use strict';

  function readJSON(id) {
    var el = document.getElementById(id);
    if (!el) return null;
    try { return JSON.parse(el.textContent || 'null'); } catch (e) { return null; }
  }

  function csrf() {
    var m = document.querySelector('meta[name="csrf-token"]');
    return m ? m.getAttribute('content') : '';
  }

  /** fetch() wrapper that always resolves to a JSON object with the HTTP status attached. */
  function api(url, body) {
    var opts = { method: body ? 'POST' : 'GET', credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
    if (body) {
      opts.headers['Content-Type'] = 'application/json';
      opts.headers['X-CSRF-Token'] = csrf();
      opts.body = JSON.stringify(body);
    }
    return fetch(url, opts).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: 'network' }; }).then(function (j) {
        j = j || {};
        j.status = r.status;
        return j;
      });
    }, function () { return { ok: false, error: 'network', status: 0 }; });
  }

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined && text !== null) n.textContent = text;
    return n;
  }

  function icon(sprite, name, size) {
    var ns = 'http://www.w3.org/2000/svg';
    var svg = document.createElementNS(ns, 'svg');
    svg.setAttribute('class', 'icon');
    svg.setAttribute('width', size || 14);
    svg.setAttribute('height', size || 14);
    svg.setAttribute('aria-hidden', 'true');
    var use = document.createElementNS(ns, 'use');
    use.setAttribute('href', sprite + '#i-' + String(name).replace(/[^a-z0-9-]/g, ''));
    svg.appendChild(use);
    return svg;
  }

  function fmt1(n) { return n === null || n === undefined ? '—' : Number(n).toFixed(1); }

  function countUp(node, to, ms) {
    if (to === null || to === undefined || !window.requestAnimationFrame || matchMedia('(prefers-reduced-motion: reduce)').matches) {
      node.textContent = to === null || to === undefined ? '—' : String(Math.round(to));
      return;
    }
    var from = 1000, t0 = null;
    function step(t) {
      if (t0 === null) t0 = t;
      var p = Math.min(1, (t - t0) / ms), eased = 1 - Math.pow(1 - p, 3);
      node.textContent = String(Math.round(from + (to - from) * eased));
      if (p < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  }

  /* ------------------------------------------------------------------ duel page */

  function initDuel() {
    var stage = document.getElementById('arn-stage');
    var data = readJSON('arn-data');
    if (!stage || !data) return;
    var T = data.i18n || {};
    var t = function (k, rep) {
      var s = T['arena.' + k] || k;
      if (rep) Object.keys(rep).forEach(function (r) { s = s.split(':' + r).join('\u2068' + rep[r] + '\u2069'); });
      return s;
    };
    var pair = data.pair;
    var busy = false;
    var sides = { a: stage.querySelector('[data-side="a"]'), b: stage.querySelector('[data-side="b"]') };
    var buttons = Array.prototype.slice.call(stage.querySelectorAll('[data-vote]'));
    var result = stage.querySelector('.arn-result');
    var nextBtn = stage.querySelector('.arn-next');
    var taskLink = stage.querySelector('.arn-task-link');

    function setState(s) { stage.setAttribute('data-state', s); }

    function notify(msg, type) {
      if (window.WB && typeof WB.toast === 'function') { WB.toast(msg, type || 'error'); return; }
      var a = stage.querySelector('.arn-inline-alert');
      if (!a) { a = el('div', 'alert alert-' + (type === 'success' ? 'success' : 'error') + ' arn-inline-alert'); a.setAttribute('role', 'status'); stage.appendChild(a); }
      a.textContent = msg;
    }

    function setButtons(enabled) {
      buttons.forEach(function (b) { b.disabled = !enabled; });
    }

    function watchFrame(side) {
      var frame = sides[side].querySelector('.arn-frame');
      var iframe = frame.querySelector('iframe');
      frame.classList.add('is-loading');
      iframe.addEventListener('load', function () { frame.classList.remove('is-loading'); }, { once: true });
    }

    function renderTask(task) {
      var trackBox = stage.querySelector('.arn-task-track');
      trackBox.textContent = '';
      if (task.track) {
        var badge = el('span', 'track-badge');
        badge.style.setProperty('--track', task.track.color || '#D9B26A');
        badge.appendChild(icon(data.icons, task.track.icon || 'layers', 14));
        badge.appendChild(el('span', '', task.track.name));
        trackBox.appendChild(badge);
      }
      var diffBox = stage.querySelector('.arn-task-diff');
      diffBox.textContent = '';
      var lvl = Math.max(1, Math.min(4, task.difficulty || 2));
      var diff = el('span', 'difficulty d-' + lvl);
      diff.title = data.difficulty[lvl] || '';
      var dots = el('span', 'difficulty-dots');
      for (var i = 1; i <= 4; i++) dots.appendChild(el('i', i <= lvl ? 'on' : ''));
      diff.appendChild(dots);
      diff.appendChild(el('span', 'difficulty-label', data.difficulty[lvl] || ''));
      diffBox.appendChild(diff);
      stage.querySelector('.arn-task-title').textContent = task.title || '';
      stage.querySelector('.arn-task-summary').textContent = task.summary || '';
    }

    function loadPair(p) {
      pair = p;
      renderTask(p.task || {});
      ['a', 'b'].forEach(function (side) {
        var box = sides[side];
        box.classList.remove('is-winner', 'is-loser', 'is-tie', 'is-bad', 'is-revealed');
        box.querySelector('.arn-identity').hidden = true;
        box.querySelector('.arn-identity').textContent = '';
        box.querySelector('.arn-anon').hidden = false;
        box.querySelector('[data-open]').setAttribute('href', p.frames[side]);
        watchFrame(side);
        box.querySelector('iframe').setAttribute('src', p.frames[side]);
      });
      result.hidden = true;
      taskLink.hidden = true;
      buttons.forEach(function (b) { b.classList.remove('is-chosen'); });
      setButtons(true);
      setState('voting');
    }

    function fetchNext() {
      if (busy) return;
      busy = true;
      setButtons(false);
      setState('loading');
      api(data.endpoints.next).then(function (res) {
        busy = false;
        if (res.ok && res.pair) {
          loadPair(res.pair);
          var title = stage.querySelector('.arn-task-title');
          title.setAttribute('tabindex', '-1');
          if (document.activeElement === nextBtn || document.activeElement === document.body) title.focus({ preventScroll: false });
        } else {
          setState('error');
          notify(res.message || t('js_error'));
        }
      });
    }

    function identity(box, info, side, outcome) {
      var idBox = box.querySelector('.arn-identity');
      idBox.textContent = '';
      var av = el('span', 'avatar', (info.name || '?').replace(/[^\p{L}\p{N}]/gu, '').charAt(0).toUpperCase() || '?');
      av.style.setProperty('--av', info.color || '#888');
      av.style.setProperty('--size', '26px');
      av.setAttribute('aria-hidden', 'true');
      idBox.appendChild(av);
      var txt = el('span', 'arn-identity-text');
      var name = info.url ? el('a', 'arn-model-name', info.name) : el('span', 'arn-model-name', info.name);
      name.dir = 'auto';
      if (info.url) { name.href = info.url; name.target = '_blank'; name.rel = 'noopener'; }
      txt.appendChild(name);
      var meta = el('span', 'arn-model-meta');
      meta.appendChild(el('span', '', info.provider || ''));
      if (info.score !== null && info.score !== undefined) {
        meta.appendChild(el('span', 'arn-dot', '·'));
        meta.appendChild(el('span', 't-num', t('js_bench', { s: fmt1(info.score) })));
      }
      if (info.rating !== null && info.rating !== undefined) {
        meta.appendChild(el('span', 'arn-dot', '·'));
        var r = el('span', 't-num');
        r.appendChild(document.createTextNode(t('js_elo') + ' '));
        var num = el('b', 'arn-elo', '1000');
        r.appendChild(num);
        meta.appendChild(r);
        countUp(num, info.rating, 900);
      }
      txt.appendChild(meta);
      idBox.appendChild(txt);
      var tag = outcome === 'win' ? t('js_winner') : (outcome === 'tie' ? t('js_tie_tag') : (outcome === 'bad' ? t('js_bad_tag') : ''));
      if (tag) idBox.appendChild(el('span', 'badge ' + (outcome === 'win' ? 'badge-gold' : 'badge-muted') + ' arn-outcome', tag));
      box.querySelector('.arn-anon').hidden = true;
      idBox.hidden = false;
      box.classList.add('is-revealed');
    }

    function reveal(res, choice) {
      var rv = res.reveal || {};
      ['a', 'b'].forEach(function (side, i) {
        var outcome = choice === side ? 'win' : (choice === 'tie' ? 'tie' : (choice === 'both_bad' ? 'bad' : 'lose'));
        var box = sides[side];
        box.classList.add(outcome === 'win' ? 'is-winner' : outcome === 'lose' ? 'is-loser' : outcome === 'tie' ? 'is-tie' : 'is-bad');
        setTimeout(function () { identity(box, rv[side] || {}, side, outcome); }, 120 + i * 180);
      });
      var a = rv.a || {}, b = rv.b || {};
      var line;
      if (choice === 'a' || choice === 'b') {
        var w = choice === 'a' ? a : b, l = choice === 'a' ? b : a;
        line = t('js_you_picked', { w: w.name || '?', l: l.name || '?' });
      } else if (choice === 'tie') {
        line = t('js_you_tied', { a: a.name || '?', b: b.name || '?' });
      } else {
        line = t('js_you_bad', { a: a.name || '?', b: b.name || '?' });
      }
      result.querySelector('.arn-result-line').textContent = line;
      var sub = '';
      if (a.score !== null && a.score !== undefined && b.score !== null && b.score !== undefined) {
        var agree = choice === 'a' ? a.score >= b.score : choice === 'b' ? b.score >= a.score : null;
        sub = agree === null ? t('js_sub_scores', { a: fmt1(a.score), b: fmt1(b.score) })
          : t(agree ? 'js_sub_agree' : 'js_sub_disagree', { a: fmt1(a.score), b: fmt1(b.score) });
      }
      if (res.votes_total) sub += (sub ? ' ' : '') + t('js_total', { n: Number(res.votes_total).toLocaleString('en-US') });
      result.querySelector('.arn-result-sub').textContent = sub;
      if (res.task_url) { taskLink.href = res.task_url; taskLink.hidden = false; }
      result.hidden = false;
      setState('revealed');
      setTimeout(function () { nextBtn.focus({ preventScroll: true }); }, 450);
    }

    function vote(choice) {
      if (busy || !pair || stage.getAttribute('data-state') !== 'voting') return;
      busy = true;
      setButtons(false);
      setState('sending');
      buttons.forEach(function (b) { b.classList.toggle('is-chosen', b.getAttribute('data-vote') === choice); });
      api(data.endpoints.vote, { token: pair.token, winner: choice }).then(function (res) {
        busy = false;
        if (res.ok) { reveal(res, choice); return; }
        if (res.error === 'used' || res.error === 'expired' || res.error === 'invalid' || res.error === 'foreign') {
          notify(res.message || t('js_error'), 'warn');
          fetchNext();
          return;
        }
        notify(res.message || t('js_error'));
        setState('voting');
        setButtons(res.status !== 429);
      });
    }

    buttons.forEach(function (b) {
      b.addEventListener('click', function () { vote(b.getAttribute('data-vote')); });
    });
    nextBtn.addEventListener('click', fetchNext);
    Array.prototype.forEach.call(stage.querySelectorAll('[data-reload]'), function (btn) {
      btn.addEventListener('click', function () {
        var box = btn.closest('.arn-side');
        var iframe = box.querySelector('iframe');
        if (!iframe.getAttribute('src')) return;
        watchFrame(box.getAttribute('data-side'));
        iframe.setAttribute('src', iframe.getAttribute('src'));
      });
    });

    document.addEventListener('keydown', function (e) {
      if (e.altKey || e.ctrlKey || e.metaKey || e.isComposing) return;
      var tag = (e.target && e.target.tagName) || '';
      if (/^(INPUT|TEXTAREA|SELECT)$/.test(tag) || (e.target && e.target.isContentEditable)) return;
      var st = stage.getAttribute('data-state');
      var map = { '1': 'a', '2': 'b', '3': 'tie', '4': 'both_bad' };
      if (st === 'voting' && map[e.key]) { e.preventDefault(); vote(map[e.key]); return; }
      if (st === 'revealed' && (e.key === 'n' || e.key === 'N' || (e.key === 'Enter' && tag !== 'A' && tag !== 'BUTTON'))) {
        e.preventDefault();
        fetchNext();
      }
    });

    // Server-rendered frames start loading before this deferred script runs (and an opaque-origin
    // sandboxed frame cannot be inspected), so the loading veil is only used for frames we swap in.
    if (!pair && data.state === 'ready') {
      fetchNext();
    }
  }

  /* ------------------------------------------------------------------ leaderboard chart */

  function initChart() {
    var canvas = document.getElementById('arn-scatter');
    var data = readJSON('arn-lb-data');
    if (!canvas || !data || !data.points || !data.points.length || typeof window.Chart === 'undefined') return;
    var css = getComputedStyle(document.documentElement);
    var v = function (n, f) { return (css.getPropertyValue(n) || '').trim() || f; };
    var colors = (window.WB && typeof WB.colors === 'function') ? WB.colors() : {};
    var text = colors.textMuted || colors['text-muted'] || v('--text-muted', '#9a978f');
    var grid = colors.border || v('--border', 'rgba(255,255,255,.08)');
    var cfg = {
      type: 'scatter',
      data: {
        datasets: [{
          label: data.label,
          data: data.points.map(function (p) { return { x: p.x, y: p.y, name: p.name }; }),
          pointRadius: 6,
          pointHoverRadius: 8,
          pointBackgroundColor: data.points.map(function (p) { return p.color; }),
          pointBorderColor: v('--bg', '#07080C'),
          pointBorderWidth: 1.5
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: { duration: 500 },
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: function (c) { return c.raw.name + ' — ' + data.xLabel + ' ' + c.raw.x.toFixed(1) + ' · ' + data.yLabel + ' ' + Math.round(c.raw.y); }
            }
          }
        },
        scales: {
          x: { title: { display: true, text: data.xLabel, color: text }, ticks: { color: text }, grid: { color: grid }, reverse: document.dir === 'rtl' },
          y: { title: { display: true, text: data.yLabel, color: text }, ticks: { color: text }, grid: { color: grid }, position: document.dir === 'rtl' ? 'right' : 'left' }
        }
      }
    };
    if (window.WB && typeof WB.chart === 'function') WB.chart(canvas, cfg); else new window.Chart(canvas, cfg);
  }

  function boot() { initDuel(); initChart(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
