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
        $rows = DB::all(
            "SELECT tr.*,
                    COALESCE(SUM(t.is_active = 1), 0) AS task_count,
                    COALESCE(SUM(t.is_active = 1 AND t.visibility = 'public'), 0) AS public_count,
                    COALESCE(SUM(t.is_active = 1 AND t.visibility = 'holdout'), 0) AS holdout_count
               FROM tracks tr
               LEFT JOIN tasks t ON t.track_id = tr.id
              WHERE tr.is_active = 1
              GROUP BY tr.id
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

    /** Count of active tasks: ['total'=>, 'public'=>, 'holdout'=>]. */
    public static function taskCounts(): array
    {
        $r = DB::one("SELECT COUNT(*) AS total, COALESCE(SUM(visibility = 'public'),0) AS pub, COALESCE(SUM(visibility = 'holdout'),0) AS hold FROM tasks WHERE is_active = 1");
        return ['total' => (int) ($r['total'] ?? 0), 'public' => (int) ($r['pub'] ?? 0), 'holdout' => (int) ($r['hold'] ?? 0)];
    }
}
