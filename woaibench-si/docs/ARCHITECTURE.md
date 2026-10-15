# WOAIBench.si — Architecture & Module Contracts

> This file is the single source of truth every module is built against. If you change a contract,
> update this file in the same change.

## 0. Product in one paragraph

WOAIBench.si ("**SI = Scientific Index**") is a bilingual (Arabic RTL / English) benchmark site that
ranks AI models on practical coding work. It competes with woaibench.ai by being **more accurate and
more transparent**:

| Accuracy / trust feature | How |
|---|---|
| Repeated attempts | every model × task is run N times (default 3) → mean and variance |
| 95% confidence intervals | stratified bootstrap over tasks and attempts (1000 resamples), small-sample corrected (§5.3) |
| Statistical rank | rank = 1 + number of models whose CI lower bound is above this model's CI upper bound |
| Multi-vendor judge panel | 3 judges from **different vendors**, median score, judges never grade their own vendor |
| Adaptive tie-break | if judge spread > 25 points an extra judge is added automatically |
| Blind judging | judges never see the model's name |
| Criterion-level rubrics | judges score 4–6 weighted criteria (0–10) with calibration anchors, not one holistic number |
| Hybrid scoring | deterministic automated checks (static + real-browser + unit tests) blended with judge scores |
| Real browser checks | outputs are executed in a sandboxed browser (admin "Verification Station" or headless Playwright) — JS errors, rendering, FPS, responsiveness, interactivity, unit tests, screenshots |
| Deterministic tracks | algorithms, reasoning and agentic tasks are graded by tests / exact answers / simulated-environment state, no LLM opinion |
| Contamination control | public task set + private **hold-out** set + Task Forge for fresh tasks each season |
| Infrastructure ≠ model failure | API/provider errors are retried and excluded; only real model failures score 0; account problems (no credit, bad key) pause the campaign instead of failing runs; a run is never scored without the browser evidence it needs |
| Full transparency | every output, screenshot, check result and judge rationale is public (public tasks) |
| Cost & latency | tokens, $ and seconds per task; price-performance Pareto frontier |
| Human Arena | blind pairwise votes → Bradley–Terry ratings with CIs |

Unique extras vs the competitor: Arabic UI + an **Arabic & RTL** track, free full leaderboard,
personal **Advisor** (custom-weighted ranking), **Local Fit Lab** (will an open model run on my
hardware + how good is it), Compare (up to 4 models), score history, public JSON API + CSV,
embeddable SVG badges, model request form.

## 1. Stack & constraints

* PHP **8.1+** (no Composer, no build step) + MySQL/MariaDB. Target host: Hostinger shared hosting
  (Apache/LiteSpeed, `.htaccess`, cron jobs, ~300 s max execution, no Node, no Chromium).
* Front-end: hand-written CSS/JS. Vendor libs are **self-hosted** in `assets/vendor/`
  (Chart.js 4). Fonts self-hosted in `assets/fonts/`. No external CDNs on site pages.
* All files are uploaded into `public_html/`. Internal folders are blocked by `.htaccess`
  (`app config storage sql lang tools cron docs`) and every PHP file in `app/` starts with
  `defined('WB') || exit;`.
* Secrets live only in `config/config.local.php` (gitignored, written by the installer).
* The repository is public: never commit secrets, never hard-code the API key.

## 2. Directory layout

```
index.php                 front controller (all routes)
.htaccess                 rewrites + deny rules
install/index.php         web installer (locks itself with install/install.lock)
app/bootstrap.php         loads core, config, session, i18n
app/core/                 helpers.php Config DB Settings I18n Csrf Auth RateLimit Router View Http ui.php
app/eval/                 benchmark engine (namespace WB\Eval)            — module: engine
app/render/               rendering untrusted outputs + probe injection   — module: verifier
app/arena/                arena pairs, votes, Bradley–Terry               — module: arena
app/data/                 read-side queries for public pages              — module: public
app/routes/*.php          route registrations, one file per module (auto-loaded, sorted by name)
app/views/layout.php      public layout;  app/views/admin/_layout.php admin layout
app/views/partials/       header.php footer.php …
app/views/pages/          public pages;  app/views/admin/ admin pages;  app/views/errors/
lang/{ar,en}/<module>.php string tables (one file per module, merged at runtime)
assets/css/app.css        design system (all shared components)
assets/css/<module>.css   optional page-specific styles (add via $page_css)
assets/js/app.js          global behaviours (WB namespace)
assets/js/<module>.js     page scripts (add via $page_js)
assets/vendor/            chart.umd.min.js
assets/img/               logo.svg logo-mark.svg icons.svg favicons og.png
uploads/shots/            screenshots run_<id>_<viewport>_<32 hex>.png|webp (unguessable, unique per capture)
uploads/brand/            generated brand images
storage/logs, storage/cache, storage/sessions
cron/worker.php           CLI worker:  php cron/worker.php --seconds=55
sql/schema.sql            schema;  sql/seed/*.json seed data
tools/verifier-node/      optional headless Playwright verifier (runs on any machine)
tools/dev/                dev-only scripts (task validators, references, mock OpenRouter, tests, fixtures)
docs/                     this file, DEPLOY.md, SECURITY.md, TASKS-CODE.md, TASKS-VISUAL.md, TASKS-AGENTIC.md
```

Class autoload: `WB\Eval\Worker` → `app/eval/Worker.php`, `WB\Arena\Arena` → `app/arena/Arena.php`
(namespace segments lower-cased as directories, class name = file name). Core classes are global
(`DB`, `Config`, `Settings`, `I18n`, `Auth`, `Csrf`, `Router`, `View`, `Http`, `RateLimit`).

## 3. Core API (app/core) — use these, don't reinvent

* Escaping: `e($s)` for HTML; `js_json($v)` inside `<script>`; never echo raw user/model data.
* JSON: `json_arr($text, $default=[])`, `to_json($v)`.
* DB (PDO, prepared statements only): `DB::all/one/val/col/keyed/exec/insert/upsert/update/tx/in`.
  `DB::update($table, $data, 'id = ?', [$id])` (positional where params).
  IN lists: `DB::all('... WHERE id IN (' . DB::in($ids) . ')', $ids)`.
* Settings: `setting('key')`, `Settings::set('key', $v)`. Defaults in `Settings::defaults()`.
* Config (secrets): `Config::get('openrouter_key')`, `Config::get('openrouter_base')`, `Config::write($array)`.
* URLs: `url('/path', ['q'=>1], $absolute=false)`, `asset('css/app.css')`, `upload_url('shots/x.png')`,
  `current_path()`, `redirect('/x')`, `redirect_back()`, `base_path()`, `site_origin()`.
* Requests: `is_post()`, `input('k')`, `input_int('k', 0, min, max)`, `json_body()`.
* Responses: `view('pages/x', $vars)`, `view('admin/x', $vars, 'admin')`, `json_out($data, $code)`, `abort(404)`.
* Security: `csrf_field()`, `csrf_check()` (aborts 419), `require_admin()`, `Auth::apiToken('verifier')`,
  `rate_limit($key, $max, $windowSec)`, `ip_hash()`, `random_token()`, `nonce_attr()`,
  `Http::sandboxHeaders()` for untrusted output, `Http::corsPublic()` for public API.
* i18n: `__('key', ['n'=>3])`, `_e('key')` (escaped), `L($row,'title')` picks `title_ar|title_en`,
  `tr('عربي','English')`, `lang()`, `is_rtl()`, `text_dir()`, `lang_url('en')`, `I18n::forJs(['arena.'])`.
* Formatting: `fmt_score`, `fmt_num`, `fmt_money`, `fmt_price` (per 1M tokens), `fmt_ms`, `fmt_tokens`,
  `fmt_date($dt, 'date'|'datetime'|'relative')`.
* Stats: `mean`, `median`, `stddev`, `percentile`, `clamp`.
* Flash: `flash('success', __('...'))`, `flashes()` (rendered by layouts).
* UI fragments (app/core/ui.php): `ui_icon`, `ui_score`, `ui_scorebar`, `ui_model_chip`,
  `ui_provider_avatar`, `ui_track_badge`, `ui_difficulty`, `ui_heat_cell`, `ui_delta`, `ui_badge`,
  `ui_rank`, `ui_empty`, `ui_stat`, `ui_pagination`, `ui_status`.
