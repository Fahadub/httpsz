<?php
/**
 * Request a model. Vars: enabled, form (name, or_id, email, note), errors (field => message), error (alert text), recent.
 */
defined('WB') || exit;

$err = static fn(string $f) => isset($errors[$f]) ? '<span class="help pub-field-error" id="err-' . e($f) . '">' . e($errors[$f]) . '</span>' : '';
$aria = static fn(string $f) => isset($errors[$f]) ? ' aria-invalid="true" aria-describedby="err-' . e($f) . '"' : '';
?>
<section class="section pub-hero">
  <div class="container-narrow stack-sm">
    <p class="eyebrow"><?= _e('pub.req.eyebrow') ?></p>
    <h1 class="t-h1"><?= _e('pub.req.h1') ?></h1>
    <p class="t-lead t-muted pub-lead"><?= _e('pub.req.lead') ?></p>
  </div>
</section>
<section class="section pub-section-tight">
  <div class="container-narrow stack">
    <?php if (!empty($error)): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?php if (!$enabled): ?>
      <?= ui_empty(__('pub.req.closed'), __('pub.req.closed_text'), 'lock') ?>
    <?php else: ?>
    <form class="form card card-pad stack pub-request-form" method="post" action="<?= e(url('/request')) ?>" novalidate>
      <?= csrf_field() ?>
      <div class="field">
        <label class="label" for="rq-name"><?= _e('pub.req.name') ?> <span class="t-gold" aria-hidden="true">*</span></label>
        <input class="input" id="rq-name" name="name" type="text" required maxlength="190" value="<?= e($form['name']) ?>" placeholder="<?= _e('pub.req.name_ph') ?>"<?= $aria('name') ?>>
        <?= $err('name') ?>
      </div>
      <div class="field">
        <label class="label" for="rq-or"><?= _e('pub.req.or_id') ?></label>
        <input class="input t-mono" id="rq-or" name="or_id" type="text" maxlength="190" dir="ltr" value="<?= e($form['or_id']) ?>" placeholder="vendor/model-name" autocomplete="off" spellcheck="false"<?= $aria('or_id') ?>>
        <span class="help"><?= _e('pub.req.or_id_help') ?></span>
        <?= $err('or_id') ?>
      </div>
      <div class="field">
        <label class="label" for="rq-email"><?= _e('pub.req.email') ?></label>
        <input class="input" id="rq-email" name="email" type="email" maxlength="190" dir="ltr" value="<?= e($form['email']) ?>" autocomplete="email"<?= $aria('email') ?>>
        <span class="help"><?= _e('pub.req.email_help') ?></span>
        <?= $err('email') ?>
      </div>
      <div class="field">
        <label class="label" for="rq-note"><?= _e('pub.req.note') ?></label>
        <textarea class="textarea" id="rq-note" name="note" rows="4" maxlength="2000" placeholder="<?= _e('pub.req.note_ph') ?>"<?= $aria('note') ?>><?= e($form['note']) ?></textarea>
        <?= $err('note') ?>
      </div>
      <div class="pub-hp" aria-hidden="true">
        <label for="rq-website">Website</label>
        <input id="rq-website" name="website" type="text" tabindex="-1" autocomplete="off" value="">
      </div>
      <div class="form-actions">
        <button class="btn btn-primary" type="submit"><?= ui_icon('send', 16) ?><span><?= _e('pub.req.submit') ?></span></button>
        <span class="t-xs t-muted"><?= _e('pub.req.privacy') ?></span>
      </div>
    </form>
    <?php endif; ?>
    <div class="card card-pad stack-sm pub-req-how">
      <h2 class="card-title"><?= ui_icon('info', 18) ?> <?= _e('pub.req.how_h') ?></h2>
      <ol class="t-small t-muted pub-steps">
        <li><?= _e('pub.req.how_1') ?></li>
        <li><?= _e('pub.req.how_2') ?></li>
        <li><?= _e('pub.req.how_3') ?></li>
      </ol>
      <?php if ((int) $recent > 0): ?><p class="t-xs t-muted"><?= _e('pub.req.recent', ['n' => (int) $recent]) ?></p><?php endif; ?>
    </div>
  </div>
</section>
