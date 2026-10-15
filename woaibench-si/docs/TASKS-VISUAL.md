# Visual-track tasks (ui · games · 3d · motion · dataviz)

25 public tasks, 5 per track, in `sql/seed/tasks/{ui,games,3d,motion,dataviz}.json` (ARCHITECTURE §5.1
format, imported by `WB\Setup\Seeder`). Each task has a hand-written oracle solution in
`tools/dev/references/<slug>.html` (marked `<!-- WB-ORACLE -->`, self-contained, no CDN, runs offline).
All brands, places and datasets are fictional or synthetic; nothing here is real benchmark data.

## Design rules used for every task

* **Every check is announced.** Any id, class or literal text that a check relies on is named in the
  prompt (the validator enforces this). Computed values (averages, regression, KPIs) are checked as text
  but never revealed; format examples use different numbers.
* **Same delivery rules everywhere.** One HTML file, no external resources (3D: three.js only from
  cdn.jsdelivr.net / unpkg.com / cdnjs.cloudflare.com / esm.sh, raw WebGL equally valid), works at
  1280×800 and 390×844, no console errors.
* **The sandbox is part of the contract.** Prompts state that storage, `alert()` and form submission are
  unavailable in the opaque-origin iframe. Chromium never fires `submit` in a frame without
  `allow-forms`, so form-based UIs must use button handlers + Enter — references do exactly that.
* **Graded where it is rendered.** 3D prompts warn that grading may run on software WebGL; references
  pick MSAA only on real GPUs, use dynamic resolution, culling or particle budgets, and hold ≥ 20 fps
  in headless SwiftShader. fps thresholds are 20 for 3D and 50 for 2D games/animation.
* **Weights:** "free" checks (loads, parses, single file, size) are light; task-specific checks carry the
  weight, so the trivial page `<html><body>Hello</body></html>` stays under ~35 auto points
  (before the 0.5 render-fail factor).

## Tasks — intent and what separates a 9/10 from a 6/10

