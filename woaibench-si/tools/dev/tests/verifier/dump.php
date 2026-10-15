<?php
/** DEV ONLY — prints the verifier test runs (status, verifier output, screenshots) as JSON. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
define('WB', true);
require dirname(__DIR__, 4) . '/app/bootstrap.php';

$rows = DB::all(
    "SELECT t.slug, r.id, r.status, r.verified, r.verified_by, r.verify_tries, r.browser_json, r.screenshot, r.screenshot_mobile, r.error
       FROM runs r JOIN tasks t ON t.id = r.task_id
      WHERE t.slug LIKE 'vt-%' ORDER BY r.id"
);
$out = [];
foreach ($rows as $r) {
    $r['browser'] = json_arr($r['browser_json']);
    unset($r['browser_json']);
    foreach (['screenshot', 'screenshot_mobile'] as $k) {
        $r[$k . '_bytes'] = $r[$k] ? (int) @filesize(WB_UPLOADS . '/' . $r[$k]) : 0;
    }
    $out[$r['slug']] = $r;
}
echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
