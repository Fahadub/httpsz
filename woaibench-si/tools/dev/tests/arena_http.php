<?php
/**
 * DEV ONLY — live HTTP tests for the arena-tools module. Included by arena_test.php --http=<base>.
 * Requires the dev server to run against the same local database as this CLI process (WB_DB_NAME).
 */
declare(strict_types=1);

defined('WB') || exit;

/** Minimal cURL client with a cookie jar per "browser". Returns [status, headers(lowercase), body]. */
function http_req(string $method, string $url, array $opt = []): array
{
    $ch = curl_init($url);
    $headers = [];
    $hdr = $opt['headers'] ?? [];
    $hdr[] = 'User-Agent: ' . ($opt['ua'] ?? 'WBTest/1.0');
    if (isset($opt['json'])) {
        $hdr[] = 'Content-Type: application/json';
        $hdr[] = 'Accept: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opt['json']));
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $hdr,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_NOBODY => $method === 'HEAD',
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
            $p = explode(':', $line, 2);
            if (count($p) === 2) {
                $k = strtolower(trim($p[0]));
                $headers[$k] = isset($headers[$k]) ? $headers[$k] . "\n" . trim($p[1]) : trim($p[1]);
            }
            return strlen($line);
        },
    ]);
    if (!empty($opt['jar'])) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $opt['jar']);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $opt['jar']);
    }
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$status, $headers, $body];
}

/** Open /arena like a browser: returns [csrf, pair|null]. */
function arena_open(string $base, string $jar, string $ua): array
{
    [, , $html] = http_req('GET', $base . '/arena?lang=en', ['jar' => $jar, 'ua' => $ua]);
    preg_match('/<meta name="csrf-token" content="([a-f0-9]+)"/', $html, $m);
    preg_match('#<script type="application/json" id="arn-data">(.*?)</script>#s', $html, $d);
    $data = json_decode($d[1] ?? 'null', true);
    return [$m[1] ?? '', $data['pair'] ?? null];
}

function arena_vote(string $base, string $jar, string $ua, string $csrf, string $token, string $winner): array
{
    return http_req('POST', $base . '/arena/vote', [
        'jar' => $jar, 'ua' => $ua, 'json' => ['token' => $token, 'winner' => $winner],
        'headers' => $csrf !== '' ? ['X-CSRF-Token: ' . $csrf] : [],
    ]);
}

