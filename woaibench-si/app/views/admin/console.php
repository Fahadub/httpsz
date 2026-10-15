<?php
/** Benchmark control room: drives POST /admin/api/step in a loop + embedded Verification Station. */
defined('WB') || exit;
echo partial('admin/_head', [
    'title' => __('admin.nav.console'),
    'sub' => __('admin.console.sub'),
    'actions' => '<a class="btn btn-ghost" href="' . e(url('/admin/campaigns/new')) . '">' . ui_icon('plus', 16) . '<span>' . _e('admin.campaigns.new') . '</span></a>',
]);
$cfg = [
    'stepUrl' => url('/admin/api/step'),
    'progressUrl' => url('/admin/api/campaigns'),
    'runUrl' => url('/admin/runs/'),
    'campaignUrl' => url('/admin/campaigns/'),
    'statuses' => $statuses,
    'verifier' => $verifier_js,
];
?>
<section class="admin-console card card-glass" data-console>
  <div class="admin-console-hero">
    <div class="admin-console-state">
      <span class="admin-console-orb" data-console-orb aria-hidden="true"></span>
      <div>
        <div class="admin-console-status" data-console-status><?= _e('admin.console.stopped') ?></div>
        <div class="t-xs t-muted" data-console-detail><?= _e('admin.console.hint') ?></div>
      </div>
    </div>
    <div class="admin-console-controls">
      <label class="checkbox"><input type="checkbox" data-console-verify<?= $verifier_js ? ' checked' : ' disabled' ?>> <?= _e('admin.console.with_station') ?></label>
      <button class="btn btn-primary btn-lg admin-console-btn" type="button" data-console-toggle aria-pressed="false">
        <span class="admin-console-btn-start"><?= ui_icon('play', 18) ?><span><?= _e('admin.console.start') ?></span></span>
        <span class="admin-console-btn-stop"><?= ui_icon('pause', 18) ?><span><?= _e('admin.console.stop') ?></span></span>
      </button>
    </div>
  </div>
  <div class="admin-console-meters">
    <div class="admin-meter"><span class="admin-meter-label"><?= _e('admin.console.steps') ?></span><span class="admin-meter-value t-num" data-m="steps">0</span></div>
    <div class="admin-meter"><span class="admin-meter-label"><?= _e('admin.console.units') ?></span><span class="admin-meter-value t-num" data-m="units">0</span></div>
    <div class="admin-meter"><span class="admin-meter-label"><?= _e('admin.console.ok') ?></span><span class="admin-meter-value t-num t-green" data-m="ok">0</span></div>
    <div class="admin-meter"><span class="admin-meter-label"><?= _e('admin.console.errors') ?></span><span class="admin-meter-value t-num t-red" data-m="errors">0</span></div>
    <div class="admin-meter"><span class="admin-meter-label"><?= _e('admin.console.verified') ?></span><span class="admin-meter-value t-num" data-m="verified">0</span></div>
    <div class="admin-meter admin-meter-spend"><span class="admin-meter-label"><?= _e('admin.console.spent') ?></span><span class="admin-meter-value t-num t-gold" data-m="spent"><?= e(fmt_money($spend_total)) ?></span><span class="t-xs t-muted" data-m="session"><?= _e('admin.console.session', ['s' => \WB\Admin\Admin::iso('$0')]) ?></span></div>
  </div>
</section>

<div class="admin-grid admin-grid-main">
  <section class="card admin-station-card">
    <div class="card-head card-pad">
      <h2 class="card-title"><?= ui_icon('shield', 18) ?> <?= _e('admin.nav.verify') ?></h2>
      <span class="t-xs t-muted" data-station-stats></span>
    </div>
    <div class="admin-station" id="station">
      <?php if ($verifier_js): ?>
        <?= ui_empty(__('admin.console.station_title'), __('admin.console.station_idle'), 'shield') ?>
      <?php else: ?>
        <div class="alert alert-info"><?= _e('admin.console.no_verifier') ?></div>
      <?php endif; ?>
    </div>
  </section>
  <div class="stack-lg">
    <section class="card card-pad stack">
      <div class="card-head"><h2 class="card-title"><?= ui_icon('target', 18) ?> <?= _e('admin.dash.campaigns') ?></h2></div>
      <div class="stack" data-console-campaigns>
        <?php if (!$campaigns): ?>
          <?= ui_empty(__('admin.dash.no_campaigns'), __('admin.console.no_campaigns_text'), 'target', '<a class="btn btn-primary btn-sm" href="' . e(url('/admin/campaigns/new')) . '">' . _e('admin.campaigns.new') . '</a>') ?>
        <?php else: ?>
          <?php foreach ($campaigns as $p): ?><?= partial('admin/_progress', ['p' => $p]) ?><?php endforeach; ?>
        <?php endif; ?>
      </div>
    </section>
    <section class="card card-pad stack">
      <div class="card-head"><h2 class="card-title"><?= ui_icon('layers', 18) ?> <?= _e('admin.dash.queue') ?></h2></div>
      <ul class="admin-counts" data-console-counts>
        <?php foreach ($statuses as $s): ?>
          <li data-status="<?= e($s) ?>"><span class="admin-dot s-<?= e($s) ?>"></span><span><?= e(__('status.' . $s)) ?></span><span class="t-num" data-n><?= e(fmt_num($counts[$s] ?? 0)) ?></span></li>
        <?php endforeach; ?>
      </ul>
    </section>
  </div>
</div>

<div class="admin-grid admin-grid-2">
  <section class="card admin-log-card">
    <div class="card-head card-pad">
      <h2 class="card-title"><?= ui_icon('activity', 18) ?> <?= _e('admin.console.log') ?></h2>
      <button class="btn btn-sm btn-ghost" type="button" data-console-clear><?= _e('admin.console.clear') ?></button>
    </div>
    <ol class="admin-log" data-console-log aria-live="off">
      <li class="admin-log-empty t-muted t-small"><?= _e('admin.console.log_empty') ?></li>
    </ol>
  </section>
  <section class="card admin-log-card">
    <div class="card-head card-pad"><h2 class="card-title"><?= ui_icon('shield', 18) ?> <?= _e('admin.console.station_log') ?></h2></div>
    <ol class="admin-log" data-station-log aria-live="off">
      <li class="admin-log-empty t-muted t-small"><?= $verifier_js ? _e('admin.console.station_idle') : _e('admin.console.no_verifier') ?></li>
    </ol>
  </section>
</div>
<script type="application/json" id="console-cfg"><?= js_json($cfg) ?></script>
