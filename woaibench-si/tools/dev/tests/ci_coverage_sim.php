<?php
/**
 * DEV ONLY — Monte-Carlo check of the "95%" confidence intervals written by WB\Eval\Aggregator.
 *
 *   php tools/dev/tests/ci_coverage_sim.php [--reps=600] [--iters=1000] [--quick]
 *
 * Every replicate draws a fresh benchmark for one model from a known truth — per track a true mean μ_t, task
 * means μ_t + N(0, task_sd), attempts task mean + N(0, attempt_sd) — runs it through Aggregator::compute()
 * (the production code path: stratified bootstrap with the small-sample correction, percentile interval,
 * point containment) and counts how often the overall and per-track intervals contain the truth.
 * Gated designs mirror the site: 12 tracks × 5 tasks × 3 attempts (seeded layout) plus smaller per-track samples,
 * 2 attempts, noisier attempts and 10 tasks per track; a skewed (non-normal) task distribution is reported as a
 * stress test. Exit code 1 when a gated coverage (overall or per track) is below 94%.
 * Measured (2000 replicates, 1000 iterations): overall 94.6–97.8%, per track 95.2–99.0%; skewed stress test
 * overall 95.2%, per track 92.0%. Before the small-sample correction: about 92% overall and 81–84% per track.
 * Deterministic: a private generator with fixed seeds (Aggregator reseeds mt_rand itself). No database needed.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
define('WB', true);
require dirname(__DIR__, 3) . '/app/bootstrap.php';

use WB\Eval\Aggregator;

$opts = getopt('', ['reps::', 'iters::', 'quick']);
$reps = max(50, (int) ($opts['reps'] ?? (isset($opts['quick']) ? 200 : 600)));
$iters = max(200, (int) ($opts['iters'] ?? 1000));
$target = 0.94;

/** Small deterministic generator (xorshift32), independent of mt_rand. */
final class Rng
{
    private int $s;
    private ?float $spare = null;

    public function __construct(int $seed)
    {
        $this->s = ($seed * 2654435761) & 0xFFFFFFFF ?: 2463534242;
    }

    public function uniform(): float
    {
        $x = $this->s;
        $x ^= ($x << 13) & 0xFFFFFFFF;
        $x ^= $x >> 17;
        $x ^= ($x << 5) & 0xFFFFFFFF;
        $this->s = $x & 0xFFFFFFFF;
        return ($this->s + 0.5) / 4294967296.0;
    }

    public function normal(float $mu = 0.0, float $sd = 1.0): float
    {
        if ($this->spare !== null) {
            $z = $this->spare;
            $this->spare = null;
            return $mu + $sd * $z;
        }
        $u = max(1e-12, $this->uniform());
        $v = $this->uniform();
        $r = sqrt(-2 * log($u));
        $this->spare = $r * sin(2 * M_PI * $v);
        return $mu + $sd * $r * cos(2 * M_PI * $v);
    }
}

/**
 * One design → ['overall' => coverage, 'track' => coverage, 'w_overall' => mean half-width, 'w_track' => …].
 * $taskMean(Rng, μ_t) draws one task's true mean; $truth(μ_t) is the population mean of that distribution.
 */
function simulate(string $label, int $tracks, int $tasks, int $attempts, float $attemptSd, callable $taskMean, callable $truth, int $reps, int $iters, int $seed): array
{
    $rng = new Rng($seed);
    $mu = [];
    for ($t = 1; $t <= $tracks; $t++) {
        $mu[$t] = 35 + 30 * ($t - 1) / max(1, $tracks - 1); // 35 … 65
    }
    $trueOverall = array_sum(array_map($truth, $mu)) / $tracks;
    $hitO = $hitT = $nT = 0;
    $wO = $wT = 0.0;
    $active = array_fill_keys(array_keys($mu), $tasks);
    for ($r = 0; $r < $reps; $r++) {
        $data = [];
        foreach ($mu as $t => $m) {
            for ($k = 0; $k < $tasks; $k++) {
                $tm = $taskMean($rng, $m);
                for ($a = 0; $a < $attempts; $a++) {
                    $data[1][$t][$t * 100 + $k][] = $tm + $rng->normal(0, $attemptSd);
                }
            }
        }
        $res = Aggregator::compute($data, $active, $iters, 0.8)[1];
        $o = $res['overall'];
        $hitO += ($o['ci_low'] <= $trueOverall && $trueOverall <= $o['ci_high']) ? 1 : 0;
        $wO += ($o['ci_high'] - $o['ci_low']) / 2;
        foreach ($res['tracks'] as $t => $s) {
            $tv = $truth($mu[$t]);
            $hitT += ($s['ci_low'] <= $tv && $tv <= $s['ci_high']) ? 1 : 0;
            $wT += ($s['ci_high'] - $s['ci_low']) / 2;
            $nT++;
        }
    }
    $out = ['label' => $label, 'overall' => $hitO / $reps, 'track' => $hitT / max(1, $nT), 'w_overall' => $wO / $reps, 'w_track' => $wT / max(1, $nT)];
    printf("  %-44s overall %5.1f%% (±%4.2f)   per track %5.1f%% (±%5.2f)\n", $label, 100 * $out['overall'], $out['w_overall'], 100 * $out['track'], $out['w_track']);
    return $out;
}

$normal = static fn(float $sd): callable => static fn(Rng $g, float $m): float => $g->normal($m, $sd);
$identity = static fn(float $m): float => $m;
// Skewed task difficulty: μ_t·(0.4 + 1.2·U²)/0.8 with U uniform — right-skewed, mean exactly μ_t (E[U²] = 1/3).
$skewed = static fn(Rng $g, float $m): float => $m * (0.4 + 1.2 * $g->uniform() ** 2) / 0.8;

echo "CI coverage simulation — {$reps} replicates × {$iters} bootstrap iterations per design (target ≥ " . (100 * $target) . "%)\n";
$t0 = microtime(true);
$results = [
    simulate('12 tracks × 5 tasks × 3 attempts (seeded)', 12, 5, 3, 5.0, $normal(15.0), $identity, $reps, $iters, 11),
    simulate('12 tracks × 5 tasks × 2 attempts', 12, 5, 2, 5.0, $normal(15.0), $identity, $reps, $iters, 12),
    simulate('12 tracks × 3 tasks × 3 attempts', 12, 3, 3, 5.0, $normal(15.0), $identity, $reps, $iters, 13),
    simulate('12 tracks × 4 tasks × 3 attempts, noisy', 12, 4, 3, 12.0, $normal(10.0), $identity, $reps, $iters, 14),
    simulate('12 tracks × 10 tasks × 3 attempts', 12, 10, 3, 5.0, $normal(15.0), $identity, max(50, intdiv($reps, 2)), $iters, 15),
];
echo "Stress test (not gated: with 5 strongly skewed task scores per track any t-type interval under-covers a little):\n";
simulate('12 tracks × 5 tasks × 3 attempts, skewed', 12, 5, 3, 5.0, $skewed, $identity, $reps, $iters, 16);
$bad = array_filter($results, static fn(array $r): bool => $r['overall'] < $target || $r['track'] < $target);
printf("%s in %.0f s\n", $bad ? count($bad) . ' design(s) below target' : 'all designs ≥ ' . (100 * $target) . '% coverage', microtime(true) - $t0);
exit($bad ? 1 : 0);
