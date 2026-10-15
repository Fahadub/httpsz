<?php
/** Settings: one form per section + secrets (config file) + recompute. */
defined('WB') || exit;
$icons = ['site' => 'globe', 'season' => 'calendar', 'judging' => 'scale', 'generation' => 'bolt', 'verification' => 'shield', 'statistics' => 'sigma', 'features' => 'sliders', 'models' => 'robot'];
$field = static function (string $key, array $rule) use ($values, $errors): string {
    $id = 'st-' . $key;
    $val = $values[$key] ?? '';
    $label = '<label class="label" for="' . $id . '">' . _e('admin.settings.f.' . $key) . '</label>';
    $help = I18n::has('admin.settings.h.' . $key) ? '<span class="help">' . _e('admin.settings.h.' . $key) . '</span>' : '';
    $err = isset($errors[$key]) ? '<span class="field-error">' . e($errors[$key]) . '</span>' : '';
    $inv = isset($errors[$key]) ? ' is-invalid' : '';
    $ltr = in_array($rule[0], ['domain', 'email', 'social', 'season', 'model', 'model_opt', 'int', 'float', 'temp_opt'], true) ? ' dir="ltr"' : '';
    switch ($rule[0]) {
        case 'bool':
            return '<div class="field"><label class="switch admin-switch-row"><input type="checkbox" id="' . $id . '" name="' . e($key) . '" value="1"' . (!empty($val) ? ' checked' : '') . '><span>' . _e('admin.settings.f.' . $key) . '</span></label>' . $help . '</div>';
        case 'enum':
            $opts = '';
            foreach ($rule[1] as $o) {
                $opts .= '<option value="' . e($o) . '"' . ((string) $val === $o ? ' selected' : '') . '>' . e(I18n::has('admin.settings.o.' . $o) ? __('admin.settings.o.' . $o) : $o) . '</option>';
            }
            return '<div class="field">' . $label . '<select class="select' . $inv . '" id="' . $id . '" name="' . e($key) . '">' . $opts . '</select>' . $help . $err . '</div>';
        case 'text':
            return '<div class="field admin-field-wide">' . $label . '<textarea class="textarea t-mono' . $inv . '" id="' . $id . '" name="' . e($key) . '" rows="5" dir="ltr" spellcheck="false">' . e((string) $val) . '</textarea>' . $help . $err . '</div>';
        case 'int':
        case 'float':
        case 'temp_opt':
            $step = $rule[0] === 'int' ? '1' : 'any';
            $mm = $rule[0] === 'temp_opt' ? ' min="0" max="2"' : ' min="' . e((string) $rule[1]) . '" max="' . e((string) $rule[2]) . '"';
            return '<div class="field">' . $label . '<input class="input t-num' . $inv . '" type="number" step="' . $step . '"' . $mm . ' id="' . $id . '" name="' . e($key) . '" value="' . e((string) $val) . '"' . $ltr . '>' . $help . $err . '</div>';
        default:
            $type = $rule[0] === 'email' ? 'email' : 'text';
            $dirAttr = in_array($key, ['tagline_ar'], true) ? ' dir="rtl" lang="ar"' : ($key === 'tagline_en' ? ' dir="ltr" lang="en"' : $ltr);
            return '<div class="field">' . $label . '<input class="input' . $inv . '" type="' . $type . '" id="' . $id . '" name="' . e($key) . '" value="' . e((string) $val) . '"' . $dirAttr . '>' . $help . $err . '</div>';
    }
};
echo partial('admin/_head', ['title' => __('admin.nav.settings'), 'sub' => __('admin.settings.sub')]);
?>
<nav class="admin-toc card card-pad" aria-label="<?= _e('admin.settings.sections') ?>">
  <?php foreach (array_keys($schema) as $sec): ?><a href="#s-<?= e($sec) ?>"><?= ui_icon($icons[$sec] ?? 'settings', 14) ?><?= _e('admin.settings.s.' . $sec) ?></a><?php endforeach; ?>
  <a href="#s-secrets"><?= ui_icon('key', 14) ?><?= _e('admin.settings.s.secrets') ?></a>
  <a href="#s-scores"><?= ui_icon('refresh', 14) ?><?= _e('admin.settings.s.scores') ?></a>
