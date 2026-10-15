<?php
/**
 * Read-side queries for model pages, compare and value (module: public).
 * Scores always come from WB\Data\Leaderboard; this class adds per-task detail and derived views.
 */
declare(strict_types=1);

namespace WB\Data;

defined('WB') || exit;

use DB;

final class Models
{
    /** SQL fragment: runs that count for a season (same filter as the aggregator). */
    public const COUNTED = "r.status = 'scored' AND c.include_in_scores = 1 AND c.season = ? AND t.is_active = 1";

    /** Model catalogue row by slug (public columns only) or null. */
    public static function find(string $slug): ?array
    {
        $m = DB::one(
            'SELECT id, slug, or_id, name, provider, provider_name, description, open_weights, params_b, active_params_b,
                    context_length, price_in, price_out, supports_vision, supports_tools, release_date, is_active, is_judge, color
               FROM models WHERE slug = ?',
            [$slug]
        );
        if (!$m) {
            return null;
        }
        $m['color'] = $m['color'] ?: \ui_provider_color((string) $m['provider']);
        return $m;
    }

    /** Judge panel models in panel order. */
    public static function judges(): array
    {
        return DB::all('SELECT id, slug, name, provider, provider_name, supports_vision FROM models WHERE is_judge = 1 ORDER BY judge_order, id');
    }

    /**
     * Display ranking for the leaderboard. Adds d_score, d_ci_low, d_ci_high, d_rank, d_stat_rank,
     * tied_leader and tied_above to each row; ordered ranked → provisional → unscored-in-track.
     */
    public static function rank(array $rows, ?string $track = null): array
    {
        foreach ($rows as &$r) {
            $src = $track ? ($r['tracks'][$track] ?? null) : $r;
            $r['d_score'] = $src['score'] ?? null;
            $r['d_ci_low'] = $src['ci_low'] ?? null;
            $r['d_ci_high'] = $src['ci_high'] ?? null;
        }
        unset($r);
        usort($rows, static function (array $a, array $b): int {
            $ga = $a['d_score'] === null ? 2 : ($a['provisional'] ? 1 : 0);
            $gb = $b['d_score'] === null ? 2 : ($b['provisional'] ? 1 : 0);
            return $ga <=> $gb ?: ($b['d_score'] ?? 0) <=> ($a['d_score'] ?? 0);
        });
        $ranked = array_values(array_filter($rows, static fn($r) => !$r['provisional'] && $r['d_score'] !== null));
        $pos = 0;
        $leader = $ranked[0] ?? null;
        $prev = null;
        foreach ($rows as &$r) {
            $r['tied_leader'] = false;
            $r['tied_above'] = false;
            if ($r['provisional'] || $r['d_score'] === null) {
                $r['d_rank'] = null;
                $r['d_stat_rank'] = null;
                continue;
            }
            $r['d_rank'] = $track ? ++$pos : ($r['rank_pos'] ?? ++$pos);
            $r['d_stat_rank'] = $track ? self::statRank($r, $ranked) : ($r['stat_rank'] ?? self::statRank($r, $ranked));
            if ($leader && $leader['id'] !== $r['id']) {
                $r['tied_leader'] = self::overlaps($r, $leader);
            }
            if ($prev) {
                $r['tied_above'] = self::overlaps($r, $prev);
            }
            $prev = $r;
        }
        unset($r);
        return $rows;
    }

    /** True when the two rows' displayed 95% CIs overlap. */
    public static function overlaps(array $a, array $b): bool
    {
        if ($a['d_ci_low'] === null || $a['d_ci_high'] === null || $b['d_ci_low'] === null || $b['d_ci_high'] === null) {
            return false;
        }
        return $a['d_ci_low'] <= $b['d_ci_high'] && $b['d_ci_low'] <= $a['d_ci_high'];
    }

