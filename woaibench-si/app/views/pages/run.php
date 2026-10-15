<?php
/**
 * Public run page (public tasks only). Vars: run, tab, attempts, judgments, checks, answer, transcript, shot, shot_mobile.
 * Every piece of model output is escaped; the rendered output lives in a sandboxed iframe (/render/{id}).
 */
defined('WB') || exit;

$r = $run;
$id = (int) $r['id'];
$type = (string) $r['output_type'];
$renderable = in_array($type, ['html', 'js'], true) && trim((string) $r['code']) !== '';
$hasCode = trim((string) $r['code']) !== '';
$track = ['slug' => $r['track_slug'], 'name_ar' => $r['name_ar'], 'name_en' => $r['name_en'], 'icon' => $r['icon'], 'color' => $r['color']];
$model = ['slug' => $r['model_slug'], 'name' => $r['model_name'], 'provider' => $r['provider'], 'provider_name' => $r['provider_name']];

$tabs = [];
if ($renderable) { $tabs['preview'] = ['pub.run.tab_preview', 'monitor']; }
if ($shot || $shot_mobile) { $tabs['shots'] = ['pub.run.tab_shots', 'image']; }
if ($type === 'answer') { $tabs['answer'] = ['pub.run.tab_answer', 'target']; }
if ($type === 'agent') { $tabs['transcript'] = ['pub.run.tab_transcript', 'robot']; }
if ($type === 'text' || (!$hasCode && $type !== 'answer' && $type !== 'agent')) { $tabs['output'] = ['pub.run.tab_output', 'file']; }
if ($hasCode) { $tabs['code'] = ['pub.run.tab_code', 'code']; }
$tabs['checks'] = ['pub.run.tab_checks', 'check'];
if ($judgments) { $tabs['judges'] = ['pub.run.tab_judges', 'scale']; }
$active = isset($tabs[$tab]) ? $tab : array_key_first($tabs);

$a = WB\Data\Tasks::autoWeight(['rubric_json' => $r['rubric_json'], 'auto_weight' => $r['task_auto_weight'], 'track_auto_weight' => $r['track_auto_weight']]);
$expected = null;
if ($r['auto_score'] !== null && $r['judge_score'] !== null && $a < 1) {
    $expected = $a * (float) $r['auto_score'] + (1 - $a) * (float) $r['judge_score'];
} elseif ($r['auto_score'] !== null && ($a >= 1 || $r['judge_score'] === null)) {
    $expected = (float) $r['auto_score'];
}
$penalised = $expected !== null && $r['final_score'] !== null && (float) $r['final_score'] < $expected - 0.6;
$attemptNo = 0;
$sameCampaign = array_values(array_filter($attempts, static fn($x) => (int) $x['campaign_id'] === (int) $r['campaign_id']));
foreach ($sameCampaign as $k => $x) {
    if ((int) $x['id'] === $id) {
        $attemptNo = $k + 1;
    }
}
$attemptTotal = max(count($sameCampaign), (int) $r['runs_per_task']);
$code = (string) $r['code'];
$codeLimit = 1500000;
$codeShown = strlen($code) > $codeLimit ? mb_strcut($code, 0, $codeLimit) : $code;
$ext = $type === 'html' ? 'html' : ($type === 'js' ? 'js' : 'txt');
?>
<section class="section pub-hero pub-run-hero" style="--track:<?= e($r['color']) ?>">
  <div class="container-wide stack">
    <nav class="breadcrumbs" aria-label="<?= _e('pub.breadcrumbs') ?>">
      <a href="<?= e(url('/tasks')) ?>"><?= _e('nav.tasks') ?></a>
      <a href="<?= e(url('/task/' . $r['task_slug'])) ?>"><?= e(L($r, 'title')) ?></a>
      <span aria-current="page"><?= _e('pub.run.crumb', ['id' => $id]) ?></span>
    </nav>
    <div class="pub-run-head">
      <div class="stack-sm">
        <div class="cluster pub-badges">
          <?= ui_track_badge($track, true) ?>
          <?= ui_badge(__('pub.type.' . $type), 'muted', pub_type_icon($type)) ?>
          <?= ui_badge(__('pub.run.attempt', ['n' => $attemptNo ?: (int) $r['attempt'], 'total' => $attemptTotal]), 'muted', 'dice') ?>
          <?php if ((int) $r['contested']): ?><?= ui_badge(__('pub.run.contested'), 'gold', 'alert', __('pub.run.contested_tip', ['n' => setting('judge_tiebreak_spread')])) ?><?php endif; ?>
          <?php if ((int) $r['verified']): ?><?= ui_badge(__('pub.run.verified'), 'green', 'check-circle', __('pub.run.verified_tip', ['by' => $r['verified_by'] ?: '—'])) ?><?php elseif ($renderable): ?><?= ui_badge(__('pub.run.unverified'), 'muted', 'eye-off', __('pub.run.unverified_tip')) ?><?php endif; ?>
          <?php if (!(int) $r['include_in_scores']): ?><?= ui_badge(__('pub.run.excluded'), 'red', 'info', __('pub.run.excluded_tip')) ?><?php endif; ?>
        </div>
        <h1 class="t-h2 pub-run-title"><?= ui_provider_avatar((string) $r['provider'], $r['provider_name'], 40) ?><span><a href="<?= e(url('/model/' . $r['model_slug'])) ?>"><?= e($r['model_name']) ?></a> <span class="t-muted pub-run-on"><?= _e('pub.run.on') ?></span> <a href="<?= e(url('/task/' . $r['task_slug'])) ?>"><?= e(L($r, 'title')) ?></a></span></h1>
      </div>
      <div class="pub-run-score">
        <span class="stat-label"><?= _e('pub.run.final') ?></span>
        <?= ui_score($r['final_score'], null, null, 'xl') ?>
        <?= ui_scorebar($r['final_score']) ?>
      </div>
    </div>
  </div>
