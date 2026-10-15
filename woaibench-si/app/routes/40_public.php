<?php
/**
 * Public data pages (module: public): leaderboard, model, compare, tasks, task, run, methodology,
 * value, changelog, about, request.
 */
declare(strict_types=1);

defined('WB') || exit;

use WB\Data\Catalog;
use WB\Data\Leaderboard;
use WB\Data\Models;
use WB\Data\Runs;
use WB\Data\Tasks;

/** String query parameter ('' when missing or not a string). */
function pub_q(string $key, int $max = 120): string
{
    $v = $_GET[$key] ?? '';
    return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
}

/** Season from ?season= when it is a known season, else the current one. */
function pub_season(): string
{
    $s = pub_q('season', 16);
    return $s !== '' && in_array($s, Catalog::seasons(), true) ? $s : Catalog::season();
}

/** Common layout vars for every public page. */
function pub_page(string $titleKey, string $descKey, string $nav, array $extra = []): array
{
    return array_merge([
        'title' => __($titleKey),
        'description' => __($descKey),
        'nav_active' => $nav,
        'page_css' => ['css/public.css'],
        'page_js' => [],
    ], $extra);
}

/** Escape a lang string and allow **bold** and `code` (used for long-form copy). */
function pub_rich(string $key, array $vars = []): string
{
    $html = e(__($key, $vars));
    $html = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $html) ?? $html;
    return preg_replace('/`(.+?)`/u', '<code>$1</code>', $html) ?? $html;
}

/** JSON block of this module's JS strings: <script type="application/json" id="pub-i18n">. */
function pub_js_strings(): string
{
    return '<script type="application/json" id="pub-i18n">' . js_json(I18n::forJs(['pub.js.'])) . '</script>';
}

/** Output-type icon name. */
function pub_type_icon(string $type): string
{
    return ['html' => 'palette', 'js' => 'code', 'answer' => 'target', 'agent' => 'robot', 'text' => 'file'][$type] ?? 'file';
}

/**
 * Score bar on a zoomed domain [$lo, $hi] with an optional 95% CI whisker (leaderboard / compare).
 */
function pub_bar(?float $score, float $lo, float $hi, ?float $ciLo = null, ?float $ciHi = null): string
{
    $span = max(1.0, $hi - $lo);
    $pos = static fn(?float $v): float => $v === null ? 0.0 : clamp(($v - $lo) / $span * 100, 0, 100);
    $f = static fn(float $v): string => number_format($v, 2, '.', '');
    $ci = '';
    if ($ciLo !== null && $ciHi !== null) {
        $l = $pos($ciLo);
        $ci = '<span class="scorebar-ci" style="inset-inline-start:' . $f($l) . '%;width:' . $f(max(0.8, $pos($ciHi) - $l)) . '%"></span>';
    }
    $label = fmt_score($score) . ($ciLo !== null && $ciHi !== null ? ' (' . fmt_score($ciLo) . '–' . fmt_score($ciHi) . ')' : '');
    return '<span class="scorebar pub-bar" role="img" aria-label="' . e($label) . '"><span class="scorebar-fill" style="width:' . $f($pos($score)) . '%"></span>' . $ci . '</span>';
}

/** Zoomed bar domain [lo, hi] covering every displayed score and CI, in steps of 10. */
function pub_bar_domain(array $rows, string $lowKey = 'd_ci_low', string $highKey = 'd_ci_high', string $scoreKey = 'd_score'): array
{
    $vals = [];
    foreach ($rows as $r) {
        foreach ([$lowKey, $highKey, $scoreKey] as $k) {
            if (isset($r[$k]) && $r[$k] !== null) {
                $vals[] = (float) $r[$k];
            }
        }
    }
    if (!$vals) {
        return [0.0, 100.0];
    }
    $lo = max(0.0, floor((min($vals) - 4) / 10) * 10);
    $hi = min(100.0, ceil((max($vals) + 2) / 10) * 10);
    return $hi - $lo < 20 ? [max(0.0, $hi - 20), $hi] : [$lo, $hi];
}

