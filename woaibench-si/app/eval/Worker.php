<?php
/**
 * Benchmark worker (ARCHITECTURE §5.7). One step claims and processes units of work until the time
 * budget is used or nothing is actionable: generate → judge → finalize → verification timeouts →
 * aggregation. Safe to run from several places at once (cron, admin console, web tick): every unit is
 * claimed with a lease.
 */
declare(strict_types=1);

namespace WB\Eval;

defined('WB') || exit;

use DB;
use Settings;

final class Worker
{
    public const AGGREGATE_GAP = 60;
    private const SWEEP_LIMIT = 25;

    /**
     * Run for about $budgetSeconds. Optional $client / $generate (callable(run, task, model, client): array)
     * replace the OpenRouter client and Generator::run (tests, dry runs). While a provider halt is active
     * (Campaign::providerHalt(): no credit / bad key) no generation or judging unit is claimed.
     * Returns ['did' => [...], 'counts' => [status => n], 'idle' => bool, 'halted' => bool, 'spent' => float, 'ms' => int].
     */
    public static function step(float $budgetSeconds = 25.0, ?ChatClient $client = null, ?callable $generate = null): array
    {
        $t0 = microtime(true);
        self::prepareRuntime($budgetSeconds);
        $deadline = $t0 + max(0.0, $budgetSeconds);
        self::heartbeat();
        self::closeCampaigns();
        $did = [];
        $spent = 0.0;
        $idle = false;
        $halted = Campaign::providerHalt() !== null;
        while (true) {
            $progress = false;
            foreach (['generate', 'judge', 'finalize', 'timeouts'] as $phase) {
                if (microtime(true) >= $deadline) {
                    break 2;
                }
                if ($halted && ($phase === 'generate' || $phase === 'judge')) {
                    continue; // every paid call would fail the same way until the admin fixes the account
                }
                try {
                    $items = match ($phase) {
                        'generate' => self::generateOne($client, $generate),
                        'judge' => self::judgeOne($client),
                        'finalize' => self::finalize(),
                        'timeouts' => self::verifyTimeouts(),
                    };
                } catch (\PDOException $e) {
                    // A deadlock that survived Lease::retrying(): the unit's lease expires and it is picked up
                    // again later. Never let one lost lock race abort the whole step (cron would lose a minute).
                    if (!Lease::isLockConflict($e)) {
                        throw $e;
                    }
                    log_error('Worker: lock conflict, unit postponed', ['phase' => $phase, 'error' => $e->getMessage()]);
                    $items = [['kind' => 'lock_conflict', 'phase' => $phase, 'ok' => false]];
                }
                foreach ($items as $item) {
                    $spent += (float) ($item['cost'] ?? 0);
                    $did[] = $item;
                    $progress = true;
                    $halted = $halted || !empty($item['halted']);
                }
            }
            if (!$progress) {
                $idle = true;
                break;
            }
        }
        self::closeCampaigns();
        $agg = self::maybeAggregate($idle);
        if ($agg !== null) {
            $did[] = $agg;
        }
        $arena = self::maybeArena();
        if ($arena !== null) {
            $did[] = $arena;
        }
        self::heartbeat();
        return [
            'did' => $did,
            'counts' => self::counts(),
            'idle' => $idle,
            'halted' => $halted,
            'spent' => round($spent, 6),
            'ms' => (int) round((microtime(true) - $t0) * 1000),
        ];
    }

    /** Run counts per status (all statuses present) plus open judgments. */
    public static function counts(): array
    {
        $counts = array_fill_keys(Pipeline::STATUSES, 0);
        foreach (DB::all('SELECT status, COUNT(*) AS n FROM runs GROUP BY status') as $r) {
            $counts[(string) $r['status']] = (int) $r['n'];
        }
        $counts['judgments_open'] = (int) DB::val("SELECT COUNT(*) FROM judgments WHERE status IN ('pending', 'running')");
        return $counts;
    }

