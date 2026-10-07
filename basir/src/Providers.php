<?php
/**
 * بصير — طبقة موحّدة لأي موفر ذكاء اصطناعي يدعم الرؤية.
 *
 * ثلاثة بروتوكولات تغطي تقريباً كل الموفرين:
 *  - openai    : أي خادم متوافق مع OpenAI (OpenAI, OpenRouter, Groq, Mistral, Qwen, Ollama, LM Studio, vLLM ...)
 *  - anthropic : Anthropic Claude (Messages API)
 *  - gemini    : Google Gemini (generateContent)
 */
declare(strict_types=1);

final class ProviderError extends RuntimeException
{
    public function __construct(string $speak, public readonly string $detail = '', public readonly int $status = 0)
    {
        parent::__construct($speak);
    }
}

final class Providers
{
    public const PROTOCOLS = ['openai', 'anthropic', 'gemini'];

    public const PRESETS = [
        'openai' => [
            'label' => 'OpenAI', 'protocol' => 'openai', 'needs_key' => true,
            'base_url' => 'https://api.openai.com/v1', 'model' => 'gpt-4.1-mini',
            'models' => ['gpt-4.1-mini', 'gpt-4.1', 'gpt-4o', 'gpt-4o-mini', 'gpt-5-mini', 'gpt-5'],
        ],
        'anthropic' => [
            'label' => 'Anthropic Claude', 'protocol' => 'anthropic', 'needs_key' => true,
            'base_url' => 'https://api.anthropic.com', 'model' => 'claude-opus-5-5',
            'models' => ['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-4-5'],
        ],
        'gemini' => [
            'label' => 'Google Gemini', 'protocol' => 'gemini', 'needs_key' => true,
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta', 'model' => 'gemini-2.5-flash',
            'models' => ['gemini-2.5-flash', 'gemini-2.5-pro', 'gemini-2.5-flash-lite'],
        ],
        'openrouter' => [
            'label' => 'OpenRouter', 'protocol' => 'openai', 'needs_key' => true,
            'base_url' => 'https://openrouter.ai/api/v1', 'model' => 'google/gemini-2.5-flash',
            'models' => ['google/gemini-2.5-flash', 'openai/gpt-4.1-mini', 'meta-llama/llama-4-maverick', 'qwen/qwen2.5-vl-72b-instruct'],
        ],
        'groq' => [
            'label' => 'Groq', 'protocol' => 'openai', 'needs_key' => true,
            'base_url' => 'https://api.groq.com/openai/v1', 'model' => 'meta-llama/llama-4-scout-17b-16e-instruct',
            'models' => ['meta-llama/llama-4-scout-17b-16e-instruct', 'meta-llama/llama-4-maverick-17b-128e-instruct'],
        ],
        'mistral' => [
            'label' => 'Mistral', 'protocol' => 'openai', 'needs_key' => true,
            'base_url' => 'https://api.mistral.ai/v1', 'model' => 'mistral-small-latest',
            'models' => ['mistral-small-latest', 'mistral-medium-latest', 'pixtral-large-latest'],
        ],
        'qwen' => [
            'label' => 'Alibaba Qwen (DashScope)', 'protocol' => 'openai', 'needs_key' => true,
            'base_url' => 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1', 'model' => 'qwen-vl-max',
            'models' => ['qwen-vl-max', 'qwen-vl-plus'],
        ],
        'ollama' => [
            'label' => 'Ollama (محلي)', 'protocol' => 'openai', 'needs_key' => false,
            'base_url' => 'http://localhost:11434/v1', 'model' => 'qwen2.5vl',
            'models' => ['qwen2.5vl', 'gemma3', 'llama3.2-vision', 'llava'],
        ],
        'lmstudio' => [
            'label' => 'LM Studio (محلي)', 'protocol' => 'openai', 'needs_key' => false,
            'base_url' => 'http://localhost:1234/v1', 'model' => '',
            'models' => [],
        ],
        'custom' => [
            'label' => 'موفر آخر (مخصص)', 'protocol' => 'openai', 'needs_key' => false,
            'base_url' => '', 'model' => '',
            'models' => [],
        ],
    ];

