<?php
/**
 * Human preference Arena: informative blind pairing, single-use vote tokens, Bradley–Terry ratings.
 *
 * Pairs are two scored runs of different models on the same public, active HTML task. The browser only
 * ever sees an opaque token (/arena/frame/{token}/a|b), so model identity cannot leak through URLs.
 */
declare(strict_types=1);

namespace WB\Arena;

defined('WB') || exit;

use DB;
use Settings;
use WB\Data\Leaderboard;

final class Arena
{
    public const WINNERS = ['a', 'b', 'tie', 'both_bad'];
    public const MAX_AGE = 3600;              // a pair can be voted on for one hour
    public const FRAME_GRACE = 1800;          // frames stay viewable a little longer (reveal screen)
    public const RECOMPUTE_MIN_GAP = 300;     // seconds between recomputes when few new votes arrived
    public const RECOMPUTE_BATCH = 20;        // …unless at least this many new votes are waiting
    public const FEW_VOTES = 30;              // ratings with fewer votes are flagged as provisional

    /** Eligible-run SQL filter (runs r, tasks t, models m). */
    private const ELIGIBLE = "r.status = 'scored' AND t.visibility = 'public' AND t.is_active = 1 AND t.output_type = 'html'
        AND m.is_active = 1 AND (r.code_bytes > 0 OR (r.code_bytes IS NULL AND r.code IS NOT NULL AND r.code <> ''))";

    private static ?array $pool = null;

    /** Arena switched on by the admin. */
    public static function enabled(): bool
    {
        return (bool) (int) \setting('arena_enabled', 1);
    }