/** Compact "automated vs judges" split for a task row. */
function pub_split(mixed $auto, mixed $judge): string
{
    $part = static function (string $cls, string $label, mixed $v): string {
        if ($v === null || $v === '') {
            return '';
        }
        return '<span class="pub-split-part ' . $cls . '"><span class="pub-split-k">' . e($label) . '</span><span class="t-num">' . e(fmt_score($v)) . '</span></span>';
    };
    $html = $part('is-auto', __('pub.split_auto'), $auto) . $part('is-judge', __('pub.split_judge'), $judge);
    return $html === '' ? '<span class="t-faint">—</span>' : '<span class="pub-split">' . $html . '</span>';
}

/** Grading-method icon name. */
function pub_grading_icon(string $method): string
{
    return ['static' => 'list', 'browser' => 'eye', 'tests' => 'check', 'answer' => 'target', 'agent' => 'robot', 'judges' => 'scale'][$method] ?? 'info';
}

Router::get('/leaderboard', static function (): void {
    $season = pub_season();
    $tracks = Catalog::tracks();
    $track = pub_q('track', 40);
    if ($track !== '' && !isset($tracks[$track])) {
        $track = '';
    }
    $rows = Models::rank(Leaderboard::rows($season, $track !== '' ? ['track' => $track] : []), $track !== '' ? $track : null);
    $stats = Leaderboard::stats($season);
    $canonical = url('/leaderboard', ['track' => $track ?: null, 'season' => $season !== Catalog::season() ? $season : null], true);
    $trackName = $track !== '' ? L($tracks[$track], 'name') : '';
    view('pages/leaderboard', pub_page('pub.lb.title', 'pub.lb.meta', 'leaderboard', [
        'title' => $trackName !== '' ? __('pub.lb.title_track', ['track' => $trackName]) : __('pub.lb.title'),
        'canonical' => $canonical,
        'page_js' => ['js/leaderboard.js'],
        'rows' => $rows,
        'tracks' => $tracks,
        'track' => $track,
        'season' => $season,
        'seasons' => Catalog::seasons(),
        'stats' => $stats,
        'json_ld' => $rows ? pub_dataset_ld($rows, $stats, $canonical) : null,
    ]));
});

/** schema.org Dataset markup for the leaderboard. */
function pub_dataset_ld(array $rows, array $stats, string $canonical): array
{
    $name = (string) setting('site_name', 'WOAIBench');
    return [
        '@context' => 'https://schema.org',
        '@type' => 'Dataset',
        'name' => __('pub.lb.ld_name', ['site' => $name]),
        'description' => __('pub.lb.ld_desc', ['models' => count($rows), 'tasks' => $stats['tasks'], 'tracks' => $stats['tracks']]),
        'url' => $canonical,
        'inLanguage' => lang(),
        'isAccessibleForFree' => true,
        'dateModified' => $stats['last_updated'] ? gmdate('c', (int) strtotime($stats['last_updated'] . ' UTC')) : null,
        'creator' => ['@type' => 'Organization', 'name' => $name, 'url' => url('/', [], true)],
        'keywords' => ['AI benchmark', 'LLM', 'coding', 'leaderboard', 'confidence intervals'],
        'variableMeasured' => [__('pub.lb.col_score'), __('pub.lb.col_arena'), __('pub.lb.col_consistency'), __('pub.lb.col_cost'), __('pub.lb.col_latency')],
        'measurementTechnique' => __('pub.lb.ld_technique'),
        'distribution' => [
            ['@type' => 'DataDownload', 'encodingFormat' => 'text/csv', 'contentUrl' => url('/export/leaderboard.csv', [], true)],
            ['@type' => 'DataDownload', 'encodingFormat' => 'application/json', 'contentUrl' => url('/api/v1/leaderboard', [], true)],
        ],
    ];
}

Router::get('/model/{slug}', static function (string $slug): void {
    $model = Models::find($slug);
    $season = pub_season();
    $all = Leaderboard::rows($season);
    $row = null;
    foreach ($all as $r) {
        if ($r['slug'] === $slug) {
            $row = $r;
        }
    }
    if (!$model || (!$row && !$model['is_active'] && !$model['is_judge'])) {
        abort(404);
    }
    $tasks = $row ? Models::taskStats((int) $model['id'], $season) : [];
    $title = $model['name'];
    view('pages/model', pub_page('pub.model.title', 'pub.model.meta', 'leaderboard', [
        'title' => $title,
        'description' => $row
            ? __('pub.model.meta_scored', ['name' => $model['name'], 'score' => fmt_score($row['score']), 'rank' => $row['rank_pos'] ?? '—'])
            : __('pub.model.meta_unscored', ['name' => $model['name']]),
        'canonical' => url('/model/' . $model['slug'], [], true),
        'page_js' => $row ? ['vendor/chart.umd.min.js', 'js/model.js'] : [],
        'model' => $model,
        'row' => $row,
        'season' => $season,
        'total_ranked' => count(array_filter($all, static fn($r) => !$r['provisional'])),
        'tracks' => Catalog::tracks(),
        'medians' => Models::trackMedians($all),
        'history' => $row ? Leaderboard::history((int) $model['id'], $season) : [],
        'tasks' => $tasks,
        'holdout' => $row ? Models::holdoutByTrack((int) $model['id'], $season) : [],
        'agreement' => $row ? Models::judgeAgreement((int) $model['id'], $season) : null,
        'outputs' => Models::recentOutputs((int) $model['id'], 8),
    ]));
});