* Logging: `log_error($msg, $ctx)`.
* Runtime / hosting: `wb_time_limit($seconds)` and `wb_ignore_abort()` (no-ops where the host disables
  `set_time_limit` / `ignore_user_abort`), `wb_respond_early($data, $code = 200)` (send a JSON answer and close the
  connection via `fastcgi_finish_request` / `litespeed_finish_request`, then keep working), `is_crawler()`
  (User-Agent heuristic), `ar_count($n, [one, two, few, many, other])` (Arabic plural forms).
* Client IP: `client_ip()` (and `ip_hash()`) use `REMOTE_ADDR` unless the request came through a trusted proxy —
  `trusted_proxies()` from config `trust_proxy` = `false` (default) | `'cloudflare'` (`WB_CLOUDFLARE_IPS`) |
  `[ip/cidr…]` | `true` (legacy: private ranges `WB_PRIVATE_IPS` only); `ip_in_ranges($ip, $cidrs)`.
* Rate limits: `rate_limit($key, $max, $windowSec)` is atomic — it reserves the slot *before* the action (no
  parallel-request bypass); `RateLimit::reset($key, $windowSec)` clears a key (e.g. after a successful login).

### Rules for every module
1. **No inline event handlers** (`onclick=`…) and no inline `<script>` without `<?= nonce_attr() ?>` —
   the site CSP blocks them. Pass data to JS with `<script type="application/json" id="x-data">` +
   `js_json()`, or `data-*` attributes.
2. Every POST from a browser: `csrf_check()` first. Every admin route: `require_admin()` first.
3. All visible text through `__()` with keys in your module's lang files (both `ar` and `en`).
4. Use design-system classes (§7). Page-specific CSS goes in `assets/css/<module>.css`.
5. Times are UTC in the DB; format with `fmt_date`.
6. Model output is **untrusted**: only ever display it escaped, or render it via `/render/...`
   in `<iframe sandbox="allow-scripts allow-forms allow-pointer-lock">` (never `allow-same-origin`).
7. PHP 8.1 compatible (no readonly classes, no `true` type, no DNF types).
8. Keep functions small and well-named; one short docblock per class/public method.

## 4. Database (sql/schema.sql)

Key tables: `settings, admins, rate_limits, api_tokens, models, tracks, tasks, campaigns, runs,
judgments, model_scores, score_history, arena_pairs, arena_votes, arena_ratings, model_requests,
brand_assets, changelog, gpus`. Read the schema file for columns. Notes:

* `models.provider` = vendor key = the part of `or_id` before `/` (e.g. `anthropic`). Used for
  judge self-exclusion and colours. Prices are USD per **1M** tokens.
* `tasks.visibility`: `public` | `holdout` (hold-out prompts/outputs are never exposed publicly). The repository
  is public, so **every seeded task is `public`**; hold-out tasks are created privately in the admin (Task Forge /
  task editor) and never committed (§11).
* `tasks.output_type`: `html` | `js` | `answer` | `agent` | `text`.
* `runs.status` state machine (§5.2). `runs.final_score` 0–100 (NULL = scored without a usable score, §5.3).
* `runs.cost_usd` = cost of the stored generation (averages, Pareto); `runs.spent_usd` = every paid call of the run
  (failed / discarded attempts, judge calls) and only grows — `campaigns.spent_cost` is its sum.
* `model_scores.scope`: `overall` or `track:<slug>`; `season` = `setting('season')`; `n_failed` = the model's runs
  excluded from that score (failed after their tries, or scored without a usable score).
* Migrations (`sql/migrations/`): `001_scores_failed_runs.sql` (model_scores.n_failed), `002_runs_spent_usd.sql`
  (runs.spent_usd, back-filled from cost_usd + judgment costs). Code reading these columns checks
  `WB\Eval\Pipeline::hasColumn()` first, so a site works before its admin runs *Migrate database*.

## 5. Benchmark engine (module: engine, app/eval, namespace WB\Eval)

### 5.1 Task definition formats

`rubric_json` — array of criteria (weights are relative):
```json
[{"key":"visual","label_en":"Visual polish","label_ar":"الإتقان البصري","weight":3,
  "guide":"Cohesive palette, typography, spacing; looks professionally designed.",
  "guide_ar":"لوحة ألوان وطباعة ومسافات متناسقة؛ يبدو مصمَّمًا باحتراف."}]
```
`guide` (English) is what the judges read; `guide_ar` is an optional Arabic translation for task authors
(admin). Public pages show only label + weight per criterion — guides describe the expected solution, so they are
not published (contamination). Deterministic tasks (answer / agent) have an empty rubric.

`checks_json` — object; every key optional:
```json
{
  "static":  [ {"id":"viewport","type":"contains","pattern":"<meta[^>]+viewport","regex":true,"weight":1,
                "label_en":"Has viewport meta","label_ar":"..."} ],
  "browser": [ {"id":"no_errors","type":"no_errors","weight":2,"label_en":"No JS errors","label_ar":"..."} ],
  "tests":   {"entry":"solve","timeout_ms":2000,"cases":[{"name":"basic","args":[[3,1,2]],"expect":[1,2,3],"cmp":"deep"}]},
  "answer":  {"type":"number","value":42,"tolerance":0.001},
  "agent":   { ... see §5.6 ... }
}
```

**Static check types** (evaluated in PHP on the extracted code, `app/eval/StaticChecks.php`):
`contains` {pattern, regex?, ci?} · `not_contains` {pattern, regex?, ci?} · `selector` {sel, min?, max?}
(CSS → XPath: groups `,`, combinators ` ` `>` `+` `~`, `tag` `*` `#id` `.class` `[attr]` `[attr op val]` and
`:first-child :last-child :only-child :checked :disabled :empty :root`; partial score below `min`) ·
`max_bytes` {value} · `min_bytes` {value} · `no_external` {allow:[hosts]} (no src/href to other hosts) ·
`viewport` · `lang_attr` {value?} · `dir_rtl` · `html_parses` (DOMDocument error count ≤ `max_errors`) ·
`single_file` (no relative src/href to local files) · `regex_count` {pattern, min, max?} ·
`js_parses` — heuristic for `js` outputs (balanced braces/brackets/parens outside strings/comments).

**Browser check types** (evaluated by the probe in a real browser, module verifier):
`loads` · `no_errors` · `nonblank` · `selector` {sel, min?} · `text` {contains, ci?} ·
`animates` (pixels/DOM change over 1.5 s) · `fps` {min} · `responsive` (no horizontal overflow at 390 px) ·
`keyboard` {keys:[...], sel?} (state changes after key events; `sel` = element focused and targeted first,
otherwise the largest element with key listeners) · `click` {sel?} (state changes after click) ·
`rtl` · `a11y_basic` (img alt, labelled inputs, named buttons, lang attr) · `canvas` (a non-empty canvas exists).
`text.contains` may be a string or a list (all must appear). On animated pages `keyboard` / `click` compare the
change with a control interval without input (pass 1.0 when the input changed new regions or stopped the
animation — pause buttons —, 0.75 when handlers ran but the effect is not separable from the animation). Each result: `{"id","type","pass":bool,"score":0..1,"detail":"...","value"?}`.

**JS tests** (`output_type = js`, executed by the verifier's harness, `assets/js/harness.js`, a **fresh Worker
per case**): `tests = {"entry","timeout_ms","weight"?,"cases":[…]}`; case =
`{"name", "args":[...], "expect":..., "cmp":"deep|approx|set|sorted|truthy", "tol":1e-6, "timeout_ms"?}` (calls
`entry(...args)`) **or** `{"name","script":"const c = new LRU(2); c.put(1,1); return c.get(1);","expect":1}`.
Result: `{"name","pass","ms","got"?: "<JSON preview string, ≤ 300 chars>", "error"?: "...", "oom"?: true}`.
Test conventions (enforced by the builders and `tools/dev/validate-tasks.mjs`, see docs/TASKS-CODE.md):
* **thenables are awaited** — an entry or script returning a promise is awaited within the case timeout.
* **script mode** wraps the snippet: `code + "\n;return (function(){ <script> })();"` inside the case function,
  so `return` works and the model's top-level declarations are in scope. ES-module `export` keywords and
  CommonJS `module.exports` packaging are tolerated.
