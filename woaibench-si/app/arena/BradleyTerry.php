<?php
/**
 * Bradley–Terry ratings for the Arena (Hunter 2004 MM algorithm).
 *
 * Votes are pairwise outcomes: ['a' => modelId, 'b' => modelId, 'winner' => 'a'|'b'|'tie'|'both_bad', 'n' => count].
 * Ties (and "both bad") count as half a win for each side. Every model also plays `$prior` virtual
 * tie games against a fixed reference player of strength 1: this keeps strengths finite for models
 * that only won or only lost and puts disconnected comparison graphs on one common scale.
 * Strengths are normalised to a geometric mean of 1 and shown on an Elo-like scale
 * rating = 1000 + 400 · log10(strength).
 */
declare(strict_types=1);

namespace WB\Arena;

defined('WB') || exit;

final class BradleyTerry
{
    public const PRIOR = 1.0;
    public const SEED = 1337;
    private const TOL = 1e-10;
    /** Above this many votes the bootstrap uses the Poisson approximation (O(cells) per resample). */
    private const EXACT_RESAMPLE_MAX = 5000;

    /**
     * Fit strengths (geometric mean = 1). Returns [modelId => strength].
     * $start optionally warm-starts the iteration with previous strengths.
     */
    public static function fit(array $votes, int $iters = 200, float $prior = self::PRIOR, array $start = []): array
    {
        [$wins, $games] = self::tally($votes);
        if (!$wins) {
            return [];
        }
        $ids = array_keys($wins);
        $p = [];
        foreach ($ids as $id) {
            $p[$id] = isset($start[$id]) && $start[$id] > 0 ? (float) $start[$id] : 1.0;
        }
        $prior = max(1e-6, $prior);
        for ($it = 0; $it < max(1, $iters); $it++) {
            $next = [];
            $delta = 0.0;
            foreach ($ids as $i) {
                $pi = $p[$i];
                $den = $prior / ($pi + 1.0);
                foreach ($games[$i] as $j => $nij) {
                    $den += $nij / ($pi + $p[$j]);
                }
                $v = ($wins[$i] + $prior / 2) / $den;
                $next[$i] = $v;
                $delta = max($delta, abs(log($v) - log($pi)));
            }
            $p = $next;
            if ($delta < self::TOL) {
                break;
            }
        }
        return self::normalise($p);
    }

    /** Elo-like rating for each strength: 1000 + 400·log10(strength / geometric mean). */
    public static function toElo(array $strengths): array
    {
        $strengths = self::normalise($strengths);
        $out = [];
        foreach ($strengths as $id => $s) {
            $out[$id] = 1000.0 + 400.0 * log10($s);
        }
        return $out;
    }

    /**
     * Ratings with bootstrap 95% CIs.
     * Returns [modelId => ['rating' => float, 'ci_low' => float, 'ci_high' => float]].
     */
    public static function ratings(array $votes, int $resamples = 200, int $iters = 200, float $prior = self::PRIOR, int $seed = self::SEED): array
    {
        $cells = self::cells($votes);
        $strength = self::fit($cells, $iters, $prior);
        if (!$strength) {
            return [];
        }
        $point = self::toElo($strength);
        $samples = array_fill_keys(array_keys($point), []);
        foreach (self::bootstrap($cells, $resamples, $seed) as $sample) {
            $elo = self::toElo(self::fit($sample, $iters, $prior, $strength));
            foreach ($point as $id => $_) {
                // A model absent from a resample has no information there: it sits at the prior (1000).
                $samples[$id][] = $elo[$id] ?? 1000.0;
            }
        }
        mt_srand(random_int(0, 2147483647)); // do not leave the global generator in a predictable state
        $out = [];
        foreach ($point as $id => $r) {
            $lo = $samples[$id] ? \percentile($samples[$id], 2.5) : $r;
            $hi = $samples[$id] ? \percentile($samples[$id], 97.5) : $r;
            $out[$id] = ['rating' => $r, 'ci_low' => min($lo, $r), 'ci_high' => max($hi, $r)];
        }
        return $out;
    }

    /** Expected probability that A beats B given two Elo-like ratings. */
    public static function expected(float $ratingA, float $ratingB): float
    {
        return 1.0 / (1.0 + 10 ** (($ratingB - $ratingA) / 400.0));
    }