function arena_http_tests(string $base): void
{
    $tmp = sys_get_temp_dir() . '/wb_arena_test_' . getmypid();
    @mkdir($tmp);
    $jar = $tmp . '/jar1.txt';
    $ua = 'WBTest/arena-' . random_token(4);

    echo "\nArena HTTP flow ({$base})\n";
    Settings::set('arena_enabled', 1);
    Settings::set('arena_votes_per_hour', 60);
    [$csrf, $pair] = arena_open($base, $jar, $ua);
    check($csrf !== '' && is_array($pair) && preg_match('/^[a-f0-9]{32}$/', (string) ($pair['token'] ?? '')) === 1, '/arena renders a pair with a 32-hex token');
    if (!$pair) {
        return;
    }
    $row = DB::one('SELECT * FROM arena_pairs WHERE token = ?', [$pair['token']]);
    $ma = (int) DB::val('SELECT model_id FROM runs WHERE id = ?', [(int) $row['run_a']]);
    $mb = (int) DB::val('SELECT model_id FROM runs WHERE id = ?', [(int) $row['run_b']]);
    check($ma !== $mb, 'pair never matches a model with itself');
    $html = http_req('GET', $base . '/arena?lang=en', ['jar' => $jar, 'ua' => $ua])[2];
    $names = DB::col('SELECT name FROM models WHERE id IN (?, ?)', [$ma, $mb]);
    check(!str_contains(json_encode($pair), (string) $names[0]) && !str_contains(json_encode($pair), (string) $names[1]), 'pair payload does not leak model names');
    check(!preg_match('#/run/\d+|/render/\d+#', $html), 'arena page exposes no run/render ids');

    // Frames
    [$st, $h, $b] = http_req('GET', $pair['frames']['a'] ? $base . $pair['frames']['a'] : '', ['ua' => $ua]);
    check($st === 200 && str_contains($h['content-security-policy'] ?? '', 'sandbox'), 'frame A served with a sandbox CSP', "status {$st}");
    [$st] = http_req('GET', $base . '/arena/frame/' . str_repeat('0', 32) . '/a', ['ua' => $ua]);
    check($st === 404, 'unknown frame token → 404');

    // CSRF missing → 419
    [$st] = arena_vote($base, $jar, $ua, '', $pair['token'], 'a');
    check($st === 419, 'vote without CSRF token → 419', "status {$st}");
    [$st] = arena_vote($base, $jar, $ua, $csrf, $pair['token'], 'maybe');
    check($st === 422, 'invalid winner → 422', "status {$st}");
    // Foreign voter (different UA → different voter hash) → 403
    [$st] = arena_vote($base, $jar, $ua . '-other', $csrf, $pair['token'], 'a');
    check($st === 403, 'token from another voter → 403', "status {$st}");

    Settings::set('arena_last_recompute', '');
    $before = (int) DB::val('SELECT COUNT(*) FROM arena_votes');
    [$st, $h, $b] = arena_vote($base, $jar, $ua, $csrf, $pair['token'], 'a');
    $res = json_decode($b, true);
    check($st === 200 && ($res['ok'] ?? false) === true, 'valid vote → 200', "status {$st} {$b}");
    check(isset($res['reveal']['a']['name'], $res['reveal']['b']['name']) && in_array($res['reveal']['a']['name'], $names, true), 'response reveals both models');
    check((int) DB::val('SELECT COUNT(*) FROM arena_votes') === $before + 1, 'vote stored');
    $vote = DB::one('SELECT * FROM arena_votes ORDER BY id DESC LIMIT 1');
    check((int) $vote['model_a'] === $ma && $vote['winner'] === 'a', 'stored vote maps side A to the right model');
    [$st] = arena_vote($base, $jar, $ua, $csrf, $pair['token'], 'b');
    check($st === 409, 'reused token → 409', "status {$st}");
    usleep(300000);
    Settings::flush();
    check((int) setting('arena_votes_at_recompute', -1) === $before + 1, 'vote endpoint triggered the rating recompute');
    $rated = DB::one('SELECT votes FROM arena_ratings WHERE model_id = ?', [$ma]);
    check($rated !== null && (int) $rated['votes'] >= 1, 'arena_ratings rewritten from real votes');

    // Expired token → 410
    [, , $nb] = http_req('GET', $base . '/arena/next', ['jar' => $jar, 'ua' => $ua, 'headers' => ['Accept: application/json']]);
    $next = json_decode($nb, true);
    check(($next['ok'] ?? false) && isset($next['pair']['token']), 'GET /arena/next returns a new pair as JSON');
    DB::exec('UPDATE arena_pairs SET created_at = ? WHERE token = ?', [now(-7200), $next['pair']['token']]);
    [$st] = arena_vote($base, $jar, $ua, $csrf, $next['pair']['token'], 'tie');
    check($st === 410, 'token older than one hour → 410', "status {$st}");

    // Rate limit: 3 votes/hour for a fresh voter.
    Settings::set('arena_votes_per_hour', 3);
    $ua2 = 'WBTest/rl-' . random_token(4);
    $jar2 = $tmp . '/jar2.txt';
    [$csrf2] = arena_open($base, $jar2, $ua2);
    $codes = [];
    for ($i = 0; $i < 4; $i++) {
        [, , $nb] = http_req('GET', $base . '/arena/next', ['jar' => $jar2, 'ua' => $ua2, 'headers' => ['Accept: application/json']]);
        $p = json_decode($nb, true)['pair']['token'] ?? '';
        [$st, $h] = arena_vote($base, $jar2, $ua2, $csrf2, $p, ['a', 'b', 'tie', 'both_bad'][$i]);
        $codes[] = $st;
    }
    check($codes === [200, 200, 200, 429], 'rate limit: 4th vote in the hour → 429', implode(',', $codes));
    Settings::set('arena_votes_per_hour', 60);

    // Disabled arena
    Settings::set('arena_enabled', 0);
    [$st, , $b] = http_req('GET', $base . '/arena?lang=en', ['ua' => $ua]);
    check($st === 200 && str_contains($b, 'empty-state') && !str_contains($b, 'arn-stage"'), 'disabled arena renders the paused state');
    [$st] = http_req('GET', $base . '/arena/next', ['ua' => $ua, 'headers' => ['Accept: application/json']]);
    check($st === 503, 'disabled arena: /arena/next → 503');
    Settings::set('arena_enabled', 1);

    [$st, , $b] = http_req('GET', $base . '/arena/leaderboard?lang=ar', ['ua' => $ua]);
    check($st === 200 && str_contains($b, 'arn-lb-table'), 'arena leaderboard renders');

    if (function_exists('tools_http_tests')) {
        tools_http_tests($base, $tmp);
    }
    array_map('unlink', glob($tmp . '/*') ?: []);
    @rmdir($tmp);
}

