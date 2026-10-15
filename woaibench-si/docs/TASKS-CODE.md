# Deterministic code & reasoning tasks (module `tasks-code`)

20 original tasks for the four deterministic tracks — **algo**, **debug**, **secure** (output type `js`,
graded by hidden unit tests in the browser harness) and **reasoning** (output type `answer`, graded by
machine-checked answers). None of them is copied or paraphrased from LeetCode, HumanEval, Advent of
Code, etc.: each scenario, contract and test suite was written for WOAIBench.si.

```
sql/seed/tasks/{algo,debug,secure,reasoning}.json   generated task definitions (ARCHITECTURE §5.1 format)
tools/dev/references/<slug>.js                       WB-ORACLE reference solution (js tasks)
tools/dev/references/<slug>.answer.txt               reference answer (reasoning tasks)
tools/dev/references/<slug>.verify.mjs               independent computation of that answer
tools/dev/references/mutants/<slug>.<name>.js        deliberately wrong solutions (must fail ≥ 1 case)
tools/dev/references/mutants/<slug>.wrong.txt        plausible wrong answers (must be rejected)
tools/dev/references/mutants/<slug>.right.txt        formatting variants of the right answer (must be accepted)
tools/dev/references/build/<track>.mjs               builders: prompts + rubric + checks + test cases
tools/dev/references/build/oracles-algo.mjs          slow brute-force oracles used for cross-checking
tools/dev/references/build/mkmutant.py               derives a mutant from a reference by exact replacements
tools/dev/validate-tasks.mjs                         shared validator (any track, any js/answer task)
```

## Rebuild and validate

```bash
node tools/dev/references/build/algo.mjs        # likewise debug / secure / reasoning
node tools/dev/validate-tasks.mjs algo debug secure reasoning          # full run, ~5 min
node tools/dev/validate-tasks.mjs secure --task=secure-vfs-resolve --verbose
node tools/dev/validate-tasks.mjs arabic --no-mutants                   # other modules can reuse it
```

The JSON files are **generated** — edit the builder, not the JSON. A builder fails loudly when a
hand-written expectation disagrees with the reference, when a reference disagrees with its brute-force
oracle on random inputs, or when a result is not JSON-stable.

The validator checks, per task: required fields, kebab-case unique slugs, Arabic text in `*_ar`,
known check / cmp / answer types, unique check ids and case names, 15–40 cases, the entry name
appearing in the prompt, unpaired UTF-16 surrogates (PHP `json_decode` and MySQL reject them) and
that `php` can decode every seed file. It then runs the reference (must pass 100 % and use < 25 % of
`timeout_ms` on every case), re-runs the references under `America/Los_Angeles`, `Asia/Kolkata` and
`Pacific/Kiritimati`, and runs every mutant (each must fail at least one case). For answer tasks it
uses a JS re-implementation of AnswerCheck **and** the real `app/eval/AnswerCheck.php` (through the
app bootstrap) when it exists.

## Harness semantics the tests rely on

These match `assets/js/harness.js` (module verifier), which documents the same contract:

* **entry mode** — `args` are JSON-cloned, the code is evaluated and `entry(...args)` is called. The
  prompt of every js task requires a top-level `function <entry>(…)` and forbids `import`/`export`/
  `require`, DOM and Node APIs.
* **script mode** — `code + "\n;(function(){ <script> })()"`; the script uses the model's functions and
  returns a value. Scripts that test async code return a promise; the harness awaits thenables
  within the case timeout (`debug-async-pool` depends on this).
* **comparisons** — `deep` (structural; NaN equals NaN; −0 equals 0; object keys whose value is
  `undefined` are ignored), `approx` (absolute `tol`, recursive), `set`/`sorted` (order-insensitive
  multiset at the top level), `truthy`.
* **JSON-stable results only** — every expected value is produced by running the reference and must
  survive `JSON.parse(JSON.stringify(x))` unchanged (no `undefined`, `NaN`, `-0`, `Map`…). Builders
  enforce this, so the result is the same whether a harness compares raw values or JSON.
* **no lone surrogates in JSON** — malformed UTF-16 inputs are built inside scripts with `\uD800`
  escapes.
