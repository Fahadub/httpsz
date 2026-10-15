/* Model page charts: track radar vs field median, score history with CI band. Module: public. */
(function () {
  'use strict';

  var dataEl = document.getElementById('model-data');
  if (!dataEl || typeof window.Chart === 'undefined') return;
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
  function make(canvas, cfg) {
    return (window.WB && typeof WB.chart === 'function') ? WB.chart(canvas, cfg) : new Chart(canvas, cfg);
  }
  function fmt(n) { return n === null || n === undefined ? '—' : Number(n).toFixed(1); }

  var charts = [];
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

  function radar() {
    var canvas = document.getElementById('model-radar');
    if (!canvas) return;
    var tracks = data.tracks || [];
    var vals = [];
    tracks.forEach(function (tr) {
      if (tr.score !== null) vals.push(tr.score);
      if (tr.median !== null) vals.push(tr.median);
    });
    var min = vals.length ? Math.max(0, Math.floor((Math.min.apply(null, vals) - 12) / 10) * 10) : 0;
    var gold = css('--gold', '#D9B26A');
    charts.push(make(canvas, {
      type: 'radar',
      data: {
        labels: tracks.map(function (tr) { return wrapLabel(tr.name); }),
        datasets: [
          {
            label: data.name,
            data: tracks.map(function (tr) { return tr.score; }),
            borderColor: gold,
            backgroundColor: css('--gold-soft', 'rgba(217,178,106,.18)'),
            pointBackgroundColor: tracks.map(function (tr) { return tr.color; }),
            pointBorderColor: gold,
            pointRadius: 3.5,
            borderWidth: 2,
            spanGaps: true
          },
          {
            label: t('median'),
            data: tracks.map(function (tr) { return tr.median; }),
            borderColor: css('--text-muted', '#999'),
            borderDash: [5, 4],
            backgroundColor: 'transparent',
            pointRadius: 0,
            borderWidth: 1.5,
            spanGaps: true
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          r: {
            min: min,
            max: 100,
            ticks: { stepSize: 10, display: true, backdropColor: 'transparent', color: css('--text-faint', '#777'), font: { size: 9 } },
            grid: { color: css('--border', 'rgba(255,255,255,.08)') },
            angleLines: { color: css('--border', 'rgba(255,255,255,.08)') },
            pointLabels: { color: css('--text-muted', '#aaa'), font: { size: labelSize(canvas) }, padding: 6 }
          }
        },
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              title: function (items) { return items.length ? tracks[items[0].dataIndex].name : ''; },
              label: function (ctx) {
                var tr = tracks[ctx.dataIndex];
                if (ctx.datasetIndex === 1) return t('median') + ': ' + fmt(tr.median);
                var ci = tr.ci_low !== null ? ' (' + fmt(tr.ci_low) + '–' + fmt(tr.ci_high) + ')' : '';
                return data.name + ': ' + fmt(tr.score) + ci;
              }
            }
          }
        }
      }
    }));
  }

  function history() {
    var canvas = document.getElementById('model-history');
    var hist = data.history || [];
    if (!canvas || hist.length < 2) return;
    var gold = css('--gold', '#D9B26A');
    charts.push(make(canvas, {
      type: 'line',
      data: {
        labels: hist.map(function (h) { return h.label; }),
        datasets: [
          { label: 'ci_low', data: hist.map(function (h) { return h.ci_low; }), borderWidth: 0, pointRadius: 0, fill: false, borderColor: 'transparent' },
          { label: 'ci_high', data: hist.map(function (h) { return h.ci_high; }), borderWidth: 0, pointRadius: 0, fill: '-1', borderColor: 'transparent', backgroundColor: css('--gold-soft', 'rgba(217,178,106,.18)') },
          { label: data.name, data: hist.map(function (h) { return h.score; }), borderColor: gold, backgroundColor: gold, borderWidth: 2, pointRadius: 2.5, pointHoverRadius: 5, tension: 0.25 }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        scales: {
          x: { grid: { display: false }, ticks: { maxTicksLimit: 6, color: css('--text-muted', '#aaa') } },
          y: { grid: { color: css('--border', 'rgba(255,255,255,.08)') }, ticks: { color: css('--text-muted', '#aaa') } }
        },
        plugins: {
          legend: { display: false },
          tooltip: {
            filter: function (item) { return item.datasetIndex === 2; },
            callbacks: {
              label: function (ctx) {
                var h = hist[ctx.dataIndex];
                var s = t('score') + ': ' + fmt(h.score);
                if (h.ci_low !== null) s += ' (' + fmt(h.ci_low) + '–' + fmt(h.ci_high) + ')';
                return s;
              },
              afterLabel: function (ctx) {
                var h = hist[ctx.dataIndex];
                return h.rank ? t('rank', { n: h.rank }) : '';
              }
            }
          }
        }
      }
    }));
  }

  function render() {
    charts.forEach(function (c) { c.destroy(); });
    charts = [];
    radar();
    history();
  }

  render();
  new MutationObserver(function (list) {
    if (list.some(function (m) { return m.attributeName === 'data-theme'; })) render();
  }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
})();
