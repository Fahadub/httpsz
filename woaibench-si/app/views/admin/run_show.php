<?php
/** Run detail: scores, outputs (escaped), checks, judgments, actions. */
defined('WB') || exit;
$r = $run;
$static = json_arr($r['static_json']);
$browser = json_arr($r['browser_json']);
$browserChecks = $browser['checks'] ?? (array_is_list($browser) ? $browser : []);
$tests = $browser['tests'] ?? [];
$act = static function (string $action, string $icon, string $label, string $cls, string $confirm) use ($r): string {
    return '<form method="post" action="' . e(url('/admin/runs/' . $r['id'] . '/action')) . '" data-confirm="' . e($confirm) . '">' . csrf_field()
        . '<input type="hidden" name="action" value="' . e($action) . '"><button class="btn btn-sm ' . $cls . '" type="submit">' . ui_icon($icon, 14) . '<span>' . e($label) . '</span></button></form>';
};
$public = $r['visibility'] === 'public';
// Engine notes ("[note:…]" lines when runs.notes is missing) are shown as notes, never as errors.
$errorText = \WB\Admin\Admin::errorText($r['error']);
$notes = $notes ?? [];
$goals = $goals ?? [];
// Required verification failed for infrastructure reasons: the run waits (unscored) until an admin re-verifies it.
$needsVerify = $r['status'] === 'awaiting_verify' && in_array('needs_verification', array_column($notes, 'code'), true);
echo partial('admin/_head', [
    'back' => url('/admin/runs', ['campaign' => $r['campaign_id']]),
    'eyebrow' => $r['campaign'] . ' · ' . __('admin.runs.attempt_n', ['n' => (int) $r['attempt']]),
    'title' => __('admin.runs.run_n', ['n' => \WB\Admin\Admin::iso('#' . $r['id'])]) . ' — ' . $r['model'],
    'sub' => L($r, 'title'),
    'actions' => '<div class="cluster">'
        . ($public ? '<a class="btn btn-sm btn-ghost" href="' . e(url('/run/' . $r['id'])) . '" target="_blank" rel="noopener">' . ui_icon('external', 14) . '<span>' . _e('admin.runs.public') . '</span></a>' : '')
        . '<a class="btn btn-sm btn-ghost" href="' . e(url('/render/' . $r['id'])) . '" target="_blank" rel="noopener">' . ui_icon('monitor', 14) . '<span>' . _e('admin.runs.render') . '</span></a>'
        . (!empty($can_retry) ? $act('retry', 'refresh', __('admin.runs.retry'), 'btn-primary', __('admin.runs.retry_confirm')) : '')
        . ($needsVerify ? $act('reverify', 'shield', __('admin.runs.reverify'), 'btn-primary', __('admin.runs.reverify_confirm')) : '')
        . $act('regenerate', 'play', __('admin.runs.regenerate'), 'btn-outline', __('admin.runs.regenerate_confirm'))
        . $act('rejudge', 'scale', __('admin.runs.rejudge'), 'btn-ghost', __('admin.runs.rejudge_confirm'))
        . $act('delete', 'trash', __('common.delete'), 'btn-danger', __('admin.runs.delete_confirm'))
        . '</div>',
]);
?>
<div class="kpi-row admin-kpis">
  <div class="stat"><span class="stat-label"><?= _e('admin.models.status') ?></span><span class="stat-value"><?= ui_status($r['status']) ?></span><?php if ((int) $r['contested']): ?><span class="stat-hint"><?= ui_badge(__('admin.runs.contested'), 'gold') ?></span><?php endif; ?></div>
  <?= ui_stat(fmt_score($r['final_score']), __('admin.runs.final'), 'trophy') ?>
  <?= ui_stat(fmt_score($r['auto_score']), __('admin.runs.auto'), 'check-circle') ?>
  <?= ui_stat(fmt_score($r['judge_score']), __('admin.runs.judge'), 'scale', $r['judge_spread'] !== null ? __('admin.runs.spread', ['n' => fmt_score($r['judge_spread'])]) : '') ?>
  <?= ui_stat(fmt_money($r['cost_usd']), __('common.cost'), 'coin', fmt_tokens(((int) $r['prompt_tokens']) + ((int) $r['completion_tokens'])) . ' ' . __('common.tokens')) ?>
  <?= ui_stat(fmt_ms($r['latency_ms']), __('common.latency'), 'timer', (string) ($r['provider_used'] ?? '')) ?>
</div>