    /**
     * Run $fn while holding the shared worker file lock (storage/cache/worker.lock).
     * Returns [true, result] or [false, null] when another worker holds the lock.
     */
    public static function withLock(callable $fn): array
    {
        $dir = WB_STORAGE . '/cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        // flock() can be disabled on shared hosts (a disabled function is undefined in PHP 8 and calling it is fatal).
        $fh = function_exists('flock') ? @fopen($dir . '/worker.lock', 'c') : false;
        if ($fh === false) {
            return [true, $fn()]; // cannot lock (read-only storage, no flock): leases still keep work safe
        }
        try {
            if (!flock($fh, LOCK_EX | LOCK_NB)) {
                return [false, null];
            }
            try {
                return [true, $fn()];
            } finally {
                flock($fh, LOCK_UN);
            }
        } finally {
            fclose($fh);
        }
    }

    /** Claim one queued run and generate it. */
    private static function generateOne(?ChatClient $client, ?callable $generate): array
    {
        $timeout = (int) clamp((float) setting('gen_timeout_s'), 30, 900);
        $cands = DB::all(
            "SELECT r.id, t.output_type, t.checks_json, c.gen_params, m.gen_params AS model_gen_params FROM runs r
               JOIN campaigns c ON c.id = r.campaign_id
               JOIN tasks t ON t.id = r.task_id
               LEFT JOIN models m ON m.id = r.model_id
              WHERE c.status = 'running' AND r.status IN ('queued', 'generating')
                AND (r.locked_until IS NULL OR r.locked_until < NOW()) AND r.tries < ?
              ORDER BY r.attempt, r.id LIMIT 8",
            [Pipeline::MAX_TRIES]
        );
        $token = null;
        $runId = 0;
        foreach ($cands as $c) {
            $token = Lease::claim('runs', (int) $c['id'], ['queued', 'generating'], 'generating', self::generationLease($c, $timeout), 'tries', Pipeline::MAX_TRIES);
            if ($token !== null) {
                $runId = (int) $c['id'];
                break;
            }
        }
        if ($token === null) {
            return [];
        }
        $t = microtime(true);
        $run = DB::one('SELECT * FROM runs WHERE id = ?', [$runId]);
        $task = Pipeline::loadTask((int) $run['task_id']);
        $model = Pipeline::loadModel((int) $run['model_id']);
        if (!$task || !$model) {
            Pipeline::generationFailed($run, $token, ['retryable' => false, 'error' => 'Task or model no longer exists']);
            return [];
        }
        // Campaign parameters override the model's own gen_params (Generator::params merges them).
        $run['gen_params'] = json_arr(DB::val('SELECT gen_params FROM campaigns WHERE id = ?', [(int) $run['campaign_id']]));
        try {
            $gen = $generate !== null ? $generate($run, $task, $model, $client) : Generator::run($run, $task, $model, $client);
        } catch (\Throwable $e) {
            log_error('Generation exception', ['run' => $runId, 'error' => $e->getMessage()]);
            $gen = ['ok' => false, 'retryable' => true, 'error' => 'Generator exception: ' . $e->getMessage()];
        }
        $gen = is_array($gen) ? $gen : ['ok' => false, 'retryable' => true, 'error' => 'Generator returned no result'];
        $cost = max(0.0, (float) ($gen['cost_usd'] ?? 0));
        $status = !empty($gen['ok'])
            ? Pipeline::afterGeneration($run, $token, $gen, $task, $model)
            : Pipeline::generationFailed($run, $token, $gen);
        self::addSpent($runId, $cost); // failed and discarded attempts were paid for too
        Campaign::refreshSpent((int) $run['campaign_id']);
        if ($status === null) {
            log_error('Generation result discarded: lease lost', ['run' => $runId]);
        }
        $halted = empty($gen['ok']) && is_array($gen['halt'] ?? null);
        return [[
            'kind' => 'generate', 'run_id' => $runId, 'model' => (string) $model['name'], 'task' => (string) $task['slug'],
            'attempt' => (int) $run['attempt'], 'ok' => !empty($gen['ok']) && $status !== null, 'status' => $status,
            'ms' => (int) round((microtime(true) - $t) * 1000), 'cost' => round($cost, 6),
            'error' => !empty($gen['ok']) ? null : Pipeline::clip((string) ($gen['error'] ?? ''), 300),
        ] + ($halted ? ['halted' => true] : [])];
    }

