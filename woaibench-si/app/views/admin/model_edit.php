<?php
/** Model edit/create form. */
defined('WB') || exit;
$v = static fn(string $k) => e((string) ($model[$k] ?? ''));
$err = static fn(string $k) => isset($errors[$k]) ? '<span class="field-error">' . e($errors[$k]) . '</span>' : '';
$cls = static fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
$chk = static fn(string $k) => !empty($model[$k]) ? ' checked' : '';
echo partial('admin/_head', [
    'back' => url('/admin/models'),
    'title' => $is_new ? __('admin.models.new') : (string) $model['name'],
    'sub' => $is_new ? __('admin.models.new_sub') : (string) $model['or_id'],
    'actions' => $is_new ? '' : '<a class="btn btn-ghost" href="' . e(url('/admin/runs', ['model' => $model['id']])) . '">' . ui_icon('history', 16) . '<span>' . _e('admin.models.view_runs', ['n' => $run_count]) . '</span></a>'
        . '<a class="btn btn-ghost" href="' . e(url('/model/' . $model['slug'])) . '" target="_blank" rel="noopener">' . ui_icon('external', 16) . '<span>' . _e('admin.models.public_page') . '</span></a>',
]);
?>
<form class="form stack-lg" method="post" action="<?= e(url($is_new ? '/admin/models/new' : '/admin/models/' . $model['id'])) ?>" novalidate>
  <?= csrf_field() ?>
  <div class="admin-grid admin-grid-2">
    <section class="card card-pad stack">
      <h2 class="card-title"><?= ui_icon('robot', 18) ?> <?= _e('admin.models.identity') ?></h2>
      <div class="field"><label class="label" for="m-name"><?= _e('admin.models.name') ?></label><input class="input<?= $cls('name') ?>" id="m-name" name="name" value="<?= $v('name') ?>" required maxlength="190"><?= $err('name') ?></div>
      <div class="form-row">
        <div class="field"><label class="label" for="m-slug"><?= _e('admin.models.slug') ?></label><input class="input t-mono<?= $cls('slug') ?>" id="m-slug" name="slug" value="<?= $v('slug') ?>" required dir="ltr" pattern="[a-z0-9][a-z0-9\-]*"><?= $err('slug') ?></div>
        <div class="field"><label class="label" for="m-or"><?= _e('admin.models.or_id') ?></label><input class="input t-mono<?= $cls('or_id') ?>" id="m-or" name="or_id" value="<?= $v('or_id') ?>" required dir="ltr" placeholder="vendor/model"><?= $err('or_id') ?><span class="help"><?= _e('admin.models.or_help') ?></span></div>
      </div>
      <div class="field"><label class="label" for="m-vendor"><?= _e('admin.models.vendor_name') ?></label><input class="input" id="m-vendor" name="provider_name" value="<?= $v('provider_name') ?>" maxlength="120"></div>
      <div class="field"><label class="label" for="m-desc"><?= _e('admin.models.description') ?></label><textarea class="textarea" id="m-desc" name="description" rows="4" maxlength="4000"><?= $v('description') ?></textarea></div>
      <div class="form-row">
        <div class="field"><label class="label" for="m-date"><?= _e('admin.models.release') ?></label><input class="input<?= $cls('release_date') ?>" id="m-date" type="date" name="release_date" value="<?= $v('release_date') ?>"><?= $err('release_date') ?></div>
        <div class="field"><label class="label" for="m-color"><?= _e('admin.models.color') ?></label>
          <div class="input-group"><input class="admin-color" type="color" value="<?= e($model['color'] ?: ui_provider_color((string) $model['provider'])) ?>" data-color-sync="#m-color" aria-label="<?= _e('admin.models.color') ?>"><input class="input t-mono<?= $cls('color') ?>" id="m-color" name="color" value="<?= $v('color') ?>" placeholder="#D9B26A" dir="ltr" maxlength="7"></div><?= $err('color') ?>
        </div>
      </div>
    </section>
    <div class="stack-lg">
      <section class="card card-pad stack">
        <h2 class="card-title"><?= ui_icon('coin', 18) ?> <?= _e('admin.models.pricing') ?></h2>
        <div class="form-row">
          <div class="field"><label class="label" for="m-pin"><?= _e('admin.models.price_in') ?></label><input class="input t-num<?= $cls('price_in') ?>" id="m-pin" type="number" step="any" min="0" name="price_in" value="<?= $v('price_in') ?>" dir="ltr"><?= $err('price_in') ?></div>
          <div class="field"><label class="label" for="m-pout"><?= _e('admin.models.price_out') ?></label><input class="input t-num<?= $cls('price_out') ?>" id="m-pout" type="number" step="any" min="0" name="price_out" value="<?= $v('price_out') ?>" dir="ltr"><?= $err('price_out') ?></div>
        </div>
        <span class="help"><?= _e('admin.models.price_help') ?></span>
        <div class="field"><label class="label" for="m-ctx"><?= _e('admin.models.context') ?></label><input class="input t-num<?= $cls('context_length') ?>" id="m-ctx" type="number" min="1" name="context_length" value="<?= $v('context_length') ?>" dir="ltr"><?= $err('context_length') ?></div>
        <div class="cluster">
          <label class="checkbox"><input type="checkbox" name="supports_vision" value="1"<?= $chk('supports_vision') ?>> <?= _e('admin.models.vision') ?></label>
          <label class="checkbox"><input type="checkbox" name="supports_tools" value="1"<?= $chk('supports_tools') ?>> <?= _e('admin.models.tools') ?></label>
        </div>
      </section>
      <section class="card card-pad stack">
        <h2 class="card-title"><?= ui_icon('scale', 18) ?> <?= _e('admin.models.flags') ?></h2>
        <div class="cluster">
          <label class="checkbox"><input type="checkbox" name="is_active" value="1"<?= $chk('is_active') ?>> <?= _e('admin.models.active_help') ?></label>
          <label class="checkbox"><input type="checkbox" name="is_judge" value="1"<?= $chk('is_judge') ?>> <?= _e('admin.models.judge_help') ?></label>
        </div>
        <div class="field"><label class="label" for="m-jo"><?= _e('admin.models.judge_order') ?></label><input class="input t-num<?= $cls('judge_order') ?>" id="m-jo" type="number" name="judge_order" value="<?= $v('judge_order') ?>" dir="ltr"><?= $err('judge_order') ?><span class="help"><?= _e('admin.models.judge_order_help') ?></span></div>
      </section>
    </div>
  </div>

  <div class="admin-grid admin-grid-2">
    <section class="card card-pad stack">
      <h2 class="card-title"><?= ui_icon('cpu', 18) ?> <?= _e('admin.models.fitlab') ?></h2>
      <label class="checkbox"><input type="checkbox" name="open_weights" value="1"<?= $chk('open_weights') ?>> <?= _e('common.open_weights') ?></label>
      <div class="form-row">
        <div class="field"><label class="label" for="m-params"><?= _e('admin.models.params') ?></label><input class="input t-num<?= $cls('params_b') ?>" id="m-params" type="number" step="any" min="0" name="params_b" value="<?= $v('params_b') ?>" dir="ltr"><?= $err('params_b') ?></div>
        <div class="field"><label class="label" for="m-active"><?= _e('admin.models.active_params') ?></label><input class="input t-num<?= $cls('active_params_b') ?>" id="m-active" type="number" step="any" min="0" name="active_params_b" value="<?= $v('active_params_b') ?>" dir="ltr"><?= $err('active_params_b') ?></div>
      </div>
      <div class="form-row">
        <div class="field"><label class="label" for="m-layers"><?= _e('admin.models.layers') ?></label><input class="input t-num<?= $cls('n_layers') ?>" id="m-layers" type="number" min="1" name="n_layers" value="<?= $v('n_layers') ?>" dir="ltr"><?= $err('n_layers') ?></div>
        <div class="field"><label class="label" for="m-kv"><?= _e('admin.models.kv_dim') ?></label><input class="input t-num<?= $cls('kv_dim') ?>" id="m-kv" type="number" min="1" name="kv_dim" value="<?= $v('kv_dim') ?>" dir="ltr"><?= $err('kv_dim') ?></div>
      </div>
      <span class="help"><?= _e('admin.models.fitlab_help') ?></span>
    </section>
    <section class="card card-pad stack">
      <h2 class="card-title"><?= ui_icon('sliders', 18) ?> <?= _e('admin.models.gen_params') ?></h2>
      <div class="field">
        <label class="label" for="m-gp"><?= _e('admin.models.gen_params_label') ?></label>
        <textarea class="textarea t-mono<?= $cls('gen_params') ?>" id="m-gp" name="gen_params" rows="7" dir="ltr" spellcheck="false" data-json-field="object" placeholder='{"reasoning": {"effort": "high"}}'><?= e(($model['gen_params'] ?? '') !== '' && $model['gen_params'] !== null ? \WB\Admin\Admin::pretty($model['gen_params']) : '') ?></textarea>
        <span class="help admin-json-status" data-json-status="#m-gp"></span>
        <?= $err('gen_params') ?>
        <span class="help"><?= _e('admin.models.gen_params_help') ?></span>
      </div>
    </section>
  </div>

  <div class="form-actions admin-sticky-actions">
    <button class="btn btn-primary" type="submit"><?= ui_icon('check', 16) ?><span><?= _e('common.save') ?></span></button>
    <a class="btn btn-ghost" href="<?= e(url('/admin/models')) ?>"><?= _e('common.cancel') ?></a>
  </div>
</form>
<?php if (!$is_new): ?>
  <form class="admin-danger card card-pad" method="post" action="<?= e(url('/admin/models/' . $model['id'] . '/delete')) ?>" data-confirm="<?= _e('admin.models.delete_confirm') ?>">
    <?= csrf_field() ?>
    <div><strong><?= _e('admin.danger') ?></strong><p class="t-small t-muted"><?= $run_count > 0 ? _e('admin.models.delete_blocked') : _e('admin.models.delete_help') ?></p></div>
    <button class="btn btn-danger btn-sm" type="submit"<?= $run_count > 0 ? ' disabled' : '' ?>><?= ui_icon('trash', 14) ?><span><?= _e('common.delete') ?></span></button>
  </form>
<?php endif; ?>
