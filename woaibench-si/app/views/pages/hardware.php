<?php
/**
 * Local Fit Lab. Vars: $models (open-weight models with params + scores), $gpus (hardware catalogue).
 * Everything is computed client-side in assets/js/hardware.js from the JSON below.
 */
defined('WB') || exit;

$quants = [
    ['FP16', 16.0], ['Q8_0', 8.5], ['Q6_K', 6.6], ['Q5_K_M', 5.7], ['Q4_K_M', 4.8], ['Q3_K_M', 3.9], ['Q2_K', 3.0],
];
$jsData = [
    'models' => $models,
    'gpus' => $gpus,
    'quants' => $quants,
    'i18n' => I18n::forJs(['tools.hwjs_', 'tools.q_']),
    'icons' => asset('img/icons.svg'),
];
// Device picker groups: GPUs by vendor, then Apple Silicon, then CPU-only setups.
$groups = [];
foreach ($gpus as $g) {
    $label = $g['kind'] === 'gpu' ? $g['vendor'] : __($g['kind'] === 'apple' ? 'tools.hw_group_apple' : 'tools.hw_group_cpu');
    $groups[$label][] = $g;
}
?>
<section class="section tools-hero">
  <div class="container-wide">
    <div class="tools-hero-grid">
      <div class="stack">
        <span class="eyebrow"><?= ui_icon('cpu', 16) ?> <?= _e('tools.hw_eyebrow') ?></span>
        <h1 class="t-display t-balance"><?= _e('tools.hw_headline') ?></h1>
        <p class="t-lead"><?= _e('tools.hw_lead') ?></p>
      </div>
      <ul class="tools-hero-points">
        <li><?= ui_icon('layers', 18) ?><span><?= _e('tools.hw_point_1') ?></span></li>
        <li><?= ui_icon('gauge', 18) ?><span><?= _e('tools.hw_point_2') ?></span></li>
        <li><?= ui_icon('trophy', 18) ?><span><?= _e('tools.hw_point_3') ?></span></li>
      </ul>
    </div>
  </div>
</section>

<section class="section tools-body">
  <div class="container-wide hw-layout" id="hw-app">
    <form class="card card-pad hw-panel stack" id="hw-form" autocomplete="off" novalidate>
      <fieldset class="hw-step">
        <legend class="hw-step-title"><span class="hw-step-n">1</span><?= _e('tools.hw_step_model') ?></legend>
        <div class="field">
          <label class="label" for="hw-model"><?= _e('tools.hw_model') ?></label>
          <select class="select" id="hw-model" name="m">
<?php if ($models): ?>
            <optgroup label="<?= _e('tools.hw_models_group') ?>">
<?php foreach ($models as $m): ?>
              <option value="<?= e($m['slug']) ?>"><?= e($m['name']) ?> · <?= e(rtrim(rtrim(number_format($m['params'], 1, '.', ''), '0'), '.')) ?>B<?= $m['score'] !== null ? ' · ' . e(fmt_score($m['score'])) : '' ?></option>
<?php endforeach; ?>
            </optgroup>
<?php endif; ?>
            <option value="custom"><?= _e('tools.hw_custom_model') ?></option>
          </select>
        </div>
        <div class="hw-custom form-row" id="hw-custom" hidden>
          <div class="field">
            <label class="label" for="hw-params"><?= _e('tools.hw_params') ?></label>
            <div class="input-group"><input class="input" type="number" id="hw-params" name="p" min="0.1" max="3000" step="0.1" value="8" inputmode="decimal"><span class="input-addon">B</span></div>
          </div>
          <div class="field">
            <label class="label" for="hw-active"><?= _e('tools.hw_active') ?></label>
            <div class="input-group"><input class="input" type="number" id="hw-active" name="a" min="0.1" max="3000" step="0.1" placeholder="<?= _e('tools.hw_active_ph') ?>" inputmode="decimal"><span class="input-addon">B</span></div>
          </div>
        </div>
        <details class="hw-advanced">
          <summary><?= _e('tools.hw_arch') ?></summary>
          <div class="form-row">
            <div class="field">
              <label class="label" for="hw-layers"><?= _e('tools.hw_layers') ?></label>
              <input class="input" type="number" id="hw-layers" name="l" min="1" max="400" step="1" inputmode="numeric">
            </div>
            <div class="field">
              <label class="label" for="hw-kvdim"><?= _e('tools.hw_kvdim') ?></label>
              <input class="input" type="number" id="hw-kvdim" name="kd" min="64" max="65536" step="64" inputmode="numeric">
            </div>
          </div>
          <p class="help" id="hw-arch-note"></p>
        </details>
      </fieldset>

      <fieldset class="hw-step">
        <legend class="hw-step-title"><span class="hw-step-n">2</span><?= _e('tools.hw_step_quant') ?></legend>
        <div class="hw-quants" role="radiogroup" aria-label="<?= _e('tools.hw_quant') ?>">