    /** Claim one pending judgment and run the judge. */
    private static function judgeOne(?ChatClient $client): array
    {
        $timeout = (int) clamp((float) setting('judge_timeout_s', 180), 30, 600);
        $cands = DB::col(
            "SELECT j.id FROM judgments j
               JOIN runs r ON r.id = j.run_id
               JOIN campaigns c ON c.id = r.campaign_id
              WHERE c.status = 'running' AND r.status = 'judging' AND j.status IN ('pending', 'running')
                AND (j.locked_until IS NULL OR j.locked_until < NOW()) AND j.tries < ?
              ORDER BY j.id LIMIT 8",
            [Pipeline::MAX_TRIES]
        );
        $token = null;
        $jid = 0;
        foreach ($cands as $id) {
            $token = Lease::claim('judgments', (int) $id, ['pending', 'running'], 'running', 3 * $timeout + 60, 'tries', Pipeline::MAX_TRIES);
            if ($token !== null) {
                $jid = (int) $id;
                break;
            }
        }
        if ($token === null) {
            return [];
        }
        $t = microtime(true);
        $j = DB::one('SELECT * FROM judgments WHERE id = ?', [$jid]);
        $run = DB::one('SELECT * FROM runs WHERE id = ?', [(int) $j['run_id']]);
        $task = Pipeline::loadTask((int) $run['task_id']);
        $judge = Pipeline::loadModel((int) $j['judge_model_id']);
        try {
            $res = ($task && $judge)
                ? Judge::evaluate($run, $task, $judge, $client)
                : ['ok' => false, 'retryable' => false, 'error' => 'Task or judge model missing'];
        } catch (\Throwable $e) {
            log_error('Judge exception', ['judgment' => $jid, 'error' => $e->getMessage()]);
            $res = ['ok' => false, 'retryable' => true, 'error' => 'Judge exception: ' . $e->getMessage()];
        }
        $cost = max(0.0, (float) ($res['cost_usd'] ?? 0));
        // Tokens and cost accumulate over retried attempts so campaign spend stays exact.
        $metrics = [
            'prompt_tokens' => (int) $j['prompt_tokens'] + (int) ($res['prompt_tokens'] ?? 0),
            'completion_tokens' => (int) $j['completion_tokens'] + (int) ($res['completion_tokens'] ?? 0),
            'cost_usd' => round((float) $j['cost_usd'] + $cost, 6),
            'latency_ms' => (int) ($res['latency_ms'] ?? 0),
        ];
        $halt = empty($res['ok']) && is_array($res['halt'] ?? null) ? $res['halt'] : null;
        if (!empty($res['ok'])) {
            $status = 'done';
            $n = Lease::write('judgments', $jid, $metrics + [
                'status' => 'done', 'score' => round((float) $res['score'], 2),
                'criteria_json' => to_json($res['criteria'] ?? []), 'rationale' => (string) ($res['rationale'] ?? ''),
                'used_vision' => !empty($res['used_vision']) ? 1 : 0, 'error' => null,
            ], ['token' => $token]);
        } elseif ($halt !== null) {
            // Provider halt (no credit / bad key): the judgment waits, the try is given back, the campaign pauses.
            $status = 'pending';
            $n = Lease::write('judgments', $jid, $metrics + [
                'status' => 'pending', 'tries' => max(0, (int) $j['tries'] - 1), 'error' => Pipeline::clip((string) ($res['error'] ?? ''), 1000),
            ], ['token' => $token]);
            if ($n === 1) {
                Campaign::halt($halt, (int) $run['campaign_id']);
            }
        } elseif (!empty($res['retryable']) && (int) $j['tries'] < Pipeline::MAX_TRIES) {
            $status = 'pending';
            $n = Lease::write('judgments', $jid, $metrics + ['status' => 'pending', 'error' => Pipeline::clip((string) ($res['error'] ?? ''), 1000)], [
                'token' => $token, 'lease' => Pipeline::holdOff((int) $j['tries'], $res['retry_after'] ?? null), 'new_token' => null,
            ]);
        } else {
            $status = 'failed';
            $n = Lease::write('judgments', $jid, $metrics + ['status' => 'failed', 'error' => Pipeline::clip((string) ($res['error'] ?? 'Judge failed'), 1000)], ['token' => $token]);
        }
        self::addSpent((int) $run['id'], $cost);
        Campaign::refreshSpent((int) $run['campaign_id']);
        $runStatus = null;
        if ($n === 1 && $status !== 'pending') {
            $runStatus = Pipeline::afterJudgment((int) $run['id']);
        }
        return [[
            'kind' => 'judge', 'run_id' => (int) $run['id'], 'judgment_id' => $jid, 'judge' => (string) ($judge['name'] ?? ''),
            'task' => (string) ($task['slug'] ?? ''), 'role' => (string) $j['role'], 'ok' => !empty($res['ok']) && $n === 1,
            'score' => !empty($res['ok']) ? round((float) $res['score'], 2) : null, 'status' => $status, 'run_status' => $runStatus,
            'ms' => (int) round((microtime(true) - $t) * 1000), 'cost' => round($cost, 6),
            'error' => !empty($res['ok']) ? null : Pipeline::clip((string) ($res['error'] ?? ''), 300),
        ] + ($halt !== null ? ['halted' => true] : [])];
    }