    /**
     * Collapse votes into unique (a, b, winner) cells with counts — the unit the bootstrap resamples.
     * Self-comparisons and unknown winners are dropped.
     */
    public static function cells(array $votes): array
    {
        $cells = [];
        foreach ($votes as $v) {
            $a = (int) ($v['a'] ?? $v['model_a'] ?? 0);
            $b = (int) ($v['b'] ?? $v['model_b'] ?? 0);
            $w = (string) ($v['winner'] ?? '');
            $n = (float) ($v['n'] ?? 1);
            if ($a <= 0 || $b <= 0 || $a === $b || $n <= 0 || !in_array($w, ['a', 'b', 'tie', 'both_bad'], true)) {
                continue;
            }
            if ($w === 'both_bad') {
                $w = 'tie';
            }
            $key = $a . ':' . $b . ':' . $w;
            if (!isset($cells[$key])) {
                $cells[$key] = ['a' => $a, 'b' => $b, 'winner' => $w, 'n' => 0.0];
            }
            $cells[$key]['n'] += $n;
        }
        return array_values($cells);
    }

    /**
     * Generate bootstrap resamples of the vote cells (deterministic: seeded mt_rand).
     * Small data: exact multinomial resampling of individual votes. Large data: Poisson bootstrap.
     */
    public static function bootstrap(array $cells, int $resamples, int $seed = self::SEED): \Generator
    {
        $total = 0.0;
        foreach ($cells as $c) {
            $total += $c['n'];
        }
        if ($total <= 0 || $resamples <= 0) {
            return;
        }
        mt_srand($seed);
        $exact = $total <= self::EXACT_RESAMPLE_MAX;
        $cum = [];
        $acc = 0.0;
        foreach ($cells as $c) {
            $acc += $c['n'];
            $cum[] = $acc;
        }
        $draws = (int) round($total);
        $k = count($cells);
        for ($r = 0; $r < $resamples; $r++) {
            $counts = array_fill(0, $k, 0);
            if ($exact) {
                for ($d = 0; $d < $draws; $d++) {
                    $counts[self::search($cum, (mt_rand() / (mt_getrandmax() + 1)) * $acc)]++;
                }
            } else {
                foreach ($cells as $idx => $c) {
                    $counts[$idx] = self::poisson($c['n']);
                }
            }
            $sample = [];
            foreach ($cells as $idx => $c) {
                if ($counts[$idx] > 0) {
                    $sample[] = ['a' => $c['a'], 'b' => $c['b'], 'winner' => $c['winner'], 'n' => $counts[$idx]];
                }
            }
            yield $sample;
        }
    }

    /** Wins (ties = ½) per model and game counts per pair. */
    private static function tally(array $votes): array
    {
        $wins = [];
        $games = [];
        foreach (self::cells($votes) as $c) {
            [$a, $b, $n] = [$c['a'], $c['b'], $c['n']];
            $wins[$a] = ($wins[$a] ?? 0.0) + ($c['winner'] === 'a' ? $n : ($c['winner'] === 'tie' ? $n / 2 : 0.0));
            $wins[$b] = ($wins[$b] ?? 0.0) + ($c['winner'] === 'b' ? $n : ($c['winner'] === 'tie' ? $n / 2 : 0.0));
            $games[$a][$b] = ($games[$a][$b] ?? 0.0) + $n;
            $games[$b][$a] = ($games[$b][$a] ?? 0.0) + $n;
        }
        ksort($wins);
        return [$wins, $games];
    }

    /** Divide by the geometric mean so the average model sits at 1 (rating 1000). */
    private static function normalise(array $p): array
    {
        if (!$p) {
            return [];
        }
        $logSum = 0.0;
        foreach ($p as $v) {
            $logSum += log(max($v, 1e-300));
        }
        $gm = exp($logSum / count($p));
        foreach ($p as $id => $v) {
            $p[$id] = max($v, 1e-300) / $gm;
        }
        return $p;
    }

    /** Index of the first cumulative weight greater than $x. */
    private static function search(array $cum, float $x): int
    {
        $lo = 0;
        $hi = count($cum) - 1;
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($cum[$mid] > $x) {
                $hi = $mid;
            } else {
                $lo = $mid + 1;
            }
        }
        return $lo;
    }

    /** Poisson(λ) draw: Knuth for small λ, rounded normal approximation for large λ. */
    private static function poisson(float $lambda): int
    {
        if ($lambda > 30) {
            $u1 = max(1e-12, mt_rand() / mt_getrandmax());
            $u2 = mt_rand() / mt_getrandmax();
            $z = sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);
            return max(0, (int) round($lambda + sqrt($lambda) * $z));
        }
        $l = exp(-$lambda);
        $k = 0;
        $p = 1.0;
        do {
            $k++;
            $p *= mt_rand() / mt_getrandmax();
        } while ($p > $l);
        return $k - 1;
    }
}
