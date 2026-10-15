<?php
/**
 * HTTP utilities: security headers, CSP nonce, outbound JSON requests (cURL).
 */
declare(strict_types=1);

defined('WB') || exit;

final class Http
{
    private static ?string $nonce = null;

    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);
    }

    public static function nonce(): string
    {
        return self::$nonce ??= base64_encode(random_bytes(16));
    }

    /**
     * Default headers for every HTML/JSON response. Endpoints that serve untrusted model output
     * (render/arena frames) MUST replace the CSP with Http::sandboxHeaders().
     */
    public static function sendSecurityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        $nonce = self::nonce();
        $extraScript = trim((string) Config::get('csp_extra_script', ''));
        $csp = [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'" . ($extraScript !== '' ? ' ' . $extraScript : ''),
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob: https:",
            "font-src 'self' data:",
            "connect-src 'self'" . ($extraScript !== '' ? ' ' . $extraScript : ''),
            "frame-src 'self'",
            "worker-src 'self' blob:",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
        ];
        header('Content-Security-Policy: ' . implode('; ', $csp));
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-Frame-Options: SAMEORIGIN');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        if (self::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    /**
     * Headers for documents that contain untrusted, model-generated HTML/JS.
     * The CSP 'sandbox' directive gives the document an opaque origin even if it is opened
     * directly (not only inside <iframe sandbox>), so it can never read our cookies or call our APIs.
     */
    public static function sandboxHeaders(bool $allowEmbedAnywhere = false): void
    {
        if (headers_sent()) {
            return;
        }
        header_remove('Content-Security-Policy');
        header_remove('X-Frame-Options');
        header_remove('Cross-Origin-Opener-Policy');
        $cdn = 'https://cdnjs.cloudflare.com https://cdn.jsdelivr.net https://unpkg.com https://esm.sh https://cdn.skypack.dev https://threejs.org https://fonts.googleapis.com https://fonts.gstatic.com';
        $self = site_origin();
        header('Content-Security-Policy: ' . implode('; ', [
            'sandbox allow-scripts allow-pointer-lock',
            "default-src 'none'",
            "script-src 'unsafe-inline' 'unsafe-eval' blob: data: {$self} {$cdn}",
            "style-src 'unsafe-inline' {$cdn}",
            "img-src data: blob: {$self} {$cdn}",
            "font-src data: {$cdn}",
            "media-src data: blob:",
            "connect-src data: blob: {$cdn}",
            "worker-src blob: data:",
            "child-src blob: data:",
            "form-action 'none'",
            'frame-ancestors ' . ($allowEmbedAnywhere ? '*' : "'self'"),
        ]));
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: private, max-age=300');
    }

    /** CORS for public read-only API responses. */
    public static function corsPublic(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Cross-Origin-Resource-Policy: cross-origin');
    }

    /**
     * Outbound JSON request via cURL.
     * Returns ['status' => int, 'json' => ?array, 'body' => string, 'error' => ?string, 'ms' => int, 'headers' => array]
     */
    public static function request(string $method, string $url, ?array $json = null, array $headers = [], int $timeout = 60): array
    {
        $ch = curl_init($url);
        $respHeaders = [];
        $hdrs = ['Accept: application/json'];
        foreach ($headers as $k => $v) {
            $hdrs[] = is_int($k) ? $v : ($k . ': ' . $v);
        }
        if ($json !== null) {
            $hdrs[] = 'Content-Type: application/json';
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $hdrs,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'WOAIBench/' . WB_VERSION . ' (+https://woaibench.si)',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($json !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        }
        $t0 = microtime(true);
        $body = curl_exec($ch);
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $err = $body === false ? curl_error($ch) : null;
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $body = is_string($body) ? $body : '';
        $decoded = $body !== '' ? json_decode($body, true) : null;
        return [
            'status' => $status,
            'json' => is_array($decoded) ? $decoded : null,
            'body' => $body,
            'error' => $err,
            'ms' => $ms,
            'headers' => $respHeaders,
        ];
    }
}

/** Nonce attribute for inline <script> tags: <script<?= nonce_attr() ?>> */
function nonce_attr(): string
{
    return ' nonce="' . e(Http::nonce()) . '"';
}