    /** Anonymous, salted voter identity: client IP + user agent (never stored in clear). */
    public static function voterHash(): string
    {
        return \ip_hash((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    }

    /** True when at least one public HTML task has scored outputs from two different models. */
    public static function available(): bool
    {
        foreach (self::pool() as $models) {
            if (count($models) >= 2) {
                return true;
            }
        }
        return false;
    }

    /**
     * Create a new blind pair for this voter. Returns the client payload (see payload()) or null when
     * there is not enough data.
     */
    public static function createPair(string $voterHash): ?array
    {
        $pool = self::pool();
        $byModel = [];
        foreach ($pool as $taskId => $models) {
            if (count($models) < 2) {
                continue;
            }
            foreach ($models as $mid) {
                $byModel[$mid][$taskId] = true;
            }
        }
        if (count($byModel) < 2) {
            return null;
        }
        $info = self::modelInfo(array_keys($byModel));
        $seen = self::seenByVoter($voterHash);

        $first = self::weightedPick(array_map(static fn($i) => $i['explore'], array_intersect_key($info, $byModel)));
        $partners = [];
        foreach ($byModel as $mid => $tasks) {
            if ($mid === $first) {
                continue;
            }
            $shared = array_intersect_key($byModel[$first], $tasks);
            if (!$shared) {
                continue;
            }
            $key = self::pairKey($first, $mid);
            $novelty = isset($seen[$key]) ? (count($seen[$key]) >= count($shared) ? 0.02 : 0.15) : 1.0;
            $partners[$mid] = $info[$mid]['explore'] * self::closeness($info[$first], $info[$mid]) * $novelty;
        }
        if (!$partners) {
            return null;
        }
        $second = self::weightedPick($partners);
        $shared = array_keys(array_intersect_key($byModel[$first], $byModel[$second]));
        $fresh = array_values(array_diff($shared, $seen[self::pairKey($first, $second)] ?? []));
        $taskId = (int) (($fresh ?: $shared)[random_int(0, count($fresh ?: $shared) - 1)]);

        $runFirst = self::randomRun($first, $taskId);
        $runSecond = self::randomRun($second, $taskId);
        if (!$runFirst || !$runSecond) {
            return null;
        }
        [$runA, $runB] = random_int(0, 1) ? [$runFirst, $runSecond] : [$runSecond, $runFirst];
        $token = \random_token(16);
        DB::insert('arena_pairs', [
            'token' => $token, 'task_id' => $taskId, 'run_a' => $runA, 'run_b' => $runB,
            'voter_hash' => $voterHash, 'used' => 0, 'created_at' => \now(),
        ]);
        if (random_int(1, 50) === 1) {
            DB::exec('DELETE FROM arena_pairs WHERE used = 0 AND created_at < ?', [\now(-86400 * 2)]);
        }
        return self::payload($token, $taskId);
    }

    /** Client payload for a pair: opaque frame URLs + task context (never model data). */
    public static function payload(string $token, int $taskId): ?array
    {
        $task = DB::one(
            'SELECT t.slug, t.title_ar, t.title_en, t.summary_ar, t.summary_en, t.difficulty,
                    tr.slug AS track_slug, tr.name_ar, tr.name_en, tr.icon, tr.color
               FROM tasks t JOIN tracks tr ON tr.id = t.track_id WHERE t.id = ?',
            [$taskId]
        );
        if (!$task) {
            return null;
        }
        return [
            'token' => $token,
            'task' => [
                'title' => \L($task, 'title'),
                'summary' => \L($task, 'summary'),
                'difficulty' => (int) $task['difficulty'],
                'track' => ['slug' => $task['track_slug'], 'name' => \L($task, 'name'), 'icon' => $task['icon'], 'color' => $task['color']],
            ],
            'frames' => [
                'a' => \url('/arena/frame/' . $token . '/a'),
                'b' => \url('/arena/frame/' . $token . '/b'),
            ],
        ];
    }

    /**
     * Resolve a frame request to [run, task] rows, or null when the token is unknown, too old or the
     * task is no longer public.
     */
    public static function frame(string $token, string $side): ?array
    {
        $pair = DB::one('SELECT * FROM arena_pairs WHERE token = ?', [$token]);
        if (!$pair || !in_array($side, ['a', 'b'], true) || self::age($pair) > self::MAX_AGE + self::FRAME_GRACE) {
            return null;
        }
        $run = DB::one('SELECT * FROM runs WHERE id = ?', [(int) $pair[$side === 'a' ? 'run_a' : 'run_b']]);
        $task = $run ? DB::one('SELECT * FROM tasks WHERE id = ?', [(int) $run['task_id']]) : null;
        if (!$run || !$task || $task['visibility'] !== 'public' || (int) $task['is_active'] !== 1) {
            return null;
        }
        return [$run, $task];
    }

    /**
     * Record a vote. Returns ['ok' => true, …reveal] or ['ok' => false, 'error' => code, 'status' => http].
     * The token must exist, belong to this voter, be unused and younger than MAX_AGE.
     */
    public static function vote(string $token, string $winner, string $voterHash): array
    {
        if (!in_array($winner, self::WINNERS, true)) {
            return ['ok' => false, 'error' => 'bad_winner', 'status' => 422];
        }
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return ['ok' => false, 'error' => 'invalid', 'status' => 404];
        }
        $result = DB::tx(static function () use ($token, $winner, $voterHash): array {
            $pair = DB::one('SELECT * FROM arena_pairs WHERE token = ? FOR UPDATE', [$token]);
            if (!$pair) {
                return ['ok' => false, 'error' => 'invalid', 'status' => 404];
            }
            if (!hash_equals((string) $pair['voter_hash'], $voterHash)) {
                return ['ok' => false, 'error' => 'foreign', 'status' => 403];
            }
            if ((int) $pair['used'] === 1) {
                return ['ok' => false, 'error' => 'used', 'status' => 409];
            }
            if (self::age($pair) > self::MAX_AGE) {
                return ['ok' => false, 'error' => 'expired', 'status' => 410];
            }
            $runs = DB::keyed('SELECT id, model_id, task_id, final_score FROM runs WHERE id IN (?, ?)', [(int) $pair['run_a'], (int) $pair['run_b']]);
            $ra = $runs[(int) $pair['run_a']] ?? null;
            $rb = $runs[(int) $pair['run_b']] ?? null;
            if (!$ra || !$rb || DB::exec('UPDATE arena_pairs SET used = 1 WHERE id = ? AND used = 0', [(int) $pair['id']]) !== 1) {
                return ['ok' => false, 'error' => $ra && $rb ? 'used' : 'invalid', 'status' => $ra && $rb ? 409 : 404];
            }
            DB::insert('arena_votes', [
                'pair_id' => (int) $pair['id'], 'task_id' => (int) $pair['task_id'],
                'run_a' => (int) $ra['id'], 'run_b' => (int) $rb['id'],
                'model_a' => (int) $ra['model_id'], 'model_b' => (int) $rb['model_id'],
                'winner' => $winner, 'voter_hash' => $voterHash, 'created_at' => \now(),
            ]);
            return ['ok' => true, 'pair' => $pair, 'runs' => [$ra, $rb]];
        });
        if (!$result['ok']) {
            return $result;
        }
        [$ra, $rb] = $result['runs'];
        return [
            'ok' => true,
            'winner' => $winner,
            'reveal' => ['a' => self::revealSide($ra), 'b' => self::revealSide($rb)],
            'task_url' => self::taskUrl((int) $result['pair']['task_id']),
            'votes_total' => (int) DB::val('SELECT COUNT(*) FROM arena_votes'),
        ];
    }

    /** Recompute ratings when ≥ RECOMPUTE_BATCH new votes, or any new vote and ≥ RECOMPUTE_MIN_GAP seconds passed. */
    public static function maybeRecompute(): void
    {
        $count = (int) DB::val('SELECT COUNT(*) FROM arena_votes');
        $atLast = (int) \setting('arena_votes_at_recompute', 0);
        if ($count === $atLast) {
            return;
        }
        $last = (string) \setting('arena_last_recompute', '');
        $lastTs = $last !== '' ? (int) strtotime($last . ' UTC') : 0;
        if (abs($count - $atLast) >= self::RECOMPUTE_BATCH || time() - $lastTs >= self::RECOMPUTE_MIN_GAP) {
            self::recomputeRatings();
        }
    }

    /** Refit Bradley–Terry on every vote and rewrite arena_ratings (votes, wins, losses, ties, rating, CI). */
    public static function recomputeRatings(): void
    {
        $locked = (int) DB::val('SELECT GET_LOCK(?, 0)', ['wb_arena_recompute']) === 1;
        if (!$locked) {
            return;
        }
        try {
            $count = (int) DB::val('SELECT COUNT(*) FROM arena_votes');
            $cells = DB::all(
                'SELECT v.model_a, v.model_b, v.winner, COUNT(*) AS n
                   FROM arena_votes v
                   JOIN models ma ON ma.id = v.model_a
                   JOIN models mb ON mb.id = v.model_b
                  WHERE v.model_a <> v.model_b
                  GROUP BY v.model_a, v.model_b, v.winner'
            );
            $ratings = BradleyTerry::ratings($cells, 200);
            $tally = self::tally($cells);
            DB::tx(static function () use ($ratings, $tally): void {
                DB::exec('DELETE FROM arena_ratings');
                foreach ($ratings as $id => $r) {
                    $t = $tally[$id] ?? ['votes' => 0, 'wins' => 0, 'losses' => 0, 'ties' => 0];
                    DB::insert('arena_ratings', [
                        'model_id' => (int) $id, 'rating' => round($r['rating'], 2),
                        'ci_low' => round($r['ci_low'], 2), 'ci_high' => round($r['ci_high'], 2),
                        'votes' => $t['votes'], 'wins' => $t['wins'], 'losses' => $t['losses'], 'ties' => $t['ties'],
                    ]);
                }
            });
            Settings::set('arena_last_recompute', \now());
            Settings::set('arena_votes_at_recompute', $count);
        } finally {
            DB::val('SELECT RELEASE_LOCK(?)', ['wb_arena_recompute']);
        }
    }

    /**
     * Arena leaderboard rows (rating desc) merged with benchmark scores, plus Spearman correlation.
     * Returns ['rows' => [...], 'spearman' => ?float, 'n_corr' => int].
     */
    public static function leaderboard(): array
    {
        $rows = DB::all(
            'SELECT a.model_id, a.rating, a.ci_low, a.ci_high, a.votes, a.wins, a.losses, a.ties, a.updated_at,
                    m.slug, m.name, m.provider, m.provider_name, m.color, m.open_weights
               FROM arena_ratings a JOIN models m ON m.id = a.model_id
              ORDER BY a.rating DESC, a.votes DESC'
        );
        $bench = [];
        foreach (Leaderboard::rows() as $r) {
            $bench[(int) $r['id']] = $r;
        }
        $pos = 0;
        $pairs = [];
        foreach ($rows as &$r) {
            $id = (int) $r['model_id'];
            foreach (['rating', 'ci_low', 'ci_high'] as $f) {
                $r[$f] = $r[$f] !== null ? (float) $r[$f] : null;
            }
            foreach (['votes', 'wins', 'losses', 'ties'] as $f) {
                $r[$f] = (int) $r[$f];
            }
            $r['id'] = $id;
            $r['color'] = $r['color'] ?: \ui_provider_color((string) $r['provider']);
            $r['few_votes'] = $r['votes'] < self::FEW_VOTES;
            $r['arena_rank'] = ++$pos;
            $r['win_rate'] = $r['votes'] > 0 ? ($r['wins'] + 0.5 * $r['ties']) / $r['votes'] : null;
            $b = $bench[$id] ?? null;
            $r['bench_score'] = $b['score'] ?? null;
            $r['bench_ci_low'] = $b['ci_low'] ?? null;
            $r['bench_ci_high'] = $b['ci_high'] ?? null;
            $r['bench_rank'] = $b['rank_pos'] ?? null;
            $r['bench_provisional'] = $b['provisional'] ?? null;
            if ($r['bench_score'] !== null) {
                $pairs[] = [$r['rating'], $r['bench_score']];
            }
        }
        unset($r);
        return ['rows' => $rows, 'spearman' => count($pairs) >= 3 ? self::spearman($pairs) : null, 'n_corr' => count($pairs)];
    }

    /** Headline numbers: votes, voters, votes in the last 24 h, rated models, last recompute. */
    public static function stats(): array
    {
        $r = DB::one('SELECT COUNT(*) AS votes, COUNT(DISTINCT voter_hash) AS voters, COALESCE(SUM(created_at >= ?), 0) AS day FROM arena_votes', [\now(-86400)]);
        return [
            'votes' => (int) ($r['votes'] ?? 0),
            'voters' => (int) ($r['voters'] ?? 0),
            'votes_24h' => (int) ($r['day'] ?? 0),
            'models' => (int) DB::val('SELECT COUNT(*) FROM arena_ratings'),
            'last_recompute' => (string) \setting('arena_last_recompute', '') ?: null,
        ];
    }

    /** Spearman rank correlation of [[x, y], …] (average ranks for ties). */
    public static function spearman(array $pairs): ?float
    {
        $n = count($pairs);
        if ($n < 3) {
            return null;
        }
        $rx = self::ranks(array_column($pairs, 0));
        $ry = self::ranks(array_column($pairs, 1));
        $mx = array_sum($rx) / $n;
        $my = array_sum($ry) / $n;
        $sxy = $sxx = $syy = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dx = $rx[$i] - $mx;
            $dy = $ry[$i] - $my;
            $sxy += $dx * $dy;
            $sxx += $dx * $dx;
            $syy += $dy * $dy;
        }
        return ($sxx > 0 && $syy > 0) ? $sxy / sqrt($sxx * $syy) : null;
    }

