<?php
/**
 * Engine routes: admin worker step, external web-cron tick and the browser-verifier API (ARCHITECTURE §5.7).
 */
declare(strict_types=1);

defined('WB') || exit;

use WB\Eval\Campaign;
use WB\Eval\VerifierApi;
use WB\Eval\Worker;

// Admin console heartbeat: run the worker for ~20 s and report progress.
Router::post('/admin/api/step', static function (): void {
    require_admin();
    csrf_check();
    if (function_exists('session_write_close')) {
        session_write_close(); // do not block the admin's other tabs while the worker runs
    }
    $out = Worker::step(20.0);
    $out['spent_usd'] = round(Campaign::totalSpent(), 4);
    $out['step_spent_usd'] = $out['spent'];
    $out['campaigns'] = Campaign::activeProgress();
    $out['ok'] = true;
    json_out($out);
});

// External web-cron: GET /worker/tick?key=<config cron_key>. Unknown or unconfigured key → 404.
Router::get('/worker/tick', static function (): void {
    $key = (string) Config::get('cron_key', '');
    $given = $_GET['key'] ?? '';
    if ($key === '' || !is_string($given) || $given === '' || !hash_equals($key, $given)) {
        abort(404);
    }
    header('X-Robots-Tag: noindex');
    [$ran, $out] = Worker::withLock(static fn(): array => Worker::step(50.0));
    if (!$ran) {
        json_out(['ok' => true, 'busy' => true]);
    }
    json_out([
        'ok' => true,
        'busy' => false,
        'units' => count($out['did']),
        'idle' => $out['idle'],
        'spent' => $out['spent'],
        'counts' => $out['counts'],
        'ms' => $out['ms'],
    ]);
});

$wbEngineVerifier = static function (string $action): void {
    $agent = VerifierApi::authorize();
    $len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($len > VerifierApi::MAX_BODY_BYTES) {
        json_out(['ok' => false, 'error' => 'payload_too_large'], 413);
    }
    $body = json_body();
    if ($action === 'next') {
        $requested = $body['agent'] ?? null;
        json_out(['ok' => true, 'run' => VerifierApi::next(is_string($requested) && in_array($requested, ['station', 'playwright'], true) ? $requested : $agent)]);
    }
    if (!isset($body['agent'])) {
        $body['agent'] = $agent;
    }
    $res = $action === 'submit' ? VerifierApi::submit($body) : VerifierApi::release($body);
    $code = (int) ($res['http'] ?? 200);
    unset($res['http']);
    json_out($res, !empty($res['ok']) ? 200 : $code);
};

Router::post('/api/verifier/next', static fn() => $wbEngineVerifier('next'));
Router::post('/api/verifier/submit', static fn() => $wbEngineVerifier('submit'));
Router::post('/api/verifier/release', static fn() => $wbEngineVerifier('release'));
unset($wbEngineVerifier);
