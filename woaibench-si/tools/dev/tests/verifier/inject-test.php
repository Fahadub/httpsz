<?php
/** DEV ONLY — unit tests for app/render/render.php (head injection + verification tokens). php inject-test.php */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
define('WB', true);
require dirname(__DIR__, 4) . '/app/bootstrap.php';
require_once WB_APP . '/render/render.php';

$fails = 0;
$ok = static function (string $name, bool $cond, string $info = '') use (&$fails): void {
    echo ($cond ? 'PASS ' : 'FAIL ') . $name . ($cond || $info === '' ? '' : ' — ' . $info) . "\n";
    $fails += $cond ? 0 : 1;
};
$X = '<script>X</script>';
$cases = [
    'full document' => ['<!doctype html><html lang="en"><head><meta charset="utf-8"></head><body>hi</body></html>', '<!doctype html><html lang="en"><head>' . $X . '<meta charset="utf-8">'],
    'BOM + comment + whitespace' => ["\xEF\xBB\xBF<!-- generated -->\n<!DOCTYPE html>\n<html>\n<head>\n<title>t</title>", "<!-- generated -->\n<!DOCTYPE html>\n<html>\n<head>" . $X . "\n<title>t</title>"],
    'no head' => ['<!doctype html><html lang="ar" dir="rtl"><body>x</body></html>', '<!doctype html><html lang="ar" dir="rtl">' . $X . '<body>'],
    'no html, no head' => ['<div>fragment</div>', $X . '<div>fragment</div>'],
    'header is not head' => ['<!doctype html><header>nav</header>', '<!doctype html>' . $X . '<header>nav</header>'],
    'quoted > in attribute' => ['<html data-x="a>b"><head data-y=\'1>2\'><title>q</title>', '<html data-x="a>b"><head data-y=\'1>2\'>' . $X . '<title>q</title>'],
    'uppercase tags' => ['<HTML><HEAD><TITLE>u</TITLE>', '<HTML><HEAD>' . $X . '<TITLE>u</TITLE>'],
    'text before html' => ["Here you go:\n<html><head></head></html>", $X . "Here you go:\n<html><head></head></html>"],
    'unterminated comment' => ['<!-- oops <html><head>', $X . '<!-- oops <html><head>'],
];
foreach ($cases as $name => [$in, $prefix]) {
    $out = wb_render_inject_head($in, $X);
    $ok('inject: ' . $name, str_starts_with($out, $prefix), json_encode(substr($out, 0, 120)));
}
$ok('inject: empty inject is identity', wb_render_inject_head('<p>x</p>', '') === '<p>x</p>');

// Verification tokens (needs a run row: use a temporary one inside a rolled-back transaction).
$pdo = DB::pdo();
$pdo->beginTransaction();
try {
    $runId = (int) DB::val("SELECT id FROM runs ORDER BY id LIMIT 1");
    if ($runId === 0) {
        throw new RuntimeException('no runs in the database — run tools/dev/fixtures.php first');
    }
    $lock = random_token(16);
    DB::update('runs', ['status' => 'verifying', 'lock_token' => $lock], 'id = ?', [$runId]);
    $vt = wb_render_vt($runId, $lock);
    $ok('vt: valid token accepted', wb_render_vt_valid($runId, $vt));
    $ok('vt: format <expiry>.<43 base64url>', (bool) preg_match('/^\d{10}\.[A-Za-z0-9_-]{43}$/', $vt), $vt);
    $ok('vt: other run rejected', !wb_render_vt_valid($runId + 1, $vt));
    $ok('vt: tampered signature rejected', !wb_render_vt_valid($runId, substr($vt, 0, -2) . (str_ends_with($vt, 'AA') ? 'BB' : 'AA')));
    [$exp, $sig] = explode('.', $vt);
    $ok('vt: changed expiry rejected', !wb_render_vt_valid($runId, ((int) $exp + 60) . '.' . $sig));
    $past = time() - 5;
    $ok('vt: expired token rejected', !wb_render_vt_valid($runId, $past . '.' . wb_render_vt_sig($runId, $lock, $past)));
    $far = time() + 86400;
    $ok('vt: far-future expiry rejected', !wb_render_vt_valid($runId, $far . '.' . wb_render_vt_sig($runId, $lock, $far)));
    $ok('vt: garbage rejected', !wb_render_vt_valid($runId, 'abc') && !wb_render_vt_valid($runId, ''));
    DB::update('runs', ['lock_token' => random_token(16)], 'id = ?', [$runId]);
    $ok('vt: new lease invalidates old token', !wb_render_vt_valid($runId, $vt));
    DB::update('runs', ['status' => 'judging', 'lock_token' => $lock], 'id = ?', [$runId]);
    $ok('vt: run no longer verifying → rejected', !wb_render_vt_valid($runId, $vt));
} finally {
    $pdo->rollBack();
}
$ok('generated: queued run is not renderable', !wb_render_is_generated(['status' => 'queued', 'code' => 'x']));
$ok('generated: scored run without output is not renderable', !wb_render_is_generated(['status' => 'scored', 'code' => null, 'raw_output' => null]));
$ok('generated: raw output only is renderable', wb_render_is_generated(['status' => 'scored', 'code' => null, 'raw_output' => 'text']));
echo $fails === 0 ? "ALL PASS\n" : "{$fails} FAILED\n";
exit($fails === 0 ? 0 : 1);
