<?php
/**
 * Run page — automated checks panel. Vars: checks (Runs::checks), run, renderable.
 */
defined('WB') || exit;

$groups = [
    'static' => ['pub.group.static', 'list', 'pub.run.static_note'],
    'browser' => ['pub.group.browser', 'eye', 'pub.run.browser_note'],
];
$metricLabels = ['load_ms' => 'pub.metric.load_ms', 'fps' => 'pub.metric.fps', 'js_errors' => 'pub.metric.js_errors', 'console_errors' => 'pub.metric.console_errors'];
$any = $checks['static'] || $checks['browser'] || $checks['tests'];
?>
<div class="stack">
  <div class="card card-pad pub-auto-summary">
    <div>
      <span class="stat-label"><?= _e('pub.split_auto') ?></span>
      <?= ui_score($run['auto_score'], null, null, 'lg') ?>
    </div>
    <p class="t-small t-muted"><?= _e('pub.run.auto_explain') ?></p>
  </div>

  <?php if (!$checks['verified'] && $renderable): ?>
    <div class="alert alert-info t-small"><?= ui_icon('info', 16) ?> <?= _e('pub.run.not_verified_note') ?></div>
  <?php endif; ?>

  <?php if ($checks['metrics']): ?>
  <div class="kpi-row pub-metrics">
    <?php foreach ($checks['metrics'] as $k => $v): if (!is_scalar($v)) { continue; } ?>
      <?= ui_stat($k === 'load_ms' ? fmt_ms($v) : fmt_num($v), isset($metricLabels[$k]) ? __($metricLabels[$k]) : (string) $k, $k === 'fps' ? 'activity' : ($k === 'load_ms' ? 'timer' : 'bug')) ?>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php foreach ($groups as $g => [$label, $icon, $note]): if (!$checks[$g]) { continue; } ?>
  <div class="card card-pad stack-sm">
    <div class="card-head split">
      <h3 class="card-title"><?= ui_icon($icon, 16) ?> <?= _e($label) ?></h3>
      <span class="t-xs t-muted"><?= _e('pub.run.passed', ['n' => count(array_filter($checks[$g], static fn($c) => $c['pass'])), 'total' => count($checks[$g])]) ?></span>
    </div>
    <p class="t-xs t-muted"><?= _e($note) ?></p>
    <ul class="pub-checks">
      <?php foreach ($checks[$g] as $c): ?>
        <li class="<?= $c['pass'] ? 'is-pass' : 'is-fail' ?>">
          <span class="pub-check-icon"><?= ui_icon($c['pass'] ? 'check-circle' : 'x-circle', 17) ?><span class="sr-only"><?= $c['pass'] ? _e('pub.pass') : _e('pub.fail') ?></span></span>
          <span class="pub-check-body">
            <span class="pub-check-label"><?= e($c['label']) ?> <span class="t-mono t-xs t-faint"><?= e($c['type']) ?></span></span>
            <?php if ($c['detail'] !== ''): ?><span class="t-xs t-muted pub-break" dir="auto"><?= e(mb_substr($c['detail'], 0, 500)) ?></span><?php endif; ?>
          </span>
          <span class="pub-check-score t-num t-xs"><?= e(fmt_num($c['score'] * 100)) ?>% · ×<?= e(fmt_num($c['weight'], $c['weight'] == floor($c['weight']) ? 0 : 1)) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endforeach; ?>

  <?php if ($checks['tests']): $passed = count(array_filter($checks['tests'], static fn($t) => $t['pass'])); ?>
  <div class="card card-pad stack-sm">
    <div class="card-head split">
      <h3 class="card-title"><?= ui_icon('check', 16) ?> <?= _e('pub.group.tests') ?></h3>
      <span class="t-xs t-muted"><?= _e('pub.run.passed', ['n' => $passed, 'total' => count($checks['tests'])]) ?></span>
    </div>
    <span class="progress"><span class="progress-bar" style="width:<?= e(number_format(100 * $passed / max(1, count($checks['tests'])), 2, '.', '')) ?>%"></span></span>
    <div class="table-wrap">
      <table class="table table-compact">
        <thead><tr><th scope="col"><?= _e('pub.run.test') ?></th><th scope="col"><?= _e('pub.run.result') ?></th><th class="num" scope="col">ms</th><th scope="col"><?= _e('pub.run.detail') ?></th></tr></thead>
        <tbody>
        <?php foreach ($checks['tests'] as $t): ?>
          <tr class="<?= $t['pass'] ? 'is-pass' : 'is-fail' ?>">
            <td class="t-mono t-xs"><?= e($t['name']) ?></td>
            <td><?= $t['pass'] ? ui_badge(__('pub.pass'), 'green', 'check') : ui_badge(__('pub.fail'), 'red', 'x') ?></td>
            <td class="num t-num t-xs"><?= $t['ms'] !== null ? e(fmt_num($t['ms'], 1)) : '—' ?></td>
            <td class="t-mono t-xs pub-break"><?php if ($t['error']): ?><span class="pub-err"><?= e($t['error']) ?></span><?php elseif ($t['got'] !== null && !$t['pass']): ?><?= _e('pub.run.got') ?> <?= e($t['got']) ?><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($checks['errors']): ?>
  <div class="card card-pad stack-sm">
    <h3 class="card-title"><?= ui_icon('bug', 16) ?> <?= _e('pub.run.errors') ?></h3>
    <pre class="code-block pub-raw t-xs" dir="ltr" tabindex="0"><?= e(implode("\n", array_slice($checks['errors'], 0, 50))) ?></pre>
  </div>
  <?php endif; ?>

  <?php if (!$any && !$checks['metrics']): ?>
    <?= ui_empty(__('pub.run.no_checks'), __('pub.run.no_checks_text'), 'check') ?>
  <?php endif; ?>
</div>
