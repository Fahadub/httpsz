<?php
/**
 * Step 3 — site identity, owner account, optional OpenRouter key.
 * Vars: $v, $errors (field => lang key), $has_hash, $key_tail, $key_result, $db_info, $flashes.
 */
defined('WB') || exit;

$fe = static function (string $field) use ($errors): string {
    return isset($errors[$field]) ? '<span class="field-error" id="err-' . e($field) . '">' . _e($errors[$field]) . '</span>' : '';
};
$inv = static function (string $field) use ($errors): string {
    return isset($errors[$field]) ? ' aria-invalid="true" aria-describedby="err-' . e($field) . '"' : '';
};
?>
<header class="panel-head">
  <span class="eyebrow"><?= _e('setup.step_of', ['n' => 3, 'total' => 4]) ?></span>
  <h1 class="display"><?= _e('setup.site_title') ?></h1>
  <p class="lead"><?= _e('setup.site_lead') ?></p>
</header>
<?= \WB\Setup\Wizard::partial('_flashes', ['flashes' => $flashes]) ?>
<?php if ($errors): ?>
<div class="callout callout-error" role="alert"><svg class="ic" aria-hidden="true"><use href="#i-alert"/></svg><div><?= _e('setup.err_form') ?></div></div>
<?php endif; ?>
<?php if (!empty($db_info['admins'])): ?>
<div class="callout callout-info" role="status"><svg class="ic" aria-hidden="true"><use href="#i-info"/></svg><div><?= _e('setup.admins_exist', ['n' => (int) $db_info['admins']]) ?></div></div>
<?php endif; ?>

