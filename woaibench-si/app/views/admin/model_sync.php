<?php
/** OpenRouter catalogue: search, select, import; refresh prices. */
defined('WB') || exit;
echo partial('admin/_head', [
    'back' => url('/admin/models'),
    'title' => __('admin.sync.title'),
    'sub' => __('admin.sync.sub'),
    'actions' => '<a class="btn btn-ghost" href="' . e(url('/admin/models/sync', ['refresh' => 1])) . '">' . ui_icon('refresh', 16) . '<span>' . _e('admin.sync.refresh') . '</span></a>',
]);
?>
<?php if (!$catalog['ok']): ?>
  <div class="alert alert-error" role="alert"><?= ui_icon('alert', 16) ?> <?= e((string) $catalog['error']) ?></div>
  <div class="card card-pad stack">
    <p class="t-muted"><?= _e('admin.sync.err_help') ?></p>
    <div class="cluster"><a class="btn btn-primary btn-sm" href="<?= e(url('/admin/settings#s-secrets')) ?>"><?= ui_icon('key', 14) ?><span><?= _e('admin.sync.go_settings') ?></span></a><a class="btn btn-ghost btn-sm" href="<?= e(url('/admin/models/sync', ['refresh' => 1])) ?>"><?= _e('admin.sync.retry') ?></a></div>
  </div>
<?php else: ?>
<div class="card card-pad admin-sync-bar">
  <div class="field admin-sync-search">
    <label class="label" for="sync-q"><?= _e('common.search') ?></label>
    <input class="input" id="sync-q" type="search" placeholder="<?= _e('admin.sync.search_ph') ?>" data-sync-search autocomplete="off">
  </div>
  <div class="cluster admin-sync-toggles">
    <label class="checkbox"><input type="checkbox" data-sync-only="new"> <?= _e('admin.sync.only_new') ?></label>
    <label class="checkbox"><input type="checkbox" data-sync-only="open"> <?= _e('admin.sync.only_open') ?></label>
    <label class="checkbox"><input type="checkbox" data-sync-only="vision"> <?= _e('admin.models.vision') ?></label>
    <label class="checkbox"><input type="checkbox" data-sync-only="tools"> <?= _e('admin.models.tools') ?></label>
  </div>
  <p class="t-xs t-muted"><?= _e('admin.sync.meta', ['n' => fmt_num(count($catalog['models'])), 't' => fmt_date(gmdate('Y-m-d H:i:s', (int) $catalog['fetched_at']), 'relative')]) ?><?= $catalog['cached'] ? ' · ' . _e('admin.sync.cached') : '' ?></p>
</div>
<form method="post" action="<?= e(url('/admin/models/sync')) ?>" class="stack" data-sync-form>
  <?= csrf_field() ?>
  <div class="card">
    <div class="table-wrap admin-sync-wrap">
      <table class="table table-compact admin-table" id="sync-table">
        <thead>
          <tr>
            <th><label class="checkbox"><input type="checkbox" data-sync-all aria-label="<?= _e('admin.select_all') ?>"></label></th>
            <th><?= _e('common.model') ?></th>
            <th><?= _e('admin.models.or_id') ?></th>
            <th class="num"><?= _e('admin.models.price_in') ?></th>
            <th class="num"><?= _e('admin.models.price_out') ?></th>
            <th class="num"><?= _e('admin.models.context') ?></th>
            <th><?= _e('admin.models.caps') ?></th>
            <th><?= _e('admin.sync.state') ?></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($catalog['models'] as $m): $ex = $existing[$m['or_id']] ?? null; ?>
          <tr data-sync-row data-search="<?= e(strtolower($m['or_id'] . ' ' . $m['name'] . ' ' . ($m['provider_name'] ?? ''))) ?>" data-new="<?= $ex ? '0' : '1' ?>" data-open="<?= (int) $m['open_weights'] ?>" data-vision="<?= (int) $m['supports_vision'] ?>" data-tools="<?= (int) $m['supports_tools'] ?>">
            <td><label class="checkbox"><input type="checkbox" name="ids[]" value="<?= e($m['or_id']) ?>" data-sync-check aria-label="<?= e($m['name']) ?>"></label></td>
            <td><?= ui_model_chip(['name' => $m['name'], 'provider' => $m['provider'], 'provider_name' => $m['provider_name'] ?? $m['provider']], false) ?></td>
            <td class="t-mono t-xs" dir="ltr"><?= e($m['or_id']) ?></td>
            <td class="num t-num"><bdi><?= e(fmt_price($m['price_in'])) ?></bdi></td>
            <td class="num t-num"><bdi><?= e(fmt_price($m['price_out'])) ?></bdi></td>
            <td class="num t-num"><?= e(fmt_tokens($m['context_length'])) ?></td>
            <td><div class="admin-caps">
              <?= $m['open_weights'] ? ui_badge(__('common.open_weights'), 'green') : '' ?>
              <?= $m['supports_vision'] ? '<span data-tip="' . _e('admin.models.vision') . '">' . ui_icon('eye', 15) . '</span>' : '' ?>
              <?= $m['supports_tools'] ? '<span data-tip="' . _e('admin.models.tools') . '">' . ui_icon('settings', 15) . '</span>' : '' ?>
            </div></td>
            <td>
              <?php if ($ex): ?>
                <?php $changed = (string) (float) $ex['price_in'] !== (string) (float) $m['price_in'] || (string) (float) $ex['price_out'] !== (string) (float) $m['price_out']; ?>
                <?= ui_badge(__('admin.sync.imported_badge'), 'blue', 'check') ?><?= $changed ? ' ' . ui_badge(__('admin.sync.price_changed'), 'gold') : '' ?>
              <?php else: ?>
                <?= ui_badge(__('admin.sync.new_badge'), 'muted') ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="form-actions admin-sticky-actions">
    <span class="t-small t-muted" data-sync-count><?= _e('admin.sync.selected', ['n' => 0]) ?></span>
    <button class="btn btn-primary" type="submit" name="action" value="import" data-sync-submit disabled><?= ui_icon('download', 16) ?><span><?= _e('admin.sync.import') ?></span></button>
    <button class="btn btn-outline" type="submit" name="action" value="prices" formnovalidate><?= ui_icon('refresh', 16) ?><span><?= _e('admin.sync.prices') ?></span></button>
  </div>
</form>
<?php endif; ?>
