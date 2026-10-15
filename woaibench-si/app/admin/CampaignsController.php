<?php
/**
 * Campaigns: list with progress/spend, create (model + task pickers, live estimate), detail matrix, actions.
 */
declare(strict_types=1);

namespace WB\Admin;

defined('WB') || exit;

use DB;
use Throwable;

final class CampaignsController
{
    public const STATUSES = ['running', 'paused', 'done', 'cancelled'];

    /** GET /admin/campaigns */
    public static function index(): void
    {
        \require_admin();
        $status = (string) \input('status', '');
        $where = in_array($status, self::STATUSES, true) ? ' WHERE status = ?' : '';
        $params = $where !== '' ? [$status] : [];
        $total = (int) DB::val('SELECT COUNT(*) FROM campaigns' . $where, $params);
        [$page, $pages, $offset] = Admin::paginate($total, 25);
        $rows = DB::all('SELECT * FROM campaigns' . $where . ' ORDER BY id DESC LIMIT 25 OFFSET ' . $offset, $params);
        Admin::render('campaigns', [
            'title' => \__('admin.nav.campaigns'),
            'nav_active' => 'campaigns',
            'campaigns' => array_map(static fn($c) => $c + ['progress' => Admin::progress($c)], $rows),
            'status' => $status,
            'page' => $page,
            'pages' => $pages,
            'engine' => Admin::engine('Campaign'),
        ]);
    }

    /** GET /admin/campaigns/new */
    public static function create(): void
    {
        \require_admin();
        $models = DB::all('SELECT id, slug, name, provider, provider_name, price_in, price_out, is_judge, color FROM models WHERE is_active = 1 ORDER BY provider, name');
        $tasks = DB::all(
            'SELECT t.id, t.slug, t.title_en, t.title_ar, t.output_type, t.difficulty, t.visibility, tr.slug AS track, tr.name_en, tr.name_ar, tr.color, tr.icon
               FROM tasks t JOIN tracks tr ON tr.id = t.track_id WHERE t.is_active = 1 ORDER BY tr.sort, tr.id, t.id'
        );
        $byTrack = [];
        foreach ($tasks as $t) {
            $byTrack[$t['track']]['track'] = ['slug' => $t['track'], 'name_en' => $t['name_en'], 'name_ar' => $t['name_ar'], 'color' => $t['color'], 'icon' => $t['icon']];
            $byTrack[$t['track']]['tasks'][] = $t;
        }
        $old = $_SESSION['admin_campaign_old'] ?? [];
        unset($_SESSION['admin_campaign_old']);
        Admin::render('campaign_new', [
            'title' => \__('admin.campaigns.new'),
            'nav_active' => 'campaigns',
            'models' => $models,
            'groups' => $byTrack,
            'old' => is_array($old) ? $old : [],
            'runs_default' => (int) \setting('runs_per_task', 3),
            'engine' => Admin::engine('Campaign'),
            'judges' => (int) DB::val('SELECT COUNT(*) FROM models WHERE is_judge = 1'),
        ]);
    }

