<?php
/**
 * موفر وهمي للاختبار يحاكي OpenAI و Anthropic و Gemini دون إنترنت.
 *   php -S 127.0.0.1:7790 tests/mock_provider.php
 * نماذج خاصة لاختبار التوافق التلقائي:
 *   reasoning-model : يرفض max_tokens و temperature (مثل نماذج التفكير)
 *   text-only       : يرفض الصور
 */
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$raw = file_get_contents('php://input');
$body = json_decode($raw ?: 'null', true);
$h = array_change_key_case(getallheaders(), CASE_LOWER);
header('Content-Type: application/json');

$log = getenv('MOCK_LOG') ?: sys_get_temp_dir() . '/basir_mock_log.jsonl';
file_put_contents($log, json_encode(['path' => $path, 'headers' => $h, 'body' => $body], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

function out(int $code, array $data): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function reply_for(string $prompt, int $images): string
{
    if (str_contains($prompt, 'memory')) {
        return json_encode(['say' => "مسح $images صور: الباب على يمينك بعد 4 خطوات.", 'memory' => 'غرفة، باب يمين 4 خطوات، طاولة أمام 2 خطوة.', 'best_direction' => 'right', 'hazard' => false], JSON_UNESCAPED_UNICODE);
    }
    $hazard = str_contains($prompt, 'درج');
    return "```json\n" . json_encode(['say' => "تقدّم 3 خطوات للأمام. عدد الصور $images.", 'action' => 'forward', 'steps' => 3, 'hazard' => $hazard], JSON_UNESCAPED_UNICODE) . "\n```";
}

// ── OpenAI-compatible ──
if (str_starts_with($path, '/openai/v1')) {
    if (($h['authorization'] ?? '') !== 'Bearer test-key') {
        out(401, ['error' => ['message' => 'Incorrect API key provided']]);
    }
    if ($path === '/openai/v1/models') {
        out(200, ['data' => [['id' => 'vision-1'], ['id' => 'reasoning-model'], ['id' => 'text-only']]]);
    }
    $model = $body['model'] ?? '';
    if ($model === 'reasoning-model' && isset($body['max_tokens'])) {
        out(400, ['error' => ['message' => "Unsupported parameter: 'max_tokens' is not supported with this model. Use 'max_completion_tokens' instead."]]);
    }
    if ($model === 'reasoning-model' && isset($body['temperature'])) {
        out(400, ['error' => ['message' => "Unsupported value: 'temperature' does not support 0.2 with this model."]]);
    }
    $images = 0;
    $prompt = '';
    foreach ($body['messages'] as $m) {
        if (is_string($m['content'])) {
            $prompt .= $m['content'];
            continue;
        }
        foreach ($m['content'] as $p) {
            if ($p['type'] === 'image_url') {
                $images++;
                if (!str_starts_with($p['image_url']['url'], 'data:image/')) {
                    out(400, ['error' => ['message' => 'bad image url']]);
                }
            } else {
                $prompt .= $p['text'];
            }
        }
    }
    if ($model === 'text-only' && $images > 0) {
        out(400, ['error' => ['message' => 'Invalid content type. image_url is only supported by certain models.']]);
    }
    out(200, ['choices' => [['message' => ['role' => 'assistant', 'content' => reply_for($prompt, $images)], 'finish_reason' => 'stop']]]);
}

// ── Anthropic ──
if (str_starts_with($path, '/anthropic/v1')) {
    if (($h['x-api-key'] ?? '') !== 'test-key' || empty($h['anthropic-version'])) {
        out(401, ['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']]);
    }
    if ($path === '/anthropic/v1/models') {
        out(200, ['data' => [['id' => 'claude-opus-5-5'], ['id' => 'claude-haiku-4-5']]]);
    }
    if (isset($body['temperature'])) {
        out(400, ['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'temperature is not supported']]);
    }
    $images = 0;
    $prompt = $body['system'] ?? '';
    foreach ($body['messages'][0]['content'] as $p) {
        if ($p['type'] === 'image') {
            $images++;
        } else {
            $prompt .= $p['text'];
        }
    }
    out(200, ['content' => [['type' => 'thinking', 'thinking' => ''], ['type' => 'text', 'text' => reply_for($prompt, $images)]], 'stop_reason' => 'end_turn']);
}

// ── Gemini ──
if (str_starts_with($path, '/gemini/v1beta')) {
    if (($h['x-goog-api-key'] ?? '') !== 'test-key') {
        out(400, ['error' => ['code' => 400, 'message' => 'API key not valid. Please pass a valid API key.', 'status' => 'INVALID_ARGUMENT']]);
    }
    if ($path === '/gemini/v1beta/models') {
        out(200, ['models' => [['name' => 'models/gemini-2.5-flash', 'supportedGenerationMethods' => ['generateContent']], ['name' => 'models/embedding-001', 'supportedGenerationMethods' => ['embedContent']]]]);
    }
    $images = 0;
    $prompt = $body['systemInstruction']['parts'][0]['text'] ?? '';
    foreach ($body['contents'][0]['parts'] as $p) {
        if (isset($p['inlineData'])) {
            $images++;
        } else {
            $prompt .= $p['text'];
        }
    }
    out(200, ['candidates' => [['content' => ['parts' => [['text' => 'thinking...', 'thought' => true], ['text' => reply_for($prompt, $images)]]], 'finishReason' => 'STOP']]]);
}

out(404, ['error' => ['message' => 'not found: ' . $path]]);
