<?php
/**
 * Run state machine (ARCHITECTURE §5.2): what happens after generation, after browser verification
 * and after each judgment.
 *
 *   queued → generating → awaiting_verify → verifying → judging → scoring → scored
 *                       └──────────────(no browser needed)──────▶ judging | scoring
 *   infrastructure error → tries++ → retry with backoff; tries ≥ 3 → failed (excluded from scores)
 */
declare(strict_types=1);

namespace WB\Eval;

defined('WB') || exit;

use DB;

final class Pipeline
{
    public const MAX_TRIES = 3;
    public const TERMINAL = ['scored', 'failed', 'skipped'];
    public const STATUSES = ['queued', 'generating', 'awaiting_verify', 'verifying', 'judging', 'scoring', 'scored', 'failed', 'skipped'];
    public const SCORE_LEASE = 60;
    public const NOTE_CODES = [
        'no_judges', 'judges_partial', 'tiebreak', 'no_code', 'unverified_timeout', 'unverified_off',
        'unverified_failed', 'render_fail', 'judging_failed', 'retried', 'generation',
    ];

    private static ?bool $notesColumn = null;

    /** Task row joined with its track (adds track_slug, track_auto_weight). */
    public static function loadTask(int $taskId): ?array
    {
        return DB::one(
            'SELECT t.*, tr.slug AS track_slug, tr.auto_weight AS track_auto_weight
               FROM tasks t JOIN tracks tr ON tr.id = t.track_id WHERE t.id = ?',
            [$taskId]
        );
    }

    /** Model row by id. */
    public static function loadModel(int $modelId): ?array
    {
        return DB::one('SELECT * FROM models WHERE id = ?', [$modelId]);
    }

    /** Decoded checks_json. */
    public static function checks(array $task): array
    {
        return json_arr($task['checks_json'] ?? null);
    }

    /** Decoded rubric criteria that have a key. */
    public static function rubric(array $task): array
    {
        $out = [];
        foreach (json_arr($task['rubric_json'] ?? null) as $c) {
            if (is_array($c) && isset($c['key']) && is_scalar($c['key']) && trim((string) $c['key']) !== '') {
                $c['key'] = trim((string) $c['key']);
                $out[] = $c;
            }
        }
        return $out;
    }

    /** setting('verify_mode') normalised to required | optional | off. */
    public static function verifyMode(): string
    {
        $m = strtolower(trim((string) setting('verify_mode')));
        return in_array($m, ['required', 'optional', 'off'], true) ? $m : 'required';
    }

    /** html/js output whose task defines browser checks or unit tests. */
    public static function hasBrowserChecks(array $task): bool
    {
        if (!in_array((string) ($task['output_type'] ?? ''), ['html', 'js'], true)) {
            return false;
        }
        $c = self::checks($task);
        return !empty($c['browser']) || !empty($c['tests']['cases']);
    }

    /** Browser checks / tests exist and verification is not switched off. */
    public static function needsBrowser(array $task): bool
    {
        return self::verifyMode() !== 'off' && self::hasBrowserChecks($task);
    }

    /** a = tasks.auto_weight ?? tracks.auto_weight, and 1.0 when the task has no rubric. */
    public static function configuredAutoWeight(array $task): float
    {
        if (!self::rubric($task)) {
            return 1.0;
        }
        $a = $task['auto_weight'] ?? null;
        if ($a === null || $a === '') {
            $a = $task['track_auto_weight'] ?? 0.4;
        }
        return clamp((float) $a, 0.0, 1.0);
    }

    /** Judges are needed when the task has a rubric and the auto weight is below 1. */
    public static function needsJudges(array $task): bool
    {
        return self::rubric($task) !== [] && self::configuredAutoWeight($task) < 1.0;
    }

