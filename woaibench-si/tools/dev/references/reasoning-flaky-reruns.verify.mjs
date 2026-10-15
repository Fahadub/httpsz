// DEV ONLY — ground truth for reasoning-flaky-reruns ("double dixie cup", n = 8, every test twice).
// Exact rational expectation by dynamic programming over (#tests seen 0 times, #seen once), cross-checked
// with the integral formula E = n ∫0^∞ [1 − (1 − e^{−t}(1 + t))^n] dt. Prints the value to 2 decimals.
const N = 8;
const gcd = (a, b) => { a = a < 0n ? -a : a; while (b) [a, b] = [b, a % b]; return a; };
const frac = (p, q) => { const g = gcd(p, q); return [p / g, q / g]; };
const add = (x, y) => frac(x[0] * y[1] + y[0] * x[1], x[1] * y[1]);
const scale = (k, x) => frac(BigInt(k) * x[0], x[1]);
const E = new Map();
const states = [];
for (let z0 = 0; z0 <= N; z0++) for (let z1 = 0; z0 + z1 <= N; z1++) states.push([z0, z1]);
states.sort((a, b) => 2 * a[0] + a[1] - (2 * b[0] + b[1]));
for (const [z0, z1] of states) {
  if (z0 === 0 && z1 === 0) { E.set('0,0', [0n, 1n]); continue; }
  let acc = [BigInt(N), 1n];
  if (z0 > 0) acc = add(acc, scale(z0, E.get(`${z0 - 1},${z1 + 1}`)));
  if (z1 > 0) acc = add(acc, scale(z1, E.get(`${z0},${z1 - 1}`)));
  E.set(`${z0},${z1}`, frac(acc[0], acc[1] * BigInt(z0 + z1)));
}
const [p, q] = E.get(`${N},0`);
const exact = Number((p * 10n ** 15n) / q) / 1e15;
let integral = 0;
const h = 2e-5;
for (let t = h / 2; t < 60; t += h) integral += (1 - Math.pow(1 - Math.exp(-t) * (1 + t), N)) * h;
if (Math.abs(N * integral - exact) > 1e-6) throw new Error('DP and integral disagree');
console.error(`exact = ${p}/${q} ≈ ${exact}`);
console.log(exact.toFixed(2));
