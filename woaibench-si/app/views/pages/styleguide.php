<?php
/**
 * Design-system reference (/styleguide, noindex): every token and component class rendered once.
 * Used by module authors and QA. Sample values here are illustrative, never real results.
 */
defined('WB') || exit;

$sampleModel = ['slug' => '', 'name' => __('design.sg_model_name'), 'provider' => 'example', 'provider_name' => __('design.sg_provider_name')];
$sampleTrack = ['slug' => 'ui', 'name_ar' => 'صناعة الواجهات', 'name_en' => 'Interface Craft', 'icon' => 'palette', 'color' => '#E3B65C'];
$tokens = ['bg', 'bg-elev', 'surface', 'surface-2', 'surface-3', 'border', 'border-strong', 'text', 'text-muted', 'text-faint', 'gold', 'gold-2', 'gold-soft', 'accent', 'green', 'red', 'blue', 'violet', 'amber', 'focus'];
$icons = [];
if (preg_match_all('/<symbol id="i-([a-z0-9\-]+)"/', (string) @file_get_contents(WB_ROOT . '/assets/img/icons.svg'), $mm)) {
    $icons = $mm[1];
}
$sections = ['brand', 'colors', 'type', 'buttons', 'forms', 'cards', 'tables', 'badges', 'data', 'nav', 'feedback', 'content', 'charts', 'icons', 'admin'];
?>
<section class="section">
  <div class="container-wide">
    <div class="section-head">
      <span class="eyebrow">/styleguide</span>
      <h1 class="section-title"><?= ui_em(__('design.sg_title')) ?></h1>
      <p class="section-sub"><?= _e('design.sg_intro') ?></p>
      <div class="cluster">
        <button class="btn btn-outline btn-sm" type="button" data-theme-toggle><?= ui_icon('sun', 15, 'icon-sun') ?><?= ui_icon('moon', 15, 'icon-moon') ?><?= _e('nav.theme') ?></button>
        <a class="btn btn-ghost btn-sm" href="<?= e(lang_url(lang() === 'ar' ? 'en' : 'ar')) ?>"><?= ui_icon('globe', 15) ?><?= lang() === 'ar' ? 'English' : 'العربية' ?></a>
        <?= ui_badge(__('design.sg_sample_note'), 'amber', 'info') ?>
      </div>
    </div>
    <nav class="sg-nav" aria-label="<?= _e('design.sg_title') ?>">
      <?php foreach ($sections as $s): ?><a class="chip" href="#sg-<?= $s ?>"><?= _e('design.sg_' . $s) ?></a><?php endforeach; ?>
    </nav>

    <!-- Brand -->
    <div class="sg-section" id="sg-brand">
      <h2 class="t-h2"><?= _e('design.sg_brand') ?></h2>
      <div class="grid-3">
        <div class="panel stack t-center" style="align-items:center"><?= ui_logo_mark(120, 'full', 'sg-mark') ?><code class="t-mono t-xs t-faint">ui_logo_mark(120, 'full')</code></div>
        <div class="panel stack" style="justify-content:center;align-items:center"><?= ui_brand(40) ?><code class="t-mono t-xs t-faint">ui_brand()</code></div>
        <div class="panel stack" style="justify-content:center;align-items:center;background:#08090C"><img src="<?= e(asset('img/logo.svg')) ?>" alt="WOAIBench.si" width="260" height="48" style="height:48px;width:auto"><code class="t-mono t-xs" style="color:#8A857A">img/logo.svg</code></div>
        <div class="panel stack" style="justify-content:center;align-items:center;background:#F6F2E9"><img src="<?= e(asset('img/logo-light.svg')) ?>" alt="WOAIBench.si" width="260" height="48" style="height:48px;width:auto"><code class="t-mono t-xs" style="color:#6F685D">img/logo-light.svg</code></div>
        <div class="panel cluster justify-center"><img src="<?= e(asset('img/favicon.svg')) ?>" alt="" width="16" height="16"><img src="<?= e(asset('img/favicon.svg')) ?>" alt="" width="32" height="32"><img src="<?= e(asset('img/logo-mark.svg')) ?>" alt="" width="48" height="48"><img src="<?= e(asset('img/apple-touch-icon.png')) ?>" alt="" width="64" height="64" style="border-radius:14px"></div>
        <div class="panel"><img src="<?= e(asset('img/og.png')) ?>" alt="og.png" width="1200" height="630" style="border-radius:10px;width:100%;height:auto"></div>
      </div>
    </div>

    <!-- Colors -->
    <div class="sg-section" id="sg-colors">
      <h2 class="t-h2"><?= _e('design.sg_colors') ?></h2>
      <div class="sg-swatches">
        <?php foreach ($tokens as $t): ?><div class="sg-swatch"><i style="background:var(--<?= $t ?>)"></i><span>--<?= $t ?></span></div><?php endforeach; ?>
        <div class="sg-swatch"><i style="background:var(--gold-grad)"></i><span>--gold-grad</span></div>
      </div>
      <h3 class="t-h3 mt-6"><?= _e('design.sg_heat') ?></h3>
      <div class="sg-row mt-4">
        <?php for ($i = 0; $i <= 10; $i++): ?><?= ui_heat_cell($i * 10 + ($i < 10 ? 4.5 : 0)) ?><?php endfor; ?><?= ui_heat_cell(null) ?>
      </div>
      <h3 class="t-h3 mt-6"><?= _e('design.sg_palette') ?></h3>
      <div class="legend mt-4" id="sg-palette"></div>
    </div>

    <!-- Type -->
    <div class="sg-section sg-type" id="sg-type">
      <h2 class="t-h2"><?= _e('design.sg_type') ?></h2>
      <div class="t-display"><?= ui_em(__('home.hero_title')) ?></div>
      <div class="t-h1">t-h1 · <?= _e('design.sg_heading') ?></div>
      <div class="t-h2">t-h2 · <?= _e('design.sg_heading') ?></div>
      <div class="t-h3">t-h3 · <?= _e('design.sg_heading') ?></div>
      <p class="t-lead">t-lead · <?= _e('home.board_sub') ?></p>
      <p>body · <?= _e('home.why_sub') ?></p>
      <p class="t-muted">t-muted · <?= _e('home.tracks_sub') ?></p>
      <p class="t-small">t-small · <?= _e('home.board_note') ?></p>
      <p class="t-xs t-faint">t-xs t-faint · <?= _e('footer.independent') ?></p>
      <p><span class="t-mono">t-mono 0123456789 const x = 42;</span> · <span class="t-num">t-num 1,234.5 / 98.7</span> · <span class="t-gold t-h3">t-gold</span></p>
      <p><a class="link" href="#sg-type">.link</a> · <a class="link-arrow" href="#sg-type">.link-arrow<?= ui_icon('arrow-right', 14) ?></a> · <span class="kbd">⌘</span> <kbd>K</kbd></p>
    </div>

    <!-- Buttons -->
    <div class="sg-section" id="sg-buttons">
      <h2 class="t-h2"><?= _e('design.sg_buttons') ?></h2>
      <div class="sg-row">
        <button class="btn btn-primary" type="button"><?= ui_icon('trophy', 17) ?>btn-primary</button>
        <button class="btn" type="button">btn</button>
        <button class="btn btn-outline" type="button">btn-outline</button>
        <button class="btn btn-ghost" type="button">btn-ghost</button>
        <button class="btn btn-danger" type="button" data-confirm="<?= _e('js.confirm') ?>"><?= ui_icon('trash', 17) ?>btn-danger</button>
        <button class="btn btn-primary btn-sm" type="button">btn-sm</button>
        <button class="btn btn-outline btn-lg" type="button">btn-lg<?= ui_icon('arrow-right', 18) ?></button>
        <button class="btn btn-icon btn-outline" type="button" aria-label="settings"><?= ui_icon('settings', 18) ?></button>
        <button class="btn-icon" type="button" aria-label="copy" data-copy="woaibench.si" data-tip="data-copy"><?= ui_icon('copy', 18) ?></button>
        <button class="btn btn-primary is-loading" type="button">is-loading</button>
        <button class="btn" type="button" disabled>disabled</button>
      </div>
      <div class="mt-4" style="max-width:360px"><button class="btn btn-outline btn-block" type="button" id="sg-toast"><?= ui_icon('sparkles', 17) ?><?= _e('design.sg_toast_btn') ?></button></div>
    </div>

    <!-- Forms -->
    <div class="sg-section" id="sg-forms">
      <h2 class="t-h2"><?= _e('design.sg_forms') ?></h2>
      <form class="form card card-pad" style="max-width:760px" action="#" data-confirm="<?= _e('js.confirm') ?>">
        <div class="form-row">
          <div class="field"><label class="label" for="sg-in"><?= _e('design.sg_field') ?> <span class="req">*</span></label><input class="input" id="sg-in" placeholder="<?= _e('design.sg_placeholder') ?>"><p class="help"><?= _e('design.sg_help') ?></p></div>
          <div class="field"><label class="label" for="sg-sel">select</label><select class="select" id="sg-sel"><option>Option A</option><option>Option B</option></select></div>
        </div>
        <div class="field has-error"><label class="label" for="sg-err">.has-error</label><input class="input" id="sg-err" value="—"><span class="field-error"><?= _e('design.sg_error') ?></span></div>
        <div class="field"><label class="label" for="sg-ta">textarea</label><textarea class="textarea" id="sg-ta"></textarea></div>
        <div class="field"><label class="label" for="sg-ig">input-group</label><div class="input-group"><span class="input-addon">$</span><input class="input" id="sg-ig" value="0.25"><button class="btn btn-outline" type="button"><?= _e('common.save') ?></button></div></div>
        <div class="field"><span class="label">input-icon</span><span class="input-icon"><?= ui_icon('search', 17) ?><input class="input" type="search" placeholder="<?= _e('common.search') ?>" aria-label="<?= _e('common.search') ?>"></span></div>
        <div class="field"><label class="label" for="sg-range">range</label><input class="range" type="range" id="sg-range" min="0" max="100" value="60" style="--pct:60%"></div>
        <div class="cluster">
          <label class="checkbox"><input type="checkbox" checked> checkbox</label>
          <label class="radio"><input type="radio" name="sg-r" checked> radio</label>
          <label class="switch"><input type="checkbox" checked> switch</label>
        </div>
        <div class="form-actions"><button class="btn btn-primary" type="submit"><?= _e('common.save') ?></button><button class="btn btn-ghost" type="reset"><?= _e('common.cancel') ?></button></div>
      </form>
    </div>

    <!-- Cards -->
    <div class="sg-section" id="sg-cards">
      <h2 class="t-h2"><?= _e('design.sg_cards') ?></h2>
      <div class="grid-3">
        <div class="card"><div class="card-head"><h3 class="card-title">card + card-head</h3><?= ui_badge('badge', 'gold') ?></div><div class="card-body t-muted"><?= _e('home.why_open_text') ?></div><div class="card-foot"><span>card-foot</span><a class="link-arrow" href="#sg-cards"><?= _e('common.more') ?><?= ui_icon('arrow-right', 14) ?></a></div></div>
        <a class="card card-pad card-hover" href="#sg-cards"><h3 class="card-title">card-pad card-hover</h3><p class="t-muted t-small mt-2"><?= _e('home.tool_compare') ?></p></a>
        <div class="card card-pad card-glass"><h3 class="card-title">card-glass</h3><p class="t-muted t-small mt-2"><?= _e('home.tool_value') ?></p></div>
        <div class="panel"><strong>panel</strong><p class="t-muted t-small mt-2"><?= _e('home.tool_advisor') ?></p></div>
        <div class="card card-pad card-gold"><strong>card-gold</strong><p class="t-muted t-small mt-2"><?= _e('home.tool_api') ?></p></div>
      </div>
    </div>

    <!-- Tables -->
    <div class="sg-section" id="sg-tables">
      <h2 class="t-h2"><?= _e('design.sg_tables') ?></h2>
      <div class="cluster mb-4"><span class="input-icon" style="max-width:320px;flex:1"><?= ui_icon('search', 17) ?><input class="input input-sm" type="search" data-filter-input="#sg-table" placeholder="data-filter-input" aria-label="<?= _e('common.search') ?>"></span></div>
      <div class="table-wrap">
        <table class="table" id="sg-table" data-sortable>
          <thead><tr><th class="center" data-sort="num" scope="col">#</th><th data-sort="text" scope="col"><?= _e('common.model') ?></th><th class="num" data-sort="num" scope="col"><?= _e('common.score') ?></th><th scope="col">scorebar</th><th class="center" scope="col">heat</th><th class="num" data-sort="num" scope="col">delta</th><th scope="col">difficulty</th></tr></thead>
          <tbody>
            <?php foreach ([[1, 'Alpha', 81.4, 79.1, 83.6, 1.2, 4], [2, 'Beta', 79.8, 77.0, 82.1, -0.6, 3], [3, 'Gamma', 74.2, 71.9, 76.4, 0, 2], [4, 'Delta', 61.0, 57.2, 64.8, 3.1, 1]] as [$rk, $nm, $sc, $lo, $hi, $dl, $df]): ?>
              <tr class="<?= $rk === 2 ? 'is-highlight' : ($rk === 4 ? 'is-provisional' : '') ?>">
                <td class="center" data-value="<?= $rk ?>"><?= ui_rank($rk, $rk === 2 ? 1 : $rk) ?></td>
                <td data-value="<?= e($nm) ?>"><?= ui_model_chip(['name' => __('design.sg_model_name') . ' ' . $nm, 'provider' => strtolower($nm), 'provider_name' => __('design.sg_provider_name')], false) ?></td>
                <td class="num" data-value="<?= $sc ?>"><?= ui_score($sc, $lo, $hi, 'md') ?></td>
                <td style="min-width:160px"><?= ui_scorebar($sc, 100, null, $lo, $hi) ?></td>
                <td class="center"><?= ui_heat_cell($sc, 'heat ' . $sc) ?></td>
                <td class="num" data-value="<?= $dl ?>"><?= ui_delta($dl) ?></td>
                <td><?= ui_difficulty($df) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="t-xs t-faint mt-2 ltr">table[data-sortable] · th[data-sort] · td[data-value] · tr.is-highlight · tr.is-provisional</p>
      <div class="table-wrap mt-4" style="max-width:520px">
        <table class="table table-compact"><thead><tr><th scope="col">table-compact</th><th class="num" scope="col"><?= _e('common.cost') ?></th></tr></thead><tbody><tr><td>prompt</td><td class="num"><?= e(fmt_money(0.0042)) ?></td></tr><tr><td>completion</td><td class="num"><?= e(fmt_money(0.31)) ?></td></tr></tbody></table>
      </div>
    </div>

    <!-- Badges -->
    <div class="sg-section" id="sg-badges">
      <h2 class="t-h2"><?= _e('design.sg_badges') ?></h2>
      <div class="sg-row">
        <?php foreach (['gold', 'green', 'red', 'blue', 'violet', 'amber', 'muted'] as $v): ?><?= ui_badge('badge-' . $v, $v) ?><?php endforeach; ?>
        <?= ui_badge(__('common.open_weights'), 'green', 'lock') ?>
        <?= ui_status('scored') ?><?= ui_status('judging') ?><?= ui_status('failed') ?><?= ui_status('awaiting_verify') ?>
      </div>
      <div class="sg-row mt-4">
        <button class="chip active" type="button"><?= _e('common.all') ?> <span class="chip-count">12</span></button>
        <button class="chip" type="button"><span class="chip-dot" style="--c:#5FA8F5"></span>chip</button>
        <button class="chip" type="button" aria-pressed="true">aria-pressed</button>
        <span class="pill"><strong>pill</strong> · <?= _e('footer.season', ['s' => setting('season')]) ?></span>
        <span class="pill"><?= ui_live_dot() ?>live-dot</span>
        <?= ui_track_badge($sampleTrack) ?>
      </div>
    </div>

    <!-- Data -->
    <div class="sg-section" id="sg-data">
      <h2 class="t-h2"><?= _e('design.sg_data') ?></h2>
      <div class="sg-row" style="gap:28px;align-items:baseline">
        <?= ui_score(81.37, 79.1, 83.6, 'xl') ?><?= ui_score(81.37, 79.1, 83.6, 'lg') ?><?= ui_score(81.37, 79.1, 83.6, 'md') ?><?= ui_score(81.37, null, null, 'sm') ?><?= ui_score(null) ?>
      </div>
      <div class="grid-2 mt-6">
        <div class="stack"><?= ui_scorebar(72, 100, null, 68, 77) ?><?= ui_scorebar(55, 100, '#5FA8F5') ?><span class="scorebar scorebar-lg"><span class="scorebar-fill" style="width:88%"></span><span class="scorebar-ci" style="inset-inline-start:85%;width:6%"></span></span></div>
        <div class="sg-row"><?= ui_rank(1, 1) ?><?= ui_rank(2, 1) ?><?= ui_rank(3, 3) ?><?= ui_rank(14) ?><?= ui_rank(null) ?> <?= ui_delta(2.4) ?><?= ui_delta(-1.1) ?><?= ui_delta(0) ?> <?= ui_difficulty(1) ?><?= ui_difficulty(4) ?></div>
      </div>
      <div class="kpi-row mt-6">
        <?= ui_stat('1,284', 'stat · kpi-row', 'chart', 'stat-hint', true) ?>
        <?= ui_stat('42', __('home.kpi_models'), 'cpu', '', true) ?>
        <?= ui_stat('95%', __('home.spec_ci'), 'target') ?>
      </div>
      <div class="sg-row mt-6"><?= ui_provider_avatar('aster', 'Aster', 36) ?><?= ui_provider_avatar('openai', 'OpenAI', 28) ?><?= ui_provider_avatar('borealis', 'Borealis', 22) ?><?= ui_model_chip($sampleModel, false) ?></div>
      <div class="legend mt-4"><span class="legend-item" style="--c:var(--gold)">legend-item</span><span class="legend-item is-line" style="--c:#3987E5">is-line</span><span class="legend-item is-off">is-off</span></div>
    </div>

    <!-- Navigation -->
    <div class="sg-section" id="sg-nav">
      <h2 class="t-h2"><?= _e('design.sg_nav') ?></h2>
      <nav class="breadcrumbs" aria-label="breadcrumbs"><a href="<?= e(url('/')) ?>"><?= _e('error.home') ?></a><a href="#sg-nav"><?= _e('nav.leaderboard') ?></a><span aria-current="page">breadcrumbs</span></nav>
      <div data-tabs-root>
        <div class="tabs" data-tabs>
          <button class="tab active" type="button" data-tab="sg-t1">tab 1 <span class="tab-count">3</span></button>
          <button class="tab" type="button" data-tab="sg-t2">tab 2</button>
          <button class="tab" type="button" data-tab="sg-t3">tab 3</button>
        </div>
        <div class="panel mt-4" data-tab-panel="sg-t1">[data-tab-panel="sg-t1"] — <?= _e('home.why_repeat_text') ?></div>
        <div class="panel mt-4" data-tab-panel="sg-t2" hidden>[data-tab-panel="sg-t2"] — <?= _e('home.why_statrank_text') ?></div>
        <div class="panel mt-4" data-tab-panel="sg-t3" hidden>[data-tab-panel="sg-t3"] — <?= _e('home.why_browser_text') ?></div>
      </div>
      <div class="tabs tabs-line mt-6" data-tabs><button class="tab active" type="button" data-tab="x1">tabs-line</button><button class="tab" type="button" data-tab="x2">tab</button></div>
      <?= ui_pagination(3, 9, '/styleguide') ?>
    </div>

    <!-- Feedback -->
    <div class="sg-section" id="sg-feedback">
      <h2 class="t-h2"><?= _e('design.sg_feedback') ?></h2>
      <?php foreach (['success' => 'check-circle', 'error' => 'x-circle', 'warn' => 'alert', 'info' => 'info'] as $k => $ic): ?>
        <div class="alert alert-<?= $k ?>" role="status"><?= ui_icon($ic, 18) ?><div class="flex-1"><div class="alert-title">alert-<?= $k ?></div><?= _e('home.calib_text') ?></div><button class="btn-icon" type="button" data-dismiss aria-label="<?= _e('design.dismiss') ?>"><?= ui_icon('x', 16) ?></button></div>
      <?php endforeach; ?>
      <div class="grid-2 mt-6">
        <?= ui_empty(__('home.board_empty_title'), __('home.board_empty_text'), 'gauge', '<a class="btn btn-outline btn-sm" href="#sg-feedback">' . _e('common.more') . '</a>') ?>
        <div class="stack">
          <span class="skeleton skeleton-text" style="width:60%"></span><span class="skeleton skeleton-text"></span><span class="skeleton" style="height:90px"></span>
          <div class="cluster"><span class="spinner"></span><span class="spinner spinner-lg"></span><span class="t-muted"><?= _e('common.loading') ?></span></div>
          <div class="progress"><span class="progress-bar" style="width:62%"></span></div>
          <div class="progress progress-lg"><span class="progress-bar is-green" style="width:35%"></span></div>
          <div class="progress is-indeterminate"><span class="progress-bar"></span></div>
          <p><span data-tip="[data-tip] tooltip" tabindex="0" class="pill">[data-tip]</span> <span data-tip="bottom" data-tip-pos="bottom" tabindex="0" class="pill">data-tip-pos</span></p>
        </div>
      </div>
      <hr class="divider"><div class="divider-label">divider-label</div>
    </div>

    <!-- Content -->
    <div class="sg-section" id="sg-content">
      <h2 class="t-h2"><?= _e('design.sg_content') ?></h2>
      <div class="grid-2">
        <div class="prose">
          <h2><?= ui_em(__('home.why_title')) ?></h2>
          <p><?= _e('home.why_sub') ?> <a href="#sg-content">.prose a</a> <code>code</code></p>
          <ul><li><?= _e('home.why_panel_title') ?></li><li><?= _e('home.why_repeat_title') ?></li></ul>
          <blockquote><?= ui_em(__('home.cta_title')) ?></blockquote>
        </div>
        <pre class="code-block">// code-block (always LTR)
