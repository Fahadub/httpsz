<?php

declare(strict_types=1);

namespace Udeyou\Core;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private readonly array $headers,
        private readonly array $post,
        private readonly string $rawBody,
        public readonly string $ip,
    ) {
    }

    public static function fromGlobals(): self
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        // Support installs where the root .htaccess rewrites into /public.
        if (str_starts_with($path, '/public/')) {
            $path = substr($path, 7);
        }
        $path = '/' . trim($path, '/');

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }
        // Apache/LiteSpeed sometimes expose Authorization only via REDIRECT_*.
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        if ($auth !== null) {
            $headers['authorization'] = $auth;
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $path,
            $headers,
            $_POST,
            (string) file_get_contents('php://input'),
            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** Parsed JSON object body, or null when the body is not a JSON object. */
    public function json(): ?array
    {
        $decoded = json_decode($this->rawBody, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function input(string $key, string $default = ''): string
    {
        $value = $this->post[$key] ?? $default;
        return is_string($value) ? trim($value) : $default;
    }

    /** API key from "Authorization: Bearer sk_..." or "X-API-Key: sk_...". */
    public function apiKey(): ?string
    {
        $auth = $this->header('authorization');
        if ($auth !== null && preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) {
            return $m[1];
        }
        return $this->header('x-api-key');
    }
}
