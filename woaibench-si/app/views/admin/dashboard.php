<?php
/** Admin dashboard. */
defined('WB') || exit;
$queue = 0;
foreach (['queued', 'generating', 'awaiting_verify', 'verifying', 'judging', 'scoring'] as $s) {
    $queue += $counts[$s] ?? 0;
}
$maxCount = max(1, ...array_values($counts ?: [0]));
$missing = array_keys(array_filter($engine, static fn($ok) => !$ok));
echo partial('admin/_head', [
    'eyebrow' => __('admin.dash.hello', ['name' => $user['name']]),
    'title' => __('admin.nav.dashboard'),
    'sub' => __('admin.dash.sub'),
    'actions' => '<a class="btn btn-ghost" href="' . e(url('/admin/console')) . '">' . ui_icon('terminal', 16) . '<span>' . _e('admin.dash.open_console') . '</span></a>'
        . '<a class="btn btn-primary" href="' . e(url('/admin/campaigns/new')) . '">' . ui_icon('plus', 16) . '<span>' . _e('admin.campaigns.new') . '</span></a>',
]);
?>
<?php if ($missing): ?>
  <div class="alert alert-info"><?= ui_icon('info', 16) ?> <?= _e('admin.dash.engine_partial', ['list' => implode(', ', $missing)]) ?></div>
<?php endif; ?>
<?php if (!$key_set): ?>
  <div class="alert alert-warn"><?= ui_icon('key', 16) ?> <?= _e('admin.dash.key_missing') ?> <a href="<?= e(url('/admin/settings#s-secrets')) ?>"><?= _e('admin.dash.key_fix') ?></a></div>
<?php endif; ?>
<?php if ($heartbeat_stale): ?>
  <div class="alert alert-warn admin-cron-alert">
    <div class="stack-sm">
      <strong><?= ui_icon('alert', 16) ?> <?= $heartbeat_age === null ? _e('admin.dash.worker_never') : _e('admin.dash.worker_stale', ['t' => fmt_date(gmdate('Y-m-d H:i:s', time() - $heartbeat_age), 'relative')]) ?></strong>
      <span><?= _e('admin.dash.cron_help') ?></span>
      <div class="admin-copyline"><code class="t-mono" dir="ltr"><?= e($cron_cmd) ?></code><button class="btn btn-sm btn-ghost" type="button" data-copy="<?= e($cron_cmd) ?>"><?= ui_icon('copy', 14) ?><span><?= _e('common.copy') ?></span></button></div>
      <span class="t-xs"><?= _e('admin.dash.cron_or_console') ?></span>
    </div>
  </div>
<?php endif; ?>

<div class="kpi-row admin-kpis">
  <?= ui_stat(fmt_num($queue), __('admin.dash.kpi_queue'), 'timer', __('admin.dash.kpi_queue_hint', ['n' => fmt_num($counts['awaiting_verify'] ?? 0)])) ?>
  <?= ui_stat(fmt_num($counts['scored'] ?? 0), __('admin.dash.kpi_scored'), 'check-circle') ?>
  <?= ui_stat(fmt_num($counts['failed'] ?? 0), __('admin.dash.kpi_failed'), 'x-circle') ?>
  <?= ui_stat(fmt_money($spend_month), __('admin.dash.kpi_month'), 'coin', __('admin.dash.kpi_total', ['s' => fmt_money($spend_total)])) ?>
  <?= ui_stat(fmt_num($totals['models']), __('admin.dash.kpi_models'), 'robot', __('admin.dash.kpi_judges', ['n' => $totals['judges']])) ?>
  <?= ui_stat(fmt_num($totals['tasks']), __('admin.dash.kpi_tasks'), 'list', __('admin.dash.kpi_holdout', ['n' => $totals['holdout']])) ?>
</div>

<div class="admin-grid admin-grid-main is-stretch">
  <section class="card card-pad stack">
    <div class="card-head">
      <h2 class="card-title"><?= ui_icon('target', 18) ?> <?= _e('admin.dash.campaigns') ?></h2>
      <a class="btn btn-sm btn-ghost" href="<?= e(url('/admin/campaigns')) ?>"><?= _e('admin.dash.all') ?></a>
    </div>
    <?php if (!$campaigns): ?>
      <?= ui_empty(__('admin.dash.no_campaigns'), __('admin.dash.no_campaigns_text'), 'target', '<a class="btn btn-primary btn-sm" href="' . e(url('/admin/campaigns/new')) . '">' . _e('admin.campaigns.new') . '</a>') ?>
    <?php else: ?>
      <div class="stack">
        <?php foreach ($campaigns as $p): ?><?= partial('admin/_progress', ['p' => $p]) ?><?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="card card-pad stack">
    <div class="card-head"><h2 class="card-title"><?= ui_icon('layers', 18) ?> <?= _e('admin.dash.queue') ?></h2></div>
    <ul class="admin-bars">
      <?php foreach ($statuses as $s): $n = $counts[$s] ?? 0; ?>
        <li>
          <a href="<?= e(url('/admin/runs', ['status' => $s])) ?>"><?= ui_status($s) ?></a>
          <span class="admin-bar"><span class="admin-bar-fill s-<?= e($s) ?>" style="width:<?= e(number_format($n / $maxCount * 100, 2, '.', '')) ?>%"></span></span>
          <span class="t-num t-small"><?= e(fmt_num($n)) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
