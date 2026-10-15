// DEV ONLY — ground truth for reasoning-judge-assignment: enumerates all 1680 distributions of the nine
// distinct submissions (vendors AAAA BBB CC) to judges A, B, C (three each) and also checks the
// closed-form count 52 = C(4,1)C(3,3)C(2,0) + C(4,2)C(3,2)C(2,1) + C(4,3)C(3,1)C(2,2).
const vendor = ['A', 'A', 'A', 'A', 'B', 'B', 'B', 'C', 'C'];
const judge = ['A', 'B', 'C'];
let condition = 0;
let favourable = 0;
const to = new Array(9);
(function rec(i, load) {
  if (i === 9) {
    const cClean = !to.some((j, k) => j === 2 && vendor[k] === 'C');
    const noneOwn = !to.some((j, k) => judge[j] === vendor[k]);
    if (cClean) condition++;
    if (cClean && noneOwn) favourable++;
    return;
  }
  for (let j = 0; j < 3; j++) if (load[j] < 3) { load[j]++; to[i] = j; rec(i + 1, load); load[j]--; }
})(0, [0, 0, 0]);
const C = (n, k) => (k < 0 || k > n ? 0 : k === 0 ? 1 : (C(n - 1, k - 1) * n) / k);
const closed = [0, 1, 2].reduce((s, z) => s + C(4, 1 + z) * C(3, 3 - z) * C(2, z), 0);
if (closed !== favourable || condition !== C(7, 3) * 20) throw new Error('enumeration and closed form disagree');
const g = (a, b) => (b ? g(b, a % b) : a);
const d = g(favourable, condition);
console.log(`${favourable / d}/${condition / d}`);
