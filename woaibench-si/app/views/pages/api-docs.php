<?php
/**
 * Public API documentation. Vars: $api_on, $season, $rows (leaderboard rows), $first_slug, $badge_row,
 * $badge_style, $tracks, $samples (pretty JSON strings per endpoint).
 */
defined('WB') || exit;

$base = url('/api/v1', [], true);
$firstTrack = (string) (array_key_first($tracks) ?? 'ui');
$endpoints = [
    ['leaderboard', '/api/v1/leaderboard', 'tools.api_ep_leaderboard', [
        ['track', __('tools.api_p_track', ['ex' => $firstTrack])],
        ['season', __('tools.api_p_season', ['s' => $season])],
        ['include_provisional', __('tools.api_p_prov')],
    ], '/api/v1/leaderboard?track=' . $firstTrack],
    ['models', '/api/v1/models', 'tools.api_ep_models', [], '/api/v1/models'],
    ['model', '/api/v1/models/{slug}', 'tools.api_ep_model', [['season', __('tools.api_p_season', ['s' => $season])]], '/api/v1/models/' . $first_slug],
    ['tracks', '/api/v1/tracks', 'tools.api_ep_tracks', [], '/api/v1/tracks'],
    ['tasks', '/api/v1/tasks', 'tools.api_ep_tasks', [], '/api/v1/tasks'],
    ['arena', '/api/v1/arena', 'tools.api_ep_arena', [], '/api/v1/arena'],
];
$badgeSlug = $badge_row['slug'] ?? $first_slug;
$badgeUrl = url('/badge/' . $badgeSlug . '.svg', $badge_style !== 'flat' ? ['style' => $badge_style] : [], true);
$modelUrl = url('/model/' . $badgeSlug, [], true);
$badgeMd = '[![WOAIBench](' . $badgeUrl . ')](' . $modelUrl . ')';
$badgeHtml = '<a href="' . $modelUrl . '"><img src="' . $badgeUrl . '" alt="WOAIBench score" height="20"></a>';
$embedUrl = url('/embed/leaderboard', ['n' => 10, 'theme' => 'dark', 'lang' => lang()], true);
$embedHtml = '<iframe src="' . $embedUrl . '" title="WOAIBench leaderboard" width="100%" height="560" style="border:0;border-radius:16px;max-width:520px" loading="lazy"></iframe>';
$csvCols = array_merge(WB\Data\Api::csvColumns(), ['track_<slug>']);
?>
<section class="section tools-hero">
  <div class="container-wide">
    <div class="tools-hero-grid">
      <div class="stack">
        <span class="eyebrow"><?= ui_icon('api', 16) ?> <?= _e('tools.api_eyebrow') ?></span>
        <h1 class="t-display t-balance"><?= _e('tools.api_headline') ?></h1>
        <p class="t-lead"><?= _e('tools.api_lead') ?></p>
        <div class="cluster">
          <a class="btn btn-primary" href="<?= e(url('/api/v1/leaderboard')) ?>"><?= ui_icon('code', 16) ?><span><?= _e('tools.api_try') ?></span></a>
          <a class="btn btn-ghost" href="<?= e(url('/export/leaderboard.csv')) ?>"><?= ui_icon('download', 16) ?><span><?= _e('tools.api_csv') ?></span></a>
        </div>
      </div>
      <dl class="api-facts">
        <div><dt><?= _e('tools.api_fact_base') ?></dt><dd><code class="ltr"><?= e($base) ?></code></dd></div>
        <div><dt><?= _e('tools.api_fact_auth') ?></dt><dd><?= _e('tools.api_fact_auth_v') ?></dd></div>
        <div><dt><?= _e('tools.api_fact_rate') ?></dt><dd><?= _e('tools.api_fact_rate_v') ?></dd></div>
        <div><dt><?= _e('tools.api_fact_cache') ?></dt><dd><?= _e('tools.api_fact_cache_v') ?></dd></div>
        <div><dt><?= _e('tools.api_fact_license') ?></dt><dd><a href="<?= e(WB\Data\Api::LICENSE_URL) ?>" rel="license noopener" target="_blank">CC BY 4.0</a> · <?= _e('tools.api_fact_license_v') ?></dd></div>
      </dl>
    </div>
<?php if (!$api_on): ?>
    <div class="alert alert-warn api-off" role="status"><?= _e('tools.api_disabled') ?></div>
<?php endif; ?>
  </div>
</section>

<section class="section tools-body">
  <div class="container-wide api-layout">
    <nav class="api-toc card card-pad" aria-label="<?= _e('tools.api_toc') ?>">
      <span class="eyebrow"><?= _e('tools.api_toc') ?></span>
      <a href="#envelope"><?= _e('tools.api_envelope') ?></a>
<?php foreach ($endpoints as [$id, $path]): ?>
      <a href="#ep-<?= e($id) ?>" class="t-mono ltr"><?= e(str_replace('/api/v1', '', $path)) ?></a>