* **empty objects survive** — `{}` in `args` / `expect` reaches the browser as `{}` (the render layer
  decodes the test suite with JSON objects preserved; PHP's associative decoding would turn it into `[]`).
  `algo-sheet-evaluator`, `debug-invoice-totals` and `secure-settings-patch` rely on this.
* Isolation is the same in both places: the validator gives each case a fresh `vm` context and the
  browser harness a fresh Worker per case (after a Worker had to be terminated the next case waits
  250 ms; an out-of-memory case is re-run once). Polluting globals therefore never leaks into the
  next case.
* Large inputs are generated inside scripts with an inline LCG (`Math.imul` + `>>> 0`), identical
  in every engine, so the JSON stays small (12–240 KB per pretty-printed seed file).

## Track design

**Rubrics** (judged part; tests already measure correctness): algo — algorithmic efficiency,
robustness, readability, idiomatic JS; debug — root-cause diagnosis, robustness, focused change,
readability; secure — security reasoning (weight 4), robustness, readability, idiomatic JS.
`auto_weight` is `null` for js tasks (track default 0.85 / 0.85 / 0.70) and `1` for reasoning
(no rubric). Static checks: `js_parses` (weight 1) everywhere; secure adds `not_contains` for
`eval(`, `Function(` (and `innerHTML` / `with(` where relevant). Tests weigh 9 (algo, debug) or 10
(secure) against those static points.

**Performance cases** are sized so that the reference needs ≤ ~15 % of the timeout on the build
machine, while the naive solution (also kept as a mutant) needs orders of magnitude more
(e.g. 10¹⁰ steps). Timeouts: 4–5 s for heavy algo suites, 2–3 s elsewhere.

**Real-browser check.** Besides the Node validator, every reference and mutant was run through the
production `assets/js/harness.js` in Chromium (Blob Worker mode, one fresh browser context per
solution): all 15 references pass 100 % (slowest case ≈ 0.6 s), every mutant fails at least one case.
The redirect suite passing there also confirms Chromium's URL parser agrees with Node's on every case.

### algo (5)

| slug | diff | cases | timeout | what it measures | mutants (all killed) |
|---|---|---|---|---|---|
| `algo-studio-allocator` | 3 | 29 | 4000 | best-fit room assignment with turnaround; segment tree over (capacity, index) + min-heap; tie rules; 100 k adversarial inputs | naive O(n·m) scan, strict boundary, no turnaround, first-fit, input order |
| `algo-sheet-evaluator` | 4 | 34 | 5000 | recursive-descent parser with exact precedence (−2^2 = −4, right-assoc ^), ranges, six error codes with left-to-right propagation, iterative Tarjan SCC, 50 000-cell chains without stack overflow | Excel precedence, recursive evaluation, cycle taints dependents, missing range cells = #REF! |
| `algo-transit-windows` | 3 | 27 | 4000 | time-dependent Dijkstra with waiting and inclusive departure windows (unsorted, overlapping), times > 2³² | no waiting, ignores window close, assumes sorted windows, O(n²) Dijkstra |
| `algo-line-diff` | 4 | 28 | 4000 | minimal line diff (Myers O(ND)), canonical hunks (− before +), exact split rules; 50 000-line files; checked by a property checker, so any minimal diff passes | adds before deletes, drops empty lines, greedy (non-minimal), O(N·M) LCS table |
| `algo-rank-agreement` | 3 | 24 | 4000 | Kendall τ-b with all tie classes, exact pair counts > 2³¹, n = 200 000 (Knight's O(n log n)) | τ-a, 32-bit pair counts, sort by x only, O(n²) |

Cross-checks before writing: 3 000 random studio inputs vs brute force, 6 000 random spreadsheets vs an
independent precedence-climbing evaluator, 6 000 random networks vs Bellman–Ford, 4 000 random text
pairs vs an LCS dynamic programme, 3 000 random score lists vs the O(n²) definition, plus the perf
generators at reduced size against the same oracles.

### debug (5)

Each prompt shows production code with a correct doc comment, a wrong implementation and
user-style bug reports (every symptom in a report was reproduced against the shown code). For every
injected bug there is a mutant that fixes **all other bugs**, so each bug has its own regression
test; common incomplete fixes are mutants too.

| slug | diff | cases | injected bugs | extra mutants |
|---|---|---|---|---|
| `debug-invoice-totals` | 3 | 28 | in-place sort of the caller's array · `localeCompare` instead of code-unit order · binary floating-point rounding (half-cent products, negative halves) · float `gross` | `Number.EPSILON` hack, `toFixed` rounding, half-up negatives |
| `debug-preview-truncate` | 2 | 27 | UTF-16 units instead of code points · off-by-one (no room for "…") · word-boundary rule (latent: only visible once the off-by-one is fixed) · `trim()` eats indentation | — |
| `debug-renewal-dates` | 3 | 24 | 0-based month slip · month-end overflow · day drift after a clamp · no validation (`months: 0` loops forever) | — |
| `debug-async-pool` | 3 | 19 | `<=` limit · completion-order results · empty input never settles · keeps launching after failure / crashes on plain values / accepts `limit: 0` | Promise.all chunking (slots not refilled) |
| `debug-csv-parse` | 2 | 26 | split on `\n` before quotes · `""` escape · trailing empty field dropped · blank records dropped · CR left by CRLF | — |

Async tests drive jobs with explicit deferreds and `setTimeout(0)` ticks (no timing races); one
timer-based case uses delays ≥ 1 ms apart only to check ordering, not timing.

### secure (5)

| slug | diff | cases | attack surface | mutants |
|---|---|---|---|---|
| `secure-context-encoder` | 3 | 22 | html / attr / js-string / url contexts; XSS breakouts (`</script>`, quote styles, backslash-quote, U+2028, `${}`), lone surrogates, non-text values, unknown contexts. html/attr/js are checked by decoders (any correct encoding passes); url output is exact RFC 3986 | no `&` escaping, raw `'` in attributes, `JSON.stringify` for js, `encodeURIComponent`, default-context fallback |
| `secure-redirect-guard` | 3 | 30 | open redirects parsed like the browser: protocol-relative, backslash, tab/newline stripping, userinfo `@` tricks, look-alike / IDN / fullwidth hosts, IP literals, schemes incl. `blob:`, ports, `https:evil.test`, same-origin `//` paths | suffix match without dot, userinfo allowed, protocol-relative result, regex fast path, `url.origin` for `blob:` |
| `secure-vfs-resolve` | 4 | 24 | percent-decode once, encoded / double-encoded traversal, overlong UTF-8, control chars, backslashes, hidden files (lexical and real path), symlinks escaping root, `/srv/public-old` prefix confusion, physical `..` in link targets, loops, 40-hop limit | prefix confusion, lexical-only containment, clamping `..`, double decoding, hidden check only on the request, no hop limit |
| `secure-template-render` | 3 | 26 | HTML escaping of all five characters, script-safe `json` filter, own-property lookups only (`__proto__`, `constructor`, getters), no re-rendering of data, inherited filter names, linear time on 100 k unclosed `{{` | no `'` escaping, `in` lookups, re-render loop, lazy-regex (quadratic), unsafe json, filter object lookup |
| `secure-settings-patch` | 3 | 26 | schema-validated deep merge: `__proto__` / `constructor` / `prototype` keys, inherited key names, non-plain objects, no input mutation or shared references, all errors sorted | no forbidden-key check, `in` schema lookup, mutates `current`, first error only, floats accepted as integers, class instances accepted |

Redirect expectations come from the WHATWG `URL` parser (Node 22 = same algorithm as Chromium);
cases avoid characters whose serialisation differs between engines.

### reasoning (5)

All answers are produced by `<slug>.verify.mjs` (brute force or two independent methods) and are
accepted by the real `AnswerCheck.php`; the wrong-answer lists hold the most likely mistakes.

| slug | diff | answer spec | answer |
|---|---|---|---|
| `reasoning-elevator-trace` | 3 | exact | `3>0>7>9>10>12 t=36 end=11 \| 0,10,12,3,7,9` |
| `reasoning-judge-assignment` | 3 | exact | `13/175` |
| `reasoning-ci-schedule` | 4 | number ± 0 | `17` |
| `reasoning-flaky-reruns` | 4 | number ± 0.05 | `34.88` (exact 2859481756726972261/81969540710400000 ≈ 34.8847) |
| `reasoning-die-path` | 3 | regex `^\s*48\s*,\s*5\s*,\s*3\s*$` | `48,5,3` |

How each answer was derived:

* **Elevator trace** — the verify script executes the exact program text from the prompt in a fresh
  VM and captures `console.log`. Hand trace: from floor 5 going down it serves 3 (t = 5) and 0
  (t = 11); with nothing below it turns, climbs and serves 7 (t = 21), 9 (t = 26), 10 (t = 30) and
  12 (t = 35). The loop body still finishes after the last request: `ahead` is false on an empty set,
  so the direction flips and the car moves once more → `t=36 end=11`. The labels are strings, so the
  default sort gives `0,10,12,3,7,9`. Traps: the duplicate 3 (Set), the extra iteration, string sort.
* **Judge assignment** — enumeration of all 1 680 = 9!/(3!·3!·3!) splits. Conditioning on "judge C
  has no C submission" leaves C(7,3)·6!/(3!·3!) = 35·20 = 700 splits. No conflicts at all: with z
  C-submissions going to judge A, judge A gets 3 − z B-submissions and judge B gets 1 + z
  A-submissions, so the count is Σ_z C(4,1+z)·C(3,3−z)·C(2,z) = 4 + 36 + 12 = 52. 52/700 = 13/175
  (the unconditional probability, 13/420, is a listed wrong answer).
* **CI schedule** — exhaustive search two ways (every precedence-feasible order × every runner
  assignment with semi-active timing; and an event-driven search over all subsets of ready jobs),
  both give 17. Lower bound: if F runs before D, D starts ≥ 6 → G ends ≥ 13 → I ends ≥ 17; if D runs
  first it ends ≥ 8, F ends ≥ 14 → I ends ≥ 18. Optimal schedule: runner 1 `F 0–6, D 6–11, H 11–12,
  I 13–17`; runner 2 `A 0–1, B 1–3, C 3–8, E 8–11, G 11–13`. Greedy list scheduling gives 18–19;
  critical path (14) and work bound (15) are traps.
* **Flaky reruns** — the "double dixie cup" expectation for n = 8, m = 2: exact rational DP over
  states (#tests seen 0 times, #seen once): E(z₀, z₁) = (n + z₀·E(z₀−1, z₁+1) + z₁·E(z₀, z₁−1)) / (z₀ + z₁),
  cross-checked with E = n∫₀^∞ [1 − (1 − e^{−t}(1 + t))^n] dt and a 200 000-run simulation. The
  tolerance ±0.05 accepts correct rounding, rejects the leading-order asymptotic estimate n(ln n + ln ln n + γ) ≈ 27.1 and round guesses such as 35.
* **Die path** — rolls simulated with face-normal vectors and rotation matrices (independent of the
  face-swap tables used while designing the puzzle). Tops after each roll: 5 6 4 5 1 2 4 1 3 2 1 3 6 5
  (sum 48); final top 5, north 3.

## Authoring rules learned the hard way

1. Never put `undefined`, `NaN`, `-0`, `Map`/`Set` or class instances in an expected value; return
   JSON data from scripts.
2. Never put unpaired surrogates in JSON strings (PHP seeding fails for the whole file).
3. Verify every claim in a debug bug report against the shown code (`3 × 1.115` does **not** misround
   in floating point; `3 × 0.075` does).
4. One mutant per injected bug that fixes everything else; add the common "almost" fixes.
5. A hidden test may only check behaviour the prompt specifies precisely (tie-breaks, error codes,
   output format, limits). Where several outputs are correct (diffs, encoders) test properties.
6. Leave ≥ 4× headroom between the reference and the timeout; make the naive variant ≥ 100× slower.
7. Raw U+2028/U+2029 characters inside a JS regex literal break the file — always write ` `.
