/* Value page: price/latency vs score scatter (log x) with the Pareto frontier and point labels. Module: public. */
(function () {
  'use strict';

  var dataEl = document.getElementById('value-data');
  var canvas = document.getElementById('value-chart');
  if (!dataEl || !canvas || typeof window.Chart === 'undefined') return;
  var data;
  try { data = JSON.parse(dataEl.textContent); } catch (e) { return; }
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
  function money(n) {
    if (window.WB && WB.fmt && WB.fmt.money) return WB.fmt.money(n);
    if (n < 0.01) return '$' + Number(n).toPrecision(2);
    return '$' + Number(n).toFixed(2);
  }
  /* Log-axis ticks: keep only 1·2·5 × 10^k and print them with just enough precision. */
  function tick125(v) {
    if (!(v > 0)) return null;
    var p = Math.pow(10, Math.floor(Math.log10(v) + 1e-9));
    var m = Math.round(v / p * 100) / 100;
    return (m === 1 || m === 2 || m === 5) ? { v: +(m * p).toPrecision(2), m: m } : null;
  }
  function secs(ms) { return ms < 1000 ? Math.round(ms) + ' ms' : (ms / 1000).toFixed(1) + ' s'; }
  function fmt(n) { return n === null || n === undefined ? '—' : Number(n).toFixed(1); }

  var axis = 'cost';
  var showLabels = true;
  var chart = null;
  var frontIds = [];

  var labelPlugin = {
    id: 'pubLabels',
    afterDatasetsDraw: function (c) {
      if (!showLabels) return;
      var ctx = c.ctx;
      var meta = c.getDatasetMeta(0);
      var placed = [];
      ctx.save();
      ctx.font = '11px ' + (css('--font-body', 'system-ui') || 'system-ui');
      ctx.fillStyle = css('--text-muted', '#aaa');
      // A halo in the surface colour keeps labels readable over grid lines and the frontier.
      ctx.strokeStyle = css('--surface', '#111');
      ctx.lineWidth = 4;
      ctx.lineJoin = 'round';
      var pts = meta.data.map(function (el, i) { return { el: el, p: c.data.datasets[0].data[i] }; })
        .sort(function (a, b) { return b.p.y - a.p.y; });
      pts.forEach(function (o) {
        var name = o.p.name;
        var w = ctx.measureText(name).width;
        var right = o.el.x + 8 + w < c.chartArea.right;
        var x = right ? o.el.x + 8 : o.el.x - 8 - w;
        // The stepped frontier runs horizontally through its points: label those above the line, not on it.
        var front = frontIds.indexOf(o.p.p && o.p.p.id) !== -1;
        var y = front ? o.el.y - 9 : o.el.y;
        var box = front ? { x1: x - 2, x2: x + w + 2, y1: y - 13, y2: y + 1 } : { x1: x - 2, x2: x + w + 2, y1: y - 7, y2: y + 7 };
        var hit = placed.some(function (b) { return !(box.x2 < b.x1 || box.x1 > b.x2 || box.y2 < b.y1 || box.y1 > b.y2); });
        if (hit) return;
        placed.push(box);
        ctx.textAlign = 'left';
        ctx.textBaseline = front ? 'bottom' : 'middle';
        ctx.strokeText(name, x, y);
        ctx.fillText(name, x, y);
      });
      ctx.restore();
    }
  };

  function build() {
    if (chart) chart.destroy();
    var key = axis === 'cost' ? 'cost' : 'latency';
    var pts = data.points.filter(function (p) { return p[key] !== null && p[key] > 0 && p.score !== null; })
      .map(function (p) { return { x: p[key], y: p.score, name: p.name, p: p }; });
    var fIds = data.frontier[key] || [];
    frontIds = fIds;
    var xMax = pts.reduce(function (m, q) { return Math.max(m, q.x); }, 0);
    var front = pts.filter(function (q) { return fIds.indexOf(q.p.id) !== -1; }).sort(function (a, b) { return a.x - b.x; });
    var grid = css('--border', 'rgba(255,255,255,.08)');
    var muted = css('--text-muted', '#aaa');
    var gold = css('--gold', '#D9B26A');
    var cfg = {
      type: 'scatter',
      data: {
        datasets: [
          {
            label: 'models',
            data: pts,
            pointStyle: pts.map(function (q) { return q.p.open ? 'rectRot' : 'circle'; }),
            pointRadius: pts.map(function (q) { return fIds.indexOf(q.p.id) !== -1 ? 7 : 5.5; }),
            pointHoverRadius: 9,
            backgroundColor: pts.map(function (q) { return q.p.provisional ? 'transparent' : q.p.color; }),
            borderColor: pts.map(function (q) { return fIds.indexOf(q.p.id) !== -1 ? gold : q.p.color; }),
            borderWidth: pts.map(function (q) { return fIds.indexOf(q.p.id) !== -1 ? 2 : 1.5; })
          },
          {
            label: 'frontier',
            type: 'line',
            data: front.map(function (q) { return { x: q.x, y: q.y }; }),
            borderColor: gold,
            borderWidth: 1.5,
            borderDash: [6, 4],
            stepped: 'after',
            pointRadius: 0,
            fill: false
          }
        ]
      },
      plugins: [labelPlugin],
      options: {
        responsive: true,
        maintainAspectRatio: false,
        layout: { padding: { right: 12, top: 18 } },
        onClick: function (evt, els) {
          var hit = els.filter(function (e) { return e.datasetIndex === 0; })[0];
          if (hit) { var q = pts[hit.index]; if (q && q.p.slug) location.href = modelUrl(q.p.slug); }
        },
        onHover: function (evt, els) {
          if (evt.native) evt.native.target.style.cursor = els.some(function (e) { return e.datasetIndex === 0; }) ? 'pointer' : 'default';
        },
        scales: {
          x: {
            type: 'logarithmic',
            max: xMax > 0 ? xMax * 1.3 : undefined, // headroom: the last point and its label stay off the frame
            title: { display: true, text: axis === 'cost' ? t('x_cost') : t('x_latency'), color: muted },
            grid: { color: grid },
            ticks: {
              color: muted,
              autoSkip: false,
              maxRotation: 0,
              callback: function (v) {
                var n = tick125(v);
                var narrow = this.chart && this.chart.width < 640;
                if (n === null || (narrow && n.m !== 1)) return '';
                return axis === 'cost' ? '$' + n.v : secs(n.v);
              }
            }
          },
          y: { title: { display: true, text: t('y_score'), color: muted }, grid: { color: grid }, ticks: { color: muted } }
        },
        plugins: {
          legend: { display: false },
          tooltip: {
            filter: function (item) { return item.datasetIndex === 0; },
            callbacks: {
              title: function (items) { var q = pts[items[0].dataIndex]; return q ? q.p.name : ''; },
              label: function (ctx) {
                var q = pts[ctx.dataIndex];
                if (!q) return '';
                var lines = [q.p.vendor + (q.p.open ? ' · ' + t('open') : ''),
                  t('score') + ': ' + fmt(q.p.score) + (q.p.ci_low !== null ? ' (' + fmt(q.p.ci_low) + '–' + fmt(q.p.ci_high) + ')' : ''),
                  (axis === 'cost' ? t('cost') + ': ' + money(q.x) : t('latency') + ': ' + secs(q.x))];
                if (fIds.indexOf(q.p.id) !== -1) lines.push('★ ' + t('on_frontier'));
                if (q.p.provisional) lines.push(t('provisional'));
                return lines;
              }
            }
          }
        }
      }
    };
    chart = (window.WB && typeof WB.chart === 'function') ? WB.chart(canvas, cfg) : new Chart(canvas, cfg);
  }

  function modelUrl(slug) {
    var base = canvas.closest('[data-base]');
    return (base ? base.getAttribute('data-base') : '') + '/model/' + encodeURIComponent(slug);
  }

  document.querySelectorAll('[data-value-axis]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      axis = btn.getAttribute('data-value-axis');
      document.querySelectorAll('[data-value-axis]').forEach(function (b) {
        var on = b === btn;
        b.classList.toggle('active', on);
        b.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      build();
    });
  });
  var labels = document.querySelector('[data-value-labels]');
  if (labels) labels.addEventListener('change', function () { showLabels = labels.checked; if (chart) chart.update('none'); });

  build();
  new MutationObserver(function (list) {
    if (list.some(function (m) { return m.attributeName === 'data-theme'; })) build();
  }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
})();
