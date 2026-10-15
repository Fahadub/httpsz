// MUTANT: textbook O(N·M) LCS table — correct, must fail the large-file performance cases.
// mutant of the reference solution — algo-line-diff
// Common prefix/suffix are trimmed, then Myers' O((N+M)·D) greedy algorithm finds a shortest edit
// script (only the [-d, d] band of V is stored per step, so memory is O(D²)). Changes inside each hunk
// are re-ordered so that deletions precede additions.

function lineDiff(oldText, newText) {
  const a = oldText === '' ? [] : oldText.split('\n');
  const b = newText === '' ? [] : newText.split('\n');
  let pre = 0;
  while (pre < a.length && pre < b.length && a[pre] === b[pre]) pre++;
  let suf = 0;
  while (suf < a.length - pre && suf < b.length - pre && a[a.length - 1 - suf] === b[b.length - 1 - suf]) suf++;
  const A = a.slice(pre, a.length - suf);
  const B = b.slice(pre, b.length - suf);

  const N = A.length, M = B.length;
  const dp = Array.from({ length: N + 1 }, () => new Int32Array(M + 1));
  for (let i = N - 1; i >= 0; i--) for (let j = M - 1; j >= 0; j--) dp[i][j] = A[i] === B[j] ? dp[i + 1][j + 1] + 1 : Math.max(dp[i + 1][j], dp[i][j + 1]);
  const middle = [];
  let i = 0, j = 0;
  while (i < N && j < M) {
    if (A[i] === B[j]) { middle.push(['=', A[i]]); i++; j++; } else if (dp[i + 1][j] >= dp[i][j + 1]) middle.push(['-', A[i++]]); else middle.push(['+', B[j++]]);
  }
  while (i < N) middle.push(['-', A[i++]]);
  while (j < M) middle.push(['+', B[j++]]);
  const ops = [];
  for (let i = 0; i < pre; i++) ops.push(['=', a[i]]);
  for (const op of middle) ops.push(op);
  for (let i = a.length - suf; i < a.length; i++) ops.push(['=', a[i]]);
  return canonicalHunks(ops);
}

function myersOps(A, B) {
  const N = A.length;
  const M = B.length;
  if (N === 0) return B.map((l) => ['+', l]);
  if (M === 0) return A.map((l) => ['-', l]);
  // Intern lines so the snake loop compares integers.
  const ids = new Map();
  const id = (s) => { let v = ids.get(s); if (v === undefined) { v = ids.size; ids.set(s, v); } return v; };
  const a = Int32Array.from(A, id);
  const b = Int32Array.from(B, id);
  const MAX = N + M;
  const V = new Int32Array(2 * MAX + 3);
  const off = MAX + 1;
  const trace = [];
  let found = -1;
  for (let d = 0; d <= MAX && found < 0; d++) {
    trace.push(V.slice(off - d - 1, off + d + 2)); // V[k] for k in [-d-1, d+1] before step d
    for (let k = -d; k <= d; k += 2) {
      let x = (k === -d || (k !== d && V[off + k - 1] < V[off + k + 1])) ? V[off + k + 1] : V[off + k - 1] + 1;
      let y = x - k;
      while (x < N && y < M && a[x] === b[y]) { x++; y++; }
      V[off + k] = x;
      if (x >= N && y >= M) { found = d; break; }
    }
  }
  const out = [];
  let x = N;
  let y = M;
  for (let d = found; d > 0; d--) {
    const Vd = trace[d];
    const at = (k) => Vd[k + d + 1];
    const k = x - y;
    const prevK = (k === -d || (k !== d && at(k - 1) < at(k + 1))) ? k + 1 : k - 1;
    const prevX = at(prevK);
    const prevY = prevX - prevK;
    while (x > prevX && y > prevY) { out.push(['=', A[x - 1]]); x--; y--; }
    if (x === prevX) out.push(['+', B[prevY]]); else out.push(['-', A[prevX]]);
    x = prevX;
    y = prevY;
  }
  while (x > 0 && y > 0) { out.push(['=', A[x - 1]]); x--; y--; }
  return out.reverse();
}

function canonicalHunks(ops) {
  const out = [];
  let dels = [];
  let adds = [];
  const flush = () => { out.push(...dels, ...adds); dels = []; adds = []; };
  for (const op of ops) {
    if (op[0] === '=') { flush(); out.push(op); } else if (op[0] === '-') dels.push(op); else adds.push(op);
  }
  flush();
  return out;
}
