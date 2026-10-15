<?php
/**
 * Global helper functions. Every page/module may rely on these.
 */
declare(strict_types=1);

defined('WB') || exit;

/** HTML-escape for text and attribute contexts. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** JSON for embedding inside <script> tags (safe against </script> and HTML injection). */
function js_json(mixed $value): string
{
    return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: 'null';
}

/** Decode JSON text to array; returns $default on null/invalid input. */
function json_arr(?string $json, array $default = []): array
{
    if ($json === null || $json === '') {
        return $default;
    }
    $data = json_decode($json, true);
    return is_array($data) ? $data : $default;
}

/** Encode to JSON for storage. */
function to_json(mixed $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: 'null';
}

/** Current UTC timestamp in MySQL DATETIME format. */
function now(int $offsetSeconds = 0): string
{
    return gmdate('Y-m-d H:i:s', time() + $offsetSeconds);
}

/** URL path prefix where the app lives ('' at domain root, '/sub' in a subfolder). */
function base_path(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $configured = class_exists('Config', false) ? Config::get('base_path', null) : null;
    if (is_string($configured)) {
        return $base = rtrim($configured, '/');
    }
    if (WB_CLI) {
        return $base = '';
    }
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $dir = rtrim(dirname($script), '/');
    // The installer lives in /install — the app base is its parent.
    if (defined('WB_INSTALLER') && str_ends_with($dir, '/install')) {
        $dir = substr($dir, 0, -strlen('/install'));
    }
    return $base = ($dir === '/' || $dir === '.') ? '' : $dir;
}

/** Absolute origin, e.g. https://woaibench.si (config 'site_url' wins). */
function site_origin(): string
{
    $configured = Config::get('site_url');
    if (is_string($configured) && $configured !== '') {
        $p = parse_url($configured);
        if (!empty($p['scheme']) && !empty($p['host'])) {
            return $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        }
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', $host) ?: 'localhost';
    return ($https ? 'https' : 'http') . '://' . $host;
}

/**
 * Build an app URL. url('/model/x', ['tab' => 'runs']) -> /model/x?tab=runs
 * Pass $absolute = true for canonical/OG/API links.
 */
function url(string $path = '/', array $query = [], bool $absolute = false): string
{
    if (preg_match('#^https?://#i', $path)) {
        $u = $path;
    } else {
        $u = base_path() . '/' . ltrim($path, '/');
    }
    $query = array_filter($query, static fn($v) => $v !== null && $v !== '');
    if ($query) {
        $u .= (str_contains($u, '?') ? '&' : '?') . http_build_query($query);
    }
    if ($absolute && !preg_match('#^https?://#i', $u)) {
        $u = site_origin() . $u;
    }
    return $u;
}

/** Versioned URL to a file in /assets. */
function asset(string $path): string
{
    $path = ltrim($path, '/');
    $file = WB_ROOT . '/assets/' . $path;
    $v = is_file($file) ? (string) filemtime($file) : WB_VERSION;
    return base_path() . '/assets/' . $path . '?v=' . $v;
}

/** URL to an uploaded file (screenshots, brand assets). */
function upload_url(?string $relative): ?string
{
    if ($relative === null || $relative === '') {
        return null;
    }
    return base_path() . '/uploads/' . ltrim($relative, '/');
}

/** Current request path relative to the app base, always starting with '/'. */
function current_path(): string
{
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH) ?: '/';
    $path = rawurldecode($path);
    $base = base_path();
    if ($base !== '' && str_starts_with($path, $base)) {
        $path = substr($path, strlen($base));
    }
    if (str_starts_with($path, '/index.php')) {
        $path = substr($path, strlen('/index.php'));
    }
    $path = '/' . ltrim($path, '/');
    return $path === '/' ? '/' : rtrim($path, '/');
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Read a GET/POST value (POST wins). Arrays are returned as-is; strings are trimmed. */
function input(string $key, mixed $default = null): mixed
{
    $v = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

/** Integer input with bounds. */
function input_int(string $key, int $default = 0, ?int $min = null, ?int $max = null): int
{
    $v = input($key);
    $n = is_numeric($v) ? (int) $v : $default;
    if ($min !== null) {
        $n = max($min, $n);
    }
    if ($max !== null) {
        $n = min($max, $n);
    }
    return $n;
}

/** Parse a JSON request body (for fetch() POSTs). */
function json_body(): array
{
    static $body = null;
    if ($body !== null) {
        return $body;
    }
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return $body = is_array($data) ? $data : [];
}

function wants_json(): bool
{
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $ctype = $_SERVER['CONTENT_TYPE'] ?? '';
    $path = WB_CLI ? '' : current_path();
    return str_contains($accept, 'application/json')
        || str_contains($ctype, 'application/json')
        || str_starts_with($path, '/api/')
        || str_starts_with($path, '/admin/api/');
}

function redirect(string $to, int $code = 302): never
{
    if (!preg_match('#^https?://#i', $to) && !str_starts_with($to, base_path() . '/') && $to !== base_path()) {
        $to = url($to);
    }
    header('Location: ' . $to, true, $code);
    exit;
}

/** Redirect back to the referring page on this site (fallback to $fallback). */
function redirect_back(string $fallback = '/'): never
{
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    if ($ref !== '' && str_starts_with($ref, site_origin() . base_path() . '/')) {
        header('Location: ' . $ref, true, 302);
        exit;
    }
    redirect($fallback);
}

function json_out(mixed $data, int $code = 200, array $headers = []): never
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        foreach ($headers as $k => $v) {
            header($k . ': ' . $v);
        }
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

/** Stop with an HTTP error page (or JSON error for API requests). */
function abort(int $code = 404, ?string $message = null): never
{
    http_response_code($code);
    if (wants_json()) {
        json_out(['ok' => false, 'error' => $message ?? ('HTTP ' . $code)], $code);
    }
    $tpl = in_array($code, [403, 404, 419, 429, 500], true) ? 'errors/' . $code : 'errors/generic';
    if (!is_file(WB_APP . '/views/' . $tpl . '.php')) {
        $tpl = 'errors/generic';
    }
    View::render($tpl, ['code' => $code, 'message' => $message], 'layout');
    exit;
}

/** Client IP (Hostinger sits behind a proxy that sets X-Forwarded-For only when trusted in config). */
function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (Config::get('trust_proxy', false)) {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $h) {
            if (!empty($_SERVER[$h])) {
                $cand = trim(explode(',', (string) $_SERVER[$h])[0]);
                if (filter_var($cand, FILTER_VALIDATE_IP)) {
                    return $cand;
                }
            }
        }
    }
    return $ip;
}

