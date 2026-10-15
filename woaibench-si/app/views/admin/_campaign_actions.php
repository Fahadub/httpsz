<?php
/** Action buttons for one campaign. $c = campaign row, $compact bool. */
defined('WB') || exit;
$compact = $compact ?? false;
$act = static function (string $action, string $icon, string $label, string $cls = 'btn-ghost', string $confirm = '') use ($c, $compact): string {
    return '<form method="post" action="' . e(url('/admin/campaigns/' . $c['id'] . '/action')) . '"' . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '>'
        . csrf_field() . '<input type="hidden" name="action" value="' . e($action) . '">'
        . '<button class="btn btn-sm ' . $cls . ($compact ? ' btn-icon' : '') . '" type="submit"' . ($compact ? ' aria-label="' . e($label) . '" data-tip="' . e($label) . '"' : '') . '>' . ui_icon($icon, 14) . ($compact ? '' : '<span>' . e($label) . '</span>') . '</button></form>';
};
$failed = (int) ($c['progress']['failed'] ?? 0);
$needsVerify = (int) ($c['progress']['needs_verify'] ?? 0);
?>
<div class="cluster admin-actions">
  <?php if ($c['status'] === 'running'): ?><?= $act('pause', 'pause', __('admin.campaigns.pause')) ?><?php endif; ?>
  <?php if ($c['status'] === 'paused'): ?><?= $act('resume', 'play', __('admin.campaigns.resume'), 'btn-outline') ?><?php endif; ?>
  <?php if (in_array($c['status'], ['running', 'paused'], true)): ?><?= $act('cancel', 'x', __('admin.campaigns.cancel'), 'btn-ghost', __('admin.campaigns.cancel_confirm')) ?><?php endif; ?>
  <?php if ($failed > 0): ?><?= $act('retry_failed', 'refresh', __('admin.campaigns.retry_failed', ['n' => $failed])) ?><?php endif; ?>
  <?php if ($needsVerify > 0): ?><?= $act('reverify', 'shield', __('admin.campaigns.reverify', ['n' => $needsVerify]), 'btn-outline', __('admin.campaigns.reverify_confirm', ['n' => $needsVerify])) ?><?php endif; ?>
  <?= (int) $c['include_in_scores'] ? $act('exclude', 'eye-off', __('admin.campaigns.exclude'), 'btn-ghost', __('admin.campaigns.exclude_confirm')) : $act('include', 'eye', __('admin.campaigns.include'), 'btn-outline') ?>
  <?php if (!$compact && $c['status'] !== 'running'): ?><?= $act('delete', 'trash', __('common.delete'), 'btn-danger', __('admin.campaigns.delete_confirm')) ?><?php endif; ?>
</div>
