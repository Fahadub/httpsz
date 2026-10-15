<?php
/**
 * Public layout. Variables: see View.php docblock. $content = rendered page.
 * Error templates rendered through abort() get noindex automatically (an integer $code >= 400).
 */
defined('WB') || exit;

$siteName = (string) setting('site_name', 'WOAIBench');
$tagline = lang() === 'ar' ? (string) setting('tagline_ar') : (string) setting('tagline_en');
$pageTitle = isset($title) && $title !== '' ? $title . ' · ' . $siteName : $siteName . ' — ' . $tagline;
$desc = isset($description) && $description !== '' ? $description : $tagline;
$canon = $canonical ?? url(current_path(), [], true);
// Social card: an explicit $og_image, else the Brand Studio OG image (when the file exists), else the bundled one.
$ogSize = null;
if (!isset($og_image)) {
    $brandOg = ltrim((string) setting('brand_og', ''), '/');
    $brandFile = $brandOg !== '' && !str_contains($brandOg, '..') ? WB_UPLOADS . '/' . $brandOg : '';
    if ($brandFile !== '' && is_file($brandFile)) {
        $og_image = site_origin() . upload_url($brandOg);
        $dim = @getimagesize($brandFile);
        $ogSize = $dim ? [(int) $dim[0], (int) $dim[1]] : null;
    }
}
$ogImg = $og_image ?? url('/assets/img/og.png', [], true);
if (!isset($og_image)) {
    $ogSize = [1200, 630];
}
$page_css = $page_css ?? [];
$page_js = $page_js ?? [];
$nav_active = $nav_active ?? '';
$robots = $robots ?? ((isset($code) && is_int($code) && $code >= 400) ? 'noindex' : null);
$altQuery = static fn(string $l): string => url(current_path(), array_merge(array_filter($_GET, 'is_string'), ['lang' => $l]), true);
// Pages without a session (cookieless public GETs, see bootstrap) carry no CSRF token: emit the meta only when set.
$csrf = csrf_token();
$fontPreload = lang() === 'ar'
    ? ['fonts/ibm-plex-sans-arabic-arabic-400-normal.woff2', 'fonts/ibm-plex-sans-arabic-arabic-500-normal.woff2', 'fonts/ibm-plex-sans-arabic-arabic-600-normal.woff2', 'fonts/noto-kufi-arabic-arabic-wght-normal.woff2']
    : ['fonts/inter-latin-wght-normal.woff2', 'fonts/instrument-serif-latin-400-normal.woff2'];
?><!doctype html>
<html lang="<?= e(lang()) ?>" dir="<?= e(text_dir()) ?>" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($pageTitle) ?></title>
<meta name="description" content="<?= e($desc) ?>">
<?php if (!empty($robots)): ?><meta name="robots" content="<?= e($robots) ?>">
<?php endif; ?>
<link rel="canonical" href="<?= e($canon) ?>">
<link rel="alternate" hreflang="ar" href="<?= e($altQuery('ar')) ?>">
<link rel="alternate" hreflang="en" href="<?= e($altQuery('en')) ?>">
<link rel="alternate" hreflang="x-default" href="<?= e($altQuery((string) setting('default_lang', 'ar'))) ?>">
<?php if ($csrf !== ''): ?><meta name="csrf-token" content="<?= e($csrf) ?>">
<?php endif; ?>
<meta name="theme-color" content="#08090C">
<meta name="color-scheme" content="dark light">
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= e($siteName) ?>">
<meta property="og:locale" content="<?= lang() === 'ar' ? 'ar_AR' : 'en_US' ?>">
<meta property="og:title" content="<?= e($pageTitle) ?>">
<meta property="og:description" content="<?= e($desc) ?>">
<meta property="og:image" content="<?= e($ogImg) ?>">
<?php if ($ogSize): ?><meta property="og:image:width" content="<?= (int) $ogSize[0] ?>">
<meta property="og:image:height" content="<?= (int) $ogSize[1] ?>">
<?php endif; ?>
<meta property="og:url" content="<?= e($canon) ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:image" content="<?= e($ogImg) ?>">
<link rel="icon" href="<?= e(asset('img/favicon.ico')) ?>" sizes="48x48">
<link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="apple-touch-icon" href="<?= e(asset('img/apple-touch-icon.png')) ?>">
<link rel="manifest" href="<?= e(url('/site.webmanifest')) ?>">
<script<?= nonce_attr() ?>>(function(){var d=document.documentElement;d.className+=' js';try{var t=localStorage.getItem('wb_theme');if(t==='light'||t==='dark'){d.setAttribute('data-theme',t);}else if(window.matchMedia&&matchMedia('(prefers-color-scheme: light)').matches){d.setAttribute('data-theme','light');}}catch(e){}})();</script>
<?php foreach ($fontPreload as $font): ?><link rel="preload" href="<?= e(base_path() . '/assets/' . $font) ?>" as="font" type="font/woff2" crossorigin>
<?php endforeach; ?>
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
<main id="main" tabindex="-1">
<?php foreach (flashes() as $f): ?>
  <div class="container"><div class="alert alert-<?= e($f['type']) ?>" role="status"><?= ui_icon(['success' => 'check-circle', 'error' => 'x-circle', 'warn' => 'alert'][$f['type']] ?? 'info', 18) ?><div class="flex-1"><?= e($f['message']) ?></div><button class="btn-icon" type="button" data-dismiss aria-label="<?= _e('design.dismiss') ?>"><?= ui_icon('x', 16) ?></button></div></div>
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
