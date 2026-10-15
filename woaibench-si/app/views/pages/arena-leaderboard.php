<?php
/**
 * Arena leaderboard (Bradley–Terry). Vars: $board (Arena::leaderboard()), $stats (Arena::stats()), $enabled.
 */
defined('WB') || exit;

$rows = $board['rows'];
$rho = $board['spearman'];
$lo = $rows ? min(array_map(static fn($r) => (float) ($r['ci_low'] ?? $r['rating']), $rows)) : 900.0;
$hi = $rows ? max(array_map(static fn($r) => (float) ($r['ci_high'] ?? $r['rating']), $rows)) : 1100.0;
$pad = max(10.0, ($hi - $lo) * 0.06);
$lo -= $pad;
$hi += $pad;
$span = max(1.0, $hi - $lo);
$pct = static fn(float $v): string => number_format(clamp(($v - $lo) / $span * 100, 0, 100), 2, '.', '');

if ($rho === null) {
    $rhoLabel = __('arena.corr_na');
} else {
    $abs = abs($rho);
    $rhoLabel = __($abs >= 0.7 ? 'arena.corr_strong' : ($abs >= 0.4 ? 'arena.corr_moderate' : 'arena.corr_weak'));
}
$points = [];
foreach ($rows as $r) {
    if ($r['bench_score'] !== null) {
        $points[] = ['x' => (float) $r['bench_score'], 'y' => (float) $r['rating'], 'name' => $r['name'], 'color' => $r['color']];
    }
}
?>
<section class="section arn-hero arn-hero-lb">
  <div class="container-wide">
    <nav class="breadcrumbs" aria-label="<?= _e('arena.breadcrumbs') ?>"><a href="<?= e(url('/arena')) ?>"><?= _e('nav.arena') ?></a><span aria-current="page"><?= _e('arena.lb_short') ?></span></nav>
    <div class="arn-hero-grid">
      <div class="stack">
        <span class="eyebrow"><?= ui_icon('trophy', 16) ?> <?= _e('arena.lb_eyebrow') ?></span>
        <h1 class="t-display t-balance"><?= _e('arena.lb_title') ?></h1>
        <p class="t-lead t-muted"><?= _e('arena.lb_lead') ?></p>
        <div class="cluster">
<?php if ($enabled): ?>
          <a class="btn btn-primary" href="<?= e(url('/arena')) ?>"><?= ui_icon('arena', 16) ?><span><?= _e('arena.cta_vote') ?></span></a>
<?php endif; ?>
          <a class="btn btn-ghost" href="<?= e(url('/api/v1/arena')) ?>"><?= ui_icon('api', 16) ?><span>JSON</span></a>
        </div>
      </div>
      <div class="kpi-row arn-hero-stats">
        <?= ui_stat(fmt_num($stats['votes']), __('arena.stat_votes'), 'thumbs-up') ?>
        <?= ui_stat(fmt_num($stats['voters']), __('arena.stat_voters'), 'users') ?>
        <?= ui_stat($rho === null ? '—' : number_format($rho, 2), __('arena.stat_rho'), 'scale', $rhoLabel) ?>
      </div>
    </div>
  </div>
</section>

<section class="section">
  <div class="container-wide">
<?php if (!$rows): ?>
    <div class="card card-pad">
      <?= ui_empty(__('arena.lb_empty_title'), __('arena.lb_empty_text'), 'arena', $enabled ? '<a class="btn btn-primary" href="' . e(url('/arena')) . '">' . _e('arena.cta_vote') . '</a>' : '') ?>
    </div>
<?php else: ?>
    <div class="card arn-lb-card">
      <div class="card-head split">
        <h2 class="card-title"><?= _e('arena.lb_table_title') ?></h2>
        <span class="t-xs t-muted"><?= _e('arena.lb_updated', ['t' => fmt_date($stats['last_recompute'] ?? ($rows[0]['updated_at'] ?? null), 'relative')]) ?></span>
      </div>
      <div class="table-wrap">
        <table class="table arn-lb-table" data-sortable>
          <thead>
            <tr>
              <th scope="col" data-sort="num" class="num">#</th>
              <th scope="col" data-sort="text"><?= _e('common.model') ?></th>
              <th scope="col" data-sort="num" class="num"><?= _e('arena.col_rating') ?></th>
              <th scope="col" class="arn-col-ci"><?= _e('arena.col_ci') ?></th>
              <th scope="col" data-sort="num" class="num"><?= _e('arena.col_votes') ?></th>
              <th scope="col" data-sort="num" class="num"><?= _e('arena.col_winrate') ?></th>
              <th scope="col" class="num"><?= _e('arena.col_wlt') ?></th>
              <th scope="col" data-sort="num" class="num"><?= _e('arena.col_bench') ?></th>
              <th scope="col" data-sort="num" class="num" data-tip="<?= _e('arena.col_delta_tip') ?>"><?= _e('arena.col_delta') ?></th>
            </tr>
          </thead>
          <tbody>
