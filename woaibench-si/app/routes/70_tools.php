<?php
/**
 * Tools routes (module: arena-tools): Local Fit Lab, Advisor, public API v1, CSV export, badges,
 * embeddable leaderboard, API docs, sitemap.xml and robots.txt.
 */
declare(strict_types=1);

defined('WB') || exit;

use WB\Data\Api;
use WB\Data\Catalog;
use WB\Data\Leaderboard;

/** Hardware catalogue: the gpus table, or the bundled seed file before the installer has filled it. */
function tools_gpus(): array
{
    $rows = DB::all('SELECT name, vendor, kind, vram_gb, bandwidth_gbs, fp16_tflops, sort FROM gpus ORDER BY sort, id');
    if (!$rows) {
        $rows = json_arr((string) @file_get_contents(WB_ROOT . '/sql/seed/gpus.json'));
    }
    return array_map(static fn($g) => [
        'name' => (string) $g['name'], 'vendor' => (string) $g['vendor'], 'kind' => (string) $g['kind'],
        'vram' => (float) $g['vram_gb'], 'bw' => (float) $g['bandwidth_gbs'],
        'tflops' => isset($g['fp16_tflops']) && $g['fp16_tflops'] !== null ? (float) $g['fp16_tflops'] : null,
    ], $rows);
}

/** Slim leaderboard rows for client-side tools (advisor, fit lab). */
function tools_rows(): array
{
    $out = [];
    foreach (Leaderboard::rows() as $r) {
        $tracks = [];
        foreach ($r['tracks'] as $slug => $t) {
            $tracks[$slug] = [round((float) $t['score'], 2), $t['ci_low'] !== null ? round((float) $t['ci_low'], 2) : null, $t['ci_high'] !== null ? round((float) $t['ci_high'], 2) : null];
        }
        $out[] = [
            'id' => $r['id'], 'slug' => $r['slug'], 'name' => $r['name'], 'provider' => $r['provider_name'] ?: $r['provider'],
            'color' => $r['color'], 'url' => url('/model/' . $r['slug']), 'open' => $r['open_weights'],
            'params' => $r['params_b'], 'active' => $r['active_params_b'], 'ctx' => $r['context_length'],
            'vision' => $r['supports_vision'], 'score' => $r['score'] !== null ? round($r['score'], 2) : null,
            'lo' => $r['ci_low'], 'hi' => $r['ci_high'], 'rank' => $r['rank_pos'], 'prov' => $r['provisional'],
            'cost' => $r['avg_cost'], 'lat' => $r['avg_latency_ms'], 'tracks' => (object) $tracks,
        ];
    }
    return $out;
}

/** Track list for client-side tools. */
function tools_tracks(): array
{
    return array_values(array_map(static fn($t) => [
        'slug' => $t['slug'], 'name' => L($t, 'name'), 'color' => $t['color'], 'icon' => $t['icon'],
    ], Catalog::tracks()));
}

