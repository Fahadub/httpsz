// WB-ORACLE reference solution — algo-studio-allocator
// Rooms are kept in (capacity, index) order inside a segment tree of "free" flags, so the best-fitting
// free room is the first free position at or after lowerBound(size). Busy rooms sit in a min-heap keyed
// by the time they become available again. O((n + m) log(n + m)).

function allocateStudios(capacities, bookings, turnaround) {
  const m = capacities.length;
  const n = bookings.length;
  const result = new Array(n).fill(-1);
  if (n === 0 || m === 0) return result;

  const rooms = Array.from({ length: m }, (_, i) => i).sort((a, b) => capacities[a] - capacities[b] || a - b);
  const sortedCap = rooms.map((r) => capacities[r]);

  let size = 1;
  while (size < m) size *= 2;
  const free = new Int32Array(2 * size);
  for (let p = 0; p < m; p++) free[size + p] = 1;
  for (let i = size - 1; i >= 1; i--) free[i] = free[2 * i] + free[2 * i + 1];
  const setFree = (p, v) => {
    let i = size + p;
    free[i] = v;
    for (i = i >> 1; i >= 1; i >>= 1) free[i] = free[2 * i] + free[2 * i + 1];
  };
  const firstFreeFrom = (lo, node = 1, nl = 0, nr = size - 1) => {
    if (nr < lo || free[node] === 0) return -1;
    if (nl === nr) return nl;
    const mid = (nl + nr) >> 1;
    const left = firstFreeFrom(lo, 2 * node, nl, mid);
    return left !== -1 ? left : firstFreeFrom(lo, 2 * node + 1, mid + 1, nr);
  };
  const lowerBound = (x) => {
    let lo = 0;
    let hi = m;
    while (lo < hi) {
      const mid = (lo + hi) >> 1;
      if (sortedCap[mid] < x) lo = mid + 1; else hi = mid;
    }
    return lo;
  };

  // Min-heap of [availableAt, position].
  const heapT = [];
  const heapP = [];
  const push = (t, p) => {
    let i = heapT.length;
    heapT.push(t); heapP.push(p);
    while (i > 0) {
      const parent = (i - 1) >> 1;
      if (heapT[parent] <= t) break;
      heapT[i] = heapT[parent]; heapP[i] = heapP[parent];
      i = parent;
    }
    heapT[i] = t; heapP[i] = p;
  };
  const pop = () => {
    const p = heapP[0];
    const lastT = heapT.pop();
    const lastP = heapP.pop();
    if (heapT.length > 0) {
      let i = 0;
      const len = heapT.length;
      for (;;) {
        let c = 2 * i + 1;
        if (c >= len) break;
        if (c + 1 < len && heapT[c + 1] < heapT[c]) c++;
        if (heapT[c] >= lastT) break;
        heapT[i] = heapT[c]; heapP[i] = heapP[c];
        i = c;
      }
      heapT[i] = lastT; heapP[i] = lastP;
    }
    return p;
  };

  const order = Array.from({ length: n }, (_, j) => j).sort((a, b) => bookings[a].start - bookings[b].start || a - b);
  for (const j of order) {
    const { start, end, size: need } = bookings[j];
    while (heapT.length > 0 && heapT[0] <= start) setFree(pop(), 1);
    const p = firstFreeFrom(lowerBound(need));
    if (p === -1) continue;
    result[j] = rooms[p];
    setFree(p, 0);
    push(end + turnaround, p);
  }
  return result;
}
