<?php
/** Home page ("/") and the design-system reference page ("/styleguide", noindex). Module: design. */
declare(strict_types=1);

defined('WB') || exit;

use WB\Data\Catalog;
use WB\Data\Leaderboard;

Router::get('/', static function (): void {
    view('pages/home', home_view_data() + [
        'nav_active' => 'home',
        'description' => __('home.meta_description'),
        'json_ld' => [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => setting('site_name', 'WOAIBench') . '.si',
            'url' => url('/', [], true),
            'inLanguage' => ['ar', 'en'],
            'description' => __('home.meta_description'),
        ],
    ]);
});

Router::get('/styleguide', static function (): void {
    view('pages/styleguide', [
        'title' => __('design.sg_title'),
        'robots' => 'noindex, nofollow',
        'page_js' => ['vendor/chart.umd.min.js'],
    ]);
});

/**
 * Everything the home page renders, in one array. Works on an empty database (fresh install):
 * every list may be empty and every count may be zero.
 */
function home_view_data(): array
{
    $rows = Leaderboard::rows();
    $ranked = array_values(array_filter($rows, static fn(array $r): bool => !$r['provisional'] && $r['rank_pos'] !== null));
    usort($ranked, static fn(array $a, array $b): int => $a['rank_pos'] <=> $b['rank_pos']);
    $tracks = Catalog::tracks();

    $leaders = [];
    foreach ($tracks as $slug => $t) {
        $best = null;
        foreach ($ranked as $r) {
            $s = $r['tracks'][$slug]['score'] ?? null;
            if ($s !== null && ($best === null || $s > $best['score'])) {
                $best = ['model' => $r, 'score' => $s];
            }
        }
        $leaders[$slug] = $best;
    }

    $tiedFirst = count(array_filter($ranked, static fn(array $r): bool => (int) $r['stat_rank'] === 1));

    return [
        'rows' => $rows,
        'ranked' => $ranked,
        'top' => array_slice($ranked, 0, 10),
        'stats' => Leaderboard::stats(),
        'tracks' => $tracks,
        'leaders' => $leaders,
        'tiedFirst' => $tiedFirst,
        'arenaTop' => home_arena_top(3),
        'changes' => home_changelog(3),
        'season' => Catalog::season(),
        'method' => [
            'runs' => Catalog::runsPerTask(),
            'judges' => max(1, (int) setting('judge_count', 3)),
            'boot' => max(1, (int) setting('bootstrap_iters', 1000)),
        ],
    ];
}

/** Highest arena ratings (Bradley–Terry, Elo-like scale) with at least one vote. */
function home_arena_top(int $limit): array
{
    return DB::all(
        'SELECT m.slug, m.name, m.provider, m.provider_name, a.rating, a.ci_low, a.ci_high, a.votes
           FROM arena_ratings a JOIN models m ON m.id = a.model_id
          WHERE a.votes > 0 AND m.is_active = 1
          ORDER BY a.rating DESC LIMIT ' . max(1, $limit)
    );
}

/** Latest published changelog entries. */
function home_changelog(int $limit): array
{
    return DB::all(
        'SELECT version, title_ar, title_en, body_ar, body_en, published_at FROM changelog
          WHERE published_at <= ? ORDER BY published_at DESC, id DESC LIMIT ' . max(1, $limit),
        [now()]
    );
}