| Slug | Diff | Intent | 9/10 vs 6/10 |
|---|---|---|---|
| ui-pricing-plans | 2 | Pricing page, annual default, exact prices | 9: premium typographic system, exact $7.50/$20/$49.17, accessible switch, table usable at 390 px. 6: template look, prices right but layout jumps or table overflows. |
| ui-ops-dashboard | 3 | KPIs computed from CSV, SVG bars, sortable/filterable table, drawer | 9: correct 1,142 / 86 / 36.1% / 5, numeric sorts with aria-sort, combined filters, calm data-dense design. 6: hard-coded or lexicographic sorting, generic admin look. |
| ui-checkout-flow | 3 | 4-step checkout, Luhn/expiry validation, VAT maths, a11y | 9: aria-invalid + describedby + focus to first error + live region, caret-safe card formatting. 6: validation only on submit, alerts, no focus management. |
| ui-command-palette | 4 | ⌘K palette: ranked fuzzy search, combobox semantics, focus trap | 9: ranking exactly as specified, `<mark>` highlights, recents, focus restored, commands really act. 6: substring filter, no aria-activedescendant, focus lost. |
| ui-kanban-board | 4 | Pointer-events DnD + full keyboard alternative + WIP limits | 9: placeholder + FLIP animations, touch handle, announced keyboard moves, over-limit state. 6: HTML5 DnD only (fails on touch), no keyboard path. |
| games-crate-pusher | 2 | Sokoban with 3 given (solver-verified) levels, undo, queue | 9: exact rules, undo restores counters, queued slide animation, crisp scaling. 6: input dropped during animation, void drawn as floor. |
| games-falling-blocks | 3 | Modern falling-block rules (7-bag, SRS kicks, hold, lock delay, DAS/ARR) | 9: guideline feel, gravity formula, scoring × level. 6: frame-based gravity, no lock delay, OS key-repeat. |
| games-tile-platformer | 3 | Given 80×15 map, fixed-timestep physics, coyote/buffer, enemies, checkpoints | 9: tuned jump arc (≥ 3.5 tiles), no tunnelling, stomp vs side hit, camera dead-zone. 6: floaty or frame-dependent physics, sticky walls. |
| games-orbital-slingshot | 4 | Exact softened gravity, 1/240 s symplectic Euler, preview = flight | 9: preview matches flight, warp keeps outcomes, all 3 levels solvable (validator brute-forces them). 6: explicit Euler drift, preview diverges. |
| games-tower-defense | 4 | Full TD: path from map, "first" targeting, frost splash/slow, 6 waves | 9: specified economy, homing projectiles, placement preview, winnable but not trivial (validator wins with a scripted build). 6: hitscan, missing slow/splash or broken sell maths. |
| 3d-product-turntable | 2 | Lamp configurator: PBR-ish shading, finishes, working light, contact shadow | 9: e-commerce quality render, smooth finish transitions, damped orbit + pinch. 6: flat shading, abrupt colour swaps, no shadow. |
| 3d-terrain-flyover | 3 | Seeded fBm terrain, biomes, water, fog, time of day, safe flight path | 9: natural landforms, horizon fog match, never clips, culling/dynamic res. 6: banded colours, camera through hills, per-frame rebuilds. |
| 3d-galaxy-particles | 3 | ≥ 100k GPU stars, persistent arms with differential rotation | 9: density-wave arms that survive time, single draw call, adaptive budget. 6: arms wind into a disc or JS updates 100k positions per frame. |
| 3d-puzzle-cube | 4 | 3×3×3 cube with correct face-turn maths, queue, solve detection | 9: (R U R′ U′)×6 returns to solved, eased queued turns, integer state. 6: wrong turn direction, float drift, dropped keys. |
| 3d-raymarched-sculpture | 4 | SDF scene: gyroid, smooth-min drops, soft shadows, AO, ACES | 9: artefact-free SDFs, penumbra shadows, bounding volumes + dynamic resolution. 6: hard shadows, banding, unplayable frame rate. |
| motion-weather-icons | 2 | Six SVG icons, one drawing system, seamless loops | 9: true family resemblance, seamless loops, pause/size/reduced motion. 6: mismatched strokes, visible loop jumps. |
| motion-logo-sequence | 3 | 6 s brand reveal with exact scrubbing/replay/speed | 9: choreography on the beats with overlap, frame-exact scrubbing both ways. 6: setTimeout chains that cannot be scrubbed, linear tweens. |
| motion-micro-interactions | 3 | Hand-written spring engine driving 4 components | 9: interruptible springs with velocity continuity, fling velocity, odometer digits. 6: CSS transitions labelled "spring", restarts from rest. |
| motion-metro-map | 4 | Schematic map with timetable-driven trains and arrival predictions | 9: timetable exact (validator cross-checks predictions against an independent model), clean labels, pan/zoom. 6: random train motion or predictions that disagree with trains. |
| motion-walk-cycle | 4 | Hierarchical SVG robot, IK walk cycle, follow-through, parallax | 9: no foot sliding, weight shift, squash & stretch jump, blended speeds. 6: limbs swinging on sine waves with sliding feet. |
| dataviz-ranked-bars | 2 | Ranked bars, computed average line, deltas, animated re-sort | 9: correct 0–100% scale, Average 44.3%, signed pp deltas, gliding re-sort. 6: truncated axis or unsorted bars. |
| dataviz-multiline-explorer | 3 | 4 series, null gaps, snapping crosshair, legend rescale, index mode | 9: real gaps (no zero drops), nice ticks, animated rescale, index maths. 6: interpolated gaps, static axis. |
| dataviz-scatter-regression | 3 | Bubble scatter with in-browser OLS, brushing, region filters | 9: exact y = 2.03x + 2.15, r² 0.81, recomputed on brush/filter (validator recomputes independently). 6: radius-proportional bubbles, stats not updated. |
| dataviz-sankey-energy | 4 | Library-free Sankey layout with ordering and unit switch | 9: common scale, stacked offsets, few crossings, 100.0 TWh ↔ 360.0 PJ. 6: ribbons overlapping at nodes, labels colliding. |
| dataviz-candlestick | 4 | OHLC candles, volume, SMA10, zoom/pan, range stats | 9: correct range stats per button (+13.92% / +13.33% / +15.55%), anchored zoom, clamped pan. 6: wrong change formula, zoom without limits. |

## Validation — `tools/dev/validate-visual.mjs`

```
cd <dir with node_modules/playwright>   # or set NODE_PATH
node /path/to/woaibench-si/tools/dev/validate-visual.mjs [track|slug …] [--probe] [--shots=DIR] [--json=FILE] [--verbose]
```

1. Structure: required fields, unique slugs/check ids, known check types, weights > 0, Arabic labels,
   4–6 rubric criteria, every id/class/text used by a check is named in the prompt.
2. Static checks re-implemented in JS (§5.1) **and** `app/eval/StaticChecks.php` via the php CLI.
3. Browser checks in Chromium at 1280×800 and 390×844, served with the same sandbox CSP as `/render`.
4. Functional assertions per task (the `EXTRAS` table): solving the Sokoban levels, Luhn rejection,
   fuzzy ranking, keyboard drag & drop, brute-forced orbital levels, a scripted tower-defense win,
   (R U R′ U′)×6, frame-exact scrubbing, independent OLS / timetable / range statistics, …
5. Discriminative power: the trivial page must score < 35 on every task.
6. `--probe`: injects the verifier's real `assets/js/probe.js` (+ the render.php pre-script) and requires
   every browser check to pass on the reference.

Exit code 0 only when everything passes.