curl <?= e(url('/api/v1/leaderboard', [], true)) ?>

{ "model": "example", "score": 81.4, "ci": [79.1, 83.6] }</pre>
      </div>
      <div class="render-frame mt-6" data-device="desktop" style="--frame-h:220px">
        <div class="render-toolbar">
          <span class="render-dots" aria-hidden="true"><i></i><i></i><i></i></span>
          <span class="render-url"><?= ui_icon('lock', 13) ?>render-frame · sandbox="allow-scripts allow-forms"</span>
          <span class="render-actions">
            <span class="device-toggle" role="group" aria-label="device-toggle">
              <button type="button" data-device="desktop" aria-pressed="true" aria-label="desktop"><?= ui_icon('monitor', 15) ?></button>
              <button type="button" data-device="tablet" aria-pressed="false" aria-label="tablet"><?= ui_icon('tablet', 15) ?></button>
              <button type="button" data-device="mobile" aria-pressed="false" aria-label="mobile"><?= ui_icon('phone', 15) ?></button>
            </span>
            <button class="btn-icon" type="button" aria-label="open"><?= ui_icon('external', 16) ?></button>
          </span>
        </div>
        <div class="render-stage"><div class="sg-render-sample" aria-hidden="true"><span>&lt;iframe sandbox="allow-scripts allow-forms" src="/render/{id}"&gt;</span></div></div>
      </div>
      <div class="hero-art mt-6" style="max-width:320px;margin-inline:auto" aria-hidden="true"><canvas data-hero-art="quiet"></canvas></div>
      <p class="t-xs t-faint t-center ltr">.hero-art · canvas[data-hero-art] · .reveal</p>
    </div>

    <!-- Charts -->
    <div class="sg-section" id="sg-charts">
      <h2 class="t-h2"><?= _e('design.sg_charts') ?></h2>
      <div class="grid-2">
        <div class="card card-pad"><div class="chart-box is-sm"><canvas id="sg-chart-line" aria-label="WB.chart line" role="img"></canvas></div></div>
        <div class="card card-pad"><div class="chart-box is-sm"><canvas id="sg-chart-bar" aria-label="WB.chart bar" role="img"></canvas></div></div>
      </div>
      <p class="t-xs t-faint mt-2 ltr">WB.chart(canvas, (colors) =&gt; config) · WB.palette(i) · WB.colors()</p>
    </div>

    <!-- Icons -->
    <div class="sg-section" id="sg-icons">
      <h2 class="t-h2"><?= _e('design.sg_icons') ?> <span class="t-faint t-small">(<?= count($icons) ?>)</span></h2>
      <div class="sg-icons">
        <?php foreach ($icons as $ic): ?><div class="sg-icon"><?= ui_icon($ic, 22) ?><span><?= e($ic) ?></span></div><?php endforeach; ?>
      </div>
    </div>

    <!-- Admin -->
    <div class="sg-section" id="sg-admin">
      <h2 class="t-h2"><?= _e('design.sg_admin') ?></h2>
      <div class="admin-shell" style="min-height:0;border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden">
        <div class="admin-sidebar" style="position:static;height:auto">
          <?= ui_brand(28) ?>
          <span class="admin-nav-group">admin-nav-group</span>
          <a class="admin-nav-link active" href="#sg-admin"><?= ui_icon('gauge', 18) ?>admin-nav-link.active</a>
          <a class="admin-nav-link" href="#sg-admin"><?= ui_icon('cpu', 18) ?>admin-nav-link <?= ui_badge('3', 'gold') ?></a>
          <a class="admin-nav-link" href="#sg-admin"><?= ui_icon('settings', 18) ?>admin-nav-link</a>
        </div>
        <div class="admin-main">
          <div class="admin-topbar" style="position:static"><h1>admin-topbar</h1><button class="btn btn-primary btn-sm" type="button"><?= ui_icon('play', 15) ?>btn</button></div>
          <div class="admin-content"><div class="kpi-row"><?= ui_stat('128', 'queued', 'clock') ?><?= ui_stat('$4.21', 'spent', 'coin') ?></div></div>
        </div>
      </div>
    </div>
  </div>