<form class="form" method="post" action="<?= e(\WB\Setup\Wizard::url(3)) ?>" data-busy novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="site">
  <button class="default-submit" type="submit" tabindex="-1" aria-hidden="true"></button>

  <fieldset class="fs">
    <legend class="fs-title"><svg class="ic" aria-hidden="true"><use href="#i-globe"/></svg><?= _e('setup.fs_site') ?></legend>
    <div class="field">
      <label class="label" for="site_url"><?= _e('setup.site_url') ?></label>
      <input class="input mono" id="site_url" name="site_url" type="url" dir="ltr" required maxlength="190" value="<?= e($v['url']) ?>" spellcheck="false" autocapitalize="off"<?= $inv('site_url') ?>>
      <?= $fe('site_url') ?><span class="help"><?= _e('setup.site_url_help') ?></span>
    </div>
    <div class="row row-2">
      <div class="field">
        <label class="label" for="site_name"><?= _e('setup.site_name') ?></label>
        <input class="input" id="site_name" name="site_name" type="text" required maxlength="60" value="<?= e($v['name']) ?>"<?= $inv('site_name') ?>>
        <?= $fe('site_name') ?>
      </div>
      <div class="field">
        <span class="label" id="lbl-lang"><?= _e('setup.default_lang') ?></span>
        <div class="segmented" role="radiogroup" aria-labelledby="lbl-lang">
          <label><input type="radio" name="default_lang" value="ar"<?= $v['lang'] === 'ar' ? ' checked' : '' ?>><span lang="ar"><?= _e('setup.lang_ar') ?></span></label>
          <label><input type="radio" name="default_lang" value="en"<?= $v['lang'] === 'en' ? ' checked' : '' ?>><span lang="en"><?= _e('setup.lang_en') ?></span></label>
        </div>
      </div>
    </div>
  </fieldset>

  <fieldset class="fs">
    <legend class="fs-title"><svg class="ic" aria-hidden="true"><use href="#i-user"/></svg><?= _e('setup.fs_admin') ?></legend>
    <div class="row row-2">
      <div class="field">
        <label class="label" for="admin_name"><?= _e('setup.admin_name') ?></label>
        <input class="input" id="admin_name" name="admin_name" type="text" required maxlength="120" autocomplete="name" value="<?= e($v['admin_name']) ?>"<?= $inv('admin_name') ?>>
        <?= $fe('admin_name') ?>
      </div>
      <div class="field">
        <label class="label" for="admin_email"><?= _e('setup.admin_email') ?></label>
        <input class="input mono" id="admin_email" name="admin_email" type="email" dir="ltr" required maxlength="190" autocomplete="username" value="<?= e($v['admin_email']) ?>" spellcheck="false" autocapitalize="off"<?= $inv('admin_email') ?>>
        <?= $fe('admin_email') ?>
      </div>
    </div>
    <div class="row row-2">
      <div class="field">
        <label class="label" for="admin_pass"><?= _e('setup.admin_pass') ?></label>
        <div class="input-wrap">
          <input class="input mono" id="admin_pass" name="admin_pass" type="password" dir="ltr" minlength="12" maxlength="200" autocomplete="new-password" data-meter-input<?= $has_hash ? ' placeholder="••••••••••••"' : ' required' ?><?= $inv('admin_pass') ?>>
          <button class="input-btn" type="button" data-reveal="#admin_pass" aria-label="<?= _e('setup.show_password') ?>"><svg class="ic" aria-hidden="true"><use href="#i-eye"/></svg></button>
        </div>
        <div class="meter" data-meter aria-live="polite">
          <span class="meter-track"><i></i><i></i><i></i><i></i></span>
          <span class="meter-label" data-meter-label><?= _e('setup.admin_pass_help') ?></span>
        </div>
        <?= $fe('admin_pass') ?>
        <?php if ($has_hash): ?><span class="help"><?= _e('setup.admin_pass_keep') ?></span><?php endif; ?>
      </div>
      <div class="field">
        <label class="label" for="admin_pass2"><?= _e('setup.admin_pass2') ?></label>
        <input class="input mono" id="admin_pass2" name="admin_pass2" type="password" dir="ltr" maxlength="200" autocomplete="new-password"<?= $has_hash ? '' : ' required' ?><?= $inv('admin_pass2') ?>>
        <?= $fe('admin_pass2') ?>
      </div>
    </div>
  </fieldset>

  <fieldset class="fs">
    <legend class="fs-title"><svg class="ic" aria-hidden="true"><use href="#i-key"/></svg><?= _e('setup.fs_api') ?></legend>
    <p class="fs-lead"><?= _e('setup.api_lead') ?></p>
    <div class="field">
      <label class="label" for="openrouter_key"><?= _e('setup.api_key') ?></label>
      <div class="key-row">
        <div class="input-wrap">
          <input class="input mono" id="openrouter_key" name="openrouter_key" type="password" dir="ltr" maxlength="200" autocomplete="off" spellcheck="false" placeholder="<?= $key_tail !== '' ? '•••• ' . e($key_tail) : 'sk-or-v1-…' ?>"<?= $inv('openrouter_key') ?>>
          <button class="input-btn" type="button" data-reveal="#openrouter_key" aria-label="<?= _e('setup.show_password') ?>"><svg class="ic" aria-hidden="true"><use href="#i-eye"/></svg></button>
        </div>
        <button class="btn btn-outline" type="submit" name="do_test" value="1" formnovalidate data-test-key><svg class="ic" aria-hidden="true"><use href="#i-spark"/></svg><?= _e('setup.api_test') ?></button>
      </div>
      <?= $fe('openrouter_key') ?>
      <div class="key-result<?= $key_result ? ' is-' . ($key_result['ok'] ? 'ok' : 'bad') : '' ?>" data-key-result role="status" aria-live="polite"><?= $key_result ? e($key_result['message']) : '' ?></div>
      <span class="help"><?= $key_tail !== '' ? _e('setup.api_key_keep', ['tail' => "\u{2066}" . $key_tail . "\u{2069}"]) : _e('setup.api_key_help') ?></span>
    </div>
  </fieldset>

  <div class="actions">
    <a class="btn btn-ghost" href="<?= e(\WB\Setup\Wizard::url(2)) ?>"><svg class="ic flip-back" aria-hidden="true"><use href="#i-arrow"/></svg><?= _e('setup.back') ?></a>
    <button class="btn btn-primary" type="submit"><?= _e('setup.continue') ?><svg class="ic flip" aria-hidden="true"><use href="#i-arrow"/></svg></button>
  </div>
</form>
