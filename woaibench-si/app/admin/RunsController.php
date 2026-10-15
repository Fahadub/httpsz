<?php
/**
 * Runs explorer: filters, detail view (outputs, checks, judgments), retry / re-judge / delete.
 */
declare(strict_types=1);

namespace WB\Admin;

defined('WB') || exit;

use DB;

final class RunsController
{
    private const PER_PAGE = 50;

    /** GET /admin/runs */
    public static function index(): void
    {
        \require_admin();
        $f = [
            'campaign' => \input_int('campaign', 0, 0),
            'model' => \input_int('model', 0, 0),
            'task' => \input_int('task', 0, 0),
            'status' => (string) \input('status', ''),
            'contested' => (string) \input('contested', ''),
        ];
        $w = [];
        $p = [];
        foreach (['campaign' => 'r.campaign_id', 'model' => 'r.model_id', 'task' => 'r.task_id'] as $k => $col) {
            if ($f[$k] > 0) {
                $w[] = $col . ' = ?';
                $p[] = $f[$k];
            }
        }
        if (in_array($f['status'], DashboardController::STATUSES, true)) {
            $w[] = 'r.status = ?';
            $p[] = $f['status'];
        }
        if ($f['contested'] === '1') {
            $w[] = 'r.contested = 1';
        }
        $where = $w ? ' WHERE ' . implode(' AND ', $w) : '';
        $total = (int) DB::val('SELECT COUNT(*) FROM runs r' . $where, $p);
        [$page, $pages, $offset] = Admin::paginate($total, self::PER_PAGE);
        $rows = DB::all(
            'SELECT r.id, r.campaign_id, r.attempt, r.status, r.tries, r.final_score, r.auto_score, r.judge_score, r.judge_spread,
                    r.contested, r.cost_usd, r.latency_ms, r.updated_at, r.verified, r.error,
                    m.name AS model, m.slug AS model_slug, m.provider, m.provider_name, t.slug AS task_slug, t.title_en, t.title_ar, t.visibility
               FROM runs r JOIN models m ON m.id = r.model_id JOIN tasks t ON t.id = r.task_id' . $where
            . ' ORDER BY r.id DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . $offset,
            $p
        );
        Admin::render('runs', [
            'title' => \__('admin.nav.runs'),
            'nav_active' => 'runs',
            'runs' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'filters' => $f,
            'statuses' => DashboardController::STATUSES,
            'campaigns' => DB::all('SELECT id, name FROM campaigns ORDER BY id DESC LIMIT 200'),
            'models' => DB::all('SELECT id, name FROM models ORDER BY name'),
            'tasks' => DB::all('SELECT id, slug FROM tasks ORDER BY slug'),
        ]);
    }

    /** GET /admin/runs/{id} */
    public static function show(int $id): void
    {
        \require_admin();
        $run = DB::one(
            'SELECT r.*, m.name AS model, m.slug AS model_slug, m.or_id, m.provider, m.provider_name,
                    t.slug AS task_slug, t.title_en, t.title_ar, t.output_type, t.visibility, t.rubric_json, c.name AS campaign
               FROM runs r JOIN models m ON m.id = r.model_id JOIN tasks t ON t.id = r.task_id JOIN campaigns c ON c.id = r.campaign_id
              WHERE r.id = ?',
            [$id]
        );
        if (!$run) {
            \abort(404);
        }
        $judgments = DB::all(
            'SELECT j.*, m.name AS judge, m.provider AS judge_provider FROM judgments j JOIN models m ON m.id = j.judge_model_id
              WHERE j.run_id = ? ORDER BY j.id',
            [$id]
        );
        $siblings = DB::all('SELECT id, attempt, status, final_score FROM runs WHERE campaign_id = ? AND model_id = ? AND task_id = ? ORDER BY attempt', [$run['campaign_id'], $run['model_id'], $run['task_id']]);
        Admin::render('run_show', [
            'title' => \__('admin.runs.run_n', ['n' => $id]),
            'nav_active' => 'runs',
            'run' => $run,
            'judgments' => $judgments,
            'siblings' => $siblings,
            'rubric' => \json_arr($run['rubric_json']),
        ]);
    }

