<?php
/**
 * Blind Arena duel. Vars: $state ready|no_data|disabled|rate_limited, $pair (payload or null),
 * $stats (Arena::stats()), $top (first arena leaderboard rows).
 */
defined('WB') || exit;

$votes = (int) ($stats['votes'] ?? 0);
$jsData = [
    'state' => $state,
    'pair' => $pair,
    'endpoints' => ['next' => url('/arena/next'), 'vote' => url('/arena/vote'), 'board' => url('/arena/leaderboard')],
    'i18n' => I18n::forJs(['arena.js_', 'arena.err_']),
    'icons' => asset('img/icons.svg'),
    'difficulty' => [1 => __('common.diff_easy'), 2 => __('common.diff_medium'), 3 => __('common.diff_hard'), 4 => __('common.diff_expert')],
];
$task = $pair['task'] ?? null;
$sides = ['a' => __('arena.letter_a'), 'b' => __('arena.letter_b')];
$buttons = [
    ['a', '1', 'arena.vote_a'],
    ['tie', '3', 'arena.vote_tie'],
    ['both_bad', '4', 'arena.vote_both_bad'],
    ['b', '2', 'arena.vote_b'],
];
?>
<section class="section arn-hero">
  <div class="container-wide">
    <div class="arn-hero-grid">
      <div class="stack">
        <span class="eyebrow"><?= ui_icon('arena', 16) ?> <?= _e('arena.eyebrow') ?></span>
        <h1 class="t-display t-balance"><?= _e('arena.headline') ?></h1>
        <p class="t-lead t-muted"><?= _e('arena.lead') ?></p>
        <div class="cluster">
          <a class="btn btn-ghost btn-sm" href="<?= e(url('/arena/leaderboard')) ?>"><?= ui_icon('trophy', 16) ?><span><?= _e('arena.see_leaderboard') ?></span></a>
          <a class="btn btn-ghost btn-sm" href="#arn-how"><?= ui_icon('info', 16) ?><span><?= _e('arena.how_title') ?></span></a>
        </div>
      </div>
      <div class="kpi-row arn-hero-stats">
        <?= ui_stat(fmt_num($votes), __('arena.stat_votes'), 'thumbs-up') ?>
        <?= ui_stat(fmt_num($stats['models'] ?? 0), __('arena.stat_models'), 'users') ?>
        <?= ui_stat(fmt_num($stats['votes_24h'] ?? 0), __('arena.stat_24h'), 'clock') ?>
      </div>
    </div>
  </div>
</section>

<section class="section arn-stage-section" aria-labelledby="arn-task-title">
  <div class="container-wide">
<?php if ($state === 'disabled' || $state === 'no_data'): ?>
    <div class="card card-pad arn-empty">
      <?= ui_empty(
          __($state === 'disabled' ? 'arena.disabled_title' : 'arena.no_data_title'),
          __($state === 'disabled' ? 'arena.disabled_text' : 'arena.no_data_text'),
          $state === 'disabled' ? 'pause' : 'arena',
          '<div class="cluster arn-empty-actions"><a class="btn btn-primary" href="' . e(url('/leaderboard')) . '">' . _e('nav.leaderboard') . '</a>'
          . '<a class="btn btn-ghost" href="' . e(url('/tasks')) . '">' . _e('nav.tasks') . '</a></div>'
      ) ?>
    </div>
<?php else: ?>
    <div class="arn-stage" id="arn-stage" data-state="<?= $pair ? 'voting' : 'loading' ?>" aria-live="polite">
      <div class="card card-glass arn-task">
        <div class="arn-task-meta cluster">
          <span class="arn-task-track"><?= $task ? ui_track_badge($task['track']) : '' ?></span>
          <span class="arn-task-diff"><?= $task ? ui_difficulty((int) $task['difficulty']) : '' ?></span>
          <span class="arn-kbd-hint t-xs t-muted"><?= ui_icon('bolt', 14) ?> <?= _e('arena.kbd_hint') ?></span>
        </div>
        <h2 class="t-h2 arn-task-title" id="arn-task-title"><?= $task ? e($task['title']) : '<span class="skeleton arn-skel-title"></span>' ?></h2>
        <p class="t-muted arn-task-summary"><?= $task ? e($task['summary']) : '<span class="skeleton arn-skel-line"></span>' ?></p>
      </div>

      <div class="arn-duel">
