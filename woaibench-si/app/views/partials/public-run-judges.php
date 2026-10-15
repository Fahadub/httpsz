<?php
/**
 * Run page — judge panel. Vars: judgments (Runs::judgments), run.
 * Rationales are model text: always escaped.
 */
defined('WB') || exit;

$scores = array_map(static fn($j) => (float) $j['score'], array_filter($judgments, static fn($j) => $j['score'] !== null));
?>
<div class="stack">
  <div class="card card-pad pub-judge-summary">
    <div class="pub-judge-kpis">
      <?= ui_stat(fmt_score($run['judge_score']), __('pub.run.judge_median'), 'scale') ?>
      <?= ui_stat(fmt_score($run['judge_spread']), __('pub.run.spread'), 'activity', __('pub.run.spread_hint', ['n' => setting('judge_tiebreak_spread')])) ?>
      <?= ui_stat((string) count($judgments), __('pub.run.judges_n'), 'users') ?>
    </div>
    <?php if ($scores): ?>
    <div class="pub-judge-strip" role="img" aria-label="<?= _e('pub.run.strip_aria') ?>">
      <span class="pub-judge-axis"></span>
      <?php foreach ($judgments as $j): if ($j['score'] === null) { continue; } ?>
        <span class="pub-judge-dot" style="inset-inline-start:<?= e(number_format(clamp((float) $j['score'], 0, 100), 2, '.', '')) ?>%" data-tip="<?= e($j['name'] . ': ' . fmt_score($j['score'])) ?>"></span>
      <?php endforeach; ?>
      <?php if ($run['judge_score'] !== null): ?><span class="pub-judge-median" style="inset-inline-start:<?= e(number_format(clamp((float) $run['judge_score'], 0, 100), 2, '.', '')) ?>%"></span><?php endif; ?>
    </div>
    <?php endif; ?>
    <p class="t-xs t-muted"><?= _e('pub.run.judges_note') ?></p>
  </div>

  <?php foreach ($judgments as $j): $rp = $j['rationale_parsed']; ?>
  <article class="card card-pad stack-sm pub-judgment" aria-label="<?= e($j['name']) ?>">
    <h2 class="sr-only"><?= e($j['name']) ?></h2>
    <header class="pub-judgment-head">
      <?= ui_model_chip($j) ?>
      <span class="cluster">
        <?php if ($j['role'] !== 'panel'): ?><?= ui_badge(__('pub.run.tiebreak'), 'gold', 'scale', __('pub.run.tiebreak_tip')) ?><?php endif; ?>
        <?= (int) $j['used_vision'] ? ui_badge(__('pub.run.vision_used'), 'blue', 'eye', __('pub.run.vision_tip')) : ui_badge(__('pub.run.code_only'), 'muted', 'code') ?>
        <?= ui_score($j['score'], null, null, 'md') ?>
      </span>
    </header>
    <?php if ($j['criteria']): ?>
    <ul class="pub-criteria">
      <?php foreach ($j['criteria'] as $c): ?>
        <li><span class="pub-crit-label"><?= e($c['label']) ?></span><?= ui_scorebar($c['score'], 10) ?><span class="t-num t-small"><?= e(number_format($c['score'], 1)) ?></span></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php if ($rp['summary'] !== ''): ?><p class="pub-judge-summary-text"><?= nl2br(e(mb_substr($rp['summary'], 0, 4000))) ?></p><?php endif; ?>
    <?php if ($rp['strengths'] || $rp['weaknesses']): ?>
    <div class="grid grid-2 pub-sw-mini">
      <div><h3 class="t-xs pub-sw-h is-good"><?= ui_icon('thumbs-up', 13) ?> <?= _e('pub.run.strengths') ?></h3><ul><?php foreach (array_slice($rp['strengths'], 0, 12) as $s): ?><li><?= e(mb_substr($s, 0, 600)) ?></li><?php endforeach; ?></ul></div>
      <div><h3 class="t-xs pub-sw-h is-bad"><?= ui_icon('alert', 13) ?> <?= _e('pub.run.weaknesses') ?></h3><ul><?php foreach (array_slice($rp['weaknesses'], 0, 12) as $s): ?><li><?= e(mb_substr($s, 0, 600)) ?></li><?php endforeach; ?></ul></div>
    </div>
    <?php endif; ?>
  </article>
  <?php endforeach; ?>
</div>