    /* ------------------------------------------------------------------ internals */

    /** task_id => [model ids with eligible runs] for public, active HTML tasks. */
    private static function pool(): array
    {
        if (self::$pool !== null) {
            return self::$pool;
        }
        $rows = DB::all(
            'SELECT DISTINCT r.task_id, r.model_id
               FROM runs r
               JOIN tasks t ON t.id = r.task_id
               JOIN models m ON m.id = r.model_id
              WHERE ' . self::ELIGIBLE
        );
        $pool = [];
        foreach ($rows as $r) {
            $pool[(int) $r['task_id']][] = (int) $r['model_id'];
        }
        return self::$pool = $pool;
    }

    /** Exploration weight, arena rating and benchmark score for each candidate model. */
    private static function modelInfo(array $ids): array
    {
        $ratings = DB::keyed('SELECT model_id, rating, votes FROM arena_ratings WHERE model_id IN (' . DB::in($ids) . ')', $ids);
        $scores = DB::keyed(
            "SELECT model_id, score FROM model_scores WHERE season = ? AND scope = 'overall' AND model_id IN (" . DB::in($ids) . ')',
            array_merge([(string) \setting('season')], $ids)
        );
        $info = [];
        foreach ($ids as $id) {
            $votes = (int) ($ratings[$id]['votes'] ?? 0);
            $info[$id] = [
                'explore' => 1 / sqrt(1 + $votes / 25),
                'rating' => isset($ratings[$id]) && $votes > 0 ? (float) $ratings[$id]['rating'] : null,
                'score' => isset($scores[$id]) ? (float) $scores[$id]['score'] : null,
            ];
        }
        return $info;
    }

