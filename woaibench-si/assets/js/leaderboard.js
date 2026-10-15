/* Leaderboard page: filters, sorting, column visibility, compare picks. Module: public. */
(function () {
  'use strict';

  var table = document.querySelector('[data-lb-table]');
  if (!table) return;

  var strings = {};
  try { strings = JSON.parse((document.getElementById('pub-i18n') || {}).textContent || '{}'); } catch (e) { strings = {}; }
  function t(key, vars) {
    var s = strings['pub.js.' + key] || key;
    Object.keys(vars || {}).forEach(function (k) { s = s.split(':' + k).join(String(vars[k])); });
    return s;
  }
  function store(key, val) {
    try {
      if (val === undefined) return localStorage.getItem(key);
      localStorage.setItem(key, val);
    } catch (e) { return null; }
    return null;
  }

  /* ---------- phones: filters and legend sit behind toggles (CSS shows them on wider screens) ---------- */
  Array.prototype.forEach.call(document.querySelectorAll('[data-collapse] .pub-collapse-toggle'), function (btn) {
    var body = document.getElementById(btn.getAttribute('aria-controls'));
    if (!body) return;
    btn.addEventListener('click', function () {
      var open = !body.classList.contains('is-open');
      body.classList.toggle('is-open', open);
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  });

  var tbody = table.tBodies[0];
  var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr.lb-row'));
  var noneRow = tbody.querySelector('tr.lb-none');
  var headers = Array.prototype.slice.call(table.tHead.rows[0].cells);
  var countEl = document.querySelector('[data-lb-count]');
  var search = document.querySelector('[data-lb-search]');
  var vendor = document.querySelector('[data-lb-vendor]');
  var openOnly = document.querySelector('[data-lb-open]');

  /* ---------- filters ---------- */
  function applyFilters() {
    var q = (search && search.value || '').trim().toLowerCase();
    var v = vendor ? vendor.value : '';
    var o = openOnly ? openOnly.checked : false;
    var shown = 0;
    rows.forEach(function (tr) {
      var ok = (!q || tr.getAttribute('data-search').indexOf(q) !== -1)
        && (!v || tr.getAttribute('data-provider') === v)
        && (!o || tr.getAttribute('data-open') === '1');
      tr.hidden = !ok;
      if (ok) shown++;
    });
    if (noneRow) noneRow.hidden = shown !== 0;
    if (countEl) countEl.textContent = t('showing', { n: shown, total: rows.length });
  }
  var debounce = (window.WB && WB.debounce) ? WB.debounce : function (fn, ms) {
    var h; return function () { clearTimeout(h); h = setTimeout(fn, ms); };
  };
  if (search) search.addEventListener('input', debounce(applyFilters, 120));
  if (vendor) vendor.addEventListener('change', applyFilters);
  if (openOnly) openOnly.addEventListener('change', applyFilters);

  /* ---------- sorting ---------- */
  var ASC_FIRST = { rank: true, name: true, cost: true, latency: true };
  var state = { key: 'rank', dir: 1 };

  function cellValue(tr, idx, isText) {
    var td = tr.cells[idx];
    var raw = td ? td.getAttribute('data-v') : '';
    if (raw === null || raw === '') return null;
    return isText ? raw : parseFloat(raw);
  }

  function sortBy(key, dir) {
    var idx = -1;
    headers.forEach(function (th, i) { if (th.getAttribute('data-lb-sort') === key) idx = i; });
    if (idx < 0) return;
    var isText = key === 'name';
    var sorted = rows.slice().sort(function (a, b) {
      var pa = a.classList.contains('is-provisional') ? 1 : 0;
      var pb = b.classList.contains('is-provisional') ? 1 : 0;
      if (pa !== pb) return pa - pb;
      var va = cellValue(a, idx, isText);
      var vb = cellValue(b, idx, isText);
      if (va === null && vb === null) return +a.getAttribute('data-order') - +b.getAttribute('data-order');
      if (va === null) return 1;
      if (vb === null) return -1;
      var c = isText ? va.localeCompare(vb) : va - vb;
      return c === 0 ? +a.getAttribute('data-order') - +b.getAttribute('data-order') : c * dir;
    });
    sorted.forEach(function (tr) { tbody.insertBefore(tr, noneRow); });
    state = { key: key, dir: dir };
    headers.forEach(function (th) {
      if (!th.hasAttribute('data-lb-sort')) return;
      if (th.getAttribute('data-lb-sort') === key) th.setAttribute('aria-sort', dir === 1 ? 'ascending' : 'descending');
      else th.removeAttribute('aria-sort');
    });
    table.classList.toggle('is-custom-sort', key !== 'rank' || dir !== 1);
  }

  headers.forEach(function (th) {
    var key = th.getAttribute('data-lb-sort');
    var btn = th.querySelector('button');
    if (!key || !btn) return;
    btn.addEventListener('click', function () {
      var dir = state.key === key ? -state.dir : (ASC_FIRST[key] ? 1 : -1);
      sortBy(key, dir);
    });
  });

  /* ---------- column visibility ---------- */
  var colsBox = document.querySelector('[data-lb-cols]');
  if (colsBox) {
    var hidden = [];
    try { hidden = JSON.parse(store('wb_lb_hidden_cols') || '[]'); } catch (e) { hidden = []; }
    if (!Array.isArray(hidden)) hidden = [];
    var boxes = Array.prototype.slice.call(colsBox.querySelectorAll('input[type=checkbox]'));
    function syncCols() {
      boxes.forEach(function (b) { table.classList.toggle('hide-' + b.value, !b.checked); });
      store('wb_lb_hidden_cols', JSON.stringify(boxes.filter(function (b) { return !b.checked; }).map(function (b) { return b.value; })));
    }
    boxes.forEach(function (b) {
      b.checked = hidden.indexOf(b.value) === -1;
      b.addEventListener('change', syncCols);
    });
    syncCols();
    document.addEventListener('click', function (e) {
      if (colsBox.open && !colsBox.contains(e.target)) colsBox.open = false;
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && colsBox.open) { colsBox.open = false; colsBox.querySelector('summary').focus(); }
    });
  }

  /* ---------- compare picks ---------- */
  var MAX = 4;
  var bar = document.querySelector('[data-lb-comparebar]');
  var link = document.querySelector('[data-lb-compare]');
  var countPick = document.querySelector('[data-lb-pick-count]');
  var picks = Array.prototype.slice.call(table.querySelectorAll('[data-lb-pick]'));
  function syncPicks() {
    var chosen = picks.filter(function (p) { return p.checked; });
    picks.forEach(function (p) {
      p.disabled = !p.checked && chosen.length >= MAX;
      var tr = p.closest('tr');
      if (tr) tr.classList.toggle('is-highlight', p.checked);
    });
    if (!bar) return;
    bar.hidden = chosen.length === 0;
    document.body.classList.toggle('has-comparebar', chosen.length > 0);
    if (countPick) countPick.textContent = t(chosen.length >= MAX ? 'picked_max' : 'picked', { n: chosen.length, max: MAX });
    if (link) {
      var slugs = chosen.map(function (p) { return p.value; });
      link.href = link.getAttribute('data-base') + (slugs.length ? '?m=' + slugs.map(encodeURIComponent).join(',') : '');
      link.classList.toggle('is-disabled', slugs.length < 2);
      link.setAttribute('aria-disabled', slugs.length < 2 ? 'true' : 'false');
    }
  }
  picks.forEach(function (p) { p.addEventListener('change', syncPicks); });
  if (link) link.addEventListener('click', function (e) {
    if (link.getAttribute('aria-disabled') === 'true') { e.preventDefault(); if (window.WB && WB.toast) WB.toast(t('pick_two'), 'info'); }
  });
  var clear = document.querySelector('[data-lb-clear]');
  if (clear) clear.addEventListener('click', function () { picks.forEach(function (p) { p.checked = false; }); syncPicks(); });

  syncPicks();
  applyFilters();
})();