</nav>
<div class="admin-settings stack-lg">
<?php foreach ($schema as $sec => $fields): ?>
  <form class="card card-pad form stack admin-settings-section" id="s-<?= e($sec) ?>" method="post" action="<?= e(url('/admin/settings')) ?>" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="<?= e($sec) ?>">
    <div class="card-head"><h2 class="card-title"><?= ui_icon($icons[$sec] ?? 'settings', 18) ?> <?= _e('admin.settings.s.' . $sec) ?></h2></div>
    <?php if (I18n::has('admin.settings.d.' . $sec)): ?><p class="t-small t-muted"><?= _e('admin.settings.d.' . $sec) ?></p><?php endif; ?>
    <?php if ($sec === 'site'): ?><div class="alert alert-warn t-small"><?= ui_icon('shield', 14) ?> <?= _e('admin.settings.analytics_warning') ?></div><?php endif; ?>
    <?php if ($sec === 'season'): ?><div class="alert alert-info t-small"><?= _e('admin.settings.season_warning') ?></div><?php endif; ?>
    <?php if ($sec === 'judging'): ?>
      <p class="t-xs t-muted"><?= _e('admin.settings.judges_now', ['n' => count($judges)]) ?> <?php foreach ($judges as $j): ?><span class="pill"><?= e($j['name']) ?> <span class="t-muted">· <?= e($j['provider']) ?></span></span> <?php endforeach; ?><a href="<?= e(url('/admin/models', ['judge' => 1])) ?>"><?= _e('admin.settings.manage_judges') ?></a></p>
    <?php endif; ?>
    <div class="admin-grid admin-grid-2">
      <?php foreach ($fields as $key => $rule): ?><?= $field($key, $rule) ?><?php endforeach; ?>
    </div>
    <div class="form-actions"><button class="btn btn-primary btn-sm" type="submit"><?= ui_icon('check', 14) ?><span><?= _e('common.save') ?></span></button></div>
  </form>
