<?php
/**
 * Per-run scoring (ARCHITECTURE §5.3): automated score from static + browser checks + tests,
 * judge panel aggregation, blend with the effective auto weight and the render-fail factor.
 */
declare(strict_types=1);

namespace WB\Eval;

defined('WB') || exit;

use DB;
use Settings;

final class Scorer
{
    public const DEFAULT_TESTS_WEIGHT = 6.0;

    /** Score a run in status `scoring` (or re-score a `scored` run) and persist it. Returns the breakdown. */
    public static function score(int $runId): ?array
    {
        $run = DB::one('SELECT * FROM runs WHERE id = ?', [$runId]);
        if (!$run || !in_array($run['status'], ['scoring', 'scored'], true)) {
            return null;
        }
        $task = Pipeline::loadTask((int) $run['task_id']);
        if (!$task) {
            return null;
        }
        $judgeScores = array_map('floatval', DB::col(
            "SELECT score FROM judgments WHERE run_id = ? AND status = 'done' AND score IS NOT NULL ORDER BY id",
            [$runId]
        ));
        $r = self::compute($task, $run, $judgeScores);
        DB::exec(
            "UPDATE runs SET auto_score = ?, judge_score = ?, judge_spread = ?, final_score = ?, contested = ?,
                    status = 'scored', finished_at = ?, locked_until = NULL, lock_token = NULL
              WHERE id = ? AND status IN ('scoring', 'scored')",
            [
                self::r2($r['auto']), self::r2($r['judge']), self::r2($r['spread']), $r['final'],
                $r['contested'] ? 1 : 0, now(), $runId,
            ]
        );
        if ($r['render_fail']) {
            Pipeline::addNotes($runId, [['code' => 'render_fail']]);
        }
        Settings::set('aggregate_dirty', 1);
        return $r;
    }

    /**
     * Pure scoring math for one run.
     * Returns auto, judge, spread, contested, auto_weight, final, render_fail, verified, parts.
     */
    public static function compute(array $task, array $run, array $judgeScores): array
    {
        $auto = self::autoScore($task, $run);
        $j = self::judgeStats($judgeScores);
        $a = Pipeline::configuredAutoWeight($task);
        if ($auto['excluded'] > 0 && $auto['measured'] + $auto['excluded'] > 0) {
            // Unverified for infrastructure reasons: the automated part only carries the share of
            // evidence that was actually measured, so static checks alone cannot stand in for tests.
            $a *= $auto['measured'] / ($auto['measured'] + $auto['excluded']);
        }
        if ($j['score'] === null) {
            $a = 1.0;
        }
        if ($auto['score'] === null) {
            $a = $j['score'] === null ? 1.0 : 0.0;
        }
        $final = null;
        if ($auto['score'] !== null || $j['score'] !== null) {
            $final = $a * ($auto['score'] ?? 0.0) + (1 - $a) * ($j['score'] ?? 0.0);
        }
        $renderFail = self::renderFailed($task, $run, $auto['browser']);
        if ($final !== null && $renderFail) {
            $final *= clamp((float) setting('render_fail_factor'), 0.0, 1.0);
        }
        return [
            'auto' => $auto['score'],
            'judge' => $j['score'],
            'spread' => $j['spread'],
            'contested' => $j['contested'],
            'auto_weight' => $a,
            'final' => $final !== null ? round(clamp($final, 0.0, 100.0), 2) : null,
            'render_fail' => $renderFail,
            'verified' => (int) ($run['verified'] ?? 0) === 1,
            'parts' => $auto['parts'],
        ];
    }

