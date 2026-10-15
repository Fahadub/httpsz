<?php
/**
 * Reusable UI fragments. They return HTML strings (already escaped) so templates can do:
 *   <?= ui_icon('trophy') ?>  <?= ui_score($row['score'], $row['ci_low'], $row['ci_high']) ?>
 * Class names follow the design-system vocabulary in docs/ARCHITECTURE.md §7.
 */
declare(strict_types=1);

defined('WB') || exit;

/** Inline SVG icon from the sprite assets/img/icons.svg (symbol id "i-<name>"). */
function ui_icon(string $name, int $size = 18, string $class = ''): string
{
    static $sprite = null;
    $sprite ??= asset('img/icons.svg');
    $name = (string) preg_replace('/[^a-z0-9\-]/', '', $name);
    $cls = trim('icon icon-' . $name . ' ' . $class);
    return '<svg class="' . e($cls) . '" width="' . $size . '" height="' . $size . '" aria-hidden="true" focusable="false"><use href="' . e($sprite . '#i-' . $name) . '"></use></svg>';
}

/** Map a 0..100 score to a heat bucket 0..10 (for .heat-N classes). */
function ui_heat_bucket(int|float|string|null $score): int
{
    if ($score === null || $score === '') {
        return -1;
    }
    return (int) clamp(floor(((float) $score) / 10), 0, 10);
}

/** Big score with optional ±CI. */
function ui_score(int|float|string|null $score, int|float|string|null $ciLow = null, int|float|string|null $ciHigh = null, string $size = 'md'): string
{
    if ($score === null || $score === '') {
        return '<span class="score score-' . e($size) . ' is-empty">—</span>';
    }
    $html = '<span class="score score-' . e($size) . '"><span class="t-num">' . e(fmt_score($score)) . '</span>';
    if ($ciLow !== null && $ciHigh !== null && $ciLow !== '' && $ciHigh !== '') {
        $half = ((float) $ciHigh - (float) $ciLow) / 2;
        $html .= '<span class="score-ci t-num" data-tip="' . e(__('common.ci95') . ': ' . fmt_score($ciLow) . ' – ' . fmt_score($ciHigh)) . '">±' . e(number_format($half, 1)) . '</span>';
    }
    return $html . '</span>';
}

/** Horizontal bar 0..max, optionally with a CI whisker. */
function ui_scorebar(int|float|string|null $score, float $max = 100, ?string $color = null, int|float|string|null $ciLow = null, int|float|string|null $ciHigh = null): string
{
    $pct = ($score === null || $score === '') ? 0 : clamp((float) $score / $max * 100, 0, 100);
    $style = 'width:' . number_format($pct, 2, '.', '') . '%' . ($color ? ';--bar:' . e($color) : '');
    $whisker = '';
    if ($ciLow !== null && $ciHigh !== null && $ciLow !== '' && $ciHigh !== '') {
        $l = clamp((float) $ciLow / $max * 100, 0, 100);
        $h = clamp((float) $ciHigh / $max * 100, 0, 100);
        $whisker = '<span class="scorebar-ci" style="inset-inline-start:' . number_format($l, 2, '.', '') . '%;width:' . number_format(max(0.5, $h - $l), 2, '.', '') . '%"></span>';
    }
    return '<span class="scorebar" role="img" aria-label="' . e(fmt_score($score)) . '"><span class="scorebar-fill" style="' . $style . '"></span>' . $whisker . '</span>';
}

/** Deterministic colour for a provider key (used when a model has no colour set). */
function ui_provider_color(string $provider): string
{
    $known = [
        'openai' => '#10A37F', 'anthropic' => '#D97757', 'google' => '#4C8DF6', 'meta-llama' => '#0866FF',
        'mistralai' => '#FA7A1E', 'deepseek' => '#4D6BFE', 'x-ai' => '#A0A0A0', 'qwen' => '#7C5CFF',
        'moonshotai' => '#2BC4C4', 'z-ai' => '#3A7BFD', 'cohere' => '#39594D', 'amazon' => '#FF9900',
        'microsoft' => '#00A4EF', 'nvidia' => '#76B900', 'minimax' => '#E2457B', 'baidu' => '#2932E1',
    ];
    if (isset($known[$provider])) {
        return $known[$provider];
    }
    $h = hexdec(substr(md5($provider), 0, 4)) % 360;
    return "hsl({$h} 55% 55%)";
}