    /**
     * Store a successful generation for a run whose lease we hold and route it onwards.
     * Returns the new status, or null when the lease was lost (result discarded).
     */
    public static function afterGeneration(array $run, string $token, array $gen, array $task, array $model): ?string
    {
        $runId = (int) $run['id'];
        $type = (string) $task['output_type'];
        $code = (string) ($gen['code'] ?? '');
        $static = self::listOf($gen['static'] ?? null);
        $answer = is_array($gen['answer'] ?? null) ? $gen['answer'] : null;
        $agent = is_array($gen['agent'] ?? null) ? $gen['agent'] : null;
        $auto = null;
        if ($type === 'answer') {
            $static[] = self::answerEntry($answer);
            $auto = !empty($answer['pass']) ? 100.0 : 0.0;
        } elseif ($type === 'agent') {
            $auto = round(clamp((float) ($agent['auto_score'] ?? 0), 0.0, 100.0), 2);
        }
        $set = [
            'raw_output' => (string) ($gen['raw_output'] ?? ''),
            'code' => $code !== '' ? $code : null, // null = nothing could be extracted (a model failure)
            'code_bytes' => (int) ($gen['code_bytes'] ?? strlen($code)),
            'prompt_tokens' => self::uint($gen['prompt_tokens'] ?? null),
            'completion_tokens' => self::uint($gen['completion_tokens'] ?? null),
            'reasoning_tokens' => self::uint($gen['reasoning_tokens'] ?? null),
            'cost_usd' => round(max(0.0, (float) ($gen['cost_usd'] ?? 0)), 6),
            'latency_ms' => self::uint($gen['latency_ms'] ?? null),
            'finish_reason' => self::clip((string) ($gen['finish_reason'] ?? ''), 40) ?: null,
            'provider_used' => self::clip((string) ($gen['provider_used'] ?? ''), 80) ?: null,
            'gen_id' => self::clip((string) ($gen['gen_id'] ?? ''), 120) ?: null,
            'static_json' => to_json($static),
            'agent_json' => $agent !== null ? to_json($agent) : null,
            'browser_json' => null,
            'verified' => 0,
            'verified_by' => null,
            'screenshot' => null,
            'screenshot_mobile' => null,
            'verify_tries' => 0,
            'auto_score' => $auto,
            'judge_score' => null,
            'judge_spread' => null,
            'final_score' => null,
            'contested' => 0,
            'error' => null,
            'finished_at' => null,
        ];
        if (self::hasNotesColumn()) {
            $set['notes'] = null;
        }
        $guard = ['token' => $token, 'status' => ['generating']];
        $notes = [];
        if (is_string($gen['note'] ?? null) && trim($gen['note']) !== '') {
            $notes[] = ['code' => 'generation', 'detail' => $gen['note']];
        }
        if (self::needsBrowser($task) && trim($code) !== '') {
            $ok = Lease::retrying(static fn(): bool => DB::tx(static function () use ($runId, $set, $guard): bool {
                if (Lease::write('runs', $runId, $set + ['status' => 'awaiting_verify'], $guard) !== 1) {
                    return false;
                }
                self::deleteJudgments($runId);
                return true;
            }));
            if ($ok) {
                self::addNotes($runId, $notes);
            }
            return $ok ? 'awaiting_verify' : null;
        }
        if (self::hasBrowserChecks($task)) {
            $notes[] = trim($code) === '' ? ['code' => 'no_code'] : ['code' => 'unverified_off'];
        }
        return self::toPost($run, $task, $model, $set, $guard, $notes, true);
    }

    /** Infrastructure failure during generation: retry with backoff, or fail after MAX_TRIES / non-retryable. */
    public static function generationFailed(array $run, string $token, array $gen): ?string
    {
        $tries = (int) ($run['tries'] ?? self::MAX_TRIES);
        $err = self::clip(trim((string) ($gen['error'] ?? '')) ?: 'Generation failed', 1000);
        if (!empty($gen['retryable']) && $tries < self::MAX_TRIES) {
            $n = Lease::write('runs', (int) $run['id'], ['status' => 'queued', 'error' => $err], [
                'token' => $token, 'lease' => self::backoff($tries), 'new_token' => null,
            ]);
            return $n === 1 ? 'queued' : null;
        }
        $n = Lease::write('runs', (int) $run['id'], ['status' => 'failed', 'error' => $err, 'finished_at' => now()], ['token' => $token]);
        return $n === 1 ? 'failed' : null;
    }

    /** Verifier results stored ($set) for a run we hold: route to judging or scoring. */
    public static function afterVerification(array $run, string $token, array $set, array $notes = []): ?string
    {
        $task = self::loadTask((int) $run['task_id']);
        $model = self::loadModel((int) $run['model_id']);
        if (!$task || !$model) {
            return null;
        }
        return self::toPost($run, $task, $model, $set, ['token' => $token, 'status' => ['verifying', 'awaiting_verify']], $notes, false);
    }

