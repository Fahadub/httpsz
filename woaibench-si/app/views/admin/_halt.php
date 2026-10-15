<?php
/**
 * Provider halt alert (setting 'provider_halt', written by the engine when OpenRouter answers 401/402/403-key-limit):
 * generation and judging stop, the campaign is paused. Shown on the dashboard and the console.
 * $live = true (console) also renders a hidden generic alert that console.js reveals when a step reports `halted`.
 */
defined('WB') || exit;
$halt = setting('provider_halt');
$halt = is_array($halt) && !empty($halt['code']) ? $halt : null;
$live = $live ?? false;
if (!$halt && !$live) {
    return;
}
$code = $halt ? (int) $halt['code'] : 0;
$cid = $halt ? (int) ($halt['campaign_id'] ?? 0) : 0;
$others = $halt ? count(array_filter(array_map('intval', (array) ($halt['campaign_ids'] ?? [])), static fn(int $id): bool => $id > 0 && $id !== $cid)) : 0;
$credits = 'https://openrouter.ai/credits';
?>
<div class="alert alert-error admin-halt" role="alert"<?= $halt ? '' : ' data-halt-live hidden' ?>>
  <?= ui_icon('alert', 18) ?>
  <div class="stack-sm admin-halt-body">
    <p class="alert-title"><?= $halt ? _e(in_array($code, [401, 402, 403], true) ? 'admin.halt.title_' . $code : 'admin.halt.title') : _e('admin.halt.title') ?></p>
    <p class="t-small"><?= $cid > 0 ? _e('admin.halt.text', ['id' => $cid]) : _e('admin.halt.text_none') ?><?php if ($others > 0): ?> <?= _e('admin.halt.others', ['n' => $others]) ?><?php endif; ?></p>
    <?php if ($halt && trim((string) ($halt['message'] ?? '')) !== ''): ?>
      <p class="t-xs t-muted"><?= _e('admin.halt.message') ?> <q class="admin-halt-msg" dir="auto"><?= e((string) $halt['message']) ?></q></p>
    <?php endif; ?>
    <?php if ($halt && !empty($halt['at'])): ?><p class="t-xs t-muted"><?= _e('admin.halt.since', ['t' => fmt_date((string) $halt['at'], 'relative')]) ?></p><?php endif; ?>
    <?php if (!$halt): ?><p class="t-xs t-muted"><?= _e('admin.halt.reload') ?></p><?php endif; ?>
    <div class="cluster">
      <a class="btn btn-sm btn-outline" href="<?= e($credits) ?>" target="_blank" rel="noopener noreferrer"><?= ui_icon('coin', 14) ?><span><?= _e('admin.halt.credits') ?></span><?= ui_icon('external', 12) ?></a>
      <?php if ($code === 401 || $code === 403): ?><a class="btn btn-sm btn-ghost" href="<?= e(url('/admin/settings#s-secrets')) ?>"><?= ui_icon('key', 14) ?><span><?= _e('admin.halt.key') ?></span></a><?php endif; ?>
      <?php if ($halt): ?>
        <form method="post" action="<?= e(url('/admin/halt/clear')) ?>">
          <?= csrf_field() ?>
          <button class="btn btn-sm btn-primary" type="submit"><?= ui_icon('play', 14) ?><span><?= _e('admin.halt.resume') ?></span></button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
