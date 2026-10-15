<?php
/**
 * Read-side queries for the public task library and task pages (module: public).
 * Hold-out tasks are never returned by anything here except as a count.
 */
declare(strict_types=1);

namespace WB\Data;

defined('WB') || exit;

use DB;

final class Tasks
{
    public const OUTPUT_TYPES = ['html', 'js', 'answer', 'agent', 'text'];

    private const COLUMNS = 't.id, t.slug, t.track_id, t.title_ar, t.title_en, t.summary_ar, t.summary_en, t.output_type,
        t.difficulty, t.rubric_json, t.checks_json, t.auto_weight, t.visibility, t.is_active, t.version, t.season, t.created_at, t.updated_at,
        tr.slug AS track_slug, tr.name_ar, tr.name_en, tr.icon, tr.color, tr.auto_weight AS track_auto_weight';

    /**
     * Active public tasks with optional filters: track (slug), difficulty (1-4), type (output type), q (search).
     */
    public static function listPublic(array $f = []): array
    {
        $where = ["t.visibility = 'public'", 't.is_active = 1', 'tr.is_active = 1'];
        $params = [];
        if (!empty($f['track'])) {
            $where[] = 'tr.slug = ?';
            $params[] = (string) $f['track'];
        }
        if (!empty($f['difficulty'])) {
            $where[] = 't.difficulty = ?';
            $params[] = (int) $f['difficulty'];
        }
        if (!empty($f['type'])) {
            $where[] = 't.output_type = ?';
            $params[] = (string) $f['type'];
        }
        if (($f['q'] ?? '') !== '') {
            $like = '%' . addcslashes((string) $f['q'], '%_\\') . '%';
            $where[] = '(t.title_en LIKE ? OR t.title_ar LIKE ? OR t.summary_en LIKE ? OR t.summary_ar LIKE ? OR t.slug LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like);
        }
        return DB::all(
            'SELECT ' . self::COLUMNS . ' FROM tasks t JOIN tracks tr ON tr.id = t.track_id
              WHERE ' . implode(' AND ', $where) . ' ORDER BY tr.sort, t.difficulty, t.id',
            $params
        );
    }

    /** Public task by slug (active or retired) or null — hold-out tasks are never returned. */
    public static function findPublic(string $slug): ?array
    {
        return DB::one(
            'SELECT ' . self::COLUMNS . ', t.prompt FROM tasks t JOIN tracks tr ON tr.id = t.track_id
              WHERE t.slug = ? AND t.visibility = \'public\'',
            [$slug]
        );
    }

    /** Best model per public task this season: [task_id => ['model' => row, 'score' => mean]]. */
    public static function bestModels(string $season): array
    {
        $rows = DB::all(
            "SELECT r.task_id, r.model_id, AVG(r.final_score) AS mean_score
               FROM runs r JOIN tasks t ON t.id = r.task_id JOIN campaigns c ON c.id = r.campaign_id
              WHERE t.visibility = 'public' AND " . Models::COUNTED . '
              GROUP BY r.task_id, r.model_id',
            [$season]
        );
        $best = [];
        foreach ($rows as $r) {
            $tid = (int) $r['task_id'];
            if (!isset($best[$tid]) || (float) $r['mean_score'] > $best[$tid]['score']) {
                $best[$tid] = ['model_id' => (int) $r['model_id'], 'score' => (float) $r['mean_score']];
            }
        }
        if (!$best) {
            return [];
        }
        $ids = array_values(array_unique(array_column($best, 'model_id')));
        $models = DB::keyed('SELECT id, slug, name, provider, provider_name FROM models WHERE id IN (' . DB::in($ids) . ')', $ids);
        foreach ($best as $tid => $b) {
            $best[$tid]['model'] = $models[$b['model_id']] ?? null;
        }
        return $best;
    }

    /** Per-model results on one task: mean ± sd over attempts, best run, auto/judge split, cost, latency. */
    public static function results(int $taskId, string $season): array
    {
        $rows = DB::all(
            'SELECT m.id AS model_id, m.slug, m.name, m.provider, m.provider_name, m.open_weights,
                    COUNT(*) AS attempts, AVG(r.final_score) AS mean_score, STDDEV_SAMP(r.final_score) AS sd,
                    MIN(r.final_score) AS min_score, MAX(r.final_score) AS max_score,
                    AVG(r.auto_score) AS auto_mean, AVG(r.judge_score) AS judge_mean,
                    AVG(r.cost_usd) AS cost, AVG(r.latency_ms) AS latency_ms
               FROM runs r JOIN tasks t ON t.id = r.task_id JOIN campaigns c ON c.id = r.campaign_id
               JOIN models m ON m.id = r.model_id
              WHERE r.task_id = ? AND ' . Models::COUNTED . '
              GROUP BY m.id, m.slug, m.name, m.provider, m.provider_name, m.open_weights
              ORDER BY mean_score DESC',
            [$taskId, $season]
        );
        if (!$rows) {
            return [];
        }
        $best = [];
        foreach (DB::all(
            'SELECT r.model_id, r.id FROM runs r JOIN tasks t ON t.id = r.task_id JOIN campaigns c ON c.id = r.campaign_id
              WHERE r.task_id = ? AND ' . Models::COUNTED . ' ORDER BY r.final_score DESC, r.id DESC',
            [$taskId, $season]
        ) as $r) {
            $best[(int) $r['model_id']] ??= (int) $r['id'];
        }
        foreach ($rows as &$r) {
            $r['best_run'] = $best[(int) $r['model_id']] ?? null;
        }
        unset($r);
        return $rows;
    }