<?php foreach ($rows as $r):
    $half = $r['ci_high'] !== null && $r['ci_low'] !== null ? ($r['ci_high'] - $r['ci_low']) / 2 : null;
    $delta = $r['bench_rank'] ? (int) $r['bench_rank'] - (int) $r['arena_rank'] : null;
    ?>
            <tr class="<?= $r['few_votes'] ? 'is-provisional' : '' ?>">
              <td class="num" data-value="<?= (int) $r['arena_rank'] ?>"><?= ui_rank((int) $r['arena_rank']) ?></td>
              <td data-value="<?= e($r['name']) ?>">
                <?= ui_model_chip($r) ?>
                <?php if ($r['few_votes']): ?><?= ui_badge(__('arena.few_votes'), 'muted', null, __('arena.few_votes_tip', ['n' => WB\Arena\Arena::FEW_VOTES])) ?><?php endif; ?>
              </td>
              <td class="num" data-value="<?= e(number_format((float) $r['rating'], 2, '.', '')) ?>">
                <span class="arn-rating t-num"><?= e(number_format((float) $r['rating'], 0, '.', '')) ?></span>
                <?php if ($half !== null): ?><span class="score-ci t-num" data-tip="<?= e(__('common.ci95') . ': ' . number_format((float) $r['ci_low'], 0, '.', '') . ' – ' . number_format((float) $r['ci_high'], 0, '.', '')) ?>">±<?= e(number_format($half, 0)) ?></span><?php endif; ?>
              </td>
              <td class="arn-col-ci">
                <span class="arn-ci-track" role="img" aria-label="<?= e(__('common.ci95') . ': ' . number_format((float) $r['ci_low'], 0, '.', '') . ' – ' . number_format((float) $r['ci_high'], 0, '.', '')) ?>">
                  <span class="arn-ci-mid" style="inset-inline-start:<?= $pct(1000) ?>%"></span>
                  <span class="arn-ci-bar" style="--bar:<?= e($r['color']) ?>;inset-inline-start:<?= $pct((float) $r['ci_low']) ?>%;width:<?= number_format(max(0.8, (float) $pct((float) $r['ci_high']) - (float) $pct((float) $r['ci_low'])), 2, '.', '') ?>%"></span>
                  <span class="arn-ci-dot" style="--bar:<?= e($r['color']) ?>;inset-inline-start:<?= $pct((float) $r['rating']) ?>%"></span>
                </span>
              </td>
              <td class="num t-num" data-value="<?= (int) $r['votes'] ?>"><?= e(fmt_num($r['votes'])) ?></td>
              <td class="num t-num" data-value="<?= e((string) ($r['win_rate'] ?? 0)) ?>"><?= $r['win_rate'] === null ? '—' : e(number_format($r['win_rate'] * 100, 0)) . '%' ?></td>
              <td class="num t-num t-small arn-wlt"><span class="arn-w"><?= (int) $r['wins'] ?></span><span class="arn-sep">/</span><span class="arn-l"><?= (int) $r['losses'] ?></span><span class="arn-sep">/</span><span class="arn-t"><?= (int) $r['ties'] ?></span></td>
              <td class="num" data-value="<?= e((string) ($r['bench_score'] ?? -1)) ?>"><?= ui_score($r['bench_score'], $r['bench_ci_low'], $r['bench_ci_high'], 'sm') ?><?php if ($r['bench_rank']): ?> <span class="t-xs t-muted">#<?= (int) $r['bench_rank'] ?></span><?php endif; ?></td>
              <td class="num" data-value="<?= $delta ?? 0 ?>"><?= $delta === null ? '<span class="t-muted">—</span>' : ui_delta($delta, 0) ?></td>
            </tr>
<?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="card-foot t-xs t-muted"><?= _e('arena.lb_foot') ?></p>
    </div>

    <div class="grid grid-2 arn-lb-extra">
      <div class="card card-pad stack">
        <h2 class="t-h3"><?= _e('arena.corr_title') ?></h2>
        <p class="arn-rho"><span class="t-num t-gold arn-rho-value"><?= $rho === null ? '—' : e(number_format($rho, 2)) ?></span><span class="badge badge-muted"><?= e($rhoLabel) ?></span></p>
        <p class="t-small t-muted"><?= _e('arena.corr_text', ['n' => $board['n_corr']]) ?></p>
<?php if ($points): ?>
        <div class="chart-box arn-chart"><canvas id="arn-scatter" aria-label="<?= _e('arena.chart_label') ?>" role="img"></canvas></div>
<?php endif; ?>
      </div>
      <div class="card card-pad stack prose">
        <h2 class="t-h3"><?= _e('arena.method_title') ?></h2>
        <p><?= _e('arena.method_1') ?></p>
        <p><?= _e('arena.method_2') ?></p>
        <p><?= _e('arena.method_3') ?></p>
        <p class="t-small t-muted"><?= _e('arena.method_4') ?></p>
      </div>
    </div>
<?php endif; ?>
  </div>
</section>
<script type="application/json" id="arn-lb-data"><?= js_json(['points' => $points, 'label' => __('arena.chart_label'), 'xLabel' => __('arena.col_bench'), 'yLabel' => __('arena.col_rating')]) ?></script>
