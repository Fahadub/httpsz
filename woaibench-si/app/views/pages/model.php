<?php
/**
 * Model page. Vars: model, row (leaderboard row|null), season, total_ranked, tracks, medians, history,
 * tasks (public per-task stats), holdout (per-track aggregates), agreement, outputs.
 */
defined('WB') || exit;

$m = $model;
$scored = $row !== null;
$sw = $scored ? WB\Data\Models::strengths($row) : ['top' => [], 'bottom' => []];
$chart = [];
if ($scored) {
    foreach ($tracks as $slug => $t) {
        $ts = $row['tracks'][$slug] ?? null;
        $chart[] = [
            'slug' => $slug, 'name' => L($t, 'name'), 'color' => $t['color'],
            'score' => $ts['score'] ?? null, 'ci_low' => $ts['ci_low'] ?? null, 'ci_high' => $ts['ci_high'] ?? null,
            'median' => isset($medians[$slug]) ? round((float) $medians[$slug], 2) : null,
        ];
    }
}
$hist = array_map(static fn($h) => [
    'date' => $h['date'], 'label' => fmt_date($h['date']), 'score' => (float) $h['score'],
    'ci_low' => $h['ci_low'] !== null ? (float) $h['ci_low'] : null, 'ci_high' => $h['ci_high'] !== null ? (float) $h['ci_high'] : null,
    'rank' => $h['rank_pos'] !== null ? (int) $h['rank_pos'] : null,
], $history);
$ctx = $m['context_length'] ? fmt_tokens($m['context_length']) : '—';
?>
<section class="section pub-hero pub-model-hero" style="--model:<?= e($m['color']) ?>">
  <div class="container-wide stack">
    <nav class="breadcrumbs" aria-label="<?= _e('pub.breadcrumbs') ?>">
      <a href="<?= e(url('/leaderboard')) ?>"><?= _e('nav.leaderboard') ?></a><span aria-current="page"><?= e($m['name']) ?></span>
    </nav>
    <div class="card card-glass pub-model-card">
      <div class="pub-model-id">
        <?= ui_provider_avatar((string) $m['provider'], $m['provider_name'], 64) ?>
        <div class="stack-sm">
          <p class="eyebrow"><?= e($m['provider_name'] ?: $m['provider']) ?></p>
          <h1 class="t-h1"><?= e($m['name']) ?></h1>
          <div class="cluster pub-badges">
            <?= $m['open_weights'] ? ui_badge(__('common.open_weights'), 'green', 'lock') : ui_badge(__('common.proprietary'), 'muted', 'lock') ?>
            <?php if ($scored && $row['provisional']): ?><?= ui_badge(__('common.provisional'), 'gold', 'info', __('common.provisional_tip')) ?><?php endif; ?>
            <?php if ($m['supports_vision']): ?><?= ui_badge(__('pub.model.vision'), 'blue', 'eye') ?><?php endif; ?>
            <?php if ($m['supports_tools']): ?><?= ui_badge(__('pub.model.tools'), 'violet', 'wand') ?><?php endif; ?>
            <?php if ($m['is_judge']): ?><?= ui_badge(__('pub.model.judge'), 'gold', 'scale', __('pub.model.judge_tip')) ?><?php endif; ?>
          </div>
          <?php if (!empty($m['description'])): ?><p class="t-muted pub-desc"><?= e($m['description']) ?></p><?php endif; ?>
        </div>
      </div>
      <dl class="pub-specs">
        <div><dt><?= _e('pub.model.release') ?></dt><dd><?= e(fmt_date($m['release_date'])) ?></dd></div>
        <div><dt><?= _e('pub.model.context') ?></dt><dd class="t-num"><?= e($ctx) ?></dd></div>
        <div><dt><?= _e('pub.model.price_in') ?></dt><dd class="t-num"><span dir="ltr"><?= e(fmt_price($m['price_in'])) ?></span></dd></div>
        <div><dt><?= _e('pub.model.price_out') ?></dt><dd class="t-num"><span dir="ltr"><?= e(fmt_price($m['price_out'])) ?></span></dd></div>
        <?php if ($m['params_b']): ?>
          <div><dt><?= _e('pub.model.params') ?></dt><dd class="t-num"><?= e(fmt_num($m['params_b'], (float) $m['params_b'] < 10 ? 1 : 0)) ?>B<?php if ($m['active_params_b'] && (float) $m['active_params_b'] < (float) $m['params_b']): ?> <span class="t-muted t-xs"><?= _e('pub.model.active', ['n' => fmt_num($m['active_params_b'], 0)]) ?></span><?php endif; ?></dd></div>
        <?php endif; ?>
        <div><dt><?= _e('pub.model.or_id') ?></dt><dd class="t-mono t-xs pub-break"><?= e($m['or_id']) ?></dd></div>
      </dl>
      <?php if ($scored): ?>
      <div class="pub-model-score">
        <span class="stat-label"><?= _e('pub.model.overall') ?></span>
        <?= ui_score($row['score'], $row['ci_low'], $row['ci_high'], 'xl') ?>
        <?= ui_scorebar($row['score'], 100, null, $row['ci_low'], $row['ci_high']) ?>
        <div class="pub-model-rank">
          <?php if ($row['rank_pos']): ?>
            <?= ui_rank($row['rank_pos'], $row['stat_rank']) ?>
            <span class="t-small t-muted"><?= _e('pub.model.rank_of', ['n' => $total_ranked]) ?><?php if ($row['stat_rank'] !== null): ?> · <span data-tip="<?= _e('pub.lb.tip_statrank', ['n' => $row['stat_rank']]) ?>"><?= _e('pub.model.stat_rank', ['n' => $row['stat_rank']]) ?></span><?php endif; ?></span>
          <?php else: ?>
            <span class="t-small t-muted"><?= _e('pub.model.unranked', ['pct' => fmt_num((float) setting('min_coverage') * 100)]) ?></span>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php if (!$scored): ?>