/** Round monogram avatar for a provider. */
function ui_provider_avatar(string $provider, ?string $providerName = null, int $size = 28): string
{
    $label = $providerName ?: $provider;
    $initial = mb_strtoupper(mb_substr(preg_replace('/[^\p{L}\p{N}]/u', '', $label) ?: '?', 0, 1));
    return '<span class="avatar" style="--av:' . e(ui_provider_color($provider)) . ';--size:' . $size . 'px" title="' . e($label) . '" aria-hidden="true">' . e($initial) . '</span>';
}

/** Model name + provider, linked to the model page. $m needs slug, name, provider (provider_name optional). */
function ui_model_chip(array $m, bool $link = true, bool $showProvider = true): string
{
    $inner = ui_provider_avatar((string) ($m['provider'] ?? ''), $m['provider_name'] ?? null)
        . '<span class="model-chip-text"><span class="model-chip-name">' . e($m['name'] ?? '') . '</span>'
        . ($showProvider ? '<span class="model-chip-provider">' . e($m['provider_name'] ?? $m['provider'] ?? '') . '</span>' : '')
        . '</span>';
    if ($link && !empty($m['slug'])) {
        return '<a class="model-chip" href="' . e(url('/model/' . $m['slug'])) . '">' . $inner . '</a>';
    }
    return '<span class="model-chip">' . $inner . '</span>';
}

/** Track badge. $t needs slug, name_ar/name_en, icon, color. */
function ui_track_badge(array $t, bool $link = false): string
{
    $html = '<span class="track-badge" style="--track:' . e($t['color'] ?? '#D9B26A') . '">'
        . ui_icon((string) ($t['icon'] ?? 'layers'), 14) . '<span>' . e(L($t, 'name')) . '</span></span>';
    if ($link && !empty($t['slug'])) {
        return '<a class="track-badge-link" href="' . e(url('/leaderboard', ['track' => $t['slug']])) . '">' . $html . '</a>';
    }
    return $html;
}

/** Difficulty dots 1..4 */
function ui_difficulty(int $level): string
{
    $labels = [1 => __('common.diff_easy'), 2 => __('common.diff_medium'), 3 => __('common.diff_hard'), 4 => __('common.diff_expert')];
    $level = (int) clamp($level, 1, 4);
    $dots = '';
    for ($i = 1; $i <= 4; $i++) {
        $dots .= '<i class="' . ($i <= $level ? 'on' : '') . '"></i>';
    }
    return '<span class="difficulty d-' . $level . '" title="' . e($labels[$level]) . '"><span class="difficulty-dots">' . $dots . '</span><span class="difficulty-label">' . e($labels[$level]) . '</span></span>';
}

/** Heatmap cell for a score. */
function ui_heat_cell(int|float|string|null $score, string $tip = ''): string
{
    $b = ui_heat_bucket($score);
    $cls = $b < 0 ? 'heat heat-na' : 'heat heat-' . $b;
    return '<span class="' . $cls . '"' . ($tip !== '' ? ' data-tip="' . e($tip) . '"' : '') . '>' . e(fmt_score($score)) . '</span>';
}

/** Up/down delta indicator. */
function ui_delta(int|float|string|null $delta, int $decimals = 1): string
{
    if ($delta === null || $delta === '' || abs((float) $delta) < 0.05) {
        return '<span class="delta delta-flat">—</span>';
    }
    $d = (float) $delta;
    $cls = $d > 0 ? 'delta-up' : 'delta-down';
    return '<span class="delta ' . $cls . '">' . ui_icon($d > 0 ? 'arrow-up' : 'arrow-down', 12) . e(number_format(abs($d), $decimals)) . '</span>';
}

