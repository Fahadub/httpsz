<?php
/** Site header with primary navigation. $nav_active = key of the active item. */
defined('WB') || exit;
$items = [
    'leaderboard' => ['/leaderboard', 'nav.leaderboard', 'trophy'],
    'arena' => ['/arena', 'nav.arena', 'arena'],
    'compare' => ['/compare', 'nav.compare', 'scale'],
    'tasks' => ['/tasks', 'nav.tasks', 'list'],
    'value' => ['/value', 'nav.value', 'coin'],
    'hardware' => ['/hardware', 'nav.hardware', 'cpu'],
    'advisor' => ['/advisor', 'nav.advisor', 'compass'],
    'methodology' => ['/methodology', 'nav.methodology', 'flask'],
];
?>
<header class="site-header">
  <div class="container-wide site-header-inner">
    <a class="brand" href="<?= e(url('/')) ?>" aria-label="<?= e(setting('site_name')) ?>">
      <img class="brand-mark" src="<?= e(asset('img/logo-mark.svg')) ?>" alt="" width="34" height="34">
      <span class="brand-word">WOAI<span class="brand-bench">Bench</span><span class="brand-tld">.si</span></span>
    </a>
    <nav class="nav" id="site-nav" aria-label="<?= _e('nav.primary') ?>">
      <?php foreach ($items as $key => [$href, $label, $icon]): ?>
        <a class="nav-link<?= $nav_active === $key ? ' active' : '' ?>" href="<?= e(url($href)) ?>"<?= $nav_active === $key ? ' aria-current="page"' : '' ?>><?= ui_icon($icon, 16) ?><span><?= _e($label) ?></span></a>
      <?php endforeach; ?>
    </nav>
    <div class="header-actions">
      <a class="lang-switch" href="<?= e(lang_url(lang() === 'ar' ? 'en' : 'ar')) ?>" hreflang="<?= lang() === 'ar' ? 'en' : 'ar' ?>"><?= ui_icon('globe', 16) ?><span><?= lang() === 'ar' ? 'EN' : 'عربي' ?></span></a>
      <button class="theme-toggle btn-icon" type="button" data-theme-toggle aria-label="<?= _e('nav.theme') ?>"><?= ui_icon('sun', 18, 'icon-sun') ?><?= ui_icon('moon', 18, 'icon-moon') ?></button>
      <button class="nav-toggle btn-icon" type="button" data-nav-toggle aria-controls="site-nav" aria-expanded="false" aria-label="<?= _e('nav.menu') ?>"><?= ui_icon('menu', 20) ?></button>
    </div>
  </div>
</header>