    /** How informative a match-up is: close ratings (or close benchmark scores) are preferred. */
    private static function closeness(array $a, array $b): float
    {
        if ($a['rating'] !== null && $b['rating'] !== null) {
            $c = exp(-((($a['rating'] - $b['rating']) / 200) ** 2));
        } elseif ($a['score'] !== null && $b['score'] !== null) {
            $c = exp(-((($a['score'] - $b['score']) / 15) ** 2));
        } else {
            $c = 0.6;
        }
        return max(0.05, $c);
    }

    /** Pairs this voter saw recently: "lo:hi" => [task ids]. */
    private static function seenByVoter(string $voterHash): array
    {
        $rows = DB::all(
            'SELECT p.task_id, ra.model_id AS ma, rb.model_id AS mb
               FROM arena_pairs p
               JOIN runs ra ON ra.id = p.run_a
               JOIN runs rb ON rb.id = p.run_b
              WHERE p.voter_hash = ? AND p.created_at >= ?
              ORDER BY p.id DESC LIMIT 300',
            [$voterHash, \now(-86400 * 7)]
        );
        $seen = [];
        foreach ($rows as $r) {
            $seen[self::pairKey((int) $r['ma'], (int) $r['mb'])][] = (int) $r['task_id'];
        }
        return array_map(static fn($t) => array_values(array_unique($t)), $seen);
    }