* **JSON-stable expectations** — every `expect` survives `JSON.parse(JSON.stringify(x))` unchanged (no
  `undefined`, `NaN`, `-0`, `Map`…). Empty objects are fine: the render layer decodes the suite with objects
  preserved (`{}` never turns into `[]`).
* **no unpaired surrogates** in task JSON (PHP `json_decode` and MySQL reject them); malformed UTF-16 test inputs
  are built inside scripts with `\uD800` escapes. Verifier submissions that contain lone surrogates are repaired
  to U+FFFD by `VerifierApi::decodeBody()` instead of being rejected.
* Comparison: `deep` (structural; NaN = NaN; −0 = 0; keys whose value is `undefined` ignored), `approx`
  (absolute `tol`, recursive), `set`/`sorted` (order-insensitive multiset, top level), `truthy`.

**Answer** (`output_type = answer`): the prompt asks for `<answer>…</answer>`. Types:
`exact` {value, ci?} · `number` {value, tolerance} · `regex` {pattern} · `set` {value:[...], ci?}
(comma/newline separated, order-insensitive) · `json` {value} (deep equal) · `one_of` {values:[...]}.

### 5.2 Run state machine

```
queued ──generate──▶ (needs browser?) ──yes──▶ awaiting_verify ──verifier claims──▶ verifying ──submit──▶ judging|scoring
                      │no                                                                   ▲
                      └──────────────────────────────▶ judging (if judges needed) ─────────┘──▶ scoring ──▶ scored
any step: infrastructure error → tries++ → retry (back to previous waiting state) ; tries ≥ 3 → failed (excluded)
any step: provider halt (401 / 402 / 403 key limit) → back to the waiting state, try given back, campaign paused (§5.7)
verification failed 3× for infrastructure reasons (verify_mode required) → stays awaiting_verify (needs_verification)
```
* "needs browser" = output_type `html` or `js` **and** the task has `browser` checks or `tests`
  **and** `setting('verify_mode') !== 'off'`. In `optional` mode runs older than
  `verify_wait_minutes` in `awaiting_verify` proceed unverified.
* "judges needed" = task has a non-empty rubric **and** effective auto_weight < 1 (`Pipeline::configuredAutoWeight`;
  a rubric-only task is always judged, even in a track whose auto weight is 1, so it never ends up without a score).
* Leases: claim with `UPDATE … SET status=?, locked_until=NOW()+INTERVAL n SECOND, lock_token=? WHERE id=? AND status=? AND (locked_until IS NULL OR locked_until < NOW())`
  and proceed only if rowCount = 1. Expired leases are reclaimable (crash safety).
