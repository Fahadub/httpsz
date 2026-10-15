<?php
/**
 * Campaigns: one benchmark batch (models × tasks × attempts) — creation, cost estimate, progress and
 * lifecycle (pause / resume / cancel / retry failed).
 */
declare(strict_types=1);

namespace WB\Eval;

defined('WB') || exit;

use DB;

final class Campaign
{
    /**
     * Cost-estimate assumptions (documented in the estimate result):
     * completion tokens per output type (code + reasoning, typical of current frontier models),
     * prompt ≈ task prompt chars / 3.5 + fixed overhead; agent tasks re-send the conversation each step.
     */
    public const OUTPUT_TOKENS = ['html' => 9000, 'js' => 3500, 'answer' => 3000, 'agent' => 2500, 'text' => 2000];
    public const CHARS_PER_TOKEN = 3.5;
    public const PROMPT_OVERHEAD = 350;
    public const AGENT_STEPS = 6;
    public const AGENT_STEP_CONTEXT = 1200;
    public const JUDGE_PROMPT_OVERHEAD = 1600;
    public const JUDGE_OUTPUT = 700;
    public const JUDGE_IMAGE_TOKENS = 1500;
    public const TIEBREAK_RATE = 0.15;
    public const MAX_RUNS_PER_TASK = 10;

    /** Create a running campaign for the current season and insert all its runs. Returns the campaign id. */
    public static function create(string $name, array $modelIds, array $taskIds, int $runsPerTask, array $genParams = []): int
    {
        $name = Pipeline::clip(trim(preg_replace('/\s+/u', ' ', $name) ?? ''), 190);
        if ($name === '') {
            throw new \InvalidArgumentException('Campaign name is required');
        }
        $runsPerTask = max(1, min(self::MAX_RUNS_PER_TASK, $runsPerTask));
        $models = self::existing('models', self::ids($modelIds));
        $tasks = self::existing('tasks', self::ids($taskIds));
        if (!$models) {
            throw new \InvalidArgumentException('Select at least one existing model');
        }
        if (!$tasks) {
            throw new \InvalidArgumentException('Select at least one existing task');
        }
        $gp = $genParams ? to_json($genParams) : null;
        if ($gp !== null && strlen($gp) > 16000) {
            throw new \InvalidArgumentException('Generation parameters are too large');
        }
        $est = self::estimate($models, $tasks, $runsPerTask);
        $uid = null;
        if (!WB_CLI && session_status() === PHP_SESSION_ACTIVE) {
            $uid = \Auth::user()['id'] ?? null;
        }
        return DB::tx(static function () use ($name, $models, $tasks, $runsPerTask, $gp, $est, $uid): int {
            $id = DB::insert('campaigns', [
                'name' => $name, 'season' => (string) setting('season'), 'status' => 'running', 'runs_per_task' => $runsPerTask,
                'model_ids' => to_json($models), 'task_ids' => to_json($tasks), 'gen_params' => $gp, 'include_in_scores' => 1,
                'est_cost' => round($est['total'], 4), 'spent_cost' => 0, 'created_by' => $uid, 'started_at' => now(),
            ]);
            $rows = [];
            for ($a = 1; $a <= $runsPerTask; $a++) {
                foreach ($tasks as $t) {
                    foreach ($models as $m) {
                        $rows[] = [$id, $m, $t, $a];
                    }
                }
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::exec(
                    'INSERT INTO runs (campaign_id, model_id, task_id, attempt) VALUES ' . implode(',', array_fill(0, count($chunk), '(?,?,?,?)')),
                    array_merge(...$chunk)
                );
            }
            return $id;
        });
    }

