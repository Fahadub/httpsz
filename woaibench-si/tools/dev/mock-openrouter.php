<?php
/**
 * DEV ONLY — deterministic mock of the OpenRouter API (no network, no randomness).
 *
 * RUN
 *   php -S 127.0.0.1:8099 tools/dev/mock-openrouter.php
 *   then point the app at it:  WB_OPENROUTER_BASE=http://127.0.0.1:8099/api/v1  WB_OPENROUTER_KEY=mock-key
 *   (any non-empty bearer key works; the key "bad" gets a 401 to test auth errors)
 *
 * ENDPOINTS (prefix /api/v1 optional)
 *   GET  /models                 OpenRouter-format catalogue (see mock_catalog())
 *   POST /chat/completions       generation, judging, agent tool calls, image output (modalities)
 *   POST /images/generations     OpenAI-style {data:[{b64_json}]} (gold gradient PNG made with GD)
 *   GET  /key  /credits  /generation?id=…   small account endpoints
 *   GET  /mock/image.png         the same PNG (used by mock/painter-url)
 *
 * MODELS UNDER TEST (behaviour is chosen by model id; unknown ids map deterministically to one of
 * oracle / average / weak by crc32 of the id, so fixture models such as "aster/aster-opus-x" work too)
 *   mock/oracle    reference solution from tools/dev/references/<slug>.html|.js|.answer.txt|.agent.json
 *                  (marker WB-ORACLE added when missing); generic valid sample if there is no reference
 *   mock/average   reference with deterministic degradation, marker WB-AVERAGE: html drops every 3rd
 *                  CSS line (and, for 1 task in 3, the last 30 % of its largest script); js uses a mutant
 *                  from references/mutants/ when one exists (2 tasks in 3); answer is wrong for 1 task
 *                  in 3; agent skips its last action for 1 task in 3
 *   mock/weak      tiny trivial output          mock/broken   prose only (no code, no answer, no tools)
 *   mock/flaky     oracle, but every 2nd request (per model, counter in the temp dir) → HTTP 503
 *   mock/notools   oracle; catalogue has no "tools" and a request WITH tools → 404 (like OpenRouter)
 *   mock/error200  HTTP 200 whose body is {"error":{"code":502,…}}      mock/ratelimit  HTTP 429 (Retry-After: 7)
 *   mock/nocredits HTTP 402 "Insufficient credits" (a provider halt: the worker pauses the campaign)
 *   mock/empty     empty content, finish_reason "stop"                  mock/slow       oracle after 1.5 s
 *   mock/truncated oracle cut at 60 % without closing fence, finish_reason "length"
 *   mock/parts     oracle with message.content as an array of text parts
 *   mock/painter   image model (images endpoint + chat image output)
 *   mock/painter-chat  images endpoint → 404, chat image output works (exercises the fallback)
 *   mock/painter-url   images endpoint answers with data[0].url instead of b64_json
 *   Injection for any model: payload {"mock": {"latency_ms": 300, "fail": 503|429|"error200"}} or
 *   header X-Mock-Latency-Ms.
 *
 * TASK MATCHING  the FIRST user message is compared (whitespace-normalised) with the "prompt" of every
 *   task in sql/seed/tasks/*.json and tools/dev/tests/fixtures/<module>/tasks/*.json (arrays of tasks);
 *   references are looked up in tools/dev/references/ and tools/dev/tests/fixtures/<module>/references/.
 *
 * AGENT TASKS  <slug>.agent.json = {"steps":[{"tool":"read_file","args":{"path":"/inbox/a.txt"}}, …,
 *   {"tool":"finish","args":{"answer":"…"}}]}. Step index = number of assistant messages already in the
 *   conversation. With a "tools" param the step is returned as an OpenAI tool_call; without it, as a
 *   fenced ```json {"tool":…,"args":…} block (JSON text protocol).
 *
 * JUDGES  a request whose system prompt contains "WOAIBench Judge" is a judge request (any model id;
 *   catalogue judges: mockjudge-a/alpha, mockjudge-b/beta, mockjudge-c/gamma, mockjudge-d/delta).
 *   Rubric keys are read from lines of EXACTLY this form anywhere in the messages:
 *       - key: <key> | weight: <number> | label: … | guide: …
 *   (fallback: any JSON array of objects with a "key" field). Reply:
 *       {"criteria":{"<key>":<0-10>,…},"summary":"…","strengths":["…"],"weaknesses":["…"]}
 *   Scores are deterministic (crc32 of the judged text + judge id + key): 8–9 when the text contains
 *   WB-ORACLE, 5–7 for WB-AVERAGE, otherwise 2–4. WB-CONTESTED: alpha/gamma score 9, beta/delta 2
 *   (spread > 25 → tie-break). WB-JUDGE-MISSING: the last rubric key is omitted until the conversation
 *   already contains an assistant reply (exercises "missing criterion → retry once").
 *   mockjudge-d/delta wraps its JSON in prose + a ```json fence (exercises robust parsing).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

define('MOCK_ROOT', dirname(__DIR__, 2));

mock_main();

function mock_main(): void
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $path = preg_replace('#^/(?:api/)?v1#', '', $path) ?? $path;
    $path = '/' . trim($path, '/');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    try {
        $latency = (int) ($_SERVER['HTTP_X_MOCK_LATENCY_MS'] ?? 0);
        if ($latency > 0) {
            usleep(min(30000, $latency) * 1000);
        }
        if ($method === 'GET' && $path === '/models') {
            mock_json(['data' => mock_catalog()]);
        }
        if ($method === 'GET' && $path === '/mock/image.png') {
            header('Content-Type: image/png');
            echo mock_png(256, 256);
            return;
        }
        if ($method === 'GET' && $path === '/') {
            header('Content-Type: text/plain; charset=utf-8');
            echo "WOAIBench mock OpenRouter — see the header of tools/dev/mock-openrouter.php\n";
            return;
        }
        mock_auth();
        if ($method === 'GET' && $path === '/key') {
            mock_json(['data' => ['label' => 'mock-key', 'usage' => 0.42, 'limit' => null, 'is_free_tier' => false, 'rate_limit' => ['requests' => 1000, 'interval' => '10s']]]);
        }
        if ($method === 'GET' && $path === '/credits') {
            mock_json(['data' => ['total_credits' => 100.0, 'total_usage' => 0.42]]);
        }
        if ($method === 'GET' && $path === '/generation') {
            mock_json(['data' => ['id' => (string) ($_GET['id'] ?? ''), 'total_cost' => 0.0012, 'provider_name' => 'MockProvider', 'model' => 'mock/oracle']]);
        }
        if ($method === 'POST' && $path === '/chat/completions') {
            mock_chat(mock_body());
        }
        if ($method === 'POST' && $path === '/images/generations') {
            mock_images(mock_body());
        }
        mock_error(404, 'Not found: ' . $method . ' ' . $path);
    } catch (Throwable $e) {
        mock_error(500, 'Mock server exception: ' . $e->getMessage());
    }
}

/* ------------------------------------------------------------------ HTTP helpers */

