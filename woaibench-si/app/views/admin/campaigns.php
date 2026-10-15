<?php
/** Campaigns list. */
defined('WB') || exit;
echo partial('admin/_head', [
    'title' => __('admin.nav.campaigns'),
    'sub' => __('admin.campaigns.sub'),
    'actions' => '<a class="btn btn-ghost" href="' . e(url('/admin/console')) . '">' . ui_icon('terminal', 16) . '<span>' . _e('admin.nav.console') . '</span></a>'
        . '<a class="btn btn-primary" href="' . e(url('/admin/campaigns/new')) . '">' . ui_icon('plus', 16) . '<span>' . _e('admin.campaigns.new') . '</span></a>',
]);
?>
<?php if (!$engine): ?><div class="alert alert-info"><?= _e('admin.engine_missing', ['c' => 'WB\\Eval\\Campaign']) ?></div><?php endif; ?>
<nav class="tabs admin-tabs" aria-label="<?= _e('admin.filter') ?>">
  <?php foreach (['' => 'common.all', 'running' => 'status.running', 'paused' => 'status.paused', 'done' => 'status.done', 'cancelled' => 'status.cancelled'] as $s => $label): ?>
    <a class="tab<?= $status === $s ? ' active' : '' ?>" href="<?= e(url('/admin/campaigns', ['status' => $s])) ?>"<?= $status === $s ? ' aria-current="page"' : '' ?>><?= _e($label) ?></a>
  <?php endforeach; ?>
</nav>
<?php if (!$campaigns): ?>
  <?= ui_empty(__('admin.campaigns.empty'), __('admin.campaigns.empty_text'), 'target', '<a class="btn btn-primary btn-sm" href="' . e(url('/admin/campaigns/new')) . '">' . _e('admin.campaigns.new') . '</a>') ?>
<?php else: ?>
<div class="card">
  <div class="table-wrap">
    <table class="table admin-table">
      <thead><tr>
        <th><?= _e('admin.campaigns.name') ?></th><th><?= _e('admin.models.status') ?></th><th class="admin-col-progress"><?= _e('admin.campaigns.progress') ?></th>
        <th class="num"><?= _e('admin.campaigns.spend') ?></th><th><?= _e('admin.campaigns.in_scores') ?></th><th><?= _e('admin.campaigns.created') ?></th><th><?= _e('admin.actions') ?></th>
      </tr></thead>
      <tbody>
      <?php foreach ($campaigns as $c): $p = $c['progress']; ?>
        <tr>
          <td><a class="admin-strong" href="<?= e(url('/admin/campaigns/' . $c['id'])) ?>"><?= e($c['name']) ?></a><div class="t-xs t-muted t-mono">#<?= (int) $c['id'] ?> · <?= e($c['season']) ?> · ×<?= (int) $c['runs_per_task'] ?></div></td>
          <td><?= ui_status($c['status']) ?></td>
          <td>
            <div class="progress" role="progressbar" aria-valuenow="<?= e((string) round($p['pct'])) ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" style="width:<?= e(number_format($p['pct'], 2, '.', '')) ?>%"></div></div>
            <div class="t-xs t-muted t-num"><?= _e('admin.progress.done_of', ['d' => fmt_num($p['done']), 't' => fmt_num($p['total'])]) ?><?= $p['failed'] ? ' · <span class="t-red">' . _e('admin.progress.failed', ['n' => $p['failed']]) . '</span>' : '' ?></div>
          </td>
          <td class="num t-num"><?= e(fmt_money($p['spent'])) ?><?= $p['est'] !== null ? '<div class="t-xs t-muted">' . _e('admin.campaigns.est', ['s' => fmt_money($p['est'])]) . '</div>' : '' ?></td>
          <td><?= (int) $c['include_in_scores'] ? ui_badge(__('common.yes'), 'green', 'check') : ui_badge(__('common.no'), 'muted') ?></td>
          <td class="t-xs t-muted"><?= e(fmt_date($c['created_at'], 'datetime')) ?></td>
          <td><?= partial('admin/_campaign_actions', ['c' => $c, 'compact' => true]) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?= ui_pagination($page, $pages, '/admin/campaigns', ['status' => $status]) ?>
<?php endif; ?>
