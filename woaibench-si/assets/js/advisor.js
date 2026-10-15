/* WOAIBench.si — Advisor: personal ranking from custom track weights and constraints (all client-side). */
(function () {
  'use strict';

  var dataEl = document.getElementById('adv-data');
  var form = document.getElementById('adv-form');
  if (!dataEl || !form) return;
  var D;
  try { D = JSON.parse(dataEl.textContent || '{}'); } catch (e) { return; }
  var T = D.i18n || {};
  function t(k, rep) {
    var s = T['tools.' + k] || k;
    if (rep) Object.keys(rep).forEach(function (r) { s = s.split(':' + r).join('\u2068' + rep[r] + '\u2069'); });
    return s;
  }
  var $ = function (id) { return document.getElementById(id); };
  var tracks = D.tracks || [];
  var trackName = Object.create(null);
  tracks.forEach(function (tr) { trackName[tr.slug] = tr.name; });
  var sliders = Array.prototype.slice.call(form.querySelectorAll('input[data-track]'));
  var presetBtns = Array.prototype.slice.call(document.querySelectorAll('[data-preset]'));
  var listSep = document.documentElement.lang === 'ar' ? '، ' : ', ';

  /* ------------------------------------------------------------------ formatting */
  function iso(s) { return '\u2066' + s + '\u2069'; }  // left-to-right isolate for Arabic layouts
  function money(v) {
    if (v === null || v === undefined) return '—';
    if (v === 0) return iso('$0');
    if (v < 0.01) return iso('$' + v.toFixed(4).replace(/0+$/, '').replace(/\.$/, ''));
    return iso('$' + v.toFixed(2));
  }
  function ms(v) {
    if (v === null || v === undefined) return '—';
    if (v < 1000) return iso(Math.round(v) + ' ms');
    if (v < 60000) return iso((v / 1000).toFixed(1) + ' s');
    return iso((v / 60000).toFixed(1) + ' min');
  }
  function f1(v) { return v === null || v === undefined ? '—' : Number(v).toFixed(1); }
  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined && text !== null) n.textContent = text;
    return n;
  }

  /* ------------------------------------------------------------------ state */
  function state() {
    var w = {};
    sliders.forEach(function (s) { w[s.getAttribute('data-track')] = parseFloat(s.value) || 0; });
    return {
      w: w,
      cost: parseFloat($('adv-cost').value) || null,
      lat: parseFloat($('adv-lat').value) || null,
      ctx: parseInt($('adv-ctx').value, 10) || null,
      open: $('adv-open').checked,
      vision: $('adv-vision').checked,
      prov: $('adv-prov').checked
    };
  }

  function score(row, st) {
    var W = 0, num = 0, wsum = 0, v = 0;
    var fallbackHalf = row.lo !== null && row.hi !== null ? (row.hi - row.lo) / 2 : 5;
    Object.keys(st.w).forEach(function (slug) {
      var w = st.w[slug];
      if (w <= 0) return;
      W += w;
      var tr = row.tracks[slug];
      if (!tr) return;
      num += w * tr[0];
      wsum += w;
      var h = tr[1] !== null && tr[2] !== null ? (tr[2] - tr[1]) / 2 : fallbackHalf;
      v += w * w * h * h;
    });
    if (W === 0) return { s: row.score, half: fallbackHalf, cov: 1, zero: true };
    if (wsum === 0) return { s: null, half: null, cov: 0 };
    return { s: num / wsum, half: Math.sqrt(v) / wsum, cov: wsum / W };
  }

  function exclusion(row, st) {
    if (!st.prov && row.prov) return 'ex_prov';
    if (st.open && !row.open) return 'ex_open';
    if (st.vision && !row.vision) return 'ex_vision';
    if (st.ctx && (row.ctx || 0) < st.ctx) return 'ex_ctx';
    if (st.cost && (row.cost === null || row.cost > st.cost)) return 'ex_cost';
    if (st.lat && (row.lat === null || row.lat > st.lat * 1000)) return 'ex_lat';
    return null;
  }

  function rank(st) {
    var ranked = [], excluded = {};
    (D.rows || []).forEach(function (row) {
      var why = exclusion(row, st);
      if (why) { excluded[why] = (excluded[why] || 0) + 1; return; }
      var r = score(row, st);
      if (r.s === null) { excluded.ex_nodata = (excluded.ex_nodata || 0) + 1; return; }
      ranked.push({ row: row, s: r.s, half: r.half, cov: r.cov, zero: !!r.zero });
    });
    ranked.sort(function (a, b) {
      var ca = a.cov > 0.999, cb = b.cov > 0.999;
      if (ca !== cb) return ca ? -1 : 1;
      return b.s - a.s;
    });
    return { ranked: ranked, excluded: excluded };
  }

  /* ------------------------------------------------------------------ rendering */
  function avatar(row) {
    var av = el('span', 'avatar', (row.provider || row.name || '?').replace(/[^\p{L}\p{N}]/gu, '').charAt(0).toUpperCase());
    av.style.setProperty('--av', row.color || '#888');
    av.style.setProperty('--size', '28px');
    av.setAttribute('aria-hidden', 'true');
    return av;
  }
  function chip(row) {
    var a = el('a', 'model-chip');
    a.href = row.url;
    a.appendChild(avatar(row));
    var txt = el('span', 'model-chip-text');
    txt.appendChild(el('span', 'model-chip-name', row.name));
    txt.appendChild(el('span', 'model-chip-provider', row.provider));
    a.appendChild(txt);
    return a;
  }

  function renderTop(res, st) {
    var box = $('adv-top');
    box.textContent = '';
    var top = res.ranked[0];
    if (!top) {
      box.appendChild(el('p', 't-muted adv-none', t('advjs_none')));
      return;
    }
    var head = el('div', 'adv-top-head');
    var left = el('div', 'adv-top-id');
    left.appendChild(el('span', 'eyebrow', t('advjs_top')));
    var name = el('a', 'adv-top-name', top.row.name);
    name.href = top.row.url;
    left.appendChild(name);
    left.appendChild(el('span', 't-small t-muted', top.row.provider));
    head.appendChild(left);
    var sc = el('div', 'adv-top-score');
    var big = el('span', 't-num adv-top-value', f1(top.s));
    sc.appendChild(big);
    sc.appendChild(el('span', 'score-ci t-num', '±' + f1(top.half)));
    sc.appendChild(el('span', 't-xs t-muted adv-top-label', t(top.zero ? 'advjs_overall_label' : 'advjs_score_label')));
    head.appendChild(sc);
    box.appendChild(head);

    var ul = el('ul', 'adv-reasons');
    function li(icon, text) {
      var item = el('li');
      var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      svg.setAttribute('class', 'icon');
      svg.setAttribute('width', '16');
      svg.setAttribute('height', '16');
      svg.setAttribute('aria-hidden', 'true');
      var use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
      use.setAttribute('href', D.icons + '#i-' + icon);
      svg.appendChild(use);
      item.appendChild(svg);
      item.appendChild(el('span', '', text));
      ul.appendChild(item);
    }
    if (top.zero) li('info', t('advjs_all_zero'));
    var maxW = 0;
    Object.keys(st.w).forEach(function (k) { maxW = Math.max(maxW, st.w[k]); });
    var focus = Object.keys(st.w).filter(function (k) { return st.w[k] > 0 && st.w[k] >= maxW * 0.6 && top.row.tracks[k]; })
      .sort(function (a, b) { return (st.w[b] - st.w[a]) || (top.row.tracks[b][0] - top.row.tracks[a][0]); }).slice(0, 3);
    if (focus.length && !top.zero) {
      li('target', t('advjs_strengths', { list: focus.map(function (k) { return (trackName[k] || k) + ' ' + f1(top.row.tracks[k][0]); }).join(listSep) }));
    }
    li('coin', t('advjs_cost_line', { c: money(top.row.cost), l: ms(top.row.lat) }));
    if (top.row.rank) li('trophy', t('advjs_overall', { r: top.row.rank }));
    var ties = res.ranked.slice(1).filter(function (o) { return o.cov > 0.999 && o.s + o.half >= top.s - top.half; });
    li('scale', ties.length ? t('advjs_ties', { list: ties.slice(0, 4).map(function (o) { return o.row.name; }).join(listSep) + (ties.length > 4 ? '…' : '') }) : t('advjs_no_ties'));
    var value = res.ranked.slice(1).filter(function (o) {
      return o.cov > 0.999 && o.row.cost !== null && top.row.cost !== null && o.row.cost <= top.row.cost * 0.5 && top.s - o.s <= 5;
    }).sort(function (a, b) { return a.row.cost - b.row.cost; })[0];
    if (value) li('sparkles', t('advjs_value', { m: value.row.name, s: f1(value.s), c: money(value.row.cost), tc: money(top.row.cost) }));
    if (top.cov < 0.999) li('alert', t('advjs_partial_top'));
    box.appendChild(ul);
  }

  function renderTable(res) {
    var body = $('adv-rows');
    body.textContent = '';
    var max = 100;
    res.ranked.forEach(function (it, i) {
      var tr = el('tr', it.cov < 0.999 ? 'is-provisional' : (i === 0 ? 'is-highlight' : ''));
      var c0 = el('td', 'num');
      var rk = el('span', 'rank' + (i < 3 ? ' rank-' + (i + 1) : ''), String(i + 1));
      c0.appendChild(rk);
      tr.appendChild(c0);
      var c1 = el('td');
      c1.appendChild(chip(it.row));
      if (it.cov < 0.999) {
        var b = el('span', 'badge badge-muted adv-partial', t('advjs_partial'));
        b.setAttribute('data-tip', t('advjs_partial_tip', { p: Math.round(it.cov * 100) + '%' }));
        c1.appendChild(b);
      }
      if (it.row.prov) c1.appendChild(el('span', 'badge badge-muted adv-partial', t('advjs_prov')));
      tr.appendChild(c1);
      var c2 = el('td', 'num');
      var sc = el('span', 'score score-sm');
      sc.appendChild(el('span', 't-num', f1(it.s)));
      sc.appendChild(el('span', 'score-ci t-num', '±' + f1(it.half)));
      c2.appendChild(sc);
      tr.appendChild(c2);
      var c3 = el('td', 'adv-col-bar');
      var bar = el('span', 'scorebar');
      var fill = el('span', 'scorebar-fill');
      fill.style.width = Math.max(0, Math.min(100, it.s / max * 100)) + '%';
      fill.style.setProperty('--bar', it.row.color || 'var(--gold)');
      bar.appendChild(fill);
      var lo = Math.max(0, it.s - it.half), hi = Math.min(100, it.s + it.half);
      var ci = el('span', 'scorebar-ci');
      ci.style.insetInlineStart = lo + '%';
      ci.style.width = Math.max(0.5, hi - lo) + '%';
      bar.appendChild(ci);
      c3.appendChild(bar);
      tr.appendChild(c3);
      var c4 = el('td', 'num t-num');
      c4.appendChild(document.createTextNode(f1(it.row.score)));
      if (it.row.rank) {
        var delta = it.row.rank - (i + 1);
        var d = el('span', 'delta ' + (delta > 0 ? 'delta-up' : delta < 0 ? 'delta-down' : 'delta-flat'), delta === 0 ? '=' : (delta > 0 ? '▲' : '▼') + Math.abs(delta));
        d.setAttribute('data-tip', t('advjs_overall', { r: it.row.rank }));
        c4.appendChild(d);
      }
      tr.appendChild(c4);
      tr.appendChild(el('td', 'num t-num', money(it.row.cost)));
      tr.appendChild(el('td', 'num t-num', ms(it.row.lat)));
      body.appendChild(tr);
    });
    $('adv-count').textContent = t('advjs_count', { n: res.ranked.length });
    var keys = Object.keys(res.excluded);
    var ex = $('adv-excluded');
    if (keys.length) {
      var n = keys.reduce(function (s, k) { return s + res.excluded[k]; }, 0);
      ex.textContent = t('advjs_excluded', { n: n, list: keys.map(function (k) { return t('advjs_' + k) + ' (' + res.excluded[k] + ')'; }).join(listSep) });
      ex.hidden = false;
    } else ex.hidden = true;
  }

  /* ------------------------------------------------------------------ presets + URL */
  function hasPreset(key) { return !!key && Object.prototype.hasOwnProperty.call(D.presets || {}, key); }
  function applyPreset(key) {
    if (!hasPreset(key)) return;
    var p = D.presets[key];
    sliders.forEach(function (s) {
      var slug = s.getAttribute('data-track');
      s.value = String(p.w && p.w[slug] !== undefined ? p.w[slug] : p.rest);
    });
    $('adv-cost').value = p.cost ? String(p.cost) : '';
    markPreset(key);
  }
  function markPreset(key) {
    presetBtns.forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-preset') === key ? 'true' : 'false'); });
  }
  function matchingPreset() {
    var keys = Object.keys(D.presets || {});
    for (var i = 0; i < keys.length; i++) {
      var p = D.presets[keys[i]];
      var ok = sliders.every(function (s) {
        var slug = s.getAttribute('data-track');
        var want = p.w && p.w[slug] !== undefined ? p.w[slug] : p.rest;
        return parseFloat(s.value) === want;
      }) && (parseFloat($('adv-cost').value) || null) === (p.cost || null);
      if (ok) return keys[i];
    }
    return null;
  }
  function writeUrl(st) {
    var q = new URLSearchParams();
    var preset = matchingPreset();
    if (preset && preset !== 'balanced') q.set('p', preset);
    else if (!preset) {
      var w = Object.keys(st.w).filter(function (k) { return st.w[k] !== 1; }).map(function (k) { return k + ':' + st.w[k]; });
      if (w.length) q.set('w', w.join(','));
    }
    if (st.cost && !(preset && D.presets[preset].cost === st.cost)) q.set('cost', String(st.cost));
    if (st.lat) q.set('lat', String(st.lat));
    if (st.ctx) q.set('ctx', String(st.ctx));
    if (st.open) q.set('open', '1');
    if (st.vision) q.set('vision', '1');
    if (st.prov) q.set('prov', '1');
    var qs = q.toString();
    try { history.replaceState(null, '', location.pathname + (qs ? '?' + qs : '')); } catch (e) { /* ignore */ }
  }
  function setSelect(id, v) {
    var sel = $(id);
    if (Array.prototype.some.call(sel.options, function (o) { return o.value === v; })) sel.value = v;
  }
  function readUrl() {
    var q = new URLSearchParams(location.search);
    if (hasPreset(q.get('p'))) applyPreset(q.get('p'));
    (q.get('w') || '').split(',').forEach(function (pair) {
      var kv = pair.split(':');
      var s = form.querySelector('input[data-track="' + (kv[0] || '').replace(/[^a-z0-9_-]/gi, '') + '"]');
      var v = parseFloat(kv[1]);
      if (s && isFinite(v)) s.value = String(Math.max(0, Math.min(5, Math.round(v * 2) / 2)));
    });
    if (q.get('cost')) setSelect('adv-cost', q.get('cost'));
    if (q.get('lat')) setSelect('adv-lat', q.get('lat'));
    if (q.get('ctx')) setSelect('adv-ctx', q.get('ctx'));
    $('adv-open').checked = q.get('open') === '1';
    $('adv-vision').checked = q.get('vision') === '1';
    $('adv-prov').checked = q.get('prov') === '1';
  }

  var urlTimer = null;
  function update() {
    sliders.forEach(function (s) {
      var o = s.parentNode.querySelector('output');
      if (o) o.textContent = String(parseFloat(s.value));
      s.parentNode.classList.toggle('is-off', parseFloat(s.value) === 0);
      s.style.setProperty('--pct', (parseFloat(s.value) / 5 * 100) + '%');
    });
    var st = state();
    markPreset(matchingPreset());
    var res = rank(st);
    renderTop(res, st);
    renderTable(res);
    clearTimeout(urlTimer);
    urlTimer = setTimeout(function () { writeUrl(st); }, 200);
  }

  presetBtns.forEach(function (b) {
    b.addEventListener('click', function () { applyPreset(b.getAttribute('data-preset')); update(); });
  });
  form.addEventListener('input', update);
  form.addEventListener('change', update);
  form.addEventListener('submit', function (e) { e.preventDefault(); });
  $('adv-reset').addEventListener('click', function () {
    form.reset();
    applyPreset('balanced');
    update();
  });
  $('adv-share').addEventListener('click', function () {
    writeUrl(state());
    var btn = this;
    var done = function () {
      if (window.WB && typeof WB.toast === 'function') WB.toast(btn.getAttribute('data-done'), 'success');
      else btn.querySelector('span').textContent = btn.getAttribute('data-done');
    };
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(location.href).then(done, done); else done();
  });

  readUrl();
  update();
})();