* Generation result with no extractable code → still scored (it is the model's failure): auto checks
  fail, judges see the raw text.

### 5.3 Scoring

```
auto_score   = 100 × Σ(w·score_i) / Σw   over static checks + browser checks (+ tests: pass fraction × tests weight)
               tests weight = checks.tests.weight ?? 6 ; answer tasks: auto_score = 100 or 0 ;
               agent: weighted goal checks, capped at agent_critical_cap (20) when a "critical" goal fails (§5.6)
judge_i      = 10 × Σ(w_c·crit_c) / Σw_c          (crit 0..10)  → 0..100
judge_score  = median(judge_i) (≥3 judges) | mean (2) | value (1)
judge_spread = max(judge_i) − min(judge_i)
a            = tasks.auto_weight ?? tracks.auto_weight     (1.0 when there is no rubric; 0.0 when there is a rubric
               but no automated evidence at all — no static / browser checks, tests, answer or agent goals)
final        = a·auto_score + (1−a)·judge_score
if output_type in (html) and there is no code, or the browser check 'loads' or 'nonblank' failed
             → final × render_fail_factor (0.5)
```
As built (`WB\Eval\Scorer::compute`) — **missing verification is never rewarded**:
* **verify_mode `required`** (default): an html/js run with browser checks or tests is never scored without its
  browser evidence.
  * The *output* is at fault — the page hangs, navigates away, runs out of memory on the last try, or crashes the
    browser renderer on every try (a verifier releases the last try with `"cause": "output"`): the run is stored as
    **verified** with the failures (every unreported check and test = 0, `metrics.hung|oom|crashed`, note
    `verify_output_failed` for crashes) and the render-fail factor applies.
  * *Infrastructure* is at fault — the verifier released the run, or its lease expired, on all 3 tries: the run is
    **not scored**. It waits in `awaiting_verify` with the note `needs_verification` (no tries left, so no
    verifier claims it) until an admin re-verifies it (`Pipeline::reverify()` → fresh tries, note `reverified`;
    `Pipeline::needsVerificationIds()` lists them). Its campaign stays `running` meanwhile.
* **Unverified runs** (only `verify_mode` off, or `optional` after `verify_wait_minutes` / 3 verifier failures, notes
  `unverified_off|unverified_timeout|unverified_failed`): browser checks and tests are *excluded*, not failed, and the
  automated part only carries the share of evidence that was measured:
  `a ← a × measured_weight / (measured_weight + excluded_weight)`, so a few static checks can never stand in for
  unit tests. When such a run has **no judge verdict** (no rubric, auto weight 1, or no judge) nothing but static
  checks was measured: `final = NULL` (note `unverified_unscorable`) — the run is excluded from aggregation and
  counted in `n_failed` instead of scoring 100 for any code that parses.
* A run with **no extractable code** is not "unverified": its browser checks and tests count as failed.
* No judge verdict → `a = 1`; no automated evidence at all → `a = 0` (judges only).
* **Unit tests** are matched to the task's cases by name when every case has a unique name, otherwise strictly by
  position (verifiers report results in case order) — duplicate names can never let one result count twice.
* **Contested** (`runs.contested`): with ≤ 3 verdicts, `spread > judge_tiebreak_spread`; after a tie-break (≥ 4
  verdicts) the single verdict farthest from the median is the one being arbitrated, so contested means the
  *remaining* verdicts still spread more than `judge_tiebreak_spread`. The tie-break judge is added once when the
  panel's spread exceeds the threshold (§5.4); the median then absorbs the outlier.
Aggregation (`WB\Eval\Aggregator::recompute(?string $season = null)`), per model, season, using runs
with status `scored` from campaigns with `include_in_scores = 1` and active tasks:
```
task_score   = mean(final over attempts)
track_score  = mean(task_score over tasks in track)
overall      = mean(track_score over tracks)          ← equal weight per track
coverage     = scored tasks / active tasks            (per track: scored tasks of the track / its active tasks)
provisional  = overall: coverage < min_coverage OR any active track missing or with track coverage < min_coverage
               track:   overall coverage < min_coverage OR that track's coverage < min_coverage
CI           = stratified bootstrap (resample tasks within each track, then attempts within each task),
               bootstrap_iters iterations, 2.5/97.5 percentiles, deterministic seed (mt_srand(1337)),
               with a small-sample correction (below)
consistency  = 100 − mean over tasks of stddev(attempts)   (higher = more consistent)
rank_pos     = competition rank (ties share a rank) by score desc among the scope's non-provisional rows
stat_rank    = 1 + #models whose ci_low > this ci_high
n_failed     = runs of included campaigns excluded from the score (status failed, or final_score NULL)
```
* Equal weight per track only means something when every track is there: excluded runs are not missing at random
  (a slow model loses its hardest tasks to timeouts), so a model that skipped a whole track — or covers one below
  `min_coverage` — is listed but **not ranked** (provisional), even when its overall coverage is ≥ `min_coverage`.
* **Small-sample correction** (a track has ~5 tasks): resampling n task means shrinks their variance by (n−1)/n and a
  percentile interval ignores that the spread itself is estimated from n values. Each resampled track mean is
  moved away from the track's point estimate by `sqrt(n/(n−1))`; track samples additionally by `t(n−1)/z`, and
  overall samples by `t(df)/z` with the Welch–Satterthwaite df of the tracks' variances (≈ Σ(n−1)). CIs are clamped
  to 0–100. Measured by `tools/dev/tests/ci_coverage_sim.php` (2000 replicates, 12 tracks × 3–10 tasks × 2–3
  attempts): overall 94.6–97.8 %, per track 95.2–99.0 % (before: ≈ 92 % overall, 81–84 % per track); a strongly
  skewed task distribution still gives 95 % overall but 92 % per track (stress test, reported, not gated).
* Track leaderboards (`?track=`, API `track.rank`) use the stored track `rank_pos` / `stat_rank`; a model that is
  provisional in that track is listed after the ranked ones, without a rank. The price-performance frontier
  (`Models::paretoIds`) ignores provisional models (they are still drawn).
* Per-task tables (`WB\Data\Models::COUNTED`) use the aggregator's exact filter: scored with a usable score,
  included campaign of the season, active task, track and model.
Writes `model_scores` (scope `overall` and `track:<slug>`; track rows also get CI) and one
`score_history` row per model per day (upsert). Sets `settings.aggregate_last`.

### 5.4 Judges

* Judge pool: `models WHERE is_judge = 1 ORDER BY judge_order`. Per run choose the first
  `judge_count` judges whose `provider` ≠ run model's provider. Extra tie-break judge = next eligible.
  Because judges never grade their own vendor, a full panel **plus** a tie-break reserve for every model needs
  judges from at least `judge_count + 2` distinct vendors (5 for the default 3); with fewer, models of the judges'
  vendors get a smaller panel (a mean of 2) and no tie-break. `Judge::panelHealth($models)` →
  `[model_id => {eligible, panel, want, tiebreak}]` for admin warnings. A run scored by fewer verdicts than
  `judge_count` (failed judges, no replacement left) gets the note `judges_partial`.
* Blind judging (`Judge::redact`): the model's name, slug and OpenRouter id are replaced by `[model]` anywhere; its
  vendor key, vendor name and brand word ("Anthropic", "Claude", "Gemini" …) as whole words in prose only — not inside
  identifiers, URLs, tags or package names (`fonts.googleapis.com`, `@anthropic-ai/sdk`), and never generic words
  (`meta`, `command`, `nova` …).
* Prompt: system prompt with calibration anchors (0–2 broken · 3–4 poor · 5–6 adequate · 7–8 strong ·
  9–10 exceptional/rare; "most competent outputs land at 5–7"), the task prompt, rubric (with guides),
  automated check summary, the code (truncated to ~60k chars, head+tail), and screenshots (desktop +
  mobile as `image_url` data URLs) when the judge `supports_vision` and screenshots exist.
  Never reveal the model name. Temperature `judge_temperature`. `response_format: json_object`.
* Expected judge JSON: `{"criteria":{"<key>":0-10,...},"summary":"…","strengths":["…"],"weaknesses":["…"]}`.
  Parse robustly (first balanced `{…}`), clamp to 0..10, missing criterion → retry once, then 0.

### 5.5 OpenRouter client (`WB\Eval\OpenRouter`)

* Base `Config::get('openrouter_base', 'https://openrouter.ai/api/v1')`, key `Config::get('openrouter_key')`.
  Headers: `Authorization: Bearer`, `HTTP-Referer: site_origin()`, `X-Title: WOAIBench.si`.
* `chat(array $payload, int $timeout): array` → normalized
  `{ok, content, reasoning, tool_calls, finish_reason, usage:{prompt_tokens, completion_tokens, reasoning_tokens, cost}, provider, id, ms, error, status, retryable}`.
  Request `usage: {include: true}` so cost comes back; fallback cost = tokens × model prices.
  `retryable` = network error, 408, 429, 5xx, or provider error codes. `retry_after` = seconds from a 429 / 503
  `Retry-After` header (the hold-off before the next try is at least that, ≤ 900 s).
  `halt` = `{code, message}` for a **provider halt** — 401 (invalid / missing key), 402 (no credit), 403 with key /
  credit / limit wording (key spend limit) — never retryable and never the model's failure (§5.7). A 403 for
  moderation is not a halt.
* Image URLs returned by an image model are fetched only when every A / AAAA address of the host is public; the
  download is pinned to that vetted address (`CURLOPT_RESOLVE`, no redirects) so DNS rebinding cannot reach an
  internal address.
* `models(): array` (GET /models) for catalog sync; `images(prompt, model, size): array` — tries
  `POST /images/generations` (OpenAI style: `data[0].b64_json|url`), falls back to chat completions
  with `modalities: ["image","text"]` (`choices[0].message.images[0].image_url.url` data URL).
  Returns `{ok, bytes, mime, error}`.

### 5.6 Agentic simulator (`WB\Eval\AgentSim`)

Multi-turn loop with **native tool calling** when `models.supports_tools`, otherwise a JSON text
protocol (model replies with a fenced ```json {"tool":"…","args":{…}}``` block per step).
`checks.agent`:
```json
{
  "max_steps": 12,
  "tools": ["list_dir","read_file","write_file","append_file","delete_file","move_file","search_files",
            "http_get","query_records","update_record","send_email","calendar_list","calendar_add","calc","finish"],
  "env": {
    "files":    {"/inbox/a.txt":"…"},
    "http":     {"https://api.example.test/rates?base=USD":{"status":200,"body":"{…}"}},
    "records":  {"orders":[{"id":1,"status":"open","total":12.5}]},
    "calendar": [{"title":"Standup","start":"2026-10-20T09:00","end":"2026-10-20T09:15"}],
    "now":      "2026-10-19T08:00"
  },
  "goal_checks": [
    {"type":"file_equals","path":"/out/x.txt","value":"…","trim":true,"weight":2},
    {"type":"file_contains","path":"/out/x.txt","value":"…","ci":true},
    {"type":"file_json_equals","path":"/out/x.json","value":{}},
    {"type":"file_exists","path":"…"}, {"type":"file_absent","path":"…"},
    {"type":"record_equals","table":"orders","id":1,"field":"status","value":"shipped"},
    {"type":"email_sent","to":"a@b.test","subject_contains":"…","body_contains":["…"]},
    {"type":"email_count","max":1},
    {"type":"calendar_has","title_contains":"…","start":"2026-10-21T10:00"},
    {"type":"answer_contains","value":"…","ci":true}, {"type":"answer_number","value":3,"tolerance":0},
    {"type":"tool_not_called","tool":"delete_file"},
    {"type":"no_write_outside","prefix":"/out/"},
    {"type":"max_steps","value":8}
  ]
}
```
Tool semantics: paths are absolute strings; `list_dir(path)` lists direct children; `search_files(query)`
case-insensitive substring over contents → paths; `http_get(url)` only from `env.http` (others → 404);
`query_records(table, where?:{field:value}, sort?, limit?)`; `update_record(table,id,fields)`;
`send_email(to,subject,body)` appends to an outbox; `calendar_add(title,start,end)`; `calc(expression)`
safe arithmetic (+ − × ÷ ^ parentheses, decimals) — **no eval**; `finish(answer)` ends the episode.
Transcript stored in `runs.agent_json`. auto_score = weighted goal checks (auto_weight 1.0).

**Goal fields (as built).** Every goal may carry `key` (stable identifier used in docs, validators and the
result; `id` is reserved for the record id of `record_equals`), `label_en` / `label_ar` (default labels per type
otherwise), `weight` (default 1), `constraint` (bool, see the gate below) and `critical` (bool).
* `record_equals` compares case-insensitively unless `"ci": false`; `file_contains` / `answer_contains` /
  `email_sent` take `ci` too (`email_sent` defaults to case-insensitive). `tool_called` {tool, min?} exists too.
* **Partial scores**: `file_contains` / `answer_contains` = fraction of the listed values present;
  `file_json_equals` = fraction of matching top-level keys (object values); `email_sent` = best fraction of
  subject/body conditions over the emails sent to `to`; `calendar_has` = matched parts of title/start/end (title
  right, time wrong → ½ or ⅓); `tool_called` = calls ÷ min. All other goals are 0/1.
* **Critical goals**: when any goal with `"critical": true` fails, the episode's auto_score is capped at
  `setting('agent_critical_cap')` (default 20) — deleting evidence, leaking credentials or touching a legal-hold
  record cannot be offset by finishing the rest. Result keys `auto_score_uncapped`, `critical_failed` (goal keys).

**Constraint gate** (setting `agent_constraint_gate`, default 1): a goal is a *constraint* if it has
`"constraint": true`, or (unless `"constraint": false`) if it already passes on the untouched initial
workspace (fallback by type: `tool_not_called`, `no_write_outside`, `max_steps`, `email_count` with only
`max`). When at least one non-constraint goal exists and none scores above 0, constraint goals get
pass=false/score=0 — an agent that does nothing scores 0. Extra result keys: goal_results items add
`key, label_en, label_ar, constraint, critical`; the AgentSim result adds `turns, end_reason (finish|max_steps|
wall_time|no_tool_calls|api_error), protocol (native|json), provider, gen_id, auto_score_uncapped,
critical_failed`; the Generator result adds `note`. Models without `supports_tools` use the JSON protocol from the
start; when the provider rejects tool calling on the very first turn (non-retryable error mentioning tools, e.g.
OpenRouter's 404 "no endpoints support tool use") the episode is replayed in the JSON protocol automatically.
Agent options: `checks.agent.wall_s` (default 150, clamped 0–600), `max_steps` clamped 1–60; generation
params whose key starts with `_` are runner options never sent to the API (`_timeout, _wall_s, _retries,
_retry_sleep_ms`).

### 5.7 Worker & entry points (as built)

Route files: `app/routes/20_engine.php` (worker step, web tick, verifier API), `app/routes/30_render.php`
(`/render/{id}`, `/admin/verify`), `app/routes/80_setup.php` (setup JSON). Route files load in name order.

* `WB\Eval\Worker::step(float $budgetSeconds = 25.0, ?ChatClient $client = null, ?callable $generate = null): array`
  — loops over the phases generate → judge → finalize → verification timeouts, one claimed unit per phase per
  round, until the budget is used or a whole round made no progress (`idle`). Then: close/reopen campaigns,
  aggregation (when `aggregate_dirty` and either idle or ≥ 60 s since `aggregate_last`) and
  `WB\Arena\Arena::maybeRecompute()`; writes the heartbeat `worker_last_run`. `$client` / `$generate` replace the
  OpenRouter client / `Generator::run` (tests). Returns
  `{"did":[…], "counts":{<status>:n, …, "judgments_open":n}, "idle":bool, "halted":bool, "spent":usd, "ms":int}`.
* **Provider halts** (OpenRouter 401 / 402 / 403 key limit, `OpenRouter::haltOf`): the unit is not a model failure and
  not an infrastructure retry — the run / judgment goes back to `queued` / `pending` with its try given back, the
  lease is released, its campaign is **paused**, and the setting `provider_halt` =
  `{"code":401|402|403,"message":"…","at":"Y-m-d H:i:s","campaign_id":n,"campaign_ids":[every campaign it paused]}`
  is stored (`Campaign::halt`). While it is set, `Worker::step` claims no generation or judging unit and returns
  `halted: true` (finalize / timeouts still run). `Campaign::resume($id)` clears it; `Campaign::clearHalt(true)` clears
  it and resumes every campaign it paused (admin "Clear & resume"); `Campaign::providerHalt()` reads it from the
  database. The admin dashboard and console show it (`admin.halt.*`), the cron worker prints it.
* A step checks its budget between units only, so the last unit may run for a whole generation or judge
  evaluation: the PHP time limit (web) is `budget + max(3 × gen_timeout_s + 180, 3 × judge_timeout_s + 60)`.
  `did` kinds: `generate` {run_id, model, task, attempt, ok, status, ms, cost, error} · `judge` {run_id,
  judgment_id, judge, task, role, ok, score, status, run_status, ms, cost, error} · `finalize` · `score` ·
  `judge_expired` · `generate_expired` · `verify_given_up` · `verify_skipped` {reason} · `aggregate` {models, runs,
  ms} · `arena`.
* Retries: infrastructure errors re-queue with backoff 30 s, 60 s, 120 s … (max 600 s; at least the provider's
  `Retry-After`); `tries ≥ 3` → `failed` (excluded from scores). Leases: generation `gen_timeout_s + 120` s (agent:
  `max(3 × gen_timeout_s + 180, wall_s + 105)` with the episode's `checks.agent.wall_s` / `_wall_s`, so a lowered
  `gen_timeout_s` never lets a second worker start a duplicate paid episode), judging `3 × judge_timeout_s + 60`,
  verification 300 s, scoring 60 s.
* Spend: every paid call adds to `runs.spent_usd` (failed and discarded generation attempts, agent turns before an
  API error, judge calls); `Campaign::refreshSpent` sums it, so campaign and dashboard spend are exact.
* `WB\Eval\Campaign` — `create(name, modelIds, taskIds, runsPerTask ≤ 10, genParams = []): int` (status `running`,
  current season, runs inserted attempt-major) · `estimate(modelIds, taskIds, runsPerTask)` →
  `{runs, gen_cost, judge_cost, total, per_model:[{model_id, name, runs, gen_cost, judge_cost, cost}], assumptions}` ·
  `progress(id)` → `{id, name, status, season, include_in_scores, runs_per_task, total, counts, done, scored, failed,
  percent, spent, est_cost, avg_score, judgments_open, eta_seconds, created_at, started_at, finished_at}` ·
  `activeProgress()` · `totalSpent()` · `refreshSpent(id)` · `pause/resume/cancel(id)` (cancel → unfinished runs
  `skipped`) · `retryFailed(id)` (`Pipeline::retry`: never-generated runs are re-queued, others keep their output
  and redo verification/judging). A `done` campaign whose runs reopen goes back to `running` automatically.
* `POST /admin/api/step` (admin + CSRF; releases the session lock first) → `Worker::step(20)` +
  `{"spent_usd", "step_spent_usd", "campaigns":[progress…], "ok":true}`.
* `GET /worker/tick?key=<config cron_key>` → `Worker::step(50)` under the worker lock →
  `{"ok", "busy", "units", "idle", "spent", "counts", "ms"}` (404 for a missing/wrong key). When the SAPI can finish
  a response early (`fastcgi_finish_request` / `litespeed_finish_request`) it answers `{"ok":true,"accepted":true}` at
  once (`wb_respond_early`) and then runs the step, so a web-cron client's ~30 s timeout never cuts a unit;
  `.htaccess` sets LiteSpeed `noabort` / `noconntimeout` for `/worker/tick` and `/admin/api/step`.
* CLI `php cron/worker.php --seconds=55` (Hostinger cron every minute). Invocations serialise on the file lock
  `storage/cache/worker.lock`: an overlapping invocation exits at once; leases keep the admin console safe too.
* **Verifier API** (auth: admin session + `X-CSRF-Token`, **or** `Authorization: Bearer <api token scope verifier>`;
  body ≤ 12 MB, decoded by `VerifierApi::decodeBody()` which repairs lone UTF-16 surrogate escapes):
  * `POST /api/verifier/next` `{"agent"?}` → `{"ok":true,"run":null}` or
    `{"ok":true,"run":{"id","lock_token","output_type","render_url","viewports":[{"name":"desktop","w":1280,"h":800},{"name":"mobile","w":390,"h":844}],"checks":[…browser checks…],"tests":{…}|null,"timeout_ms","attempt","max_attempts","task":{"slug","title"}}}`.
    Claiming spends one of the run's 3 verification tries (`attempt` = this try). `render_url` = absolute
    `/render/<id>?verify=1&vt=<hmac>` (see §6); `timeout_ms` grows with the number of test cases (15–120 s).
  * `POST /api/verifier/submit` body
    `{"run_id","lock_token","agent":"station|playwright","results":{"checks":[…],"tests":[…],"errors":["…"],"metrics":{…}},"screenshots":{"desktop":"data:image/png;base64,…","mobile":"…"}}`
    → results sanitised against the task's own definitions (`VerifierApi::sanitizeResults`: unknown checks
    dropped, missing ones = failed "not reported", test `got` strings kept as-is, metrics `load_ms fps js_errors
    console_errors resource_errors dom_nodes oom` (numbers) and `hung navigated starved hidden offscreen`
    (booleans)), screenshots validated (PNG/JPEG/WebP magic + GD re-encode, ≤ 4 MB, single-colour captures
    rejected) and saved as `uploads/shots/run_<id>_<viewport>_<32 hex>.png|webp` (`VerifierApi::saveShot`; static
    files bypass every PHP visibility check, so hold-out screenshots rely on the unguessable name; each capture gets a
    new name, so the 1-year immutable cache stays correct). The previous captures of the run are deleted once the
    new ones are stored (`VerifierApi::pruneShots`; also after a regeneration, and by the admin reset / delete).
    Legacy guessable names are renamed by the Migrator step `shots_unguessable`. `verified=1`, `verified_by`, then the
    state machine advances → `{"ok":true,"next_status":"judging|scored","warnings":[…]}`. Errors:
    `{"ok":false,"error":"bad_request|not_found|lease_lost|task_missing|payload_too_large"}` with HTTP
    400/404/409/404/413; 401 without credentials.
  * `POST /api/verifier/release` `{"run_id","lock_token","error","cause"?}` → back to `awaiting_verify` with a growing
    hold-back (20 s, then 60 s) → `{"ok":true,"next_status":"awaiting_verify"}`. On the last try: `"cause":"output"`
    (the page crashed the renderer on every try) → verified as the output's failure (note `verify_output_failed`,
    `metrics.crashed`); otherwise (infrastructure) → `verify_mode` required: waits for an admin re-verification
    (note `needs_verification`), optional / off: proceeds **unverified** (note `unverified_failed`, §5.3). The same
    applies when a verification lease expires on the last try (Worker finalize).
  * Verifier policy (both verifiers): a page that hangs or navigates away is the output's failure (submitted
    with `hung` / `navigated`); a page measured while hidden/off-screen is re-measured or released; an
    out-of-memory error ("Array buffer allocation failed", heap exhausted) is released and retried in a fresh
    frame/context, and only when it reproduces on the last try (`attempt == max_attempts`) is it submitted as
    the output's own failure.

