// MUTANT: correct, but selects the next station by scanning all stations (O(n²)) — must fail the performance cases.
// mutant of the reference solution — algo-transit-windows
// Time-dependent Dijkstra. Waiting is allowed and departure windows are closed intervals, so the
// earliest departure from a link is monotone in the arrival time (FIFO) and label-setting is exact.
// Each link's windows are sorted and merged once; the earliest usable departure is a binary search.

function earliestArrival(n, links, source, target, startTime) {
  if (source === target) return startTime;
  const L = links.length;
  const head = new Int32Array(n).fill(-1);
  const nxt = new Int32Array(L);
  const wStart = new Int32Array(L + 1);
  const opens = [];
  const closes = [];
  for (let e = 0; e < L; e++) {
    const { from, windows } = links[e];
    nxt[e] = head[from];
    head[from] = e;
    wStart[e] = opens.length;
    const ws = windows.map((w) => [w[0], w[1]]).sort((a, b) => a[0] - b[0]);
    for (const [o, c] of ws) {
      const last = opens.length - 1;
      if (last >= wStart[e] && o <= closes[last]) closes[last] = Math.max(closes[last], c);
      else { opens.push(o); closes.push(c); }
    }
  }
  wStart[L] = opens.length;

  const departAt = (e, t) => {
    let lo = wStart[e];
    let hi = wStart[e + 1];
    while (lo < hi) {
      const mid = (lo + hi) >> 1;
      if (closes[mid] < t) lo = mid + 1; else hi = mid;
    }
    return lo === wStart[e + 1] ? Infinity : Math.max(opens[lo], t);
  };

  const best = new Float64Array(n).fill(Infinity);
  best[source] = startTime;
  const hT = [startTime];
  const hV = [source];
  const push = (t, v) => {
    let i = hT.length;
    hT.push(t); hV.push(v);
    while (i > 0) {
      const p = (i - 1) >> 1;
      if (hT[p] <= t) break;
      hT[i] = hT[p]; hV[i] = hV[p]; i = p;
    }
    hT[i] = t; hV[i] = v;
  };
  const pop = () => {
    const t = hT[0];
    const v = hV[0];
    const lt = hT.pop();
    const lv = hV.pop();
    if (hT.length) {
      let i = 0;
      for (;;) {
        let c = 2 * i + 1;
        if (c >= hT.length) break;
        if (c + 1 < hT.length && hT[c + 1] < hT[c]) c++;
        if (hT[c] >= lt) break;
        hT[i] = hT[c]; hV[i] = hV[c]; i = c;
      }
      hT[i] = lt; hV[i] = lv;
    }
    return [t, v];
  };

  const settled = new Uint8Array(n);
  for (;;) {
    let u = -1;
    for (let i = 0; i < n; i++) if (!settled[i] && best[i] !== Infinity && (u === -1 || best[i] < best[u])) u = i;
    if (u === -1) break;
    settled[u] = 1;
    const t = best[u];
    if (u === target) return t;
    for (let e = head[u]; e !== -1; e = nxt[e]) {
      const d = departAt(e, t);
      if (d === Infinity) continue;
      const arr = d + links[e].duration;
      const v = links[e].to;
      if (arr < best[v]) { best[v] = arr; push(arr, v); }
    }
  }
  return -1;
}