<?php endforeach; ?>

  <section class="card card-pad stack" id="s-secrets">
    <div class="card-head"><h2 class="card-title"><?= ui_icon('key', 18) ?> <?= _e('admin.settings.s.secrets') ?></h2></div>
    <p class="t-small t-muted"><?= _e('admin.settings.secrets_help') ?></p>
    <?php if (!$writable): ?><div class="alert alert-error"><?= ui_icon('lock', 14) ?> <?= _e('admin.settings.err_writable', ['f' => 'config/config.local.php']) ?></div><?php endif; ?>
    <div class="admin-grid admin-grid-2">
      <div class="stack">
        <h3 class="t-h3"><?= _e('admin.settings.or_key') ?></h3>
        <div class="cluster">
          <?= $key_set ? ui_badge(__('admin.dash.configured'), 'green', 'check') . '<span class="t-mono" dir="ltr">' . e($key_mask) . '</span>' : ui_badge(__('admin.dash.not_configured'), 'red', 'x') ?>
          <span class="t-xs t-muted t-mono" dir="ltr"><?= e($base) ?></span>
        </div>
        <form class="form stack-sm" method="post" action="<?= e(url('/admin/settings/secrets')) ?>" autocomplete="off">
          <?= csrf_field() ?><input type="hidden" name="action" value="key">
          <div class="field"><label class="label" for="st-key"><?= _e($key_set ? 'admin.settings.replace_key' : 'admin.settings.set_key') ?></label>
            <input class="input t-mono" id="st-key" type="password" name="openrouter_key" autocomplete="new-password" dir="ltr" placeholder="sk-or-v1-…" required></div>
          <div class="cluster">
            <button class="btn btn-primary btn-sm" type="submit"<?= $writable ? '' : ' disabled' ?>><?= ui_icon('lock', 14) ?><span><?= _e('common.save') ?></span></button>
            <button class="btn btn-outline btn-sm" type="button" data-test-key="<?= e(url('/admin/api/test-key')) ?>"<?= $key_set ? '' : ' disabled' ?>><?= ui_icon('bolt', 14) ?><span><?= _e('admin.settings.test_key') ?></span></button>
          </div>
          <span class="t-small" data-test-key-out aria-live="polite"></span>
        </form>
        <?php if ($key_set): ?>
          <form method="post" action="<?= e(url('/admin/settings/secrets')) ?>" data-confirm="<?= _e('admin.settings.clear_key_confirm') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="clear_key"><button class="btn btn-ghost btn-sm" type="submit"<?= $writable ? '' : ' disabled' ?>><?= ui_icon('trash', 14) ?><span><?= _e('admin.settings.clear_key') ?></span></button></form>
        <?php endif; ?>
      </div>
      <div class="stack">
        <h3 class="t-h3"><?= _e('admin.settings.cron_key') ?></h3>
        <p class="t-small t-muted"><?= _e('admin.settings.cron_help') ?></p>
        <?php if ($cron_key !== ''): $tick = url('/worker/tick', ['key' => $cron_key], true); ?>
          <div class="admin-copyline"><code class="t-mono t-xs" dir="ltr"><?= e($tick) ?></code><button class="btn btn-sm btn-ghost" type="button" data-copy="<?= e($tick) ?>"><?= ui_icon('copy', 14) ?><span><?= _e('common.copy') ?></span></button></div>
        <?php endif; ?>
        <div class="cluster">
          <form method="post" action="<?= e(url('/admin/settings/secrets')) ?>"<?= $cron_key !== '' ? ' data-confirm="' . _e('admin.settings.regen_confirm') . '"' : '' ?>><?= csrf_field() ?><input type="hidden" name="action" value="cron"><button class="btn btn-outline btn-sm" type="submit"<?= $writable ? '' : ' disabled' ?>><?= ui_icon('refresh', 14) ?><span><?= _e($cron_key !== '' ? 'admin.settings.regen_cron' : 'admin.settings.gen_cron') ?></span></button></form>
          <?php if ($cron_key !== ''): ?><form method="post" action="<?= e(url('/admin/settings/secrets')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="clear_cron"><button class="btn btn-ghost btn-sm" type="submit"<?= $writable ? '' : ' disabled' ?>><?= _e('admin.settings.disable_cron') ?></button></form><?php endif; ?>
        </div>
        <h3 class="t-h3"><?= _e('admin.settings.csp') ?></h3>
        <form class="form stack-sm" method="post" action="<?= e(url('/admin/settings/secrets')) ?>">
          <?= csrf_field() ?><input type="hidden" name="action" value="csp">
          <div class="field"><label class="label" for="st-csp"><?= _e('admin.settings.csp_label') ?></label><input class="input t-mono" id="st-csp" name="csp_extra_script" value="<?= e($csp_extra) ?>" dir="ltr" placeholder="https://www.googletagmanager.com"><span class="help"><?= _e('admin.settings.csp_help') ?></span></div>
          <div><button class="btn btn-primary btn-sm" type="submit"<?= $writable ? '' : ' disabled' ?>><?= ui_icon('check', 14) ?><span><?= _e('common.save') ?></span></button></div>
        </form>
      </div>
    </div>
  </section>

  <section class="card card-pad stack" id="s-scores">
    <div class="card-head"><h2 class="card-title"><?= ui_icon('refresh', 18) ?> <?= _e('admin.settings.s.scores') ?></h2></div>
    <p class="t-small t-muted"><?= _e('admin.settings.recompute_help', ['t' => $aggregate_last !== '' ? fmt_date($aggregate_last, 'datetime') : __('admin.dash.never')]) ?></p>
    <form method="post" action="<?= e(url('/admin/settings/recompute')) ?>" data-busy-form>
      <?= csrf_field() ?>
      <button class="btn btn-outline" type="submit"<?= $engine_aggregator ? '' : ' disabled' ?>><?= ui_icon('refresh', 16) ?><span><?= _e('admin.dash.recompute') ?></span></button>
      <?php if (!$engine_aggregator): ?><span class="t-xs t-muted"><?= _e('admin.engine_missing', ['c' => 'WB\\Eval\\Aggregator']) ?></span><?php endif; ?>
    </form>
  </section>
</div>