function mock_json(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function mock_error(int $status, string $message, int $httpStatus = 0): never
{
    if (($httpStatus ?: $status) === 429) {
        header('Retry-After: 7');
    }
    mock_json(['error' => ['code' => $status, 'message' => $message]], $httpStatus ?: $status);
}

function mock_auth(): void
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(\S+)/', $h, $m)) {
        mock_error(401, 'No auth credentials found');
    }
    if ($m[1] === 'bad') {
        mock_error(401, 'User not found.');
    }
}

function mock_body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        mock_error(400, 'Invalid JSON body');
    }
    return $body;
}

/* ------------------------------------------------------------------ catalogue */

function mock_catalog(): array
{
    $text = ['text'];
    $vision = ['text', 'image'];
    $full = ['tools', 'tool_choice', 'max_tokens', 'temperature', 'top_p', 'reasoning', 'include_reasoning', 'response_format', 'structured_outputs', 'seed', 'stop'];
    $noTools = ['max_tokens', 'temperature', 'top_p', 'response_format', 'seed', 'stop'];
    $defs = [
        // id, name, description, in $/M, out $/M, context, inputs, outputs, params, hf id
        ['mock/oracle', 'Mock: Oracle', 'Always returns the reference solution (marker WB-ORACLE).', 3, 15, 200000, $vision, $text, $full, ''],
        ['mock/average', 'Mock: Average', 'Reference solution with deterministic degradation (marker WB-AVERAGE).', 1, 4, 128000, $text, $text, $full, ''],
        ['mock/weak', 'Mock: Weak', 'Returns tiny, trivial outputs.', 0.1, 0.4, 32000, $text, $text, $full, ''],
        ['mock/broken', 'Mock: Broken', 'Answers with prose only — no code, no answer tags, no tool calls.', 0.2, 0.8, 32000, $text, $text, $full, ''],
        ['mock/flaky', 'Mock: Flaky', 'Oracle behind an unreliable provider: every 2nd request fails with HTTP 503.', 2, 8, 128000, $text, $text, $full, ''],
        ['mock/notools', 'Mock: No Tools', 'Oracle without tool-calling support (JSON text protocol for agent tasks).', 0.5, 1.5, 64000, $text, $text, $noTools, 'mock-labs/NoTools-8B'],
        ['mock/error200', 'Mock: Error 200', 'Returns HTTP 200 with an error object in the body.', 1, 1, 8000, $text, $text, $full, ''],
        ['mock/ratelimit', 'Mock: Rate Limited', 'Always HTTP 429.', 1, 1, 8000, $text, $text, $full, ''],
        ['mock/empty', 'Mock: Empty', 'Returns an empty completion with finish_reason "stop".', 1, 1, 8000, $text, $text, $full, ''],
        ['mock/slow', 'Mock: Slow', 'Oracle that answers after 1.5 seconds.', 1, 1, 8000, $text, $text, $full, ''],
        ['mock/truncated', 'Mock: Truncated', 'Oracle output cut off at 60 % (finish_reason "length").', 1, 1, 8000, $text, $text, $full, ''],
        ['mock/parts', 'Mock: Content Parts', 'Oracle with message.content as an array of text parts.', 1, 1, 8000, $text, $text, $full, ''],
        ['mock/painter', 'Mock: Painter', 'Image generation model (images endpoint and chat image output).', 0, 0, 4000, $text, ['image', 'text'], ['max_tokens'], ''],
        ['mock/painter-chat', 'Mock: Painter (chat only)', 'Image output only through chat completions.', 0, 0, 4000, $text, ['image', 'text'], ['max_tokens'], ''],
        ['mock/painter-url', 'Mock: Painter (URL)', 'Images endpoint answers with a URL instead of base64.', 0, 0, 4000, $text, ['image', 'text'], ['max_tokens'], ''],
        ['mockjudge-a/alpha', 'Mock Judge Alpha', 'Deterministic judge (vision).', 2, 8, 200000, $vision, $text, $full, ''],
        ['mockjudge-b/beta', 'Mock Judge Beta', 'Deterministic judge.', 1.5, 6, 128000, $text, $text, $full, ''],
        ['mockjudge-c/gamma', 'Mock Judge Gamma', 'Deterministic judge (vision).', 1, 5, 128000, $vision, $text, $full, ''],
        ['mockjudge-d/delta', 'Mock Judge Delta', 'Deterministic judge that wraps its JSON in prose and a code fence.', 0.8, 3.2, 128000, $text, $text, $full, ''],
        // Realistic-looking catalogue entries from obviously fictional vendors (admin catalogue UI testing).
        ['quillmark/quill-coder-2', 'Quillmark: Quill Coder 2', 'A fictional open-weight 70B coding model from the imaginary Quillmark lab.', 0.6, 2.4, 131072, $text, $text, $full, 'quillmark/Quill-Coder-2-70B'],
        ['quillmark/quill-coder-2:free', 'Quillmark: Quill Coder 2 (free)', 'Free, rate-limited variant of the fictional Quill Coder 2.', 0, 0, 32768, $text, $text, $noTools, 'quillmark/Quill-Coder-2-70B'],
        ['nimbus-forge/nimbus-ultra', 'Nimbus Forge: Nimbus Ultra', 'Fictional frontier reasoning model with vision.', 5, 25, 400000, $vision, $text, $full, ''],
        ['tessellate/tess-mini', 'Tessellate: Tess Mini', 'Fictional small, fast and cheap model.', 0.08, 0.3, 65536, $text, $text, $full, ''],
        ['orchid-research/orchid-7b-instruct', 'Orchid Research: Orchid 7B Instruct', 'Fictional 7B open-weight instruction model.', 0.05, 0.1, 32768, $text, $text, $noTools, 'orchid-research/Orchid-7B-Instruct'],
        ['lumen-dynamics/lumen-vision', 'Lumen Dynamics: Lumen Vision', 'Fictional multimodal model (text + image input).', 1.2, 4.8, 256000, $vision, $text, $full, ''],
        ['palette-works/palette-image', 'Palette Works: Palette Image', 'Fictional image generation model.', 0, 0, 8192, $text, ['image', 'text'], ['max_tokens'], ''],
    ];
    $out = [];
    foreach ($defs as $i => [$id, $name, $desc, $pin, $pout, $ctx, $in, $outMod, $params, $hf]) {
        $out[] = [
            'id' => $id,
            'canonical_slug' => $id,
            'hugging_face_id' => $hf,
            'name' => $name,
            'created' => 1760000000 - $i * 86400,
            'description' => $desc,
            'context_length' => $ctx,
            'architecture' => [
                'modality' => implode('+', $in) . '->' . implode('+', $outMod),
                'input_modalities' => $in,
                'output_modalities' => $outMod,
                'tokenizer' => 'Other',
                'instruct_type' => null,
            ],
            'pricing' => [
                'prompt' => mock_price($pin), 'completion' => mock_price($pout),
                'request' => '0', 'image' => '0', 'web_search' => '0', 'internal_reasoning' => '0',
            ],
            'top_provider' => ['context_length' => $ctx, 'max_completion_tokens' => min(64000, $ctx), 'is_moderated' => false],
            'per_request_limits' => null,
            'supported_parameters' => $params,
        ];
    }
    return $out;
}