    /**
     * Cost estimate: ['runs','gen_cost','judge_cost','total','per_model' => [['model_id','name','runs','gen_cost',
     * 'judge_cost','cost']], 'assumptions' => [...]] from model prices (USD per 1M tokens) and the token
     * assumptions above. Judging uses each model's real panel (vendor exclusion) plus a tie-break allowance.
     */
    public static function estimate(array $modelIds, array $taskIds, int $runsPerTask): array
    {
        $runsPerTask = max(1, min(self::MAX_RUNS_PER_TASK, $runsPerTask));
        $modelIds = self::ids($modelIds);
        $taskIds = self::ids($taskIds);
        $models = $modelIds ? DB::all('SELECT * FROM models WHERE id IN (' . DB::in($modelIds) . ') ORDER BY name', $modelIds) : [];
        $tasks = $taskIds ? DB::all(
            'SELECT t.id, t.output_type, t.prompt, t.rubric_json, t.checks_json, t.auto_weight, tr.auto_weight AS track_auto_weight
               FROM tasks t JOIN tracks tr ON tr.id = t.track_id WHERE t.id IN (' . DB::in($taskIds) . ')',
            $taskIds
        ) : [];
        $profiles = array_map([self::class, 'taskProfile'], $tasks);
        $perModel = [];
        $missing = [];
        $gen = 0.0;
        $judge = 0.0;
        foreach ($models as $m) {
            if ($m['price_in'] === null || $m['price_out'] === null) {
                $missing[] = (string) $m['name'];
            }
            $panel = Judge::panelFor($m);
            $g = 0.0;
            $j = 0.0;
            foreach ($profiles as $p) {
                $g += $runsPerTask * self::price($p['in'], $p['out'], $m);
                if ($p['judged'] && $panel) {
                    $one = 0.0;
                    foreach ($panel as $jm) {
                        $in = self::JUDGE_PROMPT_OVERHEAD + $p['prompt'] + $p['out'] + (!empty($jm['supports_vision']) ? $p['shots'] * self::JUDGE_IMAGE_TOKENS : 0);
                        $one += self::price($in, self::JUDGE_OUTPUT, $jm);
                    }
                    $j += $runsPerTask * $one * (1 + self::TIEBREAK_RATE / count($panel));
                }
            }
            $gen += $g;
            $judge += $j;
            $perModel[] = [
                'model_id' => (int) $m['id'], 'name' => (string) $m['name'], 'runs' => count($tasks) * $runsPerTask,
                'gen_cost' => round($g, 4), 'judge_cost' => round($j, 4), 'cost' => round($g + $j, 4),
            ];
        }
        return [
            'runs' => count($models) * count($tasks) * $runsPerTask,
            'gen_cost' => round($gen, 4),
            'judge_cost' => round($judge, 4),
            'total' => round($gen + $judge, 4),
            'per_model' => $perModel,
            'assumptions' => [
                'output_tokens' => self::OUTPUT_TOKENS,
                'chars_per_token' => self::CHARS_PER_TOKEN,
                'prompt_overhead_tokens' => self::PROMPT_OVERHEAD,
                'agent_steps' => self::AGENT_STEPS,
                'judge_prompt_overhead_tokens' => self::JUDGE_PROMPT_OVERHEAD,
                'judge_output_tokens' => self::JUDGE_OUTPUT,
                'judge_image_tokens' => self::JUDGE_IMAGE_TOKENS,
                'tiebreak_rate' => self::TIEBREAK_RATE,
                'judged_tasks' => count(array_filter($profiles, static fn($p) => $p['judged'])),
                'missing_prices' => $missing,
                'notes' => [
                    __('engine.est.note_tokens'),
                    __('engine.est.note_judges', ['n' => (int) setting('judge_count')]),
                    __('engine.est.note_reasoning'),
                ],
            ],
        ];
    }

    /** Progress of one campaign: counts per status, percent, spent, eta (seconds). Empty array if unknown. */
    public static function progress(int $id): array
    {
        $c = DB::one('SELECT * FROM campaigns WHERE id = ?', [$id]);
        if (!$c) {
            return [];
        }
        $counts = array_fill_keys(Pipeline::STATUSES, 0);
        foreach (DB::all('SELECT status, COUNT(*) AS n FROM runs WHERE campaign_id = ? GROUP BY status', [$id]) as $r) {
            $counts[(string) $r['status']] = (int) $r['n'];
        }
        $total = array_sum($counts);
        $terminal = $counts['scored'] + $counts['failed'] + $counts['skipped'];
        $avg = DB::val("SELECT AVG(final_score) FROM runs WHERE campaign_id = ? AND status = 'scored'", [$id]);
        return [
            'id' => (int) $c['id'],
            'name' => (string) $c['name'],
            'status' => (string) $c['status'],
            'season' => (string) $c['season'],
            'include_in_scores' => (bool) $c['include_in_scores'],
            'runs_per_task' => (int) $c['runs_per_task'],
            'total' => $total,
            'counts' => $counts,
            'done' => $terminal,
            'scored' => $counts['scored'],
            'failed' => $counts['failed'],
            'percent' => $total > 0 ? round(100 * $terminal / $total, 1) : 100.0,
            'spent' => (float) $c['spent_cost'],
            'est_cost' => $c['est_cost'] !== null ? (float) $c['est_cost'] : null,
            'avg_score' => $avg !== null ? round((float) $avg, 2) : null,
            'judgments_open' => (int) DB::val(
                "SELECT COUNT(*) FROM judgments j JOIN runs r ON r.id = j.run_id WHERE r.campaign_id = ? AND j.status IN ('pending', 'running')",
                [$id]
            ),
            'eta_seconds' => self::eta($c, $total - $terminal),
            'created_at' => $c['created_at'],
            'started_at' => $c['started_at'],
            'finished_at' => $c['finished_at'],
        ];
    }