    /** Move a run that waits for browser verification on without it (timeout, mode off, verifier failures). */
    public static function skipVerification(int $runId, string $noteCode, string $detail = ''): ?string
    {
        $token = Lease::claim('runs', $runId, ['awaiting_verify', 'verifying'], 'verifying', self::SCORE_LEASE);
        if ($token === null) {
            return null;
        }
        $run = DB::one('SELECT * FROM runs WHERE id = ?', [$runId]);
        $note = ['code' => $noteCode] + ($detail !== '' ? ['detail' => $detail] : []);
        return self::afterVerification($run, $token, ['verified' => 0], [$note]);
    }

    /**
     * Called when a judgment reached a terminal state: adds replacement judges for failed ones, an
     * adaptive tie-break judge when the panel disagrees, or moves the run to scoring.
     * Returns the run status afterwards, or null when another worker is handling the run.
     */
    public static function afterJudgment(int $runId): ?string
    {
        $token = Lease::claim('runs', $runId, ['judging'], 'judging', self::SCORE_LEASE);
        if ($token === null) {
            return null;
        }
        $release = static fn(): int => Lease::write('runs', $runId, [], ['token' => $token]);
        try {
            $run = DB::one('SELECT * FROM runs WHERE id = ?', [$runId]);
            $js = DB::all('SELECT id, judge_model_id, role, status, score FROM judgments WHERE run_id = ? ORDER BY id', [$runId]);
            foreach ($js as $j) {
                if ($j['status'] === 'pending' || $j['status'] === 'running') {
                    $release();
                    return 'judging';
                }
            }
            $model = self::loadModel((int) $run['model_id']) ?? ['id' => (int) $run['model_id'], 'provider' => ''];
            $eligible = Judge::eligible($model);
            $used = array_map('intval', array_column($js, 'judge_model_id'));
            $unused = array_values(array_filter($eligible, static fn(array $m): bool => !in_array((int) $m['id'], $used, true)));
            $done = array_values(array_filter($js, static fn(array $j): bool => $j['status'] === 'done' && $j['score'] !== null));
            $target = min(max(1, (int) setting('judge_count')), count($eligible));

            if ($unused && count($done) < $target) {
                foreach (array_slice($unused, 0, $target - count($done)) as $m) {
                    self::addJudgment($runId, (int) $m['id'], $js ? 'replacement' : 'panel');
                }
                $release();
                return 'judging';
            }
            $scores = array_map(static fn(array $j): float => (float) $j['score'], $done);
            $spread = count($scores) >= 2 ? max($scores) - min($scores) : 0.0;
            $hasTiebreak = in_array('tiebreak', array_column($js, 'role'), true);
            if ($unused && !$hasTiebreak && count($scores) >= 2 && $spread > (float) setting('judge_tiebreak_spread')) {
                self::addJudgment($runId, (int) $unused[0]['id'], 'tiebreak');
                self::addNotes($runId, [['code' => 'tiebreak', 'detail' => number_format($spread, 1, '.', '')]]);
                $release();
                return 'judging';
            }
            if (!$done && !$eligible) {
                // No judge exists for this vendor at all (e.g. after an admin re-judge): automated score only.
                self::addNotes($runId, [['code' => 'no_judges']]);
            } elseif (!$done) {
                $n = Lease::write('runs', $runId, [
                    'status' => 'failed', 'error' => 'Judging failed: no judge returned a usable verdict.', 'finished_at' => now(),
                ], ['token' => $token]);
                return $n === 1 ? 'failed' : null;
            }
            $scoreToken = random_token(16);
            if (Lease::write('runs', $runId, ['status' => 'scoring'], ['token' => $token, 'lease' => self::SCORE_LEASE, 'new_token' => $scoreToken]) !== 1) {
                return null;
            }
            Scorer::score($runId);
            return 'scored';
        } catch (\Throwable $e) {
            $release();
            throw $e;
        }
    }

