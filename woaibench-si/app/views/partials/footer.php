<?php
/** Site footer: brand statement, method summary, link columns, season and computation status. */
defined('WB') || exit;
$tagline = lang() === 'ar' ? (string) setting('tagline_ar') : (string) setting('tagline_en');
$lastAgg = (string) setting('aggregate_last', '');
$socialX = (string) setting('social_x', '');
$socialGh = (string) setting('social_github', '');
$safeUrl = static fn(string $u): bool => (bool) preg_match('#^https://#i', $u);
?>
<footer class="site-footer">
  <div class="container-wide footer-top">
    <div class="footer-grid">
      <div class="footer-brand">
        <?= ui_brand(36) ?>
        <p class="footer-tagline"><?= e($tagline) ?></p>
        <div class="footer-method" aria-label="<?= _e('design.footer_method') ?>">
          <span class="badge badge-gold"><?= _e('design.chip_runs', ['n' => WB\Data\Catalog::runsPerTask()]) ?></span>
          <span class="badge badge-muted"><?= _e('design.chip_judges') ?></span>
          <span class="badge badge-muted"><?= _e('design.chip_ci') ?></span>
          <span class="badge badge-muted"><?= _e('design.chip_browser') ?></span>
        </div>
        <?php if (($socialX !== '' && $safeUrl($socialX)) || ($socialGh !== '' && $safeUrl($socialGh))): ?>
          <div class="footer-social">
            <?php if ($socialX !== '' && $safeUrl($socialX)): ?><a class="btn-icon" href="<?= e($socialX) ?>" rel="noopener me" target="_blank" aria-label="X"><?= ui_icon('x-social', 18) ?></a><?php endif; ?>
            <?php if ($socialGh !== '' && $safeUrl($socialGh)): ?><a class="btn-icon" href="<?= e($socialGh) ?>" rel="noopener me" target="_blank" aria-label="GitHub"><?= ui_icon('github', 18) ?></a><?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
      <nav class="footer-col" aria-labelledby="ft-bench">
        <h2 class="footer-title" id="ft-bench"><?= _e('footer.benchmark') ?></h2>
        <a href="<?= e(url('/leaderboard')) ?>"><?= _e('nav.leaderboard') ?></a>
        <a href="<?= e(url('/arena')) ?>"><?= _e('nav.arena') ?></a>
        <a href="<?= e(url('/tasks')) ?>"><?= _e('nav.tasks') ?></a>
        <a href="<?= e(url('/methodology')) ?>"><?= _e('nav.methodology') ?></a>
      </nav>
      <nav class="footer-col" aria-labelledby="ft-tools">
        <h2 class="footer-title" id="ft-tools"><?= _e('footer.tools') ?></h2>
        <a href="<?= e(url('/compare')) ?>"><?= _e('nav.compare') ?></a>
        <a href="<?= e(url('/value')) ?>"><?= _e('nav.value') ?></a>
        <a href="<?= e(url('/hardware')) ?>"><?= _e('nav.hardware') ?></a>
        <a href="<?= e(url('/advisor')) ?>"><?= _e('nav.advisor') ?></a>
      </nav>
      <nav class="footer-col" aria-labelledby="ft-dev">
        <h2 class="footer-title" id="ft-dev"><?= _e('footer.developers') ?></h2>
        <a href="<?= e(url('/api-docs')) ?>"><?= _e('nav.api') ?></a>
        <a href="<?= e(url('/export/leaderboard.csv')) ?>"><?= _e('footer.csv') ?></a>
        <a href="<?= e(url('/changelog')) ?>"><?= _e('nav.changelog') ?></a>
        <a href="<?= e(url('/request')) ?>"><?= _e('nav.request') ?></a>
        <a href="<?= e(url('/about')) ?>"><?= _e('nav.about') ?></a>
      </nav>
    </div>
  </div>
  <div class="container-wide footer-bottom t-xs">
    <div class="cluster">
      <span>© <?= gmdate('Y') ?> <?= e(setting('site_domain', 'woaibench.si')) ?></span>
      <span><?= _e('footer.season', ['s' => setting('season')]) ?></span>
      <?php if ($lastAgg !== ''): ?><span class="footer-status"><?= ui_live_dot() ?><?= _e('design.footer_updated', ['t' => fmt_date($lastAgg, 'relative')]) ?></span><?php endif; ?>
    </div>
    <div class="cluster">
      <span><?= _e('footer.independent') ?></span>
      <a class="link-arrow" href="#main"><?= _e('design.back_to_top') ?><?= ui_icon('arrow-up', 14) ?></a>
    </div>
  </div>
</footer>