    /** Crash recovery and leftovers: scoring runs, judging runs with no open judgments, dead leases. */
    private static function finalize(): array
    {
        $did = [];
        // Judgments whose worker died on the last allowed try.
        foreach (DB::all("SELECT id, run_id FROM judgments WHERE status = 'running' AND locked_until < NOW() AND tries >= ? LIMIT " . self::SWEEP_LIMIT, [Pipeline::MAX_TRIES]) as $j) {
            if (Lease::write('judgments', (int) $j['id'], ['status' => 'failed', 'error' => 'Judge worker stopped responding'], ['status' => ['running'], 'free' => true]) === 1) {
                $did[] = ['kind' => 'judge_expired', 'run_id' => (int) $j['run_id'], 'judgment_id' => (int) $j['id'], 'ok' => false];
            }
        }
        // Generation leases that expired on the last allowed try.
        foreach (DB::col("SELECT id FROM runs WHERE status = 'generating' AND locked_until < NOW() AND tries >= ? LIMIT " . self::SWEEP_LIMIT, [Pipeline::MAX_TRIES]) as $id) {
            if (Lease::write('runs', (int) $id, ['status' => 'failed', 'error' => 'Generation worker stopped responding 3 times', 'finished_at' => now()], ['status' => ['generating'], 'free' => true]) === 1) {
                $did[] = ['kind' => 'generate_expired', 'run_id' => (int) $id, 'ok' => false];
            }
        }
        // Verifications abandoned on the last allowed try → wait for an admin re-verification (verify_mode required),
        // else proceed unverified.
        foreach (DB::col("SELECT id FROM runs WHERE status = 'verifying' AND locked_until < NOW() AND verify_tries >= ? LIMIT " . self::SWEEP_LIMIT, [Pipeline::MAX_TRIES]) as $id) {
            $s = Pipeline::verificationFailed((int) $id, 'verifier did not report back');
            if ($s !== null) {
                $did[] = ['kind' => 'verify_given_up', 'run_id' => (int) $id, 'ok' => true, 'status' => $s];
            }
        }
        // Judging runs whose judgments are all finished (e.g. a worker died between steps).
        foreach (DB::col(
            "SELECT r.id FROM runs r WHERE r.status = 'judging' AND (r.locked_until IS NULL OR r.locked_until < NOW())
                AND NOT EXISTS (SELECT 1 FROM judgments j WHERE j.run_id = r.id AND j.status IN ('pending', 'running'))
              ORDER BY r.id LIMIT " . self::SWEEP_LIMIT
        ) as $id) {
            $s = Pipeline::afterJudgment((int) $id);
            if ($s !== null) {
                // 'judging' here means new (panel / replacement / tie-break) judgments were just created.
                $did[] = ['kind' => 'finalize', 'run_id' => (int) $id, 'ok' => $s !== 'failed', 'status' => $s];
            }
        }
        // Runs left in scoring.
        foreach (DB::col("SELECT id FROM runs WHERE status = 'scoring' AND (locked_until IS NULL OR locked_until < NOW()) ORDER BY id LIMIT " . self::SWEEP_LIMIT) as $id) {
            if (Lease::claim('runs', (int) $id, ['scoring'], 'scoring', Pipeline::SCORE_LEASE) !== null) {
                $r = Scorer::score((int) $id);
                $did[] = ['kind' => 'score', 'run_id' => (int) $id, 'ok' => $r !== null, 'score' => $r['final'] ?? null];
            }
        }
        return $did;
    }

