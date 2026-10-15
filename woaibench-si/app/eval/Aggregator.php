<?php
/**
 * Season aggregation (ARCHITECTURE §5.3): task → track → overall means (equal weight per track),
 * stratified bootstrap confidence intervals, consistency, coverage / provisional, ranks.
 * Writes model_scores (scope overall + track:<slug>) and today's score_history rows.
 */
declare(strict_types=1);

namespace WB\Eval;

defined('WB') || exit;

use DB;
use Settings;

final class Aggregator
{
    public const SEED = 1337;
    private const Z975 = 1.959964;
    /** Student t 0.975 quantiles for 1–30 degrees of freedom (larger df: Cornish–Fisher expansion). */
    private const T975 = [
        1 => 12.706, 2 => 4.303, 3 => 3.182, 4 => 2.776, 5 => 2.571, 6 => 2.447, 7 => 2.365, 8 => 2.306, 9 => 2.262,
        10 => 2.228, 11 => 2.201, 12 => 2.179, 13 => 2.160, 14 => 2.145, 15 => 2.131, 16 => 2.120, 17 => 2.110,
        18 => 2.101, 19 => 2.093, 20 => 2.086, 21 => 2.080, 22 => 2.074, 23 => 2.069, 24 => 2.064, 25 => 2.060,
        26 => 2.056, 27 => 2.052, 28 => 2.048, 29 => 2.045, 30 => 2.042,
    ];
    private const COLS = [
        'model_id', 'season', 'scope', 'score', 'ci_low', 'ci_high', 'n_tasks', 'n_runs', 'auto_avg', 'judge_avg',
        'consistency', 'avg_cost', 'avg_latency_ms', 'avg_tokens', 'coverage', 'provisional', 'rank_pos', 'stat_rank',
    ];