</section>

<section class="section pub-section-tight">
  <div class="container-wide pub-run-layout">
    <div class="pub-run-main stack" data-run-tabs>
      <div class="tabs pub-tabs" role="tablist" aria-label="<?= _e('pub.run.tabs') ?>">
        <?php foreach ($tabs as $key => [$label, $icon]): ?>
          <a class="tab<?= $key === $active ? ' active' : '' ?>" id="tab-<?= e($key) ?>" role="tab" href="<?= e(url('/run/' . $id, ['tab' => $key])) ?>" aria-controls="panel-<?= e($key) ?>" aria-selected="<?= $key === $active ? 'true' : 'false' ?>" tabindex="<?= $key === $active ? '0' : '-1' ?>" data-run-tab="<?= e($key) ?>"><?= ui_icon($icon, 15) ?><span><?= _e($label) ?></span><?php if ($key === 'judges'): ?><span class="pub-tab-n t-num"><?= count($judgments) ?></span><?php endif; ?></a>
        <?php endforeach; ?>
      </div>

      <?php if (isset($tabs['preview'])): ?>
      <div class="pub-panel" id="panel-preview" role="tabpanel" aria-labelledby="tab-preview" data-run-panel="preview"<?= $active !== 'preview' ? ' hidden' : '' ?>>
        <div class="render-frame pub-render" data-run-frame data-device="desktop">
          <div class="render-toolbar">
            <div class="device-toggle" role="group" aria-label="<?= _e('pub.run.viewport') ?>">
              <button type="button" data-device="desktop" aria-pressed="true" data-tip="<?= _e('pub.run.desktop') ?>"><?= ui_icon('monitor', 16) ?><span class="sr-only"><?= _e('pub.run.desktop') ?></span></button>
              <button type="button" data-device="tablet" aria-pressed="false" data-tip="<?= _e('pub.run.tablet') ?>"><?= ui_icon('tablet', 16) ?><span class="sr-only"><?= _e('pub.run.tablet') ?></span></button>
              <button type="button" data-device="mobile" aria-pressed="false" data-tip="<?= _e('pub.run.mobile') ?>"><?= ui_icon('phone', 16) ?><span class="sr-only"><?= _e('pub.run.mobile') ?></span></button>
            </div>
            <span class="t-xs t-mono t-muted pub-render-size" dir="ltr" data-run-size></span>
            <div class="render-actions">
              <button type="button" class="btn btn-icon btn-ghost btn-sm" data-run-reload aria-label="<?= _e('pub.run.reload') ?>" data-tip="<?= _e('pub.run.reload') ?>"><?= ui_icon('refresh', 16) ?></button>
              <a class="btn btn-icon btn-ghost btn-sm" href="<?= e(url('/render/' . $id)) ?>" target="_blank" rel="noopener noreferrer" aria-label="<?= _e('pub.run.new_tab') ?>" data-tip="<?= _e('pub.run.new_tab') ?>"><?= ui_icon('external', 16) ?></a>
              <button type="button" class="btn btn-icon btn-ghost btn-sm" data-run-fullscreen aria-label="<?= _e('pub.run.fullscreen') ?>" data-tip="<?= _e('pub.run.fullscreen') ?>"><?= ui_icon('maximize', 16) ?></button>
            </div>
          </div>
          <div class="render-stage">
            <iframe class="pub-render-iframe" sandbox="allow-scripts allow-pointer-lock" src="<?= e(url('/render/' . $id)) ?>" title="<?= _e('pub.run.iframe_title', ['model' => $r['model_name'], 'task' => L($r, 'title')]) ?>" loading="lazy" referrerpolicy="no-referrer" data-run-iframe></iframe>
          </div>
        </div>
        <p class="t-xs t-muted pub-sandbox-note"><?= ui_icon('shield', 12) ?> <?= _e('pub.run.sandbox_note') ?></p>
      </div>
      <?php endif; ?>

      <?php if (isset($tabs['shots'])): ?>
      <div class="pub-panel" id="panel-shots" role="tabpanel" aria-labelledby="tab-shots" data-run-panel="shots"<?= $active !== 'shots' ? ' hidden' : '' ?>>
        <div class="pub-shots">
          <?php if ($shot): ?><figure class="card pub-shot-fig"><img src="<?= e($shot) ?>" alt="<?= _e('pub.run.shot_desktop_alt', ['model' => $r['model_name']]) ?>" loading="lazy" decoding="async"><figcaption class="t-xs t-muted"><?= ui_icon('monitor', 12) ?> <?= _e('pub.run.desktop') ?></figcaption></figure><?php endif; ?>
          <?php if ($shot_mobile): ?><figure class="card pub-shot-fig is-mobile"><img src="<?= e($shot_mobile) ?>" alt="<?= _e('pub.run.shot_mobile_alt', ['model' => $r['model_name']]) ?>" loading="lazy" decoding="async"><figcaption class="t-xs t-muted"><?= ui_icon('phone', 12) ?> <?= _e('pub.run.mobile') ?></figcaption></figure><?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if (isset($tabs['answer'])): ?>
      <div class="pub-panel" id="panel-answer" role="tabpanel" aria-labelledby="tab-answer" data-run-panel="answer"<?= $active !== 'answer' ? ' hidden' : '' ?>>
        <?= partial('partials/public-run-answer', ['answer' => $answer, 'raw' => (string) $r['raw_output']]) ?>
      </div>
      <?php endif; ?>

      <?php if (isset($tabs['transcript'])): ?>
      <div class="pub-panel" id="panel-transcript" role="tabpanel" aria-labelledby="tab-transcript" data-run-panel="transcript"<?= $active !== 'transcript' ? ' hidden' : '' ?>>
        <?= partial('partials/public-run-transcript', ['tx' => $transcript]) ?>
      </div>
      <?php endif; ?>

      <?php if (isset($tabs['output'])): ?>
      <div class="pub-panel" id="panel-output" role="tabpanel" aria-labelledby="tab-output" data-run-panel="output"<?= $active !== 'output' ? ' hidden' : '' ?>>
        <div class="card card-pad stack-sm">
          <h2 class="card-title"><?= _e('pub.run.raw_output') ?></h2>
          <?php if (trim((string) $r['raw_output']) === ''): ?>
            <p class="t-muted"><?= _e('pub.run.no_output') ?></p>
          <?php else: ?>
            <pre class="code-block pub-raw" dir="auto" tabindex="0"><?= e(mb_strcut((string) $r['raw_output'], 0, $codeLimit)) ?></pre>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if (isset($tabs['code'])): ?>
      <div class="pub-panel" id="panel-code" role="tabpanel" aria-labelledby="tab-code" data-run-panel="code"<?= $active !== 'code' ? ' hidden' : '' ?>>
        <div class="card pub-code-card">
          <div class="pub-code-bar">
            <span class="t-xs t-mono t-muted"><bdi dir="ltr"><?= e('run-' . $id . '.' . $ext) ?> · <?= e(fmt_num(strlen($code) / 1024, 1)) ?> KB</bdi> · <?= _e('pub.run.lines', ['n' => fmt_num(substr_count($code, "\n") + 1)]) ?></span>
            <div class="cluster">
              <button type="button" class="btn btn-ghost btn-sm" data-run-copy><?= ui_icon('copy', 15) ?><span><?= _e('common.copy') ?></span></button>
              <button type="button" class="btn btn-ghost btn-sm" data-run-download data-filename="<?= e('woaibench-run-' . $id . '.' . $ext) ?>" data-mime="<?= $ext === 'html' ? 'text/html' : ($ext === 'js' ? 'text/javascript' : 'text/plain') ?>"><?= ui_icon('download', 15) ?><span><?= _e('common.download') ?></span></button>
            </div>
          </div>
          <pre class="code-block pub-code" tabindex="0" dir="ltr"><code data-run-code><?php foreach (explode("\n", $codeShown) as $line): ?><span class="ln"><?= e($line) ?></span>
