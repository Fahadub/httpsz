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
echo partial('admin/_head', [
    'back' => url('/admin/runs', ['campaign' => $r['campaign_id']]),
    'eyebrow' => $r['campaign'] . ' · ' . __('admin.runs.attempt_n', ['n' => (int) $r['attempt']]),
    'title' => __('admin.runs.run_n', ['n' => $r['id']]) . ' — ' . $r['model'],
    'sub' => L($r, 'title'),
    'actions' => '<div class="cluster">'
        . ($public ? '<a class="btn btn-sm btn-ghost" href="' . e(url('/run/' . $r['id'])) . '" target="_blank" rel="noopener">' . ui_icon('external', 14) . '<span>' . _e('admin.runs.public') . '</span></a>' : '')
        . '<a class="btn btn-sm btn-ghost" href="' . e(url('/render/' . $r['id'])) . '" target="_blank" rel="noopener">' . ui_icon('monitor', 14) . '<span>' . _e('admin.runs.render') . '</span></a>'
        . $act('retry', 'refresh', __('admin.runs.retry'), 'btn-outline', __('admin.runs.retry_confirm'))
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

<?php if ($r['error']): ?><div class="alert alert-error"><?= ui_icon('alert', 16) ?> <span class="t-mono t-small" dir="ltr"><?= e((string) $r['error']) ?></span></div><?php endif; ?>

<div class="admin-split">
  <div class="stack-lg">
    <?php if ($r['screenshot'] || $r['screenshot_mobile']): ?>
      <section class="card card-pad stack">
        <h2 class="card-title"><?= ui_icon('image', 18) ?> <?= _e('admin.runs.screenshots') ?></h2>
        <div class="admin-shots">
          <?php if ($r['screenshot']): ?><a href="<?= e(upload_url($r['screenshot'])) ?>" target="_blank" rel="noopener"><img src="<?= e(upload_url($r['screenshot'])) ?>" alt="<?= _e('admin.runs.shot_desktop') ?>" loading="lazy"></a><?php endif; ?>
          <?php if ($r['screenshot_mobile']): ?><a class="is-mobile" href="<?= e(upload_url($r['screenshot_mobile'])) ?>" target="_blank" rel="noopener"><img src="<?= e(upload_url($r['screenshot_mobile'])) ?>" alt="<?= _e('admin.runs.shot_mobile') ?>" loading="lazy"></a><?php endif; ?>
        </div>
      </section>
    <?php endif; ?>

    <section class="card card-pad stack">
      <h2 class="card-title"><?= ui_icon('check-circle', 18) ?> <?= _e('admin.runs.checks') ?></h2>
      <?php if (!$static && !$browserChecks && !$tests): ?><p class="t-muted t-small"><?= _e('admin.runs.no_checks') ?></p><?php endif; ?>
      <?php foreach (['static' => $static, 'browser' => $browserChecks] as $kind => $list): if (!$list || !array_is_list($list)) { continue; } ?>
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
              <?php if (isset($tc['ms'])): ?><span class="t-num t-xs"><?= e(fmt_ms($tc['ms'])) ?></span><?php endif; ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <?php if (!empty($browser['metrics'])): ?>
        <h3 class="t-small admin-subhead"><?= _e('admin.runs.metrics') ?></h3>
        <div class="cluster t-xs"><?php foreach ((array) $browser['metrics'] as $k => $v): ?><span class="pill t-mono" dir="ltr"><?= e((string) $k) ?>: <?= e(is_scalar($v) ? (string) $v : to_json($v)) ?></span><?php endforeach; ?></div>
      <?php endif; ?>
      <p class="t-xs t-muted"><?= (int) $r['verified'] ? _e('admin.runs.verified_by', ['v' => (string) $r['verified_by']]) : _e('admin.runs.not_verified') ?></p>
    </section>

    <section class="card card-pad stack">
      <h2 class="card-title"><?= ui_icon('scale', 18) ?> <?= _e('admin.runs.judgments', ['n' => count($judgments)]) ?></h2>
      <?php if (!$judgments): ?><p class="t-muted t-small"><?= _e('admin.runs.no_judgments') ?></p><?php endif; ?>
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
              <?php foreach ($crit as $k => $val): $label = (string) $k; foreach ($rubric as $rc) { if (($rc['key'] ?? null) === $k) { $label = (string) ($rc['label_' . lang()] ?? $rc['label_en'] ?? $k); } } ?>
                <span class="admin-crit-item"><span class="t-xs"><?= e($label) ?></span><span class="t-num"><?= e(is_numeric($val) ? number_format((float) $val, 1) : (is_scalar($val) ? (string) $val : '?')) ?></span></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <?php if ($rat): ?>
            <?php if (!empty($rat['summary'])): ?><p class="t-small"><?= e((string) $rat['summary']) ?></p><?php endif; ?>
            <div class="admin-grid admin-grid-2 t-xs">
              <?php if (!empty($rat['strengths'])): ?><ul class="admin-pros"><?php foreach ((array) $rat['strengths'] as $s): ?><li><?= e(is_scalar($s) ? (string) $s : to_json($s)) ?></li><?php endforeach; ?></ul><?php endif; ?>
              <?php if (!empty($rat['weaknesses'])): ?><ul class="admin-cons"><?php foreach ((array) $rat['weaknesses'] as $s): ?><li><?= e(is_scalar($s) ? (string) $s : to_json($s)) ?></li><?php endforeach; ?></ul><?php endif; ?>
            </div>
          <?php elseif ($j['rationale']): ?>
            <p class="t-small"><?= e((string) $j['rationale']) ?></p>
          <?php endif; ?>
          <div class="t-xs t-muted"><?= e(fmt_money($j['cost_usd'])) ?> · <?= e(fmt_ms($j['latency_ms'])) ?><?= $j['error'] ? ' · <span class="t-red">' . e((string) $j['error']) . '</span>' : '' ?></div>
        </article>
      <?php endforeach; ?>
    </section>

    <section class="card card-pad stack">
      <div class="card-head"><h2 class="card-title"><?= ui_icon('code', 18) ?> <?= _e('admin.runs.code', ['n' => fmt_num($r['code_bytes'])]) ?></h2><?php if ($r['code'] !== null && $r['code'] !== ''): ?><button class="btn btn-sm btn-ghost" type="button" data-copy-from="#run-code"><?= ui_icon('copy', 14) ?><span><?= _e('common.copy') ?></span></button><?php endif; ?></div>
      <?php if ($r['code'] === null || $r['code'] === ''): ?><p class="t-muted t-small"><?= _e('admin.runs.no_code') ?></p><?php else: ?>
        <pre class="code-block admin-pre" id="run-code" dir="ltr"><?= e(mb_strcut((string) $r['code'], 0, 400000)) ?></pre>
      <?php endif; ?>
    </section>
    <details class="card card-pad admin-details">
      <summary class="card-title"><?= ui_icon('file', 18) ?> <?= _e('admin.runs.raw') ?></summary>
      <pre class="code-block admin-pre" dir="ltr"><?= e(mb_strcut((string) $r['raw_output'], 0, 400000)) ?></pre>
    </details>
    <?php if ($r['agent_json']): ?>
      <details class="card card-pad admin-details">
        <summary class="card-title"><?= ui_icon('robot', 18) ?> <?= _e('admin.runs.agent') ?></summary>
        <pre class="code-block admin-pre" dir="ltr"><?= e(\WB\Admin\Admin::pretty($r['agent_json'])) ?></pre>
      </details>
    <?php endif; ?>
    <details class="card card-pad admin-details">
      <summary class="card-title"><?= ui_icon('database', 18) ?> <?= _e('admin.runs.json') ?></summary>
      <h3 class="t-small admin-subhead">static_json</h3>
      <pre class="code-block admin-pre" dir="ltr"><?= e(\WB\Admin\Admin::pretty($r['static_json'] ?? 'null')) ?></pre>
      <h3 class="t-small admin-subhead">browser_json</h3>
      <pre class="code-block admin-pre" dir="ltr"><?= e(\WB\Admin\Admin::pretty($r['browser_json'] ?? 'null')) ?></pre>
    </details>
  </div>
  <aside class="admin-aside stack-lg">
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
