<?php
/**
 * Leaderboard read model — the one place that turns model_scores into display rows.
 * Used by home, leaderboard, compare, advisor, value, hardware, API, badges and embeds.
 */
declare(strict_types=1);

namespace WB\Data;

defined('WB') || exit;

use DB;

final class Leaderboard
{
    private static array $cache = [];

    /**
     * All scored models for a season.
     *
     * Row keys: id slug name provider provider_name open_weights params_b active_params_b context_length
     *   price_in price_out supports_vision color release_date description
     *   score ci_low ci_high rank_pos stat_rank provisional coverage n_tasks n_runs consistency
     *   auto_avg judge_avg avg_cost avg_latency_ms avg_tokens updated_at
     *   tracks => [slug => ['score','ci_low','ci_high','n_tasks','n_runs']]
     *   arena  => ['rating','ci_low','ci_high','votes'] | null
     *
     * $opts: 'track' => slug  (sort by that track; rows without it go last)
     *        'include_provisional' => bool (default true)
     */
    public static function rows(?string $season = null, array $opts = []): array
    {
        $season ??= Catalog::season();
        $key = $season . '|' . json_encode($opts);
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        $base = DB::all(
            "SELECT m.id, m.slug, m.name, m.provider, m.provider_name, m.open_weights, m.params_b, m.active_params_b,
                    m.context_length, m.price_in, m.price_out, m.supports_vision, m.color, m.release_date, m.description,
                    s.score, s.ci_low, s.ci_high, s.rank_pos, s.stat_rank, s.provisional, s.coverage, s.n_tasks, s.n_runs,
                    s.consistency, s.auto_avg, s.judge_avg, s.avg_cost, s.avg_latency_ms, s.avg_tokens, s.updated_at
               FROM model_scores s
               JOIN models m ON m.id = s.model_id
              WHERE s.season = ? AND s.scope = 'overall'",
            [$season]
        );
        if (!$base) {
            return self::$cache[$key] = [];
        }
        $ids = array_map(static fn($r) => (int) $r['id'], $base);
        $trackRows = DB::all(
            "SELECT model_id, scope, score, ci_low, ci_high, n_tasks, n_runs FROM model_scores
              WHERE season = ? AND scope LIKE 'track:%' AND model_id IN (" . DB::in($ids) . ')',
            array_merge([$season], $ids)
        );
        $tracks = [];
        foreach ($trackRows as $t) {
            $tracks[(int) $t['model_id']][substr($t['scope'], 6)] = [
                'score' => (float) $t['score'],
                'ci_low' => $t['ci_low'] !== null ? (float) $t['ci_low'] : null,
                'ci_high' => $t['ci_high'] !== null ? (float) $t['ci_high'] : null,
                'n_tasks' => (int) $t['n_tasks'],
                'n_runs' => (int) $t['n_runs'],
            ];
        }
        $arena = DB::keyed('SELECT model_id, rating, ci_low, ci_high, votes FROM arena_ratings WHERE model_id IN (' . DB::in($ids) . ')', $ids);

        $rows = [];
        foreach ($base as $r) {
            $id = (int) $r['id'];
            foreach (['score', 'ci_low', 'ci_high', 'coverage', 'consistency', 'auto_avg', 'judge_avg', 'avg_cost', 'price_in', 'price_out', 'params_b', 'active_params_b'] as $f) {
                $r[$f] = $r[$f] !== null ? (float) $r[$f] : null;
            }
            foreach (['id', 'rank_pos', 'stat_rank', 'n_tasks', 'n_runs', 'avg_latency_ms', 'avg_tokens', 'context_length'] as $f) {
                $r[$f] = $r[$f] !== null ? (int) $r[$f] : null;
            }
            $r['open_weights'] = (bool) $r['open_weights'];
            $r['provisional'] = (bool) $r['provisional'];
            $r['supports_vision'] = (bool) $r['supports_vision'];
            $r['color'] = $r['color'] ?: \ui_provider_color((string) $r['provider']);
            $r['tracks'] = $tracks[$id] ?? [];
            $a = $arena[$id] ?? null;
            $r['arena'] = $a ? ['rating' => (float) $a['rating'], 'ci_low' => $a['ci_low'] !== null ? (float) $a['ci_low'] : null, 'ci_high' => $a['ci_high'] !== null ? (float) $a['ci_high'] : null, 'votes' => (int) $a['votes']] : null;
            if (!($opts['include_provisional'] ?? true) && $r['provisional']) {
                continue;
            }
            $rows[] = $r;
        }

        $track = $opts['track'] ?? null;
        usort($rows, static function (array $a, array $b) use ($track): int {
            if ($track) {
                $sa = $a['tracks'][$track]['score'] ?? null;
                $sb = $b['tracks'][$track]['score'] ?? null;
                if ($sa === null || $sb === null) {
                    return ($sa === null) <=> ($sb === null);
                }
                return $sb <=> $sa;
            }
            if ($a['provisional'] !== $b['provisional']) {
                return $a['provisional'] <=> $b['provisional'];
            }
            return $b['score'] <=> $a['score'];
        });
        return self::$cache[$key] = $rows;
    }

    /** Single model row (or null when the model has no scores this season). */
    public static function row(string $slug, ?string $season = null): ?array
    {
        foreach (self::rows($season) as $r) {
            if ($r['slug'] === $slug) {
                return $r;
            }
        }
        return null;
    }

    /** Headline numbers for KPIs. */
    public static function stats(?string $season = null): array
    {
        $season ??= Catalog::season();
        $counts = Catalog::taskCounts();
        return [
            'models_rated' => (int) DB::val("SELECT COUNT(*) FROM model_scores WHERE season = ? AND scope = 'overall'", [$season]),
            'tasks' => $counts['total'],
            'tasks_public' => $counts['public'],
            'tasks_holdout' => $counts['holdout'],
            'tracks' => count(Catalog::tracks()),
            'runs_scored' => (int) DB::val("SELECT COUNT(*) FROM runs WHERE status = 'scored'"),
            'judgments' => (int) DB::val("SELECT COUNT(*) FROM judgments WHERE status = 'done'"),
            'browser_checks' => (int) DB::val('SELECT COUNT(*) FROM runs WHERE verified = 1'),
            'arena_votes' => (int) DB::val('SELECT COUNT(*) FROM arena_votes'),
            'last_updated' => DB::val("SELECT MAX(updated_at) FROM model_scores WHERE season = ?", [$season]),
        ];
    }

    /** Daily score history for one model: [['date','score','ci_low','ci_high','rank_pos'],…] oldest first. */
    public static function history(int $modelId, ?string $season = null, int $days = 120): array
    {
        $season ??= Catalog::season();
        return DB::all(
            'SELECT recorded_on AS date, score, ci_low, ci_high, rank_pos FROM score_history
              WHERE model_id = ? AND season = ? AND recorded_on >= ? ORDER BY recorded_on',
            [$modelId, $season, gmdate('Y-m-d', time() - 86400 * $days)]
        );
    }
}