    /** Recompute every score of a season (default: setting('season')). Returns a summary. */
    public static function recompute(?string $season = null): array
    {
        $t0 = microtime(true);
        $season = $season ?? (string) setting('season');
        // Cleared first: a run scored while we compute sets it again, so nothing is lost.
        Settings::set('aggregate_dirty', 0);
        $iters = (int) clamp((float) setting('bootstrap_iters'), 0, 20000);
        $minCov = (float) setting('min_coverage');

        $trackSlug = [];
        foreach (DB::all('SELECT id, slug FROM tracks WHERE is_active = 1 ORDER BY sort, id') as $t) {
            $trackSlug[(int) $t['id']] = (string) $t['slug'];
        }
        $active = [];
        foreach (DB::all('SELECT t.track_id, COUNT(*) AS n FROM tasks t JOIN tracks tr ON tr.id = t.track_id
                           WHERE t.is_active = 1 AND tr.is_active = 1 GROUP BY t.track_id') as $r) {
            $active[(int) $r['track_id']] = (int) $r['n'];
        }
        $rows = DB::all(
            "SELECT r.model_id, t.track_id, r.task_id, r.final_score, r.auto_score, r.judge_score, r.cost_usd,
                    r.latency_ms, r.prompt_tokens, r.completion_tokens
               FROM runs r
               JOIN campaigns c ON c.id = r.campaign_id
               JOIN tasks t ON t.id = r.task_id
               JOIN tracks tr ON tr.id = t.track_id
               JOIN models m ON m.id = r.model_id
              WHERE r.status = 'scored' AND r.final_score IS NOT NULL AND c.include_in_scores = 1 AND c.season = ?
                AND t.is_active = 1 AND tr.is_active = 1 AND m.is_active = 1
              ORDER BY r.model_id, t.track_id, r.task_id, r.attempt, r.id",
            [$season]
        );
        $data = [];
        foreach ($rows as $r) {
            $tok = $r['prompt_tokens'] === null && $r['completion_tokens'] === null ? null : (int) $r['prompt_tokens'] + (int) $r['completion_tokens'];
            $data[(int) $r['model_id']][(int) $r['track_id']][(int) $r['task_id']][] = [
                'final' => (float) $r['final_score'],
                'auto' => $r['auto_score'] !== null ? (float) $r['auto_score'] : null,
                'judge' => $r['judge_score'] !== null ? (float) $r['judge_score'] : null,
                'cost' => $r['cost_usd'] !== null ? (float) $r['cost_usd'] : null,
                'latency' => $r['latency_ms'] !== null ? (float) $r['latency_ms'] : null,
                'tokens' => $tok,
            ];
        }
        $results = self::compute($data, $active, $iters, $minCov);
        self::addFailed($results, $season);
        self::write($season, $results, $trackSlug);
        Settings::set('aggregate_last', now());
        return [
            'season' => $season,
            'models' => count($results),
            'provisional' => count(array_filter($results, static fn($r) => $r['overall']['provisional'])),
            'runs' => count($rows),
            'tasks_active' => array_sum($active),
            'tracks' => count($trackSlug),
            'iters' => $iters,
            'ms' => (int) round((microtime(true) - $t0) * 1000),
        ];
    }

    /**
     * Pure aggregation.
     * $data[modelId][trackId][taskId] = list of runs, each a float final score or
     *   ['final','auto'?,'judge'?,'cost'?,'latency'?,'tokens'?];
     * $activeByTrack[trackId] = number of active tasks.
     * Returns [modelId => ['overall' => stats, 'tracks' => [trackId => stats]]] incl. rank_pos and stat_rank.
     *
     * Provisional (not ranked): overall — coverage < min_coverage, or any active track missing or covered below
     * min_coverage (the overall score is an equal-weight mean of tracks, so a skipped track would silently change
     * what it measures); a track — the model's overall coverage or that track's coverage is below min_coverage.
     */
    public static function compute(array $data, array $activeByTrack, int $iters, float $minCoverage): array
    {
        $totalActive = array_sum($activeByTrack);
        $out = [];
        ksort($data);
        foreach ($data as $modelId => $tracks) {
            ksort($tracks);
            $values = [];
            $trackStats = [];
            $allRuns = [];
            $sds = [];
            $nTasks = 0;
            foreach ($tracks as $trackId => $tasks) {
                ksort($tasks);
                $finals = [];
                $trackRuns = [];
                $trackSds = [];
                foreach ($tasks as $runs) {
                    $runs = array_map(static fn($r) => is_array($r) ? $r : ['final' => (float) $r], array_values($runs));
                    $f = array_map(static fn($r) => (float) $r['final'], $runs);
                    $finals[] = $f;
                    array_push($trackRuns, ...$runs);
                    if (count($f) >= 2) {
                        $trackSds[] = stddev($f);
                    }
                }
                $values[$trackId] = $finals;
                $nTasks += count($finals);
                array_push($allRuns, ...$trackRuns);
                array_push($sds, ...$trackSds);
                $cov = !empty($activeByTrack[$trackId]) ? min(1.0, count($finals) / $activeByTrack[$trackId]) : 1.0;
                $trackStats[$trackId] = self::stats(
                    self::meanOfMeans($finals),
                    [$tasks],
                    $trackRuns,
                    count($finals),
                    $trackSds,
                    $cov
                );
            }
            $overallScore = array_sum(array_column($trackStats, 'score')) / max(1, count($trackStats));
            $coverage = $totalActive > 0 ? min(1.0, $nTasks / $totalActive) : 0.0;
            $overall = self::stats($overallScore, array_values($tracks), $allRuns, $nTasks, $sds, $coverage);
            $thin = $coverage < $minCoverage;
            $thinTrack = false;
            foreach ($activeByTrack as $tid => $n) {
                if ($n > 0 && (!isset($trackStats[$tid]) || $trackStats[$tid]['coverage'] < $minCoverage)) {
                    $thinTrack = true;
                }
            }
            $overall['provisional'] = $thin || $thinTrack;

            [$boot, $bootTracks] = $iters > 0 ? self::bootstrap($values, $iters) : [[], []];
            [$overall['ci_low'], $overall['ci_high']] = self::ci($boot, $overall['score']);
            foreach ($trackStats as $tid => &$ts) {
                [$ts['ci_low'], $ts['ci_high']] = self::ci($bootTracks[$tid] ?? [], $ts['score']);
                $ts['provisional'] = $thin || $ts['coverage'] < $minCoverage;
            }
            unset($ts);
            $out[$modelId] = ['overall' => $overall, 'tracks' => $trackStats];
        }
        mt_srand(); // never leave the global generator predictable
        self::rank($out);
        return $out;
    }

    /**
     * Stratified bootstrap: per iteration, resample tasks within each track, then attempts within each task.
     * $tracks[trackId] = list of attempt lists. Returns [overall samples, [trackId => samples]].
     * Deterministic (mt_srand(SEED)); attempt resampling of ≤ 4 attempts uses an exact lookup table of all
     * equally likely resample means (one random draw instead of n).
     *
     * Small-sample correction (a track has ~5 tasks): resampling n values shrinks the variance of their mean by
     * (n−1)/n, and a percentile interval ignores that the spread itself is estimated from n values. So every
     * resampled track mean is moved away from the track's point estimate by sqrt(n/(n−1)) (variance-unbiased),
     * track samples additionally by t(n−1)/z, and overall samples by t(df)/z with the Welch–Satterthwaite df of
     * the tracks' variances (≈ Σ(n−1)). Coverage is checked by tools/dev/tests/ci_coverage_sim.php.
     */
    public static function bootstrap(array $tracks, int $iters): array
    {
        mt_srand(self::SEED);
        $prep = [];
        $point = [];
        $scale = [];
        $kTrack = [];
        $welch = [0.0, 0.0];
        foreach ($tracks as $tid => $tasks) {
            $list = [];
            $means = [];
            foreach ($tasks as $vals) {
                $vals = array_values(array_map('floatval', $vals));
                $n = count($vals);
                if ($n === 0) {
                    continue;
                }
                $means[] = array_sum($vals) / $n;
                if ($n === 1 || max($vals) === min($vals)) {
                    $list[] = [0, $vals[0], 0];
                } elseif ($n <= 4) {
                    $table = self::resampleTable($vals);
                    $list[] = [1, $table, count($table) - 1];
                } else {
                    $list[] = [2, $vals, $n - 1];
                }
            }
            if (!$list) {
                continue;
            }
            $prep[$tid] = $list;
            $nt = count($means);
            $point[$tid] = array_sum($means) / $nt;
            $scale[$tid] = $nt >= 2 ? sqrt($nt / ($nt - 1)) : 1.0;
            $kTrack[$tid] = $scale[$tid] * ($nt >= 2 ? self::tQuantile($nt - 1) / self::Z975 : 1.0);
            if ($nt >= 2) {
                $ss = 0.0;
                foreach ($means as $m) {
                    $ss += ($m - $point[$tid]) ** 2;
                }
                $v = $ss / ($nt - 1) / $nt; // variance of the track mean
                $welch[0] += $v;
                $welch[1] += $v * $v / ($nt - 1);
            }
        }
        $overall = [];
        $per = array_fill_keys(array_keys($prep), []);
        $nTracks = count($prep);
        if ($nTracks === 0) {
            return [[], []];
        }
        $pOverall = array_sum($point) / $nTracks;
        $kOverall = $welch[1] > 0 ? self::tQuantile($welch[0] ** 2 / $welch[1]) / self::Z975 : 1.0;
        $sizes = array_map('count', $prep);
        for ($b = 0; $b < $iters; $b++) {
            $sumT = 0.0;
            foreach ($prep as $tid => $list) {
                $nt = $sizes[$tid];
                $max = $nt - 1;
                $s = 0.0;
                for ($k = 0; $k < $nt; $k++) {
                    $t = $list[$max > 0 ? mt_rand(0, $max) : 0];
                    if ($t[0] === 0) {
                        $s += $t[1];
                    } elseif ($t[0] === 1) {
                        $s += $t[1][mt_rand(0, $t[2])];
                    } else {
                        $v = $t[1];
                        $m = $t[2];
                        $a = 0.0;
                        for ($q = 0; $q <= $m; $q++) {
                            $a += $v[mt_rand(0, $m)];
                        }
                        $s += $a / ($m + 1);
                    }
                }
                $dev = $s / $nt - $point[$tid];
                $per[$tid][] = $point[$tid] + $kTrack[$tid] * $dev;
                $sumT += $point[$tid] + $scale[$tid] * $dev;
            }
            $overall[] = $pOverall + $kOverall * ($sumT / $nTracks - $pOverall);
        }
        return [$overall, $per];
    }

    /** Student t 0.975 quantile for $df degrees of freedom (table to 30, then the Cornish–Fisher expansion). */
    public static function tQuantile(float $df): float
    {
        if ($df < 1) {
            return self::T975[1];
        }
        if ($df <= 30) {
            $lo = (int) floor($df);
            $hi = (int) ceil($df);
            return self::T975[$lo] + (self::T975[$hi] - self::T975[$lo]) * ($df - $lo);
        }
        $z = self::Z975;
        return $z + ($z ** 3 + $z) / (4 * $df) + (5 * $z ** 5 + 16 * $z ** 3 + 3 * $z) / (96 * $df ** 2);
    }

    /** rank_pos (competition ranking by score among non-provisional) and stat_rank, overall and per track. */
    private static function rank(array &$out): void
    {
        $scopes = ['overall' => []];
        foreach ($out as $mid => $r) {
            $scopes['overall'][$mid] = $r['overall'];
            foreach ($r['tracks'] as $tid => $s) {
                $scopes[$tid][$mid] = $s;
            }
        }
        foreach ($scopes as $scope => $entries) {
            $eligible = array_filter($entries, static fn($s) => !$s['provisional']);
            uasort($eligible, static fn($a, $b) => round($b['score'], 2) <=> round($a['score'], 2));
            $pos = 0;
            $prev = null;
            $rank = 0;
            $ranks = [];
            foreach ($eligible as $mid => $s) {
                $pos++;
                $sc = round($s['score'], 2);
                if ($sc !== $prev) {
                    $rank = $pos;
                    $prev = $sc;
                }
                $ranks[$mid] = $rank;
            }
            foreach ($entries as $mid => $s) {
                $rankPos = $ranks[$mid] ?? null;
                $stat = null;
                if ($rankPos !== null) {
                    $hi = round($s['ci_high'] ?? $s['score'], 2);
                    $stat = 1;
                    foreach ($eligible as $other => $o) {
                        if ($other !== $mid && round($o['ci_low'] ?? $o['score'], 2) > $hi) {
                            $stat++;
                        }
                    }
                }
                if ($scope === 'overall') {
                    $out[$mid]['overall']['rank_pos'] = $rankPos;
                    $out[$mid]['overall']['stat_rank'] = $stat;
                } else {
                    $out[$mid]['tracks'][$scope]['rank_pos'] = $rankPos;
                    $out[$mid]['tracks'][$scope]['stat_rank'] = $stat;
                }
            }
        }
    }

    /** Replace the season's model_scores and today's history rows in one transaction. */
    private static function write(string $season, array $results, array $trackSlug): void
    {
        $rows = [];
        $hist = [];
        foreach ($results as $mid => $r) {
            $rows[] = self::row((int) $mid, $season, 'overall', $r['overall']);
            $o = $r['overall'];
            $hist[] = [(int) $mid, $season, round($o['score'], 2), self::r2($o['ci_low']), self::r2($o['ci_high']), $o['rank_pos'], gmdate('Y-m-d')];
            foreach ($r['tracks'] as $tid => $s) {
                if (isset($trackSlug[$tid])) {
                    $rows[] = self::row((int) $mid, $season, 'track:' . $trackSlug[$tid], $s);
                }
            }
        }
        $cols = self::COLS;
        if (Pipeline::hasColumn('model_scores', 'n_failed')) {
            $cols[] = 'n_failed';
        } else {
            $rows = array_map(static fn(array $r): array => array_slice($r, 0, count(self::COLS)), $rows);
        }
        DB::tx(static function () use ($season, $rows, $hist, $cols): void {
            DB::exec('DELETE FROM model_scores WHERE season = ?', [$season]);
            self::insertMany('model_scores', $cols, $rows);
            DB::exec('DELETE FROM score_history WHERE season = ? AND recorded_on = ?', [$season, gmdate('Y-m-d')]);
            self::insertMany('score_history', ['model_id', 'season', 'score', 'ci_low', 'ci_high', 'rank_pos', 'recorded_on'], $hist);
        });
    }

    private static function row(int $mid, string $season, string $scope, array $s): array
    {
        return [
            $mid, $season, $scope, round($s['score'], 2), self::r2($s['ci_low']), self::r2($s['ci_high']),
            $s['n_tasks'], $s['n_runs'], self::r2($s['auto_avg']), self::r2($s['judge_avg']), self::r2($s['consistency']),
            $s['avg_cost'] !== null ? round($s['avg_cost'], 6) : null,
            $s['avg_latency_ms'] !== null ? (int) round($s['avg_latency_ms']) : null,
            $s['avg_tokens'] !== null ? (int) round($s['avg_tokens']) : null,
            round($s['coverage'], 2), $s['provisional'] ? 1 : 0, $s['rank_pos'] ?? null, $s['stat_rank'] ?? null,
            (int) ($s['n_failed'] ?? 0),
        ];
    }

    private static function insertMany(string $table, array $cols, array $rows): void
    {
        foreach (array_chunk($rows, 200) as $chunk) {
            $ph = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
            DB::exec(
                "INSERT INTO `$table` (`" . implode('`,`', $cols) . '`) VALUES ' . implode(',', array_fill(0, count($chunk), $ph)),
                array_merge(...$chunk)
            );
        }
    }

    /**
     * Point statistics for a set of tasks. $trackGroups = list of [taskId => runs] (one per track) so the
     * auto/judge averages follow the same equal-weight hierarchy as the score.
     */
    private static function stats(float $score, array $trackGroups, array $runs, int $nTasks, array $sds, float $coverage): array
    {
        $avg = static function (string $field) use ($trackGroups): ?float {
            $trackMeans = [];
            foreach ($trackGroups as $tasks) {
                $taskMeans = [];
                foreach ($tasks as $taskRuns) {
                    $vals = [];
                    foreach ($taskRuns as $r) {
                        if (is_array($r) && isset($r[$field]) && $r[$field] !== null) {
                            $vals[] = (float) $r[$field];
                        }
                    }
                    if ($vals) {
                        $taskMeans[] = array_sum($vals) / count($vals);
                    }
                }
                if ($taskMeans) {
                    $trackMeans[] = array_sum($taskMeans) / count($taskMeans);
                }
            }
            return $trackMeans ? array_sum($trackMeans) / count($trackMeans) : null;
        };
        $plain = static function (string $field) use ($runs): ?float {
            $vals = [];
            foreach ($runs as $r) {
                if (isset($r[$field]) && $r[$field] !== null) {
                    $vals[] = (float) $r[$field];
                }
            }
            return $vals ? array_sum($vals) / count($vals) : null;
        };
        return [
            'score' => $score,
            'ci_low' => null,
            'ci_high' => null,
            'n_tasks' => $nTasks,
            'n_runs' => count($runs),
            'auto_avg' => $avg('auto'),
            'judge_avg' => $avg('judge'),
            'consistency' => $sds ? clamp(100 - array_sum($sds) / count($sds), 0.0, 100.0) : null,
            'avg_cost' => $plain('cost'),
            'avg_latency_ms' => $plain('latency'),
            'avg_tokens' => $plain('tokens'),
            'coverage' => $coverage,
            'provisional' => false,
            'rank_pos' => null,
            'stat_rank' => null,
        ];
    }

    /** 2.5 / 97.5 percentile CI that always contains the point estimate, within the 0–100 score range. */
    private static function ci(array $samples, float $point): array
    {
        if (!$samples) {
            return [null, null];
        }
        sort($samples);
        return [max(0.0, min($point, self::pct($samples, 2.5))), min(100.0, max($point, self::pct($samples, 97.5)))];
    }

    /**
     * n_failed per model (overall and per track): runs of included campaigns of the season on active tasks /
     * tracks / models that are excluded from the score — failed after their tries, or scored without a usable
     * score (e.g. unverified with nothing else to grade).
     */
    private static function addFailed(array &$results, string $season): void
    {
        foreach ($results as &$r) {
            $r['overall']['n_failed'] = 0;
            foreach ($r['tracks'] as &$t) {
                $t['n_failed'] = 0;
            }
            unset($t);
        }
        unset($r);
        $rows = DB::all(
            "SELECT r.model_id, t.track_id, COUNT(*) AS n
               FROM runs r
               JOIN campaigns c ON c.id = r.campaign_id
               JOIN tasks t ON t.id = r.task_id
               JOIN tracks tr ON tr.id = t.track_id
               JOIN models m ON m.id = r.model_id
              WHERE (r.status = 'failed' OR (r.status = 'scored' AND r.final_score IS NULL))
                AND c.include_in_scores = 1 AND c.season = ? AND t.is_active = 1 AND tr.is_active = 1 AND m.is_active = 1
              GROUP BY r.model_id, t.track_id",
            [$season]
        );
        foreach ($rows as $row) {
            $mid = (int) $row['model_id'];
            if (!isset($results[$mid])) {
                continue;
            }
            $results[$mid]['overall']['n_failed'] += (int) $row['n'];
            if (isset($results[$mid]['tracks'][(int) $row['track_id']])) {
                $results[$mid]['tracks'][(int) $row['track_id']]['n_failed'] = (int) $row['n'];
            }
        }
    }

    private static function pct(array $sorted, float $p): float
    {
        $n = count($sorted);
        $pos = ($n - 1) * $p / 100;
        $lo = (int) floor($pos);
        $hi = (int) ceil($pos);
        return $sorted[$lo] + ($sorted[$hi] - $sorted[$lo]) * ($pos - $lo);
    }

    private static function meanOfMeans(array $lists): float
    {
        $means = array_map(static fn(array $l): float => array_sum($l) / count($l), array_filter($lists, 'count'));
        return $means ? array_sum($means) / count($means) : 0.0;
    }

    /** Means of all n^n equally likely resamples of n values (n ≤ 4 → at most 256 entries). */
    private static function resampleTable(array $vals): array
    {
        $n = count($vals);
        $table = [];
        $total = $n ** $n;
        for ($i = 0; $i < $total; $i++) {
            $x = $i;
            $s = 0.0;
            for ($k = 0; $k < $n; $k++) {
                $s += $vals[$x % $n];
                $x = intdiv($x, $n);
            }
            $table[] = $s / $n;
        }
        return $table;
    }

    private static function r2(?float $v): ?float
    {
        return $v === null ? null : round($v, 2);
    }
}
