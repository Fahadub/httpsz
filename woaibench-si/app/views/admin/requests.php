<?php
/** Model requests triage. */
defined('WB') || exit;
echo partial('admin/_head', [
    'title' => __('admin.nav.requests'),
    'sub' => __('admin.requests.sub'),
    'actions' => '<a class="btn btn-ghost" href="' . e(url('/admin/models/sync')) . '">' . ui_icon('download', 16) . '<span>' . _e('admin.sync.title') . '</span></a>',
]);
?>
<nav class="tabs admin-tabs">
  <?php foreach (['new', 'done', 'dismissed', 'all'] as $s): ?>
    <a class="tab<?= $status === $s || ($s === 'all' && !in_array($status, ['new', 'done', 'dismissed'], true)) ? ' active' : '' ?>" href="<?= e(url('/admin/requests', ['status' => $s])) ?>"><?= _e('admin.requests.st.' . $s) ?><?php if ($s !== 'all'): ?> <span class="t-num t-muted"><?= (int) ($counts[$s] ?? 0) ?></span><?php endif; ?></a>
  <?php endforeach; ?>
</nav>
<?php if (!$requests): ?>
  <?= ui_empty(__('admin.requests.empty'), __('admin.requests.empty_text'), 'mail') ?>
<?php else: ?>
<div class="card">
  <div class="table-wrap">
    <table class="table admin-table">
      <thead><tr><th><?= _e('common.model') ?></th><th><?= _e('admin.requests.note') ?></th><th><?= _e('admin.requests.contact') ?></th><th><?= _e('admin.campaigns.created') ?></th><th><?= _e('admin.models.status') ?></th><th><?= _e('admin.actions') ?></th></tr></thead>
      <tbody>
      <?php foreach ($requests as $rq): $knownId = $rq['or_id'] ? ($known[$rq['or_id']] ?? null) : null; ?>
        <tr>
          <td><strong><?= e($rq['name']) ?></strong><?php if ($rq['or_id']): ?><div class="t-mono t-xs t-muted" dir="ltr"><?= e($rq['or_id']) ?></div><?php endif; ?>
            <?php if ($knownId): ?><a class="t-xs" href="<?= e(url('/admin/models/' . $knownId)) ?>"><?= ui_icon('check', 12) ?> <?= _e('admin.requests.in_catalog') ?></a><?php endif; ?></td>
          <td class="t-small admin-note"><?= nl2br(e((string) $rq['note'])) ?></td>
          <td class="t-xs"><?= $rq['email'] ? '<a href="mailto:' . e($rq['email']) . '" dir="ltr">' . e($rq['email']) . '</a>' : '—' ?></td>
          <td class="t-xs t-muted"><?= e(fmt_date($rq['created_at'], 'relative')) ?></td>
          <td><?= ui_badge(__('admin.requests.st.' . (in_array($rq['status'], ['new', 'done', 'dismissed'], true) ? $rq['status'] : 'new')), ['new' => 'gold', 'done' => 'green', 'dismissed' => 'muted'][$rq['status']] ?? 'muted') ?></td>
          <td>
            <div class="cluster admin-actions">
              <?php if ($rq['status'] !== 'done'): ?><form method="post" action="<?= e(url('/admin/requests/' . $rq['id'])) ?>"><?= csrf_field() ?><input type="hidden" name="status" value="done"><button class="btn btn-sm btn-outline" type="submit"><?= ui_icon('check', 14) ?><span><?= _e('admin.requests.mark_done') ?></span></button></form><?php endif; ?>
              <?php if ($rq['status'] !== 'dismissed'): ?><form method="post" action="<?= e(url('/admin/requests/' . $rq['id'])) ?>"><?= csrf_field() ?><input type="hidden" name="status" value="dismissed"><button class="btn btn-sm btn-ghost" type="submit"><?= _e('admin.requests.dismiss') ?></button></form><?php endif; ?>
              <?php if ($rq['status'] !== 'new'): ?><form method="post" action="<?= e(url('/admin/requests/' . $rq['id'])) ?>"><?= csrf_field() ?><input type="hidden" name="status" value="new"><button class="btn btn-sm btn-ghost" type="submit"><?= _e('admin.requests.reopen') ?></button></form><?php endif; ?>
              <?php if (!$knownId && $rq['or_id']): ?><a class="btn btn-sm btn-ghost" href="<?= e(url('/admin/models/sync')) ?>"><?= ui_icon('download', 14) ?><span><?= _e('admin.requests.import') ?></span></a><?php endif; ?>
              <form method="post" action="<?= e(url('/admin/requests/' . $rq['id'])) ?>" data-confirm="<?= _e('admin.requests.delete_confirm') ?>"><?= csrf_field() ?><input type="hidden" name="status" value="delete"><button class="btn btn-sm btn-ghost btn-icon" type="submit" aria-label="<?= _e('common.delete') ?>"><?= ui_icon('trash', 14) ?></button></form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?= ui_pagination($page, $pages, '/admin/requests', ['status' => $status]) ?>
<?php endif; ?>