    /** Rendered outputs for the gallery: best scored run per model, screenshots first. */
    public static function gallery(int $taskId, int $limit = 12): array
    {
        $rows = DB::all(
            "SELECT r.id, r.model_id, r.final_score, r.screenshot, r.attempt, m.slug, m.name, m.provider, m.provider_name
               FROM runs r JOIN models m ON m.id = r.model_id
              WHERE r.task_id = ? AND r.status = 'scored'
              ORDER BY (r.screenshot IS NULL OR r.screenshot = ''), r.final_score DESC, r.id DESC",
            [$taskId]
        );
        $out = [];
        foreach ($rows as $r) {
            $mid = (int) $r['model_id'];
            if (isset($out[$mid])) {
                continue;
            }
            $r['shot_url'] = Runs::shotUrl($r['screenshot']);
            $out[$mid] = $r;
            if (count($out) >= $limit) {
                break;
            }
        }
        return array_values($out);
    }

    /**
     * How a task is graded: subset of static|browser|tests|answer|agent|judges (in that order).
     */
    public static function grading(array $task): array
    {
        $checks = \json_arr($task['checks_json'] ?? null);
        $rubric = \json_arr($task['rubric_json'] ?? null);
        $out = [];
        foreach (['static', 'browser', 'tests', 'answer', 'agent'] as $k) {
            if (!empty($checks[$k])) {
                $out[] = $k;
            }
        }
        if ($rubric && self::autoWeight($task) < 1.0) {
            $out[] = 'judges';
        }
        return $out;
    }

    /** Effective automated-score weight a (task override, else track default; 1.0 without a rubric). */
    public static function autoWeight(array $task): float
    {
        if (!\json_arr($task['rubric_json'] ?? null)) {
            return 1.0;
        }
        $a = $task['auto_weight'] ?? null;
        if ($a === null || $a === '') {
            $a = $task['track_auto_weight'] ?? 0.4;
        }
        return (float) \clamp((float) $a, 0, 1);
    }

    /**
     * Rubric criteria normalised for public pages: [['key','label','weight','pct']].
     * The judge guide is deliberately left out: it describes the expected solution (contamination).
     */
    public static function rubric(array $task): array
    {
        $crit = array_values(array_filter(\json_arr($task['rubric_json'] ?? null), 'is_array'));
        $total = array_sum(array_map(static fn($c) => max(0, (float) ($c['weight'] ?? 1)), $crit)) ?: 1;
        return array_map(static fn($c) => [
            'key' => (string) ($c['key'] ?? ''),
            'label' => \L($c, 'label') ?: (string) ($c['key'] ?? ''),
            'weight' => (float) ($c['weight'] ?? 1),
            'pct' => 100 * max(0, (float) ($c['weight'] ?? 1)) / $total,
        ], $crit);
    }

    /** Automated checks as display rows: [['group','id','type','label','weight']] (+ tests / answer summary rows). */
    public static function checkList(array $task): array
    {
        $checks = \json_arr($task['checks_json'] ?? null);
        $out = [];
        foreach (['static', 'browser'] as $g) {
            foreach ((array) ($checks[$g] ?? []) as $c) {
                if (!is_array($c)) {
                    continue;
                }
                $out[] = [
                    'group' => $g, 'id' => (string) ($c['id'] ?? ''), 'type' => (string) ($c['type'] ?? ''),
                    'label' => \L($c, 'label') ?: (string) ($c['id'] ?? $c['type'] ?? ''), 'weight' => (float) ($c['weight'] ?? 1),
                ];
            }
        }
        if (!empty($checks['tests']['cases']) && is_array($checks['tests']['cases'])) {
            $out[] = ['group' => 'tests', 'id' => 'tests', 'type' => 'tests', 'label' => '', 'weight' => (float) ($checks['tests']['weight'] ?? 6), 'count' => count($checks['tests']['cases'])];
        }
        if (!empty($checks['answer']) && is_array($checks['answer'])) {
            $out[] = ['group' => 'answer', 'id' => 'answer', 'type' => (string) ($checks['answer']['type'] ?? 'exact'), 'label' => '', 'weight' => 1.0];
        }
        if (!empty($checks['agent']['goal_checks']) && is_array($checks['agent']['goal_checks'])) {
            $out[] = ['group' => 'agent', 'id' => 'agent', 'type' => 'agent', 'label' => '', 'weight' => 1.0, 'count' => count($checks['agent']['goal_checks'])];
        }
        return $out;
    }
}
