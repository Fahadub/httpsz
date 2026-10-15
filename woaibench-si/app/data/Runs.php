<?php
/**
 * Read-side helpers for the public run page (module: public).
 * Everything returned here is raw data: templates must escape it (model output is untrusted).
 */
declare(strict_types=1);

namespace WB\Data;

defined('WB') || exit;

use DB;

final class Runs
{
    /** Scored run on a public task with task, track, model and campaign fields — or null (hold-out, unknown, unscored). */
    public static function findPublic(int $id): ?array
    {
        $run = DB::one(
            "SELECT r.*, t.slug AS task_slug, t.title_ar, t.title_en, t.summary_ar, t.summary_en, t.output_type, t.difficulty,
                    t.rubric_json, t.checks_json, t.auto_weight AS task_auto_weight, t.visibility, t.is_active AS task_active,
                    tr.slug AS track_slug, tr.name_ar, tr.name_en, tr.icon, tr.color, tr.auto_weight AS track_auto_weight,
                    m.slug AS model_slug, m.name AS model_name, m.provider, m.provider_name, m.open_weights, m.supports_vision,
                    c.name AS campaign_name, c.season AS campaign_season, c.runs_per_task, c.include_in_scores
               FROM runs r
               JOIN tasks t ON t.id = r.task_id
               JOIN tracks tr ON tr.id = t.track_id
               JOIN models m ON m.id = r.model_id
               JOIN campaigns c ON c.id = r.campaign_id
              WHERE r.id = ? AND t.visibility = 'public' AND r.status = 'scored'",
            [$id]
        );
        return $run ?: null;
    }

    /** All scored attempts of the same model on the same task (any campaign), oldest campaign first. */
    public static function attempts(array $run): array
    {
        return DB::all(
            "SELECT id, campaign_id, attempt, final_score FROM runs
              WHERE model_id = ? AND task_id = ? AND status = 'scored' ORDER BY campaign_id, attempt, id",
            [(int) $run['model_id'], (int) $run['task_id']]
        );
    }

    /** Public URL of a stored screenshot (only when the file exists and the path is clean). */
    public static function shotUrl(?string $rel): ?string
    {
        $rel = ltrim((string) $rel, '/');
        if ($rel === '' || str_contains($rel, '..') || !preg_match('#^[A-Za-z0-9_\-./]+$#', $rel)) {
            return null;
        }
        return is_file(WB_UPLOADS . '/' . $rel) ? \upload_url($rel) : null;
    }

    /** Completed judgments with judge model and parsed rationale/criteria. */
    public static function judgments(array $run): array
    {
        $rows = DB::all(
            "SELECT j.id, j.role, j.score, j.criteria_json, j.rationale, j.used_vision, j.latency_ms, j.cost_usd,
                    m.slug, m.name, m.provider, m.provider_name
               FROM judgments j JOIN models m ON m.id = j.judge_model_id
              WHERE j.run_id = ? AND j.status = 'done'
              ORDER BY (j.role <> 'panel'), j.id",
            [(int) $run['id']]
        );
        $labels = [];
        foreach (\json_arr($run['rubric_json'] ?? null) as $c) {
            if (is_array($c) && isset($c['key'])) {
                $labels[(string) $c['key']] = \L($c, 'label') ?: (string) $c['key'];
            }
        }
        foreach ($rows as &$j) {
            $j['criteria'] = self::criteria($j['criteria_json'], $labels);
            $j['rationale_parsed'] = self::rationale($j['rationale']);
        }
        unset($j);
        return $rows;
    }

    /** Criteria scores [['key','label','score']] in rubric order, unknown keys last. */
    private static function criteria(?string $json, array $labels): array
    {
        $raw = \json_arr($json);
        if (isset($raw['criteria']) && is_array($raw['criteria'])) {
            $raw = $raw['criteria'];
        }
        $out = [];
        foreach ($labels as $key => $label) {
            if (isset($raw[$key]) && is_numeric($raw[$key])) {
                $out[] = ['key' => $key, 'label' => $label, 'score' => \clamp((float) $raw[$key], 0, 10)];
                unset($raw[$key]);
            }
        }
        foreach ($raw as $key => $v) {
            if (is_numeric($v)) {
                $out[] = ['key' => (string) $key, 'label' => (string) $key, 'score' => \clamp((float) $v, 0, 10)];
            }
        }
        return $out;
    }