<?php foreach ($quants as $i => [$q, $bits]): ?>
          <label class="hw-quant">
            <input type="radio" name="q" value="<?= e($q) ?>"<?= $q === 'Q4_K_M' ? ' checked' : '' ?>>
            <span class="hw-quant-name"><?= e($q) ?></span>
            <span class="hw-quant-bits t-num"><?= e(number_format($bits, 1)) ?> <?= _e('tools.hw_bits') ?></span>
          </label>
<?php endforeach; ?>
        </div>
        <div class="field">
          <label class="label" for="hw-kv"><?= _e('tools.hw_kv') ?></label>
          <select class="select" id="hw-kv" name="kv">
            <option value="f16"><?= _e('tools.hw_kv_f16') ?></option>
            <option value="q8"><?= _e('tools.hw_kv_q8') ?></option>
            <option value="q4"><?= _e('tools.hw_kv_q4') ?></option>
          </select>
        </div>
      </fieldset>

      <fieldset class="hw-step">
        <legend class="hw-step-title"><span class="hw-step-n">3</span><?= _e('tools.hw_step_ctx') ?></legend>
        <div class="field">
          <div class="split"><label class="label" for="hw-ctx"><?= _e('tools.hw_ctx') ?></label><output class="hw-ctx-out t-num" id="hw-ctx-out" for="hw-ctx">32k</output></div>
          <input class="range" type="range" id="hw-ctx" name="c" min="0" max="1000" step="1" value="222" aria-describedby="hw-ctx-note">
          <div class="hw-ticks t-xs t-muted" aria-hidden="true"><span>2k</span><span>8k</span><span>32k</span><span>128k</span><span>1M</span></div>
          <p class="help" id="hw-ctx-note"></p>
        </div>
      </fieldset>

      <fieldset class="hw-step">
        <legend class="hw-step-title"><span class="hw-step-n">4</span><?= _e('tools.hw_step_hw') ?></legend>
        <div class="hw-devices stack-sm" id="hw-devices"></div>
        <template id="hw-device-tpl">
          <div class="hw-device">
            <select class="select hw-device-select" aria-label="<?= _e('tools.hw_device') ?>">
<?php foreach ($groups as $label => $list): ?>
              <optgroup label="<?= e($label) ?>">
<?php foreach ($list as $g): ?>
                <option value="<?= e($g['name']) ?>"><?= e($g['name']) ?><?= $g['kind'] === 'gpu' ? ' · ' . e(rtrim(rtrim(number_format($g['vram'], 1, '.', ''), '0'), '.')) . ' GB' : '' ?></option>
<?php endforeach; ?>
              </optgroup>