    /**
     * auto_score = 100 × Σ(w·score)/Σw over static checks, browser checks and tests (pass fraction × tests weight).
     * answer tasks: 100 or 0; agent tasks: the simulator's goal-check score. Browser checks and tests are
     * excluded when the run was not verified for infrastructure reasons (their weight is reported as
     * 'excluded'), and count as failed when the model produced no code at all.
     */
    public static function autoScore(array $task, array $run): array
    {
        $type = (string) ($task['output_type'] ?? '');
        $static = json_arr($run['static_json'] ?? null);
        if ($type === 'answer') {
            $pass = false;
            foreach ($static as $s) {
                if (is_array($s) && ($s['type'] ?? '') === 'answer') {
                    $pass = !empty($s['pass']);
                }
            }
            return ['score' => $pass ? 100.0 : 0.0, 'parts' => [['kind' => 'answer', 'id' => 'answer', 'weight' => 1.0, 'score' => $pass ? 1.0 : 0.0]], 'browser' => [], 'measured' => 1.0, 'excluded' => 0.0];
        }
        if ($type === 'agent') {
            $agent = json_arr($run['agent_json'] ?? null);
            $s = round(clamp((float) ($agent['auto_score'] ?? 0), 0.0, 100.0), 2);
            return ['score' => $s, 'parts' => [['kind' => 'agent', 'id' => 'goals', 'weight' => 1.0, 'score' => $s / 100]], 'browser' => [], 'measured' => 1.0, 'excluded' => 0.0];
        }

        $parts = [];
        foreach ($static as $s) {
            if (!is_array($s) || ($s['type'] ?? '') === 'answer') {
                continue;
            }
            $parts[] = ['kind' => 'static', 'id' => (string) ($s['id'] ?? ''), 'weight' => self::weight($s['weight'] ?? 1), 'score' => self::unit($s)];
        }
        $checks = Pipeline::checks($task);
        $verified = (int) ($run['verified'] ?? 0) === 1;
        $noCode = trim((string) ($run['code'] ?? '')) === '';
        $browser = $verified ? json_arr($run['browser_json'] ?? null) : [];
        $byDef = self::matchBrowser(is_array($checks['browser'] ?? null) ? $checks['browser'] : [], $browser['checks'] ?? []);
        $cases = is_array($checks['tests']['cases'] ?? null) ? array_values($checks['tests']['cases']) : [];

        $testsWeight = $cases ? self::weight($checks['tests']['weight'] ?? self::DEFAULT_TESTS_WEIGHT) : 0.0;
        $excluded = 0.0;
        if ($verified || $noCode) {
            foreach ($byDef as $b) {
                $parts[] = ['kind' => 'browser', 'id' => $b['id'], 'weight' => $b['weight'], 'score' => $noCode && !$verified ? 0.0 : $b['score']];
            }
            if ($cases) {
                $frac = $verified && !$noCode ? self::testsPassFraction($cases, $browser['tests'] ?? []) : 0.0;
                $parts[] = ['kind' => 'tests', 'id' => 'tests', 'weight' => $testsWeight, 'score' => $frac];
            }
        } elseif (in_array($type, ['html', 'js'], true)) {
            $excluded = array_sum(array_column($byDef, 'weight')) + $testsWeight;
        }
        $sw = 0.0;
        $sum = 0.0;
        foreach ($parts as $p) {
            $sw += $p['weight'];
            $sum += $p['weight'] * $p['score'];
        }
        return [
            'score' => $sw > 0 ? round(100 * $sum / $sw, 4) : null, 'parts' => $parts, 'browser' => $verified ? $byDef : [],
            'measured' => $sw, 'excluded' => $excluded,
        ];
    }

    /** judge_score = median (≥3 judges) | mean (2) | value (1); spread = max − min; contested after tie-break. */
    public static function judgeStats(array $scores): array
    {
        $scores = array_values(array_map('floatval', $scores));
        $n = count($scores);
        if ($n === 0) {
            return ['score' => null, 'spread' => null, 'contested' => false];
        }
        sort($scores);
        $score = $n >= 3 ? (float) median($scores) : ($n === 2 ? ($scores[0] + $scores[1]) / 2 : $scores[0]);
        $spread = $n >= 2 ? $scores[$n - 1] - $scores[0] : null;
        $effective = $spread ?? 0.0;
        if ($n >= 4) {
            // After a tie-break the single score farthest from the median is the outlier being arbitrated.
            $med = (float) median($scores);
            $far = abs($scores[0] - $med) >= abs($scores[$n - 1] - $med) ? 0 : $n - 1;
            $rest = $scores;
            unset($rest[$far]);
            $effective = max($rest) - min($rest);
        }
        return [
            'score' => round($score, 4),
            'spread' => $spread,
            'contested' => $n >= 2 && $effective > (float) setting('judge_tiebreak_spread'),
        ];
    }