<?php if ($errorText !== ''): ?><div class="alert alert-error"><?= ui_icon('alert', 16) ?> <span class="t-mono t-small admin-break" dir="ltr"><?= e($errorText) ?></span></div><?php endif; ?>
<?php if ($needsVerify): ?><div class="alert alert-warn t-small"><?= ui_icon('shield', 16) ?> <span><?= _e('admin.runs.needs_verification_hint') ?></span></div><?php endif; ?>
<?php if ($r['status'] === 'failed' && !empty($can_retry)): ?><div class="alert alert-info t-small"><?= ui_icon('info', 16) ?> <span><?= _e('admin.runs.retry_hint') ?></span></div><?php endif; ?>

<div class="admin-split">
  <div class="stack-lg">
    <?php if ($r['screenshot'] || $r['screenshot_mobile']):
        // Only link files that exist (after a host move or cleanup the column can outlive the file).
        $sd = \WB\Data\Runs::shotUrl($r['screenshot']);
        $sm = \WB\Data\Runs::shotUrl($r['screenshot_mobile']);
    ?>
      <section class="card card-pad stack">
        <h2 class="card-title"><?= ui_icon('image', 18) ?> <?= _e('admin.runs.screenshots') ?></h2>
        <?php if ($sd || $sm): ?>
        <div class="admin-shots">
          <?php if ($sd): ?><a href="<?= e($sd) ?>" target="_blank" rel="noopener"><img src="<?= e($sd) ?>" alt="<?= _e('admin.runs.shot_desktop') ?>" loading="lazy"></a><?php endif; ?>
          <?php if ($sm): ?><a class="is-mobile" href="<?= e($sm) ?>" target="_blank" rel="noopener"><img src="<?= e($sm) ?>" alt="<?= _e('admin.runs.shot_mobile') ?>" loading="lazy"></a><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if (($r['screenshot'] && !$sd) || ($r['screenshot_mobile'] && !$sm)): ?><p class="t-small t-muted"><?= ui_icon('alert', 14) ?> <?= _e('admin.runs.shot_missing') ?></p><?php endif; ?>
      </section>
    <?php endif; ?>

    <?php $checkRows = array_values(array_filter(is_array($static) && array_is_list($static) ? $static : [], static fn($c) => is_array($c) && ($c['type'] ?? '') !== 'answer')); ?>
    <?php if ($checkRows || $browserChecks || $tests || in_array($r['output_type'], ['html', 'js', 'text'], true)): ?>
    <section class="card card-pad stack">
      <h2 class="card-title"><?= ui_icon('check-circle', 18) ?> <?= _e('admin.runs.checks') ?></h2>
      <?php if (!$checkRows && !$browserChecks && !$tests): ?><p class="t-muted t-small"><?= _e('admin.runs.no_checks') ?></p><?php endif; ?>
      <?php foreach (['static' => $checkRows, 'browser' => $browserChecks] as $kind => $list): if (!$list || !array_is_list($list)) { continue; } ?>
        <h3 class="t-small admin-subhead"><?= _e('admin.runs.checks_' . $kind) ?></h3>
        <ul class="admin-checks">
          <?php foreach ($list as $ck): if (!is_array($ck)) { continue; } $pass = !empty($ck['pass']); ?>
            <li class="<?= $pass ? 'is-pass' : 'is-fail' ?>"><?= ui_icon($pass ? 'check-circle' : 'x-circle', 16) ?>
              <span><?= e((string) ($ck['label_' . lang()] ?? $ck['label_en'] ?? $ck['id'] ?? '?')) ?> <span class="t-mono t-xs t-muted" dir="ltr"><?= e((string) ($ck['type'] ?? '')) ?></span></span>
              <span class="t-xs t-muted"><?= e((string) ($ck['detail'] ?? '')) ?></span>
              <?php if (isset($ck['score'])): ?><span class="t-num t-xs"><?= e(number_format((float) $ck['score'], 2)) ?></span><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endforeach; ?>
      <?php if ($tests && is_array($tests)): ?>
        <h3 class="t-small admin-subhead"><?= _e('admin.runs.tests') ?></h3>
        <ul class="admin-checks">
          <?php foreach ($tests as $tc): if (!is_array($tc)) { continue; } $pass = !empty($tc['pass']); ?>
            <li class="<?= $pass ? 'is-pass' : 'is-fail' ?>"><?= ui_icon($pass ? 'check-circle' : 'x-circle', 16) ?><span class="t-mono t-xs" dir="ltr"><?= e((string) ($tc['name'] ?? '?')) ?></span>
              <span class="t-xs t-muted t-mono" dir="ltr"><?= e(isset($tc['error']) ? (string) $tc['error'] : (array_key_exists('got', $tc) ? 'got ' . mb_strimwidth(to_json($tc['got']), 0, 120, '…') : '')) ?></span>
              <?php if (isset($tc['ms'])): ?><span class="t-num t-xs"><bdi><?= e(fmt_ms($tc['ms'])) ?></bdi></span><?php endif; ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <?php if (!empty($browser['metrics'])): ?>
        <h3 class="t-small admin-subhead"><?= _e('admin.runs.metrics') ?></h3>
        <div class="cluster t-xs"><?php foreach ((array) $browser['metrics'] as $k => $v): ?><span class="pill t-mono" dir="ltr"><?= e((string) $k) ?>: <?= e(is_scalar($v) ? (string) $v : to_json($v)) ?></span><?php endforeach; ?></div>
      <?php endif; ?>
      <?php if (in_array($r['output_type'], ['html', 'js'], true)): ?><p class="t-xs t-muted"><?= (int) $r['verified'] ? _e('admin.runs.verified_by', ['v' => (string) $r['verified_by']]) : _e('admin.runs.not_verified') ?></p><?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($r['output_type'] === 'answer'): $ans = null; foreach ($static as $ck) { if (is_array($ck) && ($ck['type'] ?? '') === 'answer') { $ans = $ck; } } ?>
      <section class="card card-pad stack">
        <h2 class="card-title"><?= ui_icon('target', 18) ?> <?= _e('admin.runs.answer') ?></h2>
        <dl class="admin-dl">
          <dt><?= _e('admin.runs.answer_given') ?></dt><dd><code class="t-mono admin-break" dir="auto"><?= e(is_array($ans) && array_key_exists('got', $ans) ? (is_scalar($ans['got']) ? (string) $ans['got'] : to_json($ans['got'])) : '—') ?></code><?= is_array($ans) ? (!empty($ans['pass']) ? ui_badge(__('admin.runs.answer_ok'), 'green', 'check') : ui_badge(__('admin.runs.answer_bad'), 'red', 'x')) : '' ?></dd>
          <dt><?= _e('admin.runs.answer_expected') ?></dt><dd><code class="t-mono admin-break" dir="auto"><?php $exp = $answer_spec['value'] ?? ($answer_spec['values'] ?? ($answer_spec['pattern'] ?? null)); echo e($exp === null ? '—' : (is_scalar($exp) ? (string) $exp : to_json($exp))); ?></code><span class="t-xs t-muted"><?= e((string) ($answer_spec['type'] ?? 'exact')) ?><?= isset($answer_spec['tolerance']) && is_scalar($answer_spec['tolerance']) ? ' ± ' . e((string) $answer_spec['tolerance']) : '' ?></span></dd>
          <?php if (is_array($ans) && ($ans['detail'] ?? '') !== ''): ?><dt><?= _e('admin.runs.answer_detail') ?></dt><dd class="t-small t-muted" dir="auto"><?= e((string) $ans['detail']) ?></dd><?php endif; ?>
        </dl>
        <p class="t-xs t-muted"><?= ui_icon('lock', 12) ?> <?= _e('admin.runs.answer_private') ?></p>
      </section>
    <?php endif; ?>

    <?php if ($r['output_type'] === 'agent' && $transcript): $meta = $transcript['meta']; ?>
      <section class="card card-pad stack">
        <h2 class="card-title"><?= ui_icon('robot', 18) ?> <?= _e('admin.runs.episode') ?></h2>
        <div class="cluster t-xs">
          <?php if ($meta['steps'] !== null): ?><span class="pill"><?= _e('admin.runs.ep_steps', ['n' => (int) $meta['steps']]) ?></span><?php endif; ?>
          <?php if ($meta['turns'] !== null): ?><span class="pill"><?= _e('admin.runs.ep_turns', ['n' => (int) $meta['turns']]) ?></span><?php endif; ?>
          <?php if ($meta['end_reason'] !== ''): ?><span class="pill t-mono" dir="ltr"><?= e($meta['end_reason']) ?></span><?php endif; ?>
          <?php if ($meta['protocol'] !== ''): ?><span class="pill t-mono" dir="ltr"><?= e($meta['protocol']) ?></span><?php endif; ?>
        </div>
        <?php if (!empty($meta['capped'])): ?><div class="alert alert-warn t-small"><?= ui_icon('shield', 16) ?> <span><?= _e('admin.runs.critical_capped', ['cap' => fmt_num((float) setting('agent_critical_cap', 20)), 'raw' => fmt_score($meta['uncapped'])]) ?></span></div><?php endif; ?>
        <?php if ($meta['error'] !== ''): ?><div class="alert alert-warn t-small"><?= ui_icon('alert', 16) ?> <span dir="auto"><?= e($meta['error']) ?></span></div><?php endif; ?>
        <?php if ($goals): ?>
          <h3 class="t-small admin-subhead"><?= _e('admin.runs.goals') ?></h3>
          <ul class="admin-checks">
            <?php foreach ($goals as $g): ?>
              <li class="<?= $g['pass'] ? 'is-pass' : 'is-fail' ?>"><?= ui_icon($g['pass'] ? 'check-circle' : 'x-circle', 16) ?>
                <span><?= e($g['label']) ?> <span class="t-mono t-xs t-muted" dir="ltr"><?= e($g['type']) ?></span><?= $g['critical'] ? ' ' . ui_badge(__('admin.runs.critical'), $g['pass'] ? 'muted' : 'red', 'alert') : '' ?><?= $g['constraint'] ? ' ' . ui_badge(__('admin.runs.constraint'), 'muted', 'shield') : '' ?></span>
                <span class="t-xs t-muted" dir="auto"><?= e($g['detail']) ?></span>
                <span class="t-num t-xs">×<?= e(fmt_num($g['weight'], $g['weight'] == floor($g['weight']) ? 0 : 1)) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <?php if ($transcript['steps']): ?>
          <h3 class="t-small admin-subhead"><?= _e('admin.runs.ep_calls') ?></h3>
          <ol class="admin-episode">
            <?php foreach ($transcript['steps'] as $st): ?>
              <?php if ($st['kind'] === 'tool'): ?>
                <li class="<?= $st['error'] !== '' ? 'is-fail' : '' ?>">
                  <span class="t-mono t-xs admin-ep-n"><?= $st['step'] !== null ? (int) $st['step'] : '·' ?></span>
                  <span class="t-mono admin-ep-tool" dir="ltr"><?= e($st['tool']) ?></span>
                  <code class="t-mono t-xs admin-ep-args admin-break" dir="ltr"><?= e(mb_strimwidth(preg_replace('/\s+/', ' ', $st['args']) ?? '', 0, 220, '…')) ?></code>
                  <details class="admin-ep-result"><summary class="t-xs <?= $st['error'] !== '' ? 't-red' : 't-muted' ?>"><?= $st['error'] !== '' ? _e('admin.runs.ep_error') : _e('admin.runs.ep_result') ?></summary><pre class="code-block admin-pre t-xs" dir="ltr" tabindex="0"><?= e(mb_substr($st['error'] !== '' ? $st['error'] : $st['result'], 0, 20000)) ?></pre></details>
                </li>
              <?php else: ?>
                <li class="is-msg"><span class="t-mono t-xs admin-ep-n">›</span><span class="t-small t-muted admin-ep-text" dir="auto"><?= e(mb_strimwidth($st['text'], 0, 600, '…')) ?></span></li>
              <?php endif; ?>
            <?php endforeach; ?>
          </ol>
        <?php endif; ?>
        <?php if ($transcript['final'] !== null && $transcript['final'] !== ''): ?>
          <div><span class="t-xs t-muted"><?= _e('admin.runs.ep_final') ?></span><pre class="code-block admin-pre" dir="auto" tabindex="0"><?= e(mb_substr($transcript['final'], 0, 8000)) ?></pre></div>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <section class="card card-pad stack">
      <h2 class="card-title"><?= ui_icon('scale', 18) ?> <?= _e('admin.runs.judgments', ['n' => count($judgments)]) ?></h2>
      <?php if (!$judgments): ?><p class="t-muted t-small"><?= $rubric ? _e('admin.runs.no_judgments') : _e('admin.runs.no_rubric') ?></p><?php endif; ?>
      <?php foreach ($judgments as $j): $rat = json_arr($j['rationale']); $crit = json_arr($j['criteria_json']); ?>
        <article class="admin-judgment">
          <div class="admin-judgment-head">
            <strong><?= e($j['judge']) ?></strong>
            <span class="t-xs t-muted t-mono"><?= e($j['role']) ?></span>
            <?= ui_status((string) $j['status']) ?>
            <?= (int) $j['used_vision'] ? ui_badge(__('admin.runs.vision'), 'blue', 'eye') : '' ?>
            <span class="admin-judgment-score"><?= $j['score'] !== null ? ui_heat_cell($j['score']) : '—' ?></span>
          </div>
          <?php if ($crit): ?>
            <div class="admin-crit">
              <?php foreach ($crit as $k => $val): $label = (string) $k; $guide = ''; foreach ($rubric as $rc) { if (($rc['key'] ?? null) === $k) { $label = (string) ($rc['label_' . lang()] ?? $rc['label_en'] ?? $k); $guide = (string) (lang() === 'ar' && !empty($rc['guide_ar']) ? $rc['guide_ar'] : ($rc['guide'] ?? '')); } } ?>
                <span class="admin-crit-item"<?= $guide !== '' ? ' data-tip="' . e(mb_strimwidth($guide, 0, 300, '…')) . '"' : '' ?>><span class="t-xs"><?= e($label) ?></span><span class="t-num"><?= e(is_numeric($val) ? number_format((float) $val, 1) : (is_scalar($val) ? (string) $val : '?')) ?></span></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <?php if ($rat): ?>
            <?php if (!empty($rat['summary'])): ?><p class="t-small" dir="auto"><?= e((string) $rat['summary']) ?></p><?php endif; ?>
            <div class="admin-grid admin-grid-2 t-xs">
              <?php if (!empty($rat['strengths'])): ?><ul class="admin-pros"><?php foreach ((array) $rat['strengths'] as $s): ?><li dir="auto"><?= e(is_scalar($s) ? (string) $s : to_json($s)) ?></li><?php endforeach; ?></ul><?php endif; ?>
              <?php if (!empty($rat['weaknesses'])): ?><ul class="admin-cons"><?php foreach ((array) $rat['weaknesses'] as $s): ?><li dir="auto"><?= e(is_scalar($s) ? (string) $s : to_json($s)) ?></li><?php endforeach; ?></ul><?php endif; ?>
            </div>
          <?php elseif ($j['rationale']): ?>
            <p class="t-small" dir="auto"><?= e((string) $j['rationale']) ?></p>
          <?php endif; ?>
          <div class="t-xs t-muted"><?= e(fmt_money($j['cost_usd'])) ?> · <bdi><?= e(fmt_ms($j['latency_ms'])) ?></bdi><?= $j['error'] ? ' · <span class="t-red">' . e((string) $j['error']) . '</span>' : '' ?></div>
        </article>
      <?php endforeach; ?>
    </section>

    <section class="card card-pad stack">
      <div class="card-head"><h2 class="card-title"><?= ui_icon('code', 18) ?> <?= _e('admin.runs.code', ['n' => fmt_num($r['code_bytes'])]) ?></h2><?php if ($r['code'] !== null && $r['code'] !== ''): ?><button class="btn btn-sm btn-ghost" type="button" data-copy-from="#run-code"><?= ui_icon('copy', 14) ?><span><?= _e('common.copy') ?></span></button><?php endif; ?></div>
      <?php if ($r['code'] === null || $r['code'] === ''): ?><p class="t-muted t-small"><?= _e('admin.runs.no_code') ?></p><?php else: ?>
        <pre class="code-block admin-pre" id="run-code" dir="ltr" tabindex="0"><?= e(mb_strcut((string) $r['code'], 0, 400000)) ?></pre>
      <?php endif; ?>
    </section>
    <details class="card card-pad admin-details">
      <summary class="card-title"><?= ui_icon('file', 18) ?> <?= _e('admin.runs.raw') ?></summary>
      <pre class="code-block admin-pre" dir="ltr" tabindex="0"><?= e(mb_strcut((string) $r['raw_output'], 0, 400000)) ?></pre>
    </details>
    <?php if ($r['agent_json']): ?>
      <details class="card card-pad admin-details">
        <summary class="card-title"><?= ui_icon('robot', 18) ?> <?= _e('admin.runs.agent') ?></summary>
        <pre class="code-block admin-pre" dir="ltr" tabindex="0"><?= e(\WB\Admin\Admin::pretty($r['agent_json'])) ?></pre>
      </details>
    <?php endif; ?>
    <details class="card card-pad admin-details">
      <summary class="card-title"><?= ui_icon('database', 18) ?> <?= _e('admin.runs.json') ?></summary>
      <h3 class="t-small admin-subhead">static_json</h3>
      <pre class="code-block admin-pre" dir="ltr" tabindex="0"><?= e(\WB\Admin\Admin::pretty($r['static_json'] ?? 'null')) ?></pre>
      <h3 class="t-small admin-subhead">browser_json</h3>
      <pre class="code-block admin-pre" dir="ltr" tabindex="0"><?= e(\WB\Admin\Admin::pretty($r['browser_json'] ?? 'null')) ?></pre>
    </details>
  </div>
  <aside class="admin-aside stack-lg">
    <?php if ($notes): ?>
      <section class="card card-pad stack-sm">
        <h2 class="card-title"><?= ui_icon('info', 18) ?> <?= _e('admin.runs.notes') ?></h2>
        <ul class="admin-notes">
          <?php foreach ($notes as $n): ?><li><span><?= e($n['text']) ?></span><code class="admin-note-code t-mono t-xs t-muted" dir="ltr"><?= e($n['code']) ?></code></li><?php endforeach; ?>
        </ul>
      </section>
    <?php endif; ?>
    <section class="card card-pad stack-sm">
      <h2 class="card-title"><?= ui_icon('info', 18) ?> <?= _e('admin.runs.details') ?></h2>
      <dl class="admin-dl">
        <dt><?= _e('common.model') ?></dt><dd><a href="<?= e(url('/admin/models/' . $r['model_id'])) ?>"><?= e($r['model']) ?></a><div class="t-mono t-xs t-muted" dir="ltr"><?= e($r['or_id']) ?></div></dd>
        <dt><?= _e('admin.runs.task') ?></dt><dd><a href="<?= e(url('/admin/tasks/' . $r['task_id'])) ?>"><?= e($r['task_slug']) ?></a> <span class="pill t-mono"><?= e($r['output_type']) ?></span><?= $public ? '' : ' ' . ui_badge(__('admin.tasks.holdout'), 'violet', 'lock') ?></dd>
        <dt><?= _e('admin.runs.campaign') ?></dt><dd><a href="<?= e(url('/admin/campaigns/' . $r['campaign_id'])) ?>"><?= e($r['campaign']) ?></a></dd>
        <dt><?= _e('admin.runs.tries') ?></dt><dd class="t-num"><?= (int) $r['tries'] ?> / <?= (int) $r['verify_tries'] ?></dd>
        <dt><?= _e('common.tokens') ?></dt><dd class="t-num t-xs"><?= _e('admin.runs.tokens_detail', ['p' => fmt_num($r['prompt_tokens']), 'c' => fmt_num($r['completion_tokens']), 'r' => fmt_num($r['reasoning_tokens'])]) ?></dd>
        <dt><?= _e('admin.runs.finish') ?></dt><dd class="t-mono t-xs"><?= e((string) ($r['finish_reason'] ?? '—')) ?></dd>
        <dt><?= _e('admin.runs.gen_id') ?></dt><dd class="t-mono t-xs admin-break" dir="ltr"><?= e((string) ($r['gen_id'] ?? '—')) ?></dd>
        <dt><?= _e('admin.runs.lock') ?></dt><dd class="t-xs"><?= $r['locked_until'] ? e(fmt_date($r['locked_until'], 'datetime')) : '—' ?></dd>
        <dt><?= _e('admin.campaigns.created') ?></dt><dd class="t-xs"><?= e(fmt_date($r['created_at'], 'datetime')) ?></dd>
        <dt><?= _e('admin.runs.finished') ?></dt><dd class="t-xs"><?= e(fmt_date($r['finished_at'], 'datetime')) ?></dd>
      </dl>
    </section>
    <?php if (count($siblings) > 1): ?>
      <section class="card card-pad stack-sm">
        <h2 class="card-title"><?= ui_icon('layers', 18) ?> <?= _e('admin.runs.attempts') ?></h2>
        <ul class="admin-list">
          <?php foreach ($siblings as $s): ?><li class="<?= (int) $s['id'] === (int) $r['id'] ? 'is-current' : '' ?>"><a href="<?= e(url('/admin/runs/' . $s['id'])) ?>"><?= _e('admin.runs.attempt_n', ['n' => (int) $s['attempt']]) ?></a> <?= ui_status($s['status']) ?> <span class="t-num"><?= e(fmt_score($s['final_score'])) ?></span></li><?php endforeach; ?>
        </ul>
      </section>
    <?php endif; ?>
  </aside>
</div>
