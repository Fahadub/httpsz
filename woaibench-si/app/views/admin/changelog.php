<?php
/** Changelog list. */
defined('WB') || exit;
echo partial('admin/_head', [
    'title' => __('admin.nav.changelog'),
    'sub' => __('admin.changelog.sub'),
    'actions' => '<a class="btn btn-primary" href="' . e(url('/admin/changelog/new')) . '">' . ui_icon('plus', 16) . '<span>' . _e('admin.changelog.new') . '</span></a>',
]);
?>
<?php if (!$entries): ?>
  <?= ui_empty(__('admin.changelog.empty'), '', 'file', '<a class="btn btn-primary btn-sm" href="' . e(url('/admin/changelog/new')) . '">' . _e('admin.changelog.new') . '</a>') ?>
<?php else: ?>
<ol class="admin-timeline">
  <?php foreach ($entries as $en): ?>
    <li class="card card-pad admin-timeline-item">
      <div class="cluster">
        <span class="badge badge-gold t-mono"><?= e($en['version']) ?></span>
        <span class="t-xs t-muted"><?= e(fmt_date($en['published_at'], 'date')) ?></span>
        <?= strtotime($en['published_at'] . ' UTC') > time() ? ui_badge(__('admin.changelog.scheduled'), 'blue', 'clock') : '' ?>
      </div>
      <div class="admin-grid admin-grid-2">
        <div lang="ar" dir="rtl"><h3 class="t-h3"><?= e($en['title_ar']) ?></h3><p class="t-small t-muted"><?= nl2br(e(mb_strimwidth((string) $en['body_ar'], 0, 280, '…'))) ?></p></div>
        <div lang="en" dir="ltr"><h3 class="t-h3"><?= e($en['title_en']) ?></h3><p class="t-small t-muted"><?= nl2br(e(mb_strimwidth((string) $en['body_en'], 0, 280, '…'))) ?></p></div>
      </div>
      <div class="cluster">
        <a class="btn btn-sm btn-outline" href="<?= e(url('/admin/changelog/' . $en['id'])) ?>"><?= ui_icon('edit', 14) ?><span><?= _e('common.edit') ?></span></a>
        <form method="post" action="<?= e(url('/admin/changelog/' . $en['id'] . '/delete')) ?>" data-confirm="<?= _e('admin.changelog.delete_confirm') ?>"><?= csrf_field() ?><button class="btn btn-sm btn-ghost" type="submit"><?= ui_icon('trash', 14) ?><span><?= _e('common.delete') ?></span></button></form>
      </div>
    </li>
  <?php endforeach; ?>
</ol>
<?php endif; ?>
