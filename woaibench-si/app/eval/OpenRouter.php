<?php
/**
 * OpenRouter API client (chat completions, model catalogue, image generation).
 * Every response is normalized so callers never touch raw provider JSON, and every failure is
 * classified as retryable (infrastructure) or not, so model failures are never confused with outages.
 */
declare(strict_types=1);

namespace WB\Eval;

defined('WB') || exit;

final class OpenRouter implements ChatClient
{
    private const DEFAULT_BASE = 'https://openrouter.ai/api/v1';
    private const RETRYABLE_CODES = [408, 409, 425, 429];
    /** 403 wording that means the API key itself is blocked (spend limit, credits), not the request. */
    private const KEY_LIMIT_RE = '/api[\s_-]*key|key\s+limit|credit|billing|payment|quota|insufficient\s+(funds|balance)|spend(ing)?\s+limit/i';
    private const MAX_IMAGE_BYTES = 20 * 1024 * 1024;

    private string $key;
    private string $base;

    /** Defaults come from Config (openrouter_key / openrouter_base). */
    public function __construct(?string $key = null, ?string $base = null)
    {
        $this->key = trim((string) ($key ?? \Config::get('openrouter_key', '')));
        $base = trim((string) ($base ?? \Config::get('openrouter_base', self::DEFAULT_BASE)));
        $this->base = rtrim($base !== '' ? $base : self::DEFAULT_BASE, '/');
    }

    /** POST /chat/completions → normalized response (see ChatClient). Never throws. */
    public function chat(array $payload, int $timeout = 240): array
    {
        if ($this->key === '') {
            $out = self::failure('OpenRouter API key is not configured', 0, false);
            $out['halt'] = ['code' => 401, 'message' => 'OpenRouter API key is not configured'];
            return $out;
        }
        $payload['usage'] = array_merge(is_array($payload['usage'] ?? null) ? $payload['usage'] : [], ['include' => true]);
        $payload['stream'] = false;
        $res = $this->send('POST', '/chat/completions', $payload, max(5, $timeout));
        $out = self::normalize($res['status'], $res['json'], $res['body'], $res['error'], $res['ms']);
        if (!$out['ok'] && in_array($res['status'], [429, 503], true)) {
            $out['retry_after'] = self::retryAfter($res['headers'] ?? []);
        }
        return $out;
    }

    /** GET /models → raw `data[]` list of the OpenRouter catalogue ([] on failure). */
    public function models(): array
    {
        $res = $this->send('GET', '/models', null, 60);
        $data = $res['json']['data'] ?? null;
        if ($res['status'] !== 200 || !is_array($data)) {
            log_error('OpenRouter models() failed', ['status' => $res['status'], 'error' => $res['error']]);
            return [];
        }
        return array_values(array_filter($data, 'is_array'));
    }

    /**
     * Generate one image → ['ok', 'bytes' (binary), 'mime', 'error'].
     * Tries the OpenAI-style images endpoint first, then chat completions with image output.
     */
    public function images(string $prompt, string $model, string $size = '1024x1024'): array
    {
        if ($this->key === '') {
            return ['ok' => false, 'bytes' => null, 'mime' => null, 'error' => 'OpenRouter API key is not configured'];
        }
        $first = $this->imagesEndpoint($prompt, $model, $size);
        if ($first['ok']) {
            return $first;
        }
        $second = $this->imagesViaChat($prompt, $model, $size);
        if ($second['ok']) {
            return $second;
        }
        $second['error'] = 'images endpoint: ' . $first['error'] . ' | chat fallback: ' . $second['error'];
        return $second;
    }

    /** USD cost of one call: usage.cost when the API reported it, else tokens × model prices (per 1M). */
    public static function costFromUsage(array $usage, array $modelRow): float
    {
        if (isset($usage['cost']) && is_numeric($usage['cost'])) {
            return max(0.0, (float) $usage['cost']);
        }
        $in = (float) ($usage['prompt_tokens'] ?? 0);
        $out = (float) ($usage['completion_tokens'] ?? 0);
        $priceIn = is_numeric($modelRow['price_in'] ?? null) ? (float) $modelRow['price_in'] : 0.0;
        $priceOut = is_numeric($modelRow['price_out'] ?? null) ? (float) $modelRow['price_out'] : 0.0;
        return max(0.0, ($in * $priceIn + $out * $priceOut) / 1_000_000);
    }

