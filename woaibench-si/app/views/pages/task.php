<?php
/**
 * Public task page. Vars: task, rubric, checks, grading, auto_weight, results, gallery.
 */
defined('WB') || exit;

$t = $task;
$track = ['slug' => $t['track_slug'], 'name_ar' => $t['name_ar'], 'name_en' => $t['name_en'], 'icon' => $t['icon'], 'color' => $t['color']];
$hasRubric = (bool) $rubric && $auto_weight < 1.0;
$groupIcon = ['static' => 'list', 'browser' => 'eye', 'tests' => 'check', 'answer' => 'target', 'agent' => 'robot'];
?>
<section class="section pub-hero" style="--track:<?= e($t['color']) ?>">
  <div class="container-wide stack">
    <nav class="breadcrumbs" aria-label="<?= _e('pub.breadcrumbs') ?>">
      <a href="<?= e(url('/tasks')) ?>"><?= _e('nav.tasks') ?></a>
      <a href="<?= e(url('/tasks', ['track' => $t['track_slug']])) ?>"><?= e(L($t, 'name')) ?></a>
      <span aria-current="page"><?= e(L($t, 'title')) ?></span>
    </nav>
    <div class="stack-sm">
      <div class="cluster pub-badges">
        <?= ui_track_badge($track, true) ?>
        <?= ui_difficulty((int) $t['difficulty']) ?>
        <?= ui_badge(__('pub.type.' . $t['output_type']), 'muted', pub_type_icon((string) $t['output_type'])) ?>
        <?php if (!(int) $t['is_active']): ?><?= ui_badge(__('pub.task.retired'), 'gold', 'history', __('pub.task.retired_tip')) ?><?php endif; ?>
        <span class="t-xs t-muted t-mono">v<?= (int) $t['version'] ?></span>
      </div>
      <h1 class="t-h1 t-balance"><?= e(L($t, 'title')) ?></h1>
      <?php if (L($t, 'summary') !== ''): ?><p class="t-lead t-muted pub-lead"><?= e(L($t, 'summary')) ?></p><?php endif; ?>
    </div>
  </div>
</section>