    public static function preset(string $id): array
    {
        return self::PRESETS[$id] ?? self::PRESETS['custom'];
    }

    /** أسماء الموفرين المحليين بالإنجليزية (بقية الأسماء إنجليزية أصلاً). */
    private const LABELS_EN = ['ollama' => 'Ollama (local)', 'lmstudio' => 'LM Studio (local)', 'custom' => 'Other provider (custom)'];

    /** اسم الموفر بالعربية والإنجليزية: الاسم الذي كتبه المساعد كما هو، وإلا اسم الموفر الجاهز. */
    public static function labels(string $id, string $saved = ''): array
    {
        $preset = self::preset($id)['label'];
        if ($saved !== '' && $saved !== $preset) {
            return ['ar' => $saved, 'en' => $saved];
        }
        return ['ar' => $preset, 'en' => self::LABELS_EN[$id] ?? $preset];
    }

    /**
     * طلب واحد للنموذج: نص + صور اختيارية. يُرجع النص الخام من النموذج.
     *
     * @param array<int,array{data:string,mime:string,label:string}> $images
     */
    public static function complete(array $cfg, string $system, string $prompt, array $images = [], int $maxTokens = 800): string
    {
        return match ($cfg['protocol'] ?? 'openai') {
            'anthropic' => self::anthropic($cfg, $system, $prompt, $images, $maxTokens),
            'gemini'    => self::gemini($cfg, $system, $prompt, $images, $maxTokens),
            default     => self::openai($cfg, $system, $prompt, $images, $maxTokens),
        };
    }

    /** @return string[] */
    public static function listModels(array $cfg): array
    {
        $key = (string) ($cfg['api_key'] ?? '');
        $base = rtrim((string) $cfg['base_url'], '/');
        $ids = [];
        switch ($cfg['protocol'] ?? 'openai') {
            case 'anthropic':
                $r = self::http('GET', self::anthropicRoot($base) . '/v1/models?limit=100', self::anthropicHeaders($key, false), null, 20);
                self::ensureOk($r);
                foreach ($r['json']['data'] ?? [] as $m) {
                    $ids[] = (string) ($m['id'] ?? '');
                }
                break;
            case 'gemini':
                $r = self::http('GET', $base . '/models?pageSize=200', ['x-goog-api-key' => $key], null, 20);
                self::ensureOk($r);
                foreach ($r['json']['models'] ?? [] as $m) {
                    if (in_array('generateContent', $m['supportedGenerationMethods'] ?? [], true)) {
                        $ids[] = preg_replace('#^models/#', '', (string) ($m['name'] ?? ''));
                    }
                }
                break;
            default:
                $headers = $key !== '' ? ['Authorization' => 'Bearer ' . $key] : [];
                $r = self::http('GET', preg_replace('#/chat/completions$#', '', $base) . '/models', $headers, null, 20);
                self::ensureOk($r);
                foreach (($r['json']['data'] ?? $r['json']['models'] ?? []) as $m) {
                    $ids[] = (string) (is_array($m) ? ($m['id'] ?? $m['name'] ?? '') : $m);
                }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        sort($ids, SORT_NATURAL);
        return $ids;
    }

    // ───────────────────────── OpenAI-compatible ─────────────────────────

    private static function openai(array $cfg, string $system, string $prompt, array $images, int $maxTokens): string
    {
        $base = rtrim((string) $cfg['base_url'], '/');
        $url = str_ends_with($base, '/chat/completions') ? $base : $base . '/chat/completions';
        $key = (string) ($cfg['api_key'] ?? '');

        $headers = [];
        if ($key !== '') {
            $headers['Authorization'] = 'Bearer ' . $key;
        }
        if (str_contains($base, 'openrouter.ai')) {
            $headers['X-Title'] = 'Basir';
        }

        $content = [];
        foreach ($images as $img) {
            $content[] = ['type' => 'text', 'text' => $img['label']];
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $img['mime'] . ';base64,' . $img['data']]];
        }
        $content[] = ['type' => 'text', 'text' => $prompt];
        $userMsg = ['role' => 'user', 'content' => $images ? $content : $prompt];