    /** verify_mode off → everything waiting proceeds; optional → runs waiting longer than verify_wait_minutes proceed. */
    private static function verifyTimeouts(): array
    {
        $mode = Pipeline::verifyMode();
        if ($mode === 'required') {
            return [];
        }
        $sql = "SELECT r.id FROM runs r JOIN campaigns c ON c.id = r.campaign_id
                 WHERE c.status = 'running' AND r.status IN ('awaiting_verify', 'verifying')
                   AND (r.locked_until IS NULL OR r.locked_until < NOW())";
        $params = [];
        if ($mode === 'optional') {
            $sql .= ' AND r.updated_at < NOW() - INTERVAL ? MINUTE';
            $params[] = max(0, (int) setting('verify_wait_minutes'));
        }
        $did = [];
        foreach (DB::col($sql . ' ORDER BY r.id LIMIT ' . self::SWEEP_LIMIT, $params) as $id) {
            $code = $mode === 'off' ? 'unverified_off' : 'unverified_timeout';
            $s = Pipeline::skipVerification((int) $id, $code, $mode === 'optional' ? (string) (int) setting('verify_wait_minutes') : '');
            if ($s !== null) {
                $did[] = ['kind' => 'verify_skipped', 'run_id' => (int) $id, 'ok' => true, 'status' => $s, 'reason' => $code];
            }
        }
        return $did;
    }

    /**
     * Campaigns whose runs are all terminal become done; a done campaign with reopened runs (re-judge, retry)
     * runs again. Decided with plain (non-locking) reads, then updated by primary key: an UPDATE with a
     * subquery over runs would share-lock every run of the campaign and deadlock with the other workers.
     * A stale decision heals itself on the next step.
     */
    private static function closeCampaigns(): void
    {
        $open = "SELECT 1 FROM runs r WHERE r.campaign_id = c.id AND r.status NOT IN ('scored', 'failed', 'skipped')";
        $reopen = array_map('intval', DB::col("SELECT c.id FROM campaigns c WHERE c.status = 'done' AND EXISTS ($open)"));
        if ($reopen) {
            Lease::retrying(static fn(): int => DB::exec(
                "UPDATE campaigns SET status = 'running', finished_at = NULL WHERE status = 'done' AND id IN (" . DB::in($reopen) . ')',
                $reopen
            ));
        }
        $done = array_map('intval', DB::col("SELECT c.id FROM campaigns c WHERE c.status = 'running' AND NOT EXISTS ($open)"));
        if ($done) {
            Lease::retrying(static fn(): int => DB::exec(
                "UPDATE campaigns SET status = 'done', finished_at = ? WHERE status = 'running' AND id IN (" . DB::in($done) . ')',
                array_merge([now()], $done)
            ));
        }
    }

    /**
     * Aggregate when scores changed and either ≥ 60 s passed since the last aggregation or the queue is
     * drained (idle and no unfinished run left: publish the final numbers at once). While runs still wait
     * for a verifier, frequent short steps (admin console, parallel cron) do not re-aggregate every time.
     */
    private static function maybeAggregate(bool $idle): ?array
    {
        $vals = [];
        foreach (DB::all("SELECT k, v FROM settings WHERE k IN ('aggregate_dirty', 'aggregate_last')") as $r) {
            $vals[$r['k']] = json_decode((string) $r['v'], true);
        }
        if (empty($vals['aggregate_dirty'])) {
            return null;
        }
        $last = is_string($vals['aggregate_last'] ?? null) ? strtotime($vals['aggregate_last'] . ' UTC') : false;
        if ($last !== false && time() - $last < self::AGGREGATE_GAP) {
            $drained = $idle && DB::val(
                'SELECT 1 FROM runs WHERE status NOT IN (' . DB::in(Pipeline::TERMINAL) . ') LIMIT 1',
                Pipeline::TERMINAL
            ) === null;
            if (!$drained) {
                return null;
            }
        }
        Settings::flush();
        $t = microtime(true);
        try {
            $sum = Aggregator::recompute();
        } catch (\Throwable $e) {
            log_error('Aggregation failed', ['error' => $e->getMessage()]);
            Settings::set('aggregate_dirty', 1);
            return ['kind' => 'aggregate', 'ok' => false, 'error' => Pipeline::clip($e->getMessage(), 300)];
        }
        return ['kind' => 'aggregate', 'ok' => true, 'models' => $sum['models'], 'runs' => $sum['runs'], 'ms' => (int) round((microtime(true) - $t) * 1000)];
    }

