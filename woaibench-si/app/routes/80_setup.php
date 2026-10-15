<?php
/**
 * Upgrade tools for administrators (module: installer).
 *   GET  /admin/api/setup-status  → schema state, pending migrations, seed-sync preview
 *   POST /admin/api/migrate       → WB\Setup\Migrator::run()
 *   POST /admin/api/seed-sync     → WB\Setup\Seeder::run(update_existing)   body: {"update_existing": bool}
 * All JSON; admin session required; POSTs need the CSRF token (WB.fetchJSON adds it).
 */
declare(strict_types=1);

defined('WB') || exit;

use WB\Setup\Migrator;
use WB\Setup\Seeder;

Router::get('/admin/api/setup-status', static function (): void {
    require_admin();
    try {
        json_out(['ok' => true, 'schema' => Migrator::status(), 'seed' => Seeder::preview(false)]);
    } catch (Throwable $e) {
        log_error('setup-status failed: ' . $e->getMessage());
        json_out(['ok' => false, 'error' => __('setup.admin_failed', ['error' => $e->getMessage()])], 500);
    }
});

Router::post('/admin/api/migrate', static function (): void {
    $admin = require_admin();
    csrf_check();
    wb_time_limit(300);
    $r = Migrator::run();
    log_error('Admin ran database migration', ['admin' => $admin['email'], 'ok' => $r['ok'], 'applied' => $r['migrations']]);
    if (!$r['ok']) {
        json_out(['ok' => false, 'error' => __('setup.admin_failed', ['error' => $r['error']])] + $r, 500);
    }
    $msg = __('setup.admin_migrate_ok', ['files' => count(Migrator::schemaFiles()), 'n' => count($r['migrations'])]);
    json_out(['ok' => true, 'message' => $msg] + $r);
});

Router::post('/admin/api/seed-sync', static function (): void {
    $admin = require_admin();
    csrf_check();
    wb_time_limit(300);
    $body = json_body();
    $update = filter_var($body['update_existing'] ?? input('update_existing', false), FILTER_VALIDATE_BOOLEAN);
    try {
        $r = Seeder::run($update);
    } catch (Throwable $e) {
        log_error('Seed sync failed: ' . $e->getMessage());
        json_out(['ok' => false, 'error' => __('setup.admin_failed', ['error' => $e->getMessage()])], 500);
    }
    log_error('Admin ran seed sync', ['admin' => $admin['email'], 'update_existing' => $update, 'tasks' => $r['tasks']]);
    $t = $r['tasks'];
    $msg = __('setup.admin_seed_ok', [
        'inserted' => $t['inserted'] + $r['tracks']['inserted'] + $r['gpus']['inserted'],
        'updated' => $t['updated'] + $r['tracks']['updated'] + $r['gpus']['updated'],
        'unchanged' => $t['unchanged'] + $r['tracks']['unchanged'] + $r['gpus']['unchanged'],
        'skipped' => count($r['skipped']),
    ]);
    json_out(['ok' => true, 'message' => $msg] + $r);
});