/** Badge pill. $variant: gold|green|red|blue|muted|violet */
function ui_badge(string $text, string $variant = 'muted', ?string $icon = null, string $tip = ''): string
{
    return '<span class="badge badge-' . e($variant) . '"' . ($tip !== '' ? ' data-tip="' . e($tip) . '"' : '') . '>'
        . ($icon ? ui_icon($icon, 12) : '') . e($text) . '</span>';
}

/** Rank number with medal styling for top 3. */
function ui_rank(?int $rank, ?int $statRank = null): string
{
    if (!$rank) {
        return '<span class="rank rank-none">—</span>';
    }
    $cls = $rank <= 3 ? ' rank-' . $rank : '';
    $tip = $statRank !== null ? ' data-tip="' . e(__('common.stat_rank_tip', ['n' => $statRank])) . '"' : '';
    return '<span class="rank' . $cls . '"' . $tip . '>' . $rank . '</span>';
}

/** Empty state block. */
function ui_empty(string $title, string $text = '', string $icon = 'sparkles', string $actionHtml = ''): string
{
    return '<div class="empty-state">' . ui_icon($icon, 36, 'empty-icon')
        . '<h3 class="empty-title">' . e($title) . '</h3>'
        . ($text !== '' ? '<p class="empty-text">' . e($text) . '</p>' : '')
        . $actionHtml . '</div>';
}

/** KPI stat. */
function ui_stat(string $value, string $label, ?string $icon = null, string $hint = '', bool $countup = false): string
{
    $num = $countup ? ' data-countup="' . e(preg_replace('/[^0-9.]/', '', $value)) . '"' : '';
    return '<div class="stat">' . ($icon ? '<span class="stat-icon">' . ui_icon($icon, 20) . '</span>' : '')
        . '<span class="stat-value t-num"' . $num . '>' . e($value) . '</span>'
        . '<span class="stat-label">' . e($label) . '</span>'
        . ($hint !== '' ? '<span class="stat-hint">' . e($hint) . '</span>' : '') . '</div>';
}

/** Simple pagination. */
function ui_pagination(int $page, int $pages, string $path, array $query = []): string
{
    if ($pages <= 1) {
        return '';
    }
    $html = '<nav class="pagination" aria-label="' . e(__('common.pagination')) . '">';
    for ($p = 1; $p <= $pages; $p++) {
        if ($p !== 1 && $p !== $pages && abs($p - $page) > 2) {
            if ($p === 2 || $p === $pages - 1) {
                $html .= '<span class="page-gap">…</span>';
            }
            continue;
        }
        $html .= '<a class="page' . ($p === $page ? ' active' : '') . '" href="' . e(url($path, array_merge($query, ['page' => $p]))) . '">' . $p . '</a>';
    }
    return $html . '</nav>';
}

/** Run status pill used in admin and run pages. */
function ui_status(string $status): string
{
    $map = [
        'queued' => 'muted', 'generating' => 'blue', 'awaiting_verify' => 'violet', 'verifying' => 'violet',
        'judging' => 'blue', 'scoring' => 'blue', 'scored' => 'green', 'failed' => 'red', 'skipped' => 'muted',
        'pending' => 'muted', 'done' => 'green', 'running' => 'blue', 'paused' => 'gold', 'draft' => 'muted',
    ];
    $key = 'status.' . $status;
    return ui_badge(I18n::has($key) ? __($key) : $status, $map[$status] ?? 'muted');
}

/* ------------------------------------------------------------------ design-system additions */

/**
 * Inline brand mark ("Calibrated W"), theme-aware through CSS tokens (--logo-a/b/c).
 * $detail: 'simple' (header/footer sizes) | 'full' (adds the fine graduation scale, for ≥ 64 px).
 */
