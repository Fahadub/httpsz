<?php
/**
 * Embeddable top-N leaderboard (rendered without the site layout; meant for iframes on other sites).
 * Vars: $rows (ranked, non-provisional), $n, $theme dark|light, $season.
 */
defined('WB') || exit;

$site = (string) setting('site_name', 'WOAIBench');
$updated = $rows ? max(array_map(static fn($r) => (string) $r['updated_at'], $rows)) : null;
?><!doctype html>
<html lang="<?= e(lang()) ?>" dir="<?= e(text_dir()) ?>" data-theme="<?= e($theme) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e(__('tools.emb_title', ['n' => count($rows)]) . ' · ' . $site) ?></title>
<link rel="stylesheet" href="<?= e(asset('css/fonts.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/tools.css')) ?>">
<base target="_blank">
</head>
<body class="emb-body">
<main class="emb">
  <header class="emb-head">
    <a class="emb-brand" href="<?= e(url('/leaderboard', [], true)) ?>" rel="noopener">
      <img src="<?= e(asset('img/logo-mark.svg')) ?>" alt="" width="26" height="26">
      <span class="emb-brand-word">WOAI<span>Bench</span></span>
    </a>
    <span class="emb-sub t-xs"><?= _e('tools.emb_title', ['n' => count($rows)]) ?> · <?= _e('footer.season', ['s' => $season]) ?></span>
  </header>
<?php if (!$rows): ?>
  <p class="emb-empty t-small t-muted"><?= _e('tools.emb_empty') ?></p>
<?php else: ?>
  <ol class="emb-list">
<?php foreach ($rows as $i => $r):
    $half = $r['ci_high'] !== null && $r['ci_low'] !== null ? ($r['ci_high'] - $r['ci_low']) / 2 : null; ?>
    <li class="emb-row">
      <?= ui_rank($r['rank_pos'] ?? ($i + 1)) ?>
      <a class="emb-model" href="<?= e(url('/model/' . $r['slug'], [], true)) ?>" rel="noopener">
        <?= ui_provider_avatar((string) $r['provider'], $r['provider_name'], 26) ?>
        <span class="emb-model-text"><span class="emb-name"><?= e($r['name']) ?></span><span class="emb-provider"><?= e($r['provider_name'] ?: $r['provider']) ?></span></span>
      </a>
      <span class="emb-score">
        <span class="emb-score-num t-num"><?= e(fmt_score($r['score'])) ?><?php if ($half !== null): ?><small>±<?= e(number_format($half, 1)) ?></small><?php endif; ?></span>
        <?= ui_scorebar($r['score'], 100, $r['color'], $r['ci_low'], $r['ci_high']) ?>
      </span>
    </li>
<?php endforeach; ?>
  </ol>
<?php endif; ?>
  <footer class="emb-foot t-xs">
    <a href="<?= e(url('/leaderboard', [], true)) ?>" rel="noopener"><?= _e('tools.emb_full') ?> <?= is_rtl() ? '←' : '→' ?></a>
    <span><?= $updated ? _e('tools.emb_updated', ['d' => fmt_date($updated)]) : '' ?> · CC BY 4.0</span>
  </footer>
</main>
</body>
</html>
