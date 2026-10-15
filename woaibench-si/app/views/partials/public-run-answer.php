<?php
/**
 * Run page — answer panel for exact-answer tasks. Vars: answer (Runs::answer), raw (raw model output).
 */
defined('WB') || exit;

$exp = $answer['expected'];
$expText = $exp === null ? '—' : (is_scalar($exp) ? (string) $exp : to_json($exp));
?>
<div class="stack">
  <div class="grid grid-2">
    <div class="card card-pad stack-sm pub-answer <?= $answer['pass'] === true ? 'is-pass' : ($answer['pass'] === false ? 'is-fail' : '') ?>">
      <span class="stat-label"><?= _e('pub.run.given') ?></span>
      <?php if ($answer['given'] === null): ?>
        <p class="t-muted"><?= _e('pub.run.no_answer_tag') ?></p>
      <?php else: ?>
        <pre class="code-block pub-answer-value" dir="auto"><?= e(mb_substr($answer['given'], 0, 4000)) ?></pre>
      <?php endif; ?>
      <?php if ($answer['pass'] !== null): ?>
        <?= $answer['pass'] ? ui_badge(__('pub.run.correct'), 'green', 'check') : ui_badge(__('pub.run.incorrect'), 'red', 'x') ?>
      <?php endif; ?>
    </div>
    <div class="card card-pad stack-sm">
      <span class="stat-label"><?= _e('pub.run.expected') ?></span>
      <pre class="code-block pub-answer-value" dir="auto"><?= e(mb_substr($expText, 0, 4000)) ?></pre>
      <p class="t-xs t-muted"><?= _e('pub.run.answer_type', ['type' => $answer['type']]) ?><?php if ($answer['tolerance'] !== null && is_scalar($answer['tolerance'])): ?> · <?= _e('pub.run.tolerance', ['t' => (string) $answer['tolerance']]) ?><?php endif; ?></p>
    </div>
  </div>
  <details class="card card-pad pub-details">
    <summary><?= _e('pub.run.raw_output') ?></summary>
    <pre class="code-block pub-raw" dir="auto" tabindex="0"><?= e(mb_strcut($raw, 0, 400000)) ?></pre>
  </details>
</div>
