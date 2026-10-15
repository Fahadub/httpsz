<?php
/**
 * Public read API v1 (module: arena-tools): serializers, response envelope, CORS / caching / rate
 * limiting, CSV export and SVG badges. All data comes from WB\Data\Leaderboard and Catalog.
 */
declare(strict_types=1);

namespace WB\Data;

defined('WB') || exit;

use DB;
use Http;
use RateLimit;
use WB\Arena\Arena;

final class Api
{
    public const VERSION = 'v1';
    public const LICENSE = 'CC BY 4.0';
    public const LICENSE_URL = 'https://creativecommons.org/licenses/by/4.0/';
    public const RATE_PER_MIN = 120;
    public const MAX_AGE = 300;

    /** Drop the session for anonymous, cacheable responses (no Set-Cookie, no session file, no no-cache headers). */
    public static function stateless(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (empty($_COOKIE[session_name()])) {
                $_SESSION = [];
                session_destroy();
            } else {
                session_write_close();
            }
        }
        if (!headers_sent()) {
            header_remove('Set-Cookie');
            header_remove('Pragma');
            header_remove('Expires');
        }
    }

    /** Common gate for /api/v1/*: CORS, OPTIONS preflight, api_public switch, 120 req/min per client. */
    public static function guard(): void
    {
        self::stateless();
        Http::corsPublic();
        header('Access-Control-Expose-Headers: ETag, Retry-After, X-RateLimit-Limit, X-RateLimit-Remaining');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            header('Access-Control-Max-Age: 86400');
            http_response_code(204);
            exit;
        }
        if (!(int) \setting('api_public', 1)) {
            self::error(404, 'api_disabled', 'The public API is currently disabled.');
        }
        $key = 'api:' . \ip_hash();
        header('X-RateLimit-Limit: ' . self::RATE_PER_MIN);
        if (!\rate_limit($key, self::RATE_PER_MIN, 60)) {
            header('X-RateLimit-Remaining: 0');
            self::error(429, 'rate_limited', 'Too many requests: the limit is ' . self::RATE_PER_MIN . ' per minute.', ['Retry-After' => '60']);
        }
        header('X-RateLimit-Remaining: ' . RateLimit::remaining($key, self::RATE_PER_MIN, 60));
    }

    /** JSON error in the API envelope shape. */
    public static function error(int $status, string $code, string $message, array $headers = []): never
    {
        \json_out(['error' => ['code' => $code, 'message' => $message], 'meta' => self::meta(null)], $status, $headers);
    }

    /** Standard envelope: {data, meta:{season, generated_at, version, license, …}}. */
    public static function envelope(mixed $data, ?string $season = null, array $meta = []): array
    {
        return ['data' => $data, 'meta' => array_merge(self::meta($season), $meta)];
    }

    /** Send a cacheable JSON envelope with a strong ETag (304 when the client copy is current). */
    public static function send(array $envelope): never
    {
        $etag = '"' . substr(hash('sha256', \to_json($envelope['data']) . '|' . ($envelope['meta']['season'] ?? '')), 0, 32) . '"';
        self::cacheHeaders($etag);
        if (self::notModified($etag)) {
            http_response_code(304);
            exit;
        }
        \json_out($envelope, 200, ['Cache-Control' => 'public, max-age=' . self::MAX_AGE, 'ETag' => $etag]);
    }

    /** Public caching headers + conditional request check helper for non-JSON endpoints. */
    public static function cacheHeaders(string $etag, int $maxAge = self::MAX_AGE): void
    {
        header('Cache-Control: public, max-age=' . $maxAge);
        header('ETag: ' . $etag);
        header('Vary: Accept-Encoding');
    }

    public static function notModified(string $etag): bool
    {
        $inm = (string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
        if ($inm === '') {
            return false;
        }
        foreach (explode(',', $inm) as $tag) {
            $tag = trim($tag);
            if ($tag === '*' || $tag === $etag || $tag === 'W/' . $etag) {
                return true;
            }
        }
        return false;
    }

    /** Validate ?season= (falls back to the current season). Returns null for an unknown season. */
    public static function season(?string $requested): ?string
    {
        if ($requested === null || $requested === '') {
            return Catalog::season();
        }
        return in_array($requested, Catalog::seasons(), true) ? $requested : null;
    }

    /* ------------------------------------------------------------------ resources */

    /** Leaderboard rows for a season, optionally sorted by one track. */
    public static function leaderboard(string $season, ?string $track = null, bool $includeProvisional = true): array
    {
        $opts = ['include_provisional' => $includeProvisional];
        if ($track) {
            $opts['track'] = $track;
        }
        $out = [];
        $pos = 0;
        foreach (Leaderboard::rows($season, $opts) as $r) {
            $row = self::row($r);
            if ($track) {
                $t = $r['tracks'][$track] ?? null;
                $row['track'] = $t ? ['slug' => $track, 'rank' => $r['provisional'] ? null : ++$pos] + self::trackScore($t) : null;
            }
            $out[] = $row;
        }
        return $out;
    }

    /** One leaderboard row in the public API shape. */
    public static function row(array $r): array
    {
        $tracks = [];
        foreach ($r['tracks'] ?? [] as $slug => $t) {
            $tracks[$slug] = self::trackScore($t);
        }
        return [
            'rank' => $r['rank_pos'] ?? null,
            'stat_rank' => $r['stat_rank'] ?? null,
            'slug' => $r['slug'],
            'name' => $r['name'],
            'provider' => $r['provider'],
            'provider_name' => $r['provider_name'],
            'open_weights' => (bool) $r['open_weights'],
            'params_b' => $r['params_b'],
            'active_params_b' => $r['active_params_b'],
            'context_length' => $r['context_length'],
            'supports_vision' => (bool) $r['supports_vision'],
            'release_date' => $r['release_date'],
            'score' => self::r2($r['score']),
            'ci_low' => self::r2($r['ci_low']),
            'ci_high' => self::r2($r['ci_high']),
            'provisional' => (bool) $r['provisional'],
            'coverage' => self::r2($r['coverage']),
            'n_tasks' => $r['n_tasks'],
            'n_runs' => $r['n_runs'],
            'consistency' => self::r2($r['consistency']),
            'auto_avg' => self::r2($r['auto_avg']),
            'judge_avg' => self::r2($r['judge_avg']),
            'avg_cost_usd' => $r['avg_cost'] !== null ? round((float) $r['avg_cost'], 6) : null,
            'avg_latency_ms' => $r['avg_latency_ms'],
            'avg_tokens' => $r['avg_tokens'],
            'price_in_per_m' => $r['price_in'],
            'price_out_per_m' => $r['price_out'],
            'tracks' => (object) $tracks,
            'arena' => $r['arena'] ? [
                'rating' => self::r2($r['arena']['rating']), 'ci_low' => self::r2($r['arena']['ci_low']),
                'ci_high' => self::r2($r['arena']['ci_high']), 'votes' => $r['arena']['votes'],
            ] : null,
            'url' => \url('/model/' . $r['slug'], [], true),
        ];
    }

    /** All active benchmarked models with their current-season headline score. */
    public static function models(): array
    {
        $scores = [];
        foreach (Leaderboard::rows() as $r) {
            $scores[(int) $r['id']] = $r;
        }
        $rows = DB::all(
            'SELECT id, slug, name, provider, provider_name, open_weights, params_b, active_params_b, context_length,
                    price_in, price_out, supports_vision, supports_tools, release_date
               FROM models WHERE is_active = 1 ORDER BY name'
        );
        $out = [];
        foreach ($rows as $m) {
            $s = $scores[(int) $m['id']] ?? null;
            $out[] = self::modelInfo($m) + [
                'score' => $s ? self::r2($s['score']) : null,
                'ci_low' => $s ? self::r2($s['ci_low']) : null,
                'ci_high' => $s ? self::r2($s['ci_high']) : null,
                'rank' => $s['rank_pos'] ?? null,
                'provisional' => $s ? (bool) $s['provisional'] : null,
                'url' => \url('/model/' . $m['slug'], [], true),
            ];
        }
        return $out;
    }

    /** One model with per-track scores, arena rating and score history; null when unknown. */
    public static function model(string $slug, string $season): ?array
    {
        $m = DB::one(
            'SELECT id, slug, name, provider, provider_name, open_weights, params_b, active_params_b, context_length,
                    price_in, price_out, supports_vision, supports_tools, release_date, is_active
               FROM models WHERE slug = ?',
            [$slug]
        );
        if (!$m) {
            return null;
        }
        $row = null;
        foreach (Leaderboard::rows($season) as $r) {
            if ($r['slug'] === $slug) {
                $row = $r;
                break;
            }
        }
        if (!$row && !(int) $m['is_active']) {
            return null;
        }
        $tracks = Catalog::tracks();
        $perTrack = [];
        foreach ($row['tracks'] ?? [] as $ts => $t) {
            $perTrack[] = ['slug' => $ts, 'name_en' => $tracks[$ts]['name_en'] ?? $ts, 'name_ar' => $tracks[$ts]['name_ar'] ?? $ts] + self::trackScore($t);
        }
        $history = array_map(static fn($h) => [
            'date' => $h['date'], 'score' => self::r2($h['score']), 'ci_low' => self::r2($h['ci_low']),
            'ci_high' => self::r2($h['ci_high']), 'rank' => $h['rank_pos'] !== null ? (int) $h['rank_pos'] : null,
        ], Leaderboard::history((int) $m['id'], $season, 365));
        $full = $row ? self::row($row) : null;
        return self::modelInfo($m) + [
            'scores' => $full ? array_diff_key($full, array_flip(['slug', 'name', 'provider', 'provider_name', 'open_weights', 'params_b', 'active_params_b', 'context_length', 'supports_vision', 'release_date', 'tracks', 'arena', 'url', 'price_in_per_m', 'price_out_per_m'])) : null,
            'tracks' => $perTrack,
            'arena' => $full['arena'] ?? null,
            'history' => $history,
            'url' => \url('/model/' . $m['slug'], [], true),
        ];
    }

    /** Active tracks with task counts. */
    public static function tracks(): array
    {
        $out = [];
        foreach (Catalog::tracks() as $t) {
            $out[] = [
                'slug' => $t['slug'], 'name_en' => $t['name_en'], 'name_ar' => $t['name_ar'],
                'description_en' => $t['desc_en'], 'description_ar' => $t['desc_ar'],
                'icon' => $t['icon'], 'color' => $t['color'], 'auto_weight' => (float) $t['auto_weight'],
                'tasks' => $t['task_count'], 'public_tasks' => $t['public_count'], 'holdout_tasks' => $t['holdout_count'],
            ];
        }
        return $out;
    }

    /** Public, active tasks (hold-out tasks are only counted, never listed). */
    public static function tasks(): array
    {
        $rows = DB::all(
            "SELECT t.slug, tr.slug AS track, t.title_en, t.title_ar, t.summary_en, t.summary_ar, t.difficulty,
                    t.output_type, t.version, t.season
               FROM tasks t JOIN tracks tr ON tr.id = t.track_id
              WHERE t.visibility = 'public' AND t.is_active = 1 AND tr.is_active = 1
              ORDER BY tr.sort, t.difficulty, t.id"
        );
        return array_map(static fn($t) => [
            'slug' => $t['slug'], 'track' => $t['track'], 'title_en' => $t['title_en'], 'title_ar' => $t['title_ar'],
            'summary_en' => $t['summary_en'], 'summary_ar' => $t['summary_ar'], 'difficulty' => (int) $t['difficulty'],
            'output_type' => $t['output_type'], 'version' => (int) $t['version'], 'season' => $t['season'],
            'url' => \url('/task/' . $t['slug'], [], true),
        ], $rows);
    }

    /** Arena ratings (Bradley–Terry) with vote counts. */
    public static function arena(): array
    {
        $board = Arena::leaderboard();
        $rows = array_map(static fn($r) => [
            'rank' => $r['arena_rank'], 'slug' => $r['slug'], 'name' => $r['name'], 'provider' => $r['provider'],
            'provider_name' => $r['provider_name'], 'rating' => self::r2($r['rating']), 'ci_low' => self::r2($r['ci_low']),
            'ci_high' => self::r2($r['ci_high']), 'votes' => $r['votes'], 'wins' => $r['wins'], 'losses' => $r['losses'],
            'ties' => $r['ties'], 'win_rate' => $r['win_rate'] !== null ? round($r['win_rate'], 4) : null,
            'benchmark_score' => self::r2($r['bench_score']), 'url' => \url('/model/' . $r['slug'], [], true),
        ], $board['rows']);
        return ['rows' => $rows, 'spearman' => $board['spearman'] !== null ? round($board['spearman'], 4) : null];
    }

    /* ------------------------------------------------------------------ CSV */

    /** CSV columns (documented on /api-docs). Track columns follow as track_<slug>. */
    public static function csvColumns(): array
    {
        return ['rank', 'stat_rank', 'model', 'slug', 'provider', 'open_weights', 'score', 'ci_low', 'ci_high', 'provisional',
            'coverage', 'n_tasks', 'n_runs', 'consistency', 'avg_cost_usd', 'avg_latency_ms', 'price_in_per_m', 'price_out_per_m',
            'context_length', 'params_b', 'arena_rating', 'arena_votes'];
    }

    /** Leaderboard as CSV text with a UTF-8 BOM (so Excel shows Arabic and symbols correctly). */
    public static function csv(string $season): string
    {
        $tracks = array_keys(Catalog::tracks());
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, array_merge(self::csvColumns(), array_map(static fn($t) => 'track_' . $t, $tracks)), ',', '"', '');
        foreach (Leaderboard::rows($season) as $r) {
            $line = [
                $r['rank_pos'], $r['stat_rank'], self::csvSafe($r['name']), $r['slug'], self::csvSafe((string) ($r['provider_name'] ?: $r['provider'])),
                $r['open_weights'] ? 1 : 0, self::r2($r['score']), self::r2($r['ci_low']), self::r2($r['ci_high']), $r['provisional'] ? 1 : 0,
                self::r2($r['coverage']), $r['n_tasks'], $r['n_runs'], self::r2($r['consistency']),
                $r['avg_cost'] !== null ? round((float) $r['avg_cost'], 6) : null, $r['avg_latency_ms'], $r['price_in'], $r['price_out'],
                $r['context_length'], $r['params_b'], $r['arena'] ? self::r2($r['arena']['rating']) : null, $r['arena']['votes'] ?? null,
            ];
            foreach ($tracks as $t) {
                $line[] = isset($r['tracks'][$t]) ? self::r2($r['tracks'][$t]['score']) : null;
            }
            fputcsv($fh, $line, ',', '"', '');
        }
        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);
        return $csv;
    }

    /* ------------------------------------------------------------------ badge */

    /**
     * Two-part SVG badge: "WOAIBench" | "78.4 ±1.2 · #3". $row = Leaderboard row or null (unknown model).
     * $style: flat (gold value) | dark | light.
     */
    public static function badge(?array $row, string $style = 'flat'): string
    {
        $label = 'WOAIBench';
        if ($row === null) {
            $value = 'not rated';
        } else {
            $half = ($row['ci_high'] !== null && $row['ci_low'] !== null) ? ((float) $row['ci_high'] - (float) $row['ci_low']) / 2 : null;
            $value = number_format((float) $row['score'], 1) . ($half !== null ? ' ±' . number_format($half, 1) : '')
                . ($row['provisional'] ? ' · provisional' : ($row['rank_pos'] ? ' · #' . (int) $row['rank_pos'] : ''));
        }
        $pal = [
            'flat' => ['#0E0F14', '#F3E7C9', 'url(#v)', '#17130A', '#C9A24E'],
            'dark' => ['#0E0F14', '#F3E7C9', '#1B1C24', '#E7C77D', '#C9A24E'],
            'light' => ['#F4EFE3', '#1A1A20', '#FFFFFF', '#5A4310', '#B08A32'],
        ][$style] ?? null;
        $pal ??= ['#0E0F14', '#F3E7C9', 'url(#v)', '#17130A', '#C9A24E'];
        [$lBg, $lFg, $vBg, $vFg, $mark] = $pal;
        if ($row === null) {
            [$vBg, $vFg] = $style === 'light' ? ['#E6E3DC', '#5C5A55'] : ['#5B5D66', '#F2F2F2'];
        }
        $lw = (int) ceil(self::textWidth($label) + 30);
        $vw = (int) ceil(self::textWidth($value) + 14);
        $w = $lw + $vw;
        $title = $row === null ? 'WOAIBench: not rated' : 'WOAIBench score for ' . $row['name'] . ': ' . $value;
        $x = static fn(float $v): string => rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
        $esc = static fn(string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="20" viewBox="0 0 ' . $w . ' 20" role="img" aria-label="' . $esc($title) . '">'
            . '<title>' . $esc($title) . '</title>'
            . '<defs><linearGradient id="v" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#F0D896"/><stop offset="1" stop-color="#C9A24E"/></linearGradient>'
            . '<linearGradient id="s" x2="0" y2="100%"><stop offset="0" stop-color="#fff" stop-opacity=".09"/><stop offset="1" stop-opacity=".1"/></linearGradient>'
            . '<clipPath id="r"><rect width="' . $w . '" height="20" rx="4"/></clipPath></defs>'
            . '<g clip-path="url(#r)"><rect width="' . $lw . '" height="20" fill="' . $lBg . '"/><rect x="' . $lw . '" width="' . $vw . '" height="20" fill="' . $vBg . '"/>'
            . '<rect width="' . $w . '" height="20" fill="url(#s)"/>'
            . ($style === 'light' ? '<rect x=".5" y=".5" width="' . ($w - 1) . '" height="19" rx="3.5" fill="none" stroke="#D8D2C4"/>' : '')
            . '</g>'
            . '<path d="M12 4.5l4.5 5.5-4.5 5.5-4.5-5.5z" fill="none" stroke="' . $mark . '" stroke-width="1.4" stroke-linejoin="round"/><path d="M12 7.6l2 2.4-2 2.4-2-2.4z" fill="' . $mark . '"/>'
            . '<g font-family="Verdana,Geneva,DejaVu Sans,sans-serif" font-size="11" text-rendering="geometricPrecision">'
            . '<text x="' . $x(22 + self::textWidth($label) / 2) . '" y="14" fill="' . $lFg . '" text-anchor="middle" textLength="' . $x(self::textWidth($label)) . '" lengthAdjust="spacingAndGlyphs">' . $esc($label) . '</text>'
            . '<text x="' . $x($lw + $vw / 2) . '" y="14" fill="' . $vFg . '" font-weight="bold" text-anchor="middle" textLength="' . $x(self::textWidth($value)) . '" lengthAdjust="spacingAndGlyphs">' . $esc($value) . '</text>'
            . '</g></svg>';
    }

    /* ------------------------------------------------------------------ internals */

    private static function meta(?string $season): array
    {
        return [
            'season' => $season,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'version' => self::VERSION,
            'license' => self::LICENSE,
            'license_url' => self::LICENSE_URL,
            'attribution' => 'WOAIBench.si — ' . \url('/', [], true),
            'docs' => \url('/api-docs', [], true),
        ];
    }

    private static function modelInfo(array $m): array
    {
        return [
            'slug' => $m['slug'], 'name' => $m['name'], 'provider' => $m['provider'], 'provider_name' => $m['provider_name'],
            'open_weights' => (bool) $m['open_weights'],
            'params_b' => $m['params_b'] !== null ? (float) $m['params_b'] : null,
            'active_params_b' => $m['active_params_b'] !== null ? (float) $m['active_params_b'] : null,
            'context_length' => $m['context_length'] !== null ? (int) $m['context_length'] : null,
            'price_in_per_m' => $m['price_in'] !== null ? (float) $m['price_in'] : null,
            'price_out_per_m' => $m['price_out'] !== null ? (float) $m['price_out'] : null,
            'supports_vision' => (bool) $m['supports_vision'], 'supports_tools' => (bool) $m['supports_tools'],
            'release_date' => $m['release_date'],
        ];
    }

    private static function trackScore(array $t): array
    {
        return ['score' => self::r2($t['score']), 'ci_low' => self::r2($t['ci_low']), 'ci_high' => self::r2($t['ci_high']),
            'n_tasks' => $t['n_tasks'] ?? null, 'n_runs' => $t['n_runs'] ?? null];
    }

    private static function r2(mixed $v): ?float
    {
        return ($v === null || $v === '') ? null : round((float) $v, 2);
    }

    /** Neutralise spreadsheet formula injection (=, +, -, @, tab, CR at the start of a cell). */
    private static function csvSafe(string $s): string
    {
        return preg_match('/^[=+\-@\t\r]/', $s) ? "'" . $s : $s;
    }

    /** Approximate rendered width of 11px Verdana text (shields-style metrics). */
    private static function textWidth(string $s): float
    {
        static $narrow = ['i' => 3.1, 'l' => 3.1, 'j' => 3.4, 'I' => 4.3, ' ' => 3.9, '.' => 3.9, ',' => 3.9, ':' => 4.5, '·' => 4.3, '|' => 4.6, 'f' => 4.3, 't' => 4.7, 'r' => 4.7, '(' => 5.0, ')' => 5.0];
        static $wide = ['m' => 10.7, 'w' => 9.0, 'M' => 9.9, 'W' => 11.0, '#' => 9.0, '±' => 9.0, '%' => 11.9, 'B' => 7.5, 'O' => 8.6, 'A' => 7.5, 'C' => 7.7, 'D' => 8.4, 'H' => 8.3, 'N' => 8.3, 'Q' => 8.6, 'G' => 8.5, 'U' => 8.1];
        $w = 0.0;
        foreach (mb_str_split($s) as $ch) {
            if (isset($narrow[$ch])) {
                $w += $narrow[$ch];
            } elseif (isset($wide[$ch])) {
                $w += $wide[$ch];
            } elseif (ctype_digit($ch)) {
                $w += 7.0;
            } elseif (ctype_upper($ch)) {
                $w += 7.4;
            } else {
                $w += 6.6;
            }
        }
        return $w;
    }
}
