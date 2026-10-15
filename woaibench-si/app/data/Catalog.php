<?php
/**
 * Read-side helpers for tracks, tasks and seasons (shared by every public module).
 */
declare(strict_types=1);

namespace WB\Data;

defined('WB') || exit;

use DB;

final class Catalog
{
    private static ?array $tracks = null;
    /** @var array<string,int> runsPerTask() per season (request cache). */
    private static array $runs = [];

    /** Current season id, e.g. "2026.10". */
    public static function season(): string
    {
        return (string) \setting('season');
    }

    /** Seasons that have scores, newest first (always includes the current season). */
    public static function seasons(): array
    {
        $list = DB::col('SELECT DISTINCT season FROM model_scores ORDER BY season DESC');
        $cur = self::season();
        if (!in_array($cur, $list, true)) {
            array_unshift($list, $cur);
        }
        return $list;
    }

    /** Active tracks ordered by sort, keyed by slug. Each row also has task_count / public_count / holdout_count. */
    public static function tracks(): array
    {
        if (self::$tracks !== null) {
            return self::$tracks;
        }
        // Counts come from a derived table: "SELECT tr.* … GROUP BY tr.id" is rejected by MariaDB under
        // ONLY_FULL_GROUP_BY (it does not detect that tr.* depends on the primary key).
        $rows = DB::all(
            "SELECT tr.*,
                    COALESCE(x.task_count, 0) AS task_count,
                    COALESCE(x.public_count, 0) AS public_count,
                    COALESCE(x.holdout_count, 0) AS holdout_count
               FROM tracks tr
               LEFT JOIN (SELECT track_id,
                                 SUM(is_active = 1) AS task_count,
                                 SUM(is_active = 1 AND visibility = 'public') AS public_count,
                                 SUM(is_active = 1 AND visibility = 'holdout') AS holdout_count
                            FROM tasks GROUP BY track_id) x ON x.track_id = tr.id
              WHERE tr.is_active = 1
              ORDER BY tr.sort, tr.id"
        );
        $out = [];
        foreach ($rows as $r) {
            foreach (['task_count', 'public_count', 'holdout_count'] as $k) {
                $r[$k] = (int) $r[$k];
            }
            $out[$r['slug']] = $r;
        }
        return self::$tracks = $out;
    }

    public static function track(string $slug): ?array
    {
        return self::tracks()[$slug] ?? null;
    }

    /**
     * Attempts per task behind a season's scores: the largest runs_per_task among that season's campaigns that
     * count towards scores; the runs_per_task setting while there is none yet (or the database is unavailable).
     */
    public static function runsPerTask(?string $season = null): int
    {
        $season ??= self::season();
        if (!isset(self::$runs[$season])) {
            try {
                $v = DB::val('SELECT MAX(runs_per_task) FROM campaigns WHERE season = ? AND include_in_scores = 1', [$season]);
            } catch (\Throwable) {
                $v = null;
            }
            self::$runs[$season] = max(1, $v !== null ? (int) $v : (int) \setting('runs_per_task', 3));
        }
        return self::$runs[$season];
    }

    /** Count of active tasks: ['total'=>, 'public'=>, 'holdout'=>]. */
    public static function taskCounts(): array
    {
        $r = DB::one("SELECT COUNT(*) AS total, COALESCE(SUM(visibility = 'public'),0) AS pub, COALESCE(SUM(visibility = 'holdout'),0) AS hold FROM tasks WHERE is_active = 1");
        return ['total' => (int) ($r['total'] ?? 0), 'public' => (int) ($r['pub'] ?? 0), 'holdout' => (int) ($r['hold'] ?? 0)];
    }
}
