<?php
/**
 * Verifier module routes: sandboxed render documents (/render/{id}) and the admin Verification Station.
 * The verifier API (/api/verifier/*) belongs to the engine pipeline and is registered elsewhere.
 */
declare(strict_types=1);

defined('WB') || exit;

require_once WB_APP . '/render/render.php';

Router::get('/render/{id:\d+}', static function (string $id): void {
    $runId = (int) $id;
    $run = $runId > 0
        ? DB::one('SELECT id, task_id, status, code, raw_output FROM runs WHERE id = ?', [$runId])
        : null;
    if (!$run || !wb_render_is_generated($run)) {
        abort(404);
    }
    $task = DB::one(
        'SELECT id, slug, title_ar, title_en, output_type, visibility, checks_json FROM tasks WHERE id = ?',
        [(int) $run['task_id']]
    );
    if (!$task) {
        abort(404);
    }
    $vt = input('vt');
    $vt = is_string($vt) ? $vt : '';
    $vtOk = $vt !== '' && wb_render_vt_valid($runId, $vt);
    $privileged = $vtOk || Auth::check();
    // Hold-out runs do not exist for the public (404, not 403, so ids leak nothing).
    if (($task['visibility'] ?? 'public') !== 'public' && !$privileged) {
        abort(404);
    }
    $verify = input('verify') === '1';
    if ($verify && !$privileged) {
        abort(403);
    }
    $vp = input('vp');
    $theme = input('theme');
    wb_render_document($run, $task, [
        'verify' => $verify,
        'vt' => $vt,
        'mode' => input('mode') === 'playwright' ? 'playwright' : 'station',
        'viewport' => is_string($vp) && preg_match('/^[a-z0-9_-]{1,20}$/', $vp) ? $vp : 'desktop',
        'light' => input('light') === '1',
        'theme' => in_array($theme, ['dark', 'light'], true) ? $theme : null,
    ]);
});

Router::get('/admin/verify', static function (): void {
    require_admin();
    $counts = ['awaiting_verify' => 0, 'verifying' => 0];
    foreach (DB::all("SELECT status, COUNT(*) AS n FROM runs WHERE status IN ('awaiting_verify', 'verifying') GROUP BY status") as $row) {
        $counts[$row['status']] = (int) $row['n'];
    }
    $recent = DB::all(
        "SELECT r.id, r.status, r.verified_by, r.screenshot, r.browser_json, r.updated_at,
                t.slug, t.title_ar, t.title_en, t.output_type, t.visibility
           FROM runs r JOIN tasks t ON t.id = r.task_id
          WHERE r.verified = 1
          ORDER BY r.updated_at DESC, r.id DESC
          LIMIT 8"
    );
    foreach ($recent as &$r) {
        $checks = (array) (json_arr($r['browser_json'])['checks'] ?? []);
        $r['checks_total'] = count($checks);
        $r['checks_passed'] = count(array_filter($checks, static fn($c) => is_array($c) && !empty($c['pass'])));
        unset($r['browser_json']);
    }
    unset($r);
    $layout = is_file(WB_APP . '/views/admin/_layout.php') ? 'admin' : 'layout';
    view('admin/verify', [
        'title' => __('verifier.station.title'),
        'nav_active' => 'verify',
        'robots' => 'noindex',
        'page_css' => ['css/verifier.css'],
        'page_js' => ['js/verifier.js'],
        'counts' => $counts,
        'verified_24h' => (int) DB::val("SELECT COUNT(*) FROM runs WHERE verified = 1 AND updated_at >= (UTC_TIMESTAMP() - INTERVAL 1 DAY)"),
        'recent' => $recent,
        'verify_mode' => (string) setting('verify_mode'),
        'token_count' => (int) DB::val("SELECT COUNT(*) FROM api_tokens WHERE scope = 'verifier'"),
    ], $layout);
});
