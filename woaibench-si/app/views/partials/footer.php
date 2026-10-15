<?php
/** Site footer. */
defined('WB') || exit;
?>
<footer class="site-footer">
  <div class="container-wide footer-grid">
    <div class="footer-brand stack-sm">
      <a class="brand" href="<?= e(url('/')) ?>"><img class="brand-mark" src="<?= e(asset('img/logo-mark.svg')) ?>" alt="" width="30" height="30"><span class="brand-word">WOAI<span class="brand-bench">Bench</span><span class="brand-tld">.si</span></span></a>
      <p class="t-muted t-small"><?= e(lang() === 'ar' ? setting('tagline_ar') : setting('tagline_en')) ?></p>
    </div>
    <div class="footer-col">
      <h4 class="footer-title"><?= _e('footer.benchmark') ?></h4>
      <a href="<?= e(url('/leaderboard')) ?>"><?= _e('nav.leaderboard') ?></a>
      <a href="<?= e(url('/arena')) ?>"><?= _e('nav.arena') ?></a>
      <a href="<?= e(url('/tasks')) ?>"><?= _e('nav.tasks') ?></a>
      <a href="<?= e(url('/methodology')) ?>"><?= _e('nav.methodology') ?></a>
    </div>
    <div class="footer-col">
      <h4 class="footer-title"><?= _e('footer.tools') ?></h4>
      <a href="<?= e(url('/compare')) ?>"><?= _e('nav.compare') ?></a>
      <a href="<?= e(url('/value')) ?>"><?= _e('nav.value') ?></a>
      <a href="<?= e(url('/hardware')) ?>"><?= _e('nav.hardware') ?></a>
      <a href="<?= e(url('/advisor')) ?>"><?= _e('nav.advisor') ?></a>
    </div>
    <div class="footer-col">
      <h4 class="footer-title"><?= _e('footer.developers') ?></h4>
      <a href="<?= e(url('/api-docs')) ?>"><?= _e('nav.api') ?></a>
      <a href="<?= e(url('/export/leaderboard.csv')) ?>"><?= _e('footer.csv') ?></a>
      <a href="<?= e(url('/changelog')) ?>"><?= _e('nav.changelog') ?></a>
      <a href="<?= e(url('/request')) ?>"><?= _e('nav.request') ?></a>
      <a href="<?= e(url('/about')) ?>"><?= _e('nav.about') ?></a>
    </div>
  </div>
  <div class="container-wide footer-bottom t-xs t-muted">
    <span>© <?= gmdate('Y') ?> <?= e(setting('site_domain', 'woaibench.si')) ?> · <?= _e('footer.season', ['s' => setting('season')]) ?></span>
    <span><?= _e('footer.independent') ?></span>
  </div>
</footer>
