/* Compare page: searchable model picker (no reload), radar overlay, per-track bars with CI whiskers. Module: public. */
(function () {
  'use strict';

  var picker = document.querySelector('[data-cmp-picker]');
  var results = document.querySelector('[data-cmp-results]');
  var optsEl = document.getElementById('cmp-options');
  if (!picker || !results || !optsEl) return;

  var opts;
  try { opts = JSON.parse(optsEl.textContent); } catch (e) { return; }
  var strings = {};
  try { strings = JSON.parse((document.getElementById('pub-i18n') || {}).textContent || '{}'); } catch (e) { strings = {}; }
  function t(key, vars) {
    var s = strings['pub.js.' + key] || key;
    Object.keys(vars || {}).forEach(function (k) { s = s.split(':' + k).join(String(vars[k])); });
    return s;
  }
  function css(name, fallback) {
    var v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    return v || fallback;
  }
  var SERIES_FALLBACK = ['#3987E5', '#D95926', '#199E70', '#C98500'];
  /* Series colours: the validated categorical palette from app.js, shared with the PHP-rendered dots via --pub-sN. */
  function applySeries() {
    for (var i = 0; i < 4; i++) {
      var c = (window.WB && WB.palette) ? WB.palette(i) : SERIES_FALLBACK[i];
      document.documentElement.style.setProperty('--pub-s' + i, c);
    }
  }
  function color(i) { return css('--pub-s' + (i % 4), SERIES_FALLBACK[i % 4]); }
  var probe = document.createElement('canvas').getContext('2d');
  function alpha(c, a) {
    if (probe) { probe.fillStyle = '#000'; probe.fillStyle = c; c = String(probe.fillStyle); }
    var m = /^#([0-9a-f]{6})$/i.exec(c);
    if (!m) return c;
    var n = parseInt(m[1], 16);
    return 'rgba(' + (n >> 16) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + a + ')';
  }
  function fmt(n) { return n === null || n === undefined ? '—' : Number(n).toFixed(1); }

  var models = opts.models || [];
  var bySlug = {};
  models.forEach(function (m) { bySlug[m.slug] = m; });
  var selected = (opts.selected || []).slice();
  var MAX = opts.max || 4;

  /* ---------- picker ---------- */
  var chipsEl = picker.querySelector('[data-cmp-chips]');
  var combo = picker.querySelector('[data-cmp-combo]');
  var input = picker.querySelector('[data-cmp-input]');
  var listbox = picker.querySelector('[data-cmp-listbox]');
  var fallback = picker.querySelector('[data-cmp-fallback]');
  var shareBtn = picker.querySelector('[data-cmp-share]');
  if (fallback) fallback.hidden = true;
  if (combo) combo.hidden = false;
  if (shareBtn) shareBtn.hidden = false;
  var activeIdx = -1;
  var matches = [];

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
  }

  function renderChips() {
    chipsEl.textContent = '';
    selected.forEach(function (slug, i) {
      var m = bySlug[slug];
      if (!m) return;
      var chip = el('span', 'pub-pchip pub-c' + i);
      chip.appendChild(el('i', 'pub-dot'));
      chip.appendChild(el('span', '', m.name));
      var x = el('button', 'pub-pchip-x');
      x.type = 'button';
      x.setAttribute('aria-label', t('remove', { name: m.name }));
      x.textContent = '×';
      x.addEventListener('click', function () { remove(slug); });
      chip.appendChild(x);
      chipsEl.appendChild(chip);
    });
    if (input) {
      input.disabled = selected.length >= MAX;
      input.placeholder = selected.length >= MAX ? t('max', { n: MAX }) : t('add');
    }
  }

  function closeList() {
    if (!listbox) return;
    listbox.hidden = true;
    input.setAttribute('aria-expanded', 'false');
    input.removeAttribute('aria-activedescendant');
    activeIdx = -1;
  }

  function openList() {
    var q = input.value.trim().toLowerCase();
    matches = models.filter(function (m) {
      return selected.indexOf(m.slug) === -1 && (!q || (m.name + ' ' + m.vendor + ' ' + m.slug).toLowerCase().indexOf(q) !== -1);
    }).slice(0, 30);
    listbox.textContent = '';
    if (!matches.length) {
      var none = el('li', 'pub-option is-empty', t('none'));
      none.setAttribute('role', 'option');
      none.setAttribute('aria-disabled', 'true');
      listbox.appendChild(none);
    }
    matches.forEach(function (m, i) {
      var li = el('li', 'pub-option');
      li.id = 'cmp-opt-' + i;
      li.setAttribute('role', 'option');
      li.setAttribute('aria-selected', 'false');
      li.appendChild(el('span', 'pub-option-name', m.name));
      li.appendChild(el('span', 'pub-option-meta t-xs t-muted', m.vendor + (m.provisional ? ' · ' + t('provisional') : '')));
      li.appendChild(el('span', 'pub-option-score t-num', fmt(m.score)));
      li.addEventListener('mousedown', function (e) { e.preventDefault(); add(m.slug); });
      listbox.appendChild(li);
    });
    listbox.hidden = false;
    input.setAttribute('aria-expanded', 'true');
    activeIdx = -1;
  }

  function highlight(i) {
    var items = listbox.querySelectorAll('.pub-option:not(.is-empty)');
    if (!items.length) return;
    activeIdx = (i + items.length) % items.length;
    Array.prototype.forEach.call(items, function (li, j) {
      li.classList.toggle('is-active', j === activeIdx);
      li.setAttribute('aria-selected', j === activeIdx ? 'true' : 'false');
    });
    input.setAttribute('aria-activedescendant', items[activeIdx].id);
    items[activeIdx].scrollIntoView({ block: 'nearest' });
  }

  function add(slug) {
    if (selected.length >= MAX || selected.indexOf(slug) !== -1) return;
    selected.push(slug);
    input.value = '';
    closeList();
    update(true);
  }
  function remove(slug) {
    selected = selected.filter(function (s) { return s !== slug; });
    update(true);
  }

  if (input) {
    input.addEventListener('focus', openList);
    input.addEventListener('input', openList);
    input.addEventListener('blur', function () { setTimeout(closeList, 120); });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') { e.preventDefault(); if (listbox.hidden) openList(); highlight(activeIdx + 1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); highlight(activeIdx - 1); }
      else if (e.key === 'Enter') {
        e.preventDefault();
        var m = matches[activeIdx >= 0 ? activeIdx : 0];
        if (m) add(m.slug);
      } else if (e.key === 'Escape') { closeList(); }
      else if (e.key === 'Backspace' && !input.value && selected.length) { remove(selected[selected.length - 1]); }
    });
  }
  picker.addEventListener('click', function (e) {
    var b = e.target.closest('[data-cmp-remove]');
    if (b) remove(b.getAttribute('data-cmp-remove'));
  });

  function pageUrl() {
    var base = picker.getAttribute('data-base') || '/compare';
    return base + (selected.length ? '?m=' + selected.map(encodeURIComponent).join(',') : '?m=');
  }

  var reqId = 0;
  function update(push) {
    renderChips();
    var url = pageUrl();
    if (push && window.history && history.pushState) history.pushState({ m: selected.slice() }, '', url);
    var my = ++reqId;
    results.setAttribute('aria-busy', 'true');
    results.classList.add('is-loading');
    fetch(url + '&fragment=1', { headers: { 'Accept': 'text/html' }, credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
      .then(function (html) {
        if (my !== reqId) return;
        results.innerHTML = html; // same-origin, server-rendered and fully escaped
        renderCharts();
        updateTitle();
      })
      .catch(function () { if (window.WB && WB.toast) WB.toast(t('error'), 'error'); })
      .then(function () { if (my === reqId) { results.removeAttribute('aria-busy'); results.classList.remove('is-loading'); } });
  }

  function updateTitle() {
    var names = selected.map(function (s) { return bySlug[s] ? bySlug[s].name : s; });
    var parts = document.title.split(' · ');
    var site = parts.length > 1 ? parts[parts.length - 1] : '';
    if (names.length > 1) document.title = names.join(' ' + t('vs') + ' ') + (site ? ' · ' + site : '');
  }

  window.addEventListener('popstate', function (e) {
    if (e.state && Array.isArray(e.state.m)) { selected = e.state.m.slice(0, MAX); update(false); }
  });
  if (window.history && history.replaceState) history.replaceState({ m: selected.slice() }, '', location.href);

  if (shareBtn) shareBtn.addEventListener('click', function () {
    var abs = location.origin + pageUrl();
    var done = function () { if (window.WB && WB.toast) WB.toast(t('copied'), 'success'); };
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(abs).then(done, function () { window.prompt(t('copy_link'), abs); });
    else window.prompt(t('copy_link'), abs);
  });

  /* ---------- charts ---------- */
  /* Radar point labels: wrap long track names onto two lines so they never clip on phones. */
  function wrapLabel(s) {
    s = String(s);
    if (s.length <= 13 || s.indexOf(' ') === -1) return s;
    var mid = Math.floor(s.length / 2);
    var best = -1;
    for (var i = 0; i < s.length; i++) if (s[i] === ' ' && (best < 0 || Math.abs(i - mid) < Math.abs(best - mid))) best = i;
    return [s.slice(0, best), s.slice(best + 1)];
  }
  function labelSize(canvas) { return canvas.parentNode && canvas.parentNode.clientWidth < 460 ? 10 : 11.5; }
  var charts = [];
  function make(canvas, cfg) {
    return (window.WB && typeof WB.chart === 'function') ? WB.chart(canvas, cfg) : new Chart(canvas, cfg);
  }

  var whiskers = {
    id: 'pubWhiskers',
    afterDatasetsDraw: function (chart) {
      var ci = chart.config.options.plugins.pubWhiskers;
      if (!ci) return;
      var ctx = chart.ctx;
      var xs = chart.scales.x;
      ctx.save();
      ctx.lineWidth = 1.25;
      ctx.strokeStyle = css('--text', '#eee');
      ctx.globalAlpha = 0.75;
      chart.data.datasets.forEach(function (ds, di) {
        var meta = chart.getDatasetMeta(di);
        if (meta.hidden) return;
        meta.data.forEach(function (bar, i) {
          var c = ci[di] && ci[di][i];
          if (!c || c[0] === null || c[1] === null) return;
          var x1 = xs.getPixelForValue(c[0]);
          var x2 = xs.getPixelForValue(c[1]);
          var y = bar.y;
          var h = Math.min(6, (bar.height || 8) / 2);
          ctx.beginPath();
          ctx.moveTo(x1, y); ctx.lineTo(x2, y);
          ctx.moveTo(x1, y - h); ctx.lineTo(x1, y + h);
          ctx.moveTo(x2, y - h); ctx.lineTo(x2, y + h);
          ctx.stroke();
        });
      });
      ctx.restore();
    }
  };

  function renderCharts() {
    charts.forEach(function (c) { c.destroy(); });
    charts = [];
    var dataEl = document.getElementById('cmp-data');
    if (!dataEl || typeof window.Chart === 'undefined') return;
    var d;
    try { d = JSON.parse(dataEl.textContent); } catch (e) { return; }
    var labels = d.tracks.map(function (tr) { return tr.name; });
    var grid = css('--border', 'rgba(255,255,255,.08)');
    var muted = css('--text-muted', '#aaa');

    var radar = document.getElementById('cmp-radar');
    if (radar) {
      var all = [];
      d.models.forEach(function (m) { m.tracks.forEach(function (x) { if (x.score !== null) all.push(x.score); }); });
      var min = all.length ? Math.max(0, Math.floor((Math.min.apply(null, all) - 12) / 10) * 10) : 0;
      charts.push(make(radar, {
        type: 'radar',
        data: {
          labels: labels.map(wrapLabel),
          datasets: d.models.map(function (m, i) {
            return {
              label: m.name, data: m.tracks.map(function (x) { return x.score; }), borderColor: color(i),
              backgroundColor: alpha(color(i), 0.1), pointBackgroundColor: color(i), pointRadius: 2.5, borderWidth: 2, spanGaps: true
            };
          })
        },
        options: {
          responsive: true, maintainAspectRatio: false,
          scales: { r: { min: min, max: 100, ticks: { stepSize: 20, display: radar.clientWidth > 480, backdropColor: 'transparent', color: css('--text-faint', '#777'), font: { size: 9 }, z: 1 }, grid: { color: grid }, angleLines: { color: grid }, pointLabels: { color: muted, font: { size: labelSize(radar) }, padding: 6 } } },
          plugins: { legend: { display: false }, tooltip: { callbacks: { title: function (items) { return items.length ? labels[items[0].dataIndex] : ''; } } } }
        }
      }));
    }

    var bars = document.getElementById('cmp-bars');
    if (bars) {
      var ci = d.models.map(function (m) { return m.tracks.map(function (x) { return [x.ci_low, x.ci_high]; }); });
      var vals = [];
      d.models.forEach(function (m) { m.tracks.forEach(function (x) { if (x.ci_low !== null) vals.push(x.ci_low); else if (x.score !== null) vals.push(x.score); }); });
      var xmin = vals.length ? Math.max(0, Math.floor((Math.min.apply(null, vals) - 5) / 10) * 10) : 0;
      charts.push(make(bars, {
        type: 'bar',
        data: {
          labels: labels,
          datasets: d.models.map(function (m, i) {
            return { label: m.name, data: m.tracks.map(function (x) { return x.score; }), backgroundColor: alpha(color(i), 0.85), borderRadius: 3, barPercentage: 0.86, categoryPercentage: 0.78 };
          })
        },
        plugins: [whiskers],
        options: {
          indexAxis: 'y', responsive: true, maintainAspectRatio: false,
          scales: {
            x: { min: xmin, max: 100, reverse: !!d.rtl, grid: { color: grid }, ticks: { color: muted } },
            y: { position: d.rtl ? 'right' : 'left', grid: { display: false }, ticks: { color: muted, font: { size: 11 } } }
          },
          plugins: {
            legend: { display: false },
            pubWhiskers: ci,
            tooltip: {
              callbacks: {
                label: function (ctx) {
                  var c = ci[ctx.datasetIndex][ctx.dataIndex];
                  return ctx.dataset.label + ': ' + fmt(ctx.raw) + (c[0] !== null ? ' (' + fmt(c[0]) + '–' + fmt(c[1]) + ')' : '');
                }
              }
            }
          }
        }
      }));
    }
  }

  applySeries();
  renderChips();
  renderCharts();
  new MutationObserver(function (list) {
    if (list.some(function (m) { return m.attributeName === 'data-theme'; })) { applySeries(); renderCharts(); }
  }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
})();
