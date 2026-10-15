<?php
/** Task import (upload or paste) with per-task error report. */
defined('WB') || exit;
echo partial('admin/_head', [
    'back' => url('/admin/tasks'),
    'title' => __('admin.tasks.import'),
    'sub' => __('admin.tasks.import_sub'),
    'actions' => '<a class="btn btn-ghost" href="' . e(url('/admin/tasks/export')) . '">' . ui_icon('download', 16) . '<span>' . _e('admin.tasks.export_all') . '</span></a>',
]);
$example = [[
    'slug' => 'example-task', 'track' => $tracks[0] ?? 'ui', 'title_en' => 'Example task', 'title_ar' => 'مهمة مثال',
    'summary_en' => 'What it tests.', 'summary_ar' => 'ما الذي تقيسه.', 'prompt' => 'Build …', 'output_type' => 'html', 'difficulty' => 2,
    'rubric' => [['key' => 'correctness', 'label_en' => 'Correctness', 'label_ar' => 'الصحة', 'weight' => 3, 'guide' => '…']],
    'checks' => ['browser' => [['id' => 'loads', 'type' => 'loads', 'weight' => 2]]], 'auto_weight' => null, 'visibility' => 'public',
]];
?>
<?php if ($result && !$result['ok']): ?>
  <div class="card card-pad stack admin-validation">
    <strong class="t-red"><?= ui_icon('x-circle', 16) ?> <?= _e('admin.tasks.import_report', ['n' => count($result['items'])]) ?></strong>
    <?php foreach ($result['items'] as $i => $item): ?>
      <div class="admin-import-item">
        <span class="badge badge-red t-mono"><?= $i >= 0 ? '#' . ((int) $i + 1) : 'JSON' ?></span>
        <?php if ($item['slug'] !== ''): ?><span class="t-mono t-small" dir="ltr"><?= e($item['slug']) ?></span><?php endif; ?>
        <ul class="admin-msgs is-error"><?php foreach ($item['messages'] as $m): ?><li class="t-mono t-xs" dir="ltr"><?= e($m) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endforeach; ?>
    <p class="t-small t-muted"><?= _e('admin.tasks.import_nothing') ?></p>
  </div>
<?php endif; ?>
<form class="form stack-lg" method="post" action="<?= e(url('/admin/tasks/import')) ?>" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <div class="admin-grid admin-grid-2">
    <section class="card card-pad stack">
      <h2 class="card-title"><label for="import-file"><?= ui_icon('upload', 18) ?> <?= _e('admin.tasks.import_file') ?></label></h2>
      <input class="input" type="file" id="import-file" name="file" accept="application/json,.json">
      <span class="help"><?= _e('admin.tasks.import_file_help') ?></span>
      <h2 class="card-title"><label for="imp-json"><?= ui_icon('copy', 18) ?> <?= _e('admin.tasks.import_paste') ?></label></h2>
      <textarea class="textarea t-mono admin-code" name="json" rows="16" dir="ltr" spellcheck="false" data-json-field="list" id="imp-json" placeholder="[ { &quot;slug&quot;: … } ]"><?= e($json) ?></textarea>
      <span class="admin-json-status" data-json-status="#imp-json"></span>
    </section>
    <section class="card card-pad stack">
      <h2 class="card-title"><?= ui_icon('settings', 18) ?> <?= _e('admin.tasks.import_options') ?></h2>
      <label class="checkbox"><input type="radio" name="mode" value="skip" checked> <?= _e('admin.tasks.mode_skip') ?></label>
      <label class="checkbox"><input type="radio" name="mode" value="update"> <?= _e('admin.tasks.mode_update') ?></label>
      <label class="checkbox"><input type="checkbox" name="activate" value="1"> <?= _e('admin.tasks.import_activate') ?></label>
      <div class="divider"></div>
      <h3 class="t-h3"><?= _e('admin.tasks.format') ?></h3>
      <p class="t-small t-muted"><?= _e('admin.tasks.format_help') ?></p>
      <pre class="code-block t-xs" dir="ltr" tabindex="0"><?= e(json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
      <p class="t-xs t-muted"><?= _e('admin.tasks.tracks_known') ?> <span class="t-mono" dir="ltr"><?= e(implode(', ', $tracks)) ?></span></p>
    </section>
  </div>
  <div class="form-actions"><button class="btn btn-primary" type="submit"><?= ui_icon('upload', 16) ?><span><?= _e('admin.tasks.import_go') ?></span></button></div>
</form>
<script type="application/json" id="task-spec"><?= js_json(\WB\Admin\TaskValidator::spec()) ?></script>