    /** Judge rationale → ['summary' => string, 'strengths' => string[], 'weaknesses' => string[]]. */
    public static function rationale(?string $text): array
    {
        $out = ['summary' => '', 'strengths' => [], 'weaknesses' => []];
        $text = trim((string) $text);
        if ($text === '') {
            return $out;
        }
        $data = json_decode($text, true);
        if (!is_array($data)) {
            $out['summary'] = $text;
            return $out;
        }
        $out['summary'] = is_string($data['summary'] ?? null) ? $data['summary'] : '';
        foreach (['strengths', 'weaknesses'] as $k) {
            $list = $data[$k] ?? [];
            $list = is_string($list) ? [$list] : (is_array($list) ? $list : []);
            $out[$k] = array_values(array_filter(array_map(static fn($s) => is_scalar($s) ? (string) $s : '', $list), static fn($s) => $s !== ''));
        }
        return $out;
    }

    /**
     * Automated check results grouped for display:
     * ['static' => rows, 'browser' => rows, 'tests' => rows, 'errors' => string[], 'metrics' => array, 'verified' => bool]
     * row: ['id','type','label','pass','score','weight','detail'] / test row: ['name','pass','ms','got','error'].
     */
    public static function checks(array $run): array
    {
        $def = \json_arr($run['checks_json'] ?? null);
        $static = \json_arr($run['static_json'] ?? null);
        $browser = \json_arr($run['browser_json'] ?? null);
        if (isset($static['checks']) && is_array($static['checks'])) {
            $static = $static['checks'];
        }
        return [
            'static' => self::checkRows(array_is_list($static) ? $static : [], self::labelMap($def['static'] ?? [])),
            'browser' => self::checkRows((array) ($browser['checks'] ?? []), self::labelMap($def['browser'] ?? [])),
            'tests' => self::testRows((array) ($browser['tests'] ?? [])),
            'errors' => array_values(array_filter(array_map(static fn($e) => is_scalar($e) ? (string) $e : \to_json($e), (array) ($browser['errors'] ?? [])), static fn($s) => $s !== '')),
            'metrics' => is_array($browser['metrics'] ?? null) ? $browser['metrics'] : [],
            'verified' => (bool) ($run['verified'] ?? false),
        ];
    }

    private static function labelMap(mixed $defs): array
    {
        $map = [];
        foreach ((array) $defs as $d) {
            if (is_array($d) && isset($d['id'])) {
                $map[(string) $d['id']] = ['label' => \L($d, 'label'), 'weight' => (float) ($d['weight'] ?? 1)];
            }
        }
        return $map;
    }

    private static function checkRows(array $results, array $labels): array
    {
        $out = [];
        foreach ($results as $c) {
            if (!is_array($c)) {
                continue;
            }
            $id = (string) ($c['id'] ?? $c['type'] ?? '');
            $label = $labels[$id]['label'] ?? '';
            $out[] = [
                'id' => $id,
                'type' => (string) ($c['type'] ?? ''),
                'label' => $label !== '' ? $label : (\L($c, 'label') ?: $id),
                'pass' => !empty($c['pass']),
                'score' => isset($c['score']) && is_numeric($c['score']) ? \clamp((float) $c['score'], 0, 1) : (!empty($c['pass']) ? 1.0 : 0.0),
                'weight' => (float) ($c['weight'] ?? $labels[$id]['weight'] ?? 1),
                'detail' => is_scalar($c['detail'] ?? null) ? (string) $c['detail'] : (isset($c['detail']) ? \to_json($c['detail']) : ''),
            ];
        }
        return $out;
    }

