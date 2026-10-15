<?php
/**
 * Shared body for error pages. Variables: $code (int), $message (?string; shown for 4xx, and for 5xx only in debug mode).
 */
defined('WB') || exit;
$code = (int) ($code ?? 500);
$known = in_array($code, [403, 404, 419, 429, 500], true) ? (string) $code : 'generic';
$title = __('error.title_' . $known);
$text = (!empty($message) && $code < 500) ? (string) $message : __('error.text_' . $known);
$showDebug = !empty($message) && $code >= 500 && Config::get('debug');
?>
<section class="error-page">
  <div class="container-narrow">
    <div class="error-stage" aria-hidden="true">
      <div class="hero-art"><canvas data-hero-art="quiet"></canvas></div>
      <div class="error-code t-display t-gold"><?= e((string) $code) ?></div>
    </div>
    <div class="error-copy">
      <span class="eyebrow eyebrow-center"><?= _e('design.error_eyebrow_' . $known) ?></span>
      <h1 class="t-h2"><?= e($title) ?></h1>
      <p class="t-lead"><?= e($text) ?></p>
      <div class="cluster justify-center mt-2">
        <a class="btn btn-primary" href="<?= e(url('/')) ?>"><?= ui_icon('home', 17) ?><?= _e('error.home') ?></a>
        <a class="btn btn-outline" href="<?= e(url('/leaderboard')) ?>"><?= ui_icon('trophy', 17) ?><?= _e('nav.leaderboard') ?></a>
      </div>
      <?php if ($showDebug): ?>
        <details class="w-full mt-6"><summary class="t-small t-muted"><?= _e('design.error_details') ?></summary><pre class="code-block mt-4"><?= e((string) $message) ?></pre></details>
      <?php endif; ?>
    </div>
  </div>
</section>
