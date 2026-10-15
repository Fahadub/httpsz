<?php
/**
 * Admin layout. Variables: title, nav_active, page_css[], page_js[], bare (login: no chrome), robots.
 * Always loads css/admin.css and js/admin.js. Other modules (e.g. admin/verify) can use it via
 * view('admin/x', ['title' => …, 'nav_active' => 'verify'], 'admin').
 */
defined('WB') || exit;

$siteName = (string) setting('site_name', 'WOAIBench');
$pageTitle = (isset($title) && $title !== '' ? $title . ' · ' : '') . __('admin.brand_suffix') . ' · ' . $siteName;
$page_css = array_values(array_unique(array_merge(['css/admin.css'], $page_css ?? [])));
$page_js = array_values(array_unique(array_merge(['js/admin.js'], $page_js ?? [])));
$nav_active = $nav_active ?? '';
$bare = !empty($bare);
$adminUser = $bare ? null : auth_user();
$hbAge = $bare ? null : \WB\Admin\Admin::heartbeatAge();
$hbClass = $hbAge === null ? 'is-off' : ($hbAge <= 120 ? 'is-live' : ($hbAge <= 600 ? 'is-warn' : 'is-off'));
$newRequests = $bare ? 0 : \WB\Admin\Admin::newRequests();
?><!doctype html>
<html lang="<?= e(lang()) ?>" dir="<?= e(text_dir()) ?>" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($pageTitle) ?></title>
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="theme-color" content="#07080C">
<link rel="icon" href="<?= e(asset('img/favicon.ico')) ?>" sizes="48x48">
<link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
<script<?= nonce_attr() ?>>(function(){try{var t=localStorage.getItem('wb_theme');if(t==='light'||t==='dark'){document.documentElement.setAttribute('data-theme',t);}else if(window.matchMedia&&matchMedia('(prefers-color-scheme: light)').matches){document.documentElement.setAttribute('data-theme','light');}}catch(e){}})();</script>
<link rel="stylesheet" href="<?= e(asset('css/fonts.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<?php foreach ($page_css as $css): ?><link rel="stylesheet" href="<?= e(asset($css)) ?>">
<?php endforeach; ?>
</head>
<body class="admin-body<?= $bare ? ' admin-bare' : '' ?>">
<a class="skip-link" href="#main"><?= _e('common.skip') ?></a>
<?php if ($bare): ?>
<main id="main" class="admin-auth">
  <div class="admin-auth-glow" aria-hidden="true"></div>
  <?php foreach (flashes() as $f): ?>
    <div class="alert alert-<?= e($f['type']) ?> admin-auth-flash" role="status"><?= e($f['message']) ?></div>
  <?php endforeach; ?>
  <?= $content ?>
</main>
<?php else: ?>
<div class="admin-shell" data-admin-shell>
  <aside class="admin-sidebar" id="admin-sidebar" aria-label="<?= _e('admin.nav.label') ?>">
    <a class="brand admin-brand" href="<?= e(url('/admin')) ?>">
      <img class="brand-mark" src="<?= e(asset('img/logo-mark.svg')) ?>" alt="" width="32" height="32">
      <span class="admin-brand-text">
        <span class="brand-word">WOAI<span class="brand-bench">Bench</span><span class="brand-tld">.si</span></span>
        <span class="admin-brand-sub"><?= _e('admin.brand_suffix') ?></span>
      </span>
    </a>
    <nav class="admin-nav" aria-label="<?= _e('admin.nav.label') ?>">
      <?php foreach (\WB\Admin\Admin::nav() as $group => $items): ?>
        <div class="admin-nav-section">
          <span class="admin-nav-group"><?= _e($group) ?></span>
          <?php foreach ($items as $key => [$href, $label, $icon]): $active = $nav_active === $key; ?>
            <a class="admin-nav-link<?= $active ? ' active' : '' ?>" href="<?= e(url($href)) ?>"<?= $active ? ' aria-current="page"' : '' ?>>
              <?= ui_icon($icon, 18) ?><span><?= _e($label) ?></span>
              <?php if ($key === 'requests' && $newRequests > 0): ?><span class="badge badge-gold t-num"><?= (int) $newRequests ?></span><?php endif; ?>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </nav>
    <div class="admin-sidebar-foot">
      <a class="admin-nav-link" href="<?= e(url('/')) ?>" target="_blank" rel="noopener"><?= ui_icon('external', 18) ?><span><?= _e('admin.nav.view_site') ?></span></a>
      <form method="post" action="<?= e(url('/admin/logout')) ?>">
        <?= csrf_field() ?>
        <button class="admin-nav-link admin-logout" type="submit"><?= ui_icon('logout', 18) ?><span><?= _e('admin.nav.logout') ?></span></button>
      </form>
    </div>
  </aside>
  <div class="admin-main">
    <header class="admin-topbar">
      <button class="btn-icon admin-toggle" type="button" data-nav-toggle aria-controls="admin-sidebar" aria-expanded="false" aria-label="<?= _e('nav.menu') ?>"><?= ui_icon('menu', 20) ?></button>
      <div class="admin-topbar-context">
        <span class="admin-season t-mono"><?= _e('admin.top.season', ['s' => setting('season')]) ?></span>
        <a class="admin-heartbeat <?= e($hbClass) ?>" href="<?= e(url('/admin/console')) ?>" data-tip="<?= _e('admin.top.worker_tip') ?>">
          <span class="admin-heartbeat-dot" aria-hidden="true"></span>
          <span><?= $hbAge === null ? _e('admin.top.worker_never') : _e('admin.top.worker_ago', ['t' => fmt_date(gmdate('Y-m-d H:i:s', time() - $hbAge), 'relative')]) ?></span>
        </a>
      </div>
      <div class="admin-topbar-actions">
        <a class="lang-switch" href="<?= e(lang_url(lang() === 'ar' ? 'en' : 'ar')) ?>" hreflang="<?= lang() === 'ar' ? 'en' : 'ar' ?>" lang="<?= lang() === 'ar' ? 'en' : 'ar' ?>"><?= ui_icon('globe', 16) ?><span><?= lang() === 'ar' ? 'English' : 'العربية' ?></span></a>
        <button class="theme-toggle btn-icon" type="button" data-theme-toggle aria-label="<?= _e('nav.theme') ?>"><?= ui_icon('sun', 18, 'icon-sun') ?><?= ui_icon('moon', 18, 'icon-moon') ?></button>
        <?php if ($adminUser): ?>
          <a class="admin-user" href="<?= e(url('/admin/account')) ?>" title="<?= e($adminUser['email']) ?>">
            <span class="avatar" style="--av:var(--gold);--size:30px" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) $adminUser['name'], 0, 1))) ?></span>
            <span class="admin-user-name"><?= e($adminUser['name']) ?></span>
          </a>
        <?php endif; ?>
      </div>
    </header>
    <main id="main" class="admin-content">
      <?php foreach (flashes() as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?>" role="status"><?= e($f['message']) ?></div>
      <?php endforeach; ?>
      <?= $content ?>
    </main>
  </div>
</div>
<?php endif; ?>
<script type="application/json" id="wb-i18n"><?= js_json(I18n::forJs(['js.', 'admin.js.', 'verifier.js.'])) ?></script>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
<?php foreach ($page_js as $js): ?><script src="<?= e(asset($js)) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