## 6. Rendering untrusted output (module: verifier, app/render) — as built

* `GET /render/{id}` — serves the run's code as a full HTML document with `Http::sandboxHeaders()` (CSP
  `sandbox allow-scripts allow-forms allow-pointer-lock` → opaque origin even top-level; `form-action 'none'`
  still blocks any real submission; scripts only inline / blob / data / our own origin / a few CDNs).
  * `html` outputs: the extracted HTML document, verbatim. `js` outputs: a harness page (highlighted code +
    "Run tests" for public tasks). Other types: the escaped raw output. Nothing generated yet → 404.
  * Hold-out tasks: admin session or a valid `vt` token, otherwise **404** (ids leak nothing).
  * Query parameters: `verify=1` (inject the probe; needs `vt` or an admin session, else 403), `vt=<token>`,
    `mode=station|playwright` (default station), `vp=<viewport name>` (reported back by the probe), `light=1`
    (secondary viewport: only cheap checks run — loads, no_errors, nonblank, selector, text, responsive, rtl,
    a11y_basic, canvas; no tests, no timing checks), `theme=dark|light` (only for documents we generate
    ourselves: js harness, raw text, empty state).
  * Verify mode injects, as the very first content of `<head>` (after BOM / comments / doctype):
    `<script data-wb-probe>window.__WB_PROBE_CFG={…}</script>`, a tiny pre-script (WebGL contexts get
    `preserveDrawingBuffer`, page `requestAnimationFrame` timestamps are recorded), `assets/js/probe.js` and, for js
    outputs, `assets/js/harness.js`. No html2canvas: it cannot work in the opaque-origin sandbox (it scripts a cloned
    child frame, which is cross-origin there). The JS test suite is embedded with JSON objects preserved.
  * `vt` = `<expiry>.<base64url HMAC(app_secret, run_id|lock_token|expiry)>`, issued by `/api/verifier/next`,
    valid 15 min and only while the run is `verifying` with that lock token.
  * `wb_render_vt(int $runId, string $lockToken): string`, `wb_render_vt_valid(int $runId, string $vt): bool`,
    `wb_render_tests(array $task): ?object` in `app/render/render.php`.
