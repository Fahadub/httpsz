<?php
/** Site header: brand, primary navigation (drawer on small screens), language switch, theme toggle. $nav_active = key of the active item. */
defined('WB') || exit;
$nav_active = $nav_active ?? '';
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
$other = lang() === 'ar' ? 'en' : 'ar';
?>
<header class="site-header">
  <div class="container-wide site-header-inner">
    <?= ui_brand(34) ?>
    <nav class="nav" id="site-nav" aria-label="<?= _e('nav.primary') ?>">
      <div class="nav-drawer-head"><span class="eyebrow"><?= _e('design.menu_title') ?></span></div>
      <?php foreach ($items as $key => [$href, $label, $icon]): ?>
        <a class="nav-link<?= $nav_active === $key ? ' active' : '' ?>" href="<?= e(url($href)) ?>"<?= $nav_active === $key ? ' aria-current="page"' : '' ?>><?= ui_icon($icon, 18) ?><span><?= _e($label) ?></span></a>
      <?php endforeach; ?>
      <div class="nav-drawer-foot">
        <a class="btn btn-primary btn-block" href="<?= e(url('/leaderboard')) ?>"><?= ui_icon('trophy', 17) ?><?= _e('design.drawer_cta') ?></a>
        <div class="cluster t-xs t-faint">
          <a href="<?= e(url('/api-docs')) ?>"><?= _e('nav.api') ?></a>
          <a href="<?= e(url('/changelog')) ?>"><?= _e('nav.changelog') ?></a>
          <a href="<?= e(url('/about')) ?>"><?= _e('nav.about') ?></a>
        </div>
      </div>
    </nav>
    <div class="header-actions">
      <a class="lang-switch" href="<?= e(lang_url($other)) ?>" hreflang="<?= $other ?>" lang="<?= $other ?>" title="<?= _e('design.switch_lang') ?>"><?= ui_icon('globe', 16) ?><span><?= $other === 'ar' ? 'العربية' : 'English' ?></span></a>
      <button class="theme-toggle btn-icon" type="button" data-theme-toggle aria-label="<?= _e('nav.theme') ?>" data-tip="<?= _e('nav.theme') ?>" data-tip-pos="bottom"><?= ui_icon('sun', 18, 'icon-sun') ?><?= ui_icon('moon', 18, 'icon-moon') ?></button>
      <button class="nav-toggle btn-icon" type="button" data-nav-toggle aria-controls="site-nav" aria-expanded="false" aria-label="<?= _e('nav.menu') ?>"><?= ui_icon('menu', 20, 'icon-open') ?><?= ui_icon('close', 20, 'icon-close') ?></button>
    </div>
  </div>
</header>
