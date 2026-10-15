<?php
/** Runs explorer. */
defined('WB') || exit;
$q = array_filter($filters, static fn($v) => $v !== '' && $v !== 0);
echo partial('admin/_head', ['title' => __('admin.nav.runs'), 'sub' => __('admin.runs.sub', ['n' => fmt_num($total)])]);
?>
<form class="admin-filters card card-pad" method="get" action="<?= e(url('/admin/runs')) ?>">
  <div class="field"><label class="label" for="rf-c"><?= _e('admin.runs.campaign') ?></label><select class="select" id="rf-c" name="campaign" data-autosubmit><option value=""><?= _e('common.all') ?></option><?php foreach ($campaigns as $c): ?><option value="<?= (int) $c['id'] ?>"<?= $filters['campaign'] === (int) $c['id'] ? ' selected' : '' ?>>#<?= (int) $c['id'] ?> <?= e($c['name']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label class="label" for="rf-m"><?= _e('common.model') ?></label><select class="select" id="rf-m" name="model" data-autosubmit><option value=""><?= _e('common.all') ?></option><?php foreach ($models as $m): ?><option value="<?= (int) $m['id'] ?>"<?= $filters['model'] === (int) $m['id'] ? ' selected' : '' ?>><?= e($m['name']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label class="label" for="rf-t"><?= _e('admin.runs.task') ?></label><select class="select" id="rf-t" name="task" data-autosubmit><option value=""><?= _e('common.all') ?></option><?php foreach ($tasks as $t): ?><option value="<?= (int) $t['id'] ?>"<?= $filters['task'] === (int) $t['id'] ? ' selected' : '' ?>><?= e($t['slug']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label class="label" for="rf-s"><?= _e('admin.models.status') ?></label><select class="select" id="rf-s" name="status" data-autosubmit><option value=""><?= _e('common.all') ?></option><?php foreach ($statuses as $s): ?><option value="<?= e($s) ?>"<?= $filters['status'] === $s ? ' selected' : '' ?>><?= e(__('status.' . $s)) ?></option><?php endforeach; ?></select></div>
  <div class="field admin-filter-check"><label class="checkbox"><input type="checkbox" name="contested" value="1"<?= $filters['contested'] === '1' ? ' checked' : '' ?> data-autosubmit> <?= _e('admin.runs.contested_only') ?></label></div>
  <div class="admin-filters-actions">
    <button class="btn btn-outline" type="submit"><?= ui_icon('filter', 16) ?><span><?= _e('admin.filter') ?></span></button>
    <?php if ($q): ?><a class="btn btn-ghost" href="<?= e(url('/admin/runs')) ?>"><?= _e('admin.reset') ?></a><?php endif; ?>
  </div>
</form>
<?php if (!$runs): ?>
  <?= ui_empty(__('admin.runs.empty'), __('admin.runs.empty_text'), 'history') ?>
<?php else: ?>
<div class="card">
  <div class="table-wrap">
    <table class="table table-compact admin-table">
      <thead><tr>
        <th>#</th><th><?= _e('common.model') ?></th><th><?= _e('admin.runs.task') ?></th><th><?= _e('admin.models.status') ?></th>
        <th class="num"><?= _e('admin.runs.final') ?></th><th class="num"><?= _e('admin.runs.auto') ?></th><th class="num"><?= _e('admin.runs.judge') ?></th>
        <th class="num"><?= _e('common.cost') ?></th><th class="num"><?= _e('common.latency') ?></th><th><?= _e('common.updated') ?></th>
      </tr></thead>
      <tbody>
      <?php foreach ($runs as $r): ?>
        <tr>
          <td class="t-num"><a class="admin-strong" href="<?= e(url('/admin/runs/' . $r['id'])) ?>"><?= (int) $r['id'] ?></a></td>
          <td><?= ui_model_chip(['name' => $r['model'], 'provider' => $r['provider'], 'provider_name' => $r['provider_name']], false, false) ?></td>
          <td><span class="t-small"><?= e(L($r, 'title')) ?></span><div class="t-xs t-muted"><span class="t-mono" dir="ltr"><?= e($r['task_slug']) ?></span> · <?= _e('admin.runs.attempt_n', ['n' => (int) $r['attempt']]) ?><?= $r['visibility'] === 'holdout' ? ' · ' . ui_icon('lock', 11) : '' ?></div></td>
          <td><?= ui_status($r['status']) ?><?= (int) $r['contested'] ? ' ' . ui_badge(__('admin.runs.contested'), 'gold') : '' ?><?= (int) $r['tries'] > 0 && $r['status'] !== 'scored' ? ' <span class="t-xs t-muted">×' . (int) $r['tries'] . '</span>' : '' ?>
            <?php if ($r['status'] === 'failed' && $r['error']): ?><div class="t-xs t-red admin-err-cell" title="<?= e((string) $r['error']) ?>"><?= e(mb_strimwidth((string) $r['error'], 0, 70, '…')) ?></div><?php endif; ?></td>
          <td class="num"><?= $r['final_score'] !== null ? ui_heat_cell($r['final_score']) : '—' ?></td>
          <td class="num t-num"><?= e(fmt_score($r['auto_score'])) ?></td>
          <td class="num t-num"><?= e(fmt_score($r['judge_score'])) ?><?= $r['judge_spread'] !== null ? '<div class="t-xs t-muted">±' . e(fmt_score($r['judge_spread'])) . '</div>' : '' ?></td>
          <td class="num t-num"><?= e(fmt_money($r['cost_usd'])) ?></td>
          <td class="num t-num"><bdi><?= e(fmt_ms($r['latency_ms'])) ?></bdi></td>
          <td class="t-xs t-muted"><?= e(fmt_date($r['updated_at'], 'relative')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?= ui_pagination($page, $pages, '/admin/runs', $q) ?>
<?php endif; ?>
