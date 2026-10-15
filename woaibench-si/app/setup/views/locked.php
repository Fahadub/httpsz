<?php
/** Installer refused: already installed. Vars: $reason ('lock' | 'config' | 'broken'). */
defined('WB') || exit;

$home = base_path() . '/';
?>
<section class="hero-done hero-locked">
  <div class="emblem emblem-lock" aria-hidden="true"><svg class="ic" viewBox="0 0 24 24"><use href="#i-lock"/></svg></div>
  <span class="eyebrow"><?= _e('setup.locked_eyebrow') ?></span>
  <h1 class="display"><?= _e('setup.locked_title') ?></h1>
  <p class="lead"><?= _e('setup.locked_lead') ?></p>
  <p class="reason <?= $reason === 'broken' ? 'reason-bad' : '' ?>"><svg class="ic" aria-hidden="true"><use href="#i-<?= $reason === 'broken' ? 'alert' : 'info' ?>"/></svg><span><?= _e('setup.locked_reason_' . $reason) ?></span></p>
</section>

<div class="summary-grid">
  <section class="sum-card">
    <h2 class="sum-title"><svg class="ic" aria-hidden="true"><use href="#i-refresh"/></svg><?= _e('setup.locked_upgrade_title') ?></h2>
    <p class="sum-text"><?= _e('setup.locked_upgrade') ?></p>
  </section>
  <section class="sum-card">
    <h2 class="sum-title"><svg class="ic" aria-hidden="true"><use href="#i-terminal"/></svg><?= _e('setup.locked_reinstall_title') ?></h2>
    <ol class="mini-steps">
      <li><?= _e('setup.locked_reinstall_1') ?></li>
      <li><?= _e('setup.locked_reinstall_2') ?></li>
      <li><?= _e('setup.locked_reinstall_3') ?></li>
    </ol>
  </section>
</div>

<div class="callout callout-gold">
  <svg class="ic" aria-hidden="true"><use href="#i-shield"/></svg>
  <div><strong><?= _e('setup.delete_install_t') ?></strong><p><?= _e('setup.delete_install') ?></p></div>
</div>

<div class="actions actions-end">
  <a class="btn btn-ghost" href="<?= e($home . 'admin') ?>"><?= _e('setup.open_admin') ?></a>
  <a class="btn btn-primary" href="<?= e($home) ?>"><?= _e('setup.go_site') ?><svg class="ic flip" aria-hidden="true"><use href="#i-arrow"/></svg></a>
</div>