    private static function statRank(array $row, array $ranked): int
    {
        $n = 1;
        foreach ($ranked as $o) {
            if ($o['id'] !== $row['id'] && $o['d_ci_low'] !== null && $row['d_ci_high'] !== null && $o['d_ci_low'] > $row['d_ci_high']) {
                $n++;
            }
        }
        return $n;
    }

    /** Median track score across ranked (non-provisional) models: [slug => score]. */
    public static function trackMedians(array $rows): array
    {
        $by = [];
        foreach ($rows as $r) {
            if ($r['provisional']) {
                continue;
            }
            foreach ($r['tracks'] as $slug => $t) {
                $by[$slug][] = $t['score'];
            }
        }
        return array_map(static fn($xs) => \median($xs), $by);
    }

    /** Strongest and weakest tracks of a leaderboard row: ['top' => [...], 'bottom' => [...]] (slug => score). */
    public static function strengths(array $row, int $n = 3): array
    {
        $scores = array_map(static fn($t) => $t['score'], $row['tracks'] ?? []);
        arsort($scores);
        $count = count($scores);
        if ($count < 2) {
            return ['top' => $scores, 'bottom' => []];
        }
        $k = min($n, intdiv($count, 2));
        return ['top' => array_slice($scores, 0, $k, true), 'bottom' => array_reverse(array_slice($scores, -$k, $k, true), true)];
    }

    /**
     * Per-task statistics for one model this season.
     * $visibility 'public' returns task rows; hold-out data must only be shown via holdoutByTrack().
     */
    public static function taskStats(int $modelId, string $season, string $visibility = 'public'): array
    {
        $rows = DB::all(
            "SELECT t.id AS task_id, t.slug, t.title_ar, t.title_en, t.difficulty, t.output_type,
                    tr.slug AS track_slug, tr.name_ar, tr.name_en, tr.icon, tr.color,
                    COUNT(*) AS attempts, AVG(r.final_score) AS mean_score, STDDEV_SAMP(r.final_score) AS sd,
                    AVG(r.auto_score) AS auto_mean, AVG(r.judge_score) AS judge_mean, AVG(r.cost_usd) AS cost,
                    AVG(r.latency_ms) AS latency_ms, SUM(r.contested) AS contested
               FROM runs r
               JOIN tasks t ON t.id = r.task_id
               JOIN tracks tr ON tr.id = t.track_id
               JOIN campaigns c ON c.id = r.campaign_id
              WHERE r.model_id = ? AND t.visibility = ? AND " . self::COUNTED . '
              GROUP BY t.id, t.slug, t.title_ar, t.title_en, t.difficulty, t.output_type, tr.slug, tr.name_ar, tr.name_en, tr.icon, tr.color, tr.sort
              ORDER BY tr.sort, t.id',
            [$modelId, $visibility, $season]
        );
        if (!$rows || $visibility !== 'public') {
            return $rows;
        }
        $best = self::bestRuns($modelId, $season);
        foreach ($rows as &$r) {
            $r['best_run'] = $best[(int) $r['task_id']] ?? null;
        }
        unset($r);
        return $rows;
    }

    /** Best-scoring run id per public task for one model: [task_id => run_id]. */
    private static function bestRuns(int $modelId, string $season): array
    {
        $runs = DB::all(
            "SELECT r.task_id, r.id FROM runs r
               JOIN tasks t ON t.id = r.task_id JOIN campaigns c ON c.id = r.campaign_id
              WHERE r.model_id = ? AND t.visibility = 'public' AND " . self::COUNTED . '
              ORDER BY r.final_score DESC, r.id DESC',
            [$modelId, $season]
        );
        $out = [];
        foreach ($runs as $r) {
            $out[(int) $r['task_id']] ??= (int) $r['id'];
        }
        return $out;
    }