/** USD per 1M tokens → OpenRouter's per-token decimal string. */
function mock_price(float $perMillion): string
{
    return $perMillion == 0.0 ? '0' : rtrim(rtrim(sprintf('%.10F', $perMillion / 1_000_000), '0'), '.');
}

function mock_model_info(string $id): ?array
{
    foreach (mock_catalog() as $m) {
        if ($m['id'] === $id) {
            return $m;
        }
    }
    return null;
}

/** Behaviour profile for a model id. */
function mock_profile(string $model): string
{
    $base = explode(':', $model)[0];
    $known = [
        'mock/oracle' => 'oracle', 'mock/average' => 'average', 'mock/weak' => 'weak', 'mock/broken' => 'broken',
        'mock/flaky' => 'flaky', 'mock/notools' => 'notools', 'mock/error200' => 'error200', 'mock/ratelimit' => 'ratelimit', 'mock/nocredits' => 'nocredits',
        'mock/empty' => 'empty', 'mock/slow' => 'slow', 'mock/truncated' => 'truncated', 'mock/parts' => 'parts',
        'mock/painter' => 'painter', 'mock/painter-chat' => 'painter-chat', 'mock/painter-url' => 'painter-url',
    ];
    if (isset($known[$base])) {
        return $known[$base];
    }
    if (str_starts_with($base, 'mockjudge-')) {
        return 'average';
    }
    return ['oracle', 'average', 'weak'][crc32($base) % 3];
}