        $build = static function (array $f) use ($cfg, $system, $userMsg, $maxTokens): array {
            $messages = empty($f['no_system'])
                ? [['role' => 'system', 'content' => $system], $userMsg]
                : [self::mergeSystemIntoUser($system, $userMsg)];
            $b = ['model' => $cfg['model'], 'messages' => $messages];
            if (!empty($f['minimal'])) {
                return $b;
            }
            if (!empty($f['max_completion_tokens'])) {
                $b['max_completion_tokens'] = max(4000, $maxTokens * 4); // نماذج التفكير تحتاج مساحة أكبر
            } else {
                $b['max_tokens'] = $maxTokens;
            }
            if (empty($f['no_temperature'])) {
                $b['temperature'] = 0.2;
            }
            if (empty($f['no_response_format'])) {
                $b['response_format'] = ['type' => 'json_object'];
            }
            return $b;
        };

        $adjust = static function (array $f, string $msg): ?array {
            $n = $f;
            if (str_contains($msg, 'max_tokens') && empty($f['max_completion_tokens'])) {
                $n['max_completion_tokens'] = true;
            }
            if (str_contains($msg, 'temperature')) {
                $n['no_temperature'] = true;
            }
            if (str_contains($msg, 'response_format') || str_contains($msg, 'json_object') || str_contains($msg, 'json mode')) {
                $n['no_response_format'] = true;
            }
            if ((str_contains($msg, 'system') && (str_contains($msg, 'role') || str_contains($msg, 'not supported')))) {
                $n['no_system'] = true;
            }
            if ($n === $f && empty($f['minimal'])) {
                $n['minimal'] = true;
            }
            return $n === $f ? null : $n;
        };

