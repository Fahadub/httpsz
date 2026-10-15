<?php
/**
 * Row leases for runs and judgments (crash-safe work claiming).
 *
 * A lease is claimed with one atomic UPDATE … WHERE … AND (locked_until IS NULL OR locked_until < NOW())
 * and only counts when exactly one row changed. Expired leases are therefore reclaimable, and every
 * later write is guarded by the unique lock_token so a worker that lost its lease can never overwrite
 * someone else's work.
 *
 * Concurrency: every guarded UPDATE is forced onto the PRIMARY key. Otherwise MariaDB resolves
 * "WHERE id = ? AND status IN (…) AND locked_until …" through ix_*_status (status, locked_until) as a
 * range scan, next-key-locking every row in that status — two workers (cron + admin console + verifier
 * submits) then deadlock on each other's index records. Deadlocks / lock-wait timeouts that still happen
 * are retried (retrying()), never surfaced as a crashed worker.
 */
declare(strict_types=1);

namespace WB\Eval;

defined('WB') || exit;

use DB;

final class Lease
{
    private const TABLES = ['runs', 'judgments'];
    private const COUNTERS = ['tries', 'verify_tries'];

    /**
     * Atomically move row $id from one of $from to $to with a lease of $seconds.
     * Optionally increments a tries counter, refusing rows whose counter already reached $maxTries.
     * Returns the new lock token, or null when the row is not claimable (someone else won).
     */
    public static function claim(string $table, int $id, array $from, string $to, int $seconds, ?string $counter = null, ?int $maxTries = null): ?string
    {
        self::assertTable($table);
        if ($counter !== null && !in_array($counter, self::COUNTERS, true)) {
            throw new \InvalidArgumentException('Unknown counter ' . $counter);
        }
        if (!$from) {
            return null;
        }
        $token = random_token(16);
        $set = 'status = ?, locked_until = NOW() + INTERVAL ? SECOND, lock_token = ?';
        $params = [$to, max(1, $seconds), $token];
        if ($counter !== null) {
            $set .= ", `$counter` = `$counter` + 1";
        }
        $sql = "UPDATE `$table` FORCE INDEX (PRIMARY) SET $set WHERE id = ? AND status IN (" . DB::in($from) . ')'
            . ' AND (locked_until IS NULL OR locked_until < NOW())';
        $params[] = $id;
        array_push($params, ...array_values($from));
        if ($counter !== null && $maxTries !== null) {
            $sql .= " AND `$counter` < ?";
            $params[] = $maxTries;
        }
        return self::retrying(static fn(): bool => DB::exec($sql, $params) === 1) ? $token : null;
    }

    /**
     * Guarded write. $opts:
     *   token     => string  only when lock_token matches (the caller still owns the lease)
     *   status    => array   only when the current status is one of these
     *   free      => bool    only when no live lease exists
     *   lease     => ?int    null (default) clears the lease; N keeps/sets locked_until = NOW()+N s
     *   new_token => ?string lock token to store together with a lease (null = none, i.e. a backoff)
     * Returns the number of changed rows (0 = the guard failed).
     */
    public static function write(string $table, int $id, array $set, array $opts = []): int
    {
        self::assertTable($table);
        $cols = [];
        $params = [];
        foreach ($set as $col => $val) {
            if (!preg_match('/^[a-z_][a-z0-9_]*$/', (string) $col)) {
                throw new \InvalidArgumentException('Bad column ' . $col);
            }
            $cols[] = "`$col` = ?";
            $params[] = is_bool($val) ? (int) $val : $val;
        }
        $lease = $opts['lease'] ?? null;
        if ($lease === null) {
            $cols[] = 'locked_until = NULL';
            $cols[] = 'lock_token = NULL';
        } else {
            $cols[] = 'locked_until = NOW() + INTERVAL ? SECOND';
            $params[] = max(1, (int) $lease);
            $cols[] = 'lock_token = ?';
            $params[] = $opts['new_token'] ?? null;
        }
        $where = 'id = ?';
        $params[] = $id;
        if (isset($opts['token'])) {
            $where .= ' AND lock_token = ?';
            $params[] = (string) $opts['token'];
        }
        if (!empty($opts['status'])) {
            $where .= ' AND status IN (' . DB::in($opts['status']) . ')';
            array_push($params, ...array_values($opts['status']));
        }
        if (!empty($opts['free'])) {
            $where .= ' AND (locked_until IS NULL OR locked_until < NOW())';
        }
        $sql = "UPDATE `$table` FORCE INDEX (PRIMARY) SET " . implode(', ', $cols) . " WHERE $where";
        return self::retrying(static fn(): int => DB::exec($sql, $params));
    }

    /** True for a MySQL/MariaDB deadlock (1213) or lock wait timeout (1205): safe to retry. */
    public static function isLockConflict(\Throwable $e): bool
    {
        if (!$e instanceof \PDOException) {
            return false;
        }
        $code = (int) ($e->errorInfo[1] ?? 0);
        return $code === 1213 || $code === 1205 || (string) $e->getCode() === '40001';
    }

    /**
     * Run $fn — one statement, or a whole DB::tx() — and retry it (up to $attempts times, short random
     * back-off) when it lost a deadlock or timed out waiting for a lock. Inside an open transaction nothing
     * is retried here: the conflict rolled that transaction back, so only its owner may run it again.
     */
    public static function retrying(callable $fn, int $attempts = 4): mixed
    {
        for ($i = 1; ; $i++) {
            try {
                return $fn();
            } catch (\PDOException $e) {
                if ($i >= $attempts || !self::isLockConflict($e) || DB::pdo()->inTransaction()) {
                    throw $e;
                }
                usleep(random_int(5, 40) * 1000 * $i);
            }
        }
    }

    private static function assertTable(string $table): void
    {
        if (!in_array($table, self::TABLES, true)) {
            throw new \InvalidArgumentException('Lease table not allowed: ' . $table);
        }
    }
}
