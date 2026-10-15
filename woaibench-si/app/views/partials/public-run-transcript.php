<?php
/**
 * Run page — agent transcript. Vars: tx (Runs::transcript). All tool arguments/results are escaped.
 * Goal checks are shown in the Checks tab (public-run-checks.php).
 */
defined('WB') || exit;

$cut = static fn(string $s, int $n = 6000): string => mb_strlen($s) > $n ? mb_substr($s, 0, $n) . ' …' : $s;
$meta = $tx['meta'] ?? [];
$n = 0;
?>
<div class="stack">
  <?php if (($meta['end_reason'] ?? '') !== '' || ($meta['turns'] ?? null) !== null): ?>
  <div class="cluster pub-agent-meta t-xs">
    <?php if (($meta['steps'] ?? null) !== null): ?><span class="pill"><?= ui_icon('wand', 13) ?> <?= _e('pub.run.agent_steps', ['n' => fmt_num($meta['steps'])]) ?></span><?php endif; ?>
    <?php if (($meta['turns'] ?? null) !== null): ?><span class="pill"><?= ui_icon('refresh', 13) ?> <?= _e('pub.run.agent_turns', ['n' => fmt_num($meta['turns'])]) ?></span><?php endif; ?>
    <?php if (($meta['end_reason'] ?? '') !== ''): ?><span class="pill"><?= ui_icon('flag', 13) ?> <?= e(I18n::has('pub.run.end.' . $meta['end_reason']) ? __('pub.run.end.' . $meta['end_reason']) : $meta['end_reason']) ?></span><?php endif; ?>
    <?php if (($meta['protocol'] ?? '') !== ''): ?><span class="pill" data-tip="<?= _e('pub.run.protocol_tip') ?>"><?= ui_icon('terminal', 13) ?> <?= e(I18n::has('pub.run.protocol.' . $meta['protocol']) ? __('pub.run.protocol.' . $meta['protocol']) : $meta['protocol']) ?></span><?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if (!empty($tx['intro'])): ?>
  <details class="card card-pad pub-details pub-agent-intro">
    <summary><?= _e('pub.run.agent_intro') ?></summary>
    <div class="stack-sm">
      <?php foreach ($tx['intro'] as $m): ?>
        <div><span class="t-xs t-muted"><?= _e('pub.run.role_' . ($m['role'] === 'system' ? 'system' : 'user')) ?></span><pre class="code-block pub-raw t-xs" dir="auto" tabindex="0"><?= e($cut($m['text'], 12000)) ?></pre></div>
      <?php endforeach; ?>
    </div>
  </details>
  <?php endif; ?>

  <?php if (!$tx['steps'] && $tx['raw'] === ''): ?>
    <?= ui_empty(__('pub.run.no_transcript'), '', 'robot') ?>
  <?php elseif (!$tx['steps']): ?>
    <div class="card card-pad stack-sm">
      <h2 class="card-title"><?= _e('pub.run.transcript_raw') ?></h2>
      <pre class="code-block pub-raw" dir="ltr" tabindex="0"><?= e($cut($tx['raw'], 200000)) ?></pre>
    </div>
  <?php else: ?>
  <ol class="pub-timeline">
    <?php foreach ($tx['steps'] as $s): ?>
      <?php if ($s['kind'] === 'tool'): $n++; ?>
        <li class="pub-step is-tool<?= $s['error'] !== '' ? ' is-error' : '' ?>">
          <span class="pub-step-dot t-num"><?= (int) ($s['step'] ?? $n) ?></span>
          <div class="card card-pad stack-sm">
            <div class="split"><span class="t-mono pub-tool-name" dir="ltr"><?= ui_icon('wand', 14) ?> <?= e($s['tool']) ?></span><?php if ($s['error'] !== ''): ?><?= ui_badge(__('pub.run.tool_error'), 'red', 'alert') ?><?php endif; ?></div>
            <?php if ($s['args'] !== '' && $s['args'] !== '[]' && $s['args'] !== '{}'): ?>
              <div><span class="t-xs t-muted"><?= _e('pub.run.args') ?></span><pre class="code-block pub-raw t-xs" dir="ltr" tabindex="0"><?= e($cut($s['args'])) ?></pre></div>
            <?php endif; ?>
            <?php if ($s['result'] !== ''): ?>
              <div><span class="t-xs t-muted"><?= _e('pub.run.tool_result') ?></span><pre class="code-block pub-raw t-xs" dir="auto" tabindex="0"><?= e($cut($s['result'])) ?></pre></div>
            <?php endif; ?>
            <?php if ($s['error'] !== ''): ?><pre class="code-block pub-raw t-xs pub-err" dir="ltr" tabindex="0"><?= e($cut($s['error'], 2000)) ?></pre><?php endif; ?>
          </div>
        </li>
      <?php else: ?>
        <li class="pub-step is-<?= e($s['kind']) ?>">
          <span class="pub-step-dot"><?= ui_icon($s['kind'] === 'note' ? 'terminal' : 'robot', 13) ?></span>
          <div class="pub-step-text t-small" dir="auto"><?= nl2br(e($cut($s['text']))) ?></div>
        </li>
      <?php endif; ?>
    <?php endforeach; ?>
  </ol>
  <?php endif; ?>

  <?php if (($meta['error'] ?? '') !== ''): ?>
    <div class="alert alert-warn t-small"><?= ui_icon('alert', 16) ?> <span dir="auto"><?= e($meta['error']) ?></span></div>
  <?php endif; ?>

  <?php if ($tx['final'] !== null && $tx['final'] !== ''): ?>
  <div class="card card-pad stack-sm">
    <h2 class="card-title"><?= ui_icon('flag', 16) ?> <?= _e('pub.run.final_answer') ?></h2>
    <pre class="code-block pub-raw" dir="auto" tabindex="0"><?= e($cut($tx['final'], 8000)) ?></pre>
  </div>
  <?php endif; ?>
</div>