* `function wb_render_document(array $run, array $task, array $opts = []): void` (sends headers + body;
  `$opts`: `verify`, `vt`, `mode`, `viewport`, `light`, `theme`, `embed_anywhere` / `embed = 'anywhere'`
  (frame-ancestors *), `parent_origin`, `timeout_ms`). Used by `/render/{id}` and the arena frames
  (`/arena/frame/{token}/{side}`).
* **Probe protocol** (`assets/js/probe.js`, runs before any page code; never navigates or hangs on its own):
  frame → parent `{"type":"wb-probe-hello","nonce","viewport","deadline_ms"}` at start, then
  `{"type":"wb-probe","nonce","run_id","viewport":{"name","w","h","light"},"checks":[…],"tests":[…],"errors":[…],
  "metrics":{"load_ms","dom_ready_ms","fps","js_errors","console_errors","resource_errors","dom_nodes","probe_ms",
  "finished":"done|deadline|navigated|error","hidden","offscreen","starved","oom","harness"}}`. Parent → frame
  `{"type":"wb-shot"}` asks for a screenshot; the frame answers `{"type":"wb-shot","nonce","dataUrl"|"error"}`.
  Messages go to `parentOrigin` only, the nonce is removed from the DOM before page code runs. For Playwright the
  same result is exposed as `window.__WB_PROBE_READY`, `__WB_PROBE_DEADLINE`, `__WB_PROBE_RESULT` and
  `__WB_PROBE_RESULT_JSON` (non-writable) — **without the nonce**, which only ever appears in the posted messages.
  Deadline = max(timeout_ms, load + settle + per-check budgets + tests).