    /**
     * Let the arena module refresh its ratings when it is installed (Arena::maybeRecompute decides whether
     * enough new votes arrived). Reported as an 'arena' unit when it actually recomputed.
     */
    private static function maybeArena(): ?array
    {
        $cls = 'WB\\Arena\\Arena';
        if (!class_exists($cls) || !method_exists($cls, 'maybeRecompute')) {
            return null;
        }
        $stamp = static fn(): string => implode('|', DB::col(
            "SELECT v FROM settings WHERE k IN ('arena_last_recompute', 'arena_votes_at_recompute') ORDER BY k"
        ));
        try {
            $t = microtime(true);
            $before = $stamp();
            $r = $cls::maybeRecompute();
            if ($r === false || ($r === null && $stamp() === $before)) {
                return null;
            }
            return ['kind' => 'arena', 'ok' => true, 'ms' => (int) round((microtime(true) - $t) * 1000)];
        } catch (\Throwable $e) {
            log_error('Arena recompute failed', ['error' => $e->getMessage()]);
            return ['kind' => 'arena', 'ok' => false, 'error' => Pipeline::clip($e->getMessage(), 300)];
        }
    }

    private static function heartbeat(): void
    {
        try {
            Settings::set('worker_last_run', now());
        } catch (\Throwable $e) {
            log_error('Worker heartbeat failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * A step checks its budget only between units, so the last unit may start just before the deadline and run
     * for a whole generation lease (agent: up to 3 calls) or judge evaluation (up to 3 sequential calls): the PHP
     * time limit covers the longer of the two.
     */
    private static function prepareRuntime(float $budget): void
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (function_exists('ignore_user_abort') && !in_array('ignore_user_abort', $disabled, true)) {
            ignore_user_abort(true);
        }
        if (!WB_CLI && function_exists('set_time_limit') && !in_array('set_time_limit', $disabled, true)) {
            $gen = (int) clamp((float) setting('gen_timeout_s'), 30, 900);
            $judge = (int) clamp((float) setting('judge_timeout_s', 180), 30, 600);
            @set_time_limit((int) ceil($budget) + max(3 * $gen + 180, 3 * $judge + 60));
        }
    }

    /**
     * Generation lease: one call (gen_timeout_s + 120 s), or a whole agent episode — up to 3 × gen_timeout_s + 180 s
     * and never shorter than the episode's wall time (checks.agent.wall_s or the _wall_s run option, ≤ 600 s) plus
     * the last call's overrun (30 s) and retry / protocol-replay margin. A lease that expired mid-episode would let
     * a parallel worker start a second paid episode.
     */
    private static function generationLease(array $c, int $timeout): int
    {
        if (($c['output_type'] ?? '') !== 'agent') {
            return $timeout + 120;
        }
        $params = Generator::params(['gen_params' => $c['gen_params'] ?? null], ['gen_params' => $c['model_gen_params'] ?? null]);
        $agent = json_arr($c['checks_json'] ?? null)['agent'] ?? [];
        $wall = clamp((float) ($params['_wall_s'] ?? (is_array($agent) ? ($agent['wall_s'] ?? 150) : 150)), 0.0, 600.0);
        return max(3 * $timeout + 180, (int) ceil($wall) + 105);
    }

    /**
     * runs.spent_usd only grows: every paid call of a run (successful, failed or discarded generation attempts and
     * judge calls) adds to it, so campaign spend stays exact across retries, regenerations and re-judging.
     */
    private static function addSpent(int $runId, float $cost): void
    {
        if ($cost > 0 && Pipeline::hasColumn('runs', 'spent_usd')) {
            Lease::retrying(static fn(): int => DB::exec('UPDATE runs SET spent_usd = spent_usd + ? WHERE id = ?', [round($cost, 6), $runId]));
        }
    }
}