</div>

<div class="admin-grid admin-grid-main">
  <section class="card card-pad stack">
    <div class="card-head">
      <h2 class="card-title"><?= ui_icon('alert', 18) ?> <?= _e('admin.dash.failures') ?></h2>
      <a class="btn btn-sm btn-ghost" href="<?= e(url('/admin/runs', ['status' => 'failed'])) ?>"><?= _e('admin.dash.all') ?></a>
    </div>
    <?php if (!$failures): ?>
      <p class="t-muted t-small admin-quiet"><?= ui_icon('check', 16) ?> <?= _e('admin.dash.no_failures') ?></p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table table-compact">
          <thead><tr><th>#</th><th><?= _e('common.model') ?></th><th><?= _e('admin.runs.task') ?></th><th><?= _e('admin.runs.error') ?></th><th><?= _e('common.updated') ?></th></tr></thead>
          <tbody>
          <?php foreach ($failures as $f): ?>
            <tr>
              <td class="t-num"><a href="<?= e(url('/admin/runs/' . $f['id'])) ?>"><?= (int) $f['id'] ?></a></td>
              <td><?= e($f['model']) ?></td>
              <td><?= e(L($f, 'title')) ?></td>
              <td class="admin-err-cell t-xs" title="<?= e((string) $f['error']) ?>"><?= e(mb_strimwidth((string) $f['error'], 0, 90, '…')) ?></td>
              <td class="t-xs t-muted"><?= e(fmt_date($f['updated_at'], 'relative')) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="card card-pad stack">
    <div class="card-head"><h2 class="card-title"><?= ui_icon('activity', 18) ?> <?= _e('admin.dash.system') ?></h2></div>
    <dl class="admin-dl">
      <dt><?= _e('admin.dash.sys_key') ?></dt>
      <dd><?= $key_set ? ui_badge(__('admin.dash.configured'), 'green', 'check') . ' <span class="t-mono t-xs" dir="ltr">' . e($key_mask) . '</span>' : ui_badge(__('admin.dash.not_configured'), 'red', 'x') ?></dd>
      <dt><?= _e('admin.dash.sys_worker') ?></dt>
      <dd><?= $heartbeat_age === null ? ui_badge(__('admin.dash.never'), 'muted') : ui_badge(fmt_date(gmdate('Y-m-d H:i:s', time() - $heartbeat_age), 'relative'), $heartbeat_age <= 300 ? 'green' : 'gold', 'clock') ?></dd>
      <dt><?= _e('admin.dash.sys_aggregate') ?></dt>
      <dd><?= e($aggregate_last !== '' ? fmt_date($aggregate_last, 'relative') : __('admin.dash.never')) ?><?= $aggregate_dirty ? ' ' . ui_badge(__('admin.dash.dirty'), 'gold', null, __('admin.dash.dirty_tip')) : '' ?></dd>
      <dt><?= _e('admin.dash.sys_season') ?></dt>
      <dd class="t-mono"><?= e($totals['season']) ?></dd>
      <dt><?= _e('admin.dash.sys_requests') ?></dt>
      <dd><a href="<?= e(url('/admin/requests')) ?>"><?= _e('admin.dash.requests_new', ['n' => $totals['requests']]) ?></a></dd>
    </dl>
    <div class="cluster admin-card-actions">
      <form method="post" action="<?= e(url('/admin/settings/recompute')) ?>">
        <?= csrf_field() ?>
        <button class="btn btn-sm btn-outline" type="submit"<?= $engine['Aggregator'] ? '' : ' disabled' ?>><?= ui_icon('refresh', 14) ?><span><?= _e('admin.dash.recompute') ?></span></button>
      </form>
      <a class="btn btn-sm btn-ghost" href="<?= e(url('/admin/models/sync')) ?>"><?= ui_icon('download', 14) ?><span><?= _e('admin.sync.title') ?></span></a>
      <a class="btn btn-sm btn-ghost" href="<?= e(url('/admin/forge')) ?>"><?= ui_icon('wand', 14) ?><span><?= _e('admin.nav.forge') ?></span></a>
      <a class="btn btn-sm btn-ghost" href="<?= e(url('/admin/verify')) ?>"><?= ui_icon('shield', 14) ?><span><?= _e('admin.nav.verify') ?></span></a>
    </div>
  </section>
</div>