* **Probe hardening** (html outputs share the probe's realm): before any page script runs the probe captures the
  built-ins it needs (`Reflect.apply`, `Object.keys`, Math, JSON, Promise.resolve, canvas / serializer / Image.src
  primitives for Station screenshots), locks the built-in methods its checks rely on as non-writable
  (Array.prototype iteration methods, String / RegExp search methods, Function.prototype.call/apply/bind,
  Promise.prototype.then/catch/finally/constructor and `Promise[Symbol.species]`), keeps every internal record
  prototype-less (results travel through promises, so `Object.prototype.then` / setters / for-in pollution cannot
  reach them), serialises the result itself (never `toJSON`) and appends to arrays with `defineProperty`. A page that
  adds index properties ("0", "1" …) to `Object.prototype` / `Array.prototype` (which would intercept plain array
  writes) is reported as tampering: `metrics.tampered = true`, every check fails with "not trusted".
  `tools/verifier-node/verify.mjs` polls the result with primitive-only `evaluate` calls (a page that breaks
  Playwright's in-page helpers cannot hang or feed it).
* **Station screenshots** are taken inside the frame: DOM → SVG `<foreignObject>` → `<img>` → canvas, then the
  page's own (readable) canvases are composited on top (WebGL keeps its buffer thanks to the pre-script).
  Fallback: page background + canvases. Single-colour captures are rejected by the API.
* `assets/js/verifier.js` — `window.WBVerifier.start(containerEl, {concurrency:2, onLog, onStats, onState})`,
  `.stop()`, `.setConcurrency(n)`. Admin page `/admin/verify` and the Console. Uses the verifier API with
  `WB.fetchJSON` (CSRF). **One live frame** at a time (sandboxed frames of one site share a renderer process, so
  timing checks stay honest and a frozen page is unambiguously the culprit); lanes overlap claiming, uploading
  and submitting. Only claims while the tab is visible and the container rendered.
* `assets/js/harness.js` — `WBHarness.run(code, tests, {budgetMs})`: a **fresh Worker per case** (blob URL,
  else data URL, else main thread as a last resort), hard per-case timeout (worker terminated), verdict computed
  outside the worker over a private MessageChannel, 250 ms pause after a terminated worker, one in-page re-run of
  an out-of-memory case (`oom: true` when it persists).
* `tools/verifier-node/` — `node verify.mjs --site https://woaibench.si --token <api token>` using Playwright
  Chromium (software WebGL via `--enable-unsafe-swiftshader` only as a fallback; ANGLE is not forced to
  SwiftShader so 2D pages keep their real frame rate). Opens each viewport top-level in a **fresh browser
  context** (`mode=playwright`), waits for `__WB_PROBE_RESULT_JSON`, takes `page.screenshot()`, submits; lone
  surrogates are replaced before sending. A renderer crash is retried in a fresh context like out-of-memory and, on
  the last try, released with `"cause":"output"` (the output's own failure).

## 7. Design system (module: design — assets/css/app.css, assets/js/app.js, layouts)

**Identity:** "Obsidian & Champagne" — deep ink-black surfaces, champagne-gold accent, ivory text,
precise hairline borders, glass panels, generous whitespace. Light theme = ivory paper + ink text.
Display type: serif (Latin) / Arabic display face; body: Inter (Latin) / IBM Plex Sans Arabic;
numbers: tabular mono.

Theme: `<html data-theme="dark|light">` (default dark, remembers choice), `dir="rtl|ltr"`. Use logical
CSS properties (`margin-inline-start`, `inset-inline-end`, …) so RTL works automatically.

**CSS custom properties (tokens)** — defined on `:root` / `[data-theme]` in app.css; module CSS must use
these instead of hard-coded colours:
`--bg` `--bg-elev` `--surface` `--surface-2` `--surface-3` `--glass` `--border` `--border-strong`
`--text` `--text-muted` `--text-faint` `--gold` `--gold-2` `--gold-soft` (translucent gold) `--gold-grad`
(gradient) `--accent` `--green` `--red` `--blue` `--violet` `--amber` `--green-soft` `--red-soft`
`--blue-soft` `--violet-soft` `--focus` `--radius-sm` `--radius` `--radius-lg` `--radius-xl`
`--shadow-sm` `--shadow` `--shadow-lg` `--font-display` `--font-body` `--font-mono` `--ease`
`--dur` `--header-h` `--container` `--gap` `--s1`…`--s8` (spacing scale 4,8,12,16,24,32,48,64 px)
and `--heat-0`…`--heat-10` (score colour ramp). Chart colours come from `WB.palette(i)` / `WB.colors()`.

**Class vocabulary** (implemented in app.css; pages must use these):
* Layout: `container` `container-wide` `container-narrow` `section` `section-head` `eyebrow`
  `section-title` `section-sub` `grid` `grid-2` `grid-3` `grid-4` `stack` `stack-sm` `stack-lg`
  `cluster` `split` `sidebar-layout`
* Surfaces: `card` `card-pad` `card-glass` `card-hover` `card-head` `card-title` `card-body` `card-foot` `panel`
* Type: `t-display` `t-h1` `t-h2` `t-h3` `t-lead` `t-muted` `t-small` `t-xs` `t-mono` `t-num` `t-gold`
  (gold gradient text) `t-center` `t-balance`
* Buttons: `btn` `btn-primary` `btn-ghost` `btn-outline` `btn-danger` `btn-sm` `btn-lg` `btn-icon` `btn-block`
* Forms: `form` `field` `label` `input` `select` `textarea` `checkbox` `switch` `help` `form-row`
  `form-actions` `range` `input-group`
* Tables: `table-wrap` `table` `table-compact` `th[data-sort]` (sortable) `td.num` `tr.is-provisional`
  `tr.is-highlight`
* Badges: `badge` + `badge-gold|green|red|blue|violet|muted`, `chip` `chip.active` `pill`
* Data: `score score-sm|md|lg|xl` `score-ci` `scorebar` `scorebar-fill` `scorebar-ci` (uses `--bar`)
  `heat heat-0…heat-10 heat-na` `stat` `stat-value` `stat-label` `stat-hint` `stat-icon` `kpi-row`
  `rank rank-1|2|3` `delta delta-up|down|flat` `difficulty` `track-badge` (uses `--track`)
  `avatar` (uses `--av`, `--size`) `model-chip` `legend` `legend-item` `chart-box` (fixed-height chart container)
* Nav/chrome: `site-header` `nav` `nav-link` `.active` `lang-switch` `theme-toggle` `site-footer`
  `breadcrumbs` `tabs` `tab` `.active` (+ `[data-tabs]` / `[data-tab-panel]`), `pagination` `page`
* Feedback: `alert alert-success|error|warn|info` `empty-state` `skeleton` `spinner` `progress`
  `progress-bar` `toast` (JS) `[data-tip]` tooltips `kbd` `divider`
* Content: `prose` (long-form text), `code-block` (pre), `render-frame` (iframe shell with
  `render-toolbar`), `device-toggle`, `hero`, `hero-art`, `reveal` (scroll-in animation)
* Admin: `admin-shell` `admin-sidebar` `admin-main` `admin-topbar` `admin-nav-link`

**JS (`assets/js/app.js`, global `window.WB`):** `WB.fetchJSON(url, {method, body})` (adds CSRF +
JSON), `WB.toast(msg, type)`, `WB.t(key)` (strings from `<script type="application/json" id="wb-i18n">`),
`WB.colors()` (resolved CSS tokens), `WB.palette(i)` (categorical colours), `WB.chart(canvas, config)`
(Chart.js with theme defaults; requires `vendor/chart.umd.min.js` in `$page_js`),
`WB.debounce(fn, ms)`, `WB.fmt.score(n)`, `WB.fmt.money(n)`. Declarative behaviours:
`[data-theme-toggle]`, `[data-copy="text"]`, `table[data-sortable]`, `[data-filter-input="#tableId"]`,
`[data-tabs]`, `[data-countup]`, `.reveal`, `[data-confirm="message"]` (forms & buttons),
`[data-nav-toggle]`, `[data-autosubmit]` (select change submits form).
Additions (implemented): `WB.chart(canvas, config | (colors) => config)` — pass a factory so colours
re-theme on `wb:themechange`; `WB.palette(i ≥ 8)` returns a neutral colour (fold extra series into
"Other"); `WB.fetchJSON` rejects non-2xx with an Error carrying `.status` and `.data`; extra helpers
`WB.csrf() isRTL() lang() theme() setTheme() reducedMotion() on() copy() icon() sortTable()`; declarative
`[data-copy-from="#sel"]`, `[data-dismiss]`, `.device-toggle [data-device]`, `canvas[data-hero-art]`,
`th[data-sort-initial]`, `tr[data-sort-fixed]`, `[data-filter-item]`, `[data-filter-empty]`; events
`wb:themechange`, `wb:sorted`, `wb:filtered`, `wb:tab`. Extra PHP UI helpers: `ui_logo_mark`, `ui_brand`,
`ui_em` (*word* → gold italic), `ui_heat_mini`, `ui_section_head`, `ui_live_dot`. `/` and `/styleguide`
live in `app/routes/10_home.php`.

**Icons:** sprite `assets/img/icons.svg`, `<symbol id="i-<name>">`, 24×24, stroke `currentColor`.
Required names: trophy chart arena cpu compass book flask code eye check x alert arrow-up
arrow-down arrow-right arrow-left external download copy sun moon globe menu close search filter
star shield bolt cube gamepad palette bug robot brain lock accessibility type layers clock coin
gauge sparkles settings users play pause refresh trash edit plus logout image list grid info link
github x-social target scale timer database server key mail calendar file terminal wand medal
crown fire heart thumbs-up equal dice api badge history.

## 8. Public pages (module: public) — routes in app/routes/public.php

| Route | View | Notes |
|---|---|---|
| `/` | pages/home | hero, live KPIs, top-10 with CI bars, why-we're-more-accurate, tracks grid, arena teaser, value teaser, changelog |
| `/leaderboard` | pages/leaderboard | full table, track filter (`?track=`), provider/open-weights filters, sort, heatmap of tracks, CSV/JSON links, statistical-tie shading |
| `/model/{slug}` | pages/model | score card, radar by track, history chart, per-task table, outputs gallery, cost/latency, judge agreement |
| `/compare` | pages/compare | `?m=slug1,slug2,…` up to 4; radar overlay, per-track bars, head-to-head per task |
| `/tasks` `/task/{slug}` | pages/tasks, pages/task | public tasks only; holdout count shown, prompts hidden |
| `/run/{id}` | pages/run | output in sandboxed iframe, code, checks, judges, cost (public tasks only) |
| `/methodology` | pages/methodology | the full scoring method (from this doc), FAQ |
| `/value` | pages/value | price-performance scatter + Pareto frontier |
| `/changelog` `/about` `/request` | … | request-a-model form (rate-limited) |
Data access helpers in `app/data/` (`WB\Data\Leaderboard`, `WB\Data\Models` …).

## 9. Arena & tools (module: arena / tools)

* `/arena` blind pairwise duel of two rendered outputs for the same public task
  (`/arena/frame/{token}/{a|b}` → `wb_render_document`), vote `a|b|tie|both_bad` (CSRF + rate limit
  `arena_votes_per_hour`), reveal after vote. `/arena/leaderboard` Bradley–Terry (MM algorithm, ties =
  half win, both_bad = tie) scaled to Elo-like `1000 + 400·log10`, bootstrap CI (200 resamples).
* `/hardware` Local Fit Lab: pick an open-weight model (or enter params), quantization (FP16, Q8, Q6,
  Q5, Q4, Q3), context length, GPU(s) from `gpus` (multi-GPU, Apple unified memory, CPU RAM) →
  memory needed (weights + KV cache + overhead), verdict, est. tokens/s (bandwidth ÷ bytes per token),
  and "best benchmarked models that fit your machine".
* `/advisor` custom weighting: sliders per track, budget per task, open-weights only, min context →
  personal ranking computed client-side, shareable URL.
* Public API (if `api_public`): `/api/v1/leaderboard[?track=&season=]`, `/api/v1/models`,
  `/api/v1/models/{slug}`, `/api/v1/tracks`, `/api/v1/tasks`, `/api/v1/arena`; `/export/leaderboard.csv`;
  docs page `/api-docs`. CORS `*`, rate limit 120/min/IP.
* `/badge/{slug}.svg` embeddable badge; `/embed/leaderboard` compact widget (frame-ancestors *).
* `/sitemap.xml`, `/robots.txt`, JSON-LD (`Dataset` on leaderboard).

## 10. Admin (module: admin) — `/admin/*`, layout `admin`

login/logout (rate limited) · dashboard (queue counts, spend, recent failures, worker heartbeat) ·
models (OpenRouter catalog sync with search, enable/disable, judge flag + order, edit metadata incl.
open weights/params for the Fit Lab) · tracks (names, colours, auto weight) · tasks (CRUD with JSON
validation + preview, import/export JSON) · Task Forge (LLM drafts new tasks → review → activate as
hold-out) · campaigns (create with model/task pickers + live cost estimate, list, progress, pause/
resume/cancel, include-in-scores toggle) · console (`/admin/console`: runs `POST /admin/api/step` in a
loop **and** embeds the Verification Station so one open tab drives everything) · runs explorer
(filter, retry failed, view details) · settings (site, season, judging, verification, arena, API key
update → `Config::write`) · Brand Studio (image generation via OpenRouter, gallery, set active logo/
hero/og) · requests · changelog · API tokens (create/revoke verifier tokens) · recompute scores.

## 11. Setup, upgrades and route ownership (implemented)

* **Hold-out policy**: `sql/seed/tasks/*.json` is public by definition (the repository is public). Hold-out tasks
  exist only in a site's database: create them in the admin with the Task Forge (draft → review → activate as
  hold-out) or the task editor (visibility *Hold-out*); never export them into `sql/seed/` or commit them. The
  Seeder only upserts the slugs it ships, so it never touches them. See docs/DEPLOY.md §8.
* Installer `install/index.php` → `WB\Setup\Wizard` / `Installer`; refuses when `install/install.lock`
  or a working `config/config.local.php` exists. Step 1 asks for the **setup token**: a one-time file
  `storage/cache/setup-token.txt` (32 hex, created on the first visit by `Installer::ensureSetupToken()`, checked by
  `setupTokenValid()`, deleted after installation) proves the visitor controls the hosting account, so nobody can
  take over a freshly uploaded site. Writes config via `Config::write` (fresh `app_secret`,
  `cron_key`), runs `WB\Setup\Migrator::run()` (sql/schema.sql, sql/schema-*.sql, then
  `sql/migrations/NNN_*.sql` once each, then the one-off PHP upgrade steps `Migrator::STEPS` — e.g.
  `shots_unguessable`, which renames legacy guessable screenshot files — recorded in `schema_version.steps`;
  `Migrator::status()` reports `pending_steps`, and `needs_upgrade` includes them) and
  `WB\Setup\Seeder::run($updateExisting = false)`
  (tracks, `sql/seed/tasks/*.json`, `sql/seed/gpus.json`; idempotent upserts by slug/name; admin-edited
  rows are kept unless `$updateExisting`).
* Admin JSON (module setup, `app/routes/80_setup.php`): `POST /admin/api/migrate`,
  `POST /admin/api/seed-sync {update_existing}`, `GET /admin/api/setup-status`.
  Settings written: `schema_version` (object), `seed_hashes`, `seed_last_sync`, `installed_at`,
  `installed_version`.
* `app/routes/70_tools.php` owns every `/api/v1/*` route including an OPTIONS catch-all and a GET 404
  catch-all — any future `/api/v1` route must live there (or in a route file that sorts before it).
* **Sessions are lazy** (`Auth::wantsSession($path)`, called by the bootstrap), so crawlers, cookieless visitors and
  bot 404s never create session files (inode quota on shared hosting):
  * always stateless (`Auth::STATELESS_RE`, even with a cookie): `/api/v1/*`, `/badge/*`, `/embed/*`, `/export/*`,
    `/sitemap.xml`, `/robots.txt`, `/healthz`, `/site.webmanifest`, `/worker/tick`;
  * a client that already holds the `wb_sess` cookie keeps its session everywhere else;
  * without the cookie a session starts only on `/admin*`, `/install*`, `/request`, `/arena`, `/arena/next` and
    `/arena/vote` (not for crawlers on `/arena`, which get the page without a pair); `/render/*`, `/arena/frame/*` and
    `/api/verifier/*` never start one.
  * Caching: session-less GET/HEAD pages send `Cache-Control: public, max-age=60` + `Vary: Cookie, Accept-Language`
    (`Http::publicCacheHeaders`; `private` for `?lang=` switches and `/arena`); non-admin pages with a session send
    `private, no-cache` (`Http::sessionCacheHeaders`). `abort()` sends `no-store` for codes other than 404.
  * The CSRF `<meta>` is only rendered when a session exists.
* Admin sessions carry a password stamp (`Auth::passwordStamp(pass_hash)`): changing a password signs out every other
  session of that admin (`Auth::refreshPasswordStamp`), removing an admin ends theirs; 12 h idle timeout.
* Arena settings: `arena_last_recompute`, `arena_votes_at_recompute`; `Arena::maybeRecompute()` is
  called by the Worker on each step and after votes.
* Writing config from the admin: pass `Config::write()` the **raw file array** merged with changes
  (`WB\Admin\Admin::rawConfig()/writeConfig()`), never `Config::all()` — it contains env overrides
  (e.g. `WB_DB_NAME`) that must not be persisted.
* Brand Studio settings: `brand_logo`, `brand_wordmark`, `brand_hero`, `brand_og` (relative upload paths,
  '' when unset).
* Stored run payload shapes: `runs.static_json` = list of check results; `runs.browser_json` =
  `{checks, tests, errors, metrics, viewports?}`; `runs.agent_json` = the AgentSim result
  (`transcript, final_answer, goal_results, steps, end_reason, protocol, …`).
* RTL: values mixing digits and Latin units (`46.6 s`, `$3/M`) must be bidi-isolated
  (`<span dir="ltr">` / `unicode-bidi: isolate`).

* Verification Station (`assets/js/verifier.js`): `onState(state)` reports `idle | running | paused | stopping`.
  *paused* = tab hidden, window minimised or container not rendered → no claims and no frame starts. The live
  frame docks into a fixed corner viewer (CSS only, never re-parented) when its lane is < 60 % in view. A
  viewport measured while hidden/off-screen is re-measured locally (≤ 2×) while the lease is held. Memory
  failures (allocation errors, `metrics.oom`, `test.oom`) destroy the frame, wait 6 s and release the run
  while `attempt < max_attempts`; on the last try the run is submitted as the output's failure with
  `metrics.oom = 1` — the same rule as `tools/verifier-node/verify.mjs`.
