<?php
/** Changelog create/edit (bilingual). */
defined('WB') || exit;
$en = $entry;
$isNew = $en['id'] === null;
$dt = $en['published_at'] ? gmdate('Y-m-d\TH:i', (int) (strtotime(str_replace('T', ' ', (string) $en['published_at']) . ' UTC') ?: time())) : '';
echo partial('admin/_head', ['back' => url('/admin/changelog'), 'title' => $isNew ? __('admin.changelog.new') : __('admin.changelog.edit'), 'sub' => __('admin.changelog.edit_sub')]);
?>
<form class="form stack-lg" method="post" action="<?= e(url($isNew ? '/admin/changelog/new' : '/admin/changelog/' . $en['id'])) ?>">
  <?= csrf_field() ?>
  <section class="card card-pad">
    <div class="admin-grid admin-grid-2">
      <div class="field"><label class="label" for="cl-v"><?= _e('admin.changelog.version') ?></label><input class="input t-mono" id="cl-v" name="version" value="<?= e((string) $en['version']) ?>" maxlength="32" required dir="ltr"></div>
      <div class="field"><label class="label" for="cl-d"><?= _e('admin.changelog.published') ?></label><input class="input" id="cl-d" type="datetime-local" name="published_at" value="<?= e($dt) ?>" dir="ltr"><span class="help"><?= _e('admin.changelog.utc') ?></span></div>
    </div>
  </section>
  <div class="admin-grid admin-grid-2">
    <section class="card card-pad stack" lang="ar">
      <h2 class="card-title"><?= _e('admin.changelog.arabic') ?></h2>
      <div class="field"><label class="label" for="cl-tar"><?= _e('admin.changelog.title_f') ?></label><input class="input" id="cl-tar" name="title_ar" value="<?= e((string) $en['title_ar']) ?>" dir="rtl" maxlength="200" required></div>
      <div class="field"><label class="label" for="cl-bar"><?= _e('admin.changelog.body') ?></label><textarea class="textarea" id="cl-bar" name="body_ar" rows="8" dir="rtl"><?= e((string) $en['body_ar']) ?></textarea></div>
    </section>
    <section class="card card-pad stack" lang="en">
      <h2 class="card-title"><?= _e('admin.changelog.english') ?></h2>
      <div class="field"><label class="label" for="cl-ten"><?= _e('admin.changelog.title_f') ?></label><input class="input" id="cl-ten" name="title_en" value="<?= e((string) $en['title_en']) ?>" dir="ltr" maxlength="200" required></div>
      <div class="field"><label class="label" for="cl-ben"><?= _e('admin.changelog.body') ?></label><textarea class="textarea" id="cl-ben" name="body_en" rows="8" dir="ltr"><?= e((string) $en['body_en']) ?></textarea></div>
    </section>
  </div>
  <div class="form-actions"><button class="btn btn-primary" type="submit"><?= ui_icon('check', 16) ?><span><?= _e('common.save') ?></span></button><a class="btn btn-ghost" href="<?= e(url('/admin/changelog')) ?>"><?= _e('common.cancel') ?></a></div>
</form>