<?php endforeach; ?>
              <optgroup label="<?= _e('tools.hw_group_custom') ?>">
                <option value="__custom"><?= _e('tools.hw_custom_device') ?></option>
              </optgroup>
            </select>
            <input class="input hw-device-count" type="number" min="1" max="8" step="1" value="1" inputmode="numeric" aria-label="<?= _e('tools.hw_count') ?>">
            <button class="btn btn-ghost btn-icon btn-sm hw-device-remove" type="button" aria-label="<?= _e('tools.hw_remove') ?>"><?= ui_icon('close', 16) ?></button>
            <div class="hw-device-custom form-row" hidden>
              <div class="field"><label class="label"><?= _e('tools.hw_custom_mem') ?></label><div class="input-group"><input class="input hw-custom-mem" type="number" min="1" max="2048" step="1" value="24" inputmode="numeric"><span class="input-addon">GB</span></div></div>
              <div class="field"><label class="label"><?= _e('tools.hw_custom_bw') ?></label><div class="input-group"><input class="input hw-custom-bw" type="number" min="10" max="20000" step="1" value="900" inputmode="numeric"><span class="input-addon">GB/s</span></div></div>
            </div>
          </div>
        </template>
        <button class="btn btn-outline btn-sm" type="button" id="hw-add"><?= ui_icon('plus', 16) ?><span><?= _e('tools.hw_add') ?></span></button>
        <div class="field" id="hw-split-field" hidden>
          <span class="label"><?= _e('tools.hw_split') ?></span>
          <div class="hw-toggle" role="radiogroup" aria-label="<?= _e('tools.hw_split') ?>">
            <label><input type="radio" name="split" value="layer" checked><span><?= _e('tools.hw_split_layer') ?></span></label>
            <label><input type="radio" name="split" value="tensor"><span><?= _e('tools.hw_split_tensor') ?></span></label>
          </div>
        </div>
        <div class="hw-offload" id="hw-offload-box">
          <label class="switch"><input type="checkbox" id="hw-offload" name="off"><span><?= _e('tools.hw_offload') ?></span></label>
          <div class="field" id="hw-ram-field" hidden>
            <label class="label" for="hw-ram"><?= _e('tools.hw_ram') ?></label>
            <select class="select" id="hw-ram" name="ram">
<?php foreach (array_filter($gpus, static fn($g) => $g['kind'] === 'cpu') as $g): ?>
              <option value="<?= e($g['name']) ?>"><?= e($g['name']) ?></option>
<?php endforeach; ?>
            </select>
          </div>
        </div>
      </fieldset>
      <div class="cluster hw-actions">
        <button class="btn btn-ghost btn-sm" type="button" id="hw-share" data-done="<?= _e('tools.copied_link') ?>"><?= ui_icon('link', 16) ?><span><?= _e('tools.share') ?></span></button>
        <button class="btn btn-ghost btn-sm" type="button" id="hw-reset"><?= ui_icon('refresh', 16) ?><span><?= _e('tools.reset') ?></span></button>
      </div>
    </form>

    <div class="hw-results stack" aria-live="polite">
      <div class="card card-pad hw-verdict" id="hw-verdict" data-verdict="fits">
        <div class="hw-verdict-head">
          <span class="hw-verdict-icon"><?= ui_icon('check', 22) ?></span>
          <div>
            <span class="eyebrow"><?= _e('tools.hw_verdict') ?></span>
            <h2 class="t-h3 hw-verdict-title" id="hw-verdict-title"><?= _e('common.loading') ?></h2>
          </div>
        </div>
        <p class="t-muted hw-verdict-text" id="hw-verdict-text"></p>
        <div class="hw-membar" id="hw-membar" role="img" aria-label="">
          <span class="hw-seg-w"></span><span class="hw-seg-kv"></span><span class="hw-seg-oh"></span>
          <span class="hw-cap" title=""></span>
        </div>
        <div class="legend hw-legend-row">
          <span class="legend-item"><i class="hw-key hw-key-w"></i><?= _e('tools.hw_weights') ?> <b class="t-num" data-out="w">—</b></span>
          <span class="legend-item"><i class="hw-key hw-key-kv"></i><?= _e('tools.hw_kvcache') ?> <b class="t-num" data-out="kv">—</b></span>
          <span class="legend-item"><i class="hw-key hw-key-oh"></i><?= _e('tools.hw_overhead') ?> <b class="t-num" data-out="oh">—</b></span>
          <span class="legend-item"><i class="hw-key hw-key-cap"></i><?= _e('tools.hw_available') ?> <b class="t-num" data-out="cap">—</b></span>
        </div>
      </div>

      <div class="kpi-row hw-kpis">
        <div class="stat"><span class="stat-icon"><?= ui_icon('bolt', 18) ?></span><span class="stat-value t-num" data-out="tps">—</span><span class="stat-label"><?= _e('tools.hw_tps') ?></span><span class="stat-hint" data-out="tps-hint"></span></div>
        <div class="stat"><span class="stat-icon"><?= ui_icon('timer', 18) ?></span><span class="stat-value t-num" data-out="tps-full">—</span><span class="stat-label"><?= _e('tools.hw_tps_full') ?></span><span class="stat-hint" data-out="tps-full-hint"></span></div>
        <div class="stat"><span class="stat-icon"><?= ui_icon('clock', 18) ?></span><span class="stat-value t-num" data-out="ttft">—</span><span class="stat-label"><?= _e('tools.hw_ttft') ?></span><span class="stat-hint" data-out="ttft-hint"></span></div>
        <div class="stat"><span class="stat-icon"><?= ui_icon('layers', 18) ?></span><span class="stat-value t-num" data-out="maxctx">—</span><span class="stat-label"><?= _e('tools.hw_maxctx') ?></span><span class="stat-hint" data-out="maxctx-hint"></span></div>
      </div>

      <div class="card hw-card">
        <div class="card-head split"><h2 class="card-title"><?= _e('tools.hw_quant_table') ?></h2><span class="t-xs t-muted"><?= _e('tools.hw_quant_table_sub') ?></span></div>
        <div class="table-wrap">
          <table class="table table-compact hw-qtable">
            <thead><tr>
              <th scope="col"><?= _e('tools.hw_quant') ?></th>
              <th scope="col" class="num"><?= _e('tools.hw_col_mem') ?></th>
              <th scope="col"><?= _e('tools.hw_col_fit') ?></th>
              <th scope="col" class="num"><?= _e('tools.hw_col_speed') ?></th>
              <th scope="col"><?= _e('tools.hw_col_quality') ?></th>
            </tr></thead>
            <tbody id="hw-qrows"></tbody>
          </table>
        </div>
      </div>

      <div class="card hw-card">
        <div class="card-head split"><h2 class="card-title"><?= _e('tools.hw_best') ?></h2><span class="t-xs t-muted"><?= _e('tools.hw_best_sub') ?></span></div>