function ui_logo_mark(int $size = 34, string $detail = 'simple', string $class = 'brand-mark'): string
{
    static $n = 0;
    $id = 'wbmk' . (++$n);
    $fine = '';
    if ($detail === 'full') {
        $d = '';
        for ($deg = 0; $deg < 360; $deg += 6) {
            if ($deg % 90 === 0 || abs($deg - 50.6) < 11) {
                continue;
            }
            $r0 = $deg % 30 === 0 ? 22.2 : 23.3;
            $a = deg2rad($deg);
            $d .= sprintf('M%.2f %.2fL%.2f %.2f', 32 + $r0 * sin($a), 32 - $r0 * cos($a), 32 + 24.6 * sin($a), 32 - 24.6 * cos($a));
        }
        $fine = '<path d="' . $d . '" stroke="url(#' . $id . ')" stroke-width=".75" stroke-linecap="round" opacity=".7" fill="none"/>'
            . '<circle cx="47.2" cy="19.4" r="5.2" fill="none" stroke="url(#' . $id . ')" stroke-width=".9" opacity=".8"/>';
    }
    return '<svg class="' . e($class) . '" viewBox="0 0 64 64" width="' . $size . '" height="' . $size . '" aria-hidden="true" focusable="false">'
        . '<defs><linearGradient id="' . $id . '" x1="10" y1="6" x2="54" y2="58" gradientUnits="userSpaceOnUse">'
        . '<stop offset="0" class="bm-a"/><stop offset=".48" class="bm-b"/><stop offset="1" class="bm-c"/></linearGradient></defs>'
        . '<circle cx="32" cy="32" r="27.4" fill="none" stroke="url(#' . $id . ')" stroke-width="2.3"/>'
        . '<path d="M32 11.4V7.4M52.6 32h4M32 52.6v4M11.4 32h-4" stroke="url(#' . $id . ')" stroke-width="2" stroke-linecap="round" fill="none"/>'
        . $fine
        . '<polyline points="16.6 25.2 23.6 41.6 31.6 30.4 39.6 41.6 47.2 19.4" fill="none" stroke="url(#' . $id . ')" stroke-width="3.1" stroke-linecap="round" stroke-linejoin="round"/>'
        . '<circle cx="47.2" cy="19.4" r="3.2" fill="url(#' . $id . ')"/></svg>';
}

/** Brand link (mark + wordmark). Uses the active generated logo from the Brand Studio when set. */
function ui_brand(int $size = 34, bool $wordmark = true, string $href = '/'): string
{
    $custom = (string) setting('brand_logo', '');
    $mark = $custom !== ''
        ? '<img class="brand-mark" src="' . e((string) upload_url($custom)) . '" alt="" width="' . $size . '" height="' . $size . '">'
        : ui_logo_mark($size);
    $word = $wordmark ? '<span class="brand-word">WOAI<span class="brand-bench">Bench</span><span class="brand-tld">.si</span></span>' : '';
    return '<a class="brand" href="' . e(url($href)) . '" aria-label="' . e('WOAIBench.si — ' . __('design.home')) . '">'
        . $mark . $word . '</a>';
}

/** Escape text and turn *word* into <em>word</em> (gold italic accent in display headings). */
function ui_em(string $text): string
{
    return (string) preg_replace('/\*([^*]+)\*/u', '<em>$1</em>', e($text));
}

/** Tiny heat square (no number) with a tooltip — for dense per-track strips. */
function ui_heat_mini(int|float|string|null $score, string $tip = ''): string
{
    $b = ui_heat_bucket($score);
    $cls = $b < 0 ? 'heat heat-mini heat-na' : 'heat heat-mini heat-' . $b;
    $label = $tip !== '' ? $tip : fmt_score($score);
    return '<span class="' . $cls . '" data-tip="' . e($label) . '" role="img" aria-label="' . e($label) . '"></span>';
}

/** Standard section heading: eyebrow, display title (supports *emphasis*), optional sub text. */
function ui_section_head(string $eyebrow, string $title, string $sub = '', string $class = '', string $tag = 'h2'): string
{
    $tag = in_array($tag, ['h1', 'h2', 'h3'], true) ? $tag : 'h2';
    return '<div class="section-head ' . e($class) . '">'
        . ($eyebrow !== '' ? '<span class="eyebrow">' . e($eyebrow) . '</span>' : '')
        . '<' . $tag . ' class="section-title">' . ui_em($title) . '</' . $tag . '>'
        . ($sub !== '' ? '<p class="section-sub">' . e($sub) . '</p>' : '')
        . '</div>';
}

/** Pulsing "live" status dot. */
function ui_live_dot(): string
{
    return '<span class="live-dot" aria-hidden="true"></span>';
}