/** Selected compare slugs from ?m=a,b (max 4, known scored models only, order kept). */
function pub_compare_selection(array $rows): array
{
    $bySlug = [];
    foreach ($rows as $r) {
        $bySlug[$r['slug']] = $r;
    }
    $want = array_filter(array_map('trim', explode(',', pub_q('m', 800))));
    if (!$want) {
        // No-JS fallback form posts m1..m4.
        $want = array_filter([pub_q('m1', 160), pub_q('m2', 160), pub_q('m3', 160), pub_q('m4', 160)]);
    }
    $sel = [];
    foreach ($want as $s) {
        if (isset($bySlug[$s]) && !isset($sel[$s]) && count($sel) < 4) {
            $sel[$s] = $bySlug[$s];
        }
    }
    return array_values($sel);
}

/** Head-to-head data for the compare page. */
function pub_compare_h2h(array $sel, string $season): array
{
    $ids = array_map(static fn($r) => (int) $r['id'], $sel);
    $matrix = Models::taskMatrix($ids, $season);
    if (!$matrix) {
        return ['tasks' => [], 'pairs' => []];
    }
    $tasks = Tasks::listPublic();
    $tasks = array_values(array_filter($tasks, static fn($t) => isset($matrix[(int) $t['id']])));
    $pairs = [];
    for ($i = 0; $i < count($ids); $i++) {
        for ($j = $i + 1; $j < count($ids); $j++) {
            $w = $t = $l = 0;
            foreach ($matrix as $scores) {
                if (!isset($scores[$ids[$i]], $scores[$ids[$j]])) {
                    continue;
                }
                $d = $scores[$ids[$i]] - $scores[$ids[$j]];
                abs($d) < 2 ? $t++ : ($d > 0 ? $w++ : $l++);
            }
            $pairs[] = ['a' => $i, 'b' => $j, 'w' => $w, 't' => $t, 'l' => $l, 'different' => !Models::overlaps(pub_d($sel[$i]), pub_d($sel[$j]))];
        }
    }
    return ['tasks' => $tasks, 'matrix' => $matrix, 'pairs' => $pairs];
}

/** Leaderboard row with display score keys (overall). */
function pub_d(array $r): array
{
    $r['d_score'] = $r['score'];
    $r['d_ci_low'] = $r['ci_low'];
    $r['d_ci_high'] = $r['ci_high'];
    return $r;
}

Router::get('/compare', static function (): void {
    $season = Catalog::season();
    $rows = Leaderboard::rows($season);
    $sel = pub_compare_selection($rows);
    if (!$sel && $rows && pub_q('m') === '' && pub_q('m1', 160) === '') {
        $ranked = array_values(array_filter($rows, static fn($r) => !$r['provisional']));
        $sel = array_slice($ranked ?: $rows, 0, 2);
    }
    $vars = [
        'rows' => $rows,
        'sel' => $sel,
        'season' => $season,
        'tracks' => Catalog::tracks(),
        'h2h' => count($sel) >= 2 ? pub_compare_h2h($sel, $season) : ['tasks' => [], 'pairs' => []],
        'values' => pub_value_scores($rows),
    ];
    if (pub_q('fragment') === '1') {
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
        echo partial('partials/public-compare-results', $vars);
        return;
    }
    $names = implode(' vs ', array_map(static fn($r) => $r['name'], $sel));
    view('pages/compare', pub_page('pub.cmp.title', 'pub.cmp.meta', 'compare', $vars + [
        'title' => $names !== '' && count($sel) > 1 ? $names : __('pub.cmp.title'),
        'canonical' => url('/compare', ['m' => implode(',', array_map(static fn($r) => $r['slug'], $sel)) ?: null], true),
        'page_js' => ['vendor/chart.umd.min.js', 'js/compare.js'],
    ]));
});

