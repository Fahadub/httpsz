// WB-ORACLE reference solution — algo-sheet-evaluator
// 1. parse every formula once (recursive descent; nesting is bounded by the contract),
// 2. dependency graph → iterative Tarjan SCC marks cells on cycles,
// 3. iterative post-order evaluation of the remaining DAG (chains of 50 000 cells, no recursion).

const SHEET_ERR = Object.freeze({ PARSE: '#PARSE!', REF: '#REF!', DIV: '#DIV/0!', VALUE: '#VALUE!', NUM: '#NUM!', CYCLE: '#CYCLE!' });
const REF_RE = /^([A-Z]{1,3})([1-9][0-9]{0,5})$/;

function sheetColNumber(letters) {
  let n = 0;
  for (const ch of letters) n = n * 26 + (ch.charCodeAt(0) - 64);
  return n;
}
function sheetColLetters(n) {
  let s = '';
  while (n > 0) {
    const r = (n - 1) % 26;
    s = String.fromCharCode(65 + r) + s;
    n = (n - 1 - r) / 26;
  }
  return s;
}

function sheetTokenize(src) {
  const toks = [];
  let i = 0;
  while (i < src.length) {
    const ch = src[i];
    if (ch === ' ' || ch === '\t') { i++; continue; }
    if ((ch >= '0' && ch <= '9') || ch === '.') {
      const re = /\d+(\.\d*)?|\.\d+/y;
      re.lastIndex = i;
      const m = re.exec(src);
      if (!m) throw new SyntaxError('number');
      toks.push({ t: 'num', v: Number(m[0]) });
      i += m[0].length;
      continue;
    }
    if (/[A-Za-z]/.test(ch)) {
      let j = i;
      while (j < src.length && /[A-Za-z]/.test(src[j])) j++;
      while (j < src.length && /[0-9]/.test(src[j])) j++;
      toks.push({ t: 'id', v: src.slice(i, j).toUpperCase() });
      i = j;
      continue;
    }
    if ('+-*/^(),:'.includes(ch)) { toks.push({ t: ch }); i++; continue; }
    throw new SyntaxError('char');
  }
  return toks;
}

function sheetParse(src) {
  const toks = sheetTokenize(src);
  let p = 0;
  const peek = () => toks[p] && toks[p].t;
  const eat = (t) => { if (peek() !== t) throw new SyntaxError('expected ' + t); return toks[p++]; };
  const ref = () => {
    const tok = eat('id');
    const m = REF_RE.exec(tok.v);
    if (!m) throw new SyntaxError('ref');
    return { k: 'ref', name: tok.v, col: sheetColNumber(m[1]), row: Number(m[2]) };
  };
  function expr() {
    let left = term();
    while (peek() === '+' || peek() === '-') { const op = toks[p++].t; left = { k: 'bin', op, a: left, b: term() }; }
    return left;
  }
  function term() {
    let left = unary();
    while (peek() === '*' || peek() === '/') { const op = toks[p++].t; left = { k: 'bin', op, a: left, b: unary() }; }
    return left;
  }
  function unary() {
    if (peek() === '-' || peek() === '+') { const op = toks[p++].t; return { k: 'neg', op, a: unary() }; }
    return power();
  }
  function power() {
    const base = primary();
    if (peek() === '^') { p++; return { k: 'bin', op: '^', a: base, b: unary() }; }
    return base;
  }
  function primary() {
    const t = peek();
    if (t === 'num') return { k: 'num', v: toks[p++].v };
    if (t === '(') { p++; const e = expr(); eat(')'); return e; }
    if (t === 'id') {
      if (toks[p + 1] && toks[p + 1].t === '(') {
        const name = toks[p].v;
        if (!['SUM', 'MIN', 'MAX', 'ABS'].includes(name)) throw new SyntaxError('function');
        p += 2;
        const args = [arg()];
        while (peek() === ',') { p++; args.push(arg()); }
        eat(')');
        if (name === 'ABS' && (args.length !== 1 || args[0].k === 'range')) throw new SyntaxError('arity');
        return { k: 'fn', name, args };
      }
      return ref();
    }
    throw new SyntaxError('primary');
  }
  function arg() {
    if (peek() === 'id' && toks[p + 1] && toks[p + 1].t === ':') {
      const a = ref();
      p++;
      const b = ref();
      return { k: 'range', c1: Math.min(a.col, b.col), c2: Math.max(a.col, b.col), r1: Math.min(a.row, b.row), r2: Math.max(a.row, b.row) };
    }
    return expr();
  }
  const tree = expr();
  if (p !== toks.length) throw new SyntaxError('trailing');
  return tree;
}

