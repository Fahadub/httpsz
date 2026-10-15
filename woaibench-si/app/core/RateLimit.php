<?php
/**
 * Fixed-window rate limiting stored in MySQL (works on shared hosting without Redis).
 *   if (!rate_limit('vote:' . ip_hash(), 60, 3600)) abort(429);
 */
declare(strict_types=1);

defined('WB') || exit;

final class RateLimit
{
    /**
     * Returns true when the action is allowed, and counts it. Atomic: one INSERT … ON DUPLICATE KEY UPDATE both
     * reserves the slot and returns this request's own count, so concurrent requests can never overshoot $max.
     */
    public static function hit(string $key, int $max, int $windowSeconds): bool
    {
        $key = self::key($key, $windowSeconds);
        $now = now();
        $expired = now(-$windowSeconds); // a window that started at or before this instant is over
        // hits is assigned first, so both IF()s still see the old window_start.
        $inserted = DB::exec(
            'INSERT INTO rate_limits (k, hits, window_start) VALUES (?, 1, ?)
             ON DUPLICATE KEY UPDATE hits = LAST_INSERT_ID(IF(window_start <= ?, 1, hits + 1)),
                                     window_start = IF(window_start <= ?, ?, window_start)',
            [$key, $now, $expired, $expired, $now]
        ) === 1;
        $count = $inserted ? 1 : (int) DB::val('SELECT LAST_INSERT_ID()');
        if ($inserted && random_int(1, 200) === 1) {
            DB::exec('DELETE FROM rate_limits WHERE window_start < ?', [now(-86400 * 2)]);
        }
        return $count <= $max;
    }

    /** Forget a key (e.g. after a successful login). */
    public static function reset(string $key, int $windowSeconds): void
    {
        DB::exec('DELETE FROM rate_limits WHERE k = ?', [self::key($key, $windowSeconds)]);
    }

    /** Check without counting. */
    public static function remaining(string $key, int $max, int $windowSeconds): int
    {
        $key = self::key($key, $windowSeconds);
        $row = DB::one('SELECT hits, window_start FROM rate_limits WHERE k = ?', [$key]);
        if (!$row || strtotime($row['window_start'] . ' UTC') + $windowSeconds <= time()) {
            return $max;
        }
        return max(0, $max - (int) $row['hits']);
    }

    private static function key(string $key, int $windowSeconds): string
    {
        return substr(hash('sha256', $key), 0, 64) . ':' . $windowSeconds;
    }
}

function rate_limit(string $key, int $max, int $windowSeconds): bool
{
    return RateLimit::hit($key, $max, $windowSeconds);
}