    /** html outputs that did not render (no code, or a failed 'loads' / 'nonblank' browser check). */
    public static function renderFailed(array $task, array $run, array $browserByDef): bool
    {
        if (($task['output_type'] ?? '') !== 'html') {
            return false;
        }
        if (trim((string) ($run['code'] ?? '')) === '') {
            return true;
        }
        foreach ($browserByDef as $b) {
            if (in_array($b['type'], ['loads', 'nonblank'], true) && !$b['pass']) {
                return true;
            }
        }
        return false;
    }

    /**
     * Pair every browser check definition with its reported result (by id, else by type).
     * Missing results count as failed.
     */
    public static function matchBrowser(array $defs, mixed $results): array
    {
        $results = is_array($results) ? array_values(array_filter($results, 'is_array')) : [];
        $out = [];
        $usedIdx = [];
        foreach ($defs as $d) {
            if (!is_array($d)) {
                continue;
            }
            $type = (string) ($d['type'] ?? '');
            $id = (string) ($d['id'] ?? $type);
            $hit = null;
            foreach ($results as $i => $r) {
                if (!isset($usedIdx[$i]) && (string) ($r['id'] ?? '') === $id) {
                    $hit = $i;
                    break;
                }
            }
            if ($hit === null) {
                foreach ($results as $i => $r) {
                    if (!isset($usedIdx[$i]) && !isset($r['id']) && (string) ($r['type'] ?? '') === $type) {
                        $hit = $i;
                        break;
                    }
                }
            }
            $r = $hit !== null ? $results[$hit] : null;
            if ($hit !== null) {
                $usedIdx[$hit] = true;
            }
            $score = $r !== null ? self::unit($r) : 0.0;
            $out[] = [
                'id' => $id, 'type' => $type, 'weight' => self::weight($d['weight'] ?? 1),
                'score' => $score, 'reported' => $r !== null,
                'pass' => $r !== null && (array_key_exists('pass', $r) ? self::truthy($r['pass']) : $score >= 0.5),
            ];
        }
        return $out;
    }

    /** Fraction of defined test cases reported as passing (matched by name, else by position). */
    public static function testsPassFraction(array $cases, mixed $results): float
    {
        if (!$cases) {
            return 0.0;
        }
        $results = is_array($results) ? array_values(array_filter($results, 'is_array')) : [];
        $byName = [];
        foreach ($results as $r) {
            if (isset($r['name']) && is_scalar($r['name']) && !isset($byName[(string) $r['name']])) {
                $byName[(string) $r['name']] = $r;
            }
        }
        $pass = 0;
        foreach ($cases as $i => $c) {
            $name = is_array($c) && isset($c['name']) && is_scalar($c['name']) ? (string) $c['name'] : null;
            $r = $name !== null && isset($byName[$name]) ? $byName[$name] : (!$byName ? ($results[$i] ?? null) : null);
            if (is_array($r) && self::truthy($r['pass'] ?? false)) {
                $pass++;
            }
        }
        return $pass / count($cases);
    }

    /** A check result's score in 0..1 ('score' when numeric, else pass ? 1 : 0). */
    private static function unit(array $r): float
    {
        if (isset($r['score']) && is_numeric($r['score'])) {
            return clamp((float) $r['score'], 0.0, 1.0);
        }
        return self::truthy($r['pass'] ?? false) ? 1.0 : 0.0;
    }

    private static function truthy(mixed $v): bool
    {
        return $v === true || $v === 1 || $v === '1' || $v === 'true';
    }

    private static function weight(mixed $w): float
    {
        return is_numeric($w) ? max(0.0, (float) $w) : 1.0;
    }

    private static function r2(?float $v): ?float
    {
        return $v === null ? null : round($v, 2);
    }
}