function evaluateSheet(cells) {
  const names = Object.keys(cells);
  const index = new Map(names.map((n, i) => [n, i]));
  const N = names.length;
  const ast = new Array(N).fill(null);
  const value = new Array(N);
  const isErr = (v) => v !== null && typeof v === 'object';
  const err = (code) => ({ e: code });
  const LITERAL_NUM = /^\s*[+-]?(\d+(\.\d*)?|\.\d+)\s*$/;

  const rangeCells = (r, fn) => {
    for (let row = r.r1; row <= r.r2; row++) {
      for (let col = r.c1; col <= r.c2; col++) {
        const i = index.get(sheetColLetters(col) + row);
        if (i !== undefined) fn(i);
      }
    }
  };
  const deps = names.map(() => []);
  const collect = (node, out) => {
    switch (node.k) {
      case 'ref': { const i = index.get(node.name); if (i !== undefined) out.push(i); break; }
      case 'range': rangeCells(node, (i) => out.push(i)); break;
      case 'bin': collect(node.a, out); collect(node.b, out); break;
      case 'neg': collect(node.a, out); break;
      case 'fn': for (const a of node.args) collect(a, out); break;
      default: break;
    }
  };
  for (let i = 0; i < N; i++) {
    const raw = String(cells[names[i]]);
    if (raw.startsWith('=')) {
      try { ast[i] = sheetParse(raw.slice(1)); collect(ast[i], deps[i]); } catch (e) { value[i] = err(SHEET_ERR.PARSE); }
    } else value[i] = LITERAL_NUM.test(raw) ? Number(raw.trim()) : raw;
  }

  // Iterative Tarjan: cells in an SCC of size > 1 or with a self-loop are on a cycle.
  const idx = new Int32Array(N).fill(-1);
  const low = new Int32Array(N);
  const onStack = new Uint8Array(N);
  const stack = [];
  let counter = 0;
  for (let s = 0; s < N; s++) {
    if (idx[s] !== -1) continue;
    const work = [[s, 0]];
    idx[s] = low[s] = counter++; stack.push(s); onStack[s] = 1;
    while (work.length) {
      const frame = work[work.length - 1];
      const v = frame[0];
      if (frame[1] < deps[v].length) {
        const w = deps[v][frame[1]++];
        if (idx[w] === -1) { idx[w] = low[w] = counter++; stack.push(w); onStack[w] = 1; work.push([w, 0]); }
        else if (onStack[w]) low[v] = Math.min(low[v], idx[w]);
      } else {
        work.pop();
        if (work.length) { const u = work[work.length - 1][0]; low[u] = Math.min(low[u], low[v]); }
        if (low[v] === idx[v]) {
          const comp = [];
          let w;
          do { w = stack.pop(); onStack[w] = 0; comp.push(w); } while (w !== v);
          if (comp.length > 1 || deps[v].includes(v)) for (const c of comp) value[c] = err(SHEET_ERR.CYCLE);
        }
      }
    }
  }

  const finite = (x) => (Number.isFinite(x) ? x : err(SHEET_ERR.NUM));
  const evalNode = (node) => {
    switch (node.k) {
      case 'num': return finite(node.v);
      case 'ref': { const i = index.get(node.name); return i === undefined ? err(SHEET_ERR.REF) : value[i]; }
      case 'neg': {
        const a = evalNode(node.a);
        if (isErr(a)) return a;
        if (typeof a !== 'number') return err(SHEET_ERR.VALUE);
        return node.op === '-' ? -a : a;
      }
      case 'bin': {
        const a = evalNode(node.a);
        if (isErr(a)) return a;
        const b = evalNode(node.b);
        if (isErr(b)) return b;
        if (typeof a !== 'number' || typeof b !== 'number') return err(SHEET_ERR.VALUE);
        switch (node.op) {
          case '+': return finite(a + b);
          case '-': return finite(a - b);
          case '*': return finite(a * b);
          case '/': return b === 0 ? err(SHEET_ERR.DIV) : finite(a / b);
          default: return finite(a ** b);
        }
      }
      case 'fn': {
        const nums = [];
        let bad = null;
        for (const a of node.args) {
          if (a.k === 'range') {
            rangeCells(a, (i) => {
              if (bad) return;
              const v = value[i];
              if (isErr(v)) bad = v; else if (typeof v === 'number') nums.push(v);
            });
          } else {
            const v = evalNode(a);
            if (isErr(v)) bad = v; else if (typeof v !== 'number') bad = err(SHEET_ERR.VALUE); else nums.push(v);
          }
          if (bad) return bad;
        }
        if (node.name === 'ABS') return Math.abs(nums[0]);
        if (node.name === 'SUM') { let s = 0; for (const x of nums) s += x; return finite(s); }
        if (nums.length === 0) return 0;
        let r = nums[0];
        for (const x of nums) r = node.name === 'MIN' ? Math.min(r, x) : Math.max(r, x);
        return r;
      }
      default: return err(SHEET_ERR.PARSE);
    }
  };

  // Iterative post-order over the acyclic part: dependencies are evaluated first.
  const done = new Uint8Array(N);
  for (let i = 0; i < N; i++) if (value[i] !== undefined) done[i] = 1;
  for (let s = 0; s < N; s++) {
    if (done[s]) continue;
    const work = [[s, 0]];
    while (work.length) {
      const frame = work[work.length - 1];
      const v = frame[0];
      if (frame[1] < deps[v].length) {
        const w = deps[v][frame[1]++];
        if (!done[w]) work.push([w, 0]);
      } else {
        work.pop();
        if (!done[v]) { value[v] = evalNode(ast[v]); done[v] = 1; }
      }
    }
  }

  const out = {};
  for (let i = 0; i < N; i++) out[names[i]] = isErr(value[i]) ? value[i].e : value[i];
  return out;
}
