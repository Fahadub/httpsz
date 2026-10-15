<?php
/** Generic installer error. Vars: $message. */
defined('WB') || exit;
?>
<section class="hero-done hero-locked">
  <div class="emblem emblem-error" aria-hidden="true"><svg class="ic" viewBox="0 0 24 24"><use href="#i-alert"/></svg></div>
  <h1 class="display"><?= _e('setup.err_title') ?></h1>
  <p class="lead"><?= e($message) ?></p>
</section>
<div class="actions actions-end">
  <a class="btn btn-primary" href="<?= e(\WB\Setup\Wizard::url(1)) ?>"><?= _e('setup.start_over') ?></a>
</div>
