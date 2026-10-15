<?php
/** Admin sign-in. Vars: $error, $next, $email. */
defined('WB') || exit;
?>
<section class="admin-login card card-glass">
  <div class="admin-login-brand">
    <img src="<?= e(asset('img/logo-mark.svg')) ?>" alt="" width="52" height="52">
    <span class="eyebrow"><?= _e('admin.brand_suffix') ?></span>
    <h1 class="admin-login-title"><?= _e('admin.login.title') ?></h1>
    <p class="t-muted t-small"><?= _e('admin.login.sub') ?></p>
  </div>
  <?php if (!empty($error)): ?>
    <div class="alert alert-error" role="alert"><?= e($error) ?></div>
  <?php endif; ?>
  <form class="form stack" method="post" action="<?= e(url('/admin/login', ['next' => $next !== '/admin' ? $next : null])) ?>" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="next" value="<?= e($next) ?>">
    <div class="field">
      <label class="label" for="login-email"><?= _e('admin.login.email') ?></label>
      <input class="input" id="login-email" type="email" name="email" value="<?= e($email) ?>" autocomplete="username" required autofocus dir="ltr">
    </div>
    <div class="field">
      <label class="label" for="login-password"><?= _e('admin.login.password') ?></label>
      <input class="input" id="login-password" type="password" name="password" autocomplete="current-password" required dir="ltr">
    </div>
    <button class="btn btn-primary btn-block btn-lg" type="submit"><?= ui_icon('lock', 18) ?><span><?= _e('admin.login.submit') ?></span></button>
  </form>
  <p class="admin-login-foot t-xs t-muted">
    <a href="<?= e(url('/')) ?>"><?= ui_icon(is_rtl() ? 'arrow-right' : 'arrow-left', 12) ?> <?= _e('admin.login.back_site') ?></a>
    <span class="admin-login-lang"><a href="<?= e(lang_url(lang() === 'ar' ? 'en' : 'ar')) ?>"><?= lang() === 'ar' ? 'English' : 'العربية' ?></a></span>
  </p>
</section>