/** Public API, CSV, badges, embed, SEO endpoints and tool pages. */
function tools_http_tests(string $base, string $tmp): void
{
    echo "\nTools / API HTTP ({$base})\n";
    Settings::set('api_public', 1);
    $endpoints = ['/api/v1/leaderboard', '/api/v1/models', '/api/v1/tracks', '/api/v1/tasks', '/api/v1/arena'];
    $slug = (string) DB::val("SELECT m.slug FROM model_scores s JOIN models m ON m.id = s.model_id WHERE s.scope = 'overall' ORDER BY s.score DESC LIMIT 1");
    $endpoints[] = '/api/v1/models/' . $slug;
    foreach ($endpoints as $ep) {
        [$st, $h, $b] = http_req('GET', $base . $ep);
        $j = json_decode($b, true);
        $ok = $st === 200 && is_array($j) && array_key_exists('data', $j)
            && isset($j['meta']['generated_at'], $j['meta']['version'], $j['meta']['license']) && array_key_exists('season', $j['meta'])
            && $j['meta']['license'] === 'CC BY 4.0';
        check($ok, "GET {$ep} → 200 with {data, meta:{season, generated_at, version, license}}", "status {$st}");
        check(($h['access-control-allow-origin'] ?? '') === '*' && str_contains($h['cache-control'] ?? '', 'max-age=300') && !empty($h['etag']), "{$ep}: CORS *, Cache-Control max-age=300, ETag");
        check(!isset($h['set-cookie']), "{$ep}: no Set-Cookie (stateless)");
    }
    [$st, $h, $b] = http_req('GET', $base . '/api/v1/leaderboard');
    [$st304] = http_req('GET', $base . '/api/v1/leaderboard', ['headers' => ['If-None-Match: ' . ($h['etag'] ?? 'x')]]);
    check($st304 === 304, 'If-None-Match with the current ETag → 304', "status {$st304}");
    [$st, $h] = http_req('OPTIONS', $base . '/api/v1/leaderboard', ['headers' => ['Origin: https://example.org', 'Access-Control-Request-Method: GET']]);
    check($st === 204 && ($h['access-control-allow-origin'] ?? '') === '*' && str_contains($h['access-control-allow-methods'] ?? '', 'GET'), 'OPTIONS preflight → 204 with CORS headers', "status {$st}");

    $lb = json_decode(http_req('GET', $base . '/api/v1/leaderboard')[2], true)['data'];
    $row = $lb[0] ?? [];
    $keys = ['rank', 'stat_rank', 'slug', 'name', 'score', 'ci_low', 'ci_high', 'provisional', 'coverage', 'tracks', 'arena', 'avg_cost_usd', 'url'];
    check(count(array_diff($keys, array_keys($row))) === 0, 'leaderboard rows carry rank, CI, tracks, arena, cost', implode(',', array_diff($keys, array_keys($row))));
    $tr = (string) DB::val('SELECT slug FROM tracks ORDER BY sort LIMIT 1');
    $byTrack = json_decode(http_req('GET', $base . '/api/v1/leaderboard?track=' . $tr)[2], true)['data'];
    $scores = array_values(array_filter(array_map(static fn($r) => $r['track']['score'] ?? null, $byTrack), static fn($v) => $v !== null));
    $sorted = $scores;
    rsort($sorted);
    check($scores === $sorted && $scores, "?track={$tr} sorts by that track and adds a track object");
    $np = json_decode(http_req('GET', $base . '/api/v1/leaderboard?include_provisional=0')[2], true)['data'];
    check(!array_filter($np, static fn($r) => $r['provisional']), '?include_provisional=0 hides provisional models');
    [$st, , $b] = http_req('GET', $base . '/api/v1/leaderboard?track=nope');
    check($st === 404 && (json_decode($b, true)['error']['code'] ?? '') === 'unknown_track', 'unknown track → 404 unknown_track');
    [$st, , $b] = http_req('GET', $base . '/api/v1/models/does-not-exist');
    check($st === 404 && (json_decode($b, true)['error']['code'] ?? '') === 'not_found', 'unknown model → 404 not_found');
    $m = json_decode(http_req('GET', $base . '/api/v1/models/' . $slug)[2], true)['data'];
    check(isset($m['tracks'][0]['score'], $m['history'][0]['date'], $m['scores']['score']), 'model detail has per-track scores and history');
    $tasks = json_decode(http_req('GET', $base . '/api/v1/tasks')[2], true);
    $hold = DB::col("SELECT slug FROM tasks WHERE visibility = 'holdout'");
    $listed = array_column($tasks['data'], 'slug');
    check(!array_intersect($hold, $listed) && !array_filter($tasks['data'], static fn($t) => isset($t['prompt'])), 'tasks: no hold-out tasks and no prompts');
    check((int) ($tasks['meta']['holdout_tasks'] ?? -1) === (int) DB::val("SELECT COUNT(*) FROM tasks WHERE visibility = 'holdout' AND is_active = 1"), 'tasks meta counts hold-out tasks');
    Settings::set('api_public', 0);
    [$st, , $b] = http_req('GET', $base . '/api/v1/models');
    check($st === 404 && (json_decode($b, true)['error']['code'] ?? '') === 'api_disabled', 'api_public off → 404 api_disabled');
    Settings::set('api_public', 1);

    // CSV
    [$st, $h, $b] = http_req('GET', $base . '/export/leaderboard.csv');
    $lines = array_values(array_filter(explode("\n", $b), static fn($l) => trim($l) !== ''));
    check($st === 200 && str_starts_with($b, "\xEF\xBB\xBF") && str_contains($h['content-type'] ?? '', 'text/csv'), 'CSV: 200, text/csv, UTF-8 BOM');
    check(str_starts_with(substr($lines[0], 3), 'rank,stat_rank,model,slug') && count($lines) === count($lb) + 1, 'CSV: documented header + one row per model', count($lines) . ' lines');
    check(str_contains($h['content-disposition'] ?? '', 'attachment'), 'CSV: download disposition');

    // Badges
    $valid = static function (string $svg): bool {
        $d = new DOMDocument();
        return @$d->loadXML($svg) && $d->documentElement->nodeName === 'svg';
    };
    foreach (['', '?style=dark', '?style=light'] as $q) {
        [$st, $h, $b] = http_req('GET', $base . '/badge/' . $slug . '.svg' . $q);
        check($st === 200 && str_contains($h['content-type'] ?? '', 'image/svg+xml') && $valid($b) && str_contains($h['cache-control'] ?? '', 'public'), "badge{$q}: 200, image/svg+xml, valid XML, cacheable");
    }
    [$st, , $b] = http_req('GET', $base . '/badge/not-a-model.svg');
    check($st === 404 && $valid($b) && str_contains($b, 'not rated'), 'unknown badge → grey "not rated" SVG with 404');
    $mid = (int) DB::val('SELECT id FROM models WHERE slug = ?', [$slug]);
    $orig = (string) DB::val('SELECT name FROM models WHERE id = ?', [$mid]);
    DB::update('models', ['name' => 'Evil <script>alert(1)</script> & "q"'], 'id = ?', [$mid]);
    [, , $b] = http_req('GET', $base . '/badge/' . $slug . '.svg');
    DB::update('models', ['name' => $orig], 'id = ?', [$mid]);
    check($valid($b) && !str_contains($b, '<script>'), 'badge escapes model names (valid XML, no raw <script>)');

    // Embed
    [$st, $h, $b] = http_req('GET', $base . '/embed/leaderboard?n=50&theme=light&lang=en');
    check($st === 200 && str_contains($h['content-security-policy'] ?? '', 'frame-ancestors *') && !isset($h['x-frame-options']), 'embed: CSP frame-ancestors *, no X-Frame-Options');
    check(substr_count($b, 'class="emb-row"') === min(20, count(array_filter($lb, static fn($r) => !$r['provisional']))) && str_contains($b, 'data-theme="light"') && str_contains($b, '<base target="_blank">'), 'embed: n clamped to 20, theme applied, links open in a new tab');
    [, $h2] = http_req('GET', $base . '/arena');
    check(str_contains($h2['x-frame-options'] ?? '', 'SAMEORIGIN'), 'normal pages keep X-Frame-Options (embed override is local)');

    // SEO
    [$st, $h, $b] = http_req('GET', $base . '/sitemap.xml');
    $d = new DOMDocument();
    $okXml = @$d->loadXML($b);
    check($st === 200 && $okXml && str_contains($b, '/arena</loc>') && str_contains($b, 'hreflang="ar"'), 'sitemap.xml: valid XML with hreflang alternates');
    $leak = array_filter($hold, static fn($s) => str_contains($b, '/task/' . $s . '<'));
    check(!$leak, 'sitemap.xml lists no hold-out tasks');
    [$st, , $b] = http_req('GET', $base . '/robots.txt');
    check($st === 200 && str_contains($b, 'Disallow: /admin') && str_contains($b, 'Disallow: /arena/frame') && str_contains($b, 'Disallow: /api') && str_contains($b, 'Sitemap:'), 'robots.txt disallows admin/render/frames/api and links the sitemap');

    // Pages
    foreach (['/hardware', '/advisor', '/api-docs', '/arena/leaderboard'] as $p) {
        foreach (['ar', 'en'] as $l) {
            [$st, , $b] = http_req('GET', $base . $p . '?lang=' . $l);
            check($st === 200 && !preg_match('/<b>(Warning|Notice|Deprecated|Fatal error)<\/b>/', $b), "{$p}?lang={$l} renders without PHP notices");
        }
    }

    // Rate limit last: it blocks this client for the rest of the minute.
    $codes = [];
    $retry = null;
    for ($i = 0; $i < 125; $i++) {
        [$st, $h] = http_req('GET', $base . '/api/v1/tracks');
        $codes[$st] = ($codes[$st] ?? 0) + 1;
        if ($st === 429) {
            $retry = $h['retry-after'] ?? null;
        }
    }
    check(isset($codes[429]) && $retry !== null, 'API rate limit: > 120/min → 429 with Retry-After', json_encode($codes));
}