    /** Collect and validate selection from POST or JSON. Returns [modelIds, taskIds, runs, genParams, errors]. */
    private static function selection(array $in): array
    {
        $ids = static fn($v) => is_array($v) ? array_values(array_unique(array_filter(array_map('intval', $v), static fn($x) => $x > 0))) : [];
        $modelIds = $ids($in['model_ids'] ?? []);
        $taskIds = $ids($in['task_ids'] ?? []);
        $runs = (int) ($in['runs_per_task'] ?? \setting('runs_per_task', 3));
        $errors = [];
        if ($modelIds) {
            $modelIds = array_map('intval', DB::col('SELECT id FROM models WHERE is_active = 1 AND id IN (' . DB::in($modelIds) . ')', $modelIds));
        }
        if ($taskIds) {
            $taskIds = array_map('intval', DB::col('SELECT id FROM tasks WHERE is_active = 1 AND id IN (' . DB::in($taskIds) . ')', $taskIds));
        }
        if (!$modelIds) {
            $errors[] = \__('admin.campaigns.err_models');
        }
        if (!$taskIds) {
            $errors[] = \__('admin.campaigns.err_tasks');
        }
        if ($runs < 1 || $runs > 10) {
            $errors[] = \__('admin.campaigns.err_runs');
        }
        $gen = [];
        $gpRaw = $in['gen_params'] ?? '';
        if (is_array($gpRaw)) {
            $gen = $gpRaw;
        } elseif (is_string($gpRaw) && trim($gpRaw) !== '') {
            $decoded = json_decode($gpRaw, true);
            if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
                $errors[] = \__('admin.campaigns.err_gen') . ' ' . \__('admin.v.json_object');
            } else {
                $gen = $decoded;
            }
        }
        if ($gen && ($bad = ModelsController::genParamProblem($gen)) !== null) {
            $errors[] = $bad;
        }
        return [$modelIds, $taskIds, max(1, min(10, $runs)), $gen, $errors];
    }

    /** POST /admin/api/estimate {model_ids, task_ids, runs_per_task} */
    public static function apiEstimate(): void
    {
        Admin::guardPost();
        [$m, $t, $runs, , $errors] = self::selection(\json_body());
        if (!$m || !$t) {
            \json_out(['ok' => true, 'empty' => true, 'runs' => count($m) * count($t) * $runs, 'total' => 0]);
        }
        if (!Admin::engine('Campaign')) {
            \json_out(['ok' => false, 'error' => \__('admin.engine_missing', ['c' => 'WB\\Eval\\Campaign'])], 503);
        }
        try {
            $est = \WB\Eval\Campaign::estimate($m, $t, $runs);
        } catch (Throwable $e) {
            \log_error('Campaign::estimate failed', ['error' => $e->getMessage()]);
            \json_out(['ok' => false, 'error' => $e->getMessage()], 500);
        }
        $names = DB::keyed('SELECT id, name FROM models WHERE id IN (' . DB::in($m) . ')', $m);
        $per = [];
        foreach ((array) ($est['per_model'] ?? []) as $k => $row) {
            $row = is_array($row) ? $row : ['cost' => $row];
            $mid = (int) ($row['model_id'] ?? $row['id'] ?? (is_int($k) && isset($names[$k]) ? $k : 0));
            $per[] = [
                'name' => (string) ($row['name'] ?? $names[$mid]['name'] ?? ('#' . $mid)),
                'cost' => (float) ($row['total'] ?? $row['cost'] ?? (($row['gen_cost'] ?? 0) + ($row['judge_cost'] ?? 0))),
                'runs' => (int) ($row['runs'] ?? 0),
            ];
        }
        \json_out([
            'ok' => true,
            'runs' => (int) ($est['runs'] ?? count($m) * count($t) * $runs),
            'gen_cost' => (float) ($est['gen_cost'] ?? 0),
            'judge_cost' => (float) ($est['judge_cost'] ?? 0),
            'total' => (float) ($est['total'] ?? 0),
            'per_model' => $per,
            'missing_prices' => array_values(array_map('strval', (array) ($est['assumptions']['missing_prices'] ?? []))),
            'notes' => array_values(array_map('strval', (array) ($est['assumptions']['notes'] ?? []))),
            'warnings' => $errors,
        ]);
    }

    /** POST /admin/campaigns/new */
    public static function store(): void
    {
        $user = Admin::guardPost();
        [$m, $t, $runs, $gen, $errors] = self::selection($_POST);
        $name = mb_substr(trim((string) \input('name', '')), 0, 190);
        if ($name === '') {
            $errors[] = \__('admin.campaigns.err_name');
        }
        if (!Admin::engine('Campaign')) {
            $errors[] = \__('admin.engine_missing', ['c' => 'WB\\Eval\\Campaign']);
        }
        if ($errors) {
            $_SESSION['admin_campaign_old'] = [
                'name' => $name, 'model_ids' => $m, 'task_ids' => $t, 'runs_per_task' => $runs,
                'gen_params' => is_string($_POST['gen_params'] ?? null) ? $_POST['gen_params'] : '', 'include' => (bool) \input('include_in_scores'),
            ];
            foreach ($errors as $err) {
                \flash('error', $err);
            }
            \redirect('/admin/campaigns/new');
        }
        try {
            $id = \WB\Eval\Campaign::create($name, $m, $t, $runs, $gen);
        } catch (Throwable $e) {
            \log_error('Campaign::create failed', ['error' => $e->getMessage()]);
            \flash('error', \__('admin.campaigns.err_create', ['msg' => $e->getMessage()]));
            \redirect('/admin/campaigns/new');
        }
        DB::update('campaigns', ['include_in_scores' => \input('include_in_scores') ? 1 : 0, 'created_by' => (int) $user['id']], 'id = ?', [$id]);
        \flash('success', \__('admin.campaigns.created_msg', ['n' => count($m) * count($t) * $runs]));
        \redirect('/admin/console');
    }

    /** GET /admin/campaigns/{id} — matrix models × tasks with run dots. */
    public static function show(int $id): void
    {
        \require_admin();
        $c = DB::one('SELECT * FROM campaigns WHERE id = ?', [$id]);
        if (!$c) {
            \abort(404);
        }
        $runs = DB::all('SELECT id, model_id, task_id, attempt, status, final_score, cost_usd, contested FROM runs WHERE campaign_id = ? ORDER BY attempt', [$id]);
        $cells = [];
        $modelIds = [];
        $taskIds = [];
        foreach ($runs as $r) {
            $cells[(int) $r['model_id']][(int) $r['task_id']][] = $r;
            $modelIds[(int) $r['model_id']] = true;
            $taskIds[(int) $r['task_id']] = true;
        }
        $mIds = array_keys($modelIds) ?: [0];
        $tIds = array_keys($taskIds) ?: [0];
        $models = DB::all('SELECT id, slug, name, provider, provider_name FROM models WHERE id IN (' . DB::in($mIds) . ') ORDER BY provider, name', $mIds);
        $tasks = DB::all(
            'SELECT t.id, t.slug, t.title_en, t.title_ar, tr.slug AS track, tr.name_en, tr.name_ar, tr.color, tr.icon
               FROM tasks t JOIN tracks tr ON tr.id = t.track_id WHERE t.id IN (' . DB::in($tIds) . ') ORDER BY tr.sort, t.id',
            $tIds
        );
        $modelStats = [];
        foreach (DB::all(
            "SELECT model_id, COUNT(*) AS n, SUM(status = 'scored') AS scored, SUM(status = 'failed') AS failed,
                    AVG(CASE WHEN status = 'scored' THEN final_score END) AS avg_score, SUM(cost_usd) AS cost
               FROM runs WHERE campaign_id = ? GROUP BY model_id",
            [$id]
        ) as $s) {
            $modelStats[(int) $s['model_id']] = $s;
        }
        Admin::render('campaign_show', [
            'title' => $c['name'],
            'nav_active' => 'campaigns',
            'campaign' => $c,
            'progress' => Admin::progress($c),
            'models' => $models,
            'tasks' => $tasks,
            'cells' => $cells,
            'model_stats' => $modelStats,
            'gen_params' => \json_arr($c['gen_params']),
        ]);
    }

    /** POST /admin/campaigns/{id}/action  action=pause|resume|cancel|retry_failed|include|exclude|delete */
    public static function action(int $id): void
    {
        Admin::guardPost();
        $c = DB::one('SELECT * FROM campaigns WHERE id = ?', [$id]);
        if (!$c) {
            \abort(404);
        }
        $action = (string) \input('action');
        $msg = \__('admin.saved');
        switch ($action) {
            case 'pause':
            case 'resume':
            case 'cancel':
                if (Admin::engine('Campaign')) {
                    call_user_func(['WB\\Eval\\Campaign', $action], $id);
                } else {
                    DB::update('campaigns', ['status' => ['pause' => 'paused', 'resume' => 'running', 'cancel' => 'cancelled'][$action]], 'id = ?', [$id]);
                }
                $msg = \__('admin.campaigns.did_' . $action);
                break;
            case 'retry_failed':
                if (Admin::engine('Campaign') && method_exists('WB\\Eval\\Campaign', 'retryFailed')) {
                    $n = \WB\Eval\Campaign::retryFailed($id);
                } else {
                    $n = RunsController::resetRuns('campaign_id = ? AND status = ?', [$id, 'failed']);
                    if ($n > 0 && $c['status'] === 'done') {
                        DB::update('campaigns', ['status' => 'running', 'finished_at' => null], 'id = ?', [$id]);
                    }
                }
                if ($n > 0) {
                    \Settings::set('aggregate_dirty', 1);
                }
                $msg = \__('admin.campaigns.did_retry', ['n' => $n]);
                break;
            case 'include':
            case 'exclude':
                DB::update('campaigns', ['include_in_scores' => $action === 'include' ? 1 : 0], 'id = ?', [$id]);
                \Settings::set('aggregate_dirty', 1);
                $msg = \__('admin.campaigns.did_' . $action);
                break;
            case 'delete':
                if ($c['status'] === 'running') {
                    \flash('error', \__('admin.campaigns.err_delete_running'));
                    \redirect('/admin/campaigns/' . $id);
                }
                DB::exec('DELETE FROM campaigns WHERE id = ?', [$id]);
                \Settings::set('aggregate_dirty', 1);
                \flash('success', \__('admin.deleted'));
                \redirect('/admin/campaigns');
            default:
                \abort(400, 'Unknown action');
        }
        \flash('success', $msg);
        \redirect_back('/admin/campaigns/' . $id);
    }

    /** POST /admin/api/campaigns — progress of running/paused campaigns (console fallback polling). */
    public static function apiProgress(): void
    {
        Admin::guardPost();
        $rows = DB::all("SELECT * FROM campaigns WHERE status IN ('running','paused') ORDER BY id DESC LIMIT 12");
        \json_out(['ok' => true, 'counts' => Admin::statusCounts(), 'campaigns' => array_map([Admin::class, 'progress'], $rows), 'spent_usd' => Admin::spend()]);
    }
}
