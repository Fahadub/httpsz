<?php
/**
 * DEV ONLY — tests for the Arena module (Bradley–Terry maths, and optionally the live HTTP flow).
 *
 *   php tools/dev/tests/arena_test.php                                  unit tests (no database needed)
 *   WB_DB_NAME=wb_arena php tools/dev/tests/arena_test.php --http=http://127.0.0.1:8087
 *                                                                        + vote flow / API / badge / embed / sitemap
 * The HTTP part writes votes into the given local database; never point it at production.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
define('WB', true);
require dirname(__DIR__, 3) . '/app/bootstrap.php';

use WB\Arena\BradleyTerry as BT;

$fails = 0;
$passes = 0;
function check(bool $cond, string $name, string $detail = ''): void
{
    global $fails, $passes;
    if ($cond) {
        $passes++;
        echo "  ok   {$name}\n";
    } else {
        $fails++;
        echo "  FAIL {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}
function near(float $a, float $b, float $tol): bool
{
    return abs($a - $b) <= $tol;
}
function votes(int $a, int $b, string $w, int $n): array
{
    return ['a' => $a, 'b' => $b, 'winner' => $w, 'n' => $n];
}

echo "Bradley–Terry\n";

// 1. Two players, 3:1 → strength ratio 3 (prior ≈ 0 for an exact check).
$s = BT::fit([votes(1, 2, 'a', 3), votes(1, 2, 'b', 1)], 500, 1e-9);
check(near($s[1] / $s[2], 3.0, 1e-4), 'two players 3:1 → ratio 3', (string) ($s[1] / $s[2]));
$elo = BT::toElo($s);
check(near($elo[1] - $elo[2], 400 * log10(3), 0.05), 'Elo gap = 400·log10(3) ≈ 190.8', (string) ($elo[1] - $elo[2]));
check(near(($elo[1] + $elo[2]) / 2, 1000, 1e-6), 'Elo centred on 1000 (geometric mean)');

// 2. Default prior shrinks toward 1000 but keeps the order.
$s = BT::fit([votes(1, 2, 'a', 3), votes(1, 2, 'b', 1)]);
check($s[1] > $s[2] && $s[1] / $s[2] < 3.0, 'prior shrinks a small sample', (string) ($s[1] / $s[2]));

// 3. Perfect cycle A>B>C>A → all equal.
$r = BT::toElo(BT::fit([votes(1, 2, 'a', 5), votes(2, 3, 'a', 5), votes(3, 1, 'a', 5)]));
check(near($r[1], 1000, 1e-6) && near($r[2], 1000, 1e-6) && near($r[3], 1000, 1e-6), 'cycle → equal ratings', json_encode($r));

// 4. Ties are half wins; both_bad is a tie.
$t1 = BT::fit([votes(1, 2, 'a', 2), votes(1, 2, 'tie', 2)], 500, 1e-9);
check(near($t1[1] / $t1[2], 3.0, 1e-4), 'tie = ½ win each (2 wins + 2 ties → 3:1)', (string) ($t1[1] / $t1[2]));
$t2 = BT::fit([votes(1, 2, 'a', 2), votes(1, 2, 'both_bad', 2)], 500, 1e-9);
check(near($t1[1], $t2[1], 1e-9), 'both_bad counts exactly like a tie');
$t3 = BT::toElo(BT::fit([votes(1, 2, 'tie', 7)]));
check(near($t3[1], $t3[2], 1e-9), 'only ties → equal ratings');

// 5. Only wins / only losses stay finite.
$w = BT::toElo(BT::fit([votes(1, 2, 'a', 25)]));
check(is_finite($w[1]) && is_finite($w[2]) && $w[1] > $w[2], 'undefeated model stays finite', json_encode($w));
check($w[1] - $w[2] < 1200, 'undefeated gap bounded by the prior', (string) ($w[1] - $w[2]));

// 6. Disconnected components share one scale (anchored by the prior) and stay symmetric.
$d = BT::toElo(BT::fit([votes(1, 2, 'a', 10), votes(3, 4, 'a', 10)]));
check(near($d[1], $d[3], 1e-6) && near($d[2], $d[4], 1e-6), 'disconnected graph → symmetric components', json_encode($d));
check($d[1] > 1000 && $d[2] < 1000, 'disconnected graph → winners above, losers below 1000');

// 7. Garbage is ignored: self-pairs, bad winners, zero counts.
$g = BT::fit([votes(1, 1, 'a', 9), votes(1, 2, 'x', 3), votes(1, 2, 'a', 0), votes(1, 2, 'a', 1), votes(1, 2, 'b', 1)]);
check(count($g) === 2 && near($g[1], $g[2], 1e-9), 'self-pairs / invalid winners / n=0 ignored', json_encode($g));
check(BT::fit([]) === [] && BT::ratings([]) === [], 'empty input → empty output');
$dbRows = BT::cells([['model_a' => 5, 'model_b' => 6, 'winner' => 'a', 'n' => '4']]);
check(count($dbRows) === 1 && $dbRows[0]['a'] === 5 && $dbRows[0]['n'] === 4.0, 'accepts DB row shape (model_a/model_b/n)');

// 8. Determinism of bootstrap CIs.
$sample = [votes(1, 2, 'a', 12), votes(1, 2, 'b', 7), votes(2, 3, 'a', 9), votes(2, 3, 'tie', 3), votes(3, 1, 'b', 4), votes(1, 3, 'both_bad', 2)];
$r1 = BT::ratings($sample);
$r2 = BT::ratings($sample);
check($r1 === $r2, 'bootstrap is deterministic (fixed seed)');
$ok = true;
foreach ($r1 as $row) {
    $ok = $ok && $row['ci_low'] <= $row['rating'] && $row['rating'] <= $row['ci_high'] && $row['ci_high'] - $row['ci_low'] > 1;
}
check($ok, 'CI brackets the point estimate and has width', json_encode($r1));
mt_srand(BT::SEED);
$seededFirst = mt_rand();
BT::ratings($sample);
check(mt_rand() !== $seededFirst, 'global RNG is re-seeded after the bootstrap (not left predictable)');

// 9. Recovers known strengths from simulated votes (exact bootstrap path, < 5000 votes).
mt_srand(42);
$true = [1 => 1250.0, 2 => 1120.0, 3 => 1040.0, 4 => 1000.0, 5 => 930.0, 6 => 760.0];
$gm = array_sum($true) / count($true);
$sim = [];
$ids = array_keys($true);
for ($i = 0; $i < 4800; $i++) {
    $a = $ids[mt_rand(0, 5)];
    do {
        $b = $ids[mt_rand(0, 5)];
    } while ($b === $a);
    $pa = BT::expected($true[$a], $true[$b]);
    $u = mt_rand() / mt_getrandmax();
    $sim[] = ['a' => $a, 'b' => $b, 'winner' => $u < $pa ? 'a' : 'b'];
}
$t0 = microtime(true);
$fit = BT::ratings($sim);
$ms = (microtime(true) - $t0) * 1000;
$maxErr = 0.0;
$covered = 0;
foreach ($true as $id => $tr) {
    $target = $tr - $gm + 1000;
    $maxErr = max($maxErr, abs($fit[$id]['rating'] - $target));
    $covered += ($fit[$id]['ci_low'] <= $target && $target <= $fit[$id]['ci_high']) ? 1 : 0;
}
check($maxErr < 30, 'recovers true ratings within 30 points (4.8k votes)', sprintf('max error %.1f', $maxErr));
check($covered >= 5, 'bootstrap CIs cover the truth for ≥ 5/6 models', "{$covered}/6");
$order = array_keys($fit);
usort($order, static fn($x, $y) => $fit[$y]['rating'] <=> $fit[$x]['rating']);
check($order === [1, 2, 3, 4, 5, 6], 'recovers the true order');
echo sprintf("       (exact bootstrap, 200 resamples, %d votes: %.0f ms)\n", count($sim), $ms);

// 9b. Random ties (counted as ½ win) compress the scale but never reorder it.
mt_srand(43);
$tied = [];
foreach ($sim as $v) {
    $tied[] = mt_rand(1, 5) === 1 ? ['a' => $v['a'], 'b' => $v['b'], 'winner' => mt_rand(0, 1) ? 'tie' : 'both_bad'] : $v;
}
$fitT = BT::ratings($tied);
$orderT = array_keys($fitT);
usort($orderT, static fn($x, $y) => $fitT[$y]['rating'] <=> $fitT[$x]['rating']);
check($orderT === [1, 2, 3, 4, 5, 6] && $fitT[1]['rating'] - $fitT[6]['rating'] < $fit[1]['rating'] - $fit[6]['rating'], 'ties compress the spread but keep the order');

// 10. Large data uses the Poisson path and narrows the CI.
$big = [];
foreach (BT::cells($sim) as $c) {
    $c['n'] *= 20;
    $big[] = $c;
}
$t0 = microtime(true);
$fitBig = BT::ratings($big);
$ms = (microtime(true) - $t0) * 1000;
$wSmall = $fit[3]['ci_high'] - $fit[3]['ci_low'];
$wBig = $fitBig[3]['ci_high'] - $fitBig[3]['ci_low'];
check($wBig < $wSmall / 3, 'Poisson bootstrap: 20× votes → CI ≈ √20 narrower', sprintf('%.1f vs %.1f', $wBig, $wSmall));
check(BT::ratings($big) === $fitBig, 'Poisson bootstrap is deterministic too');
echo sprintf("       (Poisson bootstrap, %d votes: %.0f ms)\n", (int) array_sum(array_column($big, 'n')), $ms);

// 11. Spearman helper.
check(near((float) WB\Arena\Arena::spearman([[1, 10], [2, 20], [3, 30], [4, 40]]), 1.0, 1e-9), 'spearman perfect = 1');
check(near((float) WB\Arena\Arena::spearman([[1, 40], [2, 30], [3, 20], [4, 10]]), -1.0, 1e-9), 'spearman reversed = -1');
check(WB\Arena\Arena::spearman([[1, 1], [2, 2]]) === null, 'spearman needs ≥ 3 points');

/* ------------------------------------------------------------------ HTTP flow (optional) */

$http = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--http=')) {
        $http = rtrim(substr($arg, 7), '/');
    }
}
if ($http !== null) {
    require __DIR__ . '/arena_http.php';
    arena_http_tests($http);
}

echo "\n{$passes} passed, {$fails} failed\n";
exit($fails ? 1 : 0);
