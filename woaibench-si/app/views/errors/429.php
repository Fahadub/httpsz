<?php
/** Error page (429). */
defined('WB') || exit;
$code = $code ?? 429;
$title = __('error.title_' . (in_array($code, [403, 404, 419, 429, 500], true) ? $code : 'generic'));
?>
<section class="section error-page">
  <div class="container-narrow t-center stack">
    <div class="error-code t-display t-gold"><?= e((string) $code) ?></div>
    <h1 class="t-h2"><?= e($title) ?></h1>
    <p class="t-lead t-muted"><?= e(!empty($message) && $code !== 500 ? $message : __('error.text_' . (in_array($code, [403, 404, 419, 429, 500], true) ? $code : 'generic'))) ?></p>
    <?php if (!empty($message) && $code === 500 && Config::get('debug')): ?><pre class="code-block"><?= e($message) ?></pre><?php endif; ?>
    <div class="cluster" style="justify-content:center"><a class="btn btn-primary" href="<?= e(url('/')) ?>"><?= _e('error.home') ?></a><a class="btn btn-ghost" href="<?= e(url('/leaderboard')) ?>"><?= _e('nav.leaderboard') ?></a></div>
  </div>
</section>