/** Value index per model id: score points per cent of average task cost (null without cost). */
function pub_value_scores(array $rows): array
{
    $out = [];
    foreach ($rows as $r) {
        $out[$r['id']] = ($r['avg_cost'] ?? 0) > 0 ? $r['score'] / ($r['avg_cost'] * 100) : null;
    }
    return $out;
}

Router::get('/tasks', static function (): void {
    $tracks = Catalog::tracks();
    $f = [
        'track' => isset($tracks[pub_q('track', 40)]) ? pub_q('track', 40) : '',
        'difficulty' => in_array(pub_q('difficulty', 1), ['1', '2', '3', '4'], true) ? (int) pub_q('difficulty', 1) : 0,
        'type' => in_array(pub_q('type', 10), Tasks::OUTPUT_TYPES, true) ? pub_q('type', 10) : '',
        'q' => pub_q('q', 80),
    ];
    $season = Catalog::season();
    view('pages/tasks', pub_page('pub.tasks.title', 'pub.tasks.meta', 'tasks', [
        'canonical' => url('/tasks', ['track' => $f['track'] ?: null], true),
        'tasks' => Tasks::listPublic($f),
        'filters' => $f,
        'tracks' => $tracks,
        'counts' => Catalog::taskCounts(),
        'best' => Tasks::bestModels($season),
    ]));
});

Router::get('/task/{slug}', static function (string $slug): void {
    $task = Tasks::findPublic($slug);
    if (!$task) {
        abort(404);
    }
    $season = Catalog::season();
    view('pages/task', pub_page('pub.task.title', 'pub.task.meta', 'tasks', [
        'title' => L($task, 'title'),
        'description' => L($task, 'summary') ?: __('pub.task.meta'),
        'canonical' => url('/task/' . $task['slug'], [], true),
        'task' => $task,
        'rubric' => Tasks::rubric($task),
        'checks' => Tasks::checkList($task),
        'grading' => Tasks::grading($task),
        'auto_weight' => Tasks::autoWeight($task),
        'results' => Tasks::results((int) $task['id'], $season),
        'gallery' => Tasks::gallery((int) $task['id']),
    ]));
});

Router::get('/run/{id:\d+}', static function (string $id): void {
    $run = Runs::findPublic((int) $id);
    if (!$run) {
        abort(404);
    }
    $tab = pub_q('tab', 20);
    view('pages/run', pub_page('pub.run.title', 'pub.run.meta', 'tasks', [
        'title' => __('pub.run.title_full', ['model' => $run['model_name'], 'task' => L($run, 'title')]),
        'description' => __('pub.run.meta_full', ['model' => $run['model_name'], 'task' => L($run, 'title'), 'score' => fmt_score($run['final_score'])]),
        'canonical' => url('/run/' . (int) $run['id'], [], true),
        'page_js' => ['js/run.js'],
        'run' => $run,
        'tab' => $tab,
        'attempts' => Runs::attempts($run),
        'judgments' => Runs::judgments($run),
        'checks' => Runs::checks($run),
        'answer' => $run['output_type'] === 'answer' ? Runs::answer($run) : null,
        'transcript' => $run['output_type'] === 'agent' ? Runs::transcript($run) : null,
        'shot' => Runs::shotUrl($run['screenshot']),
        'shot_mobile' => Runs::shotUrl($run['screenshot_mobile']),
    ]));
});

Router::get('/methodology', static function (): void {
    view('pages/methodology', pub_page('pub.meth.title', 'pub.meth.meta', 'methodology', [
        'judges' => Models::judges(),
        'tracks' => Catalog::tracks(),
        'counts' => Catalog::taskCounts(),
    ]));
});

Router::get('/value', static function (): void {
    $season = pub_season();
    $rows = Leaderboard::rows($season);
    view('pages/value', pub_page('pub.value.title', 'pub.value.meta', 'value', [
        'canonical' => url('/value', [], true),
        'page_js' => ['vendor/chart.umd.min.js', 'js/value.js'],
        'rows' => $rows,
        'season' => $season,
        'frontier' => Models::paretoIds($rows, 'avg_cost'),
        'frontier_latency' => Models::paretoIds($rows, 'avg_latency_ms'),
        'values' => pub_value_scores($rows),
    ]));
});

