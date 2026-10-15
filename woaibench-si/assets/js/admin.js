/*!
 * WOAIBench.si — back-office behaviours (admin layout only).
 * Inline model toggles, JSON editors with live validation + task preview, OpenRouter catalogue picker,
 * campaign pickers with live cost estimate, Brand Studio presets, key test, busy forms.
 * Depends on assets/js/app.js (window.WB); degrades to plain forms without it.
 */
(() => {
  'use strict';

  const d = document;
  const $ = (sel, ctx = d) => ctx.querySelector(sel);
  const $$ = (sel, ctx = d) => Array.from(ctx.querySelectorAll(sel));
  const WB = window.WB || {};
  const t = (key, repl) => (WB.t ? WB.t(key, repl) : key);
  const toast = (msg, type) => (WB.toast ? WB.toast(msg, type) : null);
  const debounce = (fn, ms) => {
    let h = 0;
    return (...a) => { clearTimeout(h); h = setTimeout(() => fn(...a), ms); };
  };
  const csrf = () => (($('meta[name="csrf-token"]') || {}).content || '');

  /** POST JSON; resolves with the parsed body (also for 4xx/5xx), never rejects on HTTP status. */
  async function post(url, body) {
    try {
      const res = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrf() },
        body: JSON.stringify(body || {}),
      });
      let data = null;
      try { data = await res.json(); } catch (e) { data = null; }
      if (!data || typeof data !== 'object') data = { ok: false, error: 'HTTP ' + res.status };
      if (!res.ok && data.ok === undefined) data.ok = false;
      data._status = res.status;
      return data;
    } catch (e) {
      return { ok: false, error: t('js.error'), _status: 0 };
    }
  }
  const el = (tag, cls, text) => {
    const n = d.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined && text !== null) n.textContent = String(text);
    return n;
  };
  const money = (n) => (WB.fmt && WB.fmt.money ? WB.fmt.money(n) : '$' + Number(n || 0).toFixed(2));

  /* ------------------------------------------------------------------ model list: inline toggles */
  d.addEventListener('change', async (ev) => {
    const box = ev.target.closest && ev.target.closest('[data-model-toggle]');
    if (!box) return;
    box.disabled = true;
    const res = await post(box.getAttribute('data-url'), { field: box.getAttribute('data-model-toggle'), value: box.checked ? 1 : 0 });
    box.disabled = false;
    if (res.ok) {
      toast(t('js.saved'), 'success');
      const row = box.closest('tr');
      if (row && box.getAttribute('data-model-toggle') === 'is_active') row.classList.toggle('is-muted', !box.checked);
    } else {
      box.checked = !box.checked;
      toast(res.error || t('js.error'), 'error');
    }
  });
  const saveOrder = debounce(async (input) => {
    const res = await post(input.getAttribute('data-url'), { field: 'judge_order', value: Number(input.value || 0) });
    if (res.ok) {
      input.value = res.value;
      toast(t('js.saved'), 'success');
    } else {
      toast(res.error || t('js.error'), 'error');
    }
  }, 500);
  d.addEventListener('input', (ev) => {
    const input = ev.target.closest && ev.target.closest('[data-model-order]');
    if (input) saveOrder(input);
  });

  /* ------------------------------------------------------------------ colour / range / icon helpers */
  d.addEventListener('input', (ev) => {
    const picker = ev.target.closest && ev.target.closest('[data-color-sync]');
    if (picker) {
      const target = $(picker.getAttribute('data-color-sync'));
      if (target) target.value = picker.value.toUpperCase();
      const card = picker.closest('.admin-track');
      if (card) card.style.setProperty('--track', picker.value);
      return;
    }
    const text = ev.target;
    if (text && text.id) {
      const linked = $('[data-color-sync="#' + CSS.escape(text.id) + '"]');
      if (linked && /^#[0-9a-f]{6}$/i.test(text.value)) linked.value = text.value;
    }
    if (text && text.matches && text.matches('input[type="range"]') && text.id) {
      const out = $('[data-range-out="#' + CSS.escape(text.id) + '"]');
      if (out) out.textContent = Math.round(Number(text.value) * 100) + '%';
    }
  });
  d.addEventListener('change', (ev) => {
    const sel = ev.target.closest && ev.target.closest('[data-icon-select]');
    if (!sel) return;
    const use = sel.parentElement && $('[data-icon-preview] use', sel.parentElement);
    if (use) use.setAttribute('href', sel.getAttribute('data-sprite') + '#i-' + sel.value);
  });

  /* ------------------------------------------------------------------ JSON editors */
  const specEl = $('#task-spec');
  let SPEC = null;
  try { SPEC = specEl ? JSON.parse(specEl.textContent) : null; } catch (e) { SPEC = null; }
  const DEFAULT_SPEC = {
    output_types: ['html', 'js', 'answer', 'agent', 'text'],
    static: {}, browser: {}, cmp: ['deep', 'approx', 'set', 'sorted', 'truthy'], answer: {}, agent_tools: [], goals: {},
  };
  const spec = () => SPEC || DEFAULT_SPEC;
  const isObj = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);
  const isNum = (v) => typeof v === 'number' && Number.isFinite(v);

  /** Structural checks mirroring WB\Admin\TaskValidator (server stays authoritative). */
  function validateRubric(r, out) {
    if (!Array.isArray(r)) { out.push(['rubric', 'list']); return; }
    const keys = {};
    r.forEach((c, i) => {
      const p = 'rubric[' + i + ']';
      if (!isObj(c)) { out.push([p, 'object']); return; }
      if (typeof c.key !== 'string' || !/^[a-z][a-z0-9_]{0,39}$/.test(c.key)) out.push([p + '.key', 'key_format']);
      else if (keys[c.key]) out.push([p + '.key', 'duplicate', { v: c.key }]);
      else keys[c.key] = 1;
      ['label_en', 'label_ar'].forEach((f) => { if (typeof c[f] !== 'string' || !c[f].trim()) out.push([p + '.' + f, 'required']); });
      if (!('weight' in c)) out.push([p + '.weight', 'required']);
      else if (!isNum(c.weight) || c.weight <= 0) out.push([p + '.weight', 'weight']);
    });
  }
  function validateList(list, path, types, ids, out) {
    if (!Array.isArray(list)) { out.push([path, 'list']); return; }
    list.forEach((c, i) => {
      const p = path + '[' + i + ']';
      if (!isObj(c)) { out.push([p, 'object']); return; }
      if (typeof c.id !== 'string' || !/^[A-Za-z0-9_-]{1,40}$/.test(c.id)) out.push([p + '.id', 'id_format']);
      else if (ids[c.id]) out.push([p + '.id', 'duplicate', { v: c.id }]);
      else ids[c.id] = 1;
      if (typeof c.type !== 'string' || (Object.keys(types).length && !(c.type in types))) { out.push([p + '.type', 'unknown_type', { v: String(c.type) }]); return; }
      (types[c.type] || []).forEach((f) => { if (c[f] === undefined || c[f] === null || c[f] === '') out.push([p + '.' + f, 'required']); });
      if ('weight' in c && (!isNum(c.weight) || c.weight <= 0)) out.push([p + '.weight', 'weight']);
    });
  }
  function validateChecks(c, type, hasRubric, out) {
    if (Array.isArray(c) && c.length === 0) c = {};
    if (!isObj(c)) { out.push(['checks', 'object']); return; }
    const S = spec();
    Object.keys(c).forEach((k) => { if (!['static', 'browser', 'tests', 'answer', 'agent'].includes(k)) out.push(['checks.' + k, 'unknown_key']); });
    const ids = {};
    if (c.static !== undefined) validateList(c.static, 'checks.static', S.static, ids, out);
    if (c.browser !== undefined) validateList(c.browser, 'checks.browser', S.browser, ids, out);
    if (c.tests !== undefined) {
      const tt = c.tests;
      if (!isObj(tt)) out.push(['checks.tests', 'object']);
      else if (!Array.isArray(tt.cases) || !tt.cases.length) out.push(['checks.tests.cases', 'list']);
      else {
        let needsEntry = false;
        tt.cases.forEach((cs, i) => {
          const p = 'checks.tests.cases[' + i + ']';
          if (!isObj(cs)) { out.push([p, 'object']); return; }
          if (typeof cs.name !== 'string' || !cs.name.trim()) out.push([p + '.name', 'required']);
          const a = 'args' in cs;
          const s = 'script' in cs;
          if (a === s) out.push([p, 'args_or_script']);
          if (a) { needsEntry = true; if (!Array.isArray(cs.args)) out.push([p + '.args', 'list']); }
          const cmp = cs.cmp || 'deep';
          if (!S.cmp.includes(cmp)) out.push([p + '.cmp', 'unknown_type', { v: String(cmp) }]);
          if (cmp !== 'truthy' && !('expect' in cs)) out.push([p + '.expect', 'required']);
        });
        if (needsEntry && (typeof tt.entry !== 'string' || !/^[A-Za-z_$][\w$]*(\.[A-Za-z_$][\w$]*)*$/.test(tt.entry))) out.push(['checks.tests.entry', 'entry']);
        if ('weight' in tt && (!isNum(tt.weight) || tt.weight <= 0)) out.push(['checks.tests.weight', 'weight']);
      }
    }
    if (c.answer !== undefined) {
      const a = c.answer;
      if (!isObj(a)) out.push(['checks.answer', 'object']);
      else if (Object.keys(S.answer).length && !(a.type in S.answer)) out.push(['checks.answer.type', 'unknown_type', { v: String(a.type) }]);
      else (S.answer[a.type] || []).forEach((f) => { if (!(f in a)) out.push(['checks.answer.' + f, 'required']); });
    }
    if (c.agent !== undefined) {
      const ag = c.agent;
      if (!isObj(ag)) out.push(['checks.agent', 'object']);
      else {
        if (!Array.isArray(ag.tools) || !ag.tools.length) out.push(['checks.agent.tools', 'list']);
        else ag.tools.forEach((tool, i) => { if (S.agent_tools.length && !S.agent_tools.includes(tool)) out.push(['checks.agent.tools[' + i + ']', 'unknown_type', { v: String(tool) }]); });
        if (!Array.isArray(ag.goal_checks) || !ag.goal_checks.length) out.push(['checks.agent.goal_checks', 'list']);
        else ag.goal_checks.forEach((g, i) => {
          const p = 'checks.agent.goal_checks[' + i + ']';
          if (!isObj(g)) { out.push([p, 'object']); return; }
          if (Object.keys(S.goals).length && !(g.type in S.goals)) { out.push([p + '.type', 'unknown_type', { v: String(g.type) }]); return; }
          (S.goals[g.type] || []).forEach((f) => { if (!(f in g)) out.push([p + '.' + f, 'required']); });
          if ('weight' in g && (!isNum(g.weight) || g.weight <= 0)) out.push([p + '.weight', 'weight']);
        });
      }
    }
    if (type === 'answer' && !c.answer) out.push(['checks.answer', 'needed_for', { type }]);
    if (type === 'agent' && !c.agent) out.push(['checks.agent', 'needed_for', { type }]);
    if (type === 'js' && !c.tests) out.push(['checks.tests', 'needed_for', { type }]);
    const hasAuto = (c.static && c.static.length) || (c.browser && c.browser.length) || c.tests;
    if ((type === 'html' || type === 'text') && !hasAuto && !hasRubric) out.push(['checks', 'nothing']);
  }

  /** Parse a JSON textarea; returns {ok, value, error}. */
  function parse(text) {
    if (!text.trim()) return { ok: true, value: null, empty: true };
    try {
      return { ok: true, value: JSON.parse(text) };
    } catch (e) {
      return { ok: false, error: e.message };
    }
  }

  const form = $('[data-task-form]');
  const typeOf = () => (form && $('#tk-type', form) ? $('#tk-type', form).value : 'html');

  function renderStatus(area, res, issues) {
    const status = $('[data-json-status="#' + CSS.escape(area.id) + '"]');
    area.classList.toggle('is-invalid', !res.ok || issues.length > 0);
    if (!status) return;
    status.className = 'admin-json-status';
    status.textContent = '';
    if (!res.ok) {
      status.classList.add('is-bad');
      status.textContent = t('admin.js.json_bad', { msg: res.error });
      return;
    }
    if (res.empty) {
      status.textContent = t('admin.js.json_empty');
      return;
    }
    if (!issues.length) {
      status.classList.add('is-ok');
      status.textContent = t('admin.js.v_ok');
      return;
    }
    status.classList.add('is-warn');
    const wrap = el('div', 'admin-json-status-wrap');
    wrap.appendChild(el('span', '', t('admin.js.v_issues', { n: issues.length })));
    const ul = el('ul', 'admin-msgs is-error');
    issues.slice(0, 12).forEach(([path, code, rep]) => {
      const li = el('li', 't-mono t-xs', path + ': ' + t('admin.js.v_' + code, rep || {}));
      li.dir = 'ltr';
      ul.appendChild(li);
    });
    wrap.appendChild(ul);
    status.appendChild(wrap);
  }

  function checkArea(area) {
    const kind = area.getAttribute('data-json-field');
    const res = parse(area.value);
    const issues = [];
    if (res.ok && !res.empty) {
      const v = res.value;
      if (kind === 'object' && !isObj(v)) issues.push(['$', 'object']);
      if (kind === 'list') {
        const list = isObj(v) && Array.isArray(v.tasks) ? v.tasks : v;
        if (!Array.isArray(list)) issues.push(['$', 'list']);
        else list.forEach((task, i) => {
          if (!isObj(task)) { issues.push(['[' + i + ']', 'object']); return; }
          ['slug', 'track', 'title_en', 'title_ar', 'prompt', 'output_type'].forEach((f) => { if (!task[f]) issues.push(['[' + i + '].' + f, 'required']); });
          const sub = [];
          if (task.rubric !== undefined) validateRubric(task.rubric, sub);
          validateChecks(task.checks || {}, task.output_type, Array.isArray(task.rubric) && task.rubric.length > 0, sub);
          sub.forEach(([p, c, r]) => issues.push(['[' + i + '].' + p, c, r]));
        });
      }
      if (kind === 'task') {
        if (!isObj(v)) issues.push(['$', 'object']);
        else {
          ['slug', 'track', 'title_en', 'title_ar', 'prompt', 'output_type'].forEach((f) => { if (!v[f]) issues.push([f, 'required']); });
          if (v.rubric !== undefined) validateRubric(v.rubric, issues);
          validateChecks(v.checks || {}, v.output_type, Array.isArray(v.rubric) && v.rubric.length > 0, issues);
        }
      }
      if (kind === 'rubric') validateRubric(v, issues);
      if (kind === 'checks') {
        const rub = form ? parse($('#tk-rubric', form).value) : { ok: false };
        validateChecks(v, typeOf(), rub.ok && Array.isArray(rub.value) && rub.value.length > 0, issues);
      }
    }
    renderStatus(area, res, issues);
    return { res, issues };
  }

  const areas = $$('textarea[data-json-field]');
  areas.forEach((area) => {
    const run = debounce(() => { checkArea(area); renderPreview(); }, 250);
    area.addEventListener('input', run);
    area.addEventListener('keydown', (ev) => {
      if (ev.key === 'Tab' && !ev.shiftKey && !ev.ctrlKey && !ev.metaKey && !ev.altKey) {
        ev.preventDefault();
        const s = area.selectionStart;
        area.setRangeText('  ', s, area.selectionEnd, 'end');
        area.dispatchEvent(new Event('input'));
      }
    });
    checkArea(area);
  });
  if (form) {
    $$('input, select, textarea', form).forEach((n) => {
      if (!n.hasAttribute('data-json-field')) n.addEventListener('input', debounce(() => { renderPreview(); if (n.id === 'tk-type') { const c = $('#tk-checks'); if (c) checkArea(c); } }, 200));
    });
    form.addEventListener('submit', (ev) => {
      const bad = areas.filter((a) => form.contains(a) && !parse(a.value).ok);
      if (bad.length) {
        ev.preventDefault();
        bad[0].focus();
        toast(t('admin.js.fix_json'), 'error');
      }
    });
  }

  /* prompt length counter */
  $$('[data-count-for]').forEach((out) => {
    const area = $(out.getAttribute('data-count-for'));
    if (!area) return;
    const upd = () => { out.textContent = t('admin.js.chars', { n: area.value.length.toLocaleString('en-US') }); };
    area.addEventListener('input', upd);
    upd();
  });

  /* ------------------------------------------------------------------ task preview */
  function renderPreview() {
    const box = $('[data-task-preview]');
    if (!box || !form) return;
    const val = (id) => ($(id, form) ? $(id, form).value : '');
    const ar = (d.documentElement.lang || 'en') === 'ar';
    const title = (ar ? val('#tk-tar') : val('#tk-ten')) || val('#tk-ten') || val('#tk-tar') || t('admin.js.untitled');
    const summary = (ar ? val('#tk-sar') : val('#tk-sen')) || val('#tk-sen');
    box.textContent = '';
    box.appendChild(el('h3', '', title));
    if (summary) box.appendChild(el('p', '', summary));
    const meta = el('div', 'admin-preview-meta');
    const trackSel = $('#tk-track', form);
    const trackOpt = trackSel ? trackSel.selectedOptions[0] : null;
    if (trackOpt) meta.appendChild(el('span', 'pill', trackOpt.textContent));
    meta.appendChild(el('span', 'pill t-mono', val('#tk-type')));
    const diffSel = $('#tk-diff', form);
    if (diffSel && diffSel.selectedOptions[0]) meta.appendChild(el('span', 'pill', diffSel.selectedOptions[0].textContent));
    const visSel = $('#tk-vis', form);
    if (visSel && visSel.selectedOptions[0]) meta.appendChild(el('span', 'pill', visSel.selectedOptions[0].textContent));
    box.appendChild(meta);

    const rub = parse(val('#tk-rubric'));
    const rubric = rub.ok && Array.isArray(rub.value) ? rub.value.filter(isObj) : [];
    const awRaw = val('#tk-aw');
    const trackAw = trackOpt ? Number(trackOpt.getAttribute('data-aw')) : 0.4;
    const aw = rubric.length === 0 ? 1 : (awRaw !== '' && !Number.isNaN(Number(awRaw)) ? Number(awRaw) : trackAw);
    const mix = el('div', 'admin-preview-sec');
    mix.appendChild(el('strong', '', t('admin.js.p_mix')));
    mix.appendChild(el('p', '', t('admin.js.p_mix_val', { a: Math.round(aw * 100), j: Math.round((1 - aw) * 100) })));
    box.appendChild(mix);

    if (rubric.length) {
      const sec = el('div', 'admin-preview-sec');
      sec.appendChild(el('strong', '', t('admin.js.p_rubric', { n: rubric.length })));
      const total = rubric.reduce((s, c) => s + (isNum(c.weight) && c.weight > 0 ? c.weight : 0), 0) || 1;
      const wrap = el('div', 'admin-weights');
      rubric.forEach((c) => {
        const w = isNum(c.weight) && c.weight > 0 ? c.weight : 0;
        const row = el('div', 'admin-weight');
        row.appendChild(el('span', '', (ar ? c.label_ar : c.label_en) || c.label_en || c.key || '?'));
        row.appendChild(el('span', '', Math.round(w / total * 100) + '%'));
        const bar = el('span', 'admin-bar');
        const fill = el('span', 'admin-bar-fill');
        fill.style.width = (w / total * 100).toFixed(1) + '%';
        bar.appendChild(fill);
        row.appendChild(bar);
        wrap.appendChild(row);
      });
      sec.appendChild(wrap);
      box.appendChild(sec);
    }
    const ch = parse(val('#tk-checks'));
    const checks = ch.ok && isObj(ch.value) ? ch.value : {};
    const sec = el('div', 'admin-preview-sec');
    sec.appendChild(el('strong', '', t('admin.js.p_checks')));
    const chips = el('div', 'admin-chiplist');
    const add = (txt) => chips.appendChild(el('span', 'pill t-mono', txt));
    (Array.isArray(checks.static) ? checks.static : []).forEach((c) => isObj(c) && add('static · ' + (c.type || '?')));
    (Array.isArray(checks.browser) ? checks.browser : []).forEach((c) => isObj(c) && add('browser · ' + (c.type || '?')));
    if (isObj(checks.tests) && Array.isArray(checks.tests.cases)) add(t('admin.js.p_tests', { n: checks.tests.cases.length }));
    if (isObj(checks.answer)) add('answer · ' + (checks.answer.type || '?'));
    if (isObj(checks.agent)) add(t('admin.js.p_agent', { n: Array.isArray(checks.agent.goal_checks) ? checks.agent.goal_checks.length : 0 }));
    if (!chips.childNodes.length) chips.appendChild(el('span', 't-muted t-small', t('admin.js.p_none')));
    sec.appendChild(chips);
    box.appendChild(sec);
  }
  renderPreview();

  /* server validation */
  const vBtn = $('[data-validate-server]');
  if (vBtn && form) {
    vBtn.addEventListener('click', async () => {
      const out = $('[data-validate-out]');
      const fd = new FormData(form);
      const body = { id: Number(form.getAttribute('data-task-id') || 0) };
      ['slug', 'track', 'title_en', 'title_ar', 'summary_en', 'summary_ar', 'prompt', 'output_type', 'difficulty', 'rubric_json', 'checks_json', 'auto_weight', 'visibility'].forEach((k) => { body[k] = fd.get(k) || ''; });
      vBtn.disabled = true;
      const res = await post(form.getAttribute('data-validate-url'), body);
      vBtn.disabled = false;
      if (!out) return;
      out.textContent = '';
      if (res.ok) {
        out.className = 't-small t-green';
        out.textContent = t('admin.js.server_ok') + (res.warnings && res.warnings.length ? ' · ' + t('admin.js.n_warnings', { n: res.warnings.length }) : '');
      } else {
        out.className = 't-small t-red';
        out.textContent = res.errors ? t('admin.js.n_errors', { n: res.errors.length }) : (res.error || t('js.error'));
      }
      let panel = $('#live-validation');
      if (!panel) {
        panel = el('div', 'card card-pad admin-validation stack-sm');
        panel.id = 'live-validation';
        form.parentElement.parentElement.insertBefore(panel, form.parentElement);
      }
      panel.textContent = '';
      const lists = [['errors', 'is-error'], ['warnings', 'is-warn']];
      lists.forEach(([k, cls]) => {
        if (!res[k] || !res[k].length) return;
        const ul = el('ul', 'admin-msgs ' + cls);
        res[k].forEach((m) => { const li = el('li', 't-mono t-xs', m); li.dir = 'ltr'; ul.appendChild(li); });
        panel.appendChild(ul);
      });
      if (!panel.childNodes.length) {
        const ul = el('ul', 'admin-msgs is-ok');
        ul.appendChild(el('li', 't-small', t('admin.js.server_ok')));
        panel.appendChild(ul);
      }
      panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    });
  }

  /* ------------------------------------------------------------------ OpenRouter catalogue picker */
  const syncForm = $('[data-sync-form]');
  if (syncForm) {
    const rows = $$('[data-sync-row]', syncForm);
    const search = $('[data-sync-search]');
    const onlies = $$('[data-sync-only]');
    const count = $('[data-sync-count]');
    const submit = $('[data-sync-submit]');
    const all = $('[data-sync-all]', syncForm);
    const refresh = () => {
      const n = $$('[data-sync-check]:checked', syncForm).length;
      if (count) count.textContent = t('admin.js.selected', { n });
      if (submit) submit.disabled = n === 0;
      rows.forEach((r) => r.classList.toggle('is-selected', $('[data-sync-check]', r).checked));
    };
    const filter = () => {
      const q = (search ? search.value : '').trim().toLowerCase();
      const need = onlies.filter((c) => c.checked).map((c) => c.getAttribute('data-sync-only'));
      rows.forEach((r) => {
        const okQ = !q || q.split(/\s+/).every((w) => r.getAttribute('data-search').includes(w));
        const okF = need.every((k) => r.getAttribute('data-' + k) === '1');
        r.classList.toggle('is-hidden', !(okQ && okF));
      });
    };
    if (search) search.addEventListener('input', debounce(filter, 120));
    onlies.forEach((c) => c.addEventListener('change', filter));
    syncForm.addEventListener('change', (ev) => { if (ev.target.matches('[data-sync-check]')) refresh(); });
    if (all) all.addEventListener('change', () => {
      rows.forEach((r) => { if (!r.classList.contains('is-hidden')) $('[data-sync-check]', r).checked = all.checked; });
      refresh();
    });
    refresh();
  }

  /* ------------------------------------------------------------------ campaign pickers + estimate */
  d.addEventListener('click', (ev) => {
    const allBtn = ev.target.closest && ev.target.closest('[data-pick-all], [data-pick-none]');
    if (!allBtn) return;
    const target = $(allBtn.getAttribute('data-pick-all') || allBtn.getAttribute('data-pick-none'));
    if (!target) return;
    const on = allBtn.hasAttribute('data-pick-all');
    $$('input[type="checkbox"]', target).forEach((c) => {
      const item = c.closest('.admin-pick');
      if (!item || !item.classList.contains('is-hidden')) c.checked = on;
    });
    target.dispatchEvent(new Event('change', { bubbles: true }));
  });
  $$('[data-pick-search]').forEach((input) => {
    const target = $(input.getAttribute('data-pick-search'));
    input.addEventListener('input', debounce(() => {
      const q = input.value.trim().toLowerCase();
      $$('[data-pick-item]', target).forEach((it) => it.classList.toggle('is-hidden', !!q && !it.getAttribute('data-search').includes(q)));
    }, 120));
  });
  const cform = $('[data-campaign-form]');
  if (cform) {
    const box = $('.admin-estimate', cform);
    let seq = 0;
    const ids = (name) => $$('input[name="' + name + '[]"]:checked', cform).map((c) => Number(c.value));
    const estimate = debounce(async () => {
      const m = ids('model_ids');
      const tk = ids('task_ids');
      const runs = Number(($('[name="runs_per_task"]', cform) || {}).value || 1);
      const cm = $('[data-count-models]');
      const ct = $('[data-count-tasks]');
      if (cm) cm.textContent = '(' + m.length + ')';
      if (ct) ct.textContent = '(' + tk.length + ')';
      $('[data-est-runs]', cform).textContent = (m.length * tk.length * runs).toLocaleString('en-US');
      const list = $('[data-est-models]', cform);
      const note = $('[data-est-note]', cform);
      if (!m.length || !tk.length) {
        $('[data-est-total]', cform).textContent = '$0';
        $('[data-est-gen]', cform).textContent = '—';
        $('[data-est-judge]', cform).textContent = '—';
        list.textContent = '';
        return;
      }
      const my = ++seq;
      box.classList.add('is-loading');
      const res = await post(cform.getAttribute('data-estimate-url'), { model_ids: m, task_ids: tk, runs_per_task: runs });
      if (my !== seq) return;
      box.classList.remove('is-loading');
      if (!res.ok) {
        note.textContent = res.error || t('js.error');
        note.classList.add('t-red');
        return;
      }
      note.classList.remove('t-red');
      const missing = Array.isArray(res.missing_prices) ? res.missing_prices : [];
      note.classList.toggle('t-amber', missing.length > 0);
      note.textContent = missing.length ? t('admin.js.missing_prices', { list: missing.join(', ') }) : ((res.notes && res.notes[0]) || t('admin.js.est_note'));
      $('[data-est-total]', cform).textContent = money(res.total);
      $('[data-est-gen]', cform).textContent = money(res.gen_cost);
      $('[data-est-judge]', cform).textContent = money(res.judge_cost);
      $('[data-est-runs]', cform).textContent = Number(res.runs || 0).toLocaleString('en-US');
      list.textContent = '';
      (res.per_model || []).slice().sort((a, b) => b.cost - a.cost).forEach((row) => {
        const li = el('li');
        li.appendChild(el('span', '', row.name));
        li.appendChild(el('span', '', money(row.cost)));
        list.appendChild(li);
      });
    }, 350);
    cform.addEventListener('change', estimate);
    cform.addEventListener('input', (ev) => { if (ev.target.matches('[data-estimate-input]')) estimate(); });
    estimate();
  }

  /* ------------------------------------------------------------------ busy forms (long LLM calls) */
  d.addEventListener('submit', (ev) => {
    const f = ev.target.closest && ev.target.closest('[data-busy-form]');
    if (!f || ev.defaultPrevented) return;
    f.classList.add('is-busy');
    const note = $('[data-busy-note]', f);
    if (note) note.hidden = false;
  });

  /* ------------------------------------------------------------------ settings: test OpenRouter key */
  d.addEventListener('click', async (ev) => {
    const btn = ev.target.closest && ev.target.closest('[data-test-key]');
    if (!btn) return;
    const out = $('[data-test-key-out]');
    btn.disabled = true;
    if (out) { out.className = 't-small t-muted'; out.textContent = t('admin.js.testing'); }
    const res = await post(btn.getAttribute('data-test-key'), {});
    btn.disabled = false;
    if (out) {
      out.className = 't-small ' + (res.ok ? 't-green' : 't-red');
      out.textContent = res.ok ? res.message : (res.error || t('js.error'));
    }
  });

  /* ------------------------------------------------------------------ Brand Studio presets */
  const presetsEl = $('#brand-presets');
  if (presetsEl) {
    let presets = {};
    try { presets = JSON.parse(presetsEl.textContent) || {}; } catch (e) { presets = {}; }
    const kind = $('#br-kind');
    const size = $('#br-size');
    const prompt = $('#br-prompt');
    const apply = (k) => {
      const p = presets[k];
      if (!p) return;
      if (kind) kind.value = k;
      if (size) size.value = p.size;
      if (prompt) prompt.value = p.prompt;
      $$('[data-preset]').forEach((b) => b.setAttribute('aria-pressed', b.getAttribute('data-preset') === k ? 'true' : 'false'));
    };
    d.addEventListener('click', (ev) => {
      const b = ev.target.closest && ev.target.closest('[data-preset]');
      if (b) apply(b.getAttribute('data-preset'));
    });
    if (kind) kind.addEventListener('change', () => $$('[data-preset]').forEach((b) => b.setAttribute('aria-pressed', b.getAttribute('data-preset') === kind.value ? 'true' : 'false')));
    $$('[data-preset]').forEach((b) => b.setAttribute('aria-pressed', b.getAttribute('data-preset') === (kind ? kind.value : '') ? 'true' : 'false'));
  }
})();