    /** True for HTTP / provider error codes that are worth retrying later. */
    public static function isRetryableCode(int $code): bool
    {
        return $code === 0 || $code >= 500 || in_array($code, self::RETRYABLE_CODES, true);
    }

    /**
     * Provider halt: the account, not the model or the request, is the problem — invalid or missing key (401), no
     * credit left (402), or a key blocked by its spend limit (403 with key / credit wording). Every further call
     * would fail the same way, so the worker pauses instead of burning the runs' tries (ARCHITECTURE §5.7).
     * Returns ['code', 'message'] or null.
     */
    public static function haltOf(int $code, string $message): ?array
    {
        $halt = in_array($code, [401, 402], true) || ($code === 403 && preg_match(self::KEY_LIMIT_RE, $message) === 1);
        return $halt ? ['code' => $code, 'message' => mb_substr(trim($message), 0, 300)] : null;
    }

    /** Seconds from a Retry-After header (delta seconds or an HTTP date), capped at 1 hour; null when absent. */
    public static function retryAfter(array $headers): ?int
    {
        $v = trim((string) ($headers['retry-after'] ?? ''));
        if ($v === '') {
            return null;
        }
        $s = ctype_digit($v) ? (int) $v : (($t = strtotime($v)) !== false ? $t - time() : null);
        return $s === null ? null : max(0, min(3600, $s));
    }

    /**
     * Turn a raw HTTP result into the normalized chat response. Public so tests can feed it
     * synthetic bodies (200-with-error, content parts, empty choices …).
     */
    public static function normalize(int $status, ?array $json, string $body, ?string $curlError, int $ms): array
    {
        if (($curlError !== null && $curlError !== '') || $status === 0) {
            return self::failure('Network error: ' . ($curlError ?: 'no response'), $status, true, $ms);
        }
        if ($json === null) {
            $snippet = mb_substr(trim(strip_tags($body)), 0, 200);
            $out = self::failure("HTTP {$status}: invalid JSON response" . ($snippet !== '' ? " ({$snippet})" : ''), $status, $status === 200 || self::isRetryableCode($status), $ms);
            $out['halt'] = in_array($status, [401, 402], true) ? self::haltOf($status, 'HTTP ' . $status . ($snippet !== '' ? ': ' . $snippet : '')) : null;
            return $out;
        }
        $out = self::blank($status, $ms);
        $out['id'] = isset($json['id']) && is_scalar($json['id']) ? (string) $json['id'] : null;
        $out['provider'] = isset($json['provider']) && is_scalar($json['provider']) ? (string) $json['provider'] : null;
        $out['usage'] = self::usage(is_array($json['usage'] ?? null) ? $json['usage'] : []);

        if (isset($json['error'])) {
            return self::withError($out, $json['error'], $status);
        }
        if ($status >= 400) {
            return self::withError($out, ['code' => $status, 'message' => 'HTTP ' . $status], $status);
        }
        $choice = is_array($json['choices'] ?? null) ? ($json['choices'][0] ?? null) : null;
        if (!is_array($choice)) {
            $out['error'] = 'Provider returned no choices';
            $out['retryable'] = true;
            return $out;
        }
        if (isset($choice['error'])) {
            return self::withError($out, $choice['error'], $status);
        }
        $msg = is_array($choice['message'] ?? null) ? $choice['message'] : [];
        $out['content'] = self::contentText($msg['content'] ?? '');
        $out['reasoning'] = self::reasoningText($msg);
        $out['tool_calls'] = self::toolCalls($msg['tool_calls'] ?? []);
        $out['finish_reason'] = isset($choice['finish_reason']) && is_string($choice['finish_reason']) ? $choice['finish_reason'] : null;
        $out['message'] = self::assistantMessage($msg, $out['content'], $out['tool_calls']);
        if ($out['finish_reason'] === 'error') {
            $out['error'] = 'Provider reported finish_reason=error';
            $out['retryable'] = true;
            return $out;
        }
        $out['ok'] = true;
        return $out;
    }

