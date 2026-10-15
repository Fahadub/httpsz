<?php
/** Own account: password change + administrators. */
defined('WB') || exit;
echo partial('admin/_head', ['title' => __('admin.account.title'), 'sub' => __('admin.account.sub', ['email' => $user['email']])]);
?>
<div class="admin-grid admin-grid-2">
  <form class="card card-pad form stack" method="post" action="<?= e(url('/admin/account/password')) ?>" autocomplete="off">
    <?= csrf_field() ?>
    <h2 class="card-title"><?= ui_icon('lock', 18) ?> <?= _e('admin.account.change_pw') ?></h2>
    <input type="text" name="username" value="<?= e($user['email']) ?>" autocomplete="username" hidden>
    <div class="field"><label class="label" for="ac-cur"><?= _e('admin.account.current') ?></label><input class="input" id="ac-cur" type="password" name="current" autocomplete="current-password" required dir="ltr"></div>
    <div class="field"><label class="label" for="ac-new"><?= _e('admin.account.new') ?></label><input class="input" id="ac-new" type="password" name="new" autocomplete="new-password" minlength="10" required dir="ltr"><span class="help"><?= _e('admin.account.pw_rule') ?></span></div>
    <div class="field"><label class="label" for="ac-conf"><?= _e('admin.account.confirm') ?></label><input class="input" id="ac-conf" type="password" name="confirm" autocomplete="new-password" minlength="10" required dir="ltr"></div>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= ui_icon('check', 16) ?><span><?= _e('admin.account.update_pw') ?></span></button></div>
  </form>
  <div class="stack-lg">
    <section class="card">
      <div class="card-head card-pad"><h2 class="card-title"><?= ui_icon('users', 18) ?> <?= _e('admin.account.admins') ?></h2></div>
      <div class="table-wrap">
        <table class="table table-compact admin-table">
          <thead><tr><th><?= _e('admin.account.name') ?></th><th><?= _e('admin.login.email') ?></th><th><?= _e('admin.account.last_login') ?></th><th><span class="sr-only"><?= _e('admin.account.remove') ?></span></th></tr></thead>
          <tbody>
          <?php foreach ($admins as $a): ?>
            <tr<?= (int) $a['id'] === (int) $user['id'] ? ' class="is-highlight"' : '' ?>><td><?= e($a['name']) ?><?= (int) $a['id'] === (int) $user['id'] ? ' ' . ui_badge(__('admin.account.you'), 'gold') : '' ?></td><td class="t-xs" dir="ltr"><?= e($a['email']) ?></td><td class="t-xs t-muted"><?= $a['last_login_at'] ? e(fmt_date($a['last_login_at'], 'relative')) : '—' ?></td>
              <td class="shrink"><?php if ((int) $a['id'] !== (int) $user['id']): ?><form method="post" action="<?= e(url('/admin/account/admins/' . (int) $a['id'] . '/delete')) ?>" data-confirm="<?= _e('admin.account.remove_confirm', ['email' => $a['email']]) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-ghost" type="submit"><?= ui_icon('trash', 14) ?><span><?= _e('admin.account.remove') ?></span></button></form><?php endif; ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
    <form class="card card-pad form stack" method="post" action="<?= e(url('/admin/account/admins')) ?>" autocomplete="off">
      <?= csrf_field() ?>
      <h2 class="card-title"><?= ui_icon('plus', 18) ?> <?= _e('admin.account.add_admin') ?></h2>
      <div class="form-row">
        <div class="field"><label class="label" for="ad-name"><?= _e('admin.account.name') ?></label><input class="input" id="ad-name" name="name" maxlength="120" required></div>
        <div class="field"><label class="label" for="ad-email"><?= _e('admin.login.email') ?></label><input class="input" id="ad-email" type="email" name="email" maxlength="190" required dir="ltr" autocomplete="off"></div>
      </div>
      <div class="field"><label class="label" for="ad-pw"><?= _e('admin.login.password') ?></label><input class="input" id="ad-pw" type="password" name="password" minlength="10" required dir="ltr" autocomplete="new-password"><span class="help"><?= _e('admin.account.pw_rule') ?></span></div>
      <p class="help"><?= _e('admin.account.add_help') ?></p>
      <div class="form-actions"><button class="btn btn-outline" type="submit"><?= ui_icon('users', 16) ?><span><?= _e('admin.account.add') ?></span></button></div>
    </form>
  </div>
</div>
