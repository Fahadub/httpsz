<?php
/** Tracks editor (one form per track). */
defined('WB') || exit;
echo partial('admin/_head', ['title' => __('admin.nav.tracks'), 'sub' => __('admin.tracks.sub')]);
?>
<?php if (!$tracks): ?>
  <?= ui_empty(__('admin.tracks.empty'), __('admin.tracks.empty_text'), 'layers') ?>
<?php endif; ?>
<div class="admin-tracks stack-lg">
<?php foreach ($tracks as $t): $id = (int) $t['id']; $errs = $errors[$id] ?? []; ?>
  <form class="card card-pad admin-track form" id="track-<?= $id ?>" method="post" action="<?= e(url('/admin/tracks/' . $id)) ?>" style="--track:<?= e($t['color']) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="admin-track-head">
      <?= ui_track_badge($t) ?>
      <span class="t-mono t-xs t-muted" dir="ltr"><?= e($t['slug']) ?></span>
      <span class="t-xs t-muted"><?= _e('admin.tracks.tasks', ['a' => (int) $t['task_active'], 't' => (int) $t['task_total']]) ?></span>
      <label class="switch admin-track-active"><input type="checkbox" name="is_active" value="1"<?= (int) $t['is_active'] ? ' checked' : '' ?>><span><?= _e('admin.models.active') ?></span></label>
    </div>
    <?php foreach ($errs as $msg): ?><div class="alert alert-error"><?= e($msg) ?></div><?php endforeach; ?>
    <div class="admin-grid admin-grid-2">
      <div class="field"><label class="label" for="t<?= $id ?>-nar"><?= _e('admin.tracks.name_ar') ?></label><input class="input" id="t<?= $id ?>-nar" name="name_ar" value="<?= e($t['name_ar']) ?>" dir="rtl" lang="ar" required maxlength="120"></div>
      <div class="field"><label class="label" for="t<?= $id ?>-nen"><?= _e('admin.tracks.name_en') ?></label><input class="input" id="t<?= $id ?>-nen" name="name_en" value="<?= e($t['name_en']) ?>" dir="ltr" lang="en" required maxlength="120"></div>
      <div class="field"><label class="label" for="t<?= $id ?>-dar"><?= _e('admin.tracks.desc_ar') ?></label><textarea class="textarea" id="t<?= $id ?>-dar" name="desc_ar" rows="3" dir="rtl" lang="ar"><?= e((string) $t['desc_ar']) ?></textarea></div>
      <div class="field"><label class="label" for="t<?= $id ?>-den"><?= _e('admin.tracks.desc_en') ?></label><textarea class="textarea" id="t<?= $id ?>-den" name="desc_en" rows="3" dir="ltr" lang="en"><?= e((string) $t['desc_en']) ?></textarea></div>
    </div>
    <div class="admin-track-meta">
      <div class="field">
        <label class="label" for="t<?= $id ?>-icon"><?= _e('admin.tracks.icon') ?></label>
        <div class="input-group">
          <span class="admin-icon-preview" data-icon-preview><?= ui_icon((string) $t['icon'], 18) ?></span>
          <select class="select" id="t<?= $id ?>-icon" name="icon" data-icon-select data-sprite="<?= e(asset('img/icons.svg')) ?>">
            <?php foreach (array_unique(array_merge($icons, [(string) $t['icon']])) as $ic): ?><option value="<?= e($ic) ?>"<?= $ic === $t['icon'] ? ' selected' : '' ?>><?= e($ic) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="field">
        <label class="label" for="t<?= $id ?>-color"><?= _e('admin.models.color') ?></label>
        <div class="input-group"><input class="admin-color" type="color" value="<?= e($t['color']) ?>" data-color-sync="#t<?= $id ?>-color" aria-label="<?= _e('admin.models.color') ?>"><input class="input t-mono" id="t<?= $id ?>-color" name="color" value="<?= e($t['color']) ?>" dir="ltr" maxlength="7"></div>
      </div>
      <div class="field">
        <label class="label" for="t<?= $id ?>-aw"><?= _e('admin.tracks.auto_weight') ?> <span class="t-num t-gold" data-range-out="#t<?= $id ?>-aw"><?= e(number_format((float) $t['auto_weight'] * 100, 0)) ?>%</span></label>
        <input class="range" id="t<?= $id ?>-aw" type="range" name="auto_weight" min="0" max="1" step="0.05" value="<?= e((string) (float) $t['auto_weight']) ?>">
        <span class="help"><?= _e('admin.tracks.auto_weight_help') ?></span>
      </div>
      <div class="field"><label class="label" for="t<?= $id ?>-sort"><?= _e('admin.tracks.sort') ?></label><input class="input t-num" id="t<?= $id ?>-sort" type="number" name="sort" value="<?= (int) $t['sort'] ?>" dir="ltr"></div>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary btn-sm" type="submit"><?= ui_icon('check', 14) ?><span><?= _e('common.save') ?></span></button>
      <a class="btn btn-ghost btn-sm" href="<?= e(url('/admin/tasks', ['track' => $t['slug']])) ?>"><?= ui_icon('list', 14) ?><span><?= _e('admin.tracks.view_tasks') ?></span></a>
    </div>
  </form>
<?php endforeach; ?>
</div>