<?php foreach ($sides as $side => $letter): ?>
        <article class="arn-side" data-side="<?= $side ?>" aria-label="<?= _e('arena.output_label', ['x' => $letter]) ?>">
          <header class="render-toolbar arn-side-head">
            <span class="arn-letter" aria-hidden="true"><?= $letter ?></span>
            <span class="arn-side-label">
              <span class="arn-anon"><?= _e('arena.output_label', ['x' => $letter]) ?></span>
              <span class="arn-identity" hidden></span>
            </span>
            <span class="arn-side-actions">
              <button class="btn btn-ghost btn-icon btn-sm" type="button" data-reload aria-label="<?= _e('arena.reload') ?>" data-tip="<?= _e('arena.reload') ?>"><?= ui_icon('refresh', 16) ?></button>
              <a class="btn btn-ghost btn-icon btn-sm" data-open href="<?= e($pair['frames'][$side] ?? '#') ?>" target="_blank" rel="noopener noreferrer" aria-label="<?= _e('arena.open_full') ?>" data-tip="<?= _e('arena.open_full') ?>"><?= ui_icon('external', 16) ?></a>
            </span>
          </header>
          <div class="render-frame arn-frame">
            <iframe title="<?= _e('arena.output_label', ['x' => $letter]) ?>" sandbox="allow-scripts" referrerpolicy="no-referrer" loading="eager"<?= $pair ? ' src="' . e($pair['frames'][$side]) . '"' : '' ?>></iframe>
            <div class="arn-crown" aria-hidden="true"><?= ui_icon('crown', 22) ?></div>
          </div>
        </article>
<?php if ($side === 'a'): ?>
        <div class="arn-vs" aria-hidden="true"><span>VS</span></div>
<?php endif; ?>
<?php endforeach; ?>
      </div>

      <div class="arn-votebar" role="group" aria-label="<?= _e('arena.vote_group') ?>">
        <?php foreach ($buttons as [$value, $key, $label]): ?>
          <button class="btn <?= in_array($value, ['a', 'b'], true) ? 'btn-primary' : 'btn-outline' ?> arn-vote arn-vote-<?= e($value) ?>" type="button" data-vote="<?= e($value) ?>"<?= $pair ? '' : ' disabled' ?>>
            <kbd class="kbd"><?= e($key) ?></kbd><span><?= _e($label) ?></span>
          </button>
        <?php endforeach; ?>
      </div>

      <div class="card card-pad arn-result" hidden>
        <div class="arn-result-text">
          <span class="eyebrow arn-result-eyebrow"><?= ui_icon('sparkles', 14) ?> <?= _e('arena.reveal_eyebrow') ?></span>
          <p class="t-h3 arn-result-line"></p>
          <p class="t-small t-muted arn-result-sub"></p>
        </div>
        <div class="cluster arn-result-actions">
          <a class="btn btn-ghost btn-sm arn-task-link" href="#" hidden><?= ui_icon('list', 16) ?><span><?= _e('arena.see_task') ?></span></a>
          <button class="btn btn-primary btn-lg arn-next" type="button"><span><?= _e('arena.next') ?></span><?= ui_icon('arrow-right', 18, 'flip-rtl') ?><kbd class="kbd">N</kbd></button>
        </div>
      </div>
<?php if ($state === 'rate_limited'): ?>
      <div class="alert alert-warn" role="status"><?= _e('arena.err_rate_limited') ?></div>
<?php endif; ?>
    </div>
<?php endif; ?>
  </div>
</section>

<section class="section" id="arn-how">
  <div class="container-wide">
    <div class="grid grid-2 arn-info">
      <div class="card card-pad stack">
        <h2 class="t-h3"><?= _e('arena.how_title') ?></h2>
        <ol class="arn-steps">
          <li><span class="arn-step-n">1</span><div><strong><?= _e('arena.how_1_t') ?></strong><p class="t-small t-muted"><?= _e('arena.how_1') ?></p></div></li>
          <li><span class="arn-step-n">2</span><div><strong><?= _e('arena.how_2_t') ?></strong><p class="t-small t-muted"><?= _e('arena.how_2') ?></p></div></li>
          <li><span class="arn-step-n">3</span><div><strong><?= _e('arena.how_3_t') ?></strong><p class="t-small t-muted"><?= _e('arena.how_3') ?></p></div></li>
        </ol>
        <p class="t-xs t-muted arn-privacy"><?= ui_icon('lock', 14) ?> <?= _e('arena.privacy') ?></p>
      </div>
      <div class="card card-pad stack">
        <div class="split">
          <h2 class="t-h3"><?= _e('arena.top_title') ?></h2>
          <a class="t-small" href="<?= e(url('/arena/leaderboard')) ?>"><?= _e('arena.see_all') ?></a>
        </div>
<?php if ($top): ?>
        <ol class="arn-mini">
<?php foreach ($top as $i => $r): ?>
          <li>
            <?= ui_rank($i + 1) ?>
            <?= ui_model_chip($r) ?>
            <span class="arn-mini-rating t-num"><?= e(number_format((float) $r['rating'], 0, '.', '')) ?><span class="t-xs t-muted"> ±<?= e(number_format(((float) $r['ci_high'] - (float) $r['ci_low']) / 2, 0)) ?></span></span>
          </li>
<?php endforeach; ?>
        </ol>
<?php else: ?>
        <p class="t-muted t-small"><?= _e('arena.top_empty') ?></p>
<?php endif; ?>
      </div>
    </div>
  </div>
</section>
<script type="application/json" id="arn-data"><?= js_json($jsData) ?></script>
