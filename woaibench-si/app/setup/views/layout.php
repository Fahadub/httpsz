<?php
/**
 * Installer shell: header (brand, language, theme), step rail, panel, footer.
 * Self-contained (inline CSS/JS with the CSP nonce) because the app's assets/layout need a database.
 * Vars: $content, $step (1-4 | 'done' | 'locked' | 'error'), $view.
 */
defined('WB') || exit;

$base = base_path();
$isWizard = is_int($step);
$other = lang() === 'ar' ? 'en' : 'ar';
$q = $_GET;
$q['lang'] = $other;
unset($q['_csrf']);
$langHref = $base . '/install/?' . http_build_query(array_intersect_key($q, ['lang' => 1, 'step' => 1]));
$fontsDir = $base . '/assets/fonts/';
$css = (string) file_get_contents(__DIR__ . '/installer.css');
$js = (string) file_get_contents(__DIR__ . '/installer.js');
$titles = ['done' => 'setup.done_title', 'locked' => 'setup.locked_title', 'error' => 'setup.err_title'];
$pageTitle = $isWizard ? __(\WB\Setup\Wizard::STEPS[$step]) : __($titles[$step] ?? 'setup.title');
$jsStrings = I18n::forJs(['setup.js_', 'setup.strength_', 'setup.api_testing', 'setup.copied', 'setup.copy', 'setup.st_']);
?><!doctype html>
<html lang="<?= e(lang()) ?>" dir="<?= e(text_dir()) ?>" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<meta name="color-scheme" content="dark light">
<meta name="theme-color" content="#07080C">
<title><?= e($pageTitle) ?> · <?= _e('setup.title') ?> · WOAIBench.si</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='16' fill='%2307080C'/%3E%3Cpath d='M16 44V30M26 44V22M36 44V27M46 44V16' stroke='%23D9B26A' stroke-width='5' stroke-linecap='round'/%3E%3C/svg%3E">
<script<?= nonce_attr() ?>>(function(){try{var t=localStorage.getItem('wb_theme');if(t!=='light'&&t!=='dark'){t=window.matchMedia&&matchMedia('(prefers-color-scheme: light)').matches?'light':'dark';}document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
<style<?= nonce_attr() ?>>
@font-face{font-family:"Inter";src:url("<?= e($fontsDir) ?>inter-latin-wght-normal.woff2") format("woff2");font-weight:100 900;font-display:swap}
@font-face{font-family:"Instrument Serif";src:url("<?= e($fontsDir) ?>instrument-serif-latin-400-normal.woff2") format("woff2");font-weight:400;font-display:swap}
@font-face{font-family:"Instrument Serif";src:url("<?= e($fontsDir) ?>instrument-serif-latin-400-italic.woff2") format("woff2");font-weight:400;font-style:italic;font-display:swap}
@font-face{font-family:"JetBrains Mono";src:url("<?= e($fontsDir) ?>jetbrains-mono-latin-wght-normal.woff2") format("woff2");font-weight:100 800;font-display:swap}
@font-face{font-family:"Noto Kufi Arabic";src:url("<?= e($fontsDir) ?>noto-kufi-arabic-arabic-wght-normal.woff2") format("woff2");font-weight:100 900;font-display:swap}
<?php foreach ([300, 400, 500, 600, 700] as $w): ?>@font-face{font-family:"IBM Plex Sans Arabic";src:url("<?= e($fontsDir) ?>ibm-plex-sans-arabic-arabic-<?= $w ?>-normal.woff2") format("woff2");font-weight:<?= $w ?>;font-display:swap}
<?php endforeach; ?>
<?= $css ?>
</style>
</head>
<body class="view-<?= e($view) ?>">
<svg width="0" height="0" class="sprite" aria-hidden="true" focusable="false">
  <symbol id="i-check" viewBox="0 0 24 24"><path d="M5 12.5l4.2 4.2L19 7"/></symbol>
  <symbol id="i-x" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></symbol>
  <symbol id="i-alert" viewBox="0 0 24 24"><path d="M12 3.5l9.5 16.5h-19z"/><path d="M12 10v4.5M12 17.3v.2"/></symbol>
  <symbol id="i-info" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 11v5.5M12 7.6v.2"/></symbol>
  <symbol id="i-lock" viewBox="0 0 24 24"><rect x="4.5" y="10.5" width="15" height="10" rx="2.5"/><path d="M8 10.5V7.8a4 4 0 018 0v2.7"/></symbol>
  <symbol id="i-db" viewBox="0 0 24 24"><ellipse cx="12" cy="6" rx="7.5" ry="3"/><path d="M4.5 6v12c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3V6M4.5 12c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3"/></symbol>
  <symbol id="i-globe" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.6 2.8 3.8 5.8 3.8 9s-1.2 6.2-3.8 9c-2.6-2.8-3.8-5.8-3.8-9S9.4 5.8 12 3z"/></symbol>
  <symbol id="i-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2.5v2M12 19.5v2M4.6 4.6l1.4 1.4M18 18l1.4 1.4M2.5 12h2M19.5 12h2M4.6 19.4L6 18M18 6l1.4-1.4"/></symbol>
  <symbol id="i-moon" viewBox="0 0 24 24"><path d="M20 14.5A8.5 8.5 0 019.5 4a8.5 8.5 0 1010.5 10.5z"/></symbol>
  <symbol id="i-server" viewBox="0 0 24 24"><rect x="3.5" y="4" width="17" height="7" rx="2"/><rect x="3.5" y="13" width="17" height="7" rx="2"/><path d="M7.5 7.5h.1M7.5 16.5h.1"/></symbol>
  <symbol id="i-key" viewBox="0 0 24 24"><circle cx="8" cy="15" r="4.5"/><path d="M11.2 11.8L20 3M16.5 6.5l2.5 2.5M14 9l2 2"/></symbol>
  <symbol id="i-user" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20.5c1.4-3.6 4.4-5.5 8-5.5s6.6 1.9 8 5.5"/></symbol>
  <symbol id="i-spark" viewBox="0 0 24 24"><path d="M12 3l1.9 5.6L19.5 10.5l-5.6 1.9L12 18l-1.9-5.6-5.6-1.9 5.6-1.9z"/><path d="M19 16l.8 2.2L22 19l-2.2.8L19 22l-.8-2.2L16 19l2.2-.8z"/></symbol>
  <symbol id="i-copy" viewBox="0 0 24 24"><rect x="8.5" y="8.5" width="12" height="12" rx="2.5"/><path d="M15.5 8.5V6a2.5 2.5 0 00-2.5-2.5H6A2.5 2.5 0 003.5 6v7A2.5 2.5 0 006 15.5h2.5"/></symbol>
  <symbol id="i-arrow" viewBox="0 0 24 24"><path d="M4 12h15M13 6l6 6-6 6"/></symbol>
  <symbol id="i-shield" viewBox="0 0 24 24"><path d="M12 3l7.5 3v5.5c0 4.6-3.1 8.4-7.5 9.5-4.4-1.1-7.5-4.9-7.5-9.5V6z"/><path d="M8.8 12.2l2.3 2.3 4.3-4.6"/></symbol>
  <symbol id="i-terminal" viewBox="0 0 24 24"><rect x="3" y="4.5" width="18" height="15" rx="2.5"/><path d="M7 9.5l3 2.5-3 2.5M12.5 15h4.5"/></symbol>
  <symbol id="i-folder" viewBox="0 0 24 24"><path d="M3.5 7.5A2 2 0 015.5 5.5h4l2 2.5h7a2 2 0 012 2v7.5a2 2 0 01-2 2h-13a2 2 0 01-2-2z"/></symbol>
  <symbol id="i-eye" viewBox="0 0 24 24"><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/></symbol>
  <symbol id="i-refresh" viewBox="0 0 24 24"><path d="M20 11.5A8 8 0 006.3 6.3L4 8.5M4 4v4.5h4.5M4 12.5a8 8 0 0013.7 5.2L20 15.5M20 20v-4.5h-4.5"/></symbol>
  <symbol id="i-cpu" viewBox="0 0 24 24"><rect x="6" y="6" width="12" height="12" rx="2"/><path d="M9.5 9.5h5v5h-5zM9 2.5V6M15 2.5V6M9 18v3.5M15 18v3.5M2.5 9H6M2.5 15H6M18 9h3.5M18 15h3.5"/></symbol>
</svg>
<div class="backdrop" aria-hidden="true"></div>

<header class="topbar">
  <a class="brand" href="<?= e(\WB\Setup\Wizard::url(1)) ?>" aria-label="WOAIBench.si">
    <?= ui_logo_mark(40) /* the site's own mark (app/core/ui.php), so the installer carries the same identity */ ?>
    <span class="brand-word" dir="ltr">WOAI<span class="brand-bench">Bench</span><span class="brand-tld">.si</span></span>
    <span class="brand-tag"><?= _e('setup.title') ?></span>
  </a>
  <div class="top-actions">
    <a class="pill" href="<?= e($langHref) ?>" lang="<?= e($other) ?>" hreflang="<?= e($other) ?>">
      <svg class="ic" aria-hidden="true"><use href="#i-globe"/></svg><span><?= _e('setup.lang_' . $other) ?></span>
    </a>
    <button class="pill pill-icon" type="button" data-theme-toggle aria-label="<?= _e('setup.theme_toggle') ?>" title="<?= _e('setup.theme_toggle') ?>">
      <svg class="ic only-dark" aria-hidden="true"><use href="#i-sun"/></svg><svg class="ic only-light" aria-hidden="true"><use href="#i-moon"/></svg>
    </button>
  </div>
</header>

<div class="shell<?= $isWizard ? '' : ' shell-solo' ?>">
<?php if ($isWizard): ?>
  <aside class="rail" aria-label="<?= _e('setup.steps_nav') ?>">
    <div class="rail-head">
      <span class="eyebrow"><?= _e('setup.step_of', ['n' => $step, 'total' => count(\WB\Setup\Wizard::STEPS)]) ?></span>
      <div class="rail-progress" aria-hidden="true"><span class="rail-progress-bar" data-progress="<?= (int) round(($step - 1) / 3 * 100) ?>"></span></div>
    </div>
    <ol class="steps">
<?php foreach (\WB\Setup\Wizard::STEPS as $n => $label): $state = $n < $step ? 'is-done' : ($n === $step ? 'is-current' : 'is-next'); ?>
      <li class="step <?= $state ?>"<?= $n === $step ? ' aria-current="step"' : '' ?>>
        <span class="step-dot"><?php if ($n < $step): ?><svg class="ic" aria-hidden="true"><use href="#i-check"/></svg><?php else: ?><?= $n ?><?php endif; ?></span>
        <span class="step-text"><strong><?= _e($label) ?></strong><small><?= _e($label . '_desc') ?></small></span>
      </li>
<?php endforeach; ?>
    </ol>
    <p class="rail-note"><svg class="ic" aria-hidden="true"><use href="#i-shield"/></svg><span><?= _e('setup.rail_note') ?></span></p>
  </aside>
<?php endif; ?>
  <main class="panel" id="main">
<?= $content ?>
  </main>
</div>

<footer class="foot">
  <span dir="ltr">WOAIBench.si v<?= e(WB_VERSION) ?></span><span class="dot" aria-hidden="true">·</span><span dir="ltr">PHP <?= e(PHP_VERSION) ?></span><span class="dot" aria-hidden="true">·</span><span><?= _e('setup.foot_docs') ?></span>
</footer>
<script type="application/json" id="setup-i18n"><?= js_json($jsStrings) ?></script>
<script<?= nonce_attr() ?>>
<?= $js ?>
</script>
</body>
</html>
