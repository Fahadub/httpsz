<?php
/**
 * Run page — answer panel for exact-answer tasks. Vars: answer (Runs::answer), raw (raw model output).
 * The expected answer is never shown publicly (it would leak the task's solution); only the model's
 * answer and whether it was accepted.
 */
defined('WB') || exit;
?>
<div class="stack">
  <div class="card card-pad stack-sm pub-answer <?= $answer['pass'] === true ? 'is-pass' : ($answer['pass'] === false ? 'is-fail' : '') ?>">
    <div class="split">
      <span class="stat-label"><?= _e('pub.run.given') ?></span>
      <?php if ($answer['pass'] !== null): ?>
        <?= $answer['pass'] ? ui_badge(__('pub.run.correct'), 'green', 'check') : ui_badge(__('pub.run.incorrect'), 'red', 'x') ?>
      <?php endif; ?>
    </div>
    <?php if ($answer['given'] === null): ?>
      <p class="t-muted"><?= _e('pub.run.no_answer_tag') ?></p>
    <?php else: ?>
      <pre class="code-block pub-answer-value" dir="auto"><?= e(mb_substr($answer['given'], 0, 4000)) ?></pre>
    <?php endif; ?>
    <p class="t-xs t-muted pub-private-note"><?= ui_icon('lock', 13) ?> <span><?= _e('pub.run.expected_private', ['type' => I18n::has('pub.answer_type.' . $answer['type']) ? __('pub.answer_type.' . $answer['type']) : $answer['type']]) ?></span></p>
  </div>
  <details class="card card-pad pub-details">
    <summary><?= _e('pub.run.raw_output') ?></summary>
    <pre class="code-block pub-raw" dir="auto" tabindex="0"><?= e(mb_strcut($raw, 0, 400000)) ?></pre>
  </details>
</div>
