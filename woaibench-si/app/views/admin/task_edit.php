<?php
/** Task create/edit with live JSON validation and preview. */
defined('WB') || exit;
$isNew = $task === null;
$f = $form;
$sel = static fn($a, $b) => (string) $a === (string) $b ? ' selected' : '';
echo partial('admin/_head', [
    'back' => url('/admin/tasks'),
    'eyebrow' => $isNew ? __('admin.tasks.new') : __('admin.tasks.edit_v', ['v' => (int) $task['version']]),
    'title' => $isNew ? __('admin.tasks.new') : L($task, 'title'),
    'sub' => $isNew ? __('admin.tasks.new_sub') : $task['slug'],
]);
?>
<?php if ($result && ($result['errors'] || $result['warnings'])): ?>
  <div class="card card-pad admin-validation stack-sm" id="server-validation">
    <?php if ($result['errors']): ?>
      <strong class="t-red"><?= ui_icon('x-circle', 16) ?> <?= _e('admin.tasks.errors_n', ['n' => count($result['errors'])]) ?></strong>
      <ul class="admin-msgs is-error"><?php foreach ($result['errors'] as $m): ?><li class="t-mono t-xs" dir="ltr"><?= e($m) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <?php if ($result['warnings']): ?>
      <strong class="t-amber"><?= ui_icon('alert', 16) ?> <?= _e('admin.tasks.warnings_n', ['n' => count($result['warnings'])]) ?></strong>
      <ul class="admin-msgs is-warn"><?php foreach ($result['warnings'] as $m): ?><li class="t-mono t-xs" dir="ltr"><?= e($m) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="admin-split">
  <form class="form stack-lg" method="post" action="<?= e(url($isNew ? '/admin/tasks/new' : '/admin/tasks/' . $task['id'])) ?>" id="task-form" data-task-form data-validate-url="<?= e(url('/admin/api/validate-task')) ?>" data-task-id="<?= $isNew ? 0 : (int) $task['id'] ?>" novalidate>
    <?= csrf_field() ?>
    <section class="card card-pad stack">
      <h2 class="card-title"><?= ui_icon('file', 18) ?> <?= _e('admin.tasks.basics') ?></h2>
      <div class="form-row">
        <div class="field"><label class="label" for="tk-slug"><?= _e('admin.models.slug') ?></label><input class="input t-mono" id="tk-slug" name="slug" value="<?= e($f['slug']) ?>" dir="ltr" required pattern="[a-z0-9][a-z0-9\-]+[a-z0-9]" maxlength="120"><span class="help"><?= _e('admin.tasks.slug_help') ?></span></div>
        <div class="field"><label class="label" for="tk-track"><?= _e('admin.tasks.track') ?></label>
          <select class="select" id="tk-track" name="track"><?php foreach ($tracks as $tr): ?><option value="<?= e($tr['slug']) ?>"<?= $sel($f['track'], $tr['slug']) ?> data-aw="<?= e((string) (float) $tr['auto_weight']) ?>"><?= e(L($tr, 'name')) ?></option><?php endforeach; ?></select>
        </div>
      </div>
      <div class="form-row">
        <div class="field"><label class="label" for="tk-ten"><?= _e('admin.tasks.title_en') ?></label><input class="input" id="tk-ten" name="title_en" value="<?= e($f['title_en']) ?>" dir="ltr" lang="en" required maxlength="200"></div>
        <div class="field"><label class="label" for="tk-tar"><?= _e('admin.tasks.title_ar') ?></label><input class="input" id="tk-tar" name="title_ar" value="<?= e($f['title_ar']) ?>" dir="rtl" lang="ar" required maxlength="200"></div>
      </div>
      <div class="form-row">
        <div class="field"><label class="label" for="tk-sen"><?= _e('admin.tasks.summary_en') ?></label><textarea class="textarea" id="tk-sen" name="summary_en" rows="2" dir="ltr" lang="en"><?= e($f['summary_en']) ?></textarea></div>
        <div class="field"><label class="label" for="tk-sar"><?= _e('admin.tasks.summary_ar') ?></label><textarea class="textarea" id="tk-sar" name="summary_ar" rows="2" dir="rtl" lang="ar"><?= e($f['summary_ar']) ?></textarea></div>
      </div>
      <div class="admin-grid admin-grid-4">
        <div class="field"><label class="label" for="tk-type"><?= _e('admin.tasks.output_type') ?></label>
          <select class="select" id="tk-type" name="output_type"><?php foreach (\WB\Admin\TaskValidator::OUTPUT_TYPES as $ot): ?><option value="<?= e($ot) ?>"<?= $sel($f['output_type'], $ot) ?>><?= _e('admin.otype.' . $ot) ?></option><?php endforeach; ?></select>
        </div>
        <div class="field"><label class="label" for="tk-diff"><?= _e('admin.tasks.difficulty') ?></label>
          <select class="select" id="tk-diff" name="difficulty"><?php foreach ([1 => 'common.diff_easy', 2 => 'common.diff_medium', 3 => 'common.diff_hard', 4 => 'common.diff_expert'] as $dv => $dk): ?><option value="<?= $dv ?>"<?= $sel($f['difficulty'], $dv) ?>><?= $dv ?> · <?= _e($dk) ?></option><?php endforeach; ?></select>
        </div>
        <div class="field"><label class="label" for="tk-vis"><?= _e('admin.tasks.visibility') ?></label>
          <select class="select" id="tk-vis" name="visibility"><option value="public"<?= $sel($f['visibility'], 'public') ?>><?= _e('admin.tasks.public') ?></option><option value="holdout"<?= $sel($f['visibility'], 'holdout') ?>><?= _e('admin.tasks.holdout') ?></option></select>
        </div>
        <div class="field"><label class="label" for="tk-aw"><?= _e('admin.tracks.auto_weight') ?></label><input class="input t-num" id="tk-aw" name="auto_weight" value="<?= e($f['auto_weight']) ?>" dir="ltr" inputmode="decimal" placeholder="<?= _e('admin.tasks.aw_placeholder') ?>"></div>
      </div>
      <label class="checkbox"><input type="checkbox" name="is_active" value="1"<?= !empty($f['is_active']) ? ' checked' : '' ?>> <?= _e('admin.tasks.active_help') ?></label>
    </section>

    <section class="card card-pad stack">
      <div class="card-head"><h2 class="card-title"><?= ui_icon('terminal', 18) ?> <?= _e('admin.tasks.prompt') ?></h2><span class="t-xs t-muted t-num" data-count-for="#tk-prompt"></span></div>
      <textarea class="textarea t-mono admin-code" id="tk-prompt" name="prompt" rows="14" dir="ltr" spellcheck="false" required><?= e($f['prompt']) ?></textarea>
      <span class="help"><?= _e('admin.tasks.prompt_help') ?></span>
    </section>

    <section class="card card-pad stack">
      <div class="card-head"><h2 class="card-title"><?= ui_icon('scale', 18) ?> <?= _e('admin.tasks.rubric') ?></h2><span class="admin-json-status" data-json-status="#tk-rubric"></span></div>
      <textarea class="textarea t-mono admin-code" id="tk-rubric" name="rubric_json" rows="12" dir="ltr" spellcheck="false" data-json-field="rubric"><?= e($f['rubric_json']) ?></textarea>
      <span class="help"><?= _e('admin.tasks.rubric_help') ?></span>
    </section>

    <section class="card card-pad stack">
      <div class="card-head"><h2 class="card-title"><?= ui_icon('check-circle', 18) ?> <?= _e('admin.tasks.checks') ?></h2><span class="admin-json-status" data-json-status="#tk-checks"></span></div>
      <textarea class="textarea t-mono admin-code" id="tk-checks" name="checks_json" rows="18" dir="ltr" spellcheck="false" data-json-field="checks"><?= e($f['checks_json']) ?></textarea>
      <details class="admin-ref">
        <summary><?= _e('admin.tasks.reference') ?></summary>
        <div class="admin-ref-grid t-xs">
          <div><strong>static</strong><p class="t-mono" dir="ltr"><?= e(implode(' · ', array_keys(\WB\Admin\TaskValidator::STATIC_TYPES))) ?></p></div>
          <div><strong>browser</strong><p class="t-mono" dir="ltr"><?= e(implode(' · ', array_keys(\WB\Admin\TaskValidator::BROWSER_TYPES))) ?></p></div>
          <div><strong>tests.cases[].cmp</strong><p class="t-mono" dir="ltr"><?= e(implode(' · ', \WB\Admin\TaskValidator::CMP)) ?></p></div>
          <div><strong>answer.type</strong><p class="t-mono" dir="ltr"><?= e(implode(' · ', array_keys(\WB\Admin\TaskValidator::ANSWER_TYPES))) ?></p></div>
          <div><strong>agent.goal_checks[].type</strong><p class="t-mono" dir="ltr"><?= e(implode(' · ', array_keys(\WB\Admin\TaskValidator::GOAL_TYPES))) ?></p></div>
          <div><strong>agent.tools</strong><p class="t-mono" dir="ltr"><?= e(implode(' · ', \WB\Admin\TaskValidator::AGENT_TOOLS)) ?></p></div>
        </div>
      </details>
    </section>

    <div class="form-actions admin-sticky-actions">
      <button class="btn btn-primary" type="submit"><?= ui_icon('check', 16) ?><span><?= _e('common.save') ?></span></button>
      <button class="btn btn-outline" type="button" data-validate-server><?= ui_icon('shield', 16) ?><span><?= _e('admin.tasks.validate') ?></span></button>
      <span class="t-small" data-validate-out aria-live="polite"></span>
    </div>
  </form>

  <aside class="stack-lg admin-aside">
    <section class="card card-pad stack-sm" aria-live="polite">
      <h2 class="card-title"><?= ui_icon('eye', 18) ?> <?= _e('admin.tasks.preview') ?></h2>
      <div class="admin-preview" data-task-preview>
        <p class="t-muted t-small"><?= _e('common.loading') ?></p>
      </div>
    </section>
    <?php if (!$isNew): ?>
    <section class="card card-pad stack-sm">
      <h2 class="card-title"><?= ui_icon('info', 18) ?> <?= _e('admin.tasks.meta') ?></h2>
      <dl class="admin-dl">
        <dt><?= _e('admin.tasks.version') ?></dt><dd class="t-num">v<?= (int) $task['version'] ?></dd>
        <dt><?= _e('admin.tasks.source') ?></dt><dd><?= _e('admin.source.' . (in_array($task['source'], \WB\Admin\TaskStore::SOURCES, true) ? $task['source'] : 'manual')) ?></dd>
        <dt><?= _e('admin.models.runs') ?></dt><dd><a href="<?= e(url('/admin/runs', ['task' => $task['id']])) ?>"><?= e(fmt_num($run_count)) ?></a></dd>
        <dt><?= _e('admin.tasks.season') ?></dt><dd class="t-mono"><?= e((string) $task['season']) ?></dd>
        <dt><?= _e('common.updated') ?></dt><dd><?= e(fmt_date($task['updated_at'], 'datetime')) ?></dd>
      </dl>
      <p class="help"><?= _e('admin.tasks.version_help') ?></p>
      <div class="cluster">
        <?php foreach (['duplicate' => ['copy', 'admin.tasks.duplicate'], ((int) $task['is_active'] ? 'deactivate' : 'activate') => ['power', (int) $task['is_active'] ? 'admin.tasks.deactivate' : 'admin.tasks.activate']] as $act => [$ic, $label]): ?>
          <form method="post" action="<?= e(url('/admin/tasks/' . $task['id'] . '/action')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="<?= e($act) ?>"><button class="btn btn-sm btn-ghost" type="submit"><?= ui_icon($ic === 'power' ? 'bolt' : $ic, 14) ?><span><?= _e($label) ?></span></button></form>
        <?php endforeach; ?>
        <?php if ($task['visibility'] === 'public'): ?><a class="btn btn-sm btn-ghost" href="<?= e(url('/task/' . $task['slug'])) ?>" target="_blank" rel="noopener"><?= ui_icon('external', 14) ?><span><?= _e('admin.models.public_page') ?></span></a><?php endif; ?>
      </div>
      <form class="admin-danger-inline" method="post" action="<?= e(url('/admin/tasks/' . $task['id'] . '/action')) ?>" data-confirm="<?= _e('admin.tasks.delete_confirm') ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete">
        <button class="btn btn-sm btn-danger" type="submit"<?= $run_count > 0 ? ' disabled title="' . _e('admin.tasks.err_has_runs') . '"' : '' ?>><?= ui_icon('trash', 14) ?><span><?= _e('common.delete') ?></span></button>
      </form>
    </section>
    <?php endif; ?>
  </aside>
</div>
<script type="application/json" id="task-spec"><?= js_json($spec) ?></script>