<section class="section pub-section-tight">
  <div class="container-wide pub-task-layout">
    <div class="stack-lg pub-task-main">
      <div class="card card-pad stack">
        <div class="card-head split">
          <h2 class="card-title"><?= ui_icon('terminal', 18) ?> <?= _e('pub.task.prompt') ?></h2>
          <button class="btn btn-ghost btn-sm" type="button" data-copy="<?= e($t['prompt']) ?>"><?= ui_icon('copy', 16) ?><span><?= _e('common.copy') ?></span></button>
        </div>
        <pre class="code-block pub-prompt" dir="auto" tabindex="0"><?= e($t['prompt']) ?></pre>
        <p class="t-xs t-muted"><?= _e('pub.task.prompt_note') ?></p>
      </div>

      <div class="card card-pad stack">
        <div class="card-head split">
          <h2 class="card-title"><?= ui_icon('trophy', 18) ?> <?= _e('pub.task.results') ?></h2>
          <span class="t-xs t-muted"><?= _e('pub.task.results_note') ?></span>
        </div>
        <?php if (!$results): ?>
          <?= ui_empty(__('pub.task.no_results'), __('pub.task.no_results_text'), 'flask') ?>
        <?php else: ?>
        <div class="table-wrap">
          <table class="table table-compact pub-results-table">
            <thead><tr>
              <th scope="col">#</th><th scope="col"><?= _e('common.model') ?></th><th scope="col"><?= _e('pub.mean') ?></th>
              <th class="num" scope="col"><?= _e('pub.attempts') ?></th>
              <th scope="col"><span data-tip="<?= _e('pub.split_tip') ?>"><?= _e('pub.split') ?></span></th>
              <th class="num" scope="col"><?= _e('common.cost') ?></th><th class="num" scope="col"><?= _e('common.latency') ?></th><th scope="col"><span class="sr-only"><?= _e('pub.best_run') ?></span></th>
            </tr></thead>
            <tbody>
            <?php foreach ($results as $i => $r): ?>
              <tr>
                <td><?= ui_rank($i + 1) ?></td>
                <td><?= ui_model_chip($r) ?></td>
                <td class="pub-result-score">
                  <span class="score score-sm"><span class="t-num"><?= e(fmt_score($r['mean_score'])) ?></span><?php if ($r['sd'] !== null): ?><span class="score-ci t-num" data-tip="<?= _e('pub.sd_tip') ?> · <?= _e('pub.range') ?> <?= e("\u{2066}" . fmt_score($r['min_score']) . '–' . fmt_score($r['max_score']) . "\u{2069}") ?>">±<?= e(fmt_score($r['sd'])) ?></span><?php endif; ?></span>
                  <?= ui_scorebar($r['mean_score']) ?>
                </td>
                <td class="num t-num"><?= (int) $r['attempts'] ?></td>
                <td><?= pub_split($r['auto_mean'], $r['judge_mean']) ?></td>
                <td class="num t-num"><span dir="ltr"><?= e(fmt_money($r['cost'])) ?></span></td>
                <td class="num t-num"><span dir="ltr"><?= e(fmt_ms($r['latency_ms'])) ?></span></td>
                <td><?php if ($r['best_run']): ?><a class="btn btn-icon btn-ghost btn-sm" href="<?= e(url('/run/' . $r['best_run'])) ?>" aria-label="<?= _e('pub.view_run') ?>" data-tip="<?= _e('pub.view_run') ?>"><?= ui_icon('arrow-right', 16) ?></a><?php endif; ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>

      <?php if ($gallery): ?>
      <div class="stack">
        <h2 class="t-h3"><?= ui_icon('image', 18) ?> <?= _e('pub.task.gallery') ?></h2>
        <?= partial('partials/public-gallery', ['items' => array_map(static fn($g) => [
            'run_id' => (int) $g['id'], 'shot_url' => $g['shot_url'], 'title' => $g['name'], 'score' => $g['final_score'],
            'sub' => $g['provider_name'] ?: $g['provider'], 'color' => $t['color'], 'icon' => $t['icon'], 'type' => $t['output_type'],
        ], $gallery)]) ?>
      </div>
      <?php endif; ?>
    </div>

    <div class="stack pub-task-aside">
      <div class="card card-pad stack-sm">
        <h2 class="card-title"><?= ui_icon('scale', 18) ?> <?= _e('pub.task.scoring') ?></h2>
        <div class="pub-formula" role="img" aria-label="<?= _e('pub.task.formula_aria', ['a' => fmt_num($auto_weight * 100), 'j' => fmt_num((1 - $auto_weight) * 100)]) ?>">
          <span class="pub-formula-auto" style="flex-grow:<?= e(number_format($auto_weight, 3, '.', '')) ?>"><?= _e('pub.split_auto') ?> <?= e(fmt_num($auto_weight * 100)) ?>%</span>
          <?php if ($auto_weight < 1): ?><span class="pub-formula-judge" style="flex-grow:<?= e(number_format(1 - $auto_weight, 3, '.', '')) ?>"><?= _e('pub.split_judge') ?> <?= e(fmt_num((1 - $auto_weight) * 100)) ?>%</span><?php endif; ?>
        </div>
        <ul class="pub-grading-list">
          <?php foreach ($grading as $g): ?><li><?= ui_icon(pub_grading_icon($g), 15) ?><span><?= _e('pub.grade.' . $g) ?></span></li><?php endforeach; ?>
        </ul>
        <a class="t-small" href="<?= e(url('/methodology') . '#scoring') ?>"><?= _e('pub.task.how_scoring') ?></a>
      </div>

      <?php if ($hasRubric): ?>
      <div class="card card-pad stack-sm">
        <h2 class="card-title"><?= ui_icon('list', 18) ?> <?= _e('pub.task.rubric') ?></h2>
        <ul class="pub-rubric">
          <?php foreach ($rubric as $c): ?>
            <li>
              <div class="split"><span class="pub-rubric-label"><?= e($c['label']) ?></span><span class="t-num t-small t-muted" data-tip="<?= _e('pub.task.weight_tip', ['w' => fmt_num($c['weight'], 1)]) ?>"><?= e(fmt_num($c['pct'])) ?>%</span></div>
              <span class="progress"><span class="progress-bar" style="width:<?= e(number_format($c['pct'], 2, '.', '')) ?>%"></span></span>
            </li>
          <?php endforeach; ?>
        </ul>
        <p class="t-xs t-muted pub-private-note"><?= ui_icon('lock', 13) ?> <span><?= _e('pub.task.guide_private') ?></span></p>
      </div>
      <?php endif; ?>

      <?php if ($checks): ?>
      <div class="card card-pad stack-sm">
        <h2 class="card-title"><?= ui_icon('check', 18) ?> <?= _e('pub.task.checks') ?></h2>
        <ul class="pub-checklist">
          <?php foreach ($checks as $c): ?>
            <li>
              <?= ui_icon($groupIcon[$c['group']] ?? 'check', 14) ?>
              <span class="pub-check-label"><?php
                if ($c['group'] === 'tests') { echo _e('pub.task.tests_n', ['n' => $c['count']]); }
                elseif ($c['group'] === 'answer') { echo _e('pub.task.answer_check', ['type' => I18n::has('pub.answer_type.' . $c['type']) ? __('pub.answer_type.' . $c['type']) : $c['type']]); }
                elseif ($c['group'] === 'agent') { echo _e('pub.task.agent_goals', ['n' => $c['count']]); }
                else { echo e($c['label']); }
              ?></span>
              <span class="pub-check-meta t-xs t-muted"><?= _e('pub.group.' . $c['group']) ?> · ×<?= e(fmt_num($c['weight'], $c['weight'] == floor($c['weight']) ? 0 : 1)) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>
    </div>
  </div>
</section>
