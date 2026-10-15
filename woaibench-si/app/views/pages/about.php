<?php
/**
 * About. Vars: stats (Leaderboard::stats), judges.
 * Team text is a placeholder the owner edits in lang/{ar,en}/public.php (pub.about.team_*).
 */
defined('WB') || exit;

$email = trim((string) setting('contact_email', ''));
$validEmail = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL);
$principles = [
    ['shield', 'pub.about.p1_h', 'pub.about.p1'],
    ['eye', 'pub.about.p2_h', 'pub.about.p2'],
    ['sigma', 'pub.about.p3_h', 'pub.about.p3'],
    ['globe', 'pub.about.p4_h', 'pub.about.p4'],
];
?>
<section class="section pub-hero">
  <div class="container stack-sm">
    <p class="eyebrow"><?= _e('pub.about.eyebrow') ?></p>
    <h1 class="t-display t-balance"><?= _e('pub.about.h1') ?></h1>
    <p class="t-lead t-muted pub-lead"><?= _e('pub.about.lead') ?></p>
  </div>
</section>
<section class="section pub-section-tight">
  <div class="container stack-lg">
    <div class="kpi-row">
      <?= ui_stat(fmt_num($stats['models_rated']), __('pub.lb.kpi_models'), 'trophy') ?>
      <?= ui_stat(fmt_num($stats['tasks']), __('pub.lb.kpi_tasks'), 'list') ?>
      <?= ui_stat(fmt_num($stats['runs_scored']), __('pub.lb.kpi_runs'), 'bolt') ?>
      <?= ui_stat(fmt_num($stats['arena_votes']), __('pub.about.kpi_votes'), 'arena') ?>
    </div>

    <div class="prose pub-prose">
      <h2><?= _e('pub.about.mission_h') ?></h2>
      <p><?= pub_rich('pub.about.mission_1') ?></p>
      <p><?= pub_rich('pub.about.mission_2') ?></p>
    </div>

    <div class="grid grid-2 pub-principles">
      <?php foreach ($principles as [$icon, $h, $t]): ?>
        <div class="card card-pad stack-sm">
          <span class="stat-icon"><?= ui_icon($icon, 20) ?></span>
          <h3 class="t-h3"><?= _e($h) ?></h3>
          <p class="t-small t-muted"><?= pub_rich($t) ?></p>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="prose pub-prose">
      <h2><?= _e('pub.about.independence_h') ?></h2>
      <p><?= pub_rich('pub.about.independence_1') ?></p>
      <p><?= pub_rich('pub.about.independence_2') ?></p>
      <h2><?= _e('pub.about.team_h') ?></h2>
      <p><?= pub_rich('pub.about.team_1') ?></p>
      <h2><?= _e('pub.about.contact_h') ?></h2>
      <p><?php if ($validEmail): ?><?= _e('pub.about.contact_email') ?> <a href="mailto:<?= e($email) ?>"><?= e($email) ?></a><?php else: ?><?= _e('pub.about.contact_none') ?><?php endif; ?></p>
      <p><?= _e('pub.about.contact_request') ?> <a href="<?= e(url('/request')) ?>"><?= _e('nav.request') ?></a></p>
    </div>
  </div>
</section>
