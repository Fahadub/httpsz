<?php
/** Step 4 — review and install. Vars: $db, $db_info, $site, $admin, $key_tail, $seed, $flashes. */
defined('WB') || exit;
?>
<header class="panel-head">
  <span class="eyebrow"><?= _e('setup.step_of', ['n' => 4, 'total' => 4]) ?></span>
  <h1 class="display"><?= _e('setup.review_title') ?></h1>
  <p class="lead"><?= _e('setup.review_lead') ?></p>
</header>
<?= \WB\Setup\Wizard::partial('_flashes', ['flashes' => $flashes]) ?>

<div class="summary-grid">
  <section class="sum-card">
    <h2 class="sum-title"><svg class="ic" aria-hidden="true"><use href="#i-db"/></svg><?= _e('setup.rv_database') ?><a class="sum-edit" href="<?= e(\WB\Setup\Wizard::url(2)) ?>"><?= _e('setup.edit') ?></a></h2>
    <dl class="dl">
      <div><dt><?= _e('setup.rv_server') ?></dt><dd><bdi><?= e($db_info['server'] ?? '—') ?></bdi></dd></div>
      <div><dt><?= _e('setup.db_host') ?></dt><dd class="mono"><bdi><?= e($db['host'] . ':' . $db['port']) ?></bdi></dd></div>
      <div><dt><?= _e('setup.db_name') ?></dt><dd class="mono"><bdi><?= e($db['name']) ?></bdi></dd></div>
      <div><dt><?= _e('setup.db_user') ?></dt><dd class="mono"><bdi><?= e($db['user']) ?></bdi></dd></div>
    </dl>
  </section>
  <section class="sum-card">
    <h2 class="sum-title"><svg class="ic" aria-hidden="true"><use href="#i-globe"/></svg><?= _e('setup.rv_site') ?><a class="sum-edit" href="<?= e(\WB\Setup\Wizard::url(3)) ?>"><?= _e('setup.edit') ?></a></h2>
    <dl class="dl">
      <div><dt><?= _e('setup.site_url') ?></dt><dd class="mono"><bdi><?= e($site['url']) ?></bdi></dd></div>
      <div><dt><?= _e('setup.site_name') ?></dt><dd><bdi><?= e($site['name']) ?></bdi></dd></div>
      <div><dt><?= _e('setup.default_lang') ?></dt><dd><?= _e('setup.lang_' . $site['lang']) ?></dd></div>
      <div><dt><?= _e('setup.rv_key') ?></dt><dd><?= $key_tail !== '' ? _e('setup.rv_key_set', ['tail' => "\u{2066}…" . $key_tail . "\u{2069}"]) : '<span class="muted">' . _e('setup.rv_key_none') . '</span>' ?></dd></div>
    </dl>
  </section>
  <section class="sum-card sum-wide">
    <h2 class="sum-title"><svg class="ic" aria-hidden="true"><use href="#i-user"/></svg><?= _e('setup.rv_admin') ?><a class="sum-edit" href="<?= e(\WB\Setup\Wizard::url(3)) ?>"><?= _e('setup.edit') ?></a></h2>
    <dl class="dl dl-inline">
      <div><dt><?= _e('setup.admin_name') ?></dt><dd><bdi><?= e($admin['name']) ?></bdi></dd></div>
      <div><dt><?= _e('setup.admin_email') ?></dt><dd class="mono"><bdi><?= e($admin['email']) ?></bdi></dd></div>
    </dl>
  </section>
</div>

<section class="plan">
  <h2 class="group-title"><?= _e('setup.rv_will') ?></h2>
  <ol class="plan-list">
    <li><span class="plan-n">1</span><span><?= _e('setup.will_tables') ?><?php if (!empty($db_info['existing_tables'])): ?> <em class="muted"><?= _e('setup.db_existing', ['n' => (int) $db_info['existing_tables']]) ?></em><?php endif; ?></span></li>
    <li><span class="plan-n">2</span><span><?= _e('setup.will_seed', ['tracks' => $seed['tracks'], 'tasks' => $seed['tasks'], 'gpus' => $seed['gpus']]) ?></span></li>
    <li><span class="plan-n">3</span><span><?= _e('setup.will_admin') ?></span></li>
    <li><span class="plan-n">4</span><span><?= _e('setup.will_config') ?></span></li>
    <li><span class="plan-n">5</span><span><?= _e('setup.will_lock') ?></span></li>
  </ol>
<?php if ($seed['errors']): ?>
  <details class="callout callout-warn seed-errors">
    <summary><svg class="ic" aria-hidden="true"><use href="#i-alert"/></svg><?= _e('setup.seed_warn', ['n' => count($seed['errors'])]) ?></summary>
    <ul class="mono" dir="ltr"><?php foreach (array_slice($seed['errors'], 0, 30) as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
  </details>
<?php endif; ?>
</section>

<form class="form" method="post" action="<?= e(\WB\Setup\Wizard::url(4)) ?>" data-busy>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="install">
  <div class="actions">
    <a class="btn btn-ghost" href="<?= e(\WB\Setup\Wizard::url(3)) ?>"><svg class="ic flip-back" aria-hidden="true"><use href="#i-arrow"/></svg><?= _e('setup.back') ?></a>
    <button class="btn btn-primary btn-lg" type="submit" data-busy-text="<?= _e('setup.installing') ?>"><svg class="ic" aria-hidden="true"><use href="#i-spark"/></svg><?= _e('setup.install_now') ?></button>
  </div>
</form>
<form class="restart" method="post" action="<?= e(\WB\Setup\Wizard::url(1)) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="restart">
  <button class="link-btn" type="submit"><?= _e('setup.start_over') ?></button>
</form>
