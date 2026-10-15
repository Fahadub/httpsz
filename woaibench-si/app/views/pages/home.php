<?php
/**
 * Home page. Variables from home_view_data() in app/routes/10_home.php:
 *   $ranked, $top, $stats, $tracks, $leaders, $tiedFirst, $arenaTop, $changes, $season, $method
 * Must look complete on an empty database (no scores, no votes, no changelog).
 */
defined('WB') || exit;

$hasScores = !empty($top);
$podium = array_slice($ranked, 0, 3);
$updated = !empty($stats['last_updated']) ? fmt_date((string) $stats['last_updated'], 'relative') : '';
$trackCount = count($tracks);
$holdout = (int) ($stats['tasks_holdout'] ?? 0);
$num = static fn(int $n): string => fmt_num($n);
// Brand Studio hero image (Admin → Brand Studio → set as hero): a subtle backdrop behind the hero.
$heroRel = ltrim((string) setting('brand_hero', ''), '/');
$heroImg = $heroRel !== '' && preg_match('#^[A-Za-z0-9_\-./]+$#', $heroRel) && !str_contains($heroRel, '..') && is_file(WB_UPLOADS . '/' . $heroRel)
    ? (string) upload_url($heroRel) : '';
?>
<section class="hero home-hero<?= $heroImg !== '' ? ' has-hero-img' : '' ?>" aria-labelledby="hero-title">
  <?php if ($heroImg !== ''): ?><div class="hero-img" aria-hidden="true" style="background-image:url('<?= e($heroImg) ?>')"></div><?php endif; ?>
  <div class="hero-glow" aria-hidden="true"></div>
  <div class="hero-grid-lines" aria-hidden="true"></div>
  <div class="container-wide hero-layout">
    <div class="hero-copy">
      <a class="hero-kicker reveal" href="<?= e(url($hasScores ? '/leaderboard' : '/methodology')) ?>">
        <?= ui_live_dot() ?>
        <span><?= _e($hasScores ? 'home.kicker_live' : 'home.kicker_calibrating', ['s' => $season]) ?></span>
        <?php if ($hasScores && $updated !== ''): ?><span class="sep" aria-hidden="true"></span><span class="t-faint kicker-updated"><?= _e('home.kicker_updated', ['t' => $updated]) ?></span><?php endif; ?>
        <?= ui_icon('arrow-right', 14) ?>
      </a>
      <h1 class="t-display hero-title reveal" id="hero-title" style="--i:1"><?= ui_em(__('home.hero_title')) ?></h1>
      <p class="t-lead hero-sub reveal" style="--i:2"><?= _e('home.hero_sub', ['runs' => $method['runs'], 'judges' => $method['judges']]) ?></p>
      <div class="cluster hero-ctas reveal" style="--i:3">
        <a class="btn btn-primary btn-lg" href="<?= e(url('/leaderboard')) ?>"><?= _e('home.cta_board') ?><?= ui_icon('arrow-right', 18) ?></a>
        <a class="btn btn-outline btn-lg" href="<?= e(url('/methodology')) ?>"><?= ui_icon('flask', 18) ?><?= _e('home.cta_method') ?></a>
      </div>
      <ul class="hero-proof reveal" style="--i:4">
        <li><b class="ltr"><?= e($method['runs']) ?>×</b><span><?= _e('home.proof_runs') ?></span></li>
        <li><b><?= e($method['judges']) ?></b><span><?= _e('home.proof_judges') ?></span></li>
        <li><b class="ltr">95%</b><span><?= _e('home.proof_ci') ?></span></li>
        <li><b class="ltr">100%</b><span><?= _e('home.proof_free') ?></span></li>
      </ul>
    </div>

    <div class="hero-visual">
      <div class="hero-art" aria-hidden="true"><canvas data-hero-art="dial"></canvas></div>
      <?php if ($podium): ?>
        <div class="podium-card card-glass reveal" style="--i:2">
          <div class="podium-head">
            <span class="eyebrow"><?= _e('home.podium_title') ?></span>
            <a class="link-arrow t-xs" href="<?= e(url('/leaderboard')) ?>"><?= _e('home.podium_view') ?><?= ui_icon('arrow-right', 14) ?></a>
          </div>
          <div class="podium" role="list">
            <?php foreach ([1, 0, 2] as $idx): ?>
              <?php $m = $podium[$idx] ?? null; $p = $idx + 1; ?>
              <div class="podium-col p<?= $p ?>" role="listitem">
                <?php if ($m): ?>
                  <?= ui_provider_avatar((string) $m['provider'], $m['provider_name'] ?? null, $p === 1 ? 48 : 40) ?>
                  <a class="podium-name" href="<?= e(url('/model/' . $m['slug'])) ?>"><?= e($m['name']) ?></a>
                  <span class="podium-provider"><?= e($m['provider_name'] ?? $m['provider']) ?></span>
                  <span class="podium-score"><?= ui_score($m['score'], $m['ci_low'], $m['ci_high'], 'lg') ?></span>
                <?php else: ?>
                  <span class="avatar" style="--size:40px" aria-hidden="true">·</span>
                  <span class="podium-name t-faint">—</span>
                <?php endif; ?>
                <div class="podium-step"><?= ui_rank($p, ($m && $m['stat_rank'] !== null) ? (int) $m['stat_rank'] : null) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="podium-foot">
            <?php if ($tiedFirst > 1): ?>
              <?= ui_icon('equal', 15) ?><span><?= _e('home.podium_tie') ?></span>
            <?php else: ?>
              <?= ui_icon('check-circle', 15) ?><span><?= _e('home.podium_clear') ?></span>
            <?php endif; ?>
          </div>
        </div>
      <?php else: ?>
        <div class="calib-card card-glass reveal" style="--i:2">
          <span class="eyebrow"><?= _e('home.calib_eyebrow') ?></span>
          <h2 class="t-h3 mt-4"><?= _e('home.calib_title') ?></h2>
          <p class="t-muted t-small mt-2"><?= _e('home.calib_text') ?></p>
          <ol class="calib-steps">
            <li><?= ui_icon('bolt', 18) ?><span><?= _e('home.calib_step1', ['n' => $method['runs']]) ?></span></li>
            <li><?= ui_icon('monitor', 18) ?><span><?= _e('home.calib_step2') ?></span></li>
            <li><?= ui_icon('users', 18) ?><span><?= _e('home.calib_step3', ['n' => $method['judges']]) ?></span></li>
            <li><span class="spinner" aria-hidden="true"></span><span><?= _e('home.calib_step4') ?></span></li>
          </ol>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<section class="home-kpis" aria-label="<?= _e('home.kpi_label') ?>">
  <div class="container-wide">
    <?php if ($hasScores): ?>
      <div class="kpi-row reveal">
        <?= ui_stat($num((int) $stats['models_rated']), __('home.kpi_models'), 'cpu', '', true) ?>
        <?= ui_stat($num((int) $stats['tasks']), __('home.kpi_tasks'), 'list', $holdout > 0 ? __('home.kpi_tasks_hint', ['n' => $num($holdout)]) : '', true) ?>
        <?= ui_stat($num((int) $stats['runs_scored']), __('home.kpi_runs'), 'refresh', '', true) ?>
        <?= ui_stat($num((int) $stats['judgments']), __('home.kpi_judgments'), 'scale', '', true) ?>
        <?= ui_stat($num((int) $stats['browser_checks']), __('home.kpi_browser'), 'monitor', '', true) ?>
        <?php if ((int) $stats['arena_votes'] > 0): ?>
          <?= ui_stat($num((int) $stats['arena_votes']), __('home.kpi_votes'), 'arena', '', true) ?>
        <?php else: ?>
          <?= ui_stat($num($trackCount), __('home.kpi_tracks'), 'layers', '', true) ?>
        <?php endif; ?>
      </div>
      <div class="kpi-foot">
        <span><?= _e('home.kpi_season', ['s' => $season, 'n' => $trackCount]) ?></span>
        <?php if ($updated !== ''): ?><span><?= _e('home.kpi_updated', ['t' => $updated]) ?></span><?php endif; ?>
      </div>
    <?php else: ?>
      <div class="kpi-row empty-kpi reveal">
        <?= ui_stat((string) $trackCount, __('home.spec_tracks'), 'layers') ?>
        <?= ui_stat($num((int) $stats['tasks']), __('home.spec_tasks'), 'list', $holdout > 0 ? __('home.kpi_tasks_hint', ['n' => $num($holdout)]) : '') ?>
        <?= ui_stat($method['runs'] . '×', __('home.spec_runs'), 'refresh') ?>
        <?= ui_stat((string) $method['judges'], __('home.spec_judges'), 'scale') ?>
        <?= ui_stat($num($method['boot']), __('home.spec_boot'), 'sigma') ?>
        <?= ui_stat('95%', __('home.spec_ci'), 'target') ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<section class="section home-board" id="top10" aria-labelledby="board-title">
  <div class="container-wide">
    <div class="section-head split">
      <div>
        <span class="eyebrow"><?= _e('home.board_eyebrow') ?></span>
        <h2 class="section-title" id="board-title"><?= ui_em(__('home.board_title')) ?></h2>
        <p class="section-sub"><?= _e('home.board_sub') ?></p>
      </div>
      <a class="btn btn-outline" href="<?= e(url('/leaderboard')) ?>"><?= _e('home.board_full') ?><?= ui_icon('arrow-right', 17) ?></a>
    </div>

    <?php if ($hasScores): ?>
      <div class="table-wrap reveal">
        <table class="table board-table">
          <thead>
            <tr>
              <th class="col-rank center" scope="col"><?= _e('common.rank') ?></th>
              <th class="col-model" scope="col"><?= _e('common.model') ?></th>
              <th class="col-score num" scope="col"><?= _e('home.col_score') ?></th>
              <th class="col-bar hide-sm" scope="col"><?= _e('home.col_ci') ?></th>
              <th class="hide-md" scope="col"><?= _e('home.col_tracks') ?></th>
              <th class="num hide-md" scope="col"><?= _e('home.col_cost') ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($top as $r): ?>
              <tr>
                <td class="center"><?= ui_rank((int) $r['rank_pos'], $r['stat_rank'] !== null ? (int) $r['stat_rank'] : null) ?></td>
                <td>
                  <?= ui_model_chip($r) ?>
                  <?php if ($r['stat_rank'] !== null && (int) $r['stat_rank'] < (int) $r['rank_pos']): ?>
                    <span class="board-tie" dir="ltr"><?= ui_badge(__('home.tie_badge', ['n' => (int) $r['stat_rank']]), 'blue', null, __('home.tie_tip', ['n' => (int) $r['stat_rank']])) ?></span>
                  <?php endif; ?>
                </td>
                <td class="num"><?= ui_score($r['score'], $r['ci_low'], $r['ci_high'], 'md') ?></td>
                <td class="hide-sm"><?= ui_scorebar($r['score'], 100, null, $r['ci_low'], $r['ci_high']) ?></td>
                <td class="hide-md">
                  <span class="board-tracks">
                    <?php foreach ($tracks as $slug => $t): ?>
                      <?php $ts = $r['tracks'][$slug]['score'] ?? null; ?>
                      <?= ui_heat_mini($ts, L($t, 'name') . ' · ' . fmt_score($ts)) ?>
                    <?php endforeach; ?>
                  </span>
                </td>
                <td class="num hide-md t-muted"><?= e(fmt_money($r['avg_cost'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="board-foot">
        <div class="legend">
          <span class="board-ci-key"><?= ui_scorebar(70, 100, null, 62, 78) ?><span><?= _e('home.legend_ci') ?></span></span>
          <span class="heat-scale hide-md"><span>0</span><span class="heat-scale-bar" aria-hidden="true"></span><span>100</span><span><?= _e('home.legend_heat') ?></span></span>
        </div>
        <span><?= _e('home.board_note') ?></span>
      </div>
    <?php else: ?>
      <div class="table-wrap board-empty reveal">
        <div class="skeleton-rows" aria-hidden="true">
          <?php for ($i = 0; $i < 6; $i++): ?>
            <div class="skeleton-row">
              <span class="skeleton skeleton-circle" style="width:30px;height:30px"></span>
              <span class="skeleton skeleton-text" style="width:<?= 70 + ($i * 7) % 25 ?>%"></span>
              <span class="skeleton skeleton-text" style="width:<?= 88 - $i * 9 ?>%"></span>
              <span class="skeleton skeleton-text" style="width:80%"></span>
            </div>
          <?php endfor; ?>
        </div>
        <div class="board-empty-msg">
          <div>
            <?= ui_icon('gauge', 36, 'empty-icon') ?>
            <h3 class="empty-title"><?= _e('home.board_empty_title') ?></h3>
            <p class="empty-text"><?= _e('home.board_empty_text') ?></p>
            <a class="btn btn-outline btn-sm" href="<?= e(url('/methodology')) ?>"><?= ui_icon('flask', 15) ?><?= _e('home.cta_method') ?></a>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</section>

<section class="section home-why" id="method" aria-labelledby="why-title">
  <div class="container-wide">
    <div class="section-head t-center">
      <span class="eyebrow"><?= _e('home.why_eyebrow') ?></span>
      <h2 class="section-title" id="why-title"><?= ui_em(__('home.why_title')) ?></h2>
      <p class="section-sub"><?= _e('home.why_sub') ?></p>
    </div>
    <?php
    $why = [
        ['users', 'panel', ['n' => $method['judges']]],
        ['refresh', 'repeat', ['n' => $method['runs'], 'b' => $num($method['boot'])]],
        ['equal', 'statrank', []],
        ['monitor', 'browser', []],
        ['code', 'determ', []],
        ['lock', $holdout > 0 ? 'holdout' : 'holdout_none', ['n' => $num($holdout)]],
        ['coin', 'cost', []],
        ['eye', 'open', []],
    ];
    ?>
    <div class="why-grid">
      <?php foreach ($why as $i => [$icon, $key, $vars]): ?>
        <article class="why-card reveal" style="--i:<?= $i % 4 ?>">
          <span class="why-icon"><?= ui_icon($icon, 22) ?></span>
          <span class="why-spec"><?= _e('home.why_' . $key . '_spec', $vars) ?></span>
          <h3><?= _e('home.why_' . $key . '_title') ?></h3>
          <p><?= _e('home.why_' . $key . '_text') ?></p>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php if ($tracks): ?>
<section class="section home-tracks" id="tracks" aria-labelledby="tracks-title">
  <div class="container-wide">
    <div class="section-head split">
      <div>
        <span class="eyebrow"><?= _e('home.tracks_eyebrow') ?></span>
        <h2 class="section-title" id="tracks-title"><?= ui_em(__('home.tracks_title')) ?></h2>
        <p class="section-sub"><?= _e($holdout > 0 ? 'home.tracks_sub' : 'home.tracks_sub_public') ?></p>
      </div>
      <a class="btn btn-outline" href="<?= e(url('/tasks')) ?>"><?= _e('home.tracks_all') ?><?= ui_icon('arrow-right', 17) ?></a>
    </div>
    <div class="tracks-grid">
      <?php $ti = 0; foreach ($tracks as $slug => $t): ?>
        <?php $lead = $leaders[$slug] ?? null; ?>
        <a class="track-card reveal" style="--track:<?= e(preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) $t['color']) ? $t['color'] : '#D9B26A') ?>;--i:<?= $ti++ % 4 ?>" href="<?= e(url('/leaderboard', ['track' => $slug])) ?>">
          <div class="track-top">
            <span class="track-icon"><?= ui_icon((string) $t['icon'], 21) ?></span>
            <?php if ($slug === 'arabic'): ?><?= ui_badge(__('home.track_unique'), 'gold', 'star') ?><?php else: ?><span class="track-count"><?= _e('home.track_tasks', ['n' => (int) $t['task_count']]) ?></span><?php endif; ?>
          </div>
          <h3 class="track-name"><?= e(L($t, 'name')) ?></h3>
          <p class="track-desc"><?= e(L($t, 'desc')) ?></p>
          <div class="track-meta">
            <?php if ($lead): ?>
              <span class="track-leader"><?= ui_icon('crown', 15) ?><span class="sr-only"><?= _e('home.track_leader') ?></span><strong><?= e($lead['model']['name']) ?></strong></span>
              <span class="t-mono t-faint"><?= e(fmt_score($lead['score'])) ?></span>
            <?php else: ?>
              <span class="track-leader"><?= ui_icon('clock', 15) ?><span><?= _e('home.track_awaiting') ?></span></span>
              <span class="track-count"><?= (int) $t['holdout_count'] > 0 ? _e('home.track_holdout', ['n' => (int) $t['holdout_count']]) : '' ?></span>
            <?php endif; ?>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="section home-arena" aria-labelledby="arena-title">
  <div class="container-wide">
    <div class="arena-teaser card reveal">
      <div class="arena-copy">
        <span class="eyebrow"><?= _e('home.arena_eyebrow') ?></span>
        <h2 class="section-title" id="arena-title"><?= ui_em(__('home.arena_title')) ?></h2>
        <p class="section-sub"><?= _e('home.arena_text') ?></p>
        <?php if ($arenaTop): ?>
          <div class="arena-top">
            <span class="t-xs t-faint t-upper"><?= _e('home.arena_top') ?></span>
            <?php foreach ($arenaTop as $i => $a): ?>
              <div class="arena-top-row">
                <span class="cluster" style="gap:10px"><?= ui_rank($i + 1) ?><?= ui_model_chip($a, true, false) ?></span>
                <span class="arena-elo t-num"><?= e(fmt_num($a['rating'])) ?><?php if ($a['ci_low'] !== null && $a['ci_high'] !== null): ?> <small>±<?= e(fmt_num(((float) $a['ci_high'] - (float) $a['ci_low']) / 2)) ?></small><?php endif; ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <div class="cluster"><a class="btn btn-primary" href="<?= e(url('/arena')) ?>"><?= ui_icon('arena', 17) ?><?= _e('home.arena_cta') ?></a><a class="btn btn-ghost" href="<?= e(url('/arena/leaderboard')) ?>"><?= _e('home.arena_board') ?></a></div>
      </div>
      <div class="arena-duel" aria-hidden="true">
        <div class="duel-frame duel-a">
          <div class="duel-bar"><b>A</b><span>?</span></div>
          <div class="duel-body"><i class="w60"></i><i class="w80"></i><i class="w40"></i><span class="duel-block"></span></div>
        </div>
        <div class="duel-frame duel-b">
          <div class="duel-bar"><b>B</b><span>?</span></div>
          <div class="duel-body"><i class="w80"></i><i class="w40"></i><i class="w60"></i><span class="duel-block"></span></div>
        </div>
        <span class="duel-vs"><?= _e('pub.cmp.vs') ?></span>
        <div class="duel-votes">
          <span class="chip"><?= _e('home.vote_a') ?></span>
          <span class="chip"><?= ui_icon('equal', 14) ?><?= _e('home.vote_tie') ?></span>
          <span class="chip"><?= _e('home.vote_b') ?></span>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="section home-tools" aria-labelledby="tools-title">
  <div class="container-wide">
    <?= ui_section_head(__('home.tools_eyebrow'), __('home.tools_title'), __('home.tools_sub')) ?>
    <?php
    $tools = [
        ['/compare', 'scale', 'nav.compare', 'compare'],
        ['/value', 'coin', 'nav.value', 'value'],
        ['/hardware', 'cpu', 'nav.hardware', 'hardware'],
        ['/advisor', 'compass', 'nav.advisor', 'advisor'],
        ['/api-docs', 'api', 'nav.api', 'api'],
    ];
    ?>
    <div class="tools-grid">
      <?php foreach ($tools as $i => [$href, $icon, $label, $key]): ?>
        <a class="card card-hover tool-card reveal" style="--i:<?= $i ?>" href="<?= e(url($href)) ?>">
          <span class="tool-icon"><?= ui_icon($icon, 19) ?></span>
          <h3><?= _e($label) ?></h3>
          <p><?= _e('home.tool_' . $key) ?></p>
          <span class="link-arrow"><?= _e('home.tool_open') ?><?= ui_icon('arrow-right', 14) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php if ($changes): ?>
<section class="section home-log" aria-labelledby="log-title">
  <div class="container-wide">
    <div class="section-head split">
      <div>
        <span class="eyebrow"><?= _e('home.log_eyebrow') ?></span>
        <h2 class="section-title" id="log-title"><?= ui_em(__('home.log_title')) ?></h2>
      </div>
      <a class="link-arrow" href="<?= e(url('/changelog')) ?>"><?= _e('home.log_all') ?><?= ui_icon('arrow-right', 14) ?></a>
    </div>
    <div class="changelog-list">
      <?php foreach ($changes as $c): ?>
        <article class="changelog-item reveal">
          <div class="changelog-when"><span class="changelog-ver">v<?= e($c['version']) ?></span><time class="changelog-date" datetime="<?= e(gmdate('c', (int) strtotime($c['published_at'] . ' UTC'))) ?>"><?= e(fmt_date($c['published_at'])) ?></time></div>
          <div>
            <h3><?= e(L($c, 'title')) ?></h3>
            <?php $body = L($c, 'body'); if ($body !== ''): ?><p><?= e(mb_strimwidth($body, 0, 280, '…')) ?></p><?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="section home-cta" aria-labelledby="cta-title">
  <div class="container-wide">
    <div class="closing-cta card reveal">
      <?php
      $dial = '';
      for ($deg = 0; $deg < 360; $deg += 3) {
          $rad = deg2rad($deg);
          $r0 = $deg % 30 === 0 ? 25 : ($deg % 15 === 0 ? 27 : 28.4);
          $dial .= sprintf('M%.2f %.2fL%.2f %.2f', 32 + $r0 * sin($rad), 32 - $r0 * cos($rad), 32 + 30 * sin($rad), 32 - 30 * cos($rad));
      }
      ?>
      <svg class="cta-dial" viewBox="0 0 64 64" aria-hidden="true"><path d="<?= $dial ?>" stroke="currentColor" stroke-width=".22" fill="none"/><circle cx="32" cy="32" r="31.4" fill="none" stroke="currentColor" stroke-width=".3"/><circle cx="32" cy="32" r="22" fill="none" stroke="currentColor" stroke-width=".15"/></svg>
      <div class="cta-copy">
        <span class="eyebrow"><?= _e('home.cta_eyebrow') ?></span>
        <h2 class="section-title" id="cta-title"><?= ui_em(__('home.cta_title')) ?></h2>
        <p class="section-sub"><?= _e('home.cta_text') ?></p>
      </div>
      <div class="cta-actions">
        <a class="btn btn-primary btn-lg" href="<?= e(url('/leaderboard')) ?>"><?= ui_icon('trophy', 18) ?><?= _e('home.cta_board') ?></a>
        <a class="btn btn-outline btn-lg" href="<?= e(url('/request')) ?>"><?= ui_icon('plus', 18) ?><?= _e('nav.request') ?></a>
      </div>
    </div>
  </div>
</section>
