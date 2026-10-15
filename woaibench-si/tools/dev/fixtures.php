<?php
/**
 * DEV ONLY — fills a LOCAL database with clearly fictional sample data so pages can be built and
 * screenshotted before any real benchmark has run. Never run this against production.
 *
 *   WB_DB_NAME=wb_public php tools/dev/fixtures.php            (refuses unless db host is local)
 *
 * Model and vendor names are invented ("Aster Labs", "Borealis AI", …) so fixture screenshots can
 * never be mistaken for real benchmark results.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
define('WB', true);
require dirname(__DIR__, 2) . '/app/bootstrap.php';

$host = (string) Config::get('db.host');
if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "Refusing: fixtures only run against a local database (db.host = {$host}).\n");
    exit(1);
}

mt_srand(20261015);
$rand = static fn(float $a, float $b): float => $a + (mt_rand() / mt_getrandmax()) * ($b - $a);
$season = (string) setting('season');

DB::exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['arena_votes', 'arena_pairs', 'arena_ratings', 'score_history', 'model_scores', 'judgments', 'runs', 'campaigns', 'tasks', 'tracks', 'models', 'changelog', 'model_requests', 'gpus'] as $t) {
    DB::exec("TRUNCATE TABLE `$t`");
}
DB::exec('SET FOREIGN_KEY_CHECKS = 1');

// Tracks from the real seed.
$tracks = json_decode((string) file_get_contents(WB_ROOT . '/sql/seed/tracks.json'), true);
$trackIds = [];
foreach ($tracks as $t) {
    $trackIds[$t['slug']] = DB::insert('tracks', [
        'slug' => $t['slug'], 'name_ar' => $t['name_ar'], 'name_en' => $t['name_en'], 'desc_ar' => $t['desc_ar'],
        'desc_en' => $t['desc_en'], 'icon' => $t['icon'], 'color' => $t['color'], 'sort' => $t['sort'],
        'auto_weight' => $t['auto_weight'],
    ]);
}

// Two fictional tasks per track (one hold-out every few).
$taskIds = [];
$sampleHtml = static fn(string $title, string $hue) => "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>{$title}</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;font-family:system-ui;background:radial-gradient(circle at 30% 20%,hsl({$hue} 70% 30%),#0b0b12);color:#fff}h1{font-size:42px;letter-spacing:-1px}.card{padding:32px 40px;border-radius:20px;background:rgba(255,255,255,.08);backdrop-filter:blur(6px);animation:f 3s ease-in-out infinite alternate}@keyframes f{to{transform:translateY(-10px)}}</style></head><body><div class=\"card\"><h1>{$title}</h1><p>Fixture output · hue {$hue}</p><button>Action</button></div></body></html>";
$i = 0;
foreach ($tracks as $t) {
    for ($k = 1; $k <= 2; $k++) {
        $i++;
        $slug = $t['slug'] . '-sample-' . $k;
        $type = in_array($t['slug'], ['algo', 'debug', 'secure'], true) ? 'js' : ($t['slug'] === 'reasoning' ? 'answer' : ($t['slug'] === 'agentic' ? 'agent' : 'html'));
        $taskIds[$slug] = [DB::insert('tasks', [
            'slug' => $slug, 'track_id' => $trackIds[$t['slug']],
            'title_en' => $t['name_en'] . ' sample task ' . $k, 'title_ar' => 'مهمة تجريبية ' . $k . ' — ' . $t['name_ar'],
            'summary_en' => 'Fictional fixture task used for local UI development.', 'summary_ar' => 'مهمة وهمية لتطوير الواجهة محليًا.',
            'prompt' => 'FIXTURE PROMPT for ' . $slug . ': build something delightful.',
            'output_type' => $type, 'difficulty' => ($i % 4) + 1,
            'rubric_json' => to_json([
                ['key' => 'correctness', 'label_en' => 'Correctness', 'label_ar' => 'الصحة', 'weight' => 3, 'guide' => 'Does it work?'],
                ['key' => 'quality', 'label_en' => 'Code quality', 'label_ar' => 'جودة الشيفرة', 'weight' => 2, 'guide' => 'Readable?'],
                ['key' => 'design', 'label_en' => 'Design', 'label_ar' => 'التصميم', 'weight' => 2, 'guide' => 'Polish'],
            ]),
            'checks_json' => to_json(['static' => [['id' => 'viewport', 'type' => 'viewport', 'weight' => 1, 'label_en' => 'Viewport meta', 'label_ar' => 'وسم viewport']], 'browser' => [['id' => 'no_errors', 'type' => 'no_errors', 'weight' => 2, 'label_en' => 'No JS errors', 'label_ar' => 'لا أخطاء JS']]]),
            'visibility' => ($i % 5 === 0) ? 'holdout' : 'public', 'season' => $season, 'source' => 'seed',
        ]), $type, $t['slug']];
    }
}

// Fictional vendors & models.
$vendors = [
    ['aster', 'Aster Labs', '#E0A458'], ['borealis', 'Borealis AI', '#5FA8F5'], ['cobalt', 'Cobalt Systems', '#7F8CF7'],
    ['dune', 'Dune Research', '#E07A5F'], ['ember', 'Ember Open', '#59C27E'], ['fjord', 'Fjord Intelligence', '#3FC7B4'],
];
$modelDefs = [
    ['Aster Opus X', 0, 0, 82, 15, 75, null], ['Aster Swift 3', 0, 0, 71, 3, 15, null],
    ['Borealis Prime 4', 1, 0, 80, 10, 40, null], ['Borealis Mini 4', 1, 0, 66, 0.4, 1.6, null],
    ['Cobalt Coder 2', 2, 0, 76, 5, 20, null], ['Cobalt Flash', 2, 0, 63, 0.3, 1.2, null],
    ['Dune Reasoner', 3, 0, 74, 2, 8, null], ['Ember 70B', 4, 1, 64, 0.6, 0.8, 70], ['Ember 8B', 4, 1, 49, 0.05, 0.08, 8],
    ['Fjord MoE 235B', 5, 1, 72, 0.4, 1.2, 235], ['Fjord 32B Coder', 5, 1, 66, 0.2, 0.6, 32], ['Fjord 14B', 5, 1, 57, 0.1, 0.3, 14],
    ['Dune Lite', 3, 0, 52, 0.1, 0.4, null], ['Cobalt Ultra', 2, 0, 79, 12, 60, null],
];
$models = [];
foreach ($modelDefs as $idx => [$name, $v, $open, $base, $pin, $pout, $params]) {
    [$vk, $vn, $vc] = $vendors[$v];
    $slug = slugify($name);
    $id = DB::insert('models', [
        'slug' => $slug, 'or_id' => $vk . '/' . $slug, 'name' => $name, 'provider' => $vk, 'provider_name' => $vn,
        'description' => 'Fictional fixture model for local development.', 'open_weights' => $open,
        'params_b' => $params, 'active_params_b' => $params === 235 ? 22 : $params, 'context_length' => [32768, 128000, 200000, 1000000][$idx % 4],
        'price_in' => $pin, 'price_out' => $pout, 'supports_vision' => $idx % 3 !== 2 ? 1 : 0, 'supports_tools' => 1,
        'release_date' => gmdate('Y-m-d', time() - 86400 * (20 + $idx * 11)), 'is_active' => 1, 'color' => $vc,
    ]);
    $models[] = ['id' => $id, 'base' => $base, 'pin' => $pin, 'pout' => $pout, 'name' => $name, 'open' => $open];
}
// Judges (fictional, from three different vendors).
$judges = [];
foreach ([['Aster Judge', 'aster'], ['Borealis Judge', 'borealis'], ['Cobalt Judge', 'cobalt'], ['Fjord Judge', 'fjord']] as $jx => [$jn, $vk]) {
    $judges[] = ['id' => DB::insert('models', [
        'slug' => slugify($jn), 'or_id' => $vk . '/' . slugify($jn), 'name' => $jn, 'provider' => $vk,
        'provider_name' => ucfirst($vk), 'is_active' => 0, 'is_judge' => 1, 'judge_order' => $jx + 1, 'supports_vision' => 1,
        'price_in' => 3, 'price_out' => 15,
    ]), 'provider' => $vk];
}

$campaignId = DB::insert('campaigns', [
    'name' => 'Fixture season run', 'season' => $season, 'status' => 'done', 'runs_per_task' => 3,
    'model_ids' => to_json(array_column($models, 'id')), 'task_ids' => to_json(array_map(static fn($t) => $t[0], array_values($taskIds))),
    'started_at' => now(-86400 * 3), 'finished_at' => now(-3600), 'spent_cost' => 0,
]);

// Runs + judgments + aggregate scores (simple means; the real Aggregator replaces this).
$trackBias = [];
foreach ($models as $m) {
    foreach ($trackIds as $ts => $tid) {
        $trackBias[$m['id']][$ts] = $rand(-9, 9);
    }
}
$spent = 0.0;
foreach ($models as $mi => $m) {
    $perTrack = [];
    $allFinal = [];
    $costs = [];
    $lat = [];
    $tok = [];
    $sds = [];
    $coverageSkip = $mi === count($models) - 1; // last model: provisional
    $n = 0;
    foreach ($taskIds as $slug => [$taskId, $type, $ts]) {
        $n++;
        if ($coverageSkip && $n > 10) {
            continue;
        }
        $finals = [];
        for ($a = 1; $a <= 3; $a++) {
            $auto = clamp($m['base'] + $trackBias[$m['id']][$ts] + $rand(-12, 12), 0, 100);
            $judge = clamp($m['base'] + $trackBias[$m['id']][$ts] + $rand(-8, 8) - 4, 0, 100);
            $final = round(0.4 * $auto + 0.6 * $judge, 2);
            $ptoks = (int) $rand(600, 1800);
            $ctoks = (int) $rand(1500, 9000);
            $cost = ($ptoks * $m['pin'] + $ctoks * $m['pout']) / 1e6;
            $ms = (int) $rand(4000, 90000);
            $spent += $cost;
            $runId = DB::insert('runs', [
                'campaign_id' => $campaignId, 'model_id' => $m['id'], 'task_id' => $taskId, 'attempt' => $a, 'status' => 'scored',
                'prompt_tokens' => $ptoks, 'completion_tokens' => $ctoks, 'cost_usd' => round($cost, 6), 'latency_ms' => $ms,
                'finish_reason' => 'stop', 'raw_output' => "```html\n" . $sampleHtml($m['name'], (string) (($m['id'] * 47 + $a * 13) % 360)) . "\n```",
                'code' => $type === 'html' || $type === 'agent' ? $sampleHtml($m['name'], (string) (($m['id'] * 47 + $a * 13) % 360)) : "function solve(xs){ return xs.slice().sort((a,b)=>a-b); }",
                'code_bytes' => 900, 'verified' => 1, 'verified_by' => 'station',
                'static_json' => to_json([['id' => 'viewport', 'type' => 'viewport', 'pass' => true, 'score' => 1, 'weight' => 1, 'label_en' => 'Viewport meta', 'label_ar' => 'وسم viewport', 'detail' => '']]),
                'browser_json' => to_json(['checks' => [['id' => 'no_errors', 'type' => 'no_errors', 'pass' => $auto > 40, 'score' => $auto > 40 ? 1 : 0, 'detail' => $auto > 40 ? '0 errors' : '2 errors']], 'metrics' => ['load_ms' => (int) $rand(80, 900), 'fps' => (int) $rand(40, 60), 'js_errors' => $auto > 40 ? 0 : 2]]),
                'auto_score' => round($auto, 2), 'judge_score' => round($judge, 2), 'judge_spread' => round($rand(2, 30), 2),
                'final_score' => $final, 'contested' => 0, 'finished_at' => now(-(int) $rand(3600, 86400 * 3)),
            ]);
            foreach (array_slice(array_values(array_filter($judges, static fn($j) => true)), 0, 3) as $j) {
                DB::insert('judgments', [
                    'run_id' => $runId, 'judge_model_id' => $j['id'], 'status' => 'done', 'score' => round(clamp($judge + $rand(-6, 6), 0, 100), 2),
                    'criteria_json' => to_json(['correctness' => round($rand(4, 9), 1), 'quality' => round($rand(4, 9), 1), 'design' => round($rand(4, 9), 1)]),
                    'rationale' => to_json(['summary' => 'Fixture rationale: solid structure, minor polish issues.', 'strengths' => ['Clear layout', 'Works on mobile'], 'weaknesses' => ['Generic palette']]),
                    'used_vision' => 1, 'prompt_tokens' => 3000, 'completion_tokens' => 400, 'cost_usd' => 0.015, 'latency_ms' => 6000,
                ]);
            }
            $finals[] = $final;
            $costs[] = $cost;
            $lat[] = $ms;
            $tok[] = $ptoks + $ctoks;
        }
        $perTrack[$ts][] = mean($finals);
        $allFinal[] = mean($finals);
        $sds[] = stddev($finals);
    }
    $trackScores = [];
    foreach ($perTrack as $ts => $list) {
        $trackScores[$ts] = mean($list);
        $half = $rand(2.5, 7);
        DB::insert('model_scores', [
            'model_id' => $m['id'], 'season' => $season, 'scope' => 'track:' . $ts, 'score' => round($trackScores[$ts], 2),
            'ci_low' => round(max(0, $trackScores[$ts] - $half), 2), 'ci_high' => round(min(100, $trackScores[$ts] + $half), 2),
            'n_tasks' => count($list), 'n_runs' => count($list) * 3, 'coverage' => 1,
        ]);
    }
    $overall = mean($trackScores);
    $half = $rand(1.2, 2.8);
    $cov = $coverageSkip ? round(10 / count($taskIds), 2) : 1.0;
    DB::insert('model_scores', [
        'model_id' => $m['id'], 'season' => $season, 'scope' => 'overall', 'score' => round($overall, 2),
        'ci_low' => round($overall - $half, 2), 'ci_high' => round($overall + $half, 2), 'n_tasks' => count($allFinal),
        'n_runs' => count($allFinal) * 3, 'auto_avg' => round($overall + $rand(-3, 3), 2), 'judge_avg' => round($overall + $rand(-3, 3), 2),
        'consistency' => round(100 - mean($sds), 2), 'avg_cost' => round(mean($costs), 6), 'avg_latency_ms' => (int) mean($lat),
        'avg_tokens' => (int) mean($tok), 'coverage' => $cov, 'provisional' => $coverageSkip ? 1 : 0,
    ]);
    for ($d = 45; $d >= 0; $d -= 3) {
        DB::insert('score_history', [
            'model_id' => $m['id'], 'season' => $season, 'score' => round(clamp($overall - $d * 0.06 + $rand(-1.2, 1.2), 0, 100), 2),
            'ci_low' => round($overall - $d * 0.06 - 2.5, 2), 'ci_high' => round($overall - $d * 0.06 + 2.5, 2),
            'recorded_on' => gmdate('Y-m-d', time() - 86400 * $d),
        ]);
    }
}
DB::update('campaigns', ['spent_cost' => round($spent, 4)], 'id = ?', [$campaignId]);

// Ranks (simple: score order among non-provisional; stat_rank by CI overlap).
$rows = DB::all("SELECT model_id, score, ci_low, ci_high, provisional FROM model_scores WHERE season = ? AND scope = 'overall' ORDER BY provisional, score DESC", [$season]);
$pos = 0;
foreach ($rows as $r) {
    $stat = 1;
    foreach ($rows as $o) {
        if (!$o['provisional'] && $o['model_id'] !== $r['model_id'] && (float) $o['ci_low'] > (float) $r['ci_high']) {
            $stat++;
        }
    }
    $rank = $r['provisional'] ? null : ++$pos;
    DB::exec("UPDATE model_scores SET rank_pos = ?, stat_rank = ? WHERE model_id = ? AND season = ? AND scope = 'overall'", [$rank, $r['provisional'] ? null : $stat, $r['model_id'], $season]);
    DB::exec('UPDATE score_history SET rank_pos = ? WHERE model_id = ?', [$rank, $r['model_id']]);
}

// Arena ratings + a few votes.
foreach ($models as $m) {
    $rating = 1000 + ($m['base'] - 65) * 9 + $rand(-25, 25);
    DB::insert('arena_ratings', [
        'model_id' => $m['id'], 'rating' => round($rating, 2), 'ci_low' => round($rating - $rand(15, 40), 2),
        'ci_high' => round($rating + $rand(15, 40), 2), 'votes' => (int) $rand(40, 400), 'wins' => (int) $rand(20, 200),
        'losses' => (int) $rand(20, 200), 'ties' => (int) $rand(5, 40),
    ]);
}

// Changelog, requests, GPUs.
DB::insert('changelog', ['version' => $season . '.1', 'title_en' => 'Season opened', 'title_ar' => 'افتتاح الموسم', 'body_en' => 'Fixture changelog entry.', 'body_ar' => 'مدخل تجريبي في سجل التحديثات.', 'published_at' => now(-86400 * 10)]);
DB::insert('changelog', ['version' => $season . '.2', 'title_en' => 'Judge panel recalibrated', 'title_ar' => 'إعادة معايرة لجنة التحكيم', 'body_en' => 'Fixture changelog entry.', 'body_ar' => 'مدخل تجريبي.', 'published_at' => now(-86400 * 2)]);
DB::insert('model_requests', ['or_id' => 'example/model', 'name' => 'Example model', 'note' => 'Fixture request', 'ip_hash' => str_repeat('a', 64)]);
foreach ([['RTX 4090', 'NVIDIA', 'gpu', 24, 1008, 82.6], ['RTX 4060 Ti 16GB', 'NVIDIA', 'gpu', 16, 288, 22], ['Apple M3 Max 64GB', 'Apple', 'apple', 64, 400, 28], ['CPU (DDR5 dual-channel) 64GB', 'Generic', 'cpu', 64, 80, 2]] as $gi => [$gn, $gv, $gk, $vr, $bw, $tf]) {
    DB::insert('gpus', ['name' => $gn, 'vendor' => $gv, 'kind' => $gk, 'vram_gb' => $vr, 'bandwidth_gbs' => $bw, 'fp16_tflops' => $tf, 'sort' => $gi]);
}
Settings::set('aggregate_last', now());

echo "Fixtures loaded into " . Config::get('db.name') . ": " . count($models) . " models, " . count($taskIds) . " tasks, season {$season}\n";
