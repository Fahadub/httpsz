<?php
/** Tasks list with filters. */
defined('WB') || exit;
$q = array_filter($filters, static fn($v) => $v !== '');
echo partial('admin/_head', [
    'title' => __('admin.nav.tasks'),
    'sub' => __('admin.tasks.sub', ['n' => fmt_num($total)]),
    'actions' => '<a class="btn btn-ghost" href="' . e(url('/admin/tasks/export', $q)) . '">' . ui_icon('download', 16) . '<span>' . _e('admin.tasks.export') . '</span></a>'
        . '<a class="btn btn-ghost" href="' . e(url('/admin/tasks/import')) . '">' . ui_icon('upload', 16) . '<span>' . _e('admin.tasks.import') . '</span></a>'
        . '<a class="btn btn-primary" href="' . e(url('/admin/tasks/new')) . '">' . ui_icon('plus', 16) . '<span>' . _e('admin.tasks.new') . '</span></a>',
]);
$opt = static function (string $name, string $current, array $options): string {
    $h = '<option value="">' . _e('common.all') . '</option>';
    foreach ($options as $v => $label) {
        $h .= '<option value="' . e((string) $v) . '"' . ((string) $v === $current ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $h;
};
$trackOpts = [];
foreach ($tracks as $t) {
    $trackOpts[$t['slug']] = L($t, 'name');
}
?>
<form class="admin-filters card card-pad" method="get" action="<?= e(url('/admin/tasks')) ?>">
  <div class="field"><label class="label" for="tf-q"><?= _e('common.search') ?></label><input class="input" id="tf-q" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="<?= _e('admin.tasks.search_ph') ?>"></div>
  <div class="field"><label class="label" for="tf-track"><?= _e('admin.tasks.track') ?></label><select class="select" id="tf-track" name="track" data-autosubmit><?= $opt('track', $filters['track'], $trackOpts) ?></select></div>
  <div class="field"><label class="label" for="tf-vis"><?= _e('admin.tasks.visibility') ?></label><select class="select" id="tf-vis" name="visibility" data-autosubmit><?= $opt('visibility', $filters['visibility'], ['public' => __('admin.tasks.public'), 'holdout' => __('admin.tasks.holdout')]) ?></select></div>
  <div class="field"><label class="label" for="tf-active"><?= _e('admin.models.status') ?></label><select class="select" id="tf-active" name="active" data-autosubmit><?= $opt('active', $filters['active'], ['1' => __('admin.models.active'), '0' => __('admin.models.inactive')]) ?></select></div>
  <div class="field"><label class="label" for="tf-type"><?= _e('admin.tasks.output_type') ?></label><select class="select" id="tf-type" name="type" data-autosubmit><?= $opt('type', $filters['type'], array_combine(\WB\Admin\TaskValidator::OUTPUT_TYPES, array_map(static fn($t) => __('admin.otype.' . $t), \WB\Admin\TaskValidator::OUTPUT_TYPES))) ?></select></div>
  <div class="field"><label class="label" for="tf-src"><?= _e('admin.tasks.source') ?></label><select class="select" id="tf-src" name="source" data-autosubmit><?= $opt('source', $filters['source'], array_combine(\WB\Admin\TaskStore::SOURCES, array_map(static fn($s) => __('admin.source.' . $s), \WB\Admin\TaskStore::SOURCES))) ?></select></div>
  <div class="admin-filters-actions">
    <button class="btn btn-outline" type="submit"><?= ui_icon('filter', 16) ?><span><?= _e('admin.filter') ?></span></button>
    <?php if ($q): ?><a class="btn btn-ghost" href="<?= e(url('/admin/tasks')) ?>"><?= _e('admin.reset') ?></a><?php endif; ?>
  </div>
</form>

<?php if (!$tasks): ?>
  <?= ui_empty(__('admin.tasks.empty'), __('admin.tasks.empty_text'), 'list', '<a class="btn btn-primary btn-sm" href="' . e(url('/admin/tasks/import')) . '">' . _e('admin.tasks.import') . '</a>') ?>
<?php else: ?>
<div class="card">
  <div class="table-wrap">
    <table class="table admin-table">
      <thead><tr>
        <th class="admin-col-title"><?= _e('admin.tasks.task') ?></th><th><?= _e('admin.tasks.track') ?></th><th><?= _e('admin.tasks.output_type') ?></th>
        <th><?= _e('admin.tasks.difficulty') ?></th><th><?= _e('admin.tasks.visibility') ?></th><th class="num"><?= _e('admin.tasks.version') ?></th>
        <th class="num"><?= _e('admin.models.runs') ?></th><th><?= _e('admin.models.active') ?></th><th><span class="sr-only"><?= _e('admin.actions') ?></span></th>
      </tr></thead>
      <tbody>
      <?php foreach ($tasks as $t): ?>
        <tr class="<?= (int) $t['is_active'] ? '' : 'is-muted' ?>">
          <td>
            <a class="admin-strong" href="<?= e(url('/admin/tasks/' . $t['id'])) ?>"><?= e(L($t, 'title')) ?></a>
            <div class="t-mono t-xs t-muted" dir="ltr"><?= e($t['slug']) ?> · <?= _e('admin.source.' . (in_array($t['source'], \WB\Admin\TaskStore::SOURCES, true) ? $t['source'] : 'manual')) ?></div>
          </td>
          <td><?= ui_track_badge(['name_en' => $t['name_en'], 'name_ar' => $t['name_ar'], 'icon' => $t['icon'], 'color' => $t['color']]) ?></td>
          <td><span class="pill t-mono"><?= e($t['output_type']) ?></span></td>
          <td><?= ui_difficulty((int) $t['difficulty']) ?></td>
          <td><?= $t['visibility'] === 'holdout' ? ui_badge(__('admin.tasks.holdout'), 'violet', 'lock') : ui_badge(__('admin.tasks.public'), 'muted', 'eye') ?></td>
          <td class="num t-num">v<?= (int) $t['version'] ?></td>
          <td class="num t-num"><a href="<?= e(url('/admin/runs', ['task' => $t['id']])) ?>"><?= e(fmt_num($t['run_count'])) ?></a></td>
          <td>
            <form method="post" action="<?= e(url('/admin/tasks/' . $t['id'] . '/action')) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="<?= (int) $t['is_active'] ? 'deactivate' : 'activate' ?>">
              <button class="btn btn-sm <?= (int) $t['is_active'] ? 'btn-ghost' : 'btn-outline' ?>" type="submit"><?= (int) $t['is_active'] ? ui_badge(__('admin.models.active'), 'green', 'check') : _e('admin.tasks.activate') ?></button>
            </form>
          </td>
          <td><a class="btn btn-sm btn-ghost btn-icon" href="<?= e(url('/admin/tasks/' . $t['id'])) ?>" aria-label="<?= _e('common.edit') ?>"><?= ui_icon('edit', 16) ?></a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?= ui_pagination($page, $pages, '/admin/tasks', $q) ?>
<?php endif; ?>