    /**
     * Admin retry of a failed run. Runs whose generation never succeeded are queued again (fresh tries);
     * runs that failed later keep their output and only redo verification / judging.
     */
    public static function retry(int $runId): bool
    {
        $run = DB::one("SELECT * FROM runs WHERE id = ? AND status = 'failed'", [$runId]);
        if (!$run) {
            return false;
        }
        if ($run['raw_output'] === null) {
            return DB::exec(
                "UPDATE runs SET status = 'queued', tries = 0, verify_tries = 0, error = NULL, locked_until = NULL,
                        lock_token = NULL, finished_at = NULL WHERE id = ? AND status = 'failed'",
                [$runId]
            ) === 1;
        }
        $task = self::loadTask((int) $run['task_id']);
        if (!$task) {
            return false;
        }
        DB::exec(
            "UPDATE judgments SET status = 'pending', tries = 0, error = NULL, locked_until = NULL, lock_token = NULL
              WHERE run_id = ? AND status = 'failed'",
            [$runId]
        );
        $next = 'scoring';
        if (!(int) $run['verified'] && self::needsBrowser($task) && trim((string) $run['code']) !== '') {
            $next = 'awaiting_verify';
        } elseif (self::needsJudges($task) && Judge::eligible(self::loadModel((int) $run['model_id']) ?? []) !== []) {
            $next = 'judging';
        }
        $n = DB::exec(
            "UPDATE runs SET status = ?, tries = 0, verify_tries = 0, error = NULL, locked_until = NULL, lock_token = NULL,
                    finished_at = NULL WHERE id = ? AND status = 'failed'",
            [$next, $runId]
        );
        if ($n === 1) {
            self::addNotes($runId, [['code' => 'retried']]);
        }
        return $n === 1;
    }

    /** Insert a pending judgment (idempotent thanks to the unique (run_id, judge_model_id) key). */
    public static function addJudgment(int $runId, int $judgeModelId, string $role = 'panel'): void
    {
        DB::exec(
            "INSERT IGNORE INTO judgments (run_id, judge_model_id, role, status) VALUES (?, ?, ?, 'pending')",
            [$runId, $judgeModelId, $role]
        );
    }

    /** Whether runs.notes exists (requested schema addition); otherwise notes go to runs.error. Pass a bool to force (tests). */
    public static function hasNotesColumn(?bool $force = null): bool
    {
        if ($force !== null) {
            return self::$notesColumn = $force;
        }
        if (self::$notesColumn === null) {
            try {
                self::$notesColumn = DB::one("SHOW COLUMNS FROM runs LIKE 'notes'") !== null;
            } catch (\Throwable $e) {
                self::$notesColumn = false;
            }
        }
        return self::$notesColumn;
    }

