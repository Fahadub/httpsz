<?php
/**
 * Advisor: personal, custom-weighted ranking computed client-side (assets/js/advisor.js).
 * Vars: $rows (slim leaderboard rows), $tracks (slug, name, color, icon).
 */
defined('WB') || exit;

// Presets: weight per track slug (missing slugs get 'rest'); optional constraints.
$presets = [
    'balanced' => ['label' => __('tools.adv_p_balanced'), 'rest' => 1, 'w' => []],
    'frontend' => ['label' => __('tools.adv_p_frontend'), 'rest' => 1, 'w' => ['ui' => 5, 'motion' => 4, 'a11y' => 4, 'dataviz' => 3, 'arabic' => 2, '3d' => 2, 'debug' => 2, 'secure' => 2, 'games' => 1, 'algo' => 1, 'agentic' => 1, 'reasoning' => 1]],
    'games' => ['label' => __('tools.adv_p_games'), 'rest' => 0.5, 'w' => ['games' => 5, '3d' => 5, 'motion' => 3, 'ui' => 2, 'algo' => 2, 'debug' => 2]],
    'data' => ['label' => __('tools.adv_p_data'), 'rest' => 1, 'w' => ['dataviz' => 5, 'algo' => 4, 'reasoning' => 4, 'debug' => 3, 'agentic' => 3, 'secure' => 2, 'ui' => 1, 'games' => 0, '3d' => 0, 'motion' => 0.5]],
    'arabic' => ['label' => __('tools.adv_p_arabic'), 'rest' => 1, 'w' => ['arabic' => 5, 'ui' => 4, 'a11y' => 3, 'secure' => 2, 'motion' => 2, 'games' => 0.5, '3d' => 0.5]],
    'secure' => ['label' => __('tools.adv_p_secure'), 'rest' => 0.5, 'w' => ['secure' => 5, 'debug' => 4, 'algo' => 3, 'agentic' => 2, 'reasoning' => 2, 'a11y' => 1]],
    'budget' => ['label' => __('tools.adv_p_budget'), 'rest' => 1, 'w' => [], 'cost' => 0.02],
];
$costSteps = ['', '0.005', '0.01', '0.02', '0.05', '0.1', '0.25', '0.5', '1'];
$latSteps = ['', '10', '20', '30', '60', '120', '300'];
$ctxSteps = ['' => __('tools.adv_any'), '32768' => '32k', '131072' => '128k', '200000' => '200k', '1000000' => '1M'];
$jsData = [
    'rows' => $rows,
    'tracks' => $tracks,
    'presets' => $presets,
    'i18n' => I18n::forJs(['tools.advjs_']),
    'icons' => asset('img/icons.svg'),
];
?>
<section class="section tools-hero">
  <div class="container-wide">
    <div class="tools-hero-grid">
      <div class="stack">
        <span class="eyebrow"><?= ui_icon('compass', 16) ?> <?= _e('tools.adv_eyebrow') ?></span>
        <h1 class="t-display t-balance"><?= _e('tools.adv_headline') ?></h1>
        <p class="t-lead"><?= _e('tools.adv_lead') ?></p>
      </div>
      <ul class="tools-hero-points">
        <li><?= ui_icon('scale', 18) ?><span><?= _e('tools.adv_point_1') ?></span></li>
        <li><?= ui_icon('target', 18) ?><span><?= _e('tools.adv_point_2') ?></span></li>
        <li><?= ui_icon('link', 18) ?><span><?= _e('tools.adv_point_3') ?></span></li>
      </ul>
    </div>
  </div>
</section>

<section class="section tools-body">
  <div class="container-wide">
<?php if (!$rows): ?>
    <div class="card card-pad"><?= ui_empty(__('tools.adv_empty'), __('tools.adv_empty_text'), 'compass', '<a class="btn btn-primary" href="' . e(url('/leaderboard')) . '">' . _e('nav.leaderboard') . '</a>') ?></div>
<?php else: ?>
    <div class="adv-presets cluster" role="group" aria-label="<?= _e('tools.adv_presets') ?>">
      <span class="t-small t-muted"><?= _e('tools.adv_presets') ?></span>
<?php foreach ($presets as $key => $p): ?>
      <button class="chip" type="button" data-preset="<?= e($key) ?>" aria-pressed="<?= $key === 'balanced' ? 'true' : 'false' ?>"><?= e($p['label']) ?></button>
