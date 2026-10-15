<?php
/**
 * WOAIBench.si — CLI worker for cron (Hostinger: every minute).
 *
 *   php cron/worker.php --seconds=55
 *
 * Overlapping invocations exit immediately (file lock storage/cache/worker.lock); leases keep work
 * safe even when the admin console or a web tick runs at the same time.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('WB', true);
require dirname(__DIR__) . '/app/bootstrap.php';

$seconds = 55;
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (preg_match('/^--seconds=(\d+)$/', (string) $arg, $m)) {
        $seconds = max(1, min(3600, (int) $m[1]));
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "Usage: php cron/worker.php [--seconds=55]\n";
        exit(0);
    }
}

[$ran, $out] = WB\Eval\Worker::withLock(static fn(): array => WB\Eval\Worker::step((float) $seconds));
if (!$ran) {
    echo '[' . gmdate('Y-m-d H:i:s') . "] worker: another worker holds the lock, exiting\n";
    exit(0);
}

$kinds = [];
foreach ($out['did'] as $d) {
    $kinds[$d['kind']] = ($kinds[$d['kind']] ?? 0) + 1;
}
$c = $out['counts'];
$queue = [];
foreach (['queued', 'generating', 'awaiting_verify', 'verifying', 'judging', 'scored', 'failed'] as $s) {
    $queue[] = $s . '=' . ($c[$s] ?? 0);
}
printf(
    "[%s] worker: %d units%s in %.1fs, spent $%.4f, %s; queue %s judgments_open=%d; peak memory %.1f MB\n",
    gmdate('Y-m-d H:i:s'),
    count($out['did']),
    $kinds ? ' (' . implode(', ', array_map(static fn($k, $n) => "$k $n", array_keys($kinds), $kinds)) . ')' : '',
    $out['ms'] / 1000,
    $out['spent'],
    $out['idle'] ? 'idle' : 'busy',
    implode(' ', $queue),
    $c['judgments_open'] ?? 0,
    memory_get_peak_usage(true) / 1048576
);
if (!empty($out['halted'])) {
    // No credit / invalid or blocked API key: generation and judging wait until the admin resumes (Admin → Dashboard).
    $h = WB\Eval\Campaign::providerHalt() ?? [];
    printf("[%s] worker: PROVIDER HALT (HTTP %s: %s) — generation and judging paused until a campaign is resumed\n",
        gmdate('Y-m-d H:i:s'), (string) ($h['code'] ?? '?'), (string) ($h['message'] ?? ''));
}
