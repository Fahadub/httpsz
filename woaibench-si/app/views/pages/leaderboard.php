<?php
/**
 * Leaderboard. Vars: rows (Models::rank output), tracks, track, season, seasons, stats.
 */
defined('WB') || exit;

$isTrack = $track !== '';
$cur = $isTrack ? $tracks[$track] : null;
[$lo, $hi] = pub_bar_domain($rows);
$ranked = array_filter($rows, static fn($r) => $r['d_rank'] !== null);
$providers = [];
foreach ($rows as $r) {
    $providers[$r['provider']] = $r['provider_name'] ?: $r['provider'];
}
asort($providers);
$minCov = (float) setting('min_coverage', 0.8);
$exportQ = ['track' => $track ?: null, 'season' => $season !== WB\Data\Catalog::season() ? $season : null];
$cols = [
    'arena' => __('pub.lb.col_arena'), 'consistency' => __('pub.lb.col_consistency'), 'coverage' => __('pub.lb.col_coverage'),
    'cost' => __('pub.lb.col_cost'), 'latency' => __('pub.lb.col_latency'), 'tracks' => __('pub.lb.col_tracks'),
];
?>
<section class="section pub-hero">
  <div class="container-wide">
    <div class="pub-hero-grid">
      <div class="stack-sm">
        <p class="eyebrow"><?= _e('pub.lb.eyebrow', ['season' => $season]) ?></p>
        <h1 class="t-h1 t-balance"><?php if ($isTrack): ?><span class="pub-h1-track" style="--track:<?= e($cur['color']) ?>" aria-hidden="true"><?= ui_icon($cur['icon'], 22) ?></span> <?= e(L($cur, 'name')) ?><?php else: ?><?= _e('pub.lb.h1') ?><?php endif; ?></h1>
        <p class="t-lead t-muted pub-lead"><?= $isTrack ? e(L($cur, 'desc')) : _e('pub.lb.lead') ?></p>
      </div>
      <?php if ($rows): ?>
      <div class="kpi-row pub-kpis">
        <?= ui_stat(fmt_num($stats['models_rated']), __('pub.lb.kpi_models'), 'trophy') ?>
        <?= ui_stat(fmt_num($stats['tasks']), __('pub.lb.kpi_tasks'), 'list', (int) $stats['tasks_holdout'] > 0 ? __('pub.lb.kpi_tasks_hint', ['n' => $stats['tasks_holdout']]) : '') ?>
        <?= ui_stat(fmt_num($stats['runs_scored']), __('pub.lb.kpi_runs'), 'bolt') ?>
        <?= ui_stat(fmt_num($stats['judgments']), __('pub.lb.kpi_judgments'), 'scale') ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<section class="section pub-section-tight">
  <div class="container-wide stack">
    <nav class="pub-chips" aria-label="<?= _e('pub.lb.tracks_nav') ?>">
      <a class="chip<?= !$isTrack ? ' active' : '' ?>" href="<?= e(url('/leaderboard', ['season' => $exportQ['season']])) ?>"<?= !$isTrack ? ' aria-current="page"' : '' ?>><?= ui_icon('layers', 14) ?><span><?= _e('pub.lb.overall') ?></span></a>
      <?php foreach ($tracks as $slug => $t): ?>
        <a class="chip<?= $track === $slug ? ' active' : '' ?>" style="--track:<?= e($t['color']) ?>" href="<?= e(url('/leaderboard', ['track' => $slug, 'season' => $exportQ['season']])) ?>"<?= $track === $slug ? ' aria-current="page"' : '' ?>><?= ui_icon($t['icon'], 14) ?><span><?= e(L($t, 'name')) ?></span></a>
      <?php endforeach; ?>
    </nav>

    <?php if (!$rows): ?>
      <?= ui_empty(__('pub.lb.empty_title'), __('pub.lb.empty_text'), 'trophy', '<div class="cluster pub-center"><a class="btn btn-primary" href="' . e(url('/methodology')) . '">' . _e('pub.lb.empty_cta') . '</a><a class="btn btn-ghost" href="' . e(url('/request')) . '">' . _e('nav.request') . '</a></div>') ?>
    <?php else: ?>

    <div class="pub-collapse" data-collapse>
    <button class="btn btn-ghost btn-sm pub-collapse-toggle" type="button" aria-expanded="false" aria-controls="lb-filters"><?= ui_icon('filter', 16) ?><span><?= _e('pub.lb.filters') ?></span></button>
    <div class="pub-toolbar card card-glass pub-collapse-body" id="lb-filters" data-lb-toolbar>
      <label class="input-group pub-search">
        <?= ui_icon('search', 16) ?>
        <span class="sr-only"><?= _e('pub.lb.search') ?></span>
        <input class="input" type="search" placeholder="<?= _e('pub.lb.search') ?>" data-lb-search autocomplete="off">
      </label>
      <label class="pub-field-inline">
        <span class="sr-only"><?= _e('pub.lb.vendor') ?></span>
        <select class="select" data-lb-vendor>
          <option value=""><?= _e('pub.lb.all_vendors') ?></option>
          <?php foreach ($providers as $key => $name): ?><option value="<?= e($key) ?>"><?= e($name) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label class="switch pub-switch">
        <input type="checkbox" data-lb-open>
        
        <span><?= _e('pub.lb.open_only') ?></span>
      </label>
      <details class="pub-menu" data-lb-cols>
        <summary class="btn btn-ghost btn-sm"><?= ui_icon('grid', 16) ?><span><?= _e('pub.lb.columns') ?></span></summary>
        <div class="pub-menu-panel card">
          <?php foreach ($cols as $key => $label): ?>
            <label class="checkbox"><input type="checkbox" value="<?= e($key) ?>" checked> <span><?= e($label) ?></span></label>
          <?php endforeach; ?>
        </div>
      </details>
      <form class="pub-season" method="get" action="<?= e(url('/leaderboard')) ?>">
        <?php if ($isTrack): ?><input type="hidden" name="track" value="<?= e($track) ?>"><?php endif; ?>
        <label><span class="sr-only"><?= _e('pub.lb.season') ?></span>
          <select class="select" name="season" data-autosubmit>
            <?php foreach ($seasons as $s): ?><option value="<?= e($s) ?>"<?= $s === $season ? ' selected' : '' ?>><?= _e('pub.lb.season_opt', ['s' => $s]) ?></option><?php endforeach; ?>
          </select>
        </label>
        <noscript><button class="btn btn-sm btn-outline" type="submit"><?= _e('pub.lb.go') ?></button></noscript>
      </form>
      <div class="pub-downloads cluster">
        <a class="btn btn-ghost btn-sm" href="<?= e(url('/export/leaderboard.csv', $exportQ)) ?>" download><?= ui_icon('download', 16) ?><span>CSV</span></a>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('/api/v1/leaderboard', $exportQ)) ?>"><?= ui_icon('api', 16) ?><span>JSON</span></a>
      </div>
    </div>
    <button class="btn btn-ghost btn-sm pub-collapse-toggle" type="button" aria-expanded="false" aria-controls="lb-legend"><?= ui_icon('info', 16) ?><span><?= _e('pub.lb.legend') ?></span></button>
    <div class="legend pub-legend pub-collapse-body" id="lb-legend" aria-label="<?= _e('pub.lb.legend') ?>">
      <span class="legend-item"><i class="pub-swatch pub-swatch-tie"></i><?= _e('pub.lb.legend_tie_leader') ?></span>
      <span class="legend-item pub-legend-link"><i class="pub-swatch pub-swatch-link"></i><?= _e('pub.lb.legend_tie_above') ?></span>
      <span class="legend-item"><span class="pub-mini-bar"><i></i><b></b></span><?= _e('pub.lb.legend_bar', ['lo' => fmt_num($lo), 'hi' => fmt_num($hi)]) ?></span>
      <span class="legend-item"><?= ui_badge(__('common.provisional'), 'muted') ?><?= _e('pub.lb.legend_prov', ['pct' => fmt_num($minCov * 100)]) ?></span>
    </div>
    </div>

    <div class="pub-lb-scroll">
      <table class="table lb-table" id="lb-table" data-lb-table>
        <caption class="sr-only"><?= $isTrack ? _e('pub.lb.caption_track', ['track' => L($cur, 'name'), 'season' => $season]) : _e('pub.lb.caption', ['season' => $season]) ?></caption>
        <thead>
          <tr>
            <th class="col-pick" scope="col"><span class="sr-only"><?= _e('pub.lb.pick') ?></span></th>
            <th class="col-rank" scope="col" data-sort="rank" data-lb-sort="rank" aria-sort="ascending"><button type="button"><?= _e('pub.lb.col_rank') ?></button></th>
            <th class="col-model" scope="col" data-sort="name" data-lb-sort="name"><button type="button"><?= _e('common.model') ?></button></th>
            <th class="col-score" scope="col" data-sort="score" data-lb-sort="score"><button type="button"><?= $isTrack ? _e('pub.lb.col_track_score') : _e('pub.lb.col_score') ?></button></th>
            <th class="col-arena num" scope="col" data-sort="arena" data-lb-sort="arena"><button type="button" data-tip="<?= _e('pub.lb.tip_arena') ?>"><?= _e('pub.lb.col_arena') ?></button></th>
            <th class="col-consistency num" scope="col" data-sort="consistency" data-lb-sort="consistency"><button type="button" data-tip="<?= _e('pub.lb.tip_consistency') ?>"><?= _e('pub.lb.col_consistency_short') ?></button></th>
            <th class="col-coverage num" scope="col" data-sort="coverage" data-lb-sort="coverage"><button type="button" data-tip="<?= _e('pub.lb.tip_coverage') ?>"><?= _e('pub.lb.col_coverage_short') ?></button></th>
            <th class="col-cost num" scope="col" data-sort="cost" data-lb-sort="cost"><button type="button" data-tip="<?= _e('pub.lb.tip_cost') ?>"><?= _e('pub.lb.col_cost') ?></button></th>
            <th class="col-latency num" scope="col" data-sort="latency" data-lb-sort="latency"><button type="button" data-tip="<?= _e('pub.lb.tip_latency') ?>"><?= _e('pub.lb.col_latency') ?></button></th>
            <?php foreach ($tracks as $slug => $t): ?>
              <th class="col-tracks col-heat<?= $track === $slug ? ' is-current' : '' ?>" scope="col" data-sort="t-<?= e($slug) ?>" data-lb-sort="t-<?= e($slug) ?>" style="--track:<?= e($t['color']) ?>"><button type="button" data-tip="<?= e(L($t, 'name')) ?>" aria-label="<?= e(L($t, 'name')) ?>"><?= ui_icon($t['icon'], 15) ?></button></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $i => $r):
            // Provisional in this view = scored but not ranked here (overall or track coverage below min_coverage).
            $prov = !empty($r['d_provisional']) || ($r['d_rank'] === null && $r['d_score'] !== null);
            $cov = $isTrack ? ($r['tracks'][$track]['coverage'] ?? $r['coverage']) : $r['coverage'];
            $cls = ['lb-row'];
            if ($prov) { $cls[] = 'is-provisional'; }
            if ($r['tied_leader']) { $cls[] = 'is-tied-leader'; }
            if ($r['tied_above']) { $cls[] = 'is-tied-above'; }
            if ($r['d_rank'] === 1) { $cls[] = 'is-leader'; }
            $search = mb_strtolower($r['name'] . ' ' . ($r['provider_name'] ?? '') . ' ' . $r['provider'] . ' ' . $r['slug']);
            $arena = $r['arena'];
        ?>
          <tr class="<?= e(implode(' ', $cls)) ?>" data-provider="<?= e($r['provider']) ?>" data-open="<?= $r['open_weights'] ? '1' : '0' ?>" data-search="<?= e($search) ?>" data-slug="<?= e($r['slug']) ?>" data-order="<?= (int) $i ?>">
            <td class="col-pick"><label class="pub-pick"><input type="checkbox" class="checkbox" value="<?= e($r['slug']) ?>" data-lb-pick aria-label="<?= _e('pub.lb.pick_model', ['name' => $r['name']]) ?>"></label></td>
            <td class="col-rank" data-v="<?= $r['d_rank'] ?? '' ?>">
              <?php if ($r['d_rank'] !== null): ?>
                <?= ui_rank($r['d_rank'], $r['d_stat_rank']) ?>
                <?php if ($r['d_stat_rank'] !== null && $r['d_stat_rank'] !== $r['d_rank']): ?><span class="pub-statrank t-xs t-muted" dir="ltr" data-tip="<?= _e('pub.lb.tip_statrank', ['n' => $r['d_stat_rank']]) ?>">≈<?= (int) $r['d_stat_rank'] ?></span><?php endif; ?>
              <?php else: ?>
                <span class="rank rank-none" aria-label="<?= _e('common.provisional') ?>">—</span>
              <?php endif; ?>
            </td>
            <td class="col-model" data-v="<?= e(mb_strtolower($r['name'])) ?>">
              <div class="pub-model-cell">
                <?= ui_model_chip($r) ?>
                <span class="pub-model-flags">
                  <?php if ($r['open_weights']): ?><?= ui_badge(__('pub.open_short'), 'green', null, __('common.open_weights')) ?><?php endif; ?>
                  <?php if ($prov): ?><?= ui_badge(__('common.provisional'), 'muted', null, __('common.provisional_tip')) ?><?php endif; ?>
                </span>
              </div>
            </td>
            <td class="col-score" data-v="<?= $r['d_score'] ?? '' ?>" data-label="<?= $isTrack ? _e('pub.lb.col_track_score') : _e('pub.lb.col_score') ?>">
              <div class="pub-score-cell">
                <?= ui_score($r['d_score'], $r['d_ci_low'], $r['d_ci_high'], 'sm') ?>
                <?= pub_bar($r['d_score'], $lo, $hi, $r['d_ci_low'], $r['d_ci_high']) ?>
              </div>
            </td>
            <td class="col-arena num" data-v="<?= $arena ? $arena['rating'] : '' ?>" data-label="<?= _e('pub.lb.col_arena') ?>">
              <?php if ($arena): ?><span class="t-num" data-tip="<?= _e('pub.lb.tip_arena_row', ['lo' => fmt_num($arena['ci_low']), 'hi' => fmt_num($arena['ci_high']), 'n' => fmt_num($arena['votes'])]) ?>"><?= e(fmt_num($arena['rating'])) ?></span><?php else: ?><span class="t-faint">—</span><?php endif; ?>
            </td>
            <td class="col-consistency num" data-v="<?= $r['consistency'] ?? '' ?>" data-label="<?= _e('pub.lb.col_consistency') ?>"><span class="t-num"><?= e(fmt_score($r['consistency'])) ?></span></td>
            <td class="col-coverage num" data-v="<?= $cov ?? '' ?>" data-label="<?= _e('pub.lb.col_coverage') ?>"><span class="t-num<?= ($cov ?? 0) < $minCov ? ' pub-warn' : '' ?>"><?= $cov !== null ? e(fmt_num((float) $cov * 100)) . '%' : '—' ?></span></td>
            <td class="col-cost num" data-v="<?= $r['avg_cost'] ?? '' ?>" data-label="<?= _e('pub.lb.col_cost') ?>"><span class="t-num" dir="ltr"><?= e(fmt_money($r['avg_cost'])) ?></span></td>
            <td class="col-latency num" data-v="<?= $r['avg_latency_ms'] ?? '' ?>" data-label="<?= _e('pub.lb.col_latency') ?>"><span class="t-num" dir="ltr"><?= e(fmt_ms($r['avg_latency_ms'])) ?></span></td>
            <?php foreach ($tracks as $slug => $t):
                $ts = $r['tracks'][$slug] ?? null;
                $tip = L($t, 'name') . ': ' . ($ts ? fmt_score($ts['score']) . ($ts['ci_low'] !== null ? ' (' . "\u{2066}" . fmt_score($ts['ci_low']) . '–' . fmt_score($ts['ci_high']) . "\u{2069}" . ')' : '') . ' · ' . __('pub.lb.n_tasks', ['n' => $ts['n_tasks']]) : __('pub.lb.not_scored'));
            ?>
              <td class="col-tracks col-heat<?= $track === $slug ? ' is-current' : '' ?>" data-v="<?= $ts ? $ts['score'] : '' ?>"><span class="lb-heat-icon" style="--track:<?= e($t['color']) ?>" aria-hidden="true"><?= ui_icon($t['icon'], 11) ?></span><?= ui_heat_cell($ts['score'] ?? null, $tip) ?></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
          <tr class="lb-none" hidden><td colspan="<?= 9 + count($tracks) ?>"><?= ui_empty(__('pub.lb.no_match'), __('pub.lb.no_match_text'), 'search') ?></td></tr>
        </tbody>
      </table>
    </div>

    <p class="t-small t-muted pub-count" data-lb-count aria-live="polite"><?= _e('pub.lb.showing', ['n' => count($rows), 'total' => count($rows)]) ?></p>

    <div class="pub-note card card-pad">
      <div class="pub-note-icon"><?= ui_icon('flask', 22) ?></div>
      <div class="stack-sm">
        <p class="t-small"><?= pub_rich('pub.lb.note', ['n' => count($tracks), 'runs' => WB\Data\Catalog::runsPerTask($season), 'iters' => fmt_num(setting('bootstrap_iters'))]) ?></p>
        <p class="t-xs t-muted"><?= _e('pub.lb.updated', ['when' => fmt_date($stats['last_updated'], 'relative'), 'date' => fmt_date($stats['last_updated'], 'datetime')]) ?> · <a href="<?= e(url('/methodology')) ?>"><?= _e('pub.lb.read_method') ?></a></p>
      </div>
    </div>
    <?php endif; ?>
  </div>
</section>

<div class="pub-comparebar" data-lb-comparebar hidden>
  <div class="container-wide pub-comparebar-inner">
    <span class="t-small"><?= ui_icon('scale', 16) ?> <span data-lb-pick-count></span></span>
    <div class="cluster">
      <button class="btn btn-ghost btn-sm" type="button" data-lb-clear><?= _e('pub.lb.clear') ?></button>
      <a class="btn btn-primary btn-sm" href="<?= e(url('/compare')) ?>" data-lb-compare data-base="<?= e(url('/compare')) ?>"><?= _e('pub.lb.compare_selected') ?></a>
    </div>
  </div>
</div>
<?= pub_js_strings() ?>
