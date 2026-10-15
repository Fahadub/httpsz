<?php
/**
 * Price-performance. Vars: rows, season, frontier (ids, cost axis), frontier_latency (ids), values (id => score per cent).
 */
defined('WB') || exit;

$points = [];
foreach ($rows as $r) {
    $points[] = [
        'id' => $r['id'], 'slug' => $r['slug'], 'name' => $r['name'], 'vendor' => $r['provider_name'] ?: $r['provider'],
        'color' => $r['color'], 'open' => $r['open_weights'], 'provisional' => $r['provisional'],
        'score' => $r['score'], 'ci_low' => $r['ci_low'], 'ci_high' => $r['ci_high'],
        'cost' => $r['avg_cost'], 'latency' => $r['avg_latency_ms'],
    ];
}
$front = array_values(array_filter($rows, static fn($r) => in_array($r['id'], $frontier, true)));
usort($front, static fn($a, $b) => $a['avg_cost'] <=> $b['avg_cost']);
$vendors = [];
foreach ($rows as $r) {
    $vendors[$r['provider']] = ['name' => $r['provider_name'] ?: $r['provider'], 'color' => $r['color']];
}
$best = null;
foreach ($rows as $r) {
    $v = $values[$r['id']] ?? null;
    if ($v !== null && !$r['provisional'] && ($best === null || $v > $values[$best['id']])) {
        $best = $r;
    }
}
$noCost = array_filter($rows, static fn($r) => !$r['avg_cost']);
?>
<section class="section pub-hero">
  <div class="container-wide stack-sm">
    <p class="eyebrow"><?= _e('pub.value.eyebrow', ['season' => $season]) ?></p>
    <h1 class="t-h1"><?= _e('pub.value.h1') ?></h1>
    <p class="t-lead t-muted pub-lead"><?= _e('pub.value.lead') ?></p>
  </div>
</section>

<section class="section pub-section-tight">
  <div class="container-wide stack-lg">
    <?php if (!$rows): ?>
      <?= ui_empty(__('pub.lb.empty_title'), __('pub.lb.empty_text'), 'coin') ?>
    <?php else: ?>
    <div class="card card-pad stack">
      <div class="card-head split pub-value-head">
        <div class="tabs pub-seg" role="group" aria-label="<?= _e('pub.value.axis') ?>">
          <button type="button" class="tab active" data-value-axis="cost" aria-pressed="true"><?= ui_icon('coin', 15) ?><span><?= _e('pub.value.axis_cost') ?></span></button>
          <button type="button" class="tab" data-value-axis="latency" aria-pressed="false"><?= ui_icon('timer', 15) ?><span><?= _e('pub.value.axis_latency') ?></span></button>
        </div>
        <label class="switch pub-switch"><input type="checkbox" data-value-labels checked><span><?= _e('pub.value.labels') ?></span></label>
      </div>
      <div class="chart-box pub-chart-scatter" data-base="<?= e(base_path()) ?>"><canvas id="value-chart" role="img" aria-label="<?= _e('pub.value.chart_aria') ?>"></canvas></div>
      <div class="legend pub-legend">
        <span class="legend-item"><i class="pub-swatch pub-swatch-frontier"></i><?= _e('pub.value.legend_frontier') ?></span>
        <span class="legend-item"><i class="pub-marker is-circle"></i><?= _e('common.proprietary') ?></span>
        <span class="legend-item"><i class="pub-marker is-diamond"></i><?= _e('common.open_weights') ?></span>
        <?php foreach ($vendors as $v): ?><span class="legend-item"><i class="pub-dot" style="background:<?= e($v['color']) ?>"></i><?= e($v['name']) ?></span><?php endforeach; ?>
      </div>
      <p class="t-xs t-muted"><?= _e('pub.value.chart_note') ?></p>
    </div>

    <div class="grid grid-2">
      <div class="card card-pad stack">
        <h2 class="card-title"><?= ui_icon('trophy', 18) ?> <?= _e('pub.value.frontier') ?></h2>
        <p class="t-small t-muted"><?= _e('pub.value.frontier_text') ?></p>
        <div class="table-wrap">
          <table class="table table-compact">
            <thead><tr><th scope="col"><?= _e('common.model') ?></th><th scope="col"><?= _e('pub.lb.col_score') ?></th><th class="num" scope="col"><?= _e('pub.lb.col_cost') ?></th><th class="num" scope="col"><span data-tip="<?= _e('pub.cmp.value_tip') ?>"><?= _e('pub.cmp.row_value') ?></span></th></tr></thead>
            <tbody>
            <?php foreach ($front as $r): ?>
              <tr>
                <td><?= ui_model_chip($r) ?><?php if ($r['open_weights']): ?> <?= ui_badge(__('pub.open_short'), 'green') ?><?php endif; ?></td>
                <td><?= ui_score($r['score'], $r['ci_low'], $r['ci_high'], 'sm') ?></td>
                <td class="num t-num"><span dir="ltr"><?= e(fmt_money($r['avg_cost'])) ?></span></td>
                <td class="num t-num"><?= ($values[$r['id']] ?? null) !== null ? e(fmt_num($values[$r['id']], $values[$r['id']] < 10 ? 2 : 0)) : '—' ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$front): ?><tr><td colspan="4" class="t-muted"><?= _e('pub.value.no_cost') ?></td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <div class="card card-pad stack pub-value-explain">
        <h2 class="card-title"><?= ui_icon('info', 18) ?> <?= _e('pub.value.how') ?></h2>
        <p class="t-muted"><?= pub_rich('pub.value.how_1') ?></p>
        <p class="t-muted"><?= pub_rich('pub.value.how_2') ?></p>
        <p class="t-muted"><?= pub_rich('pub.value.how_3') ?></p>
        <?php if ($best): ?><p class="pub-callout"><?= ui_icon('sparkles', 16) ?><span><?= pub_rich('pub.value.best', ['name' => $best['name'], 'v' => fmt_num($values[$best['id']], $values[$best['id']] < 10 ? 2 : 0)]) ?></span></p><?php endif; ?>
        <?php if ($noCost): ?><p class="t-xs t-muted"><?= _e('pub.value.missing', ['n' => count($noCost)]) ?></p><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</section>
<?php if ($rows): ?>
<script type="application/json" id="value-data"><?= js_json(['points' => $points, 'frontier' => ['cost' => $frontier, 'latency' => $frontier_latency], 'rtl' => is_rtl()]) ?></script>
<?= pub_js_strings() ?>
<?php endif; ?>