    private static function testRows(array $tests): array
    {
        $out = [];
        foreach ($tests as $t) {
            if (!is_array($t)) {
                continue;
            }
            $got = array_key_exists('got', $t) ? (is_string($t['got']) ? $t['got'] : \to_json($t['got'])) : null;
            $out[] = [
                'name' => (string) ($t['name'] ?? ''),
                'pass' => !empty($t['pass']),
                'ms' => isset($t['ms']) && is_numeric($t['ms']) ? (float) $t['ms'] : null,
                'got' => $got !== null ? mb_substr($got, 0, 600) : null,
                'error' => isset($t['error']) && is_scalar($t['error']) ? mb_substr((string) $t['error'], 0, 600) : null,
            ];
        }
        return $out;
    }

    /** The model's final answer for answer tasks: ['given' => ?string, 'expected' => mixed, 'type' => string, 'pass' => ?bool]. */
    public static function answer(array $run): array
    {
        $raw = (string) ($run['raw_output'] ?? '');
        $given = null;
        if (preg_match_all('#<answer>(.*?)</answer>#is', $raw, $m) && $m[1]) {
            $given = trim((string) end($m[1]));
        }
        $spec = \json_arr($run['checks_json'] ?? null)['answer'] ?? [];
        $spec = is_array($spec) ? $spec : [];
        $expected = $spec['value'] ?? ($spec['values'] ?? ($spec['pattern'] ?? null));
        return [
            'given' => $given,
            'expected' => $expected,
            'type' => (string) ($spec['type'] ?? 'exact'),
            'tolerance' => $spec['tolerance'] ?? null,
            'pass' => $run['auto_score'] !== null ? (float) $run['auto_score'] >= 99.5 : null,
        ];
    }

    /**
     * Agent transcript normalised to steps: [['kind' => 'tool'|'message'|'note', 'tool','args','result','text','error']]
     * plus 'final' (answer) and 'goals' (goal-check results) when the engine stored them.
     */
    public static function transcript(array $run): array
    {
        $data = \json_arr($run['agent_json'] ?? null);
        $list = array_is_list($data) ? $data : ($data['steps'] ?? $data['transcript'] ?? $data['messages'] ?? []);
        $steps = [];
        foreach ((array) $list as $s) {
            if (is_array($s)) {
                array_push($steps, ...self::stepItems($s));
            }
        }
        $final = $data['final_answer'] ?? $data['answer'] ?? $data['final'] ?? null;
        $goals = $data['goal_checks'] ?? $data['goals'] ?? $data['checks'] ?? [];
        return [
            'steps' => $steps,
            'final' => is_scalar($final) ? (string) $final : ($final !== null ? \to_json($final) : null),
            'goals' => array_values(array_filter((array) $goals, 'is_array')),
            'raw' => $steps ? '' : (string) ($run['agent_json'] ?? ''),
        ];
    }

    /** One transcript entry → display items (an OpenAI-style message may carry several tool calls). */
    private static function stepItems(array $s): array
    {
        $str = static fn($v) => $v === null ? '' : (is_string($v) ? $v : \to_json($v));
        $items = [];
        $text = $s['thought'] ?? $s['text'] ?? (isset($s['role']) ? ($s['content'] ?? null) : null);
        if ($text !== null && $str($text) !== '') {
            $items[] = ['kind' => ($s['role'] ?? '') === 'tool' ? 'note' : 'message', 'role' => (string) ($s['role'] ?? 'assistant'), 'text' => $str($text)];
        }
        if (isset($s['tool']) || isset($s['name']) && !isset($s['role'])) {
            $items[] = [
                'kind' => 'tool', 'tool' => (string) ($s['tool'] ?? $s['name']),
                'args' => $str($s['args'] ?? $s['arguments'] ?? $s['input'] ?? null),
                'result' => $str($s['result'] ?? $s['output'] ?? $s['observation'] ?? null),
                'error' => $str($s['error'] ?? null),
            ];
        }
        foreach ((array) ($s['tool_calls'] ?? []) as $tc) {
            if (is_array($tc)) {
                $fn = is_array($tc['function'] ?? null) ? $tc['function'] : $tc;
                $items[] = ['kind' => 'tool', 'tool' => (string) ($fn['name'] ?? '?'), 'args' => $str($fn['arguments'] ?? $fn['args'] ?? null), 'result' => $str($tc['result'] ?? null), 'error' => ''];
            }
        }
        return $items;
    }
}