<?php endforeach; ?>
    </div>
    <div class="adv-layout">
      <form class="card card-pad adv-panel stack" id="adv-form" autocomplete="off" novalidate>
        <div class="split">
          <h2 class="t-h3 adv-panel-title"><?= _e('tools.adv_weights') ?></h2>
          <span class="t-xs t-muted"><?= _e('tools.adv_weights_hint') ?></span>
        </div>
        <div class="adv-weights">
<?php foreach ($tracks as $t): ?>
          <div class="adv-weight" style="--track:<?= e($t['color']) ?>">
            <label class="adv-weight-label" for="w-<?= e($t['slug']) ?>"><?= ui_icon((string) $t['icon'], 15) ?><span><?= e($t['name']) ?></span></label>
            <input class="range" type="range" id="w-<?= e($t['slug']) ?>" name="w-<?= e($t['slug']) ?>" data-track="<?= e($t['slug']) ?>" min="0" max="5" step="0.5" value="1">
            <output class="adv-weight-out t-num" for="w-<?= e($t['slug']) ?>">1</output>
          </div>
<?php endforeach; ?>
        </div>
        <div class="divider"></div>
        <h2 class="t-h3 adv-panel-title"><?= _e('tools.adv_constraints') ?></h2>
        <div class="form-row adv-constraints">
          <div class="field">
            <label class="label" for="adv-cost"><?= _e('tools.adv_cost') ?></label>
            <select class="select" id="adv-cost" name="cost">
<?php foreach ($costSteps as $c): ?>
              <option value="<?= e($c) ?>"><?= $c === '' ? _e('tools.adv_any') : '≤ ' . e(fmt_money((float) $c)) ?></option>
<?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label class="label" for="adv-lat"><?= _e('tools.adv_latency') ?></label>
            <select class="select" id="adv-lat" name="lat">
<?php foreach ($latSteps as $l): ?>
              <option value="<?= e($l) ?>"><?= $l === '' ? _e('tools.adv_any') : '≤ ' . e(fmt_ms((int) $l * 1000)) ?></option>
<?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label class="label" for="adv-ctx"><?= _e('tools.adv_ctx') ?></label>
            <select class="select" id="adv-ctx" name="ctx">
<?php foreach ($ctxSteps as $v => $label): ?>
              <option value="<?= e((string) $v) ?>"><?= $v === '' ? e($label) : '≥ ' . e($label) ?></option>
<?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="stack-sm adv-switches">
          <label class="switch"><input type="checkbox" id="adv-open" name="open"><span><?= _e('tools.adv_open') ?></span></label>
          <label class="switch"><input type="checkbox" id="adv-vision" name="vision"><span><?= _e('tools.adv_vision') ?></span></label>
          <label class="switch"><input type="checkbox" id="adv-prov" name="prov"><span><?= _e('tools.adv_prov') ?></span></label>
        </div>
        <div class="cluster adv-actions">
          <button class="btn btn-primary btn-sm" type="button" id="adv-share" data-done="<?= _e('tools.copied_link') ?>"><?= ui_icon('link', 16) ?><span><?= _e('tools.share') ?></span></button>
          <button class="btn btn-ghost btn-sm" type="button" id="adv-reset"><?= ui_icon('refresh', 16) ?><span><?= _e('tools.reset') ?></span></button>
        </div>
      </form>

      <div class="adv-results stack" aria-live="polite">
        <div class="card card-pad adv-top" id="adv-top"></div>
        <div class="card adv-card">
          <div class="card-head split">
            <h2 class="card-title"><?= _e('tools.adv_ranking') ?></h2>
            <span class="t-xs t-muted" id="adv-count"></span>
          </div>
          <div class="table-wrap">
            <table class="table adv-table">
              <thead><tr>
                <th scope="col" class="num">#</th>
                <th scope="col"><?= _e('common.model') ?></th>
                <th scope="col" class="num"><?= _e('tools.adv_col_yours') ?></th>
                <th scope="col" class="adv-col-bar"><span class="sr-only"><?= _e('tools.adv_col_bar') ?></span></th>
                <th scope="col" class="num"><?= _e('tools.adv_col_overall') ?></th>
                <th scope="col" class="num"><?= _e('common.cost') ?></th>
                <th scope="col" class="num"><?= _e('common.latency') ?></th>
              </tr></thead>
              <tbody id="adv-rows"></tbody>
            </table>
          </div>
          <div class="card-foot t-xs t-muted adv-excluded" id="adv-excluded" hidden></div>
        </div>
        <p class="t-xs t-muted adv-method"><?= ui_icon('info', 14) ?> <?= _e('tools.adv_method') ?></p>
      </div>
    </div>
<?php endif; ?>
  </div>
</section>
<script type="application/json" id="adv-data"><?= js_json($jsData) ?></script>
