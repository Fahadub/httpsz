<?php
/**
 * Public layout. Variables: see View.php docblock. $content = rendered page.
 */
defined('WB') || exit;

$siteName = (string) setting('site_name', 'WOAIBench');
$tagline = lang() === 'ar' ? (string) setting('tagline_ar') : (string) setting('tagline_en');
$pageTitle = isset($title) && $title !== '' ? $title . ' · ' . $siteName : $siteName . ' — ' . $tagline;
$desc = $description ?? $tagline;
$canon = $canonical ?? url(current_path(), [], true);
$ogImg = $og_image ?? url('/assets/img/og.png', [], true);
$page_css = $page_css ?? [];
$page_js = $page_js ?? [];
$nav_active = $nav_active ?? '';
?><!doctype html>
<html lang="<?= e(lang()) ?>" dir="<?= e(text_dir()) ?>" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($pageTitle) ?></title>
<meta name="description" content="<?= e($desc) ?>">
<?php if (!empty($robots)): ?><meta name="robots" content="<?= e($robots) ?>"><?php endif; ?>
<link rel="canonical" href="<?= e($canon) ?>">
<link rel="alternate" hreflang="ar" href="<?= e(url(current_path(), array_merge($_GET, ['lang' => 'ar']), true)) ?>">
<link rel="alternate" hreflang="en" href="<?= e(url(current_path(), array_merge($_GET, ['lang' => 'en']), true)) ?>">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="theme-color" content="#07080C">
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= e($siteName) ?>">
<meta property="og:title" content="<?= e($pageTitle) ?>">
<meta property="og:description" content="<?= e($desc) ?>">
<meta property="og:image" content="<?= e($ogImg) ?>">
<meta property="og:url" content="<?= e($canon) ?>">
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="apple-touch-icon" href="<?= e(asset('img/apple-touch-icon.png')) ?>">
<link rel="manifest" href="<?= e(url('/site.webmanifest')) ?>">
<script<?= nonce_attr() ?>>(function(){try{var t=localStorage.getItem('wb_theme');if(t==='light'||t==='dark'){document.documentElement.setAttribute('data-theme',t);}else if(window.matchMedia&&matchMedia('(prefers-color-scheme: light)').matches){document.documentElement.setAttribute('data-theme','light');}}catch(e){}})();</script>
<link rel="stylesheet" href="<?= e(asset('css/fonts.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<?php foreach ($page_css as $css): ?><link rel="stylesheet" href="<?= e(asset($css)) ?>">
<?php endforeach; ?>
<?php if (!empty($json_ld)): ?><script type="application/ld+json"<?= nonce_attr() ?>><?= js_json($json_ld) ?></script>
<?php endif; ?>
</head>
<body class="<?= e($body_class ?? '') ?>">
<a class="skip-link" href="#main"><?= _e('common.skip') ?></a>
<?php if (empty($hide_chrome)) { echo partial('partials/header', ['nav_active' => $nav_active]); } ?>
<main id="main">
<?php foreach (flashes() as $f): ?>
  <div class="container"><div class="alert alert-<?= e($f['type']) ?>" role="status"><?= e($f['message']) ?></div></div>
<?php endforeach; ?>
<?= $content ?>
</main>
<?php if (empty($hide_chrome)) { echo partial('partials/footer'); } ?>
<script type="application/json" id="wb-i18n"><?= js_json(I18n::forJs(['js.'])) ?></script>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
<?php foreach ($page_js as $js): ?><script src="<?= e(asset($js)) ?>" defer></script>
<?php endforeach; ?>
<?php $snippet = (string) setting('analytics_snippet', ''); if ($snippet !== '' && empty($robots)) { echo preg_replace('/<script\b/i', '<script' . nonce_attr(), $snippet); } ?>
</body>
</html>