Router::get('/changelog', static function (): void {
    view('pages/changelog', pub_page('pub.log.title', 'pub.log.meta', '', [
        'entries' => DB::all('SELECT id, version, title_ar, title_en, body_ar, body_en, published_at FROM changelog WHERE published_at <= ? ORDER BY published_at DESC, id DESC LIMIT 200', [now()]),
    ]));
});

Router::get('/about', static function (): void {
    view('pages/about', pub_page('pub.about.title', 'pub.about.meta', '', [
        'stats' => Leaderboard::stats(),
        'judges' => Models::judges(),
    ]));
});

/** Request form limits: 5 accepted submissions per hour per visitor. */
const PUB_REQUEST_MAX = 5;
const PUB_REQUEST_WINDOW = 3600;

Router::get('/request', static function (): void {
    pub_request_view();
});

Router::post('/request', static function (): void {
    csrf_check();
    if (!setting('requests_enabled')) {
        pub_request_view([], 403);
        return;
    }
    $data = [
        'name' => trim((string) (is_string($_POST['name'] ?? null) ? $_POST['name'] : '')),
        'or_id' => trim((string) (is_string($_POST['or_id'] ?? null) ? $_POST['or_id'] : '')),
        'email' => trim((string) (is_string($_POST['email'] ?? null) ? $_POST['email'] : '')),
        'note' => trim((string) (is_string($_POST['note'] ?? null) ? $_POST['note'] : '')),
    ];
    // Honeypot: real visitors never see or fill this field. Pretend success, store nothing.
    if (($_POST['website'] ?? '') !== '') {
        flash('success', __('pub.req.thanks'));
        redirect('/request');
    }
    $key = 'model-request:' . ip_hash();
    if (RateLimit::remaining($key, PUB_REQUEST_MAX, PUB_REQUEST_WINDOW) <= 0) {
        pub_request_view(['form' => $data, 'error' => __('pub.req.err_rate')], 429);
        return;
    }
    $errors = pub_request_validate($data);
    if ($errors) {
        pub_request_view(['form' => $data, 'errors' => $errors, 'error' => __('pub.req.err_fix')], 422);
        return;
    }
    rate_limit($key, PUB_REQUEST_MAX, PUB_REQUEST_WINDOW);
    DB::insert('model_requests', [
        'name' => $data['name'],
        'or_id' => $data['or_id'] !== '' ? $data['or_id'] : null,
        'email' => $data['email'] !== '' ? $data['email'] : null,
        'note' => $data['note'] !== '' ? $data['note'] : null,
        'ip_hash' => ip_hash(),
    ]);
    flash('success', __('pub.req.thanks'));
    redirect('/request');
});

/** Validation errors keyed by field. */
function pub_request_validate(array $d): array
{
    $err = [];
    $len = static fn(string $s) => mb_strlen($s);
    if ($d['name'] === '' || $len($d['name']) > 190) {
        $err['name'] = __('pub.req.err_name');
    }
    if ($d['or_id'] !== '' && ($len($d['or_id']) > 190 || !preg_match('#^[A-Za-z0-9][A-Za-z0-9._\-]*/[A-Za-z0-9][A-Za-z0-9._:\-]*$#', $d['or_id']))) {
        $err['or_id'] = __('pub.req.err_or_id');
    }
    if ($d['email'] !== '' && ($len($d['email']) > 190 || !filter_var($d['email'], FILTER_VALIDATE_EMAIL))) {
        $err['email'] = __('pub.req.err_email');
    }
    if ($len($d['note']) > 2000) {
        $err['note'] = __('pub.req.err_note');
    }
    return $err;
}

/** Render the request page (optionally with an HTTP status for errors). */
function pub_request_view(array $vars = [], int $status = 200): void
{
    if ($status !== 200) {
        http_response_code($status);
    }
    view('pages/request', pub_page('pub.req.title', 'pub.req.meta', '', $vars + [
        'enabled' => (bool) setting('requests_enabled'),
        'form' => ['name' => '', 'or_id' => '', 'email' => '', 'note' => ''],
        'errors' => [],
        'error' => $status === 403 ? __('pub.req.disabled') : null,
        'recent' => DB::val("SELECT COUNT(*) FROM model_requests WHERE created_at >= ?", [now(-86400 * 30)]),
    ]));
}
