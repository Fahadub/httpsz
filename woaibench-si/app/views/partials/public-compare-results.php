<?php
/**
 * Compare results (also served alone via /compare?m=…&fragment=1).
 * Vars: rows, sel, season, tracks, h2h (['tasks','matrix','pairs']), values (model id => score per cent).
 */
defined('WB') || exit;

$n = count($sel);
if ($n < 2): ?>
  <?= ui_empty(__('pub.cmp.pick_title'), __('pub.cmp.pick_text'), 'scale') ?>
<?php return; endif;

$ids = array_map(static fn($r) => (int) $r['id'], $sel);
$best = static function (array $vals, bool $lowerIsBetter = false): ?int {
    $vals = array_filter($vals, static fn($v) => $v !== null);
    if (count($vals) < 2) {
        return null;
    }
    return (int) array_search($lowerIsBetter ? min($vals) : max($vals), $vals, true);
};
$col = static fn(string $key) => array_map(static fn($r) => $r[$key] ?? null, $sel);
$arena = array_map(static fn($r) => $r['arena']['rating'] ?? null, $sel);
$vals = array_map(static fn($r) => $values[$r['id']] ?? null, $sel);
$summary = [
    ['pub.cmp.row_score', $col('score'), false, static fn($v, $r) => ui_score($v, $r['ci_low'], $r['ci_high'], 'sm')],
    ['pub.cmp.row_ci', array_fill(0, $n, null), null, static fn($v, $r) => '<span class="t-num t-muted">' . e(fmt_score($r['ci_low']) . ' – ' . fmt_score($r['ci_high'])) . '</span>'],
    ['pub.cmp.row_rank', array_map(static fn($r) => $r['rank_pos'] !== null ? -$r['rank_pos'] : null, $sel), false, static fn($v, $r) => $r['rank_pos'] ? ui_rank($r['rank_pos'], $r['stat_rank']) : ui_badge(__('common.provisional'), 'muted')],
    ['pub.lb.col_arena', $arena, false, static fn($v, $r) => '<span class="t-num">' . e(fmt_num($v)) . '</span>'],
    ['pub.lb.col_consistency', $col('consistency'), false, static fn($v, $r) => '<span class="t-num">' . e(fmt_score($v)) . '</span>'],
    ['pub.lb.col_coverage', $col('coverage'), false, static fn($v, $r) => '<span class="t-num">' . ($v !== null ? e(fmt_num($v * 100)) . '%' : '—') . '</span>'],
    ['pub.lb.col_cost', $col('avg_cost'), true, static fn($v, $r) => '<span class="t-num" dir="ltr">' . e(fmt_money($v)) . '</span>'],
    ['pub.lb.col_latency', $col('avg_latency_ms'), true, static fn($v, $r) => '<span class="t-num" dir="ltr">' . e(fmt_ms($v)) . '</span>'],
    ['pub.cmp.row_value', $vals, false, static fn($v, $r) => '<span class="t-num" data-tip="' . _e('pub.cmp.value_tip') . '">' . ($v !== null ? e(fmt_num($v, $v < 10 ? 2 : 0)) : '—') . '</span>'],
    ['pub.cmp.row_price', array_map(static fn($r) => $r['price_out'], $sel), true, static fn($v, $r) => '<span class="t-num t-small" dir="ltr">' . e(fmt_price($r['price_in'])) . ' / ' . e(fmt_price($r['price_out'])) . '</span>'],
    ['pub.model.context', $col('context_length'), false, static fn($v, $r) => '<span class="t-num">' . e($v ? fmt_tokens($v) : '—') . '</span>'],
    ['common.open_weights', array_fill(0, $n, null), null, static fn($v, $r) => $r['open_weights'] ? ui_badge(__('common.yes'), 'green') : '<span class="t-muted">' . _e('common.no') . '</span>'],
];

