<?php
/** Flash messages for installer screens. Vars: $flashes. */
defined('WB') || exit;

foreach ($flashes ?? [] as $f): $t = in_array($f['type'], ['success', 'error', 'warn', 'info'], true) ? $f['type'] : 'info'; ?>
<div class="callout callout-<?= e($t) ?>" role="<?= $t === 'error' ? 'alert' : 'status' ?>">
  <svg class="ic" aria-hidden="true"><use href="#i-<?= $t === 'success' ? 'check' : ($t === 'info' ? 'info' : 'alert') ?>"/></svg>
  <div><?= e($f['message']) ?></div>
</div>
<?php endforeach;
