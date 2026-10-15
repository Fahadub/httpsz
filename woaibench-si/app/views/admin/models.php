<?php
/** Models list with filters and inline toggles. */
defined('WB') || exit;
echo partial('admin/_head', [
    'title' => __('admin.nav.models'),
    'sub' => __('admin.models.sub'),
    'actions' => '<a class="btn btn-ghost" href="' . e(url('/admin/models/new')) . '">' . ui_icon('plus', 16) . '<span>' . _e('admin.models.new') . '</span></a>'
        . '<a class="btn btn-primary" href="' . e(url('/admin/models/sync')) . '">' . ui_icon('download', 16) . '<span>' . _e('admin.sync.title') . '</span></a>',
]);
$judges = array_values(array_filter($models, static fn($m) => (int) $m['is_judge'] === 1));
$vendorsJ = array_unique(array_column($judges, 'provider'));
?>
<?php if ($judges && count($vendorsJ) < 3): ?>
  <div class="alert alert-warn"><?= ui_icon('scale', 16) ?> <?= _e('admin.models.judge_vendors', ['n' => count($vendorsJ)]) ?></div>
<?php endif; ?>
<form class="admin-filters card card-pad" method="get" action="<?= e(url('/admin/models')) ?>">
  <div class="field">
    <label class="label" for="f-q"><?= _e('common.search') ?></label>
    <input class="input" id="f-q" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="<?= _e('admin.models.search_ph') ?>">
  </div>
  <div class="field">
    <label class="label" for="f-active"><?= _e('admin.models.status') ?></label>
    <select class="select" id="f-active" name="active" data-autosubmit>
      <option value=""><?= _e('common.all') ?></option>
      <option value="1"<?= $filters['active'] === '1' ? ' selected' : '' ?>><?= _e('admin.models.active') ?></option>
      <option value="0"<?= $filters['active'] === '0' ? ' selected' : '' ?>><?= _e('admin.models.inactive') ?></option>
    </select>
  </div>
  <div class="field">
    <label class="label" for="f-judge"><?= _e('admin.models.judge') ?></label>
    <select class="select" id="f-judge" name="judge" data-autosubmit>
      <option value=""><?= _e('common.all') ?></option>
      <option value="1"<?= $filters['judge'] === '1' ? ' selected' : '' ?>><?= _e('admin.models.judges_only') ?></option>
      <option value="0"<?= $filters['judge'] === '0' ? ' selected' : '' ?>><?= _e('admin.models.non_judges') ?></option>
    </select>
  </div>
  <div class="field">
    <label class="label" for="f-vendor"><?= _e('common.provider') ?></label>
    <select class="select" id="f-vendor" name="vendor" data-autosubmit>
      <option value=""><?= _e('common.all') ?></option>
      <?php foreach ($vendors as $v): ?><option value="<?= e($v) ?>"<?= $filters['vendor'] === $v ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="admin-filters-actions">
    <button class="btn btn-outline" type="submit"><?= ui_icon('filter', 16) ?><span><?= _e('admin.filter') ?></span></button>
    <?php if (array_filter($filters)): ?><a class="btn btn-ghost" href="<?= e(url('/admin/models')) ?>"><?= _e('admin.reset') ?></a><?php endif; ?>
  </div>
</form>

<?php if (!$models): ?>
  <?= ui_empty(__('admin.models.empty'), __('admin.models.empty_text'), 'robot', '<a class="btn btn-primary btn-sm" href="' . e(url('/admin/models/sync')) . '">' . _e('admin.sync.title') . '</a>') ?>
<?php else: ?>
<div class="card">
  <div class="table-wrap">
    <table class="table admin-table" data-sortable>
      <thead>
        <tr>
          <th data-sort><?= _e('common.model') ?></th>
          <th data-sort class="num"><?= _e('admin.models.price_in') ?></th>
          <th data-sort class="num"><?= _e('admin.models.price_out') ?></th>
          <th data-sort class="num"><?= _e('admin.models.context') ?></th>
          <th><?= _e('admin.models.caps') ?></th>
          <th><?= _e('admin.models.active') ?></th>
          <th><?= _e('admin.models.judge') ?></th>
          <th class="num"><?= _e('admin.models.judge_order') ?></th>
          <th class="num" data-sort><?= _e('admin.models.runs') ?></th>
          <th><span class="sr-only"><?= _e('admin.actions') ?></span></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($models as $m): $api = url('/admin/api/models/' . $m['id']); ?>
        <tr class="<?= (int) $m['is_active'] ? '' : 'is-muted' ?>">
          <td data-value="<?= e($m['name']) ?>"><a class="admin-model-cell" href="<?= e(url('/admin/models/' . $m['id'])) ?>"><?= ui_model_chip($m, false) ?></a><div class="t-mono t-xs t-muted admin-orid" dir="ltr"><?= e($m['or_id']) ?></div></td>
          <td class="num t-num" data-value="<?= e((string) ($m['price_in'] ?? -1)) ?>"><bdi><?= e(fmt_price($m['price_in'])) ?></bdi></td>
          <td class="num t-num" data-value="<?= e((string) ($m['price_out'] ?? -1)) ?>"><bdi><?= e(fmt_price($m['price_out'])) ?></bdi></td>
          <td class="num t-num" data-value="<?= e((string) ($m['context_length'] ?? 0)) ?>"><?= e(fmt_tokens($m['context_length'])) ?></td>
          <td><div class="admin-caps">
            <?= (int) $m['open_weights'] ? ui_badge(__('common.open_weights'), 'green') : '' ?>
            <?= (int) $m['supports_vision'] ? '<span data-tip="' . _e('admin.models.vision') . '">' . ui_icon('eye', 15) . '</span>' : '' ?>
            <?= (int) $m['supports_tools'] ? '<span data-tip="' . _e('admin.models.tools') . '">' . ui_icon('settings', 15) . '</span>' : '' ?>
          </div></td>
          <td>
            <label class="switch" title="<?= _e('admin.models.active') ?>">
              <input type="checkbox" data-model-toggle="is_active" data-url="<?= e($api) ?>"<?= (int) $m['is_active'] ? ' checked' : '' ?> aria-label="<?= _e('admin.models.active') ?>: <?= e($m['name']) ?>">
              
            </label>
          </td>
          <td>
            <label class="switch" title="<?= _e('admin.models.judge') ?>">
              <input type="checkbox" data-model-toggle="is_judge" data-url="<?= e($api) ?>"<?= (int) $m['is_judge'] ? ' checked' : '' ?> aria-label="<?= _e('admin.models.judge') ?>: <?= e($m['name']) ?>">
              
            </label>
          </td>
          <td class="num"><input class="input admin-order-input t-num" type="number" min="-1000" max="1000" step="1" value="<?= (int) $m['judge_order'] ?>" data-model-order data-url="<?= e($api) ?>" aria-label="<?= _e('admin.models.judge_order') ?>: <?= e($m['name']) ?>"></td>
          <td class="num t-num"><a href="<?= e(url('/admin/runs', ['model' => $m['id']])) ?>"><?= e(fmt_num($m['run_count'])) ?></a></td>
          <td><a class="btn btn-sm btn-ghost btn-icon" href="<?= e(url('/admin/models/' . $m['id'])) ?>" aria-label="<?= _e('common.edit') ?>: <?= e($m['name']) ?>"><?= ui_icon('edit', 16) ?></a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-foot t-xs t-muted"><?= _e('admin.models.count', ['n' => count($models)]) ?> · <?= _e('admin.models.toggle_hint') ?></div>
</div>
<?php endif; ?>
