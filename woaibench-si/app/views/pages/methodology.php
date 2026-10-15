<?php
/**
 * Methodology (long-form). Vars: judges (models WHERE is_judge=1), tracks, counts.
 * Numbers are live settings so the page always matches how the engine actually runs.
 */
defined('WB') || exit;

$n = [
    'runs' => (int) setting('runs_per_task', 3),
    'judges' => (int) setting('judge_count', 3),
    'spread' => fmt_num(setting('judge_tiebreak_spread', 25)),
    'iters' => fmt_num(setting('bootstrap_iters', 1000)),
    'cov' => fmt_num((float) setting('min_coverage', 0.8) * 100),
    'factor' => (string) setting('render_fail_factor', 0.5),
    'temp' => (string) setting('judge_temperature', 0),
    'tracks' => count($tracks),
    'public' => $counts['public'],
    'holdout' => $counts['holdout'],
];
$toc = [
    'overview' => 'pub.meth.overview.h', 'tasks' => 'pub.meth.tasks.h', 'attempts' => 'pub.meth.attempts.h',
    'scoring' => 'pub.meth.scoring.h', 'judges' => 'pub.meth.judges.h', 'browser' => 'pub.meth.browser.h',
    'deterministic' => 'pub.meth.det.h', 'aggregation' => 'pub.meth.agg.h', 'ci' => 'pub.meth.ci.h',
    'rank' => 'pub.meth.rank.h', 'contamination' => 'pub.meth.cont.h', 'failures' => 'pub.meth.fail.h',
    'cost' => 'pub.meth.cost.h', 'example' => 'pub.meth.ex.h', 'limitations' => 'pub.meth.lim.h',
    'versioning' => 'pub.meth.ver.h', 'faq' => 'pub.meth.faq.h',
];
$p = static fn(string $k) => '<p>' . pub_rich($k, $n) . '</p>';
$verifyMode = (string) setting('verify_mode', 'required');
?>
<section class="section pub-hero pub-meth-hero">
  <div class="container stack-sm">
    <p class="eyebrow"><?= _e('pub.meth.eyebrow') ?></p>
    <h1 class="t-display t-balance"><?= _e('pub.meth.h1') ?></h1>
    <p class="t-lead t-muted pub-lead"><?= _e('pub.meth.lead', $n) ?></p>
    <div class="kpi-row pub-meth-kpis">
      <?= ui_stat((string) $n['runs'], __('pub.meth.kpi_runs'), 'dice') ?>
      <?= ui_stat((string) $n['judges'], __('pub.meth.kpi_judges'), 'scale') ?>
      <?= ui_stat($n['iters'], __('pub.meth.kpi_iters'), 'sigma') ?>
      <?= ui_stat((string) $n['tracks'], __('pub.meth.kpi_tracks'), 'layers') ?>
    </div>
  </div>
</section>

