<?php
/** Success screen. Vars: $r (install summary: tables, seed, admin_created, lock, site_url, admin_email, season, cron, web_cron). */
defined('WB') || exit;

$site = rtrim((string) $r['site_url'], '/');
$adminUrl = $site . '/admin';
$seed = $r['seed'];
$errors = count($seed['errors'] ?? []);
$stats = [
    ['setup.done_stats_tables', (int) $r['tables'], 'db'],
    ['setup.done_stats_tracks', (int) ($seed['tracks']['inserted'] + $seed['tracks']['updated'] + $seed['tracks']['unchanged']), 'cpu'],
    ['setup.done_stats_tasks', (int) ($seed['tasks']['inserted'] + $seed['tasks']['updated'] + $seed['tasks']['unchanged']), 'spark'],
    ['setup.done_stats_gpus', (int) ($seed['gpus']['inserted'] + $seed['gpus']['updated'] + $seed['gpus']['unchanged']), 'server'],
];
?>
<section class="hero-done">
  <div class="emblem" aria-hidden="true"><svg class="ic" viewBox="0 0 24 24"><use href="#i-check"/></svg></div>
  <span class="eyebrow"><?= _e('setup.done_eyebrow', ['season' => $r['season']]) ?></span>
  <h1 class="display"><?= _e('setup.done_title') ?></h1>
  <p class="lead"><?= _e('setup.done_lead') ?></p>
</section>

<div class="stats">
<?php foreach ($stats as [$label, $n, $icon]): ?>
  <div class="stat"><svg class="ic" aria-hidden="true"><use href="#i-<?= $icon ?>"/></svg><span class="stat-value"><?= (int) $n ?></span><span class="stat-label"><?= _e($label) ?></span></div>
<?php endforeach; ?>
</div>

<?php if (!$r['lock']): ?>
<div class="callout callout-error" role="alert"><svg class="ic" aria-hidden="true"><use href="#i-alert"/></svg><div><?= _e('setup.lock_failed') ?></div></div>
<?php endif; ?>
<?php if ($errors): ?>
<div class="callout callout-warn" role="status"><svg class="ic" aria-hidden="true"><use href="#i-alert"/></svg><div><?= _e('setup.seed_errors', ['n' => $errors]) ?></div></div>
<?php endif; ?>
<?php if (!$r['admin_created']): ?>
<div class="callout callout-info" role="status"><svg class="ic" aria-hidden="true"><use href="#i-info"/></svg><div><?= _e('setup.admin_updated', ['email' => $r['admin_email']]) ?></div></div>
<?php endif; ?>

<section class="next">
  <h2 class="group-title"><?= _e('setup.next_title') ?></h2>
  <ol class="timeline">
    <li><span class="tl-n">1</span><div><strong><?= _e('setup.next_login_t') ?></strong><p><?= _e('setup.next_login') ?> <a class="mono" dir="ltr" href="<?= e($adminUrl) ?>"><?= e($adminUrl) ?></a></p></div></li>
    <li><span class="tl-n">2</span><div><strong><?= _e('setup.next_sync_t') ?></strong><p><?= _e('setup.next_sync') ?></p></div></li>
    <li><span class="tl-n">3</span><div><strong><?= _e('setup.next_judges_t') ?></strong><p><?= _e('setup.next_judges') ?></p></div></li>
    <li><span class="tl-n">4</span><div><strong><?= _e('setup.next_campaign_t') ?></strong><p><?= _e('setup.next_campaign') ?></p></div></li>
    <li><span class="tl-n">5</span><div><strong><?= _e('setup.next_cron_t') ?></strong><p><?= _e('setup.next_cron') ?></p>
      <div class="code"><code id="cron-cmd" dir="ltr"><?= e($r['cron']) ?></code><button class="code-copy" type="button" data-copy="#cron-cmd"><svg class="ic" aria-hidden="true"><use href="#i-copy"/></svg><span><?= _e('setup.copy') ?></span></button></div>
      <p class="help"><?= _e('setup.next_cron_help') ?></p>
<?php if ($r['web_cron'] !== ''): ?>
      <details class="tech"><summary><?= _e('setup.next_cron_alt_t') ?></summary><p class="help"><?= _e('setup.next_cron_alt') ?></p>
        <div class="code"><code id="webcron" dir="ltr"><?= e($r['web_cron']) ?></code><button class="code-copy" type="button" data-copy="#webcron"><svg class="ic" aria-hidden="true"><use href="#i-copy"/></svg><span><?= _e('setup.copy') ?></span></button></div>
      </details>
<?php endif; ?>
    </div></li>
    <li><span class="tl-n">6</span><div><strong><?= _e('setup.next_verifier_t') ?></strong><p><?= _e('setup.next_verifier') ?></p></div></li>
  </ol>
</section>

<div class="callout callout-gold">
  <svg class="ic" aria-hidden="true"><use href="#i-shield"/></svg>
  <div><strong><?= _e('setup.delete_install_t') ?></strong><p><?= _e('setup.delete_install') ?></p></div>
</div>

<div class="actions">
  <a class="btn btn-ghost" href="<?= e($site . '/') ?>"><?= _e('setup.view_site') ?></a>
  <a class="btn btn-primary" href="<?= e($adminUrl) ?>"><?= _e('setup.open_admin') ?><svg class="ic flip" aria-hidden="true"><use href="#i-arrow"/></svg></a>
</div>
