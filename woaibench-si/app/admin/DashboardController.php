<?php
/**
 * Dashboard (health, queue, spend) and the benchmark console (control room).
 */
declare(strict_types=1);

namespace WB\Admin;

defined('WB') || exit;

use DB;
use Config;

final class DashboardController
{
    public const STATUSES = ['queued', 'generating', 'awaiting_verify', 'verifying', 'judging', 'scoring', 'scored', 'failed', 'skipped'];

    /** GET /admin */
    public static function index(): void
    {
        $user = \require_admin();
        $running = DB::all("SELECT * FROM campaigns WHERE status IN ('running','paused') ORDER BY id DESC LIMIT 8");
        $progress = array_map([Admin::class, 'progress'], $running);
        $hasRunning = (bool) array_filter($running, static fn($c) => $c['status'] === 'running');
        $age = Admin::heartbeatAge();
        Admin::render('dashboard', [
            'title' => \__('admin.nav.dashboard'),
            'nav_active' => 'dashboard',
            'user' => $user,
            'counts' => Admin::statusCounts(),
            'statuses' => self::STATUSES,
            'campaigns' => $progress,
            'has_running' => $hasRunning,
            'heartbeat_age' => $age,
            'heartbeat_stale' => $hasRunning && ($age === null || $age > 300),
            'cron_cmd' => self::cronCommand(),
            'spend_month' => Admin::spend(Admin::monthStart()),
            'spend_total' => Admin::spend(),
            'failures' => DB::all(
                "SELECT r.id, r.error, r.updated_at, r.tries, m.name AS model, t.title_en, t.title_ar
                   FROM runs r JOIN models m ON m.id = r.model_id JOIN tasks t ON t.id = r.task_id
                  WHERE r.status = 'failed' ORDER BY r.updated_at DESC LIMIT 8"
            ),
            'key_set' => (string) Config::get('openrouter_key', '') !== '',
            'key_mask' => Admin::mask((string) Config::get('openrouter_key', '')),
            'aggregate_last' => (string) \setting('aggregate_last', ''),
            'aggregate_dirty' => (int) \setting('aggregate_dirty', 0) === 1,
            'totals' => [
                'models' => (int) DB::val('SELECT COUNT(*) FROM models WHERE is_active = 1'),
                'judges' => (int) DB::val('SELECT COUNT(*) FROM models WHERE is_judge = 1'),
                'tasks' => (int) DB::val('SELECT COUNT(*) FROM tasks WHERE is_active = 1'),
                'holdout' => (int) DB::val("SELECT COUNT(*) FROM tasks WHERE is_active = 1 AND visibility = 'holdout'"),
                'requests' => Admin::newRequests(),
                'season' => (string) \setting('season'),
            ],
            'engine' => [
                'Campaign' => Admin::engine('Campaign'),
                'OpenRouter' => Admin::engine('OpenRouter'),
                'Aggregator' => Admin::engine('Aggregator'),
                'Worker' => Admin::engine('Worker'),
            ],
        ]);
    }

    /** Exact command to schedule on Hostinger (every minute). */
    public static function cronCommand(): string
    {
        $root = realpath(WB_ROOT) ?: WB_ROOT;
        return 'php ' . $root . '/cron/worker.php --seconds=55';
    }

    /** GET /admin/console */
    public static function console(): void
    {
        \require_admin();
        $campaigns = DB::all("SELECT * FROM campaigns WHERE status IN ('running','paused') ORDER BY id DESC LIMIT 12");
        $verifierJs = is_file(WB_ROOT . '/assets/js/verifier.js');
        Admin::render('console', [
            'title' => \__('admin.nav.console'),
            'nav_active' => 'console',
            'counts' => Admin::statusCounts(),
            'statuses' => self::STATUSES,
            'campaigns' => array_map([Admin::class, 'progress'], $campaigns),
            'spend_total' => Admin::engine('Campaign') && method_exists('WB\\Eval\\Campaign', 'totalSpent') ? \WB\Eval\Campaign::totalSpent() : Admin::spend(),
            'verifier_js' => $verifierJs,
            'heartbeat_age' => Admin::heartbeatAge(),
            'page_css' => is_file(WB_ROOT . '/assets/css/verifier.css') ? ['css/verifier.css'] : [],
            'page_js' => array_values(array_filter([$verifierJs ? 'js/verifier.js' : null, 'js/console.js'])),
        ]);
    }
}
