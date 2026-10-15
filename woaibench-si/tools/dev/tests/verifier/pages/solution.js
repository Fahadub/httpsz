// Fixture solution for the verifier harness tests (sorting). Deliberately broken on two inputs:
// [42] loops forever (worker must be terminated) and [7, 7] returns a wrong answer.
function solve(xs) {
  if (xs.length === 1 && xs[0] === 42) { for (;;) { /* spin */ } }
  if (xs.length === 2 && xs[0] === 7 && xs[1] === 7) { return [7]; }
  console.log('sorting', xs.length); // must be silenced by the harness
  return xs.slice().sort((a, b) => a - b);
}

class Counter {
  constructor() { this.n = 0; }
  inc() { this.n += 1; return this.n; }
}
