<?php
/**
 * Admin Verification Station (module: verifier). Driven by assets/js/verifier.js.
 * Vars: counts{awaiting_verify, verifying}, verified_24h, recent[], verify_mode, token_count.
 */
defined('WB') || exit;

$counts = $counts ?? ['awaiting_verify' => 0, 'verifying' => 0];
$recent = $recent ?? [];
$verifyMode = (string) ($verify_mode ?? 'required');
$modeVariant = ['required' => 'green', 'optional' => 'gold', 'off' => 'red'][$verifyMode] ?? 'muted';
$cmd = 'node verify.mjs --site ' . site_origin() . base_path() . ' --token <TOKEN> --concurrency 2';
$headFile = WB_APP . '/views/admin/_head.php';
$headVars = [
    'eyebrow' => __('verifier.station.eyebrow'),
    'title' => __('verifier.station.title'),
    'sub' => __('verifier.station.sub'),
];
?>
<?php if (is_file($headFile)): ?>
  <?= partial('admin/_head', $headVars) ?>
<?php else: ?>
  <header class="section-head"><span class="eyebrow"><?= e($headVars['eyebrow']) ?></span><h1 class="t-h1"><?= e($headVars['title']) ?></h1><p class="section-sub"><?= e($headVars['sub']) ?></p></header>
<?php endif; ?>

