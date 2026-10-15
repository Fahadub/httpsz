<?php
/** Step 2 — database connection. Vars: $v (host, port, name, user), $has_pass, $error, $info, $flashes. */
defined('WB') || exit;

$err = $error ? __($error['key'], $error['params'] ?? []) : null;
?>
<header class="panel-head">
  <span class="eyebrow"><?= _e('setup.step_of', ['n' => 2, 'total' => 4]) ?></span>
  <h1 class="display"><?= _e('setup.db_title') ?></h1>
  <p class="lead"><?= _e('setup.db_lead') ?></p>
</header>
<?= \WB\Setup\Wizard::partial('_flashes', ['flashes' => $flashes]) ?>

<?php if ($err): ?>
<div class="callout callout-error" role="alert" id="db-error">
  <svg class="ic" aria-hidden="true"><use href="#i-alert"/></svg>
  <div>
    <strong><?= e($err) ?></strong>
    <?php if (!empty($error['detail'])): ?><details class="tech"><summary><?= _e('setup.tech_details') ?></summary><code dir="ltr"><?= e($error['detail']) ?></code></details><?php endif; ?>
  </div>
</div>
<?php elseif ($info): ?>
<div class="callout callout-success" role="status">
  <svg class="ic" aria-hidden="true"><use href="#i-check"/></svg>
  <div><?= _e('setup.db_ok', ['server' => $info['server']]) ?></div>
</div>
<?php endif; ?>

<form class="form" method="post" action="<?= e(\WB\Setup\Wizard::url(2)) ?>" data-busy autocomplete="off" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="db">

  <div class="callout callout-gold">
    <svg class="ic" aria-hidden="true"><use href="#i-server"/></svg>
    <div><strong><?= _e('setup.db_host_title') ?></strong><p><?= _e('setup.db_host_help') ?></p></div>
  </div>

  <div class="row row-host">
    <div class="field">
      <label class="label" for="db_host"><?= _e('setup.db_host') ?></label>
      <input class="input mono" id="db_host" name="db_host" type="text" dir="ltr" required maxlength="190" value="<?= e($v['host']) ?>" spellcheck="false" autocapitalize="off">
    </div>
    <div class="field">
      <label class="label" for="db_port"><?= _e('setup.db_port') ?></label>
      <input class="input mono" id="db_port" name="db_port" type="number" dir="ltr" min="1" max="65535" required value="<?= (int) $v['port'] ?>">
    </div>
  </div>

  <div class="row row-2">
    <div class="field">
      <label class="label" for="db_name"><?= _e('setup.db_name') ?></label>
      <input class="input mono" id="db_name" name="db_name" type="text" dir="ltr" required maxlength="64" value="<?= e($v['name']) ?>" placeholder="u123456789_woaibench" spellcheck="false" autocapitalize="off"<?= $err ? ' aria-describedby="db-error"' : '' ?>>
      <span class="help"><?= _e('setup.db_name_help') ?></span>
    </div>
    <div class="field">
      <label class="label" for="db_user"><?= _e('setup.db_user') ?></label>
      <input class="input mono" id="db_user" name="db_user" type="text" dir="ltr" required maxlength="80" value="<?= e($v['user']) ?>" placeholder="u123456789_wbuser" spellcheck="false" autocapitalize="off">
    </div>
  </div>

  <div class="field">
    <label class="label" for="db_pass"><?= _e('setup.db_pass') ?></label>
    <div class="input-wrap">
      <input class="input mono" id="db_pass" name="db_pass" type="password" dir="ltr" maxlength="200" autocomplete="new-password" value=""<?= $has_pass ? ' placeholder="••••••••••"' : '' ?>>
      <button class="input-btn" type="button" data-reveal="#db_pass" aria-label="<?= _e('setup.show_password') ?>"><svg class="ic" aria-hidden="true"><use href="#i-eye"/></svg></button>
    </div>
    <span class="help"><?= $has_pass ? _e('setup.db_pass_keep') : _e('setup.db_pass_help') ?></span>
  </div>

  <div class="actions">
    <a class="btn btn-ghost" href="<?= e(\WB\Setup\Wizard::url(1)) ?>"><svg class="ic flip-back" aria-hidden="true"><use href="#i-arrow"/></svg><?= _e('setup.back') ?></a>
    <button class="btn btn-primary" type="submit" data-busy-text="<?= _e('setup.db_testing') ?>"><?= _e('setup.db_test') ?><svg class="ic flip" aria-hidden="true"><use href="#i-arrow"/></svg></button>
  </div>
</form>
