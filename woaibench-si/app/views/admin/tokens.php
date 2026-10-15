<?php
/** Verifier API tokens. */
defined('WB') || exit;
echo partial('admin/_head', ['title' => __('admin.nav.tokens'), 'sub' => __('admin.tokens.sub')]);
$site = site_origin() . base_path();
?>
<?php if ($new_token): ?>
  <section class="card card-glass card-pad stack admin-new-token" role="status">
    <strong><?= ui_icon('key', 16) ?> <?= _e('admin.tokens.created', ['name' => $new_token['name']]) ?></strong>
    <p class="t-small"><?= _e('admin.tokens.once') ?></p>
    <div class="admin-copyline"><code class="t-mono" dir="ltr" id="new-token"><?= e($new_token['token']) ?></code><button class="btn btn-sm btn-primary" type="button" data-copy="<?= e($new_token['token']) ?>"><?= ui_icon('copy', 14) ?><span><?= _e('common.copy') ?></span></button></div>
  </section>
<?php endif; ?>
<div class="admin-split">
  <div class="stack-lg">
    <form class="card card-pad form stack" method="post" action="<?= e(url('/admin/tokens')) ?>">
      <?= csrf_field() ?>
      <h2 class="card-title"><?= ui_icon('plus', 18) ?> <?= _e('admin.tokens.new') ?></h2>
      <div class="input-group">
        <label class="sr-only" for="tok-name"><?= _e('admin.tokens.name') ?></label>
        <input class="input" id="tok-name" name="name" maxlength="120" required placeholder="<?= _e('admin.tokens.name_ph') ?>">
        <button class="btn btn-primary" type="submit"><?= ui_icon('key', 16) ?><span><?= _e('admin.tokens.create') ?></span></button>
      </div>
      <span class="help"><?= _e('admin.tokens.scope_help') ?></span>
    </form>
    <section class="card">
      <?php if (!$tokens): ?>
        <div class="card-pad"><?= ui_empty(__('admin.tokens.empty'), __('admin.tokens.empty_text'), 'key') ?></div>
      <?php else: ?>
      <div class="table-wrap">
        <table class="table admin-table">
          <thead><tr><th><?= _e('admin.tokens.name') ?></th><th><?= _e('admin.tokens.scope') ?></th><th><?= _e('admin.tokens.fingerprint') ?></th><th><?= _e('admin.tokens.last_used') ?></th><th><?= _e('admin.campaigns.created') ?></th><th><span class="sr-only"><?= _e('admin.actions') ?></span></th></tr></thead>
          <tbody>
          <?php foreach ($tokens as $t): ?>
            <tr>
              <td><strong><?= e($t['name']) ?></strong></td>
              <td><span class="pill t-mono"><?= e($t['scope']) ?></span></td>
              <td class="t-mono t-xs" dir="ltr">sha256:<?= e($t['hash_prefix']) ?>…</td>
              <td class="t-xs"><?= $t['last_used_at'] ? e(fmt_date($t['last_used_at'], 'relative')) : '<span class="t-muted">' . _e('admin.tokens.never') . '</span>' ?></td>
              <td class="t-xs t-muted"><?= e(fmt_date($t['created_at'], 'date')) ?></td>
              <td><form method="post" action="<?= e(url('/admin/tokens/' . $t['id'] . '/revoke')) ?>" data-confirm="<?= _e('admin.tokens.revoke_confirm') ?>"><?= csrf_field() ?><button class="btn btn-sm btn-danger" type="submit"><?= ui_icon('trash', 14) ?><span><?= _e('admin.tokens.revoke') ?></span></button></form></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </section>
  </div>
  <aside class="admin-aside card card-pad stack-sm">
    <h2 class="card-title"><?= ui_icon('terminal', 18) ?> <?= _e('admin.tokens.usage') ?></h2>
    <p class="t-small t-muted"><?= _e('admin.tokens.usage_help') ?></p>
    <pre class="code-block t-xs" dir="ltr">cd tools/verifier-node
npm install
npx playwright install chromium
node verify.mjs --site <?= e($site) ?> --token &lt;TOKEN&gt;</pre>
    <p class="t-xs t-muted"><?= _e('admin.tokens.usage_note') ?></p>
  </aside>
</div>