Router::get('/hardware', static function (): void {
    $scores = [];
    foreach (Leaderboard::rows() as $r) {
        $scores[(int) $r['id']] = $r;
    }
    $models = [];
    $rows = DB::all(
        'SELECT id, slug, name, provider, provider_name, params_b, active_params_b, n_layers, kv_dim, context_length, color
           FROM models WHERE is_active = 1 AND open_weights = 1 AND params_b IS NOT NULL AND params_b > 0
          ORDER BY params_b, name'
    );
    foreach ($rows as $m) {
        $s = $scores[(int) $m['id']] ?? null;
        $models[] = [
            'slug' => $m['slug'], 'name' => $m['name'], 'provider' => $m['provider_name'] ?: $m['provider'],
            'color' => $m['color'] ?: ui_provider_color((string) $m['provider']), 'url' => url('/model/' . $m['slug']),
            'params' => (float) $m['params_b'], 'active' => $m['active_params_b'] !== null ? (float) $m['active_params_b'] : null,
            'layers' => $m['n_layers'] !== null ? (int) $m['n_layers'] : null, 'kv' => $m['kv_dim'] !== null ? (int) $m['kv_dim'] : null,
            'ctx' => $m['context_length'] !== null ? (int) $m['context_length'] : null,
            'score' => $s && $s['score'] !== null ? round($s['score'], 2) : null, 'lo' => $s['ci_low'] ?? null, 'hi' => $s['ci_high'] ?? null,
            'rank' => $s['rank_pos'] ?? null, 'prov' => $s['provisional'] ?? null,
        ];
    }
    view('pages/hardware', [
        'title' => __('tools.hw_title'),
        'description' => __('tools.hw_meta_description'),
        'nav_active' => 'hardware',
        'page_css' => ['css/tools.css'],
        'page_js' => ['js/hardware.js'],
        'models' => $models,
        'gpus' => tools_gpus(),
        'json_ld' => ['@context' => 'https://schema.org', '@type' => 'WebApplication', 'name' => __('tools.hw_title'),
            'applicationCategory' => 'DeveloperApplication', 'operatingSystem' => 'Any', 'url' => url('/hardware', [], true),
            'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'USD'], 'description' => __('tools.hw_meta_description')],
    ]);
});

Router::get('/advisor', static function (): void {
    view('pages/advisor', [
        'title' => __('tools.adv_title'),
        'description' => __('tools.adv_meta_description'),
        'nav_active' => 'advisor',
        'page_css' => ['css/tools.css'],
        'page_js' => ['js/advisor.js'],
        'rows' => tools_rows(),
        'tracks' => tools_tracks(),
        'json_ld' => ['@context' => 'https://schema.org', '@type' => 'WebApplication', 'name' => __('tools.adv_title'),
            'applicationCategory' => 'DeveloperApplication', 'operatingSystem' => 'Any', 'url' => url('/advisor', [], true),
            'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'USD'], 'description' => __('tools.adv_meta_description')],
    ]);
});

Router::get('/api-docs', static function (): void {
    $rows = Leaderboard::rows();
    $badgeSlug = (string) input('badge', '');
    $badgeRow = null;
    foreach ($rows as $r) {
        if ($r['slug'] === $badgeSlug || ($badgeSlug === '' && $badgeRow === null)) {
            $badgeRow = $r;
        }
    }
    $style = in_array(input('style'), ['flat', 'dark', 'light'], true) ? (string) input('style') : 'flat';
    $season = Catalog::season();
    $sample = static fn(mixed $data, array $meta = []) => json_encode(Api::envelope($data, $season, $meta), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    $lb = array_slice(Api::leaderboard($season), 0, 1);
    $firstSlug = $rows[0]['slug'] ?? 'model-slug';
    $model = $rows ? Api::model($firstSlug, $season) : null;
    if ($model) {
        $model['history'] = array_slice($model['history'], -2);
        $model['tracks'] = array_slice($model['tracks'], 0, 2);
    }
    $arena = Api::arena();
    view('pages/api-docs', [
        'title' => __('tools.api_title'),
        'description' => __('tools.api_meta_description'),
        'nav_active' => '',
        'page_css' => ['css/tools.css'],
        'api_on' => (bool) (int) setting('api_public', 1),
        'season' => $season,
        'rows' => $rows,
        'first_slug' => $firstSlug,
        'badge_row' => $badgeRow,
        'badge_style' => $style,
        'tracks' => Catalog::tracks(),
        'samples' => [
            'leaderboard' => $sample($lb),
            'models' => $sample(array_slice(Api::models(), 0, 1)),
            'model' => $sample($model),
            'tracks' => $sample(array_slice(Api::tracks(), 0, 1)),
            'tasks' => $sample(array_slice(Api::tasks(), 0, 1), ['holdout_tasks' => Catalog::taskCounts()['holdout']]),
            'arena' => $sample(array_slice($arena['rows'], 0, 1), ['spearman' => $arena['spearman']]),
        ],
        'json_ld' => ['@context' => 'https://schema.org', '@type' => 'Dataset', 'name' => 'WOAIBench.si leaderboard',
            'description' => __('tools.api_meta_description'), 'url' => url('/api-docs', [], true),
            'license' => Api::LICENSE_URL, 'isAccessibleForFree' => true,
            'creator' => ['@type' => 'Organization', 'name' => (string) setting('site_name'), 'url' => url('/', [], true)],
            'temporalCoverage' => $season,
            'distribution' => [
                ['@type' => 'DataDownload', 'encodingFormat' => 'text/csv', 'contentUrl' => url('/export/leaderboard.csv', [], true)],
                ['@type' => 'DataDownload', 'encodingFormat' => 'application/json', 'contentUrl' => url('/api/v1/leaderboard', [], true)],
            ]],
    ]);
});

/* ------------------------------------------------------------------ public API v1 */

Router::add(['OPTIONS'], '/api/v1/{rest:.*}', static function (): void {
    Api::guard();
});

Router::get('/api/v1/leaderboard', static function (): void {
    Api::guard();
    $season = Api::season(is_string(input('season')) ? (string) input('season') : null);
    if ($season === null) {
        Api::error(404, 'unknown_season', 'Unknown season. See meta.seasons on /api/v1/tracks.');
    }
    $track = is_string(input('track')) && input('track') !== '' ? (string) input('track') : null;
    if ($track !== null && !Catalog::track($track)) {
        Api::error(404, 'unknown_track', 'Unknown track slug. See /api/v1/tracks.');
    }
    $inc = input('include_provisional');
    $include = $inc === null || !in_array(strtolower((string) $inc), ['0', 'false', 'no'], true);
    Api::send(Api::envelope(Api::leaderboard($season, $track, $include), $season, ['track' => $track, 'include_provisional' => $include, 'seasons' => Catalog::seasons()]));
});

Router::get('/api/v1/models', static function (): void {
    Api::guard();
    Api::send(Api::envelope(Api::models(), Catalog::season()));
});

Router::get('/api/v1/models/{slug:[A-Za-z0-9._\-]+}', static function (string $slug): void {
    Api::guard();
    $season = Api::season(is_string(input('season')) ? (string) input('season') : null);
    if ($season === null) {
        Api::error(404, 'unknown_season', 'Unknown season.');
    }
    $m = Api::model($slug, $season);
    if (!$m) {
        Api::error(404, 'not_found', 'Unknown model slug. See /api/v1/models.');
    }
    Api::send(Api::envelope($m, $season));
});

Router::get('/api/v1/tracks', static function (): void {
    Api::guard();
    Api::send(Api::envelope(Api::tracks(), Catalog::season(), ['seasons' => Catalog::seasons()]));
});

Router::get('/api/v1/tasks', static function (): void {
    Api::guard();
    Api::send(Api::envelope(Api::tasks(), Catalog::season(), ['holdout_tasks' => Catalog::taskCounts()['holdout']]));
});

Router::get('/api/v1/arena', static function (): void {
    Api::guard();
    $a = Api::arena();
    $s = WB\Arena\Arena::stats();
    Api::send(Api::envelope($a['rows'], null, [
        'spearman_vs_benchmark' => $a['spearman'], 'votes' => $s['votes'], 'last_recompute' => $s['last_recompute'],
        'method' => 'Bradley-Terry (MM), ties = half win, 95% CI from 200 bootstrap resamples',
    ]));
});

Router::get('/api/v1', static function (): void {
    Api::guard();
    Api::send(Api::envelope([
        'endpoints' => array_map(static fn($p) => url($p, [], true), ['/api/v1/leaderboard', '/api/v1/models', '/api/v1/models/{slug}', '/api/v1/tracks', '/api/v1/tasks', '/api/v1/arena']),
    ], Catalog::season()));
});

Router::get('/api/v1/{rest:.*}', static function (): void {
    Api::guard();
    Api::error(404, 'not_found', 'Unknown endpoint. See ' . url('/api-docs', [], true));
});

/* ------------------------------------------------------------------ CSV, badges, embed */

Router::get('/export/leaderboard.csv', static function (): void {
    Api::stateless();
    $season = Api::season(is_string(input('season')) ? (string) input('season') : null) ?? Catalog::season();
    $csv = Api::csv($season);
    $etag = '"' . substr(hash('sha256', $csv), 0, 32) . '"';
    Api::cacheHeaders($etag);
    header('Access-Control-Allow-Origin: *');
    if (Api::notModified($etag)) {
        http_response_code(304);
        exit;
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="woaibench-leaderboard-' . preg_replace('/[^0-9A-Za-z._-]/', '', $season) . '.csv"');
    header('Content-Length: ' . strlen($csv));
    echo $csv;
});

Router::get('/badge/{slug:[A-Za-z0-9._\-]+}\.svg', static function (string $slug): void {
    Api::stateless();
    $style = in_array(input('style'), ['flat', 'dark', 'light'], true) ? (string) input('style') : 'flat';
    $row = Leaderboard::row($slug);
    $svg = Api::badge($row, $style);
    $etag = '"' . substr(hash('sha256', $svg), 0, 32) . '"';
    header_remove('Content-Security-Policy');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
    header('Cross-Origin-Resource-Policy: cross-origin');
    Api::cacheHeaders($etag, $row ? 1800 : 300);
    if (Api::notModified($etag)) {
        http_response_code(304);
        exit;
    }
    http_response_code($row ? 200 : 404);
    header('Content-Type: image/svg+xml; charset=utf-8');
    echo $svg;
});

Router::get('/embed/leaderboard', static function (): void {
    Api::stateless();
    $n = input_int('n', 10, 5, 20);
    $theme = input('theme') === 'light' ? 'light' : 'dark';
    $rows = array_slice(Leaderboard::rows(null, ['include_provisional' => false]), 0, $n);
    header_remove('X-Frame-Options');
    header_remove('Cross-Origin-Opener-Policy');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; font-src 'self' data:; script-src 'none'; connect-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors *");
    header('Cache-Control: public, max-age=' . Api::MAX_AGE);
    header('Vary: Accept-Language, Cookie');
    view('pages/embed-leaderboard', ['rows' => $rows, 'n' => $n, 'theme' => $theme, 'season' => Catalog::season()], null);
});

/* ------------------------------------------------------------------ SEO */

Router::get('/sitemap.xml', static function (): void {
    Api::stateless();
    $static = ['/', '/leaderboard', '/arena', '/arena/leaderboard', '/compare', '/tasks', '/value', '/hardware', '/advisor', '/methodology', '/changelog', '/about', '/request', '/api-docs'];
    $registered = Router::patterns();
    $urls = [];
    foreach ($static as $p) {
        if (in_array($p, $registered, true)) {
            $urls[] = [$p, null, $p === '/' ? '1.0' : '0.8'];
        }
    }
    if (preg_grep('#^/model/\{slug#', $registered)) {
        $models = DB::all(
            "SELECT m.slug, MAX(s.updated_at) AS lastmod FROM models m JOIN model_scores s ON s.model_id = m.id
              WHERE m.is_active = 1 GROUP BY m.id, m.slug ORDER BY m.slug"
        );
        foreach ($models as $m) {
            $urls[] = ['/model/' . $m['slug'], $m['lastmod'], '0.7'];
        }
    }
    if (preg_grep('#^/task/\{slug#', $registered)) {
        foreach (DB::all("SELECT slug, updated_at FROM tasks WHERE visibility = 'public' AND is_active = 1 ORDER BY slug") as $t) {
            $urls[] = ['/task/' . $t['slug'], $t['updated_at'], '0.5'];
        }
    }
    $x = static fn(string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";
    foreach ($urls as [$path, $lastmod, $prio]) {
        $out .= '  <url><loc>' . $x(url($path, [], true)) . '</loc>';
        foreach (['ar', 'en'] as $l) {
            $out .= '<xhtml:link rel="alternate" hreflang="' . $l . '" href="' . $x(url($path, ['lang' => $l], true)) . '"/>';
        }
        $out .= '<xhtml:link rel="alternate" hreflang="x-default" href="' . $x(url($path, [], true)) . '"/>';
        if ($lastmod) {
            $out .= '<lastmod>' . gmdate('Y-m-d', (int) strtotime($lastmod . ' UTC')) . '</lastmod>';
        }
        $out .= '<priority>' . $prio . '</priority></url>' . "\n";
    }
    $out .= '</urlset>' . "\n";
    header('Content-Type: application/xml; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    echo $out;
});

Router::get('/robots.txt', static function (): void {
    Api::stateless();
    $b = base_path();
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: public, max-age=86400');
    echo "User-agent: *\n";
    foreach (['/admin', '/render', '/arena/frame', '/api', '/install'] as $p) {
        echo 'Disallow: ' . $b . $p . "\n";
    }
    echo 'Allow: ' . ($b ?: '') . "/\n\n";
    echo 'Sitemap: ' . url('/sitemap.xml', [], true) . "\n";
});