    /** Progress of every running or paused campaign, newest first. */
    public static function activeProgress(int $limit = 20): array
    {
        $ids = DB::col("SELECT id FROM campaigns WHERE status IN ('running', 'paused') ORDER BY id DESC LIMIT " . max(1, $limit));
        return array_map(static fn($id) => self::progress((int) $id), $ids);
    }

    /** Total spend across all campaigns (USD). */
    public static function totalSpent(): float
    {
        return (float) DB::val('SELECT COALESCE(SUM(spent_cost), 0) FROM campaigns');
    }

    /**
     * Recompute campaigns.spent_cost: the sum of runs.spent_usd, which accumulates every paid call of a run (failed
     * and discarded generation attempts and judge calls included, Worker::addSpent). Before that column exists
     * (upgrade not migrated yet) it falls back to the stored generation + judgment costs.
     * The sums are read with a plain (non-locking) SELECT first: a subquery inside the UPDATE would take
     * shared locks on every run of the campaign and block / deadlock the workers that are updating them.
     */
    public static function refreshSpent(int $id): void
    {
        $sum = (float) (Pipeline::hasColumn('runs', 'spent_usd')
            ? DB::val('SELECT COALESCE(SUM(spent_usd), 0) FROM runs WHERE campaign_id = ?', [$id])
            : DB::val(
                'SELECT (SELECT COALESCE(SUM(r.cost_usd), 0) FROM runs r WHERE r.campaign_id = ?)
                      + (SELECT COALESCE(SUM(j.cost_usd), 0) FROM judgments j JOIN runs r2 ON r2.id = j.run_id WHERE r2.campaign_id = ?)',
                [$id, $id]
            ));
        Lease::retrying(static fn(): int => DB::exec('UPDATE campaigns SET spent_cost = ? WHERE id = ?', [round($sum, 4), $id]));
    }

    public static function pause(int $id): bool
    {
        return DB::exec("UPDATE campaigns SET status = 'paused' WHERE id = ? AND status = 'running'", [$id]) === 1;
    }

    /** Resume a paused (or draft) campaign. Resuming also clears a provider halt: the admin has dealt with it. */
    public static function resume(int $id): bool
    {
        $ok = DB::exec(
            "UPDATE campaigns SET status = 'running', finished_at = NULL, started_at = COALESCE(started_at, ?)
              WHERE id = ? AND status IN ('paused', 'draft')",
            [now(), $id]
        ) === 1;
        if ($ok && self::providerHalt() !== null) {
            self::clearHalt(false);
        }
        return $ok;
    }

    /**
     * The provider halt notice (setting 'provider_halt'), or null when none is active:
     * {code: 401|402|403, message, at: 'Y-m-d H:i:s', campaign_id, campaign_ids: [every campaign it paused]}.
     * Read straight from the database: another worker process may have set it a moment ago.
     */
    public static function providerHalt(): ?array
    {
        $v = DB::val("SELECT v FROM settings WHERE k = 'provider_halt'");
        $d = is_string($v) ? json_decode($v, true) : null;
        return is_array($d) && !empty($d['code']) ? $d : null;
    }

    /**
     * Record a provider halt (no credit / invalid or blocked key, see OpenRouter::haltOf) for a unit of
     * campaign $campaignId and pause that campaign. The worker claims no generation or judging unit while a halt
     * is set; Campaign::resume() or clearHalt() lifts it.
     */
    public static function halt(array $halt, int $campaignId): void
    {
        $cur = self::providerHalt();
        $ids = array_values(array_unique(array_map('intval', (array) ($cur['campaign_ids'] ?? []))));
        if ($campaignId > 0 && !in_array($campaignId, $ids, true)) {
            $ids[] = $campaignId;
        }
        if ($campaignId > 0) {
            Lease::retrying(static fn(): bool => self::pause($campaignId));
        }
        \Settings::set('provider_halt', [
            'code' => (int) ($halt['code'] ?? 0) ?: 402,
            'message' => Pipeline::clip(trim((string) ($halt['message'] ?? '')), 300),
            'at' => (string) ($cur['at'] ?? now()),
            'campaign_id' => $campaignId,
            'campaign_ids' => $ids,
        ]);
        log_error('Provider halt: campaign paused', ['code' => $halt['code'] ?? null, 'campaign' => $campaignId]);
    }

    /** Lift the provider halt; with $resume, also resume every campaign it paused. Returns campaigns resumed. */
    public static function clearHalt(bool $resume = true): int
    {
        $cur = self::providerHalt();
        \Settings::set('provider_halt', '');
        $n = 0;
        foreach ($resume ? array_map('intval', (array) ($cur['campaign_ids'] ?? [$cur['campaign_id'] ?? 0])) : [] as $id) {
            if ($id > 0 && self::resume($id)) {
                $n++;
            }
        }
        return $n;
    }

