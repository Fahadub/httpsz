<?php
/**
 * Arena routes (module: arena-tools): blind duel page, opaque output frames, votes, next pair,
 * Bradley–Terry leaderboard.
 */
declare(strict_types=1);

defined('WB') || exit;

use WB\Arena\Arena;

/** Pair creations allowed per voter per hour (generous: skipping a duel is fine, scripting it is not). */
function arena_pair_limit(): int
{
    return max(120, 4 * (int) setting('arena_votes_per_hour', 60));
}

/** Crawlers get the page without a pre-created pair (no rows for bots; JS loads one for humans). */
function arena_is_bot(): bool
{
    return (bool) preg_match('/bot\b|crawl|spider|slurp|facebookexternalhit|embedly|preview/i', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
}

Router::get('/arena', static function (): void {
    $state = 'ready';
    $pair = null;
    if (!Arena::enabled()) {
        $state = 'disabled';
    } elseif (!Arena::available()) {
        $state = 'no_data';
    } elseif (!arena_is_bot()) {
        $voter = Arena::voterHash();
        if (rate_limit('arena:pair:' . $voter, arena_pair_limit(), 3600)) {
            $pair = Arena::createPair($voter);
            $state = $pair ? 'ready' : 'no_data';
        } else {
            $state = 'rate_limited';
        }
    }
    $board = $state === 'disabled' ? ['rows' => []] : Arena::leaderboard();
    view('pages/arena', [
        'title' => __('arena.title'),
        'description' => __('arena.meta_description'),
        'nav_active' => 'arena',
        'page_css' => ['css/arena.css'],
        'page_js' => ['js/arena.js'],
        'state' => $state,
        'pair' => $pair,
        'stats' => Arena::stats(),
        'top' => array_slice($board['rows'], 0, 5),
    ]);
});

Router::get('/arena/next', static function (): void {
    if (!Arena::enabled()) {
        json_out(['ok' => false, 'error' => 'disabled', 'message' => __('arena.disabled_text')], 503);
    }
    $voter = Arena::voterHash();
    if (!rate_limit('arena:pair:' . $voter, arena_pair_limit(), 3600)) {
        json_out(['ok' => false, 'error' => 'rate_limited', 'message' => __('arena.err_rate_limited')], 429, ['Retry-After' => '900']);
    }
    $pair = Arena::createPair($voter);
    if (!$pair) {
        json_out(['ok' => false, 'error' => 'no_data', 'message' => __('arena.no_data_text')], 404);
    }
    json_out(['ok' => true, 'pair' => $pair]);
});

Router::post('/arena/vote', static function (): void {
    csrf_check();
    if (!Arena::enabled()) {
        json_out(['ok' => false, 'error' => 'disabled', 'message' => __('arena.disabled_text')], 503);
    }
    $voter = Arena::voterHash();
    $limit = max(1, (int) setting('arena_votes_per_hour', 60));
    if (!rate_limit('arena:vote:' . $voter, $limit, 3600)) {
        json_out(['ok' => false, 'error' => 'rate_limited', 'message' => __('arena.err_rate_limited')], 429, ['Retry-After' => '3600']);
    }
    $body = json_body();
    $token = (string) ($body['token'] ?? input('token', ''));
    $winner = (string) ($body['winner'] ?? input('winner', ''));
    $res = Arena::vote($token, $winner, $voter);
    if (!$res['ok']) {
        json_out(['ok' => false, 'error' => $res['error'], 'message' => __('arena.err_' . $res['error'])], (int) $res['status']);
    }
    // Answer first, then (maybe) refit the ratings so the voter never waits for the bootstrap.
    $json = json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Content-Length: ' . strlen((string) $json));
    echo $json;
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    ignore_user_abort(true);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    } else {
        flush();
    }
    try {
        Arena::maybeRecompute();
    } catch (Throwable $e) {
        log_error('Arena recompute failed: ' . $e->getMessage());
    }
});

Router::get('/arena/frame/{token:[a-f0-9]+}/{side:a|b}', static function (string $token, string $side): void {
    $found = Arena::enabled() ? Arena::frame($token, $side) : null;
    $renderer = WB_APP . '/render/render.php';
    if ($found && is_file($renderer)) {
        require_once $renderer;
        if (function_exists('wb_render_document')) {
            wb_render_document($found[0], $found[1]);
            return;
        }
    }
    // Placeholder (expired token, or the renderer module is not installed): still a sandboxed document.
    Http::sandboxHeaders();
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    http_response_code($found ? 200 : 404);
    $msg = $found ? __('arena.frame_unavailable') : __('arena.frame_expired');
    echo '<!doctype html><html lang="' . e(lang()) . '" dir="' . e(text_dir()) . '"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($msg) . '</title>'
        . '<style>html,body{margin:0;height:100%}body{display:grid;place-items:center;font:15px/1.6 system-ui,sans-serif;'
        . 'background:#0d0e13;color:#b9b4a6;text-align:center;padding:24px;box-sizing:border-box}'
        . 'p{max-width:28em}b{display:block;color:#e7d3a3;font-size:28px;margin-bottom:8px}</style></head>'
        . '<body><p><b>' . _e($side === 'a' ? 'arena.letter_a' : 'arena.letter_b') . '</b>' . e($msg) . '</p></body></html>';
});

Router::get('/arena/leaderboard', static function (): void {
    $board = Arena::leaderboard();
    view('pages/arena-leaderboard', [
        'title' => __('arena.lb_title'),
        'description' => __('arena.lb_meta_description'),
        'nav_active' => 'arena',
        'page_css' => ['css/arena.css'],
        'page_js' => ['vendor/chart.umd.min.js', 'js/arena.js'],
        'board' => $board,
        'stats' => Arena::stats(),
        'enabled' => Arena::enabled(),
    ]);
});