</section>
<script type="application/json" id="sg-chart-data"><?= js_json(['labels' => ['W1', 'W2', 'W3', 'W4', 'W5', 'W6']]) ?></script>
<script<?= nonce_attr() ?>>
document.addEventListener('DOMContentLoaded', function () {
  var WB = window.WB;
  if (!WB) return;
  var pal = document.getElementById('sg-palette');
  for (var i = 0; i < 8; i++) {
    var s = document.createElement('span');
    s.className = 'legend-item';
    s.style.setProperty('--c', WB.palette(i));
    s.textContent = 'WB.palette(' + i + ')';
    pal.appendChild(s);
  }
  document.getElementById('sg-toast').addEventListener('click', function () {
    ['success', 'error', 'warn', 'info'].forEach(function (t, k) { setTimeout(function () { WB.toast('WB.toast(msg, "' + t + '")', t); }, k * 180); });
  });
  var labels = JSON.parse(document.getElementById('sg-chart-data').textContent).labels;
  WB.chart(document.getElementById('sg-chart-line'), function () {
    return { type: 'line', data: { labels: labels, datasets: [0, 1, 2].map(function (k) {
      return { label: 'Series ' + (k + 1), data: labels.map(function (_, j) { return 60 + k * 6 + Math.sin(j + k) * 5 + j; }), borderColor: WB.palette(k), backgroundColor: WB.palette(k) };
    }) }, options: { scales: { y: { min: 50, max: 90 } } } };
  });
  WB.chart(document.getElementById('sg-chart-bar'), function (c) {
    return { type: 'bar', data: { labels: labels, datasets: [{ label: 'Score', data: [72, 68, 81, 77, 64, 70], backgroundColor: c.gold, maxBarThickness: 24 }] }, options: { plugins: { legend: { display: false } }, scales: { y: { min: 0, max: 100 } } } };
  });
});
</script>