    private static function pairKey(int $a, int $b): string
    {
        return min($a, $b) . ':' . max($a, $b);
    }

    /** Random key from [key => weight] proportionally to weight. */
    private static function weightedPick(array $weights): int
    {
        $total = array_sum($weights);
        $x = (random_int(0, PHP_INT_MAX - 1) / PHP_INT_MAX) * $total;
        foreach ($weights as $k => $w) {
            $x -= $w;
            if ($x < 0) {
                return (int) $k;
            }
        }
        return (int) array_key_last($weights);
    }

    /** Random eligible run of a model on a task. */
    private static function randomRun(int $modelId, int $taskId): ?int
    {
        $ids = DB::col(
            'SELECT r.id FROM runs r JOIN tasks t ON t.id = r.task_id JOIN models m ON m.id = r.model_id
              WHERE r.model_id = ? AND r.task_id = ? AND ' . self::ELIGIBLE,
            [$modelId, $taskId]
        );
        return $ids ? (int) $ids[random_int(0, count($ids) - 1)] : null;
    }

    /** Public facts about one side after the vote. */
    private static function revealSide(array $run): array
    {
        $m = DB::one('SELECT id, slug, name, provider, provider_name, color FROM models WHERE id = ?', [(int) $run['model_id']]) ?? [];
        $row = isset($m['slug']) ? Leaderboard::row((string) $m['slug']) : null;
        $arena = DB::one('SELECT rating, ci_low, ci_high, votes FROM arena_ratings WHERE model_id = ?', [(int) $run['model_id']]);
        return [
            'name' => (string) ($m['name'] ?? '?'),
            'slug' => (string) ($m['slug'] ?? ''),
            'provider' => (string) ($m['provider_name'] ?? $m['provider'] ?? ''),
            'color' => ($m['color'] ?? '') ?: \ui_provider_color((string) ($m['provider'] ?? '')),
            'url' => isset($m['slug']) ? \url('/model/' . $m['slug']) : null,
            'run_url' => \url('/run/' . (int) $run['id']),
            'run_score' => $run['final_score'] !== null ? round((float) $run['final_score'], 1) : null,
            'score' => $row && $row['score'] !== null ? round((float) $row['score'], 1) : null,
            'rank' => $row['rank_pos'] ?? null,
            'rating' => $arena ? round((float) $arena['rating']) : null,
            'rating_votes' => $arena ? (int) $arena['votes'] : 0,
        ];
    }

    private static function taskUrl(int $taskId): ?string
    {
        $slug = DB::val('SELECT slug FROM tasks WHERE id = ?', [$taskId]);
        return $slug ? \url('/task/' . $slug) : null;
    }

    /** Seconds since the pair was created. */
    private static function age(array $pair): int
    {
        return time() - (int) strtotime($pair['created_at'] . ' UTC');
    }

    /** votes / wins / losses / ties per model from aggregated vote cells (both_bad counts as a tie). */
    private static function tally(array $cells): array
    {
        $t = [];
        foreach ($cells as $c) {
            $n = (int) $c['n'];
            foreach (['a' => (int) $c['model_a'], 'b' => (int) $c['model_b']] as $side => $id) {
                $t[$id] ??= ['votes' => 0, 'wins' => 0, 'losses' => 0, 'ties' => 0];
                $t[$id]['votes'] += $n;
                if ($c['winner'] === $side) {
                    $t[$id]['wins'] += $n;
                } elseif ($c['winner'] === 'a' || $c['winner'] === 'b') {
                    $t[$id]['losses'] += $n;
                } else {
                    $t[$id]['ties'] += $n;
                }
            }
        }
        return $t;
    }

    /** Average ranks (1-based) for a list of values. */
    private static function ranks(array $xs): array
    {
        $idx = array_keys($xs);
        usort($idx, static fn($i, $j) => $xs[$i] <=> $xs[$j]);
        $ranks = [];
        $n = count($idx);
        for ($i = 0; $i < $n;) {
            $j = $i;
            while ($j + 1 < $n && $xs[$idx[$j + 1]] == $xs[$idx[$i]]) {
                $j++;
            }
            $avg = ($i + $j) / 2 + 1;
            for ($k = $i; $k <= $j; $k++) {
                $ranks[$idx[$k]] = $avg;
            }
            $i = $j + 1;
        }
        ksort($ranks);
        return array_values($ranks);
    }
}