<section class="section pub-section-tight">
  <div class="container pub-meth-layout">
    <nav class="pub-toc" aria-label="<?= _e('pub.meth.toc') ?>">
      <p class="eyebrow"><?= _e('pub.meth.toc') ?></p>
      <ol>
        <?php foreach ($toc as $id => $k): ?><li><a href="#<?= e($id) ?>"><?= _e($k) ?></a></li><?php endforeach; ?>
      </ol>
    </nav>

    <article class="prose pub-prose">
      <h2 id="overview"><?= _e('pub.meth.overview.h') ?></h2>
      <?= $p('pub.meth.overview.p1') ?>
      <?= $p('pub.meth.overview.p2') ?>
      <div class="pub-track-table not-prose">
        <?php foreach ($tracks as $t): ?>
          <div class="pub-track-row">
            <?= ui_track_badge($t, true) ?>
            <span class="t-small t-muted"><?= e(L($t, 'desc')) ?></span>
            <span class="t-xs t-num pub-track-aw" data-tip="<?= _e('pub.meth.aw_tip') ?>"><?= _e('pub.meth.aw', ['a' => fmt_num((float) $t['auto_weight'] * 100)]) ?></span>
          </div>
        <?php endforeach; ?>
      </div>

      <h2 id="tasks"><?= _e('pub.meth.tasks.h') ?></h2>
      <?= $p('pub.meth.tasks.p1') ?>
      <ul>
        <li><?= pub_rich('pub.meth.tasks.l1') ?></li>
        <li><?= pub_rich('pub.meth.tasks.l2') ?></li>
        <li><?= pub_rich('pub.meth.tasks.l3') ?></li>
        <li><?= pub_rich('pub.meth.tasks.l4') ?></li>
      </ul>
      <?= $p('pub.meth.tasks.p2') ?>

      <h2 id="attempts"><?= _e('pub.meth.attempts.h') ?></h2>
      <?= $p('pub.meth.attempts.p1') ?>
      <?= $p('pub.meth.attempts.p2') ?>

      <h2 id="scoring"><?= _e('pub.meth.scoring.h') ?></h2>
      <?= $p('pub.meth.scoring.p1') ?>
      <div class="pub-formulas not-prose" dir="ltr">
        <div class="pub-eq"><span class="pub-eq-l">auto</span><span>=</span><span>100 × Σ w<sub>i</sub>·s<sub>i</sub> / Σ w<sub>i</sub></span></div>
        <div class="pub-eq"><span class="pub-eq-l">judge<sub>k</sub></span><span>=</span><span>10 × Σ w<sub>c</sub>·crit<sub>c</sub> / Σ w<sub>c</sub></span></div>
        <div class="pub-eq"><span class="pub-eq-l">judge</span><span>=</span><span>median(judge<sub>1</sub> … judge<sub>k</sub>)</span></div>
        <div class="pub-eq is-key"><span class="pub-eq-l">final</span><span>=</span><span>a · auto + (1 − a) · judge</span></div>
      </div>
      <?= $p('pub.meth.scoring.p2') ?>
      <?= $p('pub.meth.scoring.p3') ?>

      <h2 id="judges"><?= _e('pub.meth.judges.h') ?></h2>
      <?= $p('pub.meth.judges.p1') ?>
      <ol class="pub-anchors not-prose">
        <?php foreach (['0–2', '3–4', '5–6', '7–8', '9–10'] as $i => $band): ?>
          <li class="pub-anchor a<?= $i ?>"><span class="pub-anchor-band t-num" dir="ltr"><?= e($band) ?></span><span><?= pub_rich('pub.meth.judges.a' . $i) ?></span></li>
        <?php endforeach; ?>
      </ol>
      <?= $p('pub.meth.judges.p2') ?>
      <?= $p('pub.meth.judges.p3') ?>
      <div class="pub-judges not-prose">
        <p class="t-xs t-muted"><?= _e('pub.meth.judges.current') ?></p>
        <?php if ($judges): ?>
          <ol class="pub-judge-list">
            <?php foreach ($judges as $j): ?><li><?= ui_model_chip($j, false) ?><?php if ($j['supports_vision']): ?> <?= ui_badge(__('pub.model.vision'), 'blue', 'eye') ?><?php endif; ?></li><?php endforeach; ?>
          </ol>
        <?php else: ?>
          <p class="t-small t-muted"><?= _e('pub.meth.judges.none') ?></p>
        <?php endif; ?>
      </div>

      <h2 id="browser"><?= _e('pub.meth.browser.h') ?></h2>
      <?= $p('pub.meth.browser.p1') ?>
      <ul class="pub-cols-2">
        <?php foreach (['loads', 'no_errors', 'nonblank', 'animates', 'fps', 'responsive', 'keyboard', 'click', 'rtl', 'a11y_basic', 'canvas'] as $c): ?>
          <li><code><?= e($c) ?></code> — <?= _e('pub.meth.browser.c_' . $c) ?></li>
        <?php endforeach; ?>
      </ul>
      <?= $p('pub.meth.browser.p2') ?>
      <p class="t-small t-muted"><?= _e('pub.meth.browser.mode_' . (in_array($verifyMode, ['required', 'optional', 'off'], true) ? $verifyMode : 'required')) ?></p>

      <h2 id="deterministic"><?= _e('pub.meth.det.h') ?></h2>
      <?= $p('pub.meth.det.p1') ?>
      <ul>
        <li><?= pub_rich('pub.meth.det.l1') ?></li>
        <li><?= pub_rich('pub.meth.det.l2') ?></li>
        <li><?= pub_rich('pub.meth.det.l3') ?></li>
      </ul>

      <h2 id="aggregation"><?= _e('pub.meth.agg.h') ?></h2>
      <?= $p('pub.meth.agg.p1') ?>
      <div class="pub-formulas not-prose" dir="ltr">
        <div class="pub-eq"><span class="pub-eq-l">task</span><span>=</span><span>mean(final over attempts)</span></div>
        <div class="pub-eq"><span class="pub-eq-l">track</span><span>=</span><span>mean(task over tasks in the track)</span></div>
        <div class="pub-eq is-key"><span class="pub-eq-l">overall</span><span>=</span><span>mean(track over <?= (int) $n['tracks'] ?> tracks)</span></div>
        <div class="pub-eq"><span class="pub-eq-l">consistency</span><span>=</span><span>100 − mean(sd of attempts)</span></div>
      </div>
      <?= $p('pub.meth.agg.p2') ?>

      <h2 id="ci"><?= _e('pub.meth.ci.h') ?></h2>
      <?= $p('pub.meth.ci.p1') ?>
      <?= $p('pub.meth.ci.p2') ?>

      <h2 id="rank"><?= _e('pub.meth.rank.h') ?></h2>
      <?= $p('pub.meth.rank.p1') ?>
      <div class="pub-ci-demo not-prose" role="img" aria-label="<?= _e('pub.meth.rank.demo_aria') ?>">
        <?php foreach ([['A', 78, 81.5, 84], ['B', 76.5, 79.8, 83], ['C', 70, 72.6, 75]] as [$lbl, $l, $s, $h]): ?>
          <div class="pub-ci-row"><span class="pub-ci-name t-mono"><?= e($lbl) ?></span><span class="pub-ci-track"><span class="pub-ci-range" style="inset-inline-start:<?= ($l - 60) * 4 ?>%;width:<?= ($h - $l) * 4 ?>%"></span><span class="pub-ci-point" style="inset-inline-start:<?= ($s - 60) * 4 ?>%"></span></span><span class="t-num t-xs t-muted" dir="ltr"><?= e(number_format($s, 1)) ?> [<?= e(number_format($l, 1)) ?>–<?= e(number_format($h, 1)) ?>]</span></div>
        <?php endforeach; ?>
      </div>
      <?= $p('pub.meth.rank.p2') ?>
      <?= $p('pub.meth.rank.p3') ?>

      <h2 id="contamination"><?= _e('pub.meth.cont.h') ?></h2>
      <?= $p('pub.meth.cont.p1') ?>
      <?= $p('pub.meth.cont.p2') ?>

      <h2 id="failures"><?= _e('pub.meth.fail.h') ?></h2>
      <?= $p('pub.meth.fail.p1') ?>
      <?= $p('pub.meth.fail.p2') ?>

      <h2 id="cost"><?= _e('pub.meth.cost.h') ?></h2>
      <?= $p('pub.meth.cost.p1') ?>
      <?= $p('pub.meth.cost.p2') ?>

      <h2 id="example"><?= _e('pub.meth.ex.h') ?></h2>
      <?= $p('pub.meth.ex.p1') ?>
      <div class="pub-example not-prose card card-pad">
        <ol class="pub-example-steps">
          <li><span><?= _e('pub.meth.ex.s1') ?></span><code dir="ltr">auto = 80.0</code></li>
          <li><span><?= _e('pub.meth.ex.s2') ?></span><code dir="ltr">median(62, 70, 66) = 66.0</code></li>
          <li><span><?= _e('pub.meth.ex.s3') ?></span><code dir="ltr">0.35 × 80.0 + 0.65 × 66.0 = 70.9</code></li>
          <li><span><?= _e('pub.meth.ex.s4') ?></span><code dir="ltr">mean(70.9, 64.2, 73.5) = 69.5 · sd 4.8</code></li>
          <li><span><?= _e('pub.meth.ex.s5') ?></span><code dir="ltr">mean(69.5, 74.1, 58.3) = 67.3</code></li>
          <li><span><?= _e('pub.meth.ex.s6') ?></span><code dir="ltr">67.3 [64.8 – 69.9]</code></li>
        </ol>
      </div>
      <?= $p('pub.meth.ex.p2') ?>

      <h2 id="limitations"><?= _e('pub.meth.lim.h') ?></h2>
      <ul>
        <?php for ($i = 1; $i <= 6; $i++): ?><li><?= pub_rich('pub.meth.lim.l' . $i, $n) ?></li><?php endfor; ?>
      </ul>

      <h2 id="versioning"><?= _e('pub.meth.ver.h') ?></h2>
      <?= $p('pub.meth.ver.p1') ?>
      <p><a href="<?= e(url('/changelog')) ?>"><?= _e('pub.meth.ver.link') ?></a></p>

      <h2 id="faq"><?= _e('pub.meth.faq.h') ?></h2>
      <div class="pub-faq not-prose">
        <?php for ($i = 1; $i <= 9; $i++): ?>
          <details class="card pub-faq-item">
            <summary><?= _e('pub.meth.faq.q' . $i) ?></summary>
            <p><?= pub_rich('pub.meth.faq.a' . $i, $n) ?></p>
          </details>
        <?php endfor; ?>
      </div>
    </article>
  </div>
</section>