/* ------------------------------------------------------------------ chat completions */

function mock_chat(array $req): never
{
    $model = is_string($req['model'] ?? null) ? $req['model'] : '';
    $messages = is_array($req['messages'] ?? null) ? $req['messages'] : [];
    if ($model === '' || !$messages) {
        mock_error(400, 'Request must include "model" and a non-empty "messages" array');
    }
    if ($model === 'mock/invalid') {
        mock_error(400, $model . ' is not a valid model ID');
    }
    $inject = is_array($req['mock'] ?? null) ? $req['mock'] : [];
    if (!empty($inject['latency_ms'])) {
        usleep(min(30000, (int) $inject['latency_ms']) * 1000);
    }
    $fail = $inject['fail'] ?? null;
    if ($fail === 'error200') {
        mock_json(['error' => ['code' => 502, 'message' => 'Upstream provider returned an error (mock)', 'metadata' => ['provider_name' => 'MockProvider', 'raw' => 'upstream timeout']]]);
    }
    if (is_numeric($fail)) {
        mock_error((int) $fail, 'Injected failure (mock)');
    }

    $profile = mock_profile($model);
    $hasTools = is_array($req['tools'] ?? null) && $req['tools'];
    switch ($profile) {
        case 'error200':
            mock_json(['error' => ['code' => 502, 'message' => 'Upstream provider returned an error (mock)', 'metadata' => ['provider_name' => 'MockProvider', 'raw' => 'upstream timeout']]]);
        case 'ratelimit':
            mock_error(429, 'Rate limit exceeded: retry shortly (mock)');
        case 'nocredits':
            mock_error(402, 'Insufficient credits. Add more using https://openrouter.ai/credits (mock)');
        case 'flaky':
            if (mock_counter($model) % 2 === 0) {
                mock_error(503, 'Service temporarily unavailable (mock flaky provider)');
            }
            $profile = 'oracle';
            break;
        case 'notools':
            if ($hasTools) {
                mock_error(404, 'No endpoints found that support tool use. To learn more about provider routing, visit: https://openrouter.ai/docs/provider-routing (mock)');
            }
            $profile = 'oracle';
            break;
        case 'slow':
            usleep(1_500_000);
            $profile = 'oracle';
            break;
    }

    $system = mock_text_of_role($messages, 'system');
    if (str_contains($system, 'WOAIBench Judge')) {
        mock_reply($req, mock_judge_content($model, $messages), 'stop');
    }
    $modalities = is_array($req['modalities'] ?? null) ? $req['modalities'] : [];
    if (in_array('image', $modalities, true) || str_starts_with($profile, 'painter')) {
        $png = mock_png(256, 256);
        mock_reply($req, 'Here is the generated image.', 'stop', null, [], [['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . base64_encode($png)]]]);
    }

    $firstUser = mock_first_user($messages);
    $task = mock_find_task($firstUser);
    $type = (string) ($task['output_type'] ?? mock_infer_type($system, $hasTools));
    $slug = (string) ($task['slug'] ?? '');

    if ($type === 'agent') {
        mock_agent_reply($req, $profile, $task, $messages, $hasTools);
    }
    $content = mock_generation($profile, $type, $slug, $task);
    $finish = 'stop';
    if ($profile === 'empty') {
        $content = '';
    } elseif ($profile === 'truncated') {
        $content = substr($content, 0, (int) (strlen($content) * 0.6));
        $content = preg_replace('/\n?```\s*$/', '', $content) ?? $content;
        $finish = 'length';
    }
    $reasoning = $profile === 'oracle' ? 'Mock reasoning (WB-ORACLE): restate the task, plan, implement, verify edge cases.' : null;
    if ($profile === 'parts') {
        $half = (int) floor(strlen($content) / 2);
        mock_reply($req, [['type' => 'text', 'text' => substr($content, 0, $half)], ['type' => 'text', 'text' => substr($content, $half)]], $finish);
    }
    mock_reply($req, $content, $finish, $reasoning);
}

/** Build and send a chat.completion response with usage + cost. */
function mock_reply(array $req, string|array $content, string $finish, ?string $reasoning = null, array $toolCalls = [], array $images = []): never
{
    $model = (string) $req['model'];
    $info = mock_model_info($model);
    $pIn = (float) ($info['pricing']['prompt'] ?? 0.000001);
    $pOut = (float) ($info['pricing']['completion'] ?? 0.000002);
    $promptTok = (int) ceil(strlen(json_encode($req['messages'] ?? []) ?: '') / 4);
    $text = is_string($content) ? $content : implode('', array_column($content, 'text'));
    $reasonTok = $reasoning !== null ? (int) ceil(strlen($reasoning) / 4) : 0;
    $complTok = (int) ceil((strlen($text) + strlen((string) json_encode($toolCalls))) / 4) + $reasonTok;
    if ($text === '' && !$toolCalls) {
        $complTok = $reasonTok + 1;
    }
    $msg = ['role' => 'assistant', 'content' => $content, 'refusal' => null, 'reasoning' => $reasoning];
    if ($toolCalls) {
        $msg['tool_calls'] = $toolCalls;
    }
    if ($images) {
        $msg['images'] = $images;
    }
    mock_json([
        'id' => 'gen-mock-' . substr(sha1($model . '|' . json_encode($req['messages'] ?? [])), 0, 24),
        'provider' => 'MockProvider',
        'model' => $model,
        'object' => 'chat.completion',
        'created' => 1760000000,
        'system_fingerprint' => null,
        'choices' => [[
            'index' => 0, 'logprobs' => null, 'finish_reason' => $finish, 'native_finish_reason' => $finish, 'message' => $msg,
        ]],
        'usage' => [
            'prompt_tokens' => $promptTok,
            'completion_tokens' => $complTok,
            'total_tokens' => $promptTok + $complTok,
            'cost' => round($promptTok * $pIn + $complTok * $pOut, 8),
            'is_byok' => false,
            'prompt_tokens_details' => ['cached_tokens' => 0],
            'completion_tokens_details' => ['reasoning_tokens' => $reasonTok],
        ],
    ]);
}

/** Per-model request counter (file + flock), used by mock/flaky. Returns the new count (1-based). */
function mock_counter(string $model): int
{
    $file = sys_get_temp_dir() . '/wb-mock-openrouter-' . ($_SERVER['SERVER_PORT'] ?? '0') . '-' . md5($model) . '.cnt';
    $fh = fopen($file, 'c+');
    if ($fh === false) {
        return 1;
    }
    flock($fh, LOCK_EX);
    $n = (int) stream_get_contents($fh) + 1;
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, (string) $n);
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $n;
}

/* ------------------------------------------------------------------ message helpers */

function mock_message_text(array $m): string
{
    $c = $m['content'] ?? '';
    if (is_string($c)) {
        return $c;
    }
    $out = '';
    foreach (is_array($c) ? $c : [] as $part) {
        if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
            $out .= $part['text'] . "\n";
        }
    }
    return $out;
}

function mock_text_of_role(array $messages, string $role): string
{
    $out = '';
    foreach ($messages as $m) {
        if (is_array($m) && ($m['role'] ?? '') === $role) {
            $out .= mock_message_text($m) . "\n";
        }
    }
    return $out;
}

function mock_first_user(array $messages): string
{
    foreach ($messages as $m) {
        if (is_array($m) && ($m['role'] ?? '') === 'user') {
            return mock_message_text($m);
        }
    }
    return '';
}

function mock_infer_type(string $system, bool $hasTools): string
{
    return match (true) {
        $hasTools || stripos($system, 'call finish') !== false || stripos($system, 'simulated workspace') !== false => 'agent',
        str_contains($system, '<answer>') => 'answer',
        str_contains($system, '```javascript') => 'js',
        str_contains($system, '```html') => 'html',
        default => 'text',
    };
}

/* ------------------------------------------------------------------ tasks & references */

function mock_norm(string $s): string
{
    return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
}

/** All known tasks (seed + test fixtures), each normalised to slug / prompt / output_type / checks. */
function mock_tasks(): array
{
    $files = array_merge(
        glob(MOCK_ROOT . '/sql/seed/tasks/*.json') ?: [],
        glob(MOCK_ROOT . '/sql/seed/tasks/*/*.json') ?: [],
        glob(MOCK_ROOT . '/tools/dev/tests/fixtures/*/tasks/*.json') ?: []
    );
    $tasks = [];
    foreach ($files as $f) {
        $data = json_decode((string) @file_get_contents($f), true);
        if (!is_array($data)) {
            continue;
        }
        $list = isset($data['prompt']) ? [$data] : (isset($data['tasks']) && is_array($data['tasks']) ? $data['tasks'] : $data);
        foreach ($list as $t) {
            if (!is_array($t) || !isset($t['slug'], $t['prompt'])) {
                continue;
            }
            $prompt = is_array($t['prompt']) ? implode("\n", $t['prompt']) : (string) $t['prompt'];
            $checks = $t['checks'] ?? ($t['checks_json'] ?? []);
            $tasks[] = [
                'slug' => (string) $t['slug'],
                'prompt' => $prompt,
                'output_type' => (string) ($t['output_type'] ?? 'html'),
                'checks' => is_string($checks) ? (json_decode($checks, true) ?: []) : (is_array($checks) ? $checks : []),
            ];
        }
    }
    return $tasks;
}

function mock_find_task(string $userText): ?array
{
    $u = mock_norm($userText);
    if ($u === '') {
        return null;
    }
    $tasks = mock_tasks();
    foreach ($tasks as $t) {
        if (mock_norm($t['prompt']) === $u) {
            return $t;
        }
    }
    foreach ($tasks as $t) {
        $p = mock_norm($t['prompt']);
        $head = mb_substr($p, 0, 200);
        if ($head !== '' && (str_starts_with($u, $head) || str_starts_with($p, mb_substr($u, 0, 200)))) {
            return $t;
        }
    }
    return null;
}

/** Reference file content for a slug + extension, or null. */
function mock_reference(string $slug, string $ext): ?string
{
    if ($slug === '' || !preg_match('/^[a-z0-9][a-z0-9._-]*$/i', $slug)) {
        return null;
    }
    $dirs = array_merge([MOCK_ROOT . '/tools/dev/references'], glob(MOCK_ROOT . '/tools/dev/tests/fixtures/*/references') ?: []);
    foreach ($dirs as $d) {
        $f = $d . '/' . $slug . $ext;
        if (is_file($f)) {
            return (string) file_get_contents($f);
        }
    }
    return null;
}

function mock_mutant(string $slug): ?string
{
    $files = glob(MOCK_ROOT . '/tools/dev/references/mutants/' . $slug . '.*.js') ?: [];
    sort($files);
    return $files ? (string) file_get_contents($files[0]) : null;
}

/* ------------------------------------------------------------------ generation */

function mock_generation(string $profile, string $type, string $slug, ?array $task): string
{
    $every3 = $slug !== '' && crc32($slug) % 3 === 0;
    if ($profile === 'broken') {
        return "I'd be glad to help with this! A good way to approach it is to break the problem into smaller parts, "
            . "think carefully about the edge cases and test each piece as you go. Let me know if you would like me to elaborate on any step.";
    }
    if ($profile === 'weak') {
        return match ($type) {
            'html' => "Here you go:\n```html\n<!DOCTYPE html>\n<html>\n<head><title>Draft</title></head>\n<body>\n<h1>Draft</h1>\n<p>Work in progress.</p>\n</body>\n</html>\n```",
            'js' => "```javascript\nfunction " . mock_entry($task, $slug) . "() {\n  return null;\n}\n```",
            'answer' => "I am not sure.\n<answer>0</answer>",
            default => 'OK.',
        };
    }
    $avg = $profile === 'average';
    switch ($type) {
        case 'html':
            $html = mock_reference($slug, '.html') ?? mock_sample_html($slug);
            $html = mock_marked_html($html, $avg ? 'WB-AVERAGE' : 'WB-ORACLE');
            if ($avg) {
                $html = mock_degrade_html($html, $every3);
            }
            return "Here is the complete, self-contained page.\n\n```html\n" . rtrim($html) . "\n```\n\nIt works offline and adapts to mobile screens.";
        case 'js':
            $js = null;
            if ($avg && !($slug !== '' && crc32($slug) % 3 === 1)) {
                $js = mock_mutant($slug);
            }
            $js ??= mock_reference($slug, '.js') ?? ("function " . mock_entry($task, $slug) . "(...args) {\n  return args.length ? args[0] : null;\n}\n");
            $js = str_replace('WB-ORACLE', $avg ? 'WB-AVERAGE' : 'WB-ORACLE', $js);
            if (!str_contains($js, $avg ? 'WB-AVERAGE' : 'WB-ORACLE')) {
                $js = '// ' . ($avg ? 'WB-AVERAGE' : 'WB-ORACLE') . "\n" . $js;
            }
            return "Here is my solution.\n\n```javascript\n" . rtrim($js) . "\n```";
        case 'answer':
            $ans = trim((string) (mock_reference($slug, '.answer.txt') ?? '42'));
            if ($avg && $every3) {
                $ans = is_numeric($ans) ? (string) ((float) $ans + 1) : 'unknown';
            }
            return "Let me work through this carefully (" . ($avg ? 'WB-AVERAGE' : 'WB-ORACLE') . ").\n\n<answer>{$ans}</answer>";
        default:
            return 'Here is my response (' . ($avg ? 'WB-AVERAGE' : 'WB-ORACLE') . ').';
    }
}

function mock_entry(?array $task, string $slug): string
{
    $entry = $task['checks']['tests']['entry'] ?? null;
    if (is_string($entry) && preg_match('/^[A-Za-z_$][\w$]*$/', $entry)) {
        return $entry;
    }
    $ref = mock_reference($slug, '.js');
    if ($ref !== null && preg_match('/^(?:function\s+|class\s+)([A-Za-z_$][\w$]*)/m', $ref, $m)) {
        return $m[1];
    }
    return 'solve';
}

/** Ensure the marker comment is present (after the doctype, so the page stays in standards mode). */
function mock_marked_html(string $html, string $marker): string
{
    $html = str_replace(['WB-ORACLE', 'WB-AVERAGE'], $marker, $html);
    if (str_contains($html, $marker)) {
        return $html;
    }
    if (preg_match('/^\s*<!doctype[^>]*>/i', $html, $m)) {
        return $m[0] . "\n<!-- {$marker} -->" . substr($html, strlen($m[0]));
    }
    return "<!-- {$marker} -->\n" . $html;
}

/** Average: drop every 3rd CSS line; for 1 task in 3 also cut the last 30 % of the largest script. */
function mock_degrade_html(string $html, bool $breakScript): string
{
    $html = preg_replace_callback('#(<style\b[^>]*>)(.*?)(</style>)#is', static function ($m) {
        $lines = explode("\n", $m[2]);
        $keep = [];
        foreach ($lines as $i => $l) {
            if ($i % 3 !== 2 || !str_contains($l, ':') || str_contains($l, '{') || str_contains($l, '}')) {
                $keep[] = $l;
            }
        }
        return $m[1] . implode("\n", $keep) . $m[3];
    }, $html) ?? $html;
    if ($breakScript && preg_match_all('#(<script\b(?![^>]*\bsrc=)[^>]*>)(.*?)(</script>)#is', $html, $all, PREG_SET_ORDER)) {
        usort($all, static fn($a, $b) => strlen($b[2]) <=> strlen($a[2]));
        $lines = explode("\n", $all[0][2]);
        $cut = implode("\n", array_slice($lines, 0, max(1, (int) floor(count($lines) * 0.7))));
        $html = str_replace($all[0][0], $all[0][1] . $cut . "\n" . $all[0][3], $html);
    }
    return $html;
}

function mock_sample_html(string $slug): string
{
    $title = $slug !== '' ? ucwords(str_replace('-', ' ', $slug)) : 'Sample Page';
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title}</title>
<style>
  :root { color-scheme: dark; }
  body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: system-ui, sans-serif;
         background: radial-gradient(circle at 30% 20%, #3a2c12, #0b0b10); color: #f5efe2; }
  .card { padding: 32px 40px; border-radius: 20px; background: rgba(255,255,255,.06); border: 1px solid rgba(217,178,106,.35); }
  h1 { margin: 0 0 8px; font-size: clamp(28px, 6vw, 44px); }
  button { margin-top: 16px; padding: 10px 18px; border-radius: 999px; border: 0; background: #d9b26a; color: #1a1408; font-weight: 600; cursor: pointer; }
</style>
</head>
<body>
<main class="card">
  <h1>{$title}</h1>
  <p id="count">Clicked 0 times</p>
  <button type="button" id="btn">Click me</button>
</main>
<script>
  let n = 0;
  document.getElementById('btn').addEventListener('click', () => {
    n += 1;
    document.getElementById('count').textContent = 'Clicked ' + n + ' times';
  });
</script>
</body>
</html>
HTML;
}

/* ------------------------------------------------------------------ agent replay */

function mock_agent_reply(array $req, string $profile, ?array $task, array $messages, bool $hasTools): never
{
    $slug = (string) ($task['slug'] ?? '');
    if ($profile === 'broken') {
        mock_reply($req, "I'll take a look at the files and get back to you with a summary of what I find.", 'stop');
    }
    $ref = json_decode((string) (mock_reference($slug, '.agent.json') ?? ''), true);
    $steps = is_array($ref['steps'] ?? null) ? array_values(array_filter($ref['steps'], 'is_array')) : [];
    if (!$steps) {
        $steps = [['tool' => 'finish', 'args' => ['answer' => 'Done.']]];
    }
    if ($profile === 'weak') {
        $steps = [['tool' => 'finish', 'args' => ['answer' => 'I could not complete the task.']]];
    } elseif ($profile === 'average' && $slug !== '' && crc32($slug) % 3 === 0 && count($steps) > 1) {
        array_splice($steps, count($steps) - 2, 1);
    }
    $idx = 0;
    foreach ($messages as $m) {
        $idx += (is_array($m) && ($m['role'] ?? '') === 'assistant') ? 1 : 0;
    }
    $step = $steps[min($idx, count($steps) - 1)];
    $tool = (string) ($step['tool'] ?? 'finish');
    $args = is_array($step['args'] ?? null) ? $step['args'] : [];
    $argsJson = json_encode((object) $args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($hasTools) {
        $call = ['id' => 'call_' . $idx . '_' . substr(md5($slug . $idx), 0, 8), 'type' => 'function', 'function' => ['name' => $tool, 'arguments' => $argsJson]];
        mock_reply($req, '', 'tool_calls', null, [$call]);
    }
    $content = ($idx === 0 ? "I'll start by inspecting the workspace.\n\n" : '')
        . "```json\n" . json_encode(['tool' => $tool, 'args' => (object) $args], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n```";
    mock_reply($req, $content, 'stop');
}

/* ------------------------------------------------------------------ judge */

function mock_judge_content(string $judge, array $messages): string
{
    $all = '';
    $users = '';
    $hasAssistant = false;
    foreach ($messages as $m) {
        if (!is_array($m)) {
            continue;
        }
        $t = mock_message_text($m);
        $all .= $t . "\n";
        if (($m['role'] ?? '') === 'user') {
            $users .= $t . "\n";
        }
        $hasAssistant = $hasAssistant || ($m['role'] ?? '') === 'assistant';
    }
    $keys = mock_rubric_keys($all) ?: ['overall'];
    if (str_contains($users, 'WB-JUDGE-MISSING') && !$hasAssistant && count($keys) > 1) {
        array_pop($keys);
    }
    $contested = str_contains($users, 'WB-CONTESTED');
    [$lo, $hi, $tier] = match (true) {
        str_contains($users, 'WB-ORACLE') => [8, 9, 'strong'],
        str_contains($users, 'WB-AVERAGE') => [5, 7, 'adequate'],
        default => [2, 4, 'weak'],
    };
    $hash = md5($users);
    $criteria = [];
    foreach ($keys as $k) {
        if ($contested) {
            $criteria[$k] = in_array($judge, ['mockjudge-a/alpha', 'mockjudge-c/gamma'], true) ? 9 : 2;
        } else {
            $criteria[$k] = $lo + (crc32($hash . '|' . $judge . '|' . $k) % ($hi - $lo + 1));
        }
    }
    $verdict = [
        'criteria' => $criteria,
        'summary' => "Mock judge ({$judge}): a {$tier} submission overall.",
        'strengths' => $tier === 'weak' ? ['Some attempt was made.'] : ['Meets the core requirements.', 'Readable structure.'],
        'weaknesses' => $tier === 'strong' ? ['Minor polish possible.'] : ['Missing requirements and rough edges.'],
    ];
    $json = json_encode($verdict, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($judge === 'mockjudge-d/delta') {
        return "Here is my evaluation of the submission.\n\n```json\n{$json}\n```\n\nLet me know if you need more detail.";
    }
    return (string) $json;
}

/** Rubric keys from "- key: <key> | weight: …" lines, else from a JSON array of {"key":…} objects. */
function mock_rubric_keys(string $text): array
{
    if (preg_match_all('/^\s*-\s*key:\s*([^\s|]+)\s*\|\s*weight:/m', $text, $m) && $m[1]) {
        return array_values(array_unique($m[1]));
    }
    $offset = 0;
    while (($pos = strpos($text, '[', $offset)) !== false) {
        $offset = $pos + 1;
        $depth = 0;
        for ($i = $pos, $n = strlen($text); $i < $n; $i++) {
            $depth += $text[$i] === '[' ? 1 : ($text[$i] === ']' ? -1 : 0);
            if ($depth === 0) {
                $arr = json_decode(substr($text, $pos, $i - $pos + 1), true);
                if (is_array($arr) && $arr && isset($arr[0]['key'])) {
                    return array_values(array_unique(array_map(static fn($c) => (string) ($c['key'] ?? ''), array_filter($arr, 'is_array'))));
                }
                break;
            }
        }
    }
    return [];
}

/* ------------------------------------------------------------------ images */

function mock_images(array $req): never
{
    $model = (string) ($req['model'] ?? '');
    if ($model === '' || !is_string($req['prompt'] ?? null)) {
        mock_error(400, 'Request must include "model" and "prompt"');
    }
    $profile = mock_profile($model);
    if ($profile === 'ratelimit') {
        mock_error(429, 'Rate limit exceeded: retry shortly (mock)');
    }
    if ($profile === 'error200') {
        mock_json(['error' => ['code' => 502, 'message' => 'Upstream provider returned an error (mock)']]);
    }
    if ($profile === 'painter-chat') {
        mock_error(404, 'This model does not support the images endpoint (mock); use chat completions with modalities');
    }
    [$w, $h] = array_map('intval', explode('x', (string) ($req['size'] ?? '256x256')) + [1 => 256]);
    $w = max(16, min(512, $w ?: 256));
    $h = max(16, min(512, $h ?: 256));
    if ($profile === 'painter-url') {
        $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
        mock_json(['created' => 1760000000, 'data' => [['url' => 'http://' . $host . '/mock/image.png', 'revised_prompt' => $req['prompt']]]]);
    }
    mock_json(['created' => 1760000000, 'data' => [['b64_json' => base64_encode(mock_png($w, $h)), 'revised_prompt' => $req['prompt']]]]);
}

/** Champagne-gold diagonal gradient PNG with a soft highlight (GD). */
function mock_png(int $w, int $h): string
{
    if (!function_exists('imagecreatetruecolor')) {
        // 1×1 gold PNG fallback when GD is missing.
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGO4sbEJAATCAiM/Fcu1AAAAAElFTkSuQmCC');
    }
    $img = imagecreatetruecolor($w, $h);
    $from = [0x2A, 0x1E, 0x0C];
    $to = [0xF3, 0xD9, 0x8B];
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x += 2) {
            $t = ($x / max(1, $w - 1) + $y / max(1, $h - 1)) / 2;
            $dx = ($x - $w * 0.35) / $w;
            $dy = ($y - $h * 0.3) / $h;
            $glow = max(0.0, 1 - sqrt($dx * $dx + $dy * $dy) * 2.2) * 0.35;
            $c = [];
            foreach ([0, 1, 2] as $k) {
                $c[$k] = (int) max(0, min(255, $from[$k] + ($to[$k] - $from[$k]) * $t + 255 * $glow));
            }
            $col = imagecolorallocate($img, $c[0], $c[1], $c[2]);
            imagesetpixel($img, $x, $y, $col);
            if ($x + 1 < $w) {
                imagesetpixel($img, $x + 1, $y, $col);
            }
        }
    }
    ob_start();
    imagepng($img);
    return (string) ob_get_clean();
}
