// DEV ONLY — independent, deliberately simple (slow) oracles used by build/algo.mjs to cross-check the
// WB-ORACLE references on thousands of random small inputs. They share no code with the references.

export function studioBrute(capacities, bookings, turnaround) {
  const freeAt = capacities.map(() => -Infinity);
  const order = bookings.map((b, j) => [b.start, j]).sort((p, q) => p[0] - q[0] || p[1] - q[1]).map((p) => p[1]);
  const res = bookings.map(() => -1);
  for (const j of order) {
    const b = bookings[j];
    const ok = capacities.map((c, i) => i).filter((i) => freeAt[i] <= b.start && capacities[i] >= b.size);
    ok.sort((p, q) => capacities[p] - capacities[q] || p - q);
    if (ok.length) { res[j] = ok[0]; freeAt[ok[0]] = b.end + turnaround; }
  }
  return res;
}

export function transitBrute(n, links, source, target, startTime) {
  const arr = new Array(n).fill(Infinity);
  arr[source] = startTime;
  for (let round = 0; round <= n; round++) {
    let changed = false;
    for (const l of links) {
      const t = arr[l.from];
      if (t === Infinity) continue;
      let dep = Infinity;
      for (const [o, c] of l.windows) if (c >= t) dep = Math.min(dep, Math.max(o, t));
      if (dep + l.duration < arr[l.to]) { arr[l.to] = dep + l.duration; changed = true; }
    }
    if (!changed) break;
  }
  return arr[target] === Infinity ? -1 : arr[target];
}

export function editDistanceDP(oldText, newText) {
  const a = oldText === '' ? [] : oldText.split('\n');
  const b = newText === '' ? [] : newText.split('\n');
  let prev = new Array(b.length + 1).fill(0);
  for (let i = 1; i <= a.length; i++) {
    const cur = [0];
    for (let j = 1; j <= b.length; j++) cur[j] = a[i - 1] === b[j - 1] ? prev[j - 1] + 1 : Math.max(prev[j], cur[j - 1]);
    prev = cur;
  }
  return a.length + b.length - 2 * prev[b.length];
}

export function rankBrute(x, y) {
  let C = 0, D = 0, tx = 0, ty = 0, tb = 0;
  for (let i = 0; i < x.length; i++) {
    for (let j = i + 1; j < x.length; j++) {
      const sx = Math.sign(x[i] - x[j]);
      const sy = Math.sign(y[i] - y[j]);
      if (sx === 0 && sy === 0) tb++;
      else if (sx === 0) tx++;
      else if (sy === 0) ty++;
      else if (sx === sy) C++;
      else D++;
    }
  }
  const den = (C + D + ty) * (C + D + tx);
  return { concordant: C, discordant: D, tiesX: tx, tiesY: ty, tiesBoth: tb, tauB: den === 0 ? null : (C - D) / Math.sqrt(den) };
}

// ── spreadsheet: precedence-climbing parser + memoised recursion + reachability-based cycle test ──
const BIN = { '+': [1, 'L'], '-': [1, 'L'], '*': [2, 'L'], '/': [2, 'L'], '^': [4, 'R'] };
function lex(s) {
  const out = [];
  const re = /[ \t]*(?:(\d+\.?\d*|\.\d+)|([A-Za-z]+\d*)|([-+*/^(),:]))/y;
  let m;
  let pos = 0;
  while (pos < s.length) {
    re.lastIndex = pos;
    if (/^[ \t]*$/.test(s.slice(pos))) break;
    m = re.exec(s);
    if (!m) throw new Error('lex');
    pos = re.lastIndex;
    if (m[1] !== undefined) out.push({ num: Number(m[1]) });
    else if (m[2] !== undefined) out.push({ id: m[2].toUpperCase() });
    else out.push({ op: m[3] });
  }
  return out;
}
const isRef = (id) => /^[A-Z]{1,3}[1-9]\d{0,5}$/.test(id);
const colNum = (l) => [...l].reduce((n, ch) => n * 26 + ch.charCodeAt(0) - 64, 0);
function parseFormula(src) {
  const t = lex(src);
  let i = 0;
  const op = (o) => t[i] && t[i].op === o;
  function atom() {
    const tok = t[i];
    if (!tok) throw new Error('eof');
    if (tok.op === '-' || tok.op === '+') { i++; return { neg: tok.op, a: climb(3) }; }
    if (tok.num !== undefined) { i++; return { num: tok.num }; }
    if (tok.op === '(') { i++; const e = climb(0); if (!op(')')) throw new Error(')'); i++; return e; }
    if (tok.id !== undefined) {
      i++;
      if (op('(')) {
        i++;
        if (!['SUM', 'MIN', 'MAX', 'ABS'].includes(tok.id)) throw new Error('fn');
        const args = [];
        for (;;) {
          if (t[i] && t[i].id !== undefined && t[i + 1] && t[i + 1].op === ':') {
            const a = t[i].id; const b = t[i + 2] && t[i + 2].id;
            if (!isRef(a) || !b || !isRef(b)) throw new Error('range');
            i += 3;
            args.push({ range: [a, b] });
          } else args.push(climb(0));
          if (op(',')) { i++; continue; }
          if (op(')')) { i++; break; }
          throw new Error('args');
        }
        if (tok.id === 'ABS' && (args.length !== 1 || args[0].range)) throw new Error('abs');
        return { fn: tok.id, args };
      }
      if (!isRef(tok.id)) throw new Error('ref');
      return { ref: tok.id };
    }
    throw new Error('atom');
  }
  // unary minus has precedence 3: below ^ (4) but above * and / (2)
  function climb(minPrec) {
    let left = atom();
    for (;;) {
      const tok = t[i];
      if (!tok || !BIN[tok.op]) break;
      const [prec, assoc] = BIN[tok.op];
      if (prec < minPrec) break;
      i++;
      const right = tok.op === '^' ? climb(3) : climb(assoc === 'L' ? prec + 1 : prec);
      left = { bin: tok.op, a: left, b: right };
    }
    return left;
  }
  const e = climb(0);
  if (i !== t.length) throw new Error('trailing');
  return e;
}

