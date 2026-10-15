/* WOAIBench.si — Local Fit Lab: memory, verdict and speed estimates for running open-weight models locally.
 * All formulas are documented on the page (/hardware → "How these estimates work"). */
(function () {
  'use strict';

  var dataEl = document.getElementById('hw-data');
  var form = document.getElementById('hw-form');
  if (!dataEl || !form) return;
  var D;
  try { D = JSON.parse(dataEl.textContent || '{}'); } catch (e) { return; }

  var GIB = 1073741824;
  var COMFORT = 0.85;           // "fits comfortably" below 85 % of usable memory
  var EFF_LO = 0.55, EFF_HI = 0.70; // achieved share of peak memory bandwidth while decoding
  var PREFILL_EFF = 0.35;       // achieved share of peak FP16 throughput while reading the prompt
  var TP_EFF = 0.85;            // tensor-parallel communication overhead
  var PROMPT = 4096;            // prompt length for the time-to-first-token estimate
  var KV_BYTES = { f16: 2, q8: 1.0625, q4: 0.5625 };
  var USABLE = { gpu: 1, apple: 0.75, cpu: 0.85 };
  var LAYERS = [[0.5, 24], [1.5, 28], [3, 28], [8, 32], [14, 48], [32, 64], [70, 80], [123, 88], [405, 126], [1000, 160]];
  var QUANTS = D.quants.map(function (q) { return { id: q[0], bits: q[1] }; });
  var QUALITY = { FP16: 'q_fp16', Q8_0: 'q_q8', Q6_K: 'q_q6', Q5_K_M: 'q_q5', Q4_K_M: 'q_q4', Q3_K_M: 'q_q3', Q2_K: 'q_q2' };

  var T = D.i18n || {};
  function t(k, rep) {
    var s = T['tools.' + k] || k;
    if (rep) Object.keys(rep).forEach(function (r) { s = s.split(':' + r).join('\u2068' + rep[r] + '\u2069'); });
    return s;
  }
  var models = {};
  (D.models || []).forEach(function (m) { models[m.slug] = m; });
  var devices = {};
  (D.gpus || []).forEach(function (g) { devices[g.name] = g; });
  var $ = function (id) { return document.getElementById(id); };

  /* ------------------------------------------------------------------ formatting */
  function iso(s) { return '\u2066' + s + '\u2069'; }  // left-to-right isolate: keeps "22.5 GB" intact in Arabic text
  function gb(x) { return iso((x >= 100 ? Math.round(x) : x.toFixed(1)) + ' GB'); }
  function tok(x) { return x >= 10 ? String(Math.round(x)) : x.toFixed(1); }
  function ctxLabel(n) {
    if (n >= 1048576) return (n / 1048576).toFixed(n % 1048576 ? 1 : 0).replace(/\.0$/, '') + 'M';
    return Math.round(n / 1024) + 'k';
  }
  function pct(x) { return Math.round(x * 100) + '%'; }
  function num(id) { var v = parseFloat($(id).value); return isFinite(v) && v > 0 ? v : null; }

  /* ------------------------------------------------------------------ context slider (log scale 2k … 1M) */
  function sliderToCtx(v) {
    var raw = 2048 * Math.pow(512, v / 1000);
    var step = raw >= 131072 ? 16384 : raw >= 16384 ? 2048 : 1024;
    return Math.max(2048, Math.min(1048576, Math.round(raw / step) * step));
  }
  function ctxToSlider(c) { return Math.round(1000 * Math.log(c / 2048) / Math.log(512)); }

  /* ------------------------------------------------------------------ architecture heuristics */
  function heurLayers(p) {
    if (p <= LAYERS[0][0]) return LAYERS[0][1];
    for (var i = 1; i < LAYERS.length; i++) {
      if (p <= LAYERS[i][0]) {
        var a = LAYERS[i - 1], b = LAYERS[i];
        var f = (Math.log(p) - Math.log(a[0])) / (Math.log(b[0]) - Math.log(a[0]));
        return Math.round(a[1] + f * (b[1] - a[1]));
      }
    }
    return LAYERS[LAYERS.length - 1][1];
  }
  function heurKv(p) { return p < 2 ? 256 : p < 5 ? 512 : 1024; }

  /* ------------------------------------------------------------------ state */
  function selectedModel() {
    var v = $('hw-model').value;
    var m = v === 'custom' ? null : models[v];
    var params = m ? m.params : (num('hw-params') || 8);
    var active = m ? (m.active || m.params) : (num('hw-active') || params);
    active = Math.min(active, params);
    var layers = num('hw-layers') || (m && m.layers) || heurLayers(params);
    var kv = num('hw-kvdim') || (m && m.kv) || heurKv(params);
    var known = !!(num('hw-layers') || (m && m.layers)) && !!(num('hw-kvdim') || (m && m.kv));
    return { m: m, name: m ? m.name : t('hwjs_custom'), params: params, active: active, layers: layers, kv: kv, known: known, ctx: m ? m.ctx : null };
  }
  function quantBits() {
    var r = form.querySelector('input[name="q"]:checked');
    var id = r ? r.value : 'Q4_K_M';
    for (var i = 0; i < QUANTS.length; i++) if (QUANTS[i].id === id) return QUANTS[i];
    return QUANTS[4];
  }
  function deviceRows() {
    return Array.prototype.map.call($('hw-devices').querySelectorAll('.hw-device'), function (row) {
      var name = row.querySelector('.hw-device-select').value;
      var count = Math.max(1, Math.min(8, parseInt(row.querySelector('.hw-device-count').value, 10) || 1));
      var dev = devices[name];
      if (name === '__custom') {
        dev = {
          name: t('hwjs_custom_device'), kind: 'gpu', tflops: null,
          vram: Math.max(1, parseFloat(row.querySelector('.hw-custom-mem').value) || 24),
          bw: Math.max(10, parseFloat(row.querySelector('.hw-custom-bw').value) || 900)
        };
      }
      return { row: row, name: name, count: dev && dev.kind === 'gpu' ? count : 1, dev: dev };
    }).filter(function (r) { return !!r.dev; });
  }

  /** Usable memory, effective bandwidth and compute of the chosen machine. */
  function machine() {
    var rows = deviceRows();
    var split = (form.querySelector('input[name="split"]:checked') || {}).value || 'layer';
    var single = rows.filter(function (r) { return r.dev.kind !== 'gpu'; })[0];
    var mach = { kind: 'gpu', cap: 0, bw: 0, tflops: null, n: 0, ram: null };
    if (single) {
      mach.kind = single.dev.kind;
      mach.cap = single.dev.vram * USABLE[single.dev.kind];
      mach.raw = single.dev.vram;
      mach.bw = single.dev.bw;
      mach.tflops = single.dev.tflops;
      mach.n = 1;
    } else {
      var vram = 0, invBw = 0, invTf = 0, tfKnown = true, minBw = Infinity, minTf = Infinity;
      rows.forEach(function (r) {
        for (var i = 0; i < r.count; i++) {
          vram += r.dev.vram;
          invBw += r.dev.vram / r.dev.bw;
          minBw = Math.min(minBw, r.dev.bw);
          if (r.dev.tflops) { invTf += r.dev.vram / r.dev.tflops; minTf = Math.min(minTf, r.dev.tflops); } else tfKnown = false;
          mach.n++;
        }
      });
      mach.cap = vram;
      mach.raw = vram;
      if (mach.n > 1 && split === 'tensor') {
        mach.bw = mach.n * minBw * TP_EFF;
        mach.tflops = tfKnown ? mach.n * minTf * TP_EFF : null;
      } else {
        mach.bw = vram > 0 ? vram / invBw : 0;          // layer split: GPUs work one after another
        mach.tflops = tfKnown && vram > 0 ? vram / invTf : null;
      }
    }
    if (mach.kind === 'gpu' && $('hw-offload').checked) {
      var ram = devices[$('hw-ram').value];
      if (ram) mach.ram = { cap: ram.vram * USABLE.cpu, bw: ram.bw, name: ram.name };
    }
    mach.rows = rows;
    mach.split = split;
    return mach;
  }

  /** Memory + verdict + speed for a model at one quantisation. */
  function evaluate(model, quant, ctx, kvType, mach) {
    var kvb = KV_BYTES[kvType] || 2;
    var w = model.params * 1e9 * quant.bits / 8 / GIB;
    var kvPerTok = 2 * model.layers * model.kv * kvb;           // bytes of KV cache per context token
    var kv = kvPerTok * ctx / GIB;
    var oh = 1 + 0.08 * (w + kv);
    var total = w + kv + oh;
    var res = { w: w, kv: kv, oh: oh, total: total, cap: mach.cap, s: 0, verdict: 'no' };
    if (mach.cap <= 0) return res;
    if (total <= COMFORT * mach.cap) res.verdict = 'fits';
    else if (total <= mach.cap) res.verdict = 'tight';
    else if (mach.ram && total <= mach.cap + mach.ram.cap) {
      res.verdict = 'offload';
      res.s = Math.min(1, (total - mach.cap) / w);
    }
    if (res.verdict !== 'no') {
      var wTok = model.active * 1e9 * quant.bits / 8;            // weight bytes read per generated token
      var speed = function (fill, eff) {
        var sec = ((1 - res.s) * wTok + kvPerTok * ctx * fill) / (mach.bw * 1e9);
        if (res.s > 0) sec += res.s * wTok / (mach.ram.bw * 1e9);
        return eff / sec;
      };
      res.tps = [speed(0, EFF_LO), speed(0, EFF_HI)];
      res.tpsFull = [speed(1, EFF_LO), speed(1, EFF_HI)];
      if (mach.tflops && res.s === 0) {
        res.prefill = PREFILL_EFF * mach.tflops * 1e12 / (2 * model.active * 1e9);
        res.ttft = PROMPT / res.prefill;
      }
    }
    var budget = (mach.cap - 1) / 1.08 - w;
    res.maxCtx = budget > 0 ? Math.floor(budget * GIB / kvPerTok) : 0;
    return res;
  }

  /* ------------------------------------------------------------------ rendering */
  var VERDICT_ICON = { fits: 'check', tight: 'alert', offload: 'layers', no: 'x' };
  var VERDICT_BADGE = { fits: 'badge-green', tight: 'badge-gold', offload: 'badge-violet', no: 'badge-red' };

  function speedText(r) { return r ? iso(tok(r[0]) + '–' + tok(r[1])) : '—'; }
  function speedWord(x) {
    return x >= 30 ? t('hwjs_fast') : x >= 10 ? t('hwjs_reading') : x >= 3 ? t('hwjs_slow') : t('hwjs_very_slow');
  }
  function badge(verdict) {
    var b = document.createElement('span');
    b.className = 'badge ' + VERDICT_BADGE[verdict];
    b.textContent = t('hwjs_v_' + verdict);
    return b;
  }
  function out(key, text) {
    Array.prototype.forEach.call(document.querySelectorAll('[data-out="' + key + '"]'), function (n) { n.textContent = text; });
  }
  function setIcon(name) {
    var use = document.querySelector('#hw-verdict .hw-verdict-icon use');
    if (use) use.setAttribute('href', D.icons + '#i-' + name);
  }

  function renderVerdict(model, quant, ctx, mach, r) {
    var box = $('hw-verdict');
    box.setAttribute('data-verdict', r.verdict);
    setIcon(VERDICT_ICON[r.verdict]);
    $('hw-verdict-title').textContent = t('hwjs_v_' + r.verdict + '_title');
    var rep = { need: gb(r.total), have: gb(r.cap), p: pct(r.total / Math.max(r.cap, 0.001)), m: model.name, q: quant.id, ctx: ctxLabel(ctx) };
    var text;
    if (mach.cap <= 0) text = t('hwjs_no_hw');
    else if (r.verdict === 'offload') {
      rep.gpu = gb(mach.cap);
      rep.ram = gb(r.total - mach.cap);
      rep.share = pct(r.s);
      text = t('hwjs_v_offload_text', rep);
    } else if (r.verdict === 'no') {
      text = t('hwjs_v_no_text', rep) + ' ' + t(mach.kind === 'gpu' && !mach.ram ? 'hwjs_v_no_hint_offload' : 'hwjs_v_no_hint', rep);
    } else text = t('hwjs_v_' + r.verdict + '_text', rep);
    if (mach.kind === 'apple') text += ' ' + t('hwjs_apple_note', { raw: gb(mach.raw) });
    $('hw-verdict-text').textContent = text;

    var scale = Math.max(r.total, r.cap + (mach.ram ? Math.min(mach.ram.cap, Math.max(0, r.total - r.cap) * 1.4) : 0), 0.001) * 1.06;
    var bar = $('hw-membar');
    bar.querySelector('.hw-seg-w').style.width = (r.w / scale * 100) + '%';
    bar.querySelector('.hw-seg-kv').style.width = (r.kv / scale * 100) + '%';
    bar.querySelector('.hw-seg-oh').style.width = (r.oh / scale * 100) + '%';
    var cap = bar.querySelector('.hw-cap');
    var capPos = Math.min(100, r.cap / scale * 100);
    cap.style.insetInlineStart = capPos + '%';
    cap.classList.toggle('is-end', capPos > 72);
    cap.classList.toggle('is-start', capPos < 18);
    cap.setAttribute('data-label', t('hwjs_cap_label', { have: gb(r.cap) }));
    bar.classList.toggle('is-over', r.total > r.cap);
    bar.setAttribute('aria-label', t('hwjs_bar_label', { need: gb(r.total), have: gb(r.cap) }));
    out('w', gb(r.w));
    out('kv', gb(r.kv));
    out('oh', gb(r.oh));
    out('cap', gb(r.cap));
  }

  function renderKpis(model, quant, ctx, r) {
    out('tps', r.tps ? speedText(r.tps) : '—');
    out('tps-hint', r.tps ? t('hwjs_tps_hint', { w: speedWord(r.tps[0]) }) : t('hwjs_na_no_fit'));
    out('tps-full', r.tpsFull ? speedText(r.tpsFull) : '—');
    out('tps-full-hint', r.tpsFull ? t('hwjs_tps_full_hint', { ctx: ctxLabel(ctx) }) : t('hwjs_na_no_fit'));
    if (r.ttft) {
      out('ttft', r.ttft < 1 ? t('hwjs_ms', { n: Math.round(r.ttft * 1000) }) : t('hwjs_s', { n: r.ttft < 10 ? r.ttft.toFixed(1) : Math.round(r.ttft) }));
      out('ttft-hint', t('hwjs_ttft_hint', { n: '4k', r: Math.round(r.prefill).toLocaleString('en-US') }));
    } else {
      out('ttft', '—');
      out('ttft-hint', r.verdict === 'no' ? t('hwjs_na_no_fit') : r.s > 0 ? t('hwjs_ttft_offload') : t('hwjs_ttft_unknown'));
    }
    var lim = model.ctx ? Math.min(model.ctx, 1048576) : 1048576;
    if (r.maxCtx >= 1024) {
      out('maxctx', ctxLabel(Math.min(r.maxCtx, lim)));
      out('maxctx-hint', r.maxCtx >= lim ? t('hwjs_maxctx_model', { q: quant.id }) : t('hwjs_maxctx_hint', { q: quant.id }));
    } else {
      out('maxctx', '—');
      out('maxctx-hint', t('hwjs_maxctx_none', { q: quant.id }));
    }
  }

  function cell(tr, content, cls) {
    var td = document.createElement('td');
    if (cls) td.className = cls;
    if (content instanceof Node) td.appendChild(content); else td.textContent = content;
    tr.appendChild(td);
    return td;
  }

  function renderQuantTable(model, current, ctx, kvType, mach) {
    var body = $('hw-qrows');
    body.textContent = '';
    QUANTS.forEach(function (q) {
      var r = evaluate(model, q, ctx, kvType, mach);
      var tr = document.createElement('tr');
      if (q.id === current.id) tr.className = 'is-highlight';
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'hw-qpick';
      btn.textContent = q.id;
      btn.setAttribute('aria-pressed', q.id === current.id ? 'true' : 'false');
      btn.addEventListener('click', function () {
        var radio = form.querySelector('input[name="q"][value="' + q.id + '"]');
        if (radio) { radio.checked = true; update(); }
      });
      var first = cell(tr, btn);
      var bits = document.createElement('span');
      bits.className = 't-xs t-muted hw-qbits';
      bits.textContent = q.bits.toFixed(1) + ' ' + t('hwjs_bits');
      first.appendChild(bits);
      cell(tr, gb(r.total), 'num t-num');
      cell(tr, badge(r.verdict));
      cell(tr, r.tps ? speedText(r.tps) + ' ' + t('hwjs_tps_unit') : '—', 'num t-num');
      cell(tr, t(QUALITY[q.id]), 't-small t-muted');
      body.appendChild(tr);
    });
  }

  function renderBest(ctx, kvType, mach) {
    var body = $('hw-brows');
    if (!body) return;
    body.textContent = '';
    var list = (D.models || []).filter(function (m) { return m.score !== null && m.score !== undefined; }).map(function (m) {
      var model = { name: m.name, params: m.params, active: Math.min(m.active || m.params, m.params), layers: m.layers || heurLayers(m.params), kv: m.kv || heurKv(m.params), ctx: m.ctx };
      var effCtx = m.ctx ? Math.min(ctx, m.ctx) : ctx;
      var best = null;
      for (var i = 0; i < QUANTS.length; i++) {
        var r = evaluate(model, QUANTS[i], effCtx, kvType, mach);
        if (r.verdict === 'fits' || r.verdict === 'tight') { best = { q: QUANTS[i], r: r }; break; }
      }
      if (!best && mach.ram) {
        for (var j = 0; j < QUANTS.length; j++) {
          var r2 = evaluate(model, QUANTS[j], effCtx, kvType, mach);
          if (r2.verdict === 'offload') { best = { q: QUANTS[j], r: r2 }; break; }
        }
      }
      return { m: m, best: best };
    });
    list.sort(function (a, b) {
      if (!!a.best !== !!b.best) return a.best ? -1 : 1;
      return (b.m.score || 0) - (a.m.score || 0);
    });
    var rank = 0;
    list.forEach(function (it) {
      var tr = document.createElement('tr');
      if (!it.best) tr.className = 'is-provisional hw-cant';
      cell(tr, it.best ? String(++rank) : '—', 'num t-num');
      var chip = document.createElement('a');
      chip.className = 'model-chip';
      chip.href = it.m.url;
      var av = document.createElement('span');
      av.className = 'avatar';
      av.style.setProperty('--av', it.m.color || '#888');
      av.style.setProperty('--size', '28px');
      av.setAttribute('aria-hidden', 'true');
      av.textContent = (it.m.provider || it.m.name || '?').replace(/[^\p{L}\p{N}]/gu, '').charAt(0).toUpperCase();
      var txt = document.createElement('span');
      txt.className = 'model-chip-text';
      var nm = document.createElement('span');
      nm.className = 'model-chip-name';
      nm.textContent = it.m.name;
      var pv = document.createElement('span');
      pv.className = 'model-chip-provider';
      pv.textContent = (it.m.provider || '') + ' · ' + String(it.m.params).replace(/\.0+$/, '') + 'B' + (it.m.active && it.m.active < it.m.params ? ' (' + String(it.m.active).replace(/\.0+$/, '') + 'B ' + t('hwjs_active') + ')' : '');
      txt.appendChild(nm);
      txt.appendChild(pv);
      chip.appendChild(av);
      chip.appendChild(txt);
      cell(tr, chip);
      var sc = document.createElement('span');
      sc.className = 'score score-sm';
      var sv = document.createElement('span');
      sv.className = 't-num';
      sv.textContent = Number(it.m.score).toFixed(1);
      sc.appendChild(sv);
      if (it.m.lo !== null && it.m.hi !== null && it.m.lo !== undefined) {
        var ci = document.createElement('span');
        ci.className = 'score-ci t-num';
        ci.textContent = '±' + ((it.m.hi - it.m.lo) / 2).toFixed(1);
        sc.appendChild(ci);
      }
      cell(tr, sc, 'num');
      cell(tr, it.best ? it.best.q.id + ' · ' + gb(it.best.r.total) : t('hwjs_none_fits'), 't-small');
      cell(tr, it.best && it.best.r.tps ? speedText(it.best.r.tps) + ' ' + t('hwjs_tps_unit') : '—', 'num t-num');
      cell(tr, badge(it.best ? it.best.r.verdict : 'no'));
      body.appendChild(tr);
    });
  }

  function renderArchNote(model) {
    $('hw-layers').placeholder = String(heurLayers(model.params));
    $('hw-kvdim').placeholder = String(heurKv(model.params));
    $('hw-arch-note').textContent = model.known ? t('hwjs_arch_known', { l: model.layers, k: model.kv }) : t('hwjs_arch_heur', { l: model.layers, k: model.kv });
  }

  function renderCtxNote(model, ctx) {
    $('hw-ctx-out').textContent = ctxLabel(ctx) + ' ' + t('hwjs_tokens');
    $('hw-ctx-note').textContent = model.ctx && ctx > model.ctx ? t('hwjs_ctx_over', { max: ctxLabel(model.ctx) }) : (model.ctx ? t('hwjs_ctx_max', { max: ctxLabel(model.ctx) }) : '');
  }

  /* ------------------------------------------------------------------ device rows */
  function addDevice(name, count) {
    var tpl = $('hw-device-tpl');
    var node = tpl.content.firstElementChild.cloneNode(true);
    var sel = node.querySelector('.hw-device-select');
    if (name && (devices[name] || name === '__custom')) sel.value = name;
    node.querySelector('.hw-device-count').value = String(count || 1);
    $('hw-devices').appendChild(node);
    syncDevices();
    return node;
  }
  function syncDevices() {
    var rows = Array.prototype.slice.call($('hw-devices').querySelectorAll('.hw-device'));
    var single = null;
    rows.forEach(function (row) {
      var name = row.querySelector('.hw-device-select').value;
      var dev = devices[name];
      var isGpu = name === '__custom' || (dev && dev.kind === 'gpu');
      row.querySelector('.hw-device-count').hidden = !isGpu;
      row.querySelector('.hw-device-custom').hidden = name !== '__custom';
      if (!isGpu && !single) single = row;
    });
    if (single) rows.forEach(function (row) { if (row !== single) row.remove(); });
    rows = Array.prototype.slice.call($('hw-devices').querySelectorAll('.hw-device'));
    rows.forEach(function (row) { row.querySelector('.hw-device-remove').hidden = rows.length < 2; });
    $('hw-add').hidden = !!single || rows.length >= 4;
    var total = 0;
    rows.forEach(function (row) { if (!row.querySelector('.hw-device-count').hidden) total += parseInt(row.querySelector('.hw-device-count').value, 10) || 1; });
    $('hw-split-field').hidden = !!single || total < 2;
    $('hw-offload-box').hidden = !!single;
    $('hw-ram-field').hidden = !$('hw-offload').checked;
  }

  /* ------------------------------------------------------------------ URL state */
  function writeUrl() {
    var q = new URLSearchParams();
    q.set('m', $('hw-model').value);
    if ($('hw-model').value === 'custom') {
      if (num('hw-params')) q.set('p', $('hw-params').value);
      if (num('hw-active')) q.set('a', $('hw-active').value);
    }
    if (num('hw-layers')) q.set('l', $('hw-layers').value);
    if (num('hw-kvdim')) q.set('kd', $('hw-kvdim').value);
    q.set('q', quantBits().id);
    q.set('kv', $('hw-kv').value);
    q.set('ctx', String(sliderToCtx(+$('hw-ctx').value)));
    q.set('hw', deviceRows().map(function (r) {
      return r.name === '__custom'
        ? 'custom:' + r.dev.vram + ':' + r.dev.bw + '*' + r.count
        : r.name + '*' + r.count;
    }).join('|'));
    var split = form.querySelector('input[name="split"]:checked');
    if (split && split.value !== 'layer') q.set('split', split.value);
    if ($('hw-offload').checked) { q.set('off', '1'); q.set('ram', $('hw-ram').value); }
    try { history.replaceState(null, '', location.pathname + '?' + q.toString()); } catch (e) { /* ignore */ }
  }

  function readUrl() {
    var q = new URLSearchParams(location.search);
    if (!q.has('m') && !q.has('hw')) return false;
    var m = q.get('m');
    if (m && (models[m] || m === 'custom')) $('hw-model').value = m;
    ['p:hw-params', 'a:hw-active', 'l:hw-layers', 'kd:hw-kvdim'].forEach(function (pair) {
      var k = pair.split(':');
      if (q.has(k[0]) && isFinite(parseFloat(q.get(k[0])))) $(k[1]).value = q.get(k[0]);
    });
    var radio = form.querySelector('input[name="q"][value="' + (q.get('q') || '').replace(/[^A-Za-z0-9_]/g, '') + '"]');
    if (radio) radio.checked = true;
    if (KV_BYTES[q.get('kv')]) $('hw-kv').value = q.get('kv');
    var c = parseInt(q.get('ctx'), 10);
    if (c >= 2048) $('hw-ctx').value = String(ctxToSlider(Math.min(c, 1048576)));
    var added = 0;
    (q.get('hw') || '').split('|').slice(0, 4).forEach(function (part) {
      var bits = part.split('*');
      var name = bits[0];
      var count = parseInt(bits[1], 10) || 1;
      if (name.indexOf('custom:') === 0) {
        var spec = name.split(':');
        var node = addDevice('__custom', count);
        node.querySelector('.hw-custom-mem').value = parseFloat(spec[1]) || 24;
        node.querySelector('.hw-custom-bw').value = parseFloat(spec[2]) || 900;
        added++;
      } else if (devices[name]) { addDevice(name, count); added++; }
    });
    if (q.get('split') === 'tensor') { var t2 = form.querySelector('input[name="split"][value="tensor"]'); if (t2) t2.checked = true; }
    if (q.get('off') === '1') {
      $('hw-offload').checked = true;
      if (devices[q.get('ram')]) $('hw-ram').value = q.get('ram');
    }
    return added > 0;
  }

  /* ------------------------------------------------------------------ main */
  var urlTimer = null;
  function update() {
    syncDevices();
    $('hw-custom').hidden = $('hw-model').value !== 'custom';
    var model = selectedModel();
    var quant = quantBits();
    var ctx = sliderToCtx(+$('hw-ctx').value);
    $('hw-ctx').style.setProperty('--pct', (+$('hw-ctx').value / 10) + '%');
    var kvType = $('hw-kv').value;
    var mach = machine();
    var r = evaluate(model, quant, ctx, kvType, mach);
    renderArchNote(model);
    renderCtxNote(model, ctx);
    renderVerdict(model, quant, ctx, mach, r);
    renderKpis(model, quant, ctx, r);
    renderQuantTable(model, quant, ctx, kvType, mach);
    renderBest(ctx, kvType, mach);
    clearTimeout(urlTimer);
    urlTimer = setTimeout(writeUrl, 250);
  }

  function defaults() {
    var best = null;
    (D.models || []).forEach(function (m) {
      if (m.params <= 40 && m.score !== null && m.score !== undefined && (!best || m.score > best.score)) best = m;
    });
    if (best) $('hw-model').value = best.slug;
    else if (!(D.models || []).length) $('hw-model').value = 'custom';
    var gpuDefault = devices['GeForce RTX 4090'] ? 'GeForce RTX 4090' : ((D.gpus || []).filter(function (g) { return g.kind === 'gpu'; })[0] || {}).name;
    addDevice(gpuDefault || '__custom', 1);
    var ramDefault = 'Desktop DDR5-5600, dual channel (64 GB)';
    if (devices[ramDefault]) $('hw-ram').value = ramDefault;
  }

  if (!readUrl()) defaults();
  if (!$('hw-devices').children.length) addDevice('__custom', 1);

  form.addEventListener('input', function (e) {
    if (e.target && e.target.id === 'hw-model') { $('hw-layers').value = ''; $('hw-kvdim').value = ''; }
    update();
  });
  form.addEventListener('change', update);
  form.addEventListener('submit', function (e) { e.preventDefault(); });
  $('hw-devices').addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('.hw-device-remove') : null;
    if (btn) { btn.closest('.hw-device').remove(); update(); }
  });
  $('hw-add').addEventListener('click', function () {
    var rows = deviceRows();
    addDevice(rows.length ? rows[rows.length - 1].name : '__custom', 1);
    update();
  });
  $('hw-reset').addEventListener('click', function () {
    $('hw-devices').textContent = '';
    form.reset();
    $('hw-ctx').value = '222';
    defaults();
    update();
  });
  $('hw-share').addEventListener('click', function () {
    writeUrl();
    var btn = this;
    var done = function () {
      if (window.WB && typeof WB.toast === 'function') WB.toast(btn.getAttribute('data-done'), 'success');
      else btn.querySelector('span').textContent = btn.getAttribute('data-done');
    };
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(location.href).then(done, done); else done();
  });
  update();
})();