// Per-task winners.
$wins = array_fill(0, $n, 0);
$ties = 0;
$taskRows = [];
foreach ($h2h['tasks'] as $t) {
    $scores = [];
    foreach ($ids as $i => $id) {
        $scores[$i] = $h2h['matrix'][(int) $t['id']][$id] ?? null;
    }
    $present = array_filter($scores, static fn($v) => $v !== null);
    $winner = null;
    if (count($present) >= 2) {
        arsort($present);
        $top = array_keys($present);
        $isTie = $present[$top[0]] - $present[$top[1]] < 2;
        $winner = $isTie ? 'tie' : $top[0];
        $isTie ? $ties++ : $wins[$top[0]]++;
    }
    $taskRows[] = ['task' => $t, 'scores' => $scores, 'winner' => $winner];
}
$chart = [];
foreach ($sel as $i => $r) {
    $tr = [];
    foreach ($tracks as $slug => $t) {
        $s = $r['tracks'][$slug] ?? null;
        $tr[] = ['score' => $s['score'] ?? null, 'ci_low' => $s['ci_low'] ?? null, 'ci_high' => $s['ci_high'] ?? null];
    }
    $chart[] = ['name' => $r['name'], 'slug' => $r['slug'], 'tracks' => $tr];
}
?>
<div class="stack-lg">
  <div class="pub-verdicts">
    <?php foreach ($h2h['pairs'] as $p): $a = $sel[$p['a']]; $b = $sel[$p['b']]; $lead = $a['score'] >= $b['score'] ? $a : $b; $trail = $lead === $a ? $b : $a; ?>
      <div class="card card-pad pub-verdict <?= $p['different'] ? 'is-different' : 'is-tied' ?>">
        <div class="pub-verdict-head">
          <span class="pub-vs"><span class="pub-c<?= $p['a'] ?>"><i class="pub-dot"></i><?= e($a['name']) ?></span><span class="t-faint">vs</span><span class="pub-c<?= $p['b'] ?>"><i class="pub-dot"></i><?= e($b['name']) ?></span></span>
          <?= $p['different'] ? ui_badge(__('pub.cmp.diff_yes'), 'green', 'check') : ui_badge(__('pub.cmp.diff_no'), 'muted', 'equal') ?>
        </div>
        <p class="t-small"><?= $p['different']
            ? _e('pub.cmp.verdict_diff', ['a' => $lead['name'], 'b' => $trail['name'], 'd' => fmt_score($lead['score'] - $trail['score'])])
            : _e('pub.cmp.verdict_tie', ['a' => $a['name'], 'b' => $b['name'], 'd' => fmt_score(abs($a['score'] - $b['score']))]) ?></p>
        <div class="pub-wtl" role="img" aria-label="<?= _e('pub.cmp.wtl_aria', ['a' => $a['name'], 'w' => $p['w'], 't' => $p['t'], 'l' => $p['l']]) ?>">
          <span class="pub-wtl-w pub-c<?= $p['a'] ?>" style="flex-grow:<?= $p['w'] ?>"></span><span class="pub-wtl-t" style="flex-grow:<?= $p['t'] ?>"></span><span class="pub-wtl-l pub-c<?= $p['b'] ?>" style="flex-grow:<?= $p['l'] ?>"></span>
        </div>
        <div class="pub-wtl-legend t-xs t-muted">
          <span><?= _e('pub.cmp.wins_of', ['name' => $a['name'], 'n' => $p['w']]) ?></span>
          <span><?= _e('pub.cmp.ties', ['n' => $p['t']]) ?></span>
          <span><?= _e('pub.cmp.wins_of', ['name' => $b['name'], 'n' => $p['l']]) ?></span>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <p class="t-xs t-muted"><?= _e('pub.cmp.verdict_note') ?></p>

  <div class="card card-pad stack">
    <h2 class="card-title"><?= ui_icon('list', 18) ?> <?= _e('pub.cmp.summary') ?></h2>
    <div class="table-wrap">
      <table class="table pub-cmp-table">
        <thead><tr><th scope="col"><span class="sr-only"><?= _e('pub.cmp.metric') ?></span></th>
          <?php foreach ($sel as $i => $r): ?><th scope="col" class="pub-c<?= $i ?>"><span class="pub-cmp-head"><i class="pub-dot"></i><?= ui_model_chip($r) ?></span></th><?php endforeach; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($summary as [$label, $vs, $lower, $render]): $b = $lower === null ? null : $best($vs, $lower); ?>
          <tr><th scope="row"><?= _e($label) ?></th>
            <?php foreach ($sel as $i => $r): ?><td class="<?= $b === $i ? 'is-best' : '' ?>"><?= $render($vs[$i], $r) ?></td><?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="grid grid-2 pub-charts">
    <div class="card card-pad stack">
      <h2 class="card-title"><?= ui_icon('target', 18) ?> <?= _e('pub.cmp.radar') ?></h2>
      <div class="chart-box pub-chart-radar"><canvas id="cmp-radar" role="img" aria-label="<?= _e('pub.cmp.radar') ?>"></canvas></div>
      <div class="legend"><?php foreach ($sel as $i => $r): ?><span class="legend-item pub-c<?= $i ?>"><?= e($r['name']) ?></span><?php endforeach; ?></div>
    </div>
    <div class="card card-pad stack">
      <h2 class="card-title"><?= ui_icon('chart', 18) ?> <?= _e('pub.cmp.bars') ?></h2>
      <div class="pub-chart-bars" style="--rows:<?= count($tracks) ?>;--series:<?= $n ?>"><canvas id="cmp-bars" role="img" aria-label="<?= _e('pub.cmp.bars') ?>"></canvas></div>
      <p class="t-xs t-muted"><?= _e('pub.cmp.bars_note') ?></p>
    </div>
  </div>

  <div class="card card-pad stack">
    <div class="card-head split">
      <h2 class="card-title"><?= ui_icon('scale', 18) ?> <?= _e('pub.cmp.h2h') ?></h2>
      <div class="cluster pub-tally">
        <?php foreach ($sel as $i => $r): ?><span class="pub-tally-item pub-c<?= $i ?>"><i class="pub-dot"></i><?= e($r['name']) ?> <b class="t-num"><?= (int) $wins[$i] ?></b></span><?php endforeach; ?>
        <span class="pub-tally-item"><?= ui_icon('equal', 14) ?><?= _e('pub.cmp.tie') ?> <b class="t-num"><?= (int) $ties ?></b></span>
      </div>
    </div>
    <p class="t-xs t-muted"><?= _e('pub.cmp.h2h_note') ?></p>
    <?php if (!$taskRows): ?>
      <?= ui_empty(__('pub.cmp.h2h_empty'), '', 'list') ?>
    <?php else: ?>
    <div class="table-wrap">
      <table class="table table-compact pub-h2h-table">
        <thead><tr><th scope="col"><?= _e('pub.task_col') ?></th>
          <?php foreach ($sel as $i => $r): ?><th class="num pub-c<?= $i ?>" scope="col"><i class="pub-dot"></i><span class="pub-h2h-name"><?= e($r['name']) ?></span></th><?php endforeach; ?>
          <th scope="col"><?= _e('pub.cmp.winner') ?></th></tr></thead>
        <tbody>
        <?php foreach ($taskRows as $tr): $t = $tr['task']; ?>
          <tr>
            <td><a href="<?= e(url('/task/' . $t['slug'])) ?>"><?= e(L($t, 'title')) ?></a><span class="pub-h2h-track t-xs t-muted"><?= ui_icon((string) $t['icon'], 12) ?> <?= e(L($t, 'name')) ?></span></td>
            <?php foreach ($tr['scores'] as $i => $s): ?><td class="num<?= $tr['winner'] === $i ? ' is-best' : '' ?>"><?= ui_heat_cell($s) ?></td><?php endforeach; ?>
            <td><?php if ($tr['winner'] === 'tie'): ?><span class="t-muted t-small"><?= ui_icon('equal', 14) ?> <?= _e('pub.cmp.tie') ?></span><?php elseif ($tr['winner'] !== null): ?><span class="pub-winner pub-c<?= (int) $tr['winner'] ?>"><i class="pub-dot"></i><?= e($sel[$tr['winner']]['name']) ?></span><?php else: ?><span class="t-faint">—</span><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
  <script type="application/json" id="cmp-data"><?= js_json(['models' => $chart, 'tracks' => array_values(array_map(static fn($t) => ['name' => L($t, 'name'), 'slug' => $t['slug']], $tracks)), 'rtl' => is_rtl()]) ?></script>
</div>
