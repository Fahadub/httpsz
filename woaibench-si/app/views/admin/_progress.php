<?php
/** One campaign progress block. $p = normalised progress (Admin::normalizeProgress), $link bool. */
defined('WB') || exit;
$link = $link ?? true;
?>
<div class="admin-progress" data-campaign="<?= (int) $p['id'] ?>">
  <div class="admin-progress-top">
    <?php if ($link): ?><a class="admin-progress-name" href="<?= e(url('/admin/campaigns/' . $p['id'])) ?>"><?= e($p['name']) ?></a><?php else: ?><span class="admin-progress-name"><?= e($p['name']) ?></span><?php endif; ?>
    <?= $p['status'] !== '' ? ui_status($p['status']) : '' ?>
    <span class="admin-progress-pct t-num" data-pct><?= e(number_format($p['pct'], 1)) ?>%</span>
  </div>
  <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= e((string) round($p['pct'])) ?>" aria-label="<?= e($p['name']) ?>"><div class="progress-bar" data-bar style="width:<?= e(number_format($p['pct'], 2, '.', '')) ?>%"></div></div>
  <div class="admin-progress-meta t-xs t-muted">
    <span data-done><?= _e('admin.progress.done_of', ['d' => fmt_num($p['done']), 't' => fmt_num($p['total'])]) ?></span>
    <?php if ($p['failed'] > 0): ?><span class="t-red" data-failed><?= _e('admin.progress.failed', ['n' => fmt_num($p['failed'])]) ?></span><?php endif; ?>
    <span data-spent><?= _e('admin.progress.spent', ['s' => \WB\Admin\Admin::iso(fmt_money($p['spent']))]) ?><?= $p['est'] !== null ? ' / ' . e(\WB\Admin\Admin::iso(fmt_money($p['est']))) : '' ?></span>
  </div>
</div>