        $r = self::sendAdaptive($cfg, $url, $headers, $build, $adjust);
        $choice = $r['json']['choices'][0] ?? [];
        $text = self::joinText($choice['message']['content'] ?? '');
        if ($text === '' && !empty($choice['message']['refusal'])) {
            throw new ProviderError(tr('رفض النموذج الإجابة على هذه الصورة.', 'The model refused to answer for this image.'), (string) $choice['message']['refusal']);
        }
        if ($text === '' && ($choice['finish_reason'] ?? '') === 'length') {
            throw new ProviderError(tr('انقطع رد النموذج قبل أن يكتمل. جرّب نموذجاً آخر.', 'The model reply was cut off. Try another model.'), 'finish_reason=length');
        }
        return $text;
    }

    private static function mergeSystemIntoUser(string $system, array $userMsg): array
    {
        if (is_string($userMsg['content'])) {
            $userMsg['content'] = $system . "\n\n" . $userMsg['content'];
        } else {
            array_unshift($userMsg['content'], ['type' => 'text', 'text' => $system]);
        }
        return $userMsg;
    }

    // ───────────────────────── Anthropic ─────────────────────────

    private static function anthropicRoot(string $base): string
    {
        return preg_replace('#/v1(/messages)?$#', '', rtrim($base, '/'));
    }

    private static function anthropicHeaders(string $key, bool $withBeta): array
    {
        $h = ['x-api-key' => $key, 'anthropic-version' => '2023-06-01'];
        if ($withBeta) {
            $h['anthropic-beta'] = 'server-side-fallback-2026-07-01';
        }
        return $h;
    }

    private static function anthropic(array $cfg, string $system, string $prompt, array $images, int $maxTokens): string
    {
        $base = rtrim((string) $cfg['base_url'], '/');
        $url = self::anthropicRoot($base) . '/v1/messages';
        $key = (string) ($cfg['api_key'] ?? '');
        $official = str_contains($base, 'api.anthropic.com');

        $content = [];
        foreach ($images as $img) {
            $content[] = ['type' => 'text', 'text' => $img['label']];
            $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $img['mime'], 'data' => $img['data']]];
        }
        $content[] = ['type' => 'text', 'text' => $prompt];

        $build = static function (array $f) use ($cfg, $system, $content, $maxTokens, $official): array {
            // مساحة كافية لأن النماذج الحديثة تفكّر قبل الإجابة
            $b = [
                'model' => $cfg['model'],
                'max_tokens' => max(4096, $maxTokens * 4),
                'system' => $system,
                'messages' => [['role' => 'user', 'content' => $content]],
            ];
            if ($official && empty($f['minimal'])) {
                // جهد منخفض = رد أسرع، مهم للتنقل. والرجوع التلقائي لنموذج آخر إن رفض النموذج.
                $b['output_config'] = ['effort' => 'low'];
                $b['fallbacks'] = 'default';
            }
            return $b;
        };
        $adjust = static fn (array $f, string $msg): ?array => empty($f['minimal']) && $official ? ['minimal' => true] : null;
        $headersFor = static fn (array $f): array => self::anthropicHeaders($key, $official && empty($f['minimal']));

        $r = self::sendAdaptive($cfg, $url, $headersFor, $build, $adjust);
        $j = $r['json'];
        $text = '';
        foreach ($j['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
        }
        if (($j['stop_reason'] ?? '') === 'refusal' && trim($text) === '') {
            throw new ProviderError(tr('رفض النموذج الإجابة على هذه الصورة.', 'The model refused to answer for this image.'), (string) ($j['stop_details']['explanation'] ?? 'refusal'));
        }
        return trim($text);
    }

    // ───────────────────────── Google Gemini ─────────────────────────

    private static function gemini(array $cfg, string $system, string $prompt, array $images, int $maxTokens): string
    {
        $base = rtrim((string) $cfg['base_url'], '/');
        $model = preg_replace('#^models/#', '', (string) $cfg['model']);
        $url = $base . '/models/' . rawurlencode($model) . ':generateContent';
        $headers = ['x-goog-api-key' => (string) ($cfg['api_key'] ?? '')];

        $parts = [];
        foreach ($images as $img) {
            $parts[] = ['text' => $img['label']];
            $parts[] = ['inlineData' => ['mimeType' => $img['mime'], 'data' => $img['data']]];
        }
        $parts[] = ['text' => $prompt];

        $build = static function (array $f) use ($system, $parts, $maxTokens): array {
            if (!empty($f['minimal'])) {
                array_unshift($parts, ['text' => $system]);
                return ['contents' => [['role' => 'user', 'parts' => $parts]]];
            }
            return [
                'systemInstruction' => ['parts' => [['text' => $system]]],
                'contents' => [['role' => 'user', 'parts' => $parts]],
                'generationConfig' => [
                    'temperature' => 0.2,
                    'maxOutputTokens' => max(4096, $maxTokens * 4),
                    'responseMimeType' => 'application/json',
                ],
            ];
        };
        $adjust = static fn (array $f, string $msg): ?array => empty($f['minimal']) ? ['minimal' => true] : null;

        $r = self::sendAdaptive($cfg, $url, $headers, $build, $adjust);
        $j = $r['json'];
        if (!empty($j['promptFeedback']['blockReason'])) {
            throw new ProviderError(tr('رفض النموذج تحليل هذه الصورة.', 'The model refused to analyse this image.'), (string) $j['promptFeedback']['blockReason']);
        }
        $text = '';
        foreach ($j['candidates'][0]['content']['parts'] ?? [] as $p) {
            if (isset($p['text']) && empty($p['thought'])) {
                $text .= $p['text'];
            }
        }
        if (trim($text) === '' && !empty($j['candidates'][0]['finishReason'])) {
            throw new ProviderError(tr('لم يُرجع النموذج إجابة. جرّب مرة أخرى.', 'The model returned no answer. Please try again.'), 'finishReason=' . $j['candidates'][0]['finishReason']);
        }
        return trim($text);
    }

    // ───────────────────────── HTTP + توافق تلقائي ─────────────────────────

    /**
     * يرسل الطلب، وإذا رفض الموفر معاملاً ما (خطأ 400) يعدّل الطلب ويعيد المحاولة،
     * ثم يتذكّر الصيغة الناجحة لهذا النموذج في data/compat.json حتى لا يتكرر الخطأ.
     *
     * @param array|Closure $headers مصفوفة ثابتة أو دالة تأخذ الأعلام
     */
    private static function sendAdaptive(array $cfg, string $url, array|Closure $headers, callable $build, callable $adjust): array
    {
        $compatFile = data_path('compat.json');
        $ckey = sha1(($cfg['protocol'] ?? '') . '|' . $url . '|' . ($cfg['model'] ?? ''));
        $compat = read_json($compatFile);
        $flags = $compat[$ckey] ?? [];
        $start = $flags;

        $r = [];
        for ($i = 0; $i < 4; $i++) {
            $h = $headers instanceof Closure ? $headers($flags) : $headers;
            $r = self::http('POST', $url, $h, $build($flags), 90);
            if ($r['status'] === 400) {
                $next = $adjust($flags, strtolower(self::errorText($r)));
                if ($next !== null) {
                    $flags = $next;
                    continue;
                }
            }
            break;
        }
        self::ensureOk($r);

        if ($flags !== $start) {
            $compat = read_json($compatFile);
            $compat[$ckey] = $flags;
            try {
                write_json($compatFile, $compat);
            } catch (Throwable) {
                // ليست ضرورية لعمل التطبيق
            }
        }
        return $r;
    }

    private static function http(string $method, string $url, array $headers, ?array $body, int $timeout): array
    {
        $payload = $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $lines = ['Accept: application/json'];
        if ($payload !== null) {
            $lines[] = 'Content-Type: application/json';
        }
        foreach ($headers as $k => $v) {
            $lines[] = $k . ': ' . $v;
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $lines,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_FOLLOWLOCATION => false,
            ];
            if ($payload !== null) {
                $opts[CURLOPT_POSTFIELDS] = $payload;
            }
            if ($ca = getenv('BASIR_CA_BUNDLE')) {
                $opts[CURLOPT_CAINFO] = $ca;
            } elseif (PHP_OS_FAMILY === 'Windows' && !ini_get('curl.cainfo')) {
                // PHP لويندوز غالباً بلا شهادات: نستخدم مخزن شهادات ويندوز نفسه
                // (PHP 8.1 لا يعرّف الثابت لكن مكتبة curl فيه تدعمه: CURLSSLOPT_NATIVE_CA = 16)
                $opts[CURLOPT_SSL_OPTIONS] = defined('CURLSSLOPT_NATIVE_CA') ? CURLSSLOPT_NATIVE_CA : 16;
            }
            curl_setopt_array($ch, $opts);
            $raw = curl_exec($ch);
            $status = $raw === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_error($ch);
            curl_close($ch);
        } else {
            $ctx = stream_context_create(['http' => [
                'method' => $method,
                'header' => implode("\r\n", $lines),
                'content' => $payload ?? '',
                'timeout' => $timeout,
                'ignore_errors' => true,
                'follow_location' => 0,
            ]]);
            $raw = @file_get_contents($url, false, $ctx);
            $status = 0;
            $err = $raw === false ? (error_get_last()['message'] ?? 'network error') : '';
            foreach ($http_response_header ?? [] as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                    $status = (int) $m[1];
                }
            }
        }

        $raw = is_string($raw) ? $raw : '';
        $json = json_decode($raw, true);
        return ['status' => $status, 'json' => is_array($json) ? $json : null, 'raw' => $raw, 'error' => $err];
    }

    private static function errorText(array $r): string
    {
        $j = $r['json'] ?? null;
        if (is_array($j)) {
            $e = $j['error'] ?? $j;
            if (is_array($e) && isset($e[0])) {
                $e = $e[0]['error'] ?? $e[0];
            }
            if (is_array($e)) {
                $msg = $e['message'] ?? $e['detail'] ?? $e['error'] ?? null;
                if (is_string($msg)) {
                    return $msg;
                }
                return json_encode($e, JSON_UNESCAPED_UNICODE);
            }
            if (is_string($e)) {
                return $e;
            }
        }
        return mb_substr(trim(strip_tags($r['raw'] ?? '')), 0, 300) ?: (string) ($r['error'] ?? '');
    }

    private static function ensureOk(array $r): void
    {
        $s = $r['status'];
        if ($s >= 200 && $s < 300 && is_array($r['json'])) {
            return;
        }
        $detail = trim(($s ? "HTTP $s: " : '') . self::errorText($r));
        $low = strtolower($detail);
        if ($s === 0) {
            $speak = (str_contains($low, 'ssl') || str_contains($low, 'certificate'))
                ? tr('مشكلة في شهادة الأمان عند الاتصال بالموفر. راجع إعداد الشهادات على الخادم.', 'Security certificate problem when contacting the provider. Check the certificate setup on the server.')
                : tr('تعذر الاتصال بموفر الذكاء الاصطناعي. تأكد من الإنترنت ومن الرابط.', 'Cannot reach the AI provider. Check the internet connection and the base URL.');
        } elseif ($s >= 200 && $s < 300) {
            $speak = tr('رد الموفر بصيغة غير مفهومة. تأكد من الرابط الأساسي.', 'The provider sent an unreadable reply. Check the base URL.');
        } elseif ($s === 401 || preg_match('/api[ _-]?key|unauthori[sz]ed|authentication/', $low)) {
            $speak = tr('مفتاح الموفر غير صحيح أو منتهي.', 'The provider key is wrong or expired.');
        } elseif ($s === 403) {
            $speak = tr('المفتاح لا يملك صلاحية لهذا النموذج.', 'The key has no access to this model.');
        } elseif ($s === 404) {
            $speak = tr('النموذج أو الرابط غير موجود. تحقق من اسم النموذج والرابط الأساسي.', 'Model or URL not found. Check the model name and base URL.');
        } elseif ($s === 402 || $s === 429) {
            $speak = tr('تم تجاوز حد الاستخدام أو نفد الرصيد. انتظر قليلاً ثم حاول.', 'Usage limit reached or out of credit. Wait a little and try again.');
        } elseif ($s === 413) {
            $speak = tr('الصورة كبيرة جداً على الموفر.', 'The image is too large for the provider.');
        } elseif ($s >= 500) {
            $speak = tr('خادم الموفر مشغول حالياً. حاول بعد قليل.', 'The provider is busy right now. Try again shortly.');
        } elseif (preg_match('/image|vision|multimodal|multi-modal|modality|image_url|inline_?data/', $low)) {
            $speak = tr('هذا النموذج لا يدعم الصور. اختر نموذجاً يدعم الرؤية.', 'This model does not accept images. Choose a vision model.');
        } else {
            $speak = tr('رفض الموفر الطلب. راجع إعدادات الموفر.', 'The provider rejected the request. Check the provider settings.');
        }
        throw new ProviderError($speak, $detail, $s);
    }

    private static function joinText(mixed $content): string
    {
        if (is_string($content)) {
            return trim($content);
        }
        $out = '';
        if (is_array($content)) {
            foreach ($content as $part) {
                if (is_array($part) && isset($part['text']) && ($part['type'] ?? 'text') !== 'reasoning') {
                    $out .= $part['text'];
                }
            }
        }
        return trim($out);
    }
}
