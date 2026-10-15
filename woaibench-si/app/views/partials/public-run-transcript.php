<?php
/**
 * Run page — agent transcript. Vars: tx (Runs::transcript). All tool arguments/results are escaped.
 */
defined('WB') || exit;

$cut = static fn(string $s, int $n = 6000): string => mb_strlen($s) > $n ? mb_substr($s, 0, $n) . ' …' : $s;
$n = 0;
?>
<div class="stack">
  <?php if (!$tx['steps'] && $tx['raw'] === ''): ?>
    <?= ui_empty(__('pub.run.no_transcript'), '', 'robot') ?>
  <?php elseif (!$tx['steps']): ?>
    <div class="card card-pad stack-sm">
      <h3 class="card-title"><?= _e('pub.run.transcript_raw') ?></h3>
      <pre class="code-block pub-raw" dir="ltr" tabindex="0"><?= e($cut($tx['raw'], 200000)) ?></pre>
    </div>
  <?php else: ?>
  <ol class="pub-timeline">
    <?php foreach ($tx['steps'] as $s): ?>
      <?php if ($s['kind'] === 'tool'): $n++; ?>
        <li class="pub-step is-tool">
          <span class="pub-step-dot t-num"><?= $n ?></span>
          <div class="card card-pad stack-sm">
            <div class="split"><span class="t-mono pub-tool-name"><?= ui_icon('wand', 14) ?> <?= e($s['tool']) ?></span><?php if ($s['error'] !== ''): ?><?= ui_badge(__('pub.run.tool_error'), 'red', 'alert') ?><?php endif; ?></div>
            <?php if ($s['args'] !== '' && $s['args'] !== '[]' && $s['args'] !== '{}'): ?>
              <div><span class="t-xs t-muted"><?= _e('pub.run.args') ?></span><pre class="code-block pub-raw t-xs" dir="ltr"><?= e($cut($s['args'])) ?></pre></div>
            <?php endif; ?>
            <?php if ($s['result'] !== ''): ?>
              <div><span class="t-xs t-muted"><?= _e('pub.run.tool_result') ?></span><pre class="code-block pub-raw t-xs" dir="auto"><?= e($cut($s['result'])) ?></pre></div>
            <?php endif; ?>
            <?php if ($s['error'] !== ''): ?><pre class="code-block pub-raw t-xs pub-err" dir="ltr"><?= e($cut($s['error'], 2000)) ?></pre><?php endif; ?>
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

  <?php if ($tx['final'] !== null && $tx['final'] !== ''): ?>
  <div class="card card-pad stack-sm">
    <h3 class="card-title"><?= ui_icon('flag', 16) ?> <?= _e('pub.run.final_answer') ?></h3>
    <pre class="code-block pub-raw" dir="auto"><?= e($cut($tx['final'], 8000)) ?></pre>
  </div>
  <?php endif; ?>

  <?php if ($tx['goals']): ?>
  <div class="card card-pad stack-sm">
    <h3 class="card-title"><?= ui_icon('target', 16) ?> <?= _e('pub.run.goals') ?></h3>
    <ul class="pub-checks">
      <?php foreach ($tx['goals'] as $g): $pass = !empty($g['pass']); ?>
        <li class="<?= $pass ? 'is-pass' : 'is-fail' ?>">
          <span class="pub-check-icon"><?= ui_icon($pass ? 'check-circle' : 'x-circle', 17) ?><span class="sr-only"><?= $pass ? _e('pub.pass') : _e('pub.fail') ?></span></span>
          <span class="pub-check-body"><span class="pub-check-label t-mono t-xs"><?= e((string) ($g['type'] ?? '')) ?></span>
            <?php $detail = $g['detail'] ?? ($g['path'] ?? ($g['tool'] ?? '')); if (is_scalar($detail) && (string) $detail !== ''): ?><span class="t-xs t-muted pub-break"><?= e(mb_substr((string) $detail, 0, 400)) ?></span><?php endif; ?>
          </span>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>
</div>
