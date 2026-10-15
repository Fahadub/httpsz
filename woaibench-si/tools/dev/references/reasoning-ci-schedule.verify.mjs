// DEV ONLY — ground truth for reasoning-ci-schedule, computed two independent ways:
//  (1) every precedence-feasible job order × every runner assignment, semi-active timing;
//  (2) exhaustive event-driven search (start any subset of ready jobs at t = 0 or a completion time).
const JOBS = {
  A: { d: 1, pre: [] }, B: { d: 2, pre: ['A'] }, C: { d: 5, pre: [] }, D: { d: 5, pre: ['A', 'B'] },
  E: { d: 3, pre: ['A', 'C'] }, F: { d: 6, pre: [] }, G: { d: 2, pre: ['C', 'D'] }, H: { d: 1, pre: ['A', 'F'] },
  I: { d: 4, pre: ['F', 'G'] },
};
const EXCLUSIVE = [['D', 'F']];
const names = Object.keys(JOBS);
const n = names.length;

function method1() {
  let best = Infinity;
  const order = [];
  const used = new Set();
  const rec = () => {
    if (order.length === n) {
      for (let mask = 0; mask < 1 << n; mask++) {
        const free = [0, 0];
        const end = {};
        for (let i = 0; i < n; i++) {
          const x = order[i];
          const r = (mask >> i) & 1;
          let s = free[r];
          for (const p of JOBS[x].pre) s = Math.max(s, end[p]);
          for (const [u, v] of EXCLUSIVE) { const o = x === u ? v : x === v ? u : null; if (o && end[o] !== undefined) s = Math.max(s, end[o]); }
          end[x] = s + JOBS[x].d;
          free[r] = end[x];
        }
        best = Math.min(best, Math.max(...Object.values(end)));
      }
      return;
    }
    for (const x of names) {
      if (used.has(x) || !JOBS[x].pre.every((p) => used.has(p))) continue;
      used.add(x); order.push(x); rec(); order.pop(); used.delete(x);
    }
  };
  rec();
  return best;
}

function method2() {
  const dur = names.map((x) => JOBS[x].d);
  const pre = names.map((x) => JOBS[x].pre.map((p) => names.indexOf(p)));
  const ex = EXCLUSIVE.map(([u, v]) => [names.indexOf(u), names.indexOf(v)]);
  let best = Infinity;
  const search = (t, done, running) => {
    if (t >= best) return;
    if (done === (1 << n) - 1) { best = t; return; }
    const started = running.reduce((m, [j]) => m | (1 << j), done);
    const ready = [];
    for (let j = 0; j < n; j++) if (!((started >> j) & 1) && pre[j].every((q) => (done >> q) & 1)) ready.push(j);
    const subsets = [[]];
    for (const j of ready) for (const s of [...subsets]) if (s.length < 2 - running.length) subsets.push([...s, j]);
    for (const s of subsets) {
      const live = [...running.map((r) => r[0]), ...s];
      if (ex.some(([u, v]) => live.includes(u) && live.includes(v))) continue;
      const next = [...running, ...s.map((j) => [j, t + dur[j]])];
      if (!next.length) continue;
      const tn = Math.min(...next.map((r) => r[1]));
      let d2 = done;
      const still = [];
      for (const r of next) if (r[1] === tn) d2 |= 1 << r[0]; else still.push(r);
      search(tn, d2, still);
    }
  };
  search(0, 0, []);
  return best;
}

const a = method1();
const b = method2();
if (a !== b) throw new Error(`methods disagree: ${a} vs ${b}`);
console.log(String(a));