<?php endforeach; ?>
      <a href="#csv"><?= _e('tools.api_csv_title') ?></a>
      <a href="#badges"><?= _e('tools.api_badges') ?></a>
      <a href="#embed"><?= _e('tools.api_embed') ?></a>
      <a href="#errors"><?= _e('tools.api_errors') ?></a>
    </nav>

    <div class="api-main stack-lg">
      <article class="card card-pad api-block stack" id="envelope">
        <h2 class="t-h3"><?= _e('tools.api_envelope') ?></h2>
        <p class="t-muted"><?= _e('tools.api_envelope_text') ?></p>
        <pre class="code-block ltr" dir="ltr" tabindex="0"><code>{
  "data": …,
  "meta": {
    "season": "<?= e($season) ?>",
    "generated_at": "2026-10-15T08:00:00Z",
    "version": "v1",
    "license": "CC BY 4.0",
    "license_url": "https://creativecommons.org/licenses/by/4.0/",
    "attribution": "WOAIBench.si — <?= e(url('/', [], true)) ?>",
    "docs": "<?= e(url('/api-docs', [], true)) ?>"
  }
}</code></pre>
        <p class="t-small t-muted"><?= _e('tools.api_fields_note') ?></p>
        <p class="t-small t-muted"><?= _e('tools.api_fields_rows') ?></p>
      </article>

<?php foreach ($endpoints as [$id, $path, $descKey, $params, $example]):
    $curl = 'curl -s "' . url($example, [], true) . '"'; ?>
      <article class="card card-pad api-block stack" id="ep-<?= e($id) ?>">
        <div class="api-ep-head">
          <span class="badge badge-green api-method">GET</span>
          <code class="api-path ltr" dir="ltr"><?= e($path) ?></code>
          <a class="btn btn-ghost btn-sm api-open" href="<?= e(url($example)) ?>" target="_blank" rel="noopener"><?= ui_icon('external', 14) ?><span><?= _e('tools.api_open') ?></span></a>
        </div>
        <p class="t-muted"><?= _e($descKey) ?></p>
<?php if ($params): ?>
        <div class="table-wrap">
          <table class="table table-compact api-params">
            <thead><tr><th scope="col"><?= _e('tools.api_param') ?></th><th scope="col"><?= _e('tools.api_desc') ?></th></tr></thead>
            <tbody>
<?php foreach ($params as [$name, $desc]): ?>
              <tr><td><code class="ltr"><?= e($name) ?></code></td><td class="t-small"><?= e($desc) ?></td></tr>
<?php endforeach; ?>
            </tbody>
          </table>
        </div>
<?php endif; ?>
        <div class="api-snippet">
          <div class="api-snippet-head"><span class="t-xs t-muted">curl</span><button class="btn btn-ghost btn-sm" type="button" data-copy="<?= e($curl) ?>"><?= ui_icon('copy', 14) ?><span><?= _e('common.copy') ?></span></button></div>
          <pre class="code-block ltr" dir="ltr" tabindex="0"><code><?= e($curl) ?></code></pre>
        </div>
        <details class="api-sample">
          <summary><?= _e('tools.api_sample') ?></summary>
          <pre class="code-block ltr api-json" dir="ltr" tabindex="0"><code><?= e((string) $samples[$id]) ?></code></pre>
        </details>
      </article>
<?php endforeach; ?>

      <article class="card card-pad api-block stack" id="csv">
        <div class="api-ep-head">
          <span class="badge badge-green api-method">GET</span>
          <code class="api-path ltr" dir="ltr">/export/leaderboard.csv</code>
          <a class="btn btn-ghost btn-sm api-open" href="<?= e(url('/export/leaderboard.csv')) ?>"><?= ui_icon('download', 14) ?><span><?= _e('common.download') ?></span></a>
        </div>
        <p class="t-muted"><?= _e('tools.api_csv_text') ?></p>
        <div class="api-cols">
<?php foreach ($csvCols as $c): ?>
          <code class="ltr"><?= e($c) ?></code>
<?php endforeach; ?>
        </div>
        <p class="t-small t-muted"><?= _e('tools.api_csv_cols') ?></p>
      </article>

      <article class="card card-pad api-block stack" id="badges">
        <h2 class="t-h3"><?= _e('tools.api_badges') ?></h2>
        <p class="t-muted"><?= _e('tools.api_badges_text') ?></p>
        <form class="form-row api-badge-form" method="get" action="<?= e(url('/api-docs')) ?>#badges">
          <div class="field">
            <label class="label" for="badge-model"><?= _e('common.model') ?></label>
            <select class="select" id="badge-model" name="badge" data-autosubmit>
<?php foreach ($rows as $r): ?>
              <option value="<?= e($r['slug']) ?>"<?= $r['slug'] === $badgeSlug ? ' selected' : '' ?>><?= e($r['name']) ?></option>
<?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label class="label" for="badge-style"><?= _e('tools.api_badge_style') ?></label>
            <select class="select" id="badge-style" name="style" data-autosubmit>
