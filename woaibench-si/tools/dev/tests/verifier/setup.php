<?php
/**
 * DEV ONLY — prepares a LOCAL database for the verifier test-suite (tools/dev/tests/verifier).
 *
 *   WB_DB_NAME=wb_verifier php tools/dev/tests/verifier/setup.php            runs leased (status verifying) + vt tokens
 *   WB_DB_NAME=wb_verifier php tools/dev/tests/verifier/setup.php --queue    runs awaiting_verify in a running campaign
 *
 * Creates (idempotently) a test track, a fictional model, one task + one run per page in ./pages,
 * an admin (probe-admin@example.test) and a verifier API token. Prints JSON for the test runner.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
define('WB', true);
require dirname(__DIR__, 4) . '/app/bootstrap.php';
require_once WB_APP . '/render/render.php';

$host = (string) Config::get('db.host');
if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "Refusing: test setup only runs against a local database.\n");
    exit(1);
}
$queue = in_array('--queue', $argv, true);
$base = rtrim((string) (getenv('WB_TEST_BASE') ?: 'http://127.0.0.1:8084'), '/');
$pages = __DIR__ . '/pages';
$read = static fn(string $f): string => (string) file_get_contents($pages . '/' . $f);
$b = static fn(string $id, string $type, array $extra = []): array => ['id' => $id, 'type' => $type, 'weight' => 1, 'label_en' => $id, 'label_ar' => $id] + $extra;

$jsTests = [
    'entry' => 'solve', 'timeout_ms' => 1000,
    'cases' => [
        ['name' => 'basic', 'args' => [[3, 1, 2]], 'expect' => [1, 2, 3]],
        ['name' => 'empty', 'args' => [[]], 'expect' => []],
        ['name' => 'negatives', 'args' => [[0, -5, 3, -1]], 'expect' => [-5, -1, 0, 3]],
        ['name' => 'wrong', 'args' => [[7, 7]], 'expect' => [7, 7]],
        ['name' => 'timeout', 'args' => [[42]], 'expect' => [42]],
        ['name' => 'after-timeout', 'args' => [[2, 1]], 'expect' => [1, 2]],
        ['name' => 'approx', 'script' => 'return 0.1 + 0.2;', 'expect' => 0.3, 'cmp' => 'approx'],
        ['name' => 'set', 'args' => [[3, 1, 2]], 'expect' => [2, 3, 1], 'cmp' => 'set'],
        ['name' => 'class-script', 'script' => 'const c = new Counter(); c.inc(); return c.inc();', 'expect' => 2],
        ['name' => 'truthy', 'script' => 'return typeof solve === "function";', 'expect' => true, 'cmp' => 'truthy'],
    ],
];

// slug => [page file | null, output_type, visibility, browser checks, tests]
$defs = [
    'vt-good' => ['good.html', 'html', 'public', [
        $b('loads', 'loads'), $b('no_errors', 'no_errors'), $b('nonblank', 'nonblank'), $b('has_button', 'selector', ['sel' => 'button', 'min' => 1]),
        $b('title_text', 'text', ['contains' => 'aurora notes', 'ci' => true]), $b('animates', 'animates'), $b('fps', 'fps', ['min' => 30]),
        $b('responsive', 'responsive'), $b('keyboard', 'keyboard', ['keys' => ['ArrowRight']]), $b('click', 'click', ['sel' => '#toggle']),
        $b('a11y', 'a11y_basic'), $b('rtl', 'rtl'), $b('canvas', 'canvas'),
    ], null],
    'vt-broken' => ['broken.html', 'html', 'public', [$b('loads', 'loads'), $b('no_errors', 'no_errors'), $b('nonblank', 'nonblank')], null],
    'vt-blank' => ['blank.html', 'html', 'public', [$b('loads', 'loads'), $b('nonblank', 'nonblank'), $b('animates', 'animates'), $b('canvas', 'canvas')], null],
    'vt-hang' => ['hang.html', 'html', 'public', [$b('loads', 'loads'), $b('no_errors', 'no_errors'), $b('nonblank', 'nonblank')], null],
    'vt-game' => ['game.html', 'html', 'public', [
        $b('canvas', 'canvas'), $b('animates', 'animates'), $b('fps', 'fps', ['min' => 20]), $b('keyboard', 'keyboard'), $b('click', 'click'), $b('no_errors', 'no_errors'),
    ], null],
    'vt-webgl' => ['webgl.html', 'html', 'public', [$b('canvas', 'canvas'), $b('nonblank', 'nonblank'), $b('animates', 'animates'), $b('fps', 'fps', ['min' => 20])], null],
    'vt-wide' => ['wide.html', 'html', 'public', [$b('responsive', 'responsive'), $b('nonblank', 'nonblank')], null],
    'vt-rtl' => ['rtl.html', 'html', 'public', [$b('rtl', 'rtl'), $b('a11y', 'a11y_basic'), $b('greeting', 'text', ['contains' => 'مرحبا']), $b('responsive', 'responsive')], null],
    'vt-js' => ['solution.js', 'js', 'public', [], $jsTests],
    'vt-sandbox' => ['sandbox.html', 'html', 'public', [$b('loads', 'loads')], null],
    'vt-forge' => ['forge.html', 'html', 'public', [$b('nonblank', 'nonblank')], null],
    'vt-holdout' => ['good.html', 'html', 'holdout', [$b('nonblank', 'nonblank')], null],
    'vt-noout' => [null, 'html', 'public', [$b('loads', 'loads'), $b('nonblank', 'nonblank')], null],
    'vt-answer' => [null, 'answer', 'public', [], null],
];

$trackId = (int) (DB::val("SELECT id FROM tracks WHERE slug = 'vt-track'") ?: DB::insert('tracks', [
    'slug' => 'vt-track', 'name_en' => 'Verifier tests', 'name_ar' => 'اختبارات المتحقق', 'icon' => 'shield', 'color' => '#7F8CF7', 'sort' => 99, 'auto_weight' => 0.5, 'is_active' => 0,
]));
$modelId = (int) (DB::val("SELECT id FROM models WHERE slug = 'vt-probe-model'") ?: DB::insert('models', [
    'slug' => 'vt-probe-model', 'or_id' => 'vtlabs/vt-probe-model', 'name' => 'Probe Test Model', 'provider' => 'vtlabs', 'provider_name' => 'VT Labs (fictional)', 'is_active' => 0,
]));
DB::exec("DELETE FROM campaigns WHERE name = 'Verifier test campaign'");
DB::exec("DELETE FROM tasks WHERE slug LIKE 'vt-%'");

$season = (string) setting('season');
$out = ['base' => $base, 'queue' => $queue, 'runs' => []];
$taskIds = [];
foreach ($defs as $slug => [$file, $type, $vis, $browser, $tests]) {
    $checks = ['browser' => $browser];
    if ($tests !== null) {
        $checks['tests'] = $tests;
    }
    $taskIds[$slug] = DB::insert('tasks', [
        'slug' => $slug, 'track_id' => $trackId, 'title_en' => 'VT ' . $slug, 'title_ar' => 'اختبار ' . $slug,
        'prompt' => 'Verifier test fixture.', 'output_type' => $type, 'difficulty' => 1,
        'rubric_json' => to_json([]), 'checks_json' => to_json($checks), 'auto_weight' => 1, 'visibility' => $vis,
        'is_active' => 1, 'season' => $season, 'source' => 'test',
    ]);
}
$campaignId = DB::insert('campaigns', [
    'name' => 'Verifier test campaign', 'season' => $season, 'status' => 'running', 'runs_per_task' => 1,
    'model_ids' => to_json([$modelId]), 'task_ids' => to_json(array_values($taskIds)), 'include_in_scores' => 0, 'started_at' => now(),
]);
foreach ($defs as $slug => [$file, $type]) {
    $code = $file !== null ? $read($file) : null;
    $raw = $file !== null ? "```\n" . $code . "\n```" : ($type === 'answer' ? "Reasoning…\n<answer>42</answer>\n<script>alert(1)</script>" : 'I cannot produce this page.');
    $lock = $queue ? null : random_token(16);
    $runId = DB::insert('runs', [
        'campaign_id' => $campaignId, 'model_id' => $modelId, 'task_id' => $taskIds[$slug], 'attempt' => 1,
        'status' => $queue ? 'awaiting_verify' : 'verifying', 'lock_token' => $lock, 'locked_until' => $queue ? null : now(3600),
        'raw_output' => $raw, 'code' => $code, 'code_bytes' => $code !== null ? strlen($code) : 0,
    ]);
    $row = ['id' => $runId, 'task_id' => $taskIds[$slug], 'url' => $base . '/render/' . $runId];
    if ($lock !== null) {
        $row['vt'] = wb_render_vt($runId, $lock);
        $row['lock_token'] = $lock;
        $row['verify_url'] = $base . '/render/' . $runId . '?verify=1&vt=' . rawurlencode($row['vt']);
    }
    $out['runs'][$slug] = $row;
}

// Admin + verifier API token for the end-to-end phases.
$email = 'probe-admin@example.test';
$password = 'Verifier-Test-2026!';
DB::exec('DELETE FROM admins WHERE email = ?', [$email]);
DB::insert('admins', ['email' => $email, 'name' => 'Probe Admin', 'pass_hash' => password_hash($password, PASSWORD_DEFAULT), 'role' => 'owner']);
DB::exec("DELETE FROM rate_limits WHERE k LIKE 'admin_login%'");
DB::exec("DELETE FROM api_tokens WHERE name = 'verifier-test'");
$token = random_token(24);
DB::insert('api_tokens', ['name' => 'verifier-test', 'token_hash' => hash('sha256', $token), 'scope' => 'verifier']);
Settings::set('verify_mode', 'required');
$out['admin'] = ['email' => $email, 'password' => $password];
$out['token'] = $token;
echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
