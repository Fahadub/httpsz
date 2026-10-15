/*!
 * WOAIBench.si — global behaviours (window.WB). Vanilla ES2020, no dependencies, CSP-safe (no inline handlers).
 *
 * API:   WB.fetchJSON(url, {method, body, headers, signal}) · WB.toast(msg, type, ms) · WB.t(key, repl)
 *        WB.colors() · WB.palette(i) · WB.chart(canvas, config | (colors) => config) · WB.debounce(fn, ms)
 *        WB.fmt.{score, money, num, pct, ms, tokens} · WB.csrf() · WB.isRTL() · WB.theme() · WB.setTheme(t)
 *        WB.reducedMotion() · WB.on(event, selector, handler)
 * Declarative: [data-theme-toggle] [data-copy] [data-copy-from] table[data-sortable] [data-filter-input]
 *        [data-tabs] [data-countup] .reveal [data-confirm] [data-nav-toggle] [data-autosubmit]
 *        [data-dismiss] .device-toggle [data-device] canvas[data-hero-art]
 * Events: document 'wb:themechange' {detail:{theme}} after the theme changes.
 */
(() => {
  'use strict';

  const d = document;
  const root = d.documentElement;
  const WB = (window.WB = window.WB || {});
  const $ = (sel, ctx = d) => ctx.querySelector(sel);
  const $$ = (sel, ctx = d) => Array.from(ctx.querySelectorAll(sel));
  const mq = (q) => (window.matchMedia ? window.matchMedia(q) : { matches: false, addEventListener() {} });
  const motionQuery = mq('(prefers-reduced-motion: reduce)');

  root.classList.add('js');

  /* ------------------------------------------------------------------ basics */

  WB.isRTL = () => (root.getAttribute('dir') || 'ltr') === 'rtl';
  WB.lang = () => root.getAttribute('lang') || 'en';
  WB.reducedMotion = () => motionQuery.matches;
  WB.csrf = () => ($('meta[name="csrf-token"]') || {}).content || '';

  /** Delegated event listener. */
  WB.on = (type, selector, handler, opts) => {
    d.addEventListener(type, (ev) => {
      const el = ev.target && ev.target.closest ? ev.target.closest(selector) : null;
      if (el) handler(ev, el);
    }, opts);
  };

  WB.debounce = (fn, ms = 200) => {
    let t = 0;
    return function debounced(...args) {
      clearTimeout(t);
      t = setTimeout(() => fn.apply(this, args), ms);
    };
  };

  /* ------------------------------------------------------------------ i18n */

  let strings = null;
  WB.t = (key, repl) => {
    if (strings === null) {
      try {
        strings = JSON.parse(($('#wb-i18n') || {}).textContent || '{}') || {};
      } catch (e) {
        strings = {};
      }
    }
    let s = Object.prototype.hasOwnProperty.call(strings, key) ? String(strings[key]) : key;
    if (repl) {
      Object.keys(repl).forEach((k) => {
        s = s.split(':' + k).join(String(repl[k])).split('{' + k + '}').join(String(repl[k]));
      });
    }
    return s;
  };

  /* ------------------------------------------------------------------ formatting (mirrors PHP fmt_*) */

  const nf = (n, dec) => Number(n).toLocaleString('en-US', { minimumFractionDigits: dec, maximumFractionDigits: dec });
  const blank = (n) => n === null || n === undefined || n === '' || Number.isNaN(Number(n));
  WB.fmt = {
    score: (n) => (blank(n) ? '—' : Number(n).toFixed(1)),
    num: (n, dec = 0) => (blank(n) ? '—' : nf(n, dec)),
    pct: (n, dec = 0) => (blank(n) ? '—' : nf(Number(n) * 100, dec) + '%'),
    money: (n) => {
      if (blank(n)) return '—';
      const v = Number(n);
      if (v === 0) return '$0';
      if (Math.abs(v) < 0.01) return '$' + String(Number(v.toFixed(4)));
      if (Math.abs(v) < 100) return '$' + nf(v, 2);
      return '$' + nf(v, 0);
    },
    ms: (n) => {
      if (blank(n)) return '—';
      const v = Number(n);
      if (v < 1000) return nf(v, 0) + ' ms';
      if (v < 60000) return nf(v / 1000, 1) + ' s';
      return nf(v / 60000, 1) + ' min';
    },
    tokens: (n) => {
      if (blank(n)) return '—';
      const v = Number(n);
      if (v >= 1e6) return nf(v / 1e6, 1) + 'M';
      if (v >= 1000) return nf(v / 1000, 1) + 'k';
      return nf(v, 0);
    },
  };

  /* ------------------------------------------------------------------ fetch */

  /**
   * JSON fetch with the CSRF header. Resolves with the parsed body for 2xx responses; otherwise rejects
   * with an Error whose .status and .data carry the server response. Unhandled rejections from
   * fetchJSON show an error toast automatically.
   */
  WB.fetchJSON = async (url, opts = {}) => {
    const hasBody = opts.body !== undefined && opts.body !== null;
    const method = String(opts.method || (hasBody ? 'POST' : 'GET')).toUpperCase();
    const headers = Object.assign({ Accept: 'application/json', 'X-Requested-With': 'fetch', 'X-CSRF-Token': WB.csrf() }, opts.headers || {});
    let body = opts.body;
    if (hasBody && !(body instanceof FormData) && !(body instanceof Blob) && !(body instanceof URLSearchParams) && typeof body !== 'string') {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(body);
    }
    let res;
    try {
      res = await fetch(url, { method, headers, body: hasBody ? body : undefined, credentials: 'same-origin', signal: opts.signal });
    } catch (e) {
      if (e && e.name === 'AbortError') throw e;
      const err = new Error(WB.t('js.error'));
      err.status = 0;
      err.wbFetch = true;
      throw err;
    }
    const text = await res.text();
    let data = null;
    try {
      data = text ? JSON.parse(text) : null;
    } catch (e) {
      data = null;
    }
    if (!res.ok) {
      const msg = data && typeof data === 'object' && (data.error || data.message);
      const err = new Error(msg ? String(msg) : WB.t('js.error') + ' (' + res.status + ')');
      err.status = res.status;
      err.data = data;
      err.wbFetch = true;
      throw err;
    }
    return data;
  };
  window.addEventListener('unhandledrejection', (ev) => {
    if (ev.reason && ev.reason.wbFetch) {
      WB.toast(ev.reason.message || WB.t('js.error'), 'error');
    }
  });

  /* ------------------------------------------------------------------ toast */

  const iconHref = () => {
    const link = $('link[rel="icon"]');
    const base = link ? link.getAttribute('href').replace(/img\/favicon\.svg.*$/, '') : '/assets/';
    return base + 'img/icons.svg';
  };
  const svgIcon = (name, size = 18) => {
    const ns = 'http://www.w3.org/2000/svg';
    const svg = d.createElementNS(ns, 'svg');
    svg.setAttribute('class', 'icon');
    svg.setAttribute('width', String(size));
    svg.setAttribute('height', String(size));
    svg.setAttribute('aria-hidden', 'true');
    const use = d.createElementNS(ns, 'use');
    use.setAttribute('href', iconHref() + '#i-' + name);
    svg.appendChild(use);
    return svg;
  };
  WB.icon = svgIcon;

  WB.toast = (msg, type = 'info', ms = 3600) => {
    let stack = $('.toast-stack');
    if (!stack) {
      stack = d.createElement('div');
      stack.className = 'toast-stack';
      stack.setAttribute('role', 'status');
      stack.setAttribute('aria-live', 'polite');
      d.body.appendChild(stack);
    }
    const kind = ['success', 'error', 'warn', 'info'].includes(type) ? type : 'info';
    const el = d.createElement('div');
    el.className = 'toast toast-' + kind;
    el.appendChild(svgIcon({ success: 'check-circle', error: 'x-circle', warn: 'alert', info: 'info' }[kind]));
    const span = d.createElement('span');
    span.textContent = String(msg);
    el.appendChild(span);
    stack.appendChild(el);
    const remove = () => {
      el.classList.add('is-leaving');
      setTimeout(() => el.remove(), 260);
    };
    const timer = setTimeout(remove, Math.max(1200, ms));
    el.addEventListener('click', () => { clearTimeout(timer); remove(); });
    return el;
  };

  /* ------------------------------------------------------------------ theme, colours, charts */

  WB.theme = () => (root.getAttribute('data-theme') === 'light' ? 'light' : 'dark');

  const tokenNames = ['bg', 'bg-elev', 'surface', 'surface-2', 'surface-3', 'border', 'border-strong', 'text', 'text-muted',
    'text-faint', 'gold', 'gold-2', 'gold-3', 'gold-soft', 'accent', 'green', 'red', 'blue', 'violet', 'amber', 'green-soft',
    'red-soft', 'blue-soft', 'violet-soft', 'focus', 'grid-line', 'font-body', 'font-mono', 'font-display'];

  /** Resolved design tokens for the current theme (camelCased keys) + heat ramp. */
  WB.colors = () => {
    const cs = getComputedStyle(root);
    const out = {};
    tokenNames.forEach((n) => {
      out[n.replace(/-([a-z0-9])/g, (_, c) => c.toUpperCase())] = cs.getPropertyValue('--' + n).trim();
    });
    out.heat = [];
    for (let i = 0; i <= 10; i++) out.heat.push(cs.getPropertyValue('--heat-' + i).trim());
    out.grid = out.gridLine;
    out.theme = WB.theme();
    return out;
  };

  // Categorical palette validated for CVD separation on both surfaces (fixed order, never cycled).
  const PALETTE = {
    dark: ['#3987E5', '#D95926', '#199E70', '#C98500', '#D55181', '#008300', '#9085E9', '#E66767'],
    light: ['#2A78D6', '#EB6834', '#1BAF7A', '#EDA100', '#E87BA4', '#008300', '#6250D6', '#E34948'],
  };
  /** Categorical colour i (0..7). Index 8+ returns the neutral "other" colour: fold extra series instead of cycling. */
  WB.palette = (i = 0) => {
    const list = PALETTE[WB.theme()];
    const n = Math.floor(Number(i) || 0);
    if (n >= 0 && n < list.length) return list[n];
    return getComputedStyle(root).getPropertyValue('--text-faint').trim() || '#8A857A';
  };
  WB.palette.size = 8;
  WB.palette.all = () => PALETTE[WB.theme()].slice();

  const charts = new Set();

  function chartDefaults(Chart, c) {
    const D = Chart.defaults;
    D.font.family = getComputedStyle(root).getPropertyValue('--font-body').trim() || 'system-ui, sans-serif';
    D.font.size = 12;
    D.color = c.textMuted;
    D.borderColor = c.gridLine;
    D.maintainAspectRatio = false;
    D.responsive = true;
    D.animation = WB.reducedMotion() ? false : { duration: 700, easing: 'easeOutQuart' };
    D.elements.line.borderWidth = 2;
    D.elements.line.tension = 0.25;
    D.elements.line.borderCapStyle = 'round';
    D.elements.point.radius = 0;
    D.elements.point.hoverRadius = 5;
    D.elements.point.hitRadius = 12;
    D.elements.bar.borderRadius = 4;
    D.elements.bar.borderSkipped = 'start';
    if (D.datasets && D.datasets.bar) D.datasets.bar.maxBarThickness = 24;
    const P = D.plugins;
    P.legend.rtl = WB.isRTL();
    P.legend.labels.usePointStyle = true;
    P.legend.labels.pointStyle = 'rectRounded';
    P.legend.labels.boxWidth = 10;
    P.legend.labels.boxHeight = 10;
    P.legend.labels.padding = 16;
    P.legend.labels.color = c.textMuted;
    P.tooltip.rtl = WB.isRTL();
    P.tooltip.backgroundColor = c.surface3;
    P.tooltip.borderColor = c.borderStrong;
    P.tooltip.borderWidth = 1;
    P.tooltip.titleColor = c.text;
    P.tooltip.bodyColor = c.textMuted;
    P.tooltip.footerColor = c.textFaint;
    P.tooltip.padding = 12;
    P.tooltip.cornerRadius = 10;
    P.tooltip.boxPadding = 6;
    P.tooltip.usePointStyle = true;
    P.tooltip.titleFont = { weight: '600', size: 13 };
    P.tooltip.bodyFont = { size: 12.5 };
    P.tooltip.caretSize = 6;
    P.tooltip.displayColors = true;
    if (D.scale) {
      D.scale.grid = Object.assign(D.scale.grid || {}, { color: c.gridLine, tickColor: 'transparent' });
      D.scale.border = Object.assign(D.scale.border || {}, { color: c.borderStrong });
      D.scale.ticks = Object.assign(D.scale.ticks || {}, { color: c.textFaint, padding: 8 });
    }
    if (D.scales && D.scales.radialLinear) {
      const r = D.scales.radialLinear;
      r.grid = Object.assign(r.grid || {}, { color: c.gridLine });
      r.angleLines = Object.assign(r.angleLines || {}, { color: c.gridLine });
      r.pointLabels = Object.assign(r.pointLabels || {}, { color: c.textMuted, font: { size: 12 } });
      r.ticks = Object.assign(r.ticks || {}, { color: c.textFaint, backdropColor: 'transparent' });
    }
  }

  /** Re-apply theme colours to scale options an existing chart carries explicitly. */
  function rethemeScales(chart, c) {
    const scales = (chart.options && chart.options.scales) || {};
    Object.keys(scales).forEach((k) => {
      const s = scales[k];
      if (s.grid) s.grid.color = c.gridLine;
      if (s.border) s.border.color = c.borderStrong;
      if (s.ticks) {
        s.ticks.color = c.textFaint;
        if ('backdropColor' in s.ticks) s.ticks.backdropColor = 'transparent';
      }
      if (s.angleLines) s.angleLines.color = c.gridLine;
      if (s.pointLabels) s.pointLabels.color = c.textMuted;
      if (s.title) s.title.color = c.textMuted;
    });
    const P = chart.options.plugins || {};
    if (P.legend && P.legend.labels) P.legend.labels.color = c.textMuted;
    if (P.tooltip) {
      P.tooltip.backgroundColor = c.surface3;
      P.tooltip.borderColor = c.borderStrong;
      P.tooltip.titleColor = c.text;
      P.tooltip.bodyColor = c.textMuted;
    }
  }

  /**
   * Chart.js with the house theme (requires assets/vendor/chart.umd.min.js on the page).
   * Pass a function (colors) => config to have data colours rebuilt when the theme toggles.
   */
  WB.chart = (canvas, config) => {
    const Chart = window.Chart;
    if (!Chart) {
      console.warn('WB.chart: Chart.js is not loaded (add vendor/chart.umd.min.js to $page_js)');
      return null;
    }
    const el = typeof canvas === 'string' ? $(canvas) : canvas;
    if (!el) return null;
    const c = WB.colors();
    chartDefaults(Chart, c);
    const factory = typeof config === 'function' ? config : null;
    const chart = new Chart(el, factory ? factory(c) : config);
    chart.$wbFactory = factory;
    charts.add(chart);
    const origDestroy = chart.destroy.bind(chart);
    chart.destroy = () => {
      charts.delete(chart);
      origDestroy();
    };
    return chart;
  };

  function rethemeCharts() {
    const Chart = window.Chart;
    if (!Chart || !charts.size) return;
    const c = WB.colors();
    chartDefaults(Chart, c);
    charts.forEach((chart) => {
      if (chart.$wbFactory) {
        const next = chart.$wbFactory(c);
        chart.data = next.data;
        if (next.options) chart.options = next.options;
      }
      rethemeScales(chart, c);
      chart.update('none');
    });
  }

  WB.setTheme = (theme) => {
    const t = theme === 'light' ? 'light' : 'dark';
    root.setAttribute('data-theme', t);
    try {
      localStorage.setItem('wb_theme', t);
    } catch (e) { /* storage unavailable */ }
    const meta = $('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', t === 'light' ? '#F6F2E9' : '#08090C');
    rethemeCharts();
    d.dispatchEvent(new CustomEvent('wb:themechange', { detail: { theme: t } }));
  };

  /* ------------------------------------------------------------------ declarative behaviours */

  function initThemeToggle() {
    const t = WB.theme();
    WB.on('click', '[data-theme-toggle]', (ev) => {
      ev.preventDefault();
      root.classList.add('theme-switching');
      WB.setTheme(WB.theme() === 'light' ? 'dark' : 'light');
      setTimeout(() => root.classList.remove('theme-switching'), 50);
    });
    const meta = $('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', t === 'light' ? '#F6F2E9' : '#08090C');
  }

  async function copyText(text) {
    try {
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(text);
        return true;
      }
    } catch (e) { /* fall through */ }
    const ta = d.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    d.body.appendChild(ta);
    ta.select();
    let ok = false;
    try {
      ok = d.execCommand('copy');
    } catch (e) {
      ok = false;
    }
    ta.remove();
    return ok;
  }
  WB.copy = copyText;

  function initCopy() {
    WB.on('click', '[data-copy], [data-copy-from]', async (ev, el) => {
      ev.preventDefault();
      let text = el.getAttribute('data-copy');
      const from = el.getAttribute('data-copy-from');
      if (from) {
        const src = $(from);
        text = src ? ('value' in src && src.value !== undefined ? src.value : src.textContent) : '';
      }
      const ok = await copyText(text || '');
      WB.toast(ok ? WB.t('js.copied') : WB.t('js.error'), ok ? 'success' : 'error', 1800);
      if (ok) {
        el.classList.add('is-copied');
        setTimeout(() => el.classList.remove('is-copied'), 1400);
      }
    });
  }

  /* ---- sortable tables: table[data-sortable] th[data-sort] td[data-value] */
  function cellValue(row, idx) {
    const cell = row.cells[idx];
    if (!cell) return '';
    const v = cell.getAttribute('data-value');
    return v !== null ? v : cell.textContent.trim();
  }
  function sortTable(table, th, forceDir) {
    const tbody = table.tBodies[0];
    if (!tbody) return;
    const idx = th.cellIndex;
    const type = th.getAttribute('data-sort');
    const rows = Array.from(tbody.rows).filter((r) => !r.hasAttribute('data-sort-fixed'));
    const fixed = Array.from(tbody.rows).filter((r) => r.hasAttribute('data-sort-fixed'));
    const numeric = type === 'num' || (type !== 'text' && rows.every((r) => {
      const v = cellValue(r, idx);
      return v === '' || !Number.isNaN(parseFloat(v));
    }));
    const current = th.getAttribute('aria-sort');
    const firstDir = th.getAttribute('data-sort-default') || (numeric ? 'descending' : 'ascending');
    const dir = forceDir || (current === 'descending' ? 'ascending' : current === 'ascending' ? 'descending' : firstDir);
    const mult = dir === 'ascending' ? 1 : -1;
    const collator = new Intl.Collator(WB.lang(), { numeric: true, sensitivity: 'base' });
    rows.sort((a, b) => {
      const va = cellValue(a, idx);
      const vb = cellValue(b, idx);
      if (numeric) {
        const na = parseFloat(va);
        const nb = parseFloat(vb);
        const ea = Number.isNaN(na);
        const eb = Number.isNaN(nb);
        if (ea || eb) return ea === eb ? 0 : ea ? 1 : -1; // blanks always last
        return (na - nb) * mult;
      }
      return collator.compare(va, vb) * mult;
    });
    $$('th[data-sort]', table).forEach((h) => h !== th && h.removeAttribute('aria-sort'));
    th.setAttribute('aria-sort', dir);
    const frag = d.createDocumentFragment();
    rows.concat(fixed).forEach((r) => frag.appendChild(r));
    tbody.appendChild(frag);
    table.dispatchEvent(new CustomEvent('wb:sorted', { detail: { column: idx, dir } }));
  }
  function initSortable() {
    $$('table[data-sortable] th[data-sort]').forEach((th) => {
      if (!th.hasAttribute('tabindex')) th.setAttribute('tabindex', '0');
      th.setAttribute('role', 'columnheader');
    });
    WB.on('click', 'table[data-sortable] th[data-sort]', (ev, th) => sortTable(th.closest('table'), th));
    WB.on('keydown', 'table[data-sortable] th[data-sort]', (ev, th) => {
      if (ev.key === 'Enter' || ev.key === ' ') {
        ev.preventDefault();
        sortTable(th.closest('table'), th);
      }
    });
  }
  WB.sortTable = sortTable;

  /* ---- live filter: <input data-filter-input="#tableId"> (rows) or a container with [data-filter-item] children */
  const normalize = (s) => String(s || '').toLowerCase()
    .replace(/[ً-ٰٟـ]/g, '') // Arabic diacritics + tatweel
    .replace(/[أإآ]/g, 'ا').replace(/ى/g, 'ي').replace(/ة/g, 'ه')
    .normalize('NFKD').replace(/[̀-ͯ]/g, '');
  function applyFilter(input) {
    const target = $(input.getAttribute('data-filter-input'));
    if (!target) return;
    const q = normalize(input.value.trim());
    const items = target.tBodies ? Array.from(target.tBodies[0] ? target.tBodies[0].rows : []) : $$('[data-filter-item]', target);
    let shown = 0;
    items.forEach((el) => {
      const hay = normalize(el.getAttribute('data-filter-text') || el.textContent);
      const ok = q === '' || hay.includes(q);
      el.hidden = !ok;
      if (ok) shown++;
    });
    const emptySel = input.getAttribute('data-filter-empty');
    const emptyEl = (emptySel ? $(emptySel) : null) || (target.parentElement ? $('[data-filter-empty]', target.parentElement) : null);
    if (emptyEl) emptyEl.hidden = shown !== 0;
    target.dispatchEvent(new CustomEvent('wb:filtered', { detail: { query: input.value, shown } }));
  }
  function initFilter() {
    const run = WB.debounce((el) => applyFilter(el), 120);
    WB.on('input', '[data-filter-input]', (ev, el) => run(el));
    $$('[data-filter-input]').forEach((el) => { if (el.value) applyFilter(el); });
  }

  /* ---- tabs: [data-tabs] containing .tab[data-tab="name"] (or href="#name"); panels [data-tab-panel="name"] */
  function tabName(tab) {
    return tab.getAttribute('data-tab') || (tab.getAttribute('href') || '').replace(/^#/, '');
  }
  function activateTab(group, tab, focus) {
    const scope = group.closest('[data-tabs-root]') || d;
    const tabs = $$('.tab', group);
    tabs.forEach((t) => {
      const on = t === tab;
      t.classList.toggle('active', on);
      t.setAttribute('aria-selected', on ? 'true' : 'false');
      t.setAttribute('tabindex', on ? '0' : '-1');
      const panel = $('[data-tab-panel="' + CSS.escape(tabName(t)) + '"]', scope);
      if (panel) panel.hidden = !on;
    });
    if (focus) tab.focus();
    if (group.getAttribute('data-tabs') === 'hash' && history.replaceState) {
      history.replaceState(null, '', '#' + tabName(tab));
    }
    group.dispatchEvent(new CustomEvent('wb:tab', { detail: { tab: tabName(tab) } }));
  }
  function initTabs() {
    $$('[data-tabs]').forEach((group) => {
      const scope = group.closest('[data-tabs-root]') || d;
      group.setAttribute('role', 'tablist');
      const tabs = $$('.tab', group);
      tabs.forEach((t) => {
        t.setAttribute('role', 'tab');
        const panel = $('[data-tab-panel="' + CSS.escape(tabName(t)) + '"]', scope);
        if (panel) {
          if (!panel.id) panel.id = 'panel-' + tabName(t);
          panel.setAttribute('role', 'tabpanel');
          t.setAttribute('aria-controls', panel.id);
        }
      });
      const fromHash = group.getAttribute('data-tabs') === 'hash' && location.hash ? tabs.find((t) => '#' + tabName(t) === location.hash) : null;
      const initial = fromHash || tabs.find((t) => t.classList.contains('active') || t.getAttribute('aria-selected') === 'true') || tabs[0];
      if (initial) activateTab(group, initial, false);
    });
    WB.on('click', '[data-tabs] .tab', (ev, tab) => {
      ev.preventDefault();
      activateTab(tab.closest('[data-tabs]'), tab, false);
    });
    WB.on('keydown', '[data-tabs] .tab', (ev, tab) => {
      const keys = WB.isRTL() ? { ArrowLeft: 1, ArrowRight: -1 } : { ArrowLeft: -1, ArrowRight: 1 };
      const group = tab.closest('[data-tabs]');
      const tabs = $$('.tab', group);
      let i = tabs.indexOf(tab);
      if (ev.key in keys) i = (i + keys[ev.key] + tabs.length) % tabs.length;
      else if (ev.key === 'Home') i = 0;
      else if (ev.key === 'End') i = tabs.length - 1;
      else return;
      ev.preventDefault();
      activateTab(group, tabs[i], true);
    });
  }

  /* ---- count-up numbers: <span data-countup="1234">1,234</span> (final text is restored exactly) */
  function countUp(el) {
    const target = parseFloat(el.getAttribute('data-countup'));
    const finalText = el.textContent;
    if (!Number.isFinite(target) || WB.reducedMotion() || target === 0) return;
    const dec = (String(el.getAttribute('data-countup')).split('.')[1] || '').length;
    const grouped = /\d,\d/.test(finalText);
    const start = performance.now();
    const dur = Math.min(1800, 900 + Math.log10(target + 1) * 220);
    const step = (now) => {
      const p = Math.min(1, (now - start) / dur);
      const eased = 1 - Math.pow(1 - p, 3);
      const v = target * eased;
      el.textContent = grouped ? nf(v, dec) : v.toFixed(dec);
      if (p < 1) requestAnimationFrame(step);
      else el.textContent = finalText;
    };
    el.textContent = grouped ? nf(0, dec) : (0).toFixed(dec);
    requestAnimationFrame(step);
  }

  /* ---- scroll-in reveal + count-up via one IntersectionObserver.
     Reveal never hides content: items already on screen (or inside .hero, animated by CSS) are left alone;
     items below the fold play a short entrance when they first scroll into view. */
  function initObservers() {
    const vh = window.innerHeight || 800;
    const reveals = $$('.reveal').filter((el) => !el.closest('.hero') && el.getBoundingClientRect().top > vh);
    const counters = $$('[data-countup]');
    if (!('IntersectionObserver' in window)) return;
    const io = new IntersectionObserver((entries) => {
      entries.forEach((en) => {
        if (!en.isIntersecting) return;
        const el = en.target;
        io.unobserve(el);
        if (el.classList.contains('reveal')) {
          el.classList.add('is-visible');
          if (!WB.reducedMotion() && el.animate) {
            const i = parseFloat(getComputedStyle(el).getPropertyValue('--i')) || 0;
            el.animate([{ opacity: 0, transform: 'translateY(18px)' }, { opacity: 1, transform: 'none' }],
              { duration: 760, delay: i * 70, easing: 'cubic-bezier(.16,1,.3,1)', fill: 'backwards' });
          }
        }
        if (el.hasAttribute('data-countup')) countUp(el);
      });
    }, { rootMargin: '0px 0px 0px 0px', threshold: 0 });
    reveals.forEach((el) => io.observe(el));
    counters.forEach((el) => io.observe(el));
  }

  /* ---- confirmations: forms and buttons/links with [data-confirm="message"] */
  function initConfirm() {
    d.addEventListener('submit', (ev) => {
      const form = ev.target;
      if (!(form instanceof HTMLFormElement)) return;
      const btn = ev.submitter;
      if (btn && btn.hasAttribute('data-confirm')) return; // already confirmed on click
      const msg = form.getAttribute('data-confirm');
      if (msg !== null && !window.confirm(msg || WB.t('js.confirm'))) ev.preventDefault();
    }, true);
    d.addEventListener('click', (ev) => {
      const el = ev.target.closest ? ev.target.closest('[data-confirm]') : null;
      if (!el || el.tagName === 'FORM') return;
      const msg = el.getAttribute('data-confirm');
      if (!window.confirm(msg || WB.t('js.confirm'))) {
        ev.preventDefault();
        ev.stopImmediatePropagation();
      }
    }, true);
  }

  /* ---- navigation drawer: [data-nav-toggle][aria-controls="id"] */
  function initNav() {
    const toggles = $$('[data-nav-toggle]');
    if (!toggles.length) return;
    let lastToggle = null;
    const targetOf = (btn) => d.getElementById(btn.getAttribute('aria-controls') || '') || $('#site-nav');
    const setOpen = (btn, open) => {
      const target = targetOf(btn);
      if (!target) return;
      target.classList.toggle('is-open', open);
      d.body.classList.toggle('nav-open', open);
      $$('[data-nav-toggle]').forEach((b) => {
        if (targetOf(b) === target) b.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
      if (open) {
        lastToggle = btn;
        const first = target.querySelector('a, button, input');
        if (first) setTimeout(() => first.focus({ preventScroll: true }), 60);
      } else if (lastToggle && d.activeElement && target.contains(d.activeElement)) {
        lastToggle.focus({ preventScroll: true });
      }
    };
    const openToggle = () => toggles.find((b) => b.getAttribute('aria-expanded') === 'true');
    WB.on('click', '[data-nav-toggle]', (ev, btn) => {
      ev.preventDefault();
      setOpen(btn, btn.getAttribute('aria-expanded') !== 'true');
    });
    d.addEventListener('keydown', (ev) => {
      const b = openToggle();
      if (ev.key === 'Escape' && b) setOpen(b, false);
    });
    d.addEventListener('click', (ev) => {
      const b = openToggle();
      if (!b) return;
      const target = targetOf(b);
      if (ev.target === d.body || (target && ev.target.closest && ev.target.closest('a[href]') && target.contains(ev.target))) {
        setOpen(b, false);
      }
    });
    const wide = mq('(min-width: 1181px)');
    const onWide = () => { const b = openToggle(); if (b && wide.matches && b.closest('.site-header')) setOpen(b, false); };
    if (wide.addEventListener) wide.addEventListener('change', onWide);
  }

  /* ---- auto-submit selects/inputs: <select data-autosubmit> */
  function initAutosubmit() {
    d.addEventListener('change', (ev) => {
      const el = ev.target;
      if (!el || !el.closest || !el.closest('[data-autosubmit]')) return;
      const form = el.form || el.closest('form');
      if (!form) return;
      if (form.requestSubmit) form.requestSubmit();
      else form.submit();
    });
  }

  /* ---- dismissable alerts: <button data-dismiss> inside .alert */
  function initDismiss() {
    WB.on('click', '[data-dismiss]', (ev, btn) => {
      const box = btn.closest(btn.getAttribute('data-dismiss') || '.alert') || btn.parentElement;
      if (box) box.remove();
    });
  }

  /* ---- render frame device toggle: .device-toggle button[data-device="desktop|tablet|mobile"] */
  function initDeviceToggle() {
    WB.on('click', '.device-toggle [data-device]', (ev, btn) => {
      const frame = btn.closest('.render-frame') || $(btn.closest('.device-toggle').getAttribute('data-target') || '');
      if (!frame) return;
      frame.setAttribute('data-device', btn.getAttribute('data-device'));
      $$('[data-device]', btn.closest('.device-toggle')).forEach((b) => b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'));
    });
  }

  /* ---- header glass on scroll */
  function initHeader() {
    const header = $('.site-header');
    if (!header) return;
    let ticking = false;
    const update = () => {
      header.classList.toggle('is-scrolled', window.scrollY > 8);
      ticking = false;
    };
    window.addEventListener('scroll', () => {
      if (!ticking) {
        ticking = true;
        requestAnimationFrame(update);
      }
    }, { passive: true });
    update();
  }

  /* ------------------------------------------------------------------ hero art: precision dial */

  /**
   * Generative "calibration field": a slowly turning graduated bezel, counter-rotating arcs, a sweep and
   * measurement points that light up as the sweep passes. ~30 fps, paused off-screen / in hidden tabs,
   * static under prefers-reduced-motion.
   */
  function HeroArt(canvas) {
    const ctx = canvas.getContext('2d');
    if (!ctx) return;
    const variant = canvas.getAttribute('data-hero-art') || 'dial';
    let w = 0, h = 0, dpr = 1, raf = 0, visible = true, last = 0, t0 = performance.now();
    let col = {};
    const rnd = mulberry32(1337);
    const pts = Array.from({ length: variant === 'quiet' ? 46 : 84 }, () => ({
      a: rnd() * Math.PI * 2,
      r: 0.16 + Math.pow(rnd(), 0.8) * 0.66,
      s: 0.6 + rnd() * 1.6,
      drift: (rnd() - 0.5) * 0.02,
      lit: 0,
    }));

    function readColors() {
      const c = WB.colors();
      col = { gold: c.gold || '#D9B26A', gold2: c.gold2 || '#F0D9A6', faint: c.textFaint || '#8A857A', text: c.text || '#F3EEE4', light: c.theme === 'light' };
    }
    function resize() {
      const rect = canvas.getBoundingClientRect();
      dpr = Math.min(2, window.devicePixelRatio || 1);
      w = Math.max(1, rect.width);
      h = Math.max(1, rect.height);
      canvas.width = Math.round(w * dpr);
      canvas.height = Math.round(h * dpr);
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    }
    function alpha(hex, a) {
      const m = /^#?([0-9a-f]{6})$/i.exec(hex);
      if (!m) return hex;
      const n = parseInt(m[1], 16);
      return 'rgba(' + (n >> 16 & 255) + ',' + (n >> 8 & 255) + ',' + (n & 255) + ',' + a + ')';
    }
    function draw(now) {
      const t = (now - t0) / 1000;
      const cx = w / 2, cy = h / 2;
      const R = Math.min(w, h) * 0.46;
      const k = col.light ? 1.25 : 1;
      ctx.clearRect(0, 0, w, h);
      ctx.lineCap = 'round';

      // glow
      const g = ctx.createRadialGradient(cx, cy, R * 0.05, cx, cy, R * 1.05);
      g.addColorStop(0, alpha(col.gold, col.light ? 0.10 : 0.12));
      g.addColorStop(1, alpha(col.gold, 0));
      ctx.fillStyle = g;
      ctx.beginPath();
      ctx.arc(cx, cy, R * 1.05, 0, Math.PI * 2);
      ctx.fill();

      // outer bezel: graduations every 2°, longer every 10°, major every 30°
      const rot = WB.reducedMotion() ? 0 : t * 0.035;
      ctx.save();
      ctx.translate(cx, cy);
      ctx.rotate(rot);
      ctx.strokeStyle = alpha(col.gold, 0.38 * k);
      ctx.lineWidth = 1;
      ctx.beginPath();
      ctx.arc(0, 0, R, 0, Math.PI * 2);
      ctx.stroke();
      for (let i = 0; i < 180; i++) {
        const a = (i / 180) * Math.PI * 2;
        const major = i % 15 === 0, mid = i % 5 === 0;
        const len = major ? R * 0.075 : mid ? R * 0.045 : R * 0.022;
        ctx.strokeStyle = alpha(major ? col.gold : col.faint, (major ? 0.75 : mid ? 0.45 : 0.28) * k);
        ctx.lineWidth = major ? 1.4 : 1;
        ctx.beginPath();
        ctx.moveTo(Math.cos(a) * (R - 3), Math.sin(a) * (R - 3));
        ctx.lineTo(Math.cos(a) * (R - 3 - len), Math.sin(a) * (R - 3 - len));
        ctx.stroke();
      }
      ctx.restore();

      // counter-rotating arcs
      const arcs = [[0.79, 0.12, 1.4, -0.06], [0.79, 2.3, 0.7, -0.06], [0.79, 3.6, 1.9, -0.06], [0.62, 0.8, 2.2, 0.09], [0.62, 3.9, 1.1, 0.09]];
      arcs.forEach(([rr, start, len, speed]) => {
        const s = start + (WB.reducedMotion() ? 0 : t * speed);
        ctx.strokeStyle = alpha(col.gold, (rr > 0.7 ? 0.5 : 0.32) * k);
        ctx.lineWidth = rr > 0.7 ? 1.6 : 1;
        ctx.beginPath();
        ctx.arc(cx, cy, R * rr, s, s + len);
        ctx.stroke();
      });
      // dotted inner ring
      ctx.fillStyle = alpha(col.faint, 0.45 * k);
      for (let i = 0; i < 96; i++) {
        const a = (i / 96) * Math.PI * 2 - rot * 0.5;
        ctx.beginPath();
        ctx.arc(cx + Math.cos(a) * R * 0.46, cy + Math.sin(a) * R * 0.46, 0.8, 0, Math.PI * 2);
        ctx.fill();
      }
      // crosshair
      ctx.strokeStyle = alpha(col.faint, 0.35 * k);
      ctx.lineWidth = 1;
      ctx.beginPath();
      ctx.moveTo(cx - R * 0.94, cy); ctx.lineTo(cx - R * 0.5, cy);
      ctx.moveTo(cx + R * 0.5, cy); ctx.lineTo(cx + R * 0.94, cy);
      ctx.moveTo(cx, cy - R * 0.94); ctx.lineTo(cx, cy - R * 0.5);
      ctx.moveTo(cx, cy + R * 0.5); ctx.lineTo(cx, cy + R * 0.94);
      ctx.stroke();

      // sweep
      const sweep = WB.reducedMotion() ? -0.9 : (t * 0.55) % (Math.PI * 2);
      const slices = 28;
      for (let i = 0; i < slices; i++) {
        const a1 = sweep - (i / slices) * 0.9;
        ctx.fillStyle = alpha(col.gold, (1 - i / slices) * 0.05 * k);
        ctx.beginPath();
        ctx.moveTo(cx, cy);
        ctx.arc(cx, cy, R * 0.94, a1 - 0.9 / slices, a1);
        ctx.closePath();
        ctx.fill();
      }
      ctx.strokeStyle = alpha(col.gold2, 0.55 * k);
      ctx.lineWidth = 1.2;
      ctx.beginPath();
      ctx.moveTo(cx, cy);
      ctx.lineTo(cx + Math.cos(sweep) * R * 0.94, cy + Math.sin(sweep) * R * 0.94);
      ctx.stroke();

      // measurement points
      pts.forEach((p) => {
        if (!WB.reducedMotion()) p.r = Math.min(0.9, Math.max(0.12, p.r + Math.sin(t * 0.3 + p.a) * p.drift * 0.01));
        let diff = (sweep - p.a) % (Math.PI * 2);
        if (diff < 0) diff += Math.PI * 2;
        if (diff < 0.06) p.lit = 1;
        p.lit *= WB.reducedMotion() ? 1 : 0.965;
        const x = cx + Math.cos(p.a) * R * p.r, y = cy + Math.sin(p.a) * R * p.r;
        const base = 0.22 + p.lit * 0.75;
        ctx.fillStyle = alpha(p.lit > 0.2 ? col.gold2 : col.gold, Math.min(1, base * k));
        ctx.beginPath();
        ctx.arc(x, y, p.s * (1 + p.lit * 0.6), 0, Math.PI * 2);
        ctx.fill();
        if (p.lit > 0.25) {
          ctx.strokeStyle = alpha(col.gold, p.lit * 0.5 * k);
          ctx.lineWidth = 1;
          ctx.beginPath();
          ctx.arc(x, y, p.s + (1 - p.lit) * 14, 0, Math.PI * 2);
          ctx.stroke();
        }
      });

      // centre pin
      ctx.fillStyle = alpha(col.gold, 0.9);
      ctx.beginPath();
      ctx.arc(cx, cy, 2.4, 0, Math.PI * 2);
      ctx.fill();
      ctx.strokeStyle = alpha(col.gold, 0.5 * k);
      ctx.beginPath();
      ctx.arc(cx, cy, 7, 0, Math.PI * 2);
      ctx.stroke();
    }
    function loop(now) {
      raf = 0;
      if (!visible || d.hidden) return;
      if (now - last >= 33) { // ~30 fps is plenty for slow motion and halves the CPU cost
        last = now;
        draw(now);
      }
      raf = requestAnimationFrame(loop);
    }
    function start() {
      if (WB.reducedMotion()) {
        draw(performance.now());
        return;
      }
      if (!raf) raf = requestAnimationFrame(loop);
    }
    readColors();
    resize();
    draw(performance.now());
    if ('IntersectionObserver' in window) {
      new IntersectionObserver((es) => {
        visible = es[0].isIntersecting;
        if (visible) start();
      }, { threshold: 0.01 }).observe(canvas);
    }
    d.addEventListener('visibilitychange', () => { if (!d.hidden) start(); });
    window.addEventListener('resize', WB.debounce(() => { resize(); draw(performance.now()); }, 150));
    d.addEventListener('wb:themechange', () => { readColors(); draw(performance.now()); });
    if (motionQuery.addEventListener) motionQuery.addEventListener('change', () => { draw(performance.now()); start(); });
    start();
  }
  function mulberry32(a) {
    return () => {
      a |= 0; a = a + 0x6D2B79F5 | 0;
      let t = Math.imul(a ^ a >>> 15, 1 | a);
      t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t;
      return ((t ^ t >>> 14) >>> 0) / 4294967296;
    };
  }
  function initHeroArt() {
    $$('canvas[data-hero-art]').forEach((c) => {
      try {
        HeroArt(c);
      } catch (e) {
        console.warn('hero art disabled', e);
      }
    });
  }

  /* ---- horizontal scrollers (chip rows, tabs, wide tables): .can-scroll-start / .can-scroll-end fade the
     edge that hides content (CSS), and the active chip/tab is scrolled into view without moving the page. */
  const SCROLLERS = '.pub-chips, .tabs, .table-wrap, .pub-lb-scroll, .pub-kpis, .lb-table thead tr';
  function centerInScroller(sc, el) {
    if (!sc || !el || sc.scrollWidth <= sc.clientWidth + 1) return;
    const a = el.getBoundingClientRect();
    const b = sc.getBoundingClientRect();
    sc.scrollLeft += (a.left + a.width / 2) - (b.left + b.width / 2);
  }
  WB.centerInScroller = centerInScroller;
  function updateScrollHint(sc) {
    const ox = getComputedStyle(sc).overflowX; // e.g. the leaderboard turns into cards (overflow visible) on phones
    const max = ox === 'auto' || ox === 'scroll' ? sc.scrollWidth - sc.clientWidth : 0;
    const pos = Math.abs(sc.scrollLeft); // RTL scrollers count negative from the start edge
    sc.classList.toggle('can-scroll-start', max > 1 && pos > 1);
    sc.classList.toggle('can-scroll-end', max > 1 && pos < max - 1);
  }
  WB.updateScrollHint = updateScrollHint;
  function initScrollHints() {
    const list = $$(SCROLLERS);
    if (!list.length) return;
    list.forEach((sc) => {
      centerInScroller(sc, $('.chip.active, .chip[aria-current="page"], .tab.active, .tab[aria-selected="true"]', sc));
      updateScrollHint(sc);
      sc.addEventListener('scroll', () => updateScrollHint(sc), { passive: true });
    });
    if ('ResizeObserver' in window) {
      const ro = new ResizeObserver((entries) => entries.forEach((en) => updateScrollHint(en.target)));
      list.forEach((sc) => ro.observe(sc));
    } else {
      window.addEventListener('resize', WB.debounce(() => list.forEach(updateScrollHint), 150));
    }
  }

  /* ---- range sliders: keep --pct (filled part of the track) in sync with the value */
  function syncRange(el) {
    if (!el || el.type !== 'range') return;
    const min = el.min === '' ? 0 : parseFloat(el.min);
    const max = el.max === '' ? 100 : parseFloat(el.max);
    const val = parseFloat(el.value);
    const pct = max > min && Number.isFinite(val) ? Math.min(100, Math.max(0, (val - min) / (max - min) * 100)) : 0;
    el.style.setProperty('--pct', pct.toFixed(2) + '%');
  }
  WB.syncRange = syncRange;
  function initRanges() {
    const onEvt = (ev) => { if (ev.target && ev.target.matches && ev.target.matches('input[type="range"]')) syncRange(ev.target); };
    d.addEventListener('input', onEvt);
    d.addEventListener('change', onEvt);
    // form resets restore the default value without an input event
    d.addEventListener('reset', (ev) => setTimeout(() => $$('input[type="range"]', ev.target).forEach(syncRange), 0));
    $$('input[type="range"], input.range').forEach(syncRange);
  }

  /* ------------------------------------------------------------------ boot */

  function boot() {
    initThemeToggle();
    initCopy();
    initSortable();
    initFilter();
    initTabs();
    initConfirm();
    initNav();
    initAutosubmit();
    initDismiss();
    initDeviceToggle();
    initHeader();
    initObservers();
    initHeroArt();
    initRanges();
    initScrollHints();
    $$('table[data-sortable] th[data-sort][data-sort-initial]').forEach((th) => sortTable(th.closest('table'), th, th.getAttribute('data-sort-initial')));
  }
  if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
