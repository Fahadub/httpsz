<?php
/** Campaign detail: totals + models × tasks matrix of run dots. */
defined('WB') || exit;
$c = $campaign;
$c['progress'] = $progress;
echo partial('admin/_head', [
    'back' => url('/admin/campaigns'),
    'eyebrow' => '#' . $c['id'] . ' · ' . $c['season'],
    'title' => $c['name'],
    'actions' => partial('admin/_campaign_actions', ['c' => $c, 'compact' => false]),
]);
$p = $progress;
?>
<div class="kpi-row admin-kpis">
  <?= ui_stat(number_format($p['pct'], 1) . '%', __('admin.campaigns.progress'), 'gauge', __('admin.progress.done_of', ['d' => fmt_num($p['done']), 't' => fmt_num($p['total'])])) ?>
  <?= ui_stat(fmt_num($p['counts']['scored'] ?? 0), __('status.scored'), 'check-circle') ?>
  <?= ui_stat(fmt_num($p['failed']), __('status.failed'), 'x-circle') ?>
  <?= ui_stat(fmt_money($p['spent']), __('admin.campaigns.spend'), 'coin', $p['est'] !== null ? __('admin.campaigns.est', ['s' => \WB\Admin\Admin::iso(fmt_money($p['est']))]) : '') ?>
</div>
<div class="card card-pad stack-sm">
  <div class="progress"><div class="progress-bar" style="width:<?= e(number_format($p['pct'], 2, '.', '')) ?>%"></div></div>
  <div class="cluster t-xs">
    <?= ui_status($c['status']) ?>
    <?php foreach ($p['counts'] as $s => $n): ?><a class="admin-legend-item" href="<?= e(url('/admin/runs', ['campaign' => $c['id'], 'status' => $s])) ?>"><span class="admin-dot s-<?= e($s) ?>"></span><?= e(I18n::has('status.' . $s) ? __('status.' . $s) : $s) ?> <span class="t-num"><?= (int) $n ?></span></a><?php endforeach; ?>
    <span class="t-muted"><?= (int) $c['include_in_scores'] ? _e('admin.campaigns.counts_yes') : _e('admin.campaigns.counts_no') ?></span>
    <?php if ($gen_params): ?><span class="t-mono t-muted" dir="ltr"><?= e(to_json($gen_params)) ?></span><?php endif; ?>
  </div>
</div>
<?php if (!$models || !$tasks): ?>
  <?= ui_empty(__('admin.campaigns.no_runs'), '', 'target') ?>
<?php else: ?>
<section class="card">
  <div class="card-head card-pad"><h2 class="card-title"><?= ui_icon('grid', 18) ?> <?= _e('admin.campaigns.matrix') ?></h2><span class="t-xs t-muted"><?= _e('admin.campaigns.matrix_help') ?></span></div>
  <div class="table-wrap admin-matrix-wrap">
    <table class="table table-compact admin-matrix">
      <thead>
        <tr>
          <th class="admin-matrix-corner"><?= _e('common.model') ?></th>
          <?php foreach ($tasks as $t): ?><th class="admin-matrix-task" title="<?= e(L($t, 'title')) ?>"><span style="--track:<?= e($t['color']) ?>"><?= e($t['slug']) ?></span></th><?php endforeach; ?>
          <th class="num"><?= _e('common.score') ?></th><th class="num"><?= _e('common.cost') ?></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($models as $m): $ms = $model_stats[(int) $m['id']] ?? null; ?>
        <tr>
          <th scope="row" class="admin-matrix-model"><?= ui_model_chip($m, false, false) ?></th>
          <?php foreach ($tasks as $t): $list = $cells[(int) $m['id']][(int) $t['id']] ?? []; ?>
            <td class="admin-matrix-cell">
              <?php foreach ($list as $r): $tip = '#' . $r['id'] . ' · ' . (I18n::has('status.' . $r['status']) ? __('status.' . $r['status']) : $r['status']) . ($r['final_score'] !== null ? ' · ' . fmt_score($r['final_score']) : '') . ((int) $r['contested'] ? ' · ' . __('admin.runs.contested') : ''); ?>
                <a class="admin-dot s-<?= e($r['status']) ?><?= (int) $r['contested'] ? ' is-contested' : '' ?>" href="<?= e(url('/admin/runs/' . $r['id'])) ?>" data-tip="<?= e($tip) ?>" aria-label="<?= e($tip) ?>"></a>
              <?php endforeach; ?>
            </td>
          <?php endforeach; ?>
          <td class="num t-num"><?= $ms && $ms['avg_score'] !== null ? e(fmt_score($ms['avg_score'])) : '—' ?></td>
          <td class="num t-num"><?= e(fmt_money($ms['cost'] ?? 0)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>
