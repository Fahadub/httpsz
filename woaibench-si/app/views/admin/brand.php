<?php
/** Brand Studio: generate brand imagery and manage the gallery. */
defined('WB') || exit;
echo partial('admin/_head', ['title' => __('admin.nav.brand'), 'sub' => __('admin.brand.sub')]);
$presetData = [];
foreach ($presets as $k => $p) {
    $presetData[$k] = ['prompt' => $p['prompt'], 'size' => $p['size']];
}
?>
<?php if (!$gd): ?><div class="alert alert-error"><?= _e('admin.brand.err_gd') ?></div><?php endif; ?>
<?php if (!$writable): ?><div class="alert alert-error"><?= _e('admin.brand.err_write') ?></div><?php endif; ?>
<div class="admin-split">
  <form class="card card-pad form stack" method="post" action="<?= e(url('/admin/brand/generate')) ?>" data-brand-form data-busy-form>
    <?= csrf_field() ?>
    <h2 class="card-title"><?= ui_icon('sparkles', 18) ?> <?= _e('admin.brand.generate') ?></h2>
    <div class="field">
      <span class="label"><?= _e('admin.brand.preset') ?></span>
      <div class="admin-presets" role="group" aria-label="<?= _e('admin.brand.preset') ?>">
        <?php foreach ($kinds as $k): ?>
          <button class="chip" type="button" data-preset="<?= e($k) ?>"><?= ui_icon(['logo' => 'star', 'wordmark' => 'type', 'hero' => 'image', 'og' => 'link'][$k] ?? 'image', 14) ?><span><?= _e('admin.brand.kind.' . $k) ?></span></button>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="admin-grid admin-grid-2">
      <div class="field"><label class="label" for="br-kind"><?= _e('admin.brand.kind_label') ?></label>
        <select class="select" id="br-kind" name="kind"><?php foreach ($kinds as $k): ?><option value="<?= e($k) ?>"><?= _e('admin.brand.kind.' . $k) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label class="label" for="br-size"><?= _e('admin.brand.size') ?></label>
        <select class="select t-num" id="br-size" name="size"><?php foreach ($sizes as $s): ?><option value="<?= e($s) ?>"><?= e($s) ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="field"><label class="label" for="br-prompt"><?= _e('admin.brand.prompt') ?></label>
      <textarea class="textarea" id="br-prompt" name="prompt" rows="8" dir="ltr" maxlength="4000" required><?= e($presets['logo']['prompt']) ?></textarea>
      <span class="help"><?= _e('admin.brand.prompt_help') ?></span></div>
    <div class="field"><label class="label" for="br-model"><?= _e('admin.brand.model') ?></label>
      <input class="input t-mono" id="br-model" name="model" value="<?= e($image_model) ?>" dir="ltr"><span class="help"><?= _e('admin.brand.model_help') ?></span></div>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit"<?= $gd && $writable ? '' : ' disabled' ?>><?= ui_icon('wand', 16) ?><span><?= _e('admin.brand.go') ?></span></button>
    </div>
    <p class="help" data-busy-note hidden><?= ui_icon('clock', 14) ?> <?= _e('admin.brand.busy') ?></p>
  </form>
  <aside class="admin-aside card card-pad stack-sm">
    <h2 class="card-title"><?= ui_icon('info', 18) ?> <?= _e('admin.brand.how') ?></h2>
    <ul class="admin-steps t-small">
      <li><?= _e('admin.brand.how_1') ?></li>
      <li><?= _e('admin.brand.how_2') ?></li>
      <li><?= _e('admin.brand.how_3') ?></li>
    </ul>
  </aside>
</div>

<section class="stack">
  <div class="cluster admin-gallery-head">
    <h2 class="t-h3"><?= _e('admin.brand.gallery') ?></h2>
    <nav class="tabs admin-tabs" aria-label="<?= _e('admin.brand.gallery') ?>">
      <a class="tab<?= $filter === '' ? ' active' : '' ?>" href="<?= e(url('/admin/brand')) ?>"><?= _e('common.all') ?></a>
      <?php foreach ($kinds as $k): ?><a class="tab<?= $filter === $k ? ' active' : '' ?>" href="<?= e(url('/admin/brand', ['kind' => $k])) ?>"><?= _e('admin.brand.kind.' . $k) ?></a><?php endforeach; ?>
    </nav>
  </div>
  <?php if (!$assets): ?>
    <?= ui_empty(__('admin.brand.empty'), __('admin.brand.empty_text'), 'image') ?>
  <?php else: ?>
    <div class="admin-gallery">
      <?php foreach ($assets as $a): $act = (int) $a['is_active'] === 1; ?>
        <figure class="card admin-asset<?= $act ? ' is-active' : '' ?>">
          <a class="admin-asset-img" href="<?= e(upload_url($a['path'])) ?>" target="_blank" rel="noopener"><img src="<?= e(upload_url($a['path'])) ?>" alt="<?= e(__('admin.brand.kind.' . $a['kind'])) ?>" loading="lazy" width="<?= (int) $a['width'] ?>" height="<?= (int) $a['height'] ?>"></a>
          <figcaption class="card-pad stack-sm">
            <div class="cluster"><?= ui_badge(__('admin.brand.kind.' . $a['kind']), 'gold') ?><?= $act ? ui_badge(__('admin.brand.active'), 'green', 'check') : '' ?><span class="t-xs t-muted t-num"><?= (int) $a['width'] ?>×<?= (int) $a['height'] ?></span></div>
            <p class="t-xs t-muted admin-clamp" title="<?= e((string) $a['prompt']) ?>"><?= e((string) $a['prompt']) ?></p>
            <div class="t-xs t-muted"><span class="t-mono" dir="ltr"><?= e((string) $a['model']) ?></span> · <?= e(fmt_date($a['created_at'], 'relative')) ?></div>
            <div class="cluster admin-actions">
              <form method="post" action="<?= e(url('/admin/brand/' . $a['id'] . '/action')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $act ? 'deactivate' : 'activate' ?>"><button class="btn btn-sm <?= $act ? 'btn-ghost' : 'btn-outline' ?>" type="submit"><?= ui_icon($act ? 'x' : 'check', 14) ?><span><?= _e($act ? 'admin.brand.unset' : 'admin.brand.set_active') ?></span></button></form>
              <a class="btn btn-sm btn-ghost btn-icon" href="<?= e(upload_url($a['path'])) ?>" download aria-label="<?= _e('common.download') ?>"><?= ui_icon('download', 14) ?></a>
              <form method="post" action="<?= e(url('/admin/brand/' . $a['id'] . '/action')) ?>" data-confirm="<?= _e('admin.brand.delete_confirm') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><button class="btn btn-sm btn-ghost btn-icon" type="submit" aria-label="<?= _e('common.delete') ?>"><?= ui_icon('trash', 14) ?></button></form>
            </div>
          </figcaption>
        </figure>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<script type="application/json" id="brand-presets"><?= js_json($presetData) ?></script>