    /** Append machine-readable notes [['code' => …, 'detail' => …]] to a run (see NOTE_CODES). */
    public static function addNotes(int $runId, array $notes): void
    {
        $clean = [];
        foreach ($notes as $n) {
            $code = (string) ($n['code'] ?? '');
            if (!preg_match('/^[a-z_]{1,40}$/', $code)) {
                continue;
            }
            $item = ['code' => $code];
            $detail = trim(self::clip(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) ($n['detail'] ?? '')) ?? '', 300));
            if ($detail !== '') {
                $item['detail'] = $detail;
            }
            $clean[] = $item;
        }
        if (!$clean) {
            return;
        }
        if (self::hasNotesColumn()) {
            $cur = json_arr(DB::val('SELECT notes FROM runs WHERE id = ?', [$runId]));
            foreach ($clean as $item) {
                if (!in_array($item, $cur, true)) {
                    $cur[] = $item;
                }
            }
            DB::exec('UPDATE runs SET notes = ? WHERE id = ?', [to_json(array_slice($cur, -20)), $runId]);
            return;
        }
        $text = (string) DB::val('SELECT error FROM runs WHERE id = ?', [$runId]);
        foreach ($clean as $item) {
            $line = '[note:' . $item['code'] . ']' . (isset($item['detail']) ? ' ' . $item['detail'] : '');
            if (!str_contains($text, $line)) {
                $text = ltrim($text . "\n" . $line);
            }
        }
        DB::exec('UPDATE runs SET error = ? WHERE id = ?', [self::clip($text, 4000), $runId]);
    }

    /** Notes of a run row as a list of ['code','detail'?] (reads runs.notes, or "[note:…]" lines in runs.error). */
    public static function notes(array $run): array
    {
        if (array_key_exists('notes', $run)) {
            return array_values(array_filter(json_arr($run['notes']), 'is_array'));
        }
        $out = [];
        foreach (preg_split('/\R/', (string) ($run['error'] ?? '')) ?: [] as $line) {
            if (preg_match('/^\[note:([a-z_]+)\]\s*(.*)$/', trim($line), $m)) {
                $out[] = ['code' => $m[1]] + ($m[2] !== '' ? ['detail' => $m[2]] : []);
            }
        }
        return $out;
    }

    /** Human text for a note in the current language. */
    public static function noteText(array $note): string
    {
        $key = 'engine.note.' . ($note['code'] ?? '');
        $text = __($key, ['detail' => (string) ($note['detail'] ?? '')]);
        return $text === $key ? (string) ($note['code'] ?? '') : $text;
    }

    /** Cost of one API call: provider-reported cost, else tokens × model prices. */
    public static function costOf(array $usage, array $model): float
    {
        $cost = $usage['cost'] ?? null;
        if (is_numeric($cost) && (float) $cost > 0) {
            return (float) $cost;
        }
        if (method_exists(OpenRouter::class, 'costFromUsage')) {
            try {
                return max(0.0, (float) OpenRouter::costFromUsage($usage, $model));
            } catch (\Throwable $e) {
                // fall through to the local formula
            }
        }
        return ((int) ($usage['prompt_tokens'] ?? 0) * (float) ($model['price_in'] ?? 0)
            + (int) ($usage['completion_tokens'] ?? 0) * (float) ($model['price_out'] ?? 0)) / 1e6;
    }

    /** Retry backoff in seconds after the n-th failed try (30, 60, 120 … max 600). */
    public static function backoff(int $tries): int
    {
        return (int) min(600, 30 * (2 ** max(0, $tries - 1)));
    }

    /** UTF-8 safe truncation. */
    public static function clip(string $s, int $max): string
    {
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max) : $s;
    }

    /**
     * Shared tail of the state machine: write $set and move to judging (creating the panel) or scoring
     * (scored inline). $guard is the Lease::write guard proving we own the run.
     */
    private static function toPost(array $run, array $task, array $model, array $set, array $guard, array $notes, bool $fresh): ?string
    {
        $runId = (int) $run['id'];
        $judges = [];
        if (self::needsJudges($task)) {
            $judges = Judge::panelFor($model);
            $want = max(1, (int) setting('judge_count'));
            if (!$judges) {
                $notes[] = ['code' => 'no_judges'];
            } elseif (count($judges) < $want) {
                $notes[] = ['code' => 'judges_partial', 'detail' => count($judges) . '/' . $want];
            }
        }
        $status = $judges ? 'judging' : 'scoring';
        $scoreToken = $status === 'scoring' ? random_token(16) : null;
        // Retried as a whole when it loses a deadlock (InnoDB rolled it back; the lease guard keeps it idempotent).
        $ok = Lease::retrying(static fn(): bool => DB::tx(static function () use ($runId, $set, $guard, $status, $scoreToken, $judges, $fresh): bool {
            $opts = $guard + ($scoreToken !== null ? ['lease' => self::SCORE_LEASE, 'new_token' => $scoreToken] : []);
            if (Lease::write('runs', $runId, $set + ['status' => $status], $opts) !== 1) {
                return false;
            }
            if ($fresh) {
                self::deleteJudgments($runId);
            }
            foreach ($judges as $j) {
                self::addJudgment($runId, (int) $j['id'], 'panel');
            }
            return true;
        }));
        if (!$ok) {
            return null;
        }
        self::addNotes($runId, $notes);
        if ($status === 'scoring') {
            Scorer::score($runId);
            return 'scored';
        }
        return 'judging';
    }

    /**
     * Delete a run's judgments by primary key. "DELETE … WHERE run_id = ?" would gap-lock the run_id index
     * when there is nothing to delete, and concurrent transactions inserting judgments for neighbouring runs
     * then deadlock on each other's gaps.
     */
    private static function deleteJudgments(int $runId): void
    {
        $ids = array_map('intval', DB::col('SELECT id FROM judgments WHERE run_id = ?', [$runId]));
        if ($ids) {
            DB::exec('DELETE FROM judgments WHERE id IN (' . DB::in($ids) . ')', $ids);
        }
    }

    /** The answer check stored inside static_json so the result is visible and re-scorable. */
    private static function answerEntry(?array $answer): array
    {
        $enc = static fn($v): string => is_scalar($v) || $v === null ? (string) $v : to_json($v);
        return [
            'id' => 'answer', 'type' => 'answer', 'pass' => !empty($answer['pass']), 'score' => !empty($answer['pass']) ? 1 : 0,
            'weight' => 1, 'label_en' => 'Correct final answer', 'label_ar' => 'الإجابة النهائية صحيحة',
            'detail' => self::clip((string) ($answer['detail'] ?? ''), 500),
            'got' => self::clip($enc($answer['got'] ?? ''), 500),
            'expected' => self::clip($enc($answer['expected'] ?? ''), 500),
        ];
    }

    private static function listOf(mixed $v): array
    {
        return is_array($v) ? array_values(array_filter($v, 'is_array')) : [];
    }

    private static function uint(mixed $v): ?int
    {
        return is_numeric($v) ? max(0, (int) $v) : null;
    }
}