    /** Flatten string | list of content parts into plain text. */
    public static function contentText(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }
        if (!is_array($content)) {
            return '';
        }
        $parts = [];
        foreach ($content as $part) {
            if (is_string($part)) {
                $parts[] = $part;
            } elseif (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                $parts[] = $part['text'];
            }
        }
        return implode('', $parts);
    }

    /* ------------------------------------------------------------------ internals */

    private static function blank(int $status, int $ms): array
    {
        return [
            'ok' => false, 'status' => $status, 'content' => '', 'reasoning' => null, 'tool_calls' => [],
            'message' => ['role' => 'assistant', 'content' => ''], 'finish_reason' => null,
            'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0, 'reasoning_tokens' => 0, 'cost' => null],
            'provider' => null, 'id' => null, 'ms' => $ms, 'error' => null, 'retryable' => false,
            'halt' => null, 'retry_after' => null,
        ];
    }

    private static function failure(string $error, int $status, bool $retryable, int $ms = 0): array
    {
        $out = self::blank($status, $ms);
        $out['error'] = $error;
        $out['retryable'] = $retryable;
        return $out;
    }

    /** Apply an OpenRouter error object ({code, message, metadata}) to a response. */
    private static function withError(array $out, mixed $error, int $status): array
    {
        $code = $status >= 400 ? $status : 0;
        $message = 'Unknown provider error';
        if (is_array($error)) {
            if (isset($error['code']) && is_numeric($error['code'])) {
                $code = (int) $error['code'];
            }
            if (isset($error['message']) && is_scalar($error['message'])) {
                $message = (string) $error['message'];
            }
            $raw = $error['metadata']['raw'] ?? null;
            if (is_string($raw) && $raw !== '') {
                $message .= ' — ' . mb_substr($raw, 0, 300);
            }
            $provider = $error['metadata']['provider_name'] ?? null;
            if (is_string($provider) && $provider !== '' && $out['provider'] === null) {
                $out['provider'] = $provider;
            }
        } elseif (is_string($error) && $error !== '') {
            $message = $error;
        }
        $out['ok'] = false;
        $out['error'] = ($code ? "[{$code}] " : '') . mb_substr($message, 0, 600);
        // A 2xx carrying an error object without a usable code (code 0) is a provider hiccup → retry.
        $out['retryable'] = self::isRetryableCode($code) || ($status >= 400 && self::isRetryableCode($status));
        $out['halt'] = self::haltOf($code, $message) ?? ($status !== $code ? self::haltOf($status, $message) : null);
        if ($out['halt'] !== null) {
            $out['retryable'] = false;
        }
        return $out;
    }

    private static function usage(array $u): array
    {
        $reasoning = $u['completion_tokens_details']['reasoning_tokens'] ?? ($u['reasoning_tokens'] ?? 0);
        $cost = $u['cost'] ?? null;
        return [
            'prompt_tokens' => max(0, (int) ($u['prompt_tokens'] ?? 0)),
            'completion_tokens' => max(0, (int) ($u['completion_tokens'] ?? 0)),
            'reasoning_tokens' => max(0, is_numeric($reasoning) ? (int) $reasoning : 0),
            'cost' => is_numeric($cost) ? (float) $cost : null,
        ];
    }

    private static function reasoningText(array $msg): ?string
    {
        foreach (['reasoning', 'reasoning_content'] as $k) {
            if (isset($msg[$k]) && is_string($msg[$k]) && trim($msg[$k]) !== '') {
                return $msg[$k];
            }
        }
        $parts = [];
        foreach (is_array($msg['reasoning_details'] ?? null) ? $msg['reasoning_details'] : [] as $d) {
            foreach (['text', 'summary'] as $k) {
                if (is_array($d) && isset($d[$k]) && is_string($d[$k]) && $d[$k] !== '') {
                    $parts[] = $d[$k];
                }
            }
        }
        return $parts ? implode("\n", $parts) : null;
    }

    /** Normalize tool calls to OpenAI format with string arguments. */
    private static function toolCalls(mixed $calls): array
    {
        if (!is_array($calls)) {
            return [];
        }
        $out = [];
        foreach (array_values($calls) as $i => $c) {
            $fn = is_array($c['function'] ?? null) ? $c['function'] : [];
            $name = isset($fn['name']) && is_scalar($fn['name']) ? (string) $fn['name'] : '';
            $args = $fn['arguments'] ?? '';
            if (!is_string($args)) {
                $args = $args === null ? '' : to_json($args);
            }
            $id = isset($c['id']) && is_scalar($c['id']) && (string) $c['id'] !== '' ? (string) $c['id'] : 'call_' . ($i + 1);
            $out[] = ['id' => $id, 'type' => 'function', 'function' => ['name' => $name, 'arguments' => $args]];
        }
        return $out;
    }

    /** Assistant message suitable for sending back in a multi-turn conversation. */
    private static function assistantMessage(array $msg, string $content, array $toolCalls): array
    {
        $m = ['role' => 'assistant', 'content' => $content];
        if ($toolCalls) {
            $m['tool_calls'] = $toolCalls;
        }
        if (isset($msg['reasoning_details']) && is_array($msg['reasoning_details']) && $msg['reasoning_details']) {
            $m['reasoning_details'] = $msg['reasoning_details'];
        }
        return $m;
    }

    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->key,
            'HTTP-Referer' => site_origin(),
            'X-Title' => 'WOAIBench.si',
        ];
    }

    private function send(string $method, string $path, ?array $json, int $timeout): array
    {
        try {
            return \Http::request($method, $this->base . $path, $json, $this->headers(), $timeout);
        } catch (\Throwable $e) {
            return ['status' => 0, 'json' => null, 'body' => '', 'error' => $e->getMessage(), 'ms' => 0, 'headers' => []];
        }
    }

    /* ------------------------------------------------------------------ images */

    private function imagesEndpoint(string $prompt, string $model, string $size): array
    {
        $res = $this->send('POST', '/images/generations', ['model' => $model, 'prompt' => $prompt, 'size' => $size, 'n' => 1], 110);
        $item = $res['json']['data'][0] ?? null;
        if ($res['status'] !== 200 || !is_array($item)) {
            $err = $res['json']['error']['message'] ?? $res['error'] ?? ('HTTP ' . $res['status']);
            return self::imageFail(is_string($err) ? $err : 'HTTP ' . $res['status']);
        }
        if (isset($item['b64_json']) && is_string($item['b64_json'])) {
            return self::imageFromBytes(base64_decode($item['b64_json'], true));
        }
        if (isset($item['url']) && is_string($item['url'])) {
            return $this->imageFromUrl($item['url']);
        }
        return self::imageFail('no image in response');
    }

    private function imagesViaChat(string $prompt, string $model, string $size): array
    {
        $res = $this->chatRaw([
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => $prompt . "\n\n(Output size: {$size}.)"]],
            'modalities' => ['image', 'text'],
        ]);
        if (!$res['ok']) {
            return self::imageFail((string) $res['error']);
        }
        $msg = $res['json']['choices'][0]['message'] ?? [];
        $url = $msg['images'][0]['image_url']['url'] ?? ($msg['images'][0]['url'] ?? null);
        if (!is_string($url) && is_array($msg['content'] ?? null)) {
            foreach ($msg['content'] as $part) {
                if (is_array($part) && isset($part['image_url']['url']) && is_string($part['image_url']['url'])) {
                    $url = $part['image_url']['url'];
                    break;
                }
            }
        }
        if (!is_string($url) || $url === '') {
            return self::imageFail('model returned no image');
        }
        return str_starts_with($url, 'data:') ? self::imageFromDataUrl($url) : $this->imageFromUrl($url);
    }

    /** Chat call that keeps the raw JSON (image parts are not part of the normalized shape). */
    private function chatRaw(array $payload): array
    {
        $payload['usage'] = ['include' => true];
        $res = $this->send('POST', '/chat/completions', $payload, 110);
        $norm = self::normalize($res['status'], $res['json'], $res['body'], $res['error'], $res['ms']);
        return ['ok' => $norm['ok'], 'error' => $norm['error'], 'json' => $res['json'] ?? []];
    }

    private static function imageFromDataUrl(string $url): array
    {
        if (!preg_match('#^data:[\w/+.-]*;base64,#', $url, $m)) {
            return self::imageFail('unsupported data URL');
        }
        return self::imageFromBytes(base64_decode(substr($url, strlen($m[0])), true));
    }

    private function imageFromUrl(string $url): array
    {
        $target = $this->safeImageTarget($url);
        if ($target === null) {
            return self::imageFail('refusing to fetch image URL');
        }
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 60,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXFILESIZE => self::MAX_IMAGE_BYTES,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'WOAIBench/' . WB_VERSION,
        ];
        if ($target['ip'] !== null) {
            // Connect to the address that was vetted, never to a second DNS answer (rebinding, private AAAA).
            $opts[CURLOPT_RESOLVE] = [$target['host'] . ':' . $target['port'] . ':' . (str_contains($target['ip'], ':') ? '[' . $target['ip'] . ']' : $target['ip'])];
        }
        curl_setopt_array($ch, $opts);
        $bytes = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($bytes) || $status !== 200) {
            return self::imageFail('image download failed (HTTP ' . $status . ')');
        }
        return self::imageFromBytes($bytes);
    }

    /**
     * SSRF guard for image URLs returned by the provider: only http(s), and every address the host resolves to
     * (A and AAAA) must be public — then the download is pinned to one of those vetted addresses.
     * Returns ['host', 'port', 'ip' (null = no pinning: the API base host itself, e.g. the local mock)] or null.
     */
    private function safeImageTarget(string $url): ?array
    {
        $p = parse_url($url);
        $scheme = strtolower((string) ($p['scheme'] ?? ''));
        $host = strtolower(trim((string) ($p['host'] ?? ''), '[]'));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }
        $port = (int) ($p['port'] ?? ($scheme === 'https' ? 443 : 80));
        if ($host === strtolower((string) parse_url($this->base, PHP_URL_HOST))) {
            return ['host' => $host, 'port' => $port, 'ip' => null];
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::resolveAll($host);
        if (!$ips) {
            return null;
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return null;
            }
        }
        return ['host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    /** Every IPv4 and IPv6 address of $host ([] when it does not resolve). */
    private static function resolveAll(string $host): array
    {
        $ips = [];
        $records = function_exists('dns_get_record') ? @dns_get_record($host, DNS_A | DNS_AAAA) : false;
        foreach (is_array($records) ? $records : [] as $r) {
            $ip = $r['ip'] ?? $r['ipv6'] ?? null;
            if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP)) {
                $ips[] = $ip;
            }
        }
        if (!$ips) {
            // dns_get_record unavailable or empty (some resolvers / hosts files): IPv4 lookup as the fallback.
            foreach (@gethostbynamel($host) ?: [] as $ip) {
                $ips[] = $ip;
            }
        }
        return array_values(array_unique($ips));
    }

    /** Validate magic bytes (PNG, JPEG, WebP) and return the image. */
    private static function imageFromBytes(string|false $bytes): array
    {
        if (!is_string($bytes) || $bytes === '') {
            return self::imageFail('empty or undecodable image data');
        }
        if (strlen($bytes) > self::MAX_IMAGE_BYTES) {
            return self::imageFail('image too large');
        }
        $mime = match (true) {
            str_starts_with($bytes, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            strlen($bytes) > 12 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            default => null,
        };
        if ($mime === null) {
            return self::imageFail('data is not a PNG, JPEG or WebP image');
        }
        return ['ok' => true, 'bytes' => $bytes, 'mime' => $mime, 'error' => null];
    }

    private static function imageFail(string $error): array
    {
        return ['ok' => false, 'bytes' => null, 'mime' => null, 'error' => $error];
    }
}
