<?php
/**
 * Fixed-window rate limiting stored in MySQL (works on shared hosting without Redis).
 *   if (!rate_limit('vote:' . ip_hash(), 60, 3600)) abort(429);
 */
declare(strict_types=1);

defined('WB') || exit;

final class RateLimit
{
    /** Returns true when the action is allowed (and counts it). */
    public static function hit(string $key, int $max, int $windowSeconds): bool
    {
        $key = substr(hash('sha256', $key), 0, 64) . ':' . $windowSeconds;
        $now = now();
        $row = DB::one('SELECT hits, window_start FROM rate_limits WHERE k = ?', [$key]);
        if (!$row || strtotime($row['window_start'] . ' UTC') + $windowSeconds <= time()) {
            DB::upsert('rate_limits', ['k' => $key, 'hits' => 1, 'window_start' => $now], ['hits', 'window_start']);
            if (random_int(1, 200) === 1) {
                DB::exec('DELETE FROM rate_limits WHERE window_start < ?', [now(-86400 * 2)]);
            }
            return true;
        }
        if ((int) $row['hits'] >= $max) {
            return false;
        }
        DB::exec('UPDATE rate_limits SET hits = hits + 1 WHERE k = ?', [$key]);
        return true;
    }

    /** Check without counting. */
    public static function remaining(string $key, int $max, int $windowSeconds): int
    {
        $key = substr(hash('sha256', $key), 0, 64) . ':' . $windowSeconds;
        $row = DB::one('SELECT hits, window_start FROM rate_limits WHERE k = ?', [$key]);
        if (!$row || strtotime($row['window_start'] . ' UTC') + $windowSeconds <= time()) {
            return $max;
        }
        return max(0, $max - (int) $row['hits']);
    }
}

function rate_limit(string $key, int $max, int $windowSeconds): bool
{
    return RateLimit::hit($key, $max, $windowSeconds);
}