    /** Hold-out results aggregated per track only (never task-level): [slug => ['tasks','attempts','score']]. */
    public static function holdoutByTrack(int $modelId, string $season): array
    {
        $out = [];
        foreach (self::taskStats($modelId, $season, 'holdout') as $r) {
            $s = $r['track_slug'];
            $out[$s]['scores'][] = (float) $r['mean_score'];
            $out[$s]['attempts'] = ($out[$s]['attempts'] ?? 0) + (int) $r['attempts'];
        }
        foreach ($out as $s => $v) {
            $out[$s] = ['tasks' => count($v['scores']), 'attempts' => $v['attempts'], 'score' => \mean($v['scores'])];
        }
        return $out;
    }

    /** Judge agreement on this model's runs: ['runs','mean_spread','contested','contested_pct']. */
    public static function judgeAgreement(int $modelId, string $season): array
    {
        $r = DB::one(
            'SELECT COUNT(*) AS n, AVG(r.judge_spread) AS spread, COALESCE(SUM(r.contested), 0) AS contested
               FROM runs r JOIN tasks t ON t.id = r.task_id JOIN campaigns c ON c.id = r.campaign_id
              WHERE r.model_id = ? AND r.judge_score IS NOT NULL AND ' . self::COUNTED,
            [$modelId, $season]
        );
        $n = (int) ($r['n'] ?? 0);
        return [
            'runs' => $n,
            'mean_spread' => $r['spread'] !== null ? (float) $r['spread'] : null,
            'contested' => (int) ($r['contested'] ?? 0),
            'contested_pct' => $n ? 100 * (int) $r['contested'] / $n : null,
        ];
    }

    /** Recent public outputs (screenshot first) for the gallery. */
    public static function recentOutputs(int $modelId, int $limit = 8): array
    {
        $rows = DB::all(
            "SELECT r.id, r.final_score, r.screenshot, r.finished_at, r.attempt, t.slug AS task_slug, t.title_ar, t.title_en,
                    t.output_type, tr.slug AS track_slug, tr.name_ar, tr.name_en, tr.icon, tr.color
               FROM runs r JOIN tasks t ON t.id = r.task_id JOIN tracks tr ON tr.id = t.track_id
              WHERE r.model_id = ? AND r.status = 'scored' AND t.visibility = 'public'
              ORDER BY (r.screenshot IS NULL OR r.screenshot = ''), r.finished_at DESC, r.id DESC
              LIMIT " . max(1, min(48, $limit)),
            [$modelId]
        );
        foreach ($rows as &$r) {
            $r['shot_url'] = Runs::shotUrl($r['screenshot']);
        }
        unset($r);
        return $rows;
    }

    /**
     * Mean task score per (task, model) for public tasks: [task_id => [model_id => mean]].
     * Used by compare head-to-head.
     */
    public static function taskMatrix(array $modelIds, string $season): array
    {
        if (!$modelIds) {
            return [];
        }
        $rows = DB::all(
            "SELECT r.task_id, r.model_id, AVG(r.final_score) AS mean_score
               FROM runs r JOIN tasks t ON t.id = r.task_id JOIN campaigns c ON c.id = r.campaign_id
              WHERE t.visibility = 'public' AND r.model_id IN (" . DB::in($modelIds) . ') AND ' . self::COUNTED . '
              GROUP BY r.task_id, r.model_id',
            array_merge($modelIds, [$season])
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['task_id']][(int) $r['model_id']] = (float) $r['mean_score'];
        }
        return $out;
    }

    /** Pareto frontier (min cost, max score) over rows with both values; returns row ids on the frontier. */
    public static function paretoIds(array $rows, string $xKey = 'avg_cost'): array
    {
        $pts = array_values(array_filter($rows, static fn($r) => ($r[$xKey] ?? null) !== null && $r[$xKey] > 0 && $r['score'] !== null));
        usort($pts, static fn($a, $b) => [$a[$xKey], -$a['score']] <=> [$b[$xKey], -$b['score']]);
        $ids = [];
        $best = -INF;
        foreach ($pts as $p) {
            if ($p['score'] > $best) {
                $ids[] = $p['id'];
                $best = $p['score'];
            }
        }
        return $ids;
    }
}