<section class="section pub-section-tight">
  <div class="container-wide">
    <?= $m['is_judge'] && !$m['is_active'] ? ui_empty(__('pub.model.judge_only_title'), __('pub.model.judge_only_text', ['name' => $m['name']]), 'scale', '<div class="cluster pub-center"><a class="btn btn-primary" href="' . e(url('/methodology') . '#judges') . '">' . _e('pub.model.judge_only_cta') . '</a></div>') : ui_empty(__('pub.model.unscored_title'), __('pub.model.unscored_text', ['name' => $m['name']]), 'flask', '<div class="cluster pub-center"><a class="btn btn-primary" href="' . e(url('/request')) . '">' . _e('nav.request') . '</a><a class="btn btn-ghost" href="' . e(url('/leaderboard')) . '">' . _e('nav.leaderboard') . '</a></div>') ?>
  </div>
</section>
<?php else: ?>
<section class="section pub-section-tight">
  <div class="container-wide stack-lg">
    <div class="kpi-row pub-kpis-wide">
      <?= ui_stat($row['arena'] ? fmt_num($row['arena']['rating']) : '—', __('pub.lb.col_arena'), 'arena', $row['arena'] ? __('pub.model.arena_hint', ['lo' => fmt_num($row['arena']['ci_low']), 'hi' => fmt_num($row['arena']['ci_high']), 'n' => fmt_num($row['arena']['votes'])]) : __('pub.model.arena_none')) ?>
      <?= ui_stat(fmt_score($row['consistency']), __('pub.lb.col_consistency'), 'target', __('pub.model.consistency_hint')) ?>
      <?= ui_stat(fmt_num(($row['coverage'] ?? 0) * 100) . '%', __('pub.lb.col_coverage'), 'layers', __('pub.model.coverage_hint', ['n' => $row['n_tasks'] ?? 0, 'runs' => $row['n_runs'] ?? 0])) ?>
      <?= ui_stat(fmt_money($row['avg_cost']), __('pub.lb.col_cost'), 'coin', __('pub.model.tokens_hint', ['n' => fmt_tokens($row['avg_tokens'])])) ?>
      <?= ui_stat(fmt_ms($row['avg_latency_ms']), __('pub.lb.col_latency'), 'timer', __('pub.model.latency_hint')) ?>
      <?= ui_stat($agreement && $agreement['mean_spread'] !== null ? fmt_score($agreement['mean_spread']) : '—', __('pub.model.agreement'), 'scale', $agreement && $agreement['runs'] ? __('pub.model.agreement_hint', ['pct' => fmt_num($agreement['contested_pct'], 1)]) : __('pub.model.agreement_none')) ?>
    </div>

    <div class="grid grid-2 pub-charts">
      <div class="card card-pad stack">
        <div class="card-head"><h2 class="card-title"><?= ui_icon('target', 18) ?> <?= _e('pub.model.radar') ?></h2></div>
        <div class="chart-box pub-chart-radar"><canvas id="model-radar" role="img" aria-label="<?= _e('pub.model.radar_aria', ['name' => $m['name']]) ?>"></canvas></div>
        <div class="legend"><span class="legend-item"><?= e($m['name']) ?></span><span class="legend-item is-line pub-li-median"><?= _e('pub.model.median') ?></span></div>
      </div>
      <div class="card card-pad stack">
        <div class="card-head"><h2 class="card-title"><?= ui_icon('history', 18) ?> <?= _e('pub.model.history') ?></h2></div>
        <?php if (count($hist) >= 2): ?>
          <div class="chart-box"><canvas id="model-history" role="img" aria-label="<?= _e('pub.model.history_aria', ['name' => $m['name']]) ?>"></canvas></div>
          <p class="t-xs t-muted"><?= _e('pub.model.history_note') ?></p>
        <?php else: ?>
          <?= ui_empty(__('pub.model.history_empty'), __('pub.model.history_empty_text'), 'history') ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="grid grid-2">
      <?php foreach (['top' => ['pub.model.strengths', 'arrow-up', 'green'], 'bottom' => ['pub.model.weaknesses', 'arrow-down', 'red']] as $k => [$label, $icon, $tone]): ?>
      <div class="card card-pad stack-sm pub-sw pub-sw-<?= e($tone) ?>">
        <h2 class="card-title"><?= ui_icon($icon, 18) ?> <?= _e($label) ?></h2>
        <?php if (!$sw[$k]): ?><p class="t-muted t-small"><?= _e('pub.model.sw_none') ?></p><?php endif; ?>
        <ul class="pub-sw-list">
          <?php foreach ($sw[$k] as $slug => $score): $t = $tracks[$slug] ?? ['slug' => $slug, 'name_en' => $slug, 'name_ar' => $slug, 'icon' => 'layers', 'color' => '#999']; $med = $medians[$slug] ?? null; ?>
            <li>
              <?= ui_track_badge($t, true) ?>
              <span class="pub-sw-score t-num"><?= e(fmt_score($score)) ?></span>
              <?php if ($med !== null): ?><span class="t-xs t-muted" data-tip="<?= _e('pub.model.vs_median_tip', ['m' => fmt_score($med)]) ?>"><?= ui_delta($score - $med) ?></span><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="card card-pad stack">
      <div class="card-head split"><h2 class="card-title"><?= ui_icon('layers', 18) ?> <?= _e('pub.model.track_table') ?></h2><span class="t-xs t-muted"><?= _e('pub.model.track_table_note') ?></span></div>
      <div class="table-wrap">
        <table class="table table-compact">
          <thead><tr><th scope="col"><?= _e('pub.track') ?></th><th scope="col"><?= _e('pub.lb.col_score') ?></th><th class="num" scope="col"><?= _e('pub.model.ci') ?></th><th class="num" scope="col"><?= _e('pub.model.median') ?></th><th class="num" scope="col"><?= _e('pub.model.delta') ?></th><th class="num" scope="col"><?= _e('pub.model.tasks_n') ?></th></tr></thead>
          <tbody>
          <?php foreach ($chart as $c): ?>
            <tr>
              <td><?= ui_track_badge($tracks[$c['slug']], true) ?></td>
              <td class="pub-track-bar"><span class="t-num"><?= e(fmt_score($c['score'])) ?></span><?= ui_scorebar($c['score'], 100, $c['color'], $c['ci_low'], $c['ci_high']) ?></td>
              <td class="num t-num t-muted"><?= $c['ci_low'] !== null ? e(fmt_score($c['ci_low']) . '–' . fmt_score($c['ci_high'])) : '—' ?></td>
              <td class="num t-num t-muted"><?= e(fmt_score($c['median'])) ?></td>
              <td class="num"><?= $c['score'] !== null && $c['median'] !== null ? ui_delta($c['score'] - $c['median']) : '—' ?></td>
              <td class="num t-num"><?= e((string) ($row['tracks'][$c['slug']]['n_tasks'] ?? 0)) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card card-pad stack">
      <div class="card-head split">
        <h2 class="card-title"><?= ui_icon('list', 18) ?> <?= _e('pub.model.tasks') ?></h2>
        <label class="input-group pub-search-sm"><?= ui_icon('search', 14) ?><span class="sr-only"><?= _e('pub.model.task_filter') ?></span><input class="input" type="search" placeholder="<?= _e('pub.model.task_filter') ?>" data-filter-input="#model-tasks"></label>
      </div>
      <?php if (!$tasks): ?>
        <?= ui_empty(__('pub.model.tasks_empty'), __('pub.model.tasks_empty_text'), 'list') ?>
      <?php else: ?>
      <div class="table-wrap">
        <table class="table table-compact pub-task-table" id="model-tasks" data-sortable>
          <thead><tr>
            <th scope="col" data-sort="text"><?= _e('pub.task_col') ?></th>
            <th scope="col" data-sort="text"><?= _e('pub.track') ?></th>
            <th scope="col" data-sort="num"><?= _e('pub.difficulty') ?></th>
            <th class="num" scope="col" data-sort="num"><?= _e('pub.mean') ?></th>
            <th class="num" scope="col" data-sort="num"><?= _e('pub.attempts') ?></th>
            <th scope="col"><span data-tip="<?= _e('pub.split_tip') ?>"><?= _e('pub.split') ?></span></th>
            <th class="num" scope="col" data-sort="num" data-sort-default="ascending"><?= _e('common.cost') ?></th>
            <th class="num" scope="col" data-sort="num" data-sort-default="ascending"><?= _e('common.latency') ?></th>
            <th scope="col"><span class="sr-only"><?= _e('pub.best_run') ?></span></th>
          </tr></thead>
          <tbody>
          <?php foreach ($tasks as $t): ?>
            <tr>
              <td><a href="<?= e(url('/task/' . $t['slug'])) ?>"><?= e(L($t, 'title')) ?></a><?php if ((int) $t['contested'] > 0): ?> <span class="t-xs" data-tip="<?= _e('pub.contested_tip') ?>"><?= ui_icon('alert', 12) ?></span><?php endif; ?></td>
              <td><?= ui_track_badge(['slug' => $t['track_slug'], 'name_ar' => $t['name_ar'], 'name_en' => $t['name_en'], 'icon' => $t['icon'], 'color' => $t['color']]) ?></td>
              <td data-value="<?= (int) $t['difficulty'] ?>"><?= ui_difficulty((int) $t['difficulty']) ?></td>
              <td class="num" data-value="<?= e((string) round((float) $t['mean_score'], 2)) ?>"><?= ui_heat_cell($t['mean_score'], $t['sd'] !== null ? '± ' . fmt_score($t['sd']) . ' (' . __('pub.sd') . ')' : '') ?></td>
              <td class="num t-num"><?= (int) $t['attempts'] ?></td>
              <td><?= pub_split($t['auto_mean'], $t['judge_mean']) ?></td>
              <td class="num t-num" data-value="<?= e((string) ($t['cost'] ?? '')) ?>"><span dir="ltr"><?= e(fmt_money($t['cost'])) ?></span></td>
              <td class="num t-num" data-value="<?= e((string) ($t['latency_ms'] ?? '')) ?>"><span dir="ltr"><?= e(fmt_ms($t['latency_ms'])) ?></span></td>
              <td><?php if ($t['best_run']): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('/run/' . $t['best_run'])) ?>"><?= _e('pub.best_run') ?> <?= ui_icon(is_rtl() ? 'arrow-left' : 'arrow-right', 14) ?></a><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <?php if ($holdout): ?>
    <div class="card card-pad stack">
      <div class="card-head"><h2 class="card-title"><?= ui_icon('lock', 18) ?> <?= _e('pub.model.holdout') ?></h2></div>
      <p class="t-small t-muted"><?= _e('pub.model.holdout_text') ?></p>
      <div class="pub-holdout-grid">
        <?php foreach ($holdout as $slug => $h): if (!isset($tracks[$slug])) { continue; } ?>
          <div class="pub-holdout-cell">
            <?= ui_track_badge($tracks[$slug]) ?>
            <span class="t-num pub-holdout-score"><?= e(fmt_score($h['score'])) ?></span>
            <?php $pubScore = $row['tracks'][$slug]['score'] ?? null; ?>
            <span class="t-xs t-muted"><?= _e('pub.model.holdout_n', ['n' => $h['tasks'], 'runs' => $h['attempts']]) ?><?php if ($pubScore !== null): ?> · <span data-tip="<?= _e('pub.model.holdout_gap_tip') ?>"><?= _e('pub.model.holdout_gap') ?> <?= ui_delta($h['score'] - $pubScore) ?></span><?php endif; ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<?php if ($outputs): ?>
<section class="section pub-section-tight">
  <div class="container-wide stack">
    <div class="section-head"><h2 class="t-h2"><?= _e('pub.model.outputs') ?></h2><p class="section-sub t-muted"><?= _e('pub.model.outputs_sub') ?></p></div>
    <?= partial('partials/public-gallery', ['items' => array_map(static fn($o) => [
        'run_id' => (int) $o['id'], 'shot_url' => $o['shot_url'], 'title' => L($o, 'title'), 'score' => $o['final_score'],
        'sub' => L($o, 'name'), 'color' => $o['color'], 'icon' => $o['icon'], 'type' => $o['output_type'],
    ], $outputs)]) ?>
  </div>
</section>
<?php endif; ?>
<?php if ($scored): ?>
<script type="application/json" id="model-data"><?= js_json(['name' => $m['name'], 'color' => $m['color'], 'tracks' => $chart, 'history' => $hist]) ?></script>
<?= pub_js_strings() ?>
<?php endif; ?>