<?php foreach (['flat', 'dark', 'light'] as $s): ?>
              <option value="<?= $s ?>"<?= $s === $badge_style ? ' selected' : '' ?>><?= _e('tools.api_style_' . $s) ?></option>
<?php endforeach; ?>
            </select>
          </div>
          <noscript><button class="btn btn-outline btn-sm" type="submit"><?= _e('tools.api_update') ?></button></noscript>
        </form>
        <div class="api-badge-preview">
<?php foreach (['flat', 'dark', 'light'] as $s): ?>
          <figure class="api-badge-fig<?= $s === $badge_style ? ' is-active' : '' ?> api-badge-<?= $s ?>">
            <img src="<?= e(url('/badge/' . $badgeSlug . '.svg', $s !== 'flat' ? ['style' => $s] : [])) ?>" alt="<?= e('WOAIBench · ' . ($badge_row['name'] ?? '')) ?>" height="20">
            <figcaption class="t-xs t-muted"><?= _e('tools.api_style_' . $s) ?></figcaption>
          </figure>
<?php endforeach; ?>
        </div>
        <div class="api-snippet">
          <div class="api-snippet-head"><span class="t-xs t-muted">Markdown</span><button class="btn btn-ghost btn-sm" type="button" data-copy="<?= e($badgeMd) ?>"><?= ui_icon('copy', 14) ?><span><?= _e('common.copy') ?></span></button></div>
          <pre class="code-block ltr" dir="ltr" tabindex="0"><code><?= e($badgeMd) ?></code></pre>
        </div>
        <div class="api-snippet">
          <div class="api-snippet-head"><span class="t-xs t-muted">HTML</span><button class="btn btn-ghost btn-sm" type="button" data-copy="<?= e($badgeHtml) ?>"><?= ui_icon('copy', 14) ?><span><?= _e('common.copy') ?></span></button></div>
          <pre class="code-block ltr" dir="ltr" tabindex="0"><code><?= e($badgeHtml) ?></code></pre>
        </div>
        <p class="t-small t-muted"><?= _e('tools.api_badge_note') ?></p>
      </article>

      <article class="card card-pad api-block stack" id="embed">
        <h2 class="t-h3"><?= _e('tools.api_embed') ?></h2>
        <p class="t-muted"><?= _e('tools.api_embed_text') ?></p>
        <div class="api-embed-grid">
          <iframe class="api-embed-frame" src="<?= e(url('/embed/leaderboard', ['n' => 5, 'theme' => 'dark', 'lang' => lang()])) ?>" title="<?= _e('tools.api_embed_preview') ?>" loading="lazy"></iframe>
          <div class="stack-sm">
            <div class="api-snippet">
              <div class="api-snippet-head"><span class="t-xs t-muted">HTML</span><button class="btn btn-ghost btn-sm" type="button" data-copy="<?= e($embedHtml) ?>"><?= ui_icon('copy', 14) ?><span><?= _e('common.copy') ?></span></button></div>
              <pre class="code-block ltr is-wrap" dir="ltr" tabindex="0"><code><?= e($embedHtml) ?></code></pre>
            </div>
            <div class="table-wrap">
              <table class="table table-compact api-params">
                <thead><tr><th scope="col"><?= _e('tools.api_param') ?></th><th scope="col"><?= _e('tools.api_desc') ?></th></tr></thead>
                <tbody>
                  <tr><td><code class="ltr">n</code></td><td class="t-small"><?= _e('tools.api_embed_n') ?></td></tr>
                  <tr><td><code class="ltr">theme</code></td><td class="t-small"><?= _e('tools.api_embed_theme') ?></td></tr>
                  <tr><td><code class="ltr">lang</code></td><td class="t-small"><?= _e('tools.api_embed_lang') ?></td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </article>

      <article class="card card-pad api-block stack" id="errors">
        <h2 class="t-h3"><?= _e('tools.api_errors') ?></h2>
        <div class="table-wrap">
          <table class="table table-compact api-params">
            <thead><tr><th scope="col">HTTP</th><th scope="col"><code class="ltr">error.code</code></th><th scope="col"><?= _e('tools.api_desc') ?></th></tr></thead>
            <tbody>
              <tr><td class="t-num">304</td><td>—</td><td class="t-small"><?= _e('tools.api_err_304') ?></td></tr>
              <tr><td class="t-num">404</td><td><code class="ltr">not_found · unknown_track · unknown_season · api_disabled</code></td><td class="t-small"><?= _e('tools.api_err_404') ?></td></tr>
              <tr><td class="t-num">429</td><td><code class="ltr">rate_limited</code></td><td class="t-small"><?= _e('tools.api_err_429') ?></td></tr>
            </tbody>
          </table>
        </div>
        <pre class="code-block ltr" dir="ltr" tabindex="0"><code>{"error": {"code": "rate_limited", "message": "Too many requests: the limit is 120 per minute."}, "meta": {…}}</code></pre>
      </article>
    </div>
  </div>
</section>