<div class="vs-page stack-lg" data-verifier-station data-state="idle">
  <section class="vs-console card card-glass" aria-label="<?= _e('verifier.station.controls') ?>">
    <div class="vs-console-status">
      <span class="vs-orb" aria-hidden="true"><span></span></span>
      <div class="vs-console-text">
        <span class="vs-console-state" data-vs-status aria-live="polite"
              data-label-idle="<?= _e('verifier.station.state_idle') ?>"
              data-label-running="<?= _e('verifier.station.state_running') ?>"
              data-label-stopping="<?= _e('verifier.station.state_stopping') ?>"><?= _e('verifier.station.state_idle') ?></span>
        <span class="vs-console-meta t-xs t-muted">
          <?= _e('verifier.station.mode') ?>
          <?= ui_badge(__('verifier.station.mode_' . (in_array($verifyMode, ['required', 'optional', 'off'], true) ? $verifyMode : 'required')), $modeVariant) ?>
          <span class="vs-dot-sep" aria-hidden="true">·</span>
          <?= _e('verifier.station.active') ?> <strong class="t-num" data-vs-stat="active">0</strong>
        </span>
      </div>
    </div>
    <div class="vs-console-actions">
      <label class="vs-conc">
        <span class="label"><?= _e('verifier.station.concurrency') ?></span>
        <select class="select" data-vs-concurrency>
          <?php foreach ([1, 2, 3, 4] as $n): ?>
            <option value="<?= $n ?>"<?= $n === 2 ? ' selected' : '' ?>><?= $n ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="button" class="btn btn-primary" data-vs-start><?= ui_icon('play', 16) ?><span><?= _e('verifier.station.start') ?></span></button>
      <button type="button" class="btn btn-outline" data-vs-stop disabled><?= ui_icon('pause', 16) ?><span><?= _e('verifier.station.stop') ?></span></button>
    </div>
  </section>

  <?php if ($verifyMode === 'off'): ?>
    <div class="alert alert-warn" role="status"><?= ui_icon('alert', 18) ?><div><?= _e('verifier.station.mode_off_hint') ?></div></div>
  <?php endif; ?>

  <div class="kpi-row vs-kpis">
    <?= ui_stat(fmt_num($counts['awaiting_verify']), __('verifier.station.kpi_queue'), 'clock', __('verifier.station.kpi_queue_hint', ['n' => fmt_num($counts['verifying'])])) ?>
    <div class="stat"><span class="stat-icon"><?= ui_icon('check', 20) ?></span><span class="stat-value t-num" data-vs-stat="processed">0</span><span class="stat-label"><?= _e('verifier.station.kpi_processed') ?></span><span class="stat-hint"><?= _e('verifier.station.kpi_processed_hint') ?></span></div>
    <div class="stat"><span class="stat-icon"><?= ui_icon('target', 20) ?></span><span class="stat-value t-num" data-vs-stat="pass_rate">—</span><span class="stat-label"><?= _e('verifier.station.kpi_pass') ?></span><span class="stat-hint"><?= _e('verifier.station.kpi_tests') ?> <span class="t-num" data-vs-stat="tests">—</span></span></div>
    <div class="stat"><span class="stat-icon"><?= ui_icon('timer', 20) ?></span><span class="stat-value t-num" data-vs-stat="avg">—</span><span class="stat-label"><?= _e('verifier.station.kpi_avg') ?></span><span class="stat-hint"><?= _e('verifier.station.kpi_avg_hint') ?></span></div>
    <div class="stat"><span class="stat-icon"><?= ui_icon('refresh', 20) ?></span><span class="stat-value t-num" data-vs-stat="released">0</span><span class="stat-label"><?= _e('verifier.station.kpi_released') ?></span><span class="stat-hint"><?= _e('verifier.station.kpi_verified_24h', ['n' => fmt_num($verified_24h ?? 0)]) ?></span></div>
  </div>

  <div class="vs-grid">
    <section class="card vs-stage-card">
      <div class="card-head">
        <h2 class="card-title"><?= ui_icon('eye', 18) ?> <?= _e('verifier.station.stage') ?></h2>
        <span class="t-xs t-muted"><?= _e('verifier.station.stage_hint') ?></span>
      </div>
      <div class="vs-stage" data-vs-stage>
        <div class="vs-stage-empty" data-vs-empty>
          <?= ui_empty(__('verifier.station.empty_title'), __('verifier.station.empty_text'), 'shield') ?>
        </div>
      </div>
    </section>

    <aside class="vs-aside">
      <section class="card vs-log-card">
        <div class="card-head">
          <h2 class="card-title"><?= ui_icon('terminal', 18) ?> <?= _e('verifier.station.log') ?></h2>
          <button type="button" class="btn btn-ghost btn-sm" data-vs-clear><?= _e('verifier.station.clear') ?></button>
        </div>
        <ol class="vs-log" data-vs-log aria-live="off"></ol>
      </section>

      <section class="card vs-howto">
        <div class="card-head">
          <h2 class="card-title"><?= ui_icon('server', 18) ?> <?= _e('verifier.station.headless') ?></h2>
          <?= ui_badge(__('verifier.station.tokens_n', ['n' => (int) ($token_count ?? 0)]), (int) ($token_count ?? 0) > 0 ? 'green' : 'muted', 'key') ?>
        </div>
        <div class="card-body stack-sm">
          <p class="t-small t-muted"><?= _e('verifier.station.headless_text') ?></p>
          <div class="vs-cmd">
            <pre class="code-block is-wrap" dir="ltr"><code><?= e($cmd) ?></code></pre>
            <button type="button" class="btn btn-ghost btn-sm vs-cmd-copy" data-copy="<?= e($cmd) ?>"><?= ui_icon('copy', 14) ?><span><?= _e('verifier.station.copy') ?></span></button>
          </div>
          <a class="btn btn-outline btn-sm" href="<?= e(url('/admin/tokens')) ?>"><?= ui_icon('key', 14) ?><span><?= _e('verifier.station.manage_tokens') ?></span></a>
        </div>
      </section>

      <section class="card vs-tips">
        <div class="card-head"><h2 class="card-title"><?= ui_icon('info', 18) ?> <?= _e('verifier.station.tips') ?></h2></div>
        <ul class="vs-tip-list card-body">
          <li><?= ui_icon('eye', 16) ?><span><?= _e('verifier.station.tip_visible') ?></span></li>
          <li><?= ui_icon('lock', 16) ?><span><?= _e('verifier.station.tip_sandbox') ?></span></li>
          <li><?= ui_icon('gauge', 16) ?><span><?= _e('verifier.station.tip_concurrency') ?></span></li>
          <li><?= ui_icon('refresh', 16) ?><span><?= _e('verifier.station.tip_release') ?></span></li>
        </ul>
      </section>
    </aside>
  </div>

  <section class="card vs-recent">
    <div class="card-head">
      <h2 class="card-title"><?= ui_icon('history', 18) ?> <?= _e('verifier.station.recent') ?></h2>
      <a class="t-small" href="<?= e(url('/admin/runs')) ?>"><?= _e('verifier.station.all_runs') ?></a>
    </div>
    <?php if (!$recent): ?>
      <div class="card-body"><?= ui_empty(__('verifier.station.recent_empty'), __('verifier.station.recent_empty_text'), 'image') ?></div>
    <?php else: ?>
      <div class="table-wrap is-flush">
        <table class="table table-compact">
          <thead>
            <tr>
              <th scope="col"><?= _e('verifier.station.col_shot') ?></th>
              <th scope="col"><?= _e('verifier.station.col_task') ?></th>
              <th scope="col"><?= _e('verifier.station.col_agent') ?></th>
              <th scope="col"><?= _e('verifier.station.col_checks') ?></th>
              <th scope="col"><?= _e('verifier.station.col_status') ?></th>
              <th scope="col" class="num"><?= _e('verifier.station.col_when') ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recent as $r):
                $total = (int) $r['checks_total'];
                $passed = (int) $r['checks_passed'];
                $pct = $total > 0 ? $passed / $total * 100 : null;
                $shot = upload_url($r['screenshot'] ?? null);
            ?>
              <tr>
                <td class="shrink">
                  <?php if ($shot): ?>
                    <a class="vs-thumb" href="<?= e(url('/admin/runs/' . (int) $r['id'])) ?>"><img src="<?= e($shot) ?>" alt="<?= _e('verifier.station.shot_alt', ['id' => (int) $r['id']]) ?>" loading="lazy" width="96" height="60"></a>
                  <?php else: ?>
                    <span class="vs-thumb is-empty" aria-hidden="true"><?= ui_icon('image', 18) ?></span>
                  <?php endif; ?>
                </td>
                <td>
                  <a class="vs-recent-title" href="<?= e(url('/admin/runs/' . (int) $r['id'])) ?>"><?= e(L($r, 'title')) ?></a>
                  <div class="t-xs t-muted t-mono">#<?= (int) $r['id'] ?> · <?= e($r['output_type']) ?><?= $r['visibility'] !== 'public' ? ' · ' . _e('verifier.station.holdout') : '' ?></div>
                </td>
                <td><?= ui_badge((string) ($r['verified_by'] ?: '—'), $r['verified_by'] === 'playwright' ? 'blue' : 'violet') ?></td>
                <td class="vs-recent-checks">
                  <?php if ($pct === null): ?>
                    <span class="t-muted">—</span>
                  <?php else: ?>
                    <span class="t-num"><?= $passed ?>/<?= $total ?></span>
                    <?= ui_scorebar($pct, 100, $pct >= 99.5 ? 'var(--green)' : ($pct >= 50 ? 'var(--gold)' : 'var(--red)')) ?>
                  <?php endif; ?>
                </td>
                <td><?= ui_status((string) $r['status']) ?></td>
                <td class="num t-small t-muted"><?= e(fmt_date((string) $r['updated_at'], 'relative')) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>
<script type="application/json" id="vs-i18n"><?= js_json(I18n::forJs(['verifier.js.'])) ?></script>
