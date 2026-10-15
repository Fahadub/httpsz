<?php
/** Admin page heading: $title, optional $eyebrow, $sub, $actions (trusted HTML), $back (url). */
defined('WB') || exit;
?>
<div class="admin-head">
  <div class="admin-head-text">
    <?php if (!empty($back)): ?><a class="admin-back t-small" href="<?= e($back) ?>"><?= ui_icon(is_rtl() ? 'arrow-right' : 'arrow-left', 14) ?><?= _e('common.back') ?></a><?php endif; ?>
    <?php if (!empty($eyebrow)): ?><span class="eyebrow"><?= e($eyebrow) ?></span><?php endif; ?>
    <h1 class="admin-title"><?= e($title ?? '') ?></h1>
    <?php if (!empty($sub)): ?><p class="admin-sub t-muted"><?= e($sub) ?></p><?php endif; ?>
  </div>
  <?php if (!empty($actions)): ?><div class="admin-head-actions"><?= $actions ?></div><?php endif; ?>
</div>