/** Salted, non-reversible client identifier (privacy friendly). */
function ip_hash(string $extra = ''): string
{
    return hash_hmac('sha256', client_ip() . '|' . $extra, app_secret());
}

function app_secret(): string
{
    $s = (string) Config::get('app_secret', '');
    return $s !== '' ? $s : 'wb-insecure-default-secret-change-me';
}

function random_token(int $bytes = 16): string
{
    return bin2hex(random_bytes($bytes));
}

function slugify(string $text, int $max = 120): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('~[^a-z0-9]+~', '-', $text) ?? '';
    $text = trim($text, '-');
    if ($text === '') {
        $text = 'item-' . substr(random_token(4), 0, 8);
    }
    return substr($text, 0, $max);
}

/** Append a line to storage/logs/app-YYYY-MM-DD.log */
function log_error(string $message, array $context = []): void
{
    $dir = WB_STORAGE . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $line = '[' . gmdate('c') . '] ' . $message;
    if ($context) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
    @file_put_contents($dir . '/app-' . gmdate('Y-m-d') . '.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/** Flash messages (stored in session, shown once). type: success|error|warn|info */
function flash(string $type, string $message): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }
}

function flashes(): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return [];
    }
    $f = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $f;
}

/** Old form input after a failed POST (set with keep_old()). */
function keep_old(array $data): void
{
    $_SESSION['_old'] = $data;
}

function old(string $key, mixed $default = ''): mixed
{
    static $old = null;
    if ($old === null) {
        $old = $_SESSION['_old'] ?? [];
        unset($_SESSION['_old']);
    }
    return $old[$key] ?? $default;
}

/* ------------------------------------------------------------------ formatting */

/** Locale-aware number formatting (Western digits for both languages — clearer for data). */
function fmt_num(int|float|string|null $n, int $decimals = 0): string
{
    if ($n === null || $n === '') {
        return '—';
    }
    return number_format((float) $n, $decimals, '.', ',');
}

/** Score with one decimal, or an em dash. */
function fmt_score(int|float|string|null $n): string
{
    return ($n === null || $n === '') ? '—' : number_format((float) $n, 1, '.', '');
}

/** USD formatting that stays readable for tiny per-task costs. */
function fmt_money(int|float|string|null $usd): string
{
    if ($usd === null || $usd === '') {
        return '—';
    }
    $usd = (float) $usd;
    if ($usd == 0.0) {
        return '$0';
    }
    if (abs($usd) < 0.01) {
        return '$' . rtrim(rtrim(number_format($usd, 4, '.', ''), '0'), '.');
    }
    if (abs($usd) < 100) {
        return '$' . number_format($usd, 2, '.', ',');
    }
    return '$' . number_format($usd, 0, '.', ',');
}