export function sheetBrute(cells) {
  const names = Object.keys(cells);
  const has = (n) => Object.prototype.hasOwnProperty.call(cells, n);
  const parsed = {};
  const rangeList = ([a, b]) => {
    const pa = /^([A-Z]+)(\d+)$/.exec(a);
    const pb = /^([A-Z]+)(\d+)$/.exec(b);
    const c1 = Math.min(colNum(pa[1]), colNum(pb[1])), c2 = Math.max(colNum(pa[1]), colNum(pb[1]));
    const r1 = Math.min(+pa[2], +pb[2]), r2 = Math.max(+pa[2], +pb[2]);
    const out = [];
    for (let r = r1; r <= r2; r++) for (let c = c1; c <= c2; c++) {
      let s = '', n = c;
      while (n > 0) { const m = (n - 1) % 26; s = String.fromCharCode(65 + m) + s; n = (n - 1 - m) / 26; }
      out.push(s + r);
    }
    return out;
  };
  const depsOf = (node, acc = []) => {
    if (!node) return acc;
    if (node.ref && has(node.ref)) acc.push(node.ref);
    if (node.a) depsOf(node.a, acc);
    if (node.b) depsOf(node.b, acc);
    if (node.args) for (const x of node.args) if (x.range) acc.push(...rangeList(x.range).filter(has)); else depsOf(x, acc);
    return acc;
  };
  for (const n of names) {
    const raw = String(cells[n]);
    if (raw[0] === '=') { try { parsed[n] = parseFormula(raw.slice(1)); } catch (e) { parsed[n] = 'PARSE'; } }
  }
  const deps = {};
  for (const n of names) deps[n] = parsed[n] && parsed[n] !== 'PARSE' ? depsOf(parsed[n]) : [];
  const onCycle = {};
  for (const n of names) {
    const seen = new Set();
    const st = [...deps[n]];
    let hit = false;
    while (st.length) { const c = st.pop(); if (c === n) { hit = true; break; } if (seen.has(c)) continue; seen.add(c); st.push(...deps[c]); }
    onCycle[n] = hit;
  }
  const memo = {};
  const E = (code) => ({ err: code });
  const fin = (v) => (Number.isFinite(v) ? v : E('#NUM!'));
  const cell = (n) => {
    if (n in memo) return memo[n];
    let v;
    const raw = String(cells[n]);
    if (onCycle[n]) v = E('#CYCLE!');
    else if (parsed[n] === 'PARSE') v = E('#PARSE!');
    else if (parsed[n]) v = ev(parsed[n]);
    else v = /^\s*[+-]?(\d+(\.\d*)?|\.\d+)\s*$/.test(raw) ? Number(raw.trim()) : raw;
    memo[n] = v;
    return v;
  };
  const ev = (node) => {
    if (node.num !== undefined) return fin(node.num);
    if (node.ref) return has(node.ref) ? cell(node.ref) : E('#REF!');
    if (node.neg) { const a = ev(node.a); if (a && a.err) return a; if (typeof a !== 'number') return E('#VALUE!'); return node.neg === '-' ? -a : a; }
    if (node.bin) {
      const a = ev(node.a); if (a && a.err) return a;
      const b = ev(node.b); if (b && b.err) return b;
      if (typeof a !== 'number' || typeof b !== 'number') return E('#VALUE!');
      if (node.bin === '/' && b === 0) return E('#DIV/0!');
      return fin({ '+': a + b, '-': a - b, '*': a * b, '/': a / b, '^': Math.pow(a, b) }[node.bin]);
    }
    const nums = [];
    for (const x of node.args) {
      if (x.range) {
        for (const c of rangeList(x.range).filter(has)) { const v = cell(c); if (v && v.err) return v; if (typeof v === 'number') nums.push(v); }
      } else { const v = ev(x); if (v && v.err) return v; if (typeof v !== 'number') return E('#VALUE!'); nums.push(v); }
    }
    if (node.fn === 'ABS') return Math.abs(nums[0]);
    if (node.fn === 'SUM') return fin(nums.reduce((s, v) => s + v, 0));
    if (!nums.length) return 0;
    return node.fn === 'MIN' ? Math.min(...nums) : Math.max(...nums);
  };
  const out = {};
  for (const n of names) { const v = cell(n); out[n] = v && v.err ? v.err : v; }
  return out;
}