<?php if (array_filter($models, static fn($m) => $m['score'] !== null)): ?>
        <div class="table-wrap">
          <table class="table hw-btable">
            <thead><tr>
              <th scope="col" class="num">#</th>
              <th scope="col"><?= _e('common.model') ?></th>
              <th scope="col" class="num"><?= _e('common.score') ?></th>
              <th scope="col"><?= _e('tools.hw_col_bestq') ?></th>
              <th scope="col" class="num"><?= _e('tools.hw_col_speed') ?></th>
              <th scope="col"><?= _e('tools.hw_col_fit') ?></th>
            </tr></thead>
            <tbody id="hw-brows"></tbody>
          </table>
        </div>
<?php else: ?>
        <div class="card-pad"><?= ui_empty(__('tools.hw_best_empty'), __('tools.hw_best_empty_text'), 'cpu') ?></div>
<?php endif; ?>
      </div>

      <details class="card card-pad hw-method">
        <summary class="hw-method-sum"><?= ui_icon('flask', 18) ?><span><?= _e('tools.hw_method_title') ?></span></summary>
        <div class="prose">
          <p><?= _e('tools.hw_method_intro') ?></p>
          <ul>
            <li><strong><?= _e('tools.hw_weights') ?></strong> = <code><?= _e('tools.hw_f_weights') ?></code></li>
            <li><strong><?= _e('tools.hw_kvcache') ?></strong> = <code>2 × <?= _e('tools.hw_f_layers') ?> × kv_dim × <?= _e('tools.hw_f_ctx') ?> × <?= _e('tools.hw_f_bytes') ?></code> — <?= _e('tools.hw_f_kv_note') ?></li>
            <li><strong><?= _e('tools.hw_overhead') ?></strong> = <code>1 GB + 8% × (<?= _e('tools.hw_weights') ?> + <?= _e('tools.hw_kvcache') ?>)</code> — <?= _e('tools.hw_f_oh_note') ?></li>
            <li><strong><?= _e('tools.hw_available') ?></strong>: <?= _e('tools.hw_f_avail') ?></li>
            <li><strong><?= _e('tools.hw_tps') ?></strong> ≈ <code><?= _e('tools.hw_f_tps') ?></code> — <?= _e('tools.hw_f_tps_note') ?></li>
            <li><strong><?= _e('tools.hw_ttft') ?></strong> ≈ <code><?= _e('tools.hw_f_prefill') ?></code></li>
          </ul>
          <p><?= _e('tools.hw_method_verdicts') ?></p>
          <p class="t-small t-muted"><?= _e('tools.hw_method_heuristic') ?></p>
          <p class="t-small t-muted"><?= _e('tools.hw_method_specs') ?></p>
        </div>
      </details>
    </div>
  </div>
</section>
<script type="application/json" id="hw-data"><?= js_json($jsData) ?></script>