    /** Cancel: every unfinished run becomes skipped (in-flight results are discarded by the lease guard). */
    public static function cancel(int $id): bool
    {
        return DB::tx(static function () use ($id): bool {
            $n = DB::exec("UPDATE campaigns SET status = 'cancelled', finished_at = ? WHERE id = ? AND status IN ('running', 'paused', 'draft')", [now(), $id]);
            if ($n !== 1) {
                return false;
            }
            DB::exec(
                "UPDATE judgments j JOIN runs r ON r.id = j.run_id SET j.status = 'skipped', j.locked_until = NULL, j.lock_token = NULL
                  WHERE r.campaign_id = ? AND j.status IN ('pending', 'running')",
                [$id]
            );
            DB::exec(
                "UPDATE runs SET status = 'skipped', locked_until = NULL, lock_token = NULL, finished_at = ?
                  WHERE campaign_id = ? AND status NOT IN ('scored', 'failed', 'skipped')",
                [now(), $id]
            );
            return true;
        });
    }

    /** Retry every failed run of a campaign (not for cancelled campaigns). Returns the number retried. */
    public static function retryFailed(int $id): int
    {
        $status = DB::val('SELECT status FROM campaigns WHERE id = ?', [$id]);
        if ($status === null || $status === 'cancelled') {
            return 0;
        }
        $n = 0;
        foreach (DB::col("SELECT id FROM runs WHERE campaign_id = ? AND status = 'failed' ORDER BY id", [$id]) as $runId) {
            if (Pipeline::retry((int) $runId)) {
                $n++;
            }
        }
        if ($n > 0 && $status === 'done') {
            DB::exec("UPDATE campaigns SET status = 'running', finished_at = NULL WHERE id = ? AND status = 'done'", [$id]);
        }
        return $n;
    }

    /** Token profile of one task for the estimate. */
    private static function taskProfile(array $t): array
    {
        $type = (string) $t['output_type'];
        $prompt = (int) ceil(mb_strlen((string) $t['prompt']) / self::CHARS_PER_TOKEN) + self::PROMPT_OVERHEAD;
        $out = self::OUTPUT_TOKENS[$type] ?? self::OUTPUT_TOKENS['text'];
        $in = $type === 'agent'
            ? self::AGENT_STEPS * ($prompt + self::AGENT_STEP_CONTEXT * (self::AGENT_STEPS + 1) / 2)
            : $prompt;
        $checks = json_arr($t['checks_json'] ?? null);
        return [
            'prompt' => $prompt,
            'in' => (int) round($in),
            'out' => $out,
            'judged' => Pipeline::needsJudges($t),
            'shots' => $type === 'html' && (!empty($checks['browser']) || !empty($checks['tests']['cases'])) ? 2 : 0,
        ];
    }

    private static function price(float $in, float $out, array $m): float
    {
        return ($in * (float) ($m['price_in'] ?? 0) + $out * (float) ($m['price_out'] ?? 0)) / 1e6;
    }

    private static function eta(array $c, int $remaining): ?int
    {
        if ($remaining <= 0) {
            return 0;
        }
        if ($c['status'] !== 'running') {
            return null;
        }
        $recent = DB::one(
            "SELECT COUNT(*) AS n, MIN(finished_at) AS first FROM runs
              WHERE campaign_id = ? AND status IN ('scored', 'failed') AND finished_at >= ?",
            [(int) $c['id'], now(-900)]
        );
        $rate = 0.0;
        if ((int) $recent['n'] >= 2) {
            $rate = (int) $recent['n'] / max(60, time() - (int) strtotime($recent['first'] . ' UTC'));
        } elseif ($c['started_at']) {
            $done = (int) DB::val("SELECT COUNT(*) FROM runs WHERE campaign_id = ? AND status IN ('scored', 'failed')", [(int) $c['id']]);
            $elapsed = time() - (int) strtotime($c['started_at'] . ' UTC');
            $rate = $done > 0 && $elapsed > 0 ? $done / $elapsed : 0.0;
        }
        return $rate > 0 ? (int) round($remaining / $rate) : null;
    }

    /** Positive unique integer ids, input order kept. */
    private static function ids(array $ids): array
    {
        $out = [];
        foreach ($ids as $v) {
            if (is_numeric($v) && (int) $v > 0) {
                $out[(int) $v] = (int) $v;
            }
        }
        return array_values($out);
    }

    /** The subset of $ids that exist in $table (input order kept). */
    private static function existing(string $table, array $ids): array
    {
        if (!$ids || !in_array($table, ['models', 'tasks'], true)) {
            return [];
        }
        $found = array_map('intval', DB::col("SELECT id FROM `$table` WHERE id IN (" . DB::in($ids) . ')', $ids));
        return array_values(array_filter($ids, static fn($id) => in_array($id, $found, true)));
    }
}