<?php endforeach; ?></code></pre>
          <?php if ($codeShown !== $code): ?><p class="t-xs t-muted pub-pad"><?= _e('pub.run.truncated') ?></p><?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <div class="pub-panel" id="panel-checks" role="tabpanel" aria-labelledby="tab-checks" data-run-panel="checks"<?= $active !== 'checks' ? ' hidden' : '' ?>>
        <?= partial('partials/public-run-checks', ['checks' => $checks, 'run' => $r, 'renderable' => $renderable]) ?>
      </div>

      <?php if (isset($tabs['judges'])): ?>
      <div class="pub-panel" id="panel-judges" role="tabpanel" aria-labelledby="tab-judges" data-run-panel="judges"<?= $active !== 'judges' ? ' hidden' : '' ?>>
        <?= partial('partials/public-run-judges', ['judgments' => $judgments, 'run' => $r]) ?>
      </div>
      <?php endif; ?>
    </div>

    <aside class="pub-run-aside stack">
      <div class="card card-pad stack-sm">
        <h2 class="card-title"><?= ui_icon('sigma', 18) ?> <?= _e('pub.run.how_scored') ?></h2>
        <dl class="pub-dl">
          <div><dt><?= _e('pub.split_auto') ?></dt><dd class="t-num"><?= e(fmt_score($r['auto_score'])) ?></dd></div>
          <?php if ($a < 1): ?><div><dt><?= _e('pub.run.judge_median') ?></dt><dd class="t-num"><?= e(fmt_score($r['judge_score'])) ?></dd></div><?php endif; ?>
          <div><dt><?= _e('pub.run.auto_weight') ?></dt><dd class="t-num"><?= e(number_format($a, 2)) ?></dd></div>
        </dl>
        <?php if ($expected !== null && $a < 1 && $r['judge_score'] !== null): ?>
          <p class="pub-formula-line t-mono t-xs" dir="ltr"><span><?= e(number_format($a, 2)) ?> × <?= e(fmt_score($r['auto_score'])) ?> + <?= e(number_format(1 - $a, 2)) ?> × <?= e(fmt_score($r['judge_score'])) ?></span><b>= <?= e(fmt_score($expected)) ?></b></p>
        <?php endif; ?>
        <?php if ($penalised): ?><p class="alert alert-warn t-xs"><?= _e('pub.run.render_penalty', ['f' => setting('render_fail_factor')]) ?></p><?php endif; ?>
      </div>

      <div class="card card-pad stack-sm">
        <h2 class="card-title"><?= ui_icon('gauge', 18) ?> <?= _e('pub.run.meta') ?></h2>
        <dl class="pub-dl">
          <div><dt><?= _e('pub.run.prompt_tokens') ?></dt><dd class="t-num"><?= e(fmt_num($r['prompt_tokens'])) ?></dd></div>
          <div><dt><?= _e('pub.run.completion_tokens') ?></dt><dd class="t-num"><?= e(fmt_num($r['completion_tokens'])) ?></dd></div>
          <div><dt><?= _e('pub.run.reasoning_tokens') ?></dt><dd class="t-num"><?= e(fmt_num($r['reasoning_tokens'])) ?></dd></div>
          <div><dt><?= _e('common.cost') ?></dt><dd class="t-num"><span dir="ltr"><?= e(fmt_money($r['cost_usd'])) ?></span></dd></div>
          <div><dt><?= _e('common.latency') ?></dt><dd class="t-num"><span dir="ltr"><?= e(fmt_ms($r['latency_ms'])) ?></span></dd></div>
          <div><dt><?= _e('pub.run.finish') ?></dt><dd class="t-mono t-xs"><?= e($r['finish_reason'] ?: '—') ?></dd></div>
          <div><dt><?= _e('pub.run.provider') ?></dt><dd class="t-xs"><?= e($r['provider_used'] ?: '—') ?></dd></div>
          <div><dt><?= _e('pub.run.code_size') ?></dt><dd class="t-num"><span dir="ltr"><?= $r['code_bytes'] !== null ? e(fmt_num((int) $r['code_bytes'] / 1024, 1)) . ' KB' : '—' ?></span></dd></div>
          <div><dt><?= _e('pub.run.finished') ?></dt><dd class="t-xs"><?= e(fmt_date($r['finished_at'], 'datetime')) ?></dd></div>
          <div><dt><?= _e('pub.run.campaign') ?></dt><dd class="t-xs"><?= e($r['campaign_name']) ?> <span class="t-muted">(<?= e($r['campaign_season']) ?>)</span></dd></div>
        </dl>
      </div>

      <?php if (count($attempts) > 1): ?>
      <div class="card card-pad stack-sm">
        <h2 class="card-title"><?= ui_icon('dice', 18) ?> <?= _e('pub.run.attempts') ?></h2>
        <ul class="pub-attempts">
          <?php foreach ($attempts as $x): ?>
            <li class="<?= (int) $x['id'] === $id ? 'is-current' : '' ?>">
              <a href="<?= e(url('/run/' . (int) $x['id'])) ?>"<?= (int) $x['id'] === $id ? ' aria-current="page"' : '' ?>><span><?= _e('pub.run.attempt_short', ['n' => (int) $x['attempt']]) ?><?php if ((int) $x['campaign_id'] !== (int) $r['campaign_id']): ?> <span class="t-xs t-muted"><?= _e('pub.run.other_campaign') ?></span><?php endif; ?></span><?= ui_heat_cell($x['final_score']) ?></a>
            </li>
          <?php endforeach; ?>
        </ul>
        <p class="t-xs t-muted"><?= _e('pub.run.attempts_note', ['sd' => fmt_score(stddev(array_map(static fn($x) => (float) $x['final_score'], $sameCampaign ?: $attempts)))]) ?></p>
      </div>
      <?php endif; ?>

      <?php if ((int) $r['contested']): ?>
      <div class="card card-pad stack-sm pub-contested">
        <h2 class="card-title"><?= ui_icon('alert', 18) ?> <?= _e('pub.run.contested') ?></h2>
        <p class="t-small"><?= _e('pub.run.contested_text', ['spread' => fmt_score($r['judge_spread']), 'n' => setting('judge_tiebreak_spread')]) ?></p>
      </div>
      <?php endif; ?>

      <div class="cluster">
        <a class="btn btn-outline btn-sm" href="<?= e(url('/model/' . $r['model_slug'])) ?>"><?= ui_icon('user', 15) ?><span><?= _e('pub.run.model_page') ?></span></a>
        <a class="btn btn-outline btn-sm" href="<?= e(url('/task/' . $r['task_slug'])) ?>"><?= ui_icon('list', 15) ?><span><?= _e('pub.run.task_page') ?></span></a>
      </div>
    </aside>
  </div>
</section>
<?= pub_js_strings() ?>