/** Price per 1M tokens. */
function fmt_price(int|float|string|null $perMillion): string
{
    if ($perMillion === null || $perMillion === '') {
        return '—';
    }
    return fmt_money((float) $perMillion) . '/M';
}

function fmt_ms(int|float|string|null $ms): string
{
    if ($ms === null || $ms === '') {
        return '—';
    }
    $ms = (float) $ms;
    if ($ms < 1000) {
        return number_format($ms, 0) . ' ms';
    }
    if ($ms < 60000) {
        return number_format($ms / 1000, 1) . ' s';
    }
    return number_format($ms / 60000, 1) . ' min';
}

function fmt_tokens(int|float|string|null $n): string
{
    if ($n === null || $n === '') {
        return '—';
    }
    $n = (float) $n;
    if ($n >= 1_000_000) {
        return number_format($n / 1_000_000, 1) . 'M';
    }
    if ($n >= 1000) {
        return number_format($n / 1000, 1) . 'k';
    }
    return number_format($n, 0);
}

/** Date in the current language. $style: 'date' | 'datetime' | 'relative' */
function fmt_date(?string $datetime, string $style = 'date'): string
{
    if (!$datetime) {
        return '—';
    }
    $ts = strtotime($datetime . ' UTC');
    if ($ts === false) {
        return e($datetime);
    }
    if ($style === 'relative') {
        $diff = time() - $ts;
        $ar = lang() === 'ar';
        if ($diff < 60) {
            return $ar ? 'الآن' : 'just now';
        }
        $units = [[86400 * 30, 'mo', 'شهر'], [86400, 'd', 'يوم'], [3600, 'h', 'ساعة'], [60, 'min', 'دقيقة']];
        foreach ($units as [$sec, $en, $arU]) {
            if ($diff >= $sec) {
                $n = (int) floor($diff / $sec);
                return $ar ? "منذ {$n} {$arU}" : "{$n} {$en} ago";
            }
        }
    }
    if (class_exists('IntlDateFormatter')) {
        $fmt = new IntlDateFormatter(
            lang() === 'ar' ? 'ar-u-nu-latn' : 'en',
            IntlDateFormatter::MEDIUM,
            $style === 'datetime' ? IntlDateFormatter::SHORT : IntlDateFormatter::NONE,
            'UTC'
        );
        $out = $fmt->format($ts);
        if ($out !== false) {
            return $out;
        }
    }
    return gmdate($style === 'datetime' ? 'Y-m-d H:i' : 'Y-m-d', $ts);
}

/** Clamp a number. */
function clamp(float $v, float $min, float $max): float
{
    return max($min, min($max, $v));
}

/** Mean of a numeric array (null when empty). */
function mean(array $xs): ?float
{
    $xs = array_values(array_filter($xs, static fn($x) => $x !== null));
    return $xs ? array_sum($xs) / count($xs) : null;
}

/** Median of a numeric array (null when empty). */
function median(array $xs): ?float
{
    $xs = array_values(array_filter($xs, static fn($x) => $x !== null));
    $n = count($xs);
    if ($n === 0) {
        return null;
    }
    sort($xs);
    $mid = intdiv($n, 2);
    return $n % 2 ? (float) $xs[$mid] : ($xs[$mid - 1] + $xs[$mid]) / 2;
}

/** Sample standard deviation (0 for < 2 values). */
function stddev(array $xs): float
{
    $xs = array_values(array_filter($xs, static fn($x) => $x !== null));
    $n = count($xs);
    if ($n < 2) {
        return 0.0;
    }
    $m = array_sum($xs) / $n;
    $ss = 0.0;
    foreach ($xs as $x) {
        $ss += ($x - $m) ** 2;
    }
    return sqrt($ss / ($n - 1));
}

/** Percentile (0..100) with linear interpolation on a sorted copy. */
function percentile(array $xs, float $p): ?float
{
    $xs = array_values(array_filter($xs, static fn($x) => $x !== null));
    $n = count($xs);
    if ($n === 0) {
        return null;
    }
    sort($xs);
    $pos = ($n - 1) * $p / 100;
    $lo = (int) floor($pos);
    $hi = (int) ceil($pos);
    return $xs[$lo] + ($xs[$hi] - $xs[$lo]) * ($pos - $lo);
}