    /**
     * Reset runs to a fresh 'queued' state (outputs, checks, scores and judgments cleared).
     * Returns the number of runs reset.
     */
    public static function resetRuns(string $where, array $params): int
    {
        $ids = array_map('intval', DB::col('SELECT id FROM runs WHERE ' . $where, $params));
        if (!$ids) {
            return 0;
        }
        DB::tx(static function () use ($ids): void {
            foreach (array_chunk($ids, 500) as $chunk) {
                DB::exec('DELETE FROM judgments WHERE run_id IN (' . DB::in($chunk) . ')', $chunk);
                DB::exec(
                    "UPDATE runs SET status = 'queued', locked_until = NULL, lock_token = NULL, tries = 0, verify_tries = 0,
                            prompt_tokens = NULL, completion_tokens = NULL, reasoning_tokens = NULL, cost_usd = NULL, latency_ms = NULL,
                            finish_reason = NULL, provider_used = NULL, gen_id = NULL, raw_output = NULL, code = NULL, code_bytes = NULL,
                            static_json = NULL, browser_json = NULL, agent_json = NULL, verified = 0, verified_by = NULL,
                            screenshot = NULL, screenshot_mobile = NULL, auto_score = NULL, judge_score = NULL, judge_spread = NULL,
                            final_score = NULL, contested = 0, error = NULL, finished_at = NULL
                      WHERE id IN (" . DB::in($chunk) . ')',
                    $chunk
                );
            }
        });
        \Settings::set('aggregate_dirty', 1);
        return count($ids);
    }

    /** POST /admin/runs/{id}/action  action=retry|rejudge|delete */
    public static function action(int $id): void
    {
        Admin::guardPost();
        $run = DB::one('SELECT r.id, r.status, r.campaign_id, t.rubric_json FROM runs r JOIN tasks t ON t.id = r.task_id WHERE r.id = ?', [$id]);
        if (!$run) {
            \abort(404);
        }
        switch ((string) \input('action')) {
            case 'retry':
                self::resetRuns('id = ?', [$id]);
                DB::exec("UPDATE campaigns SET status = 'running', finished_at = NULL WHERE id = ? AND status = 'done'", [(int) $run['campaign_id']]);
                \flash('success', \__('admin.runs.did_retry'));
                break;
            case 'rejudge':
                if (\json_arr($run['rubric_json']) === []) {
                    \flash('error', \__('admin.runs.err_no_rubric'));
                    break;
                }
                $hasOutput = (bool) DB::val('SELECT raw_output IS NOT NULL FROM runs WHERE id = ?', [$id]);
                if (!$hasOutput || !in_array($run['status'], ['scored', 'judging', 'scoring', 'failed'], true)) {
                    \flash('error', \__('admin.runs.err_rejudge_state'));
                    break;
                }
                DB::tx(static function () use ($id): void {
                    DB::exec('DELETE FROM judgments WHERE run_id = ?', [$id]);
                    DB::exec(
                        "UPDATE runs SET status = 'judging', locked_until = NULL, lock_token = NULL, tries = 0, judge_score = NULL,
                                judge_spread = NULL, final_score = NULL, contested = 0, error = NULL, finished_at = NULL WHERE id = ?",
                        [$id]
                    );
                });
                DB::exec("UPDATE campaigns SET status = 'running', finished_at = NULL WHERE id = ? AND status = 'done'", [(int) $run['campaign_id']]);
                \Settings::set('aggregate_dirty', 1);
                \flash('success', \__('admin.runs.did_rejudge'));
                break;
            case 'delete':
                DB::exec('DELETE FROM runs WHERE id = ?', [$id]);
                \Settings::set('aggregate_dirty', 1);
                \flash('success', \__('admin.deleted'));
                \redirect('/admin/runs');
            default:
                \abort(400, 'Unknown action');
        }
        \redirect('/admin/runs/' . $id);
    }
}
