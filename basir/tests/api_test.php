<?php
/**
 * اختبارات واجهة بصير مقابل الموفر الوهمي. شغّلها عبر: sh tests/run.sh
 */
declare(strict_types=1);

$app = getenv('APP_URL') ?: 'http://127.0.0.1:7781';
$mock = getenv('MOCK_URL') ?: 'http://127.0.0.1:7790';
$fails = 0;
$passes = 0;

function call(string $action, ?array $body = null, string $contentType = 'application/json'): array
{
    global $app;
    $ch = curl_init("$app/api.php?action=$action");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    if ($body !== null) {
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ["Content-Type: $contentType"],
        ]);
    }
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['code' => $code, 'json' => json_decode((string) $raw, true) ?? ['raw' => $raw]];
}

function check(string $name, bool $cond, mixed $info = null): void
{
    global $fails, $passes;
    if ($cond) {
        $passes++;
        echo "  ✓ $name\n";
    } else {
        $fails++;
        echo "  ✗ $name\n    " . json_encode($info, JSON_UNESCAPED_UNICODE) . "\n";
    }
}

function jpeg(int $w = 64, int $h = 48): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 120, 130, 140));
    ob_start();
    imagejpeg($im);
    return base64_encode((string) ob_get_clean());
}

echo "حالة أولية\n";
$r = call('status');
check('status ok, not configured', $r['code'] === 200 && $r['json']['configured'] === false, $r);
$r = call('analyze', ['mode' => 'navigate', 'images' => [['data' => jpeg()]]]);
check('analyze before config → 409', $r['code'] === 409, $r);

echo "حماية\n";
$r = call('save_config', ['provider' => 'custom', 'base_url' => "$mock/openai/v1", 'api_key' => 'x', 'model' => 'm'], 'text/plain');
check('text/plain POST rejected (CSRF guard)', $r['code'] === 415, $r);
$r = call('save_config', ['provider' => 'openai', 'base_url' => 'javascript:alert(1)', 'api_key' => 'x', 'model' => 'm']);
check('bad base_url rejected', $r['code'] === 400, $r);
$r = call('save_config', ['provider' => 'openai', 'base_url' => "$mock/openai/v1", 'model' => 'vision-1']);
check('missing key rejected for key-requiring preset', $r['code'] === 400, $r);

echo "OpenAI-compatible\n";
$cfg = ['provider' => 'openai', 'base_url' => "$mock/openai/v1", 'api_key' => 'test-key', 'model' => 'vision-1',
    'settings' => ['speech_rate' => 1.2, 'step_m' => 0.65]];
$r = call('test', $cfg);
check('test connection with vision image', $r['code'] === 200 && $r['json']['ok'] && $r['json']['vision_tested'], $r);
$r = call('models', $cfg);
check('list models', ($r['json']['models'] ?? []) === ['reasoning-model', 'text-only', 'vision-1'], $r);
$r = call('test', ['api_key' => 'wrong'] + $cfg);
check('wrong key → 502 with Arabic key message', $r['code'] === 502 && str_contains($r['json']['error'], 'المفتاح') || str_contains($r['json']['error'] ?? '', 'مفتاح'), $r);
$r = call('save_config', $cfg);
check('save config', $r['code'] === 200 && $r['json']['configured'] === true, $r);
check('status never leaks key', !str_contains(json_encode($r['json']), 'test-key') && $r['json']['provider']['key_hint'] !== '', $r['json']['provider'] ?? null);
check('settings saved', abs($r['json']['settings']['speech_rate'] - 1.2) < 0.001, $r['json']['settings'] ?? null);

$r = call('save_config', ['api_key' => ''] + $cfg + ['model' => 'vision-1']);
check('empty key keeps stored key (same base url)', $r['code'] === 200, $r);
$r = call('test', ['api_key' => '', 'base_url' => 'http://127.0.0.1:9/evil/v1'] + $cfg);
check('stored key NOT reused for a different base url', $r['code'] === 400, $r);

$r = call('analyze', ['mode' => 'navigate', 'images' => [['data' => jpeg(), 'label' => 'الأمام']], 'history' => ['تقدّم خطوتين']]);
check('navigate → say/action/steps', $r['code'] === 200 && $r['json']['action'] === 'forward' && $r['json']['steps'] === 3 && str_contains($r['json']['say'], 'عدد الصور 1'), $r);

$r = call('analyze', ['mode' => 'navigate', 'images' => [['data' => 'not-an-image']]]);
check('invalid image rejected', $r['code'] === 400, $r);

echo "الجوالات الإضافية\n";
$r = call('node_frame', ['dir' => 'right', 'image' => jpeg(), 'device' => 'test']);
check('node frame saved', $r['code'] === 200 && count($r['json']['nodes']) === 1, $r);
$r = call('node_frame', ['dir' => 'up', 'image' => jpeg()]);
check('bad node dir rejected', $r['code'] === 400, $r);
$r = call('analyze', ['mode' => 'navigate', 'images' => [['data' => jpeg()]], 'include_nodes' => true]);
check('nodes included in analysis', $r['code'] === 200 && $r['json']['nodes_used'] === ['right'] && str_contains($r['json']['say'], 'عدد الصور 2'), $r);
$r = call('node_leave', ['dir' => 'right']);
check('node leave', $r['code'] === 200 && $r['json']['nodes'] === [], $r);

echo "المسح وذاكرة المكان\n";
$frames = array_map(fn ($a) => ['data' => jpeg(), 'label' => "زاوية $a"], [0, 90, 180, 270]);
$r = call('analyze', ['mode' => 'survey', 'images' => $frames]);
check('survey returns memory + best direction', $r['code'] === 200 && $r['json']['best_direction'] === 'right' && $r['json']['memory'] !== '', $r);
$r = call('status');
check('memory stored', !empty($r['json']['memory']['summary']), $r['json']['memory'] ?? null);
$r = call('memory_clear', []);
$r = call('status');
check('memory cleared', $r['json']['memory'] === null, $r['json']['memory'] ?? null);

echo "توافق تلقائي (نموذج تفكير يرفض max_tokens و temperature)\n";
$r = call('save_config', ['model' => 'reasoning-model'] + $cfg);
$r = call('analyze', ['mode' => 'describe', 'images' => [['data' => jpeg()]]]);
check('adaptive retry succeeds', $r['code'] === 200 && $r['json']['ok'], $r);
$r = call('save_config', ['model' => 'text-only'] + $cfg);
$r = call('analyze', ['mode' => 'describe', 'images' => [['data' => jpeg()]]]);
check('non-vision model → Arabic hint', $r['code'] === 502 && str_contains($r['json']['error'], 'لا يدعم الصور'), $r);

echo "Anthropic\n";
$a = ['provider' => 'custom', 'protocol' => 'anthropic', 'base_url' => "$mock/anthropic", 'api_key' => 'test-key', 'model' => 'claude-opus-5-5'];
$r = call('save_config', $a);
check('save anthropic', $r['code'] === 200 && $r['json']['provider']['protocol'] === 'anthropic', $r);
$r = call('analyze', ['mode' => 'navigate', 'images' => [['data' => jpeg()], ['data' => jpeg(), 'label' => 'اليمين']]]);
check('anthropic analyze (thinking block skipped)', $r['code'] === 200 && str_contains($r['json']['say'], 'عدد الصور 2'), $r);
$r = call('models', $a);
check('anthropic models', in_array('claude-opus-5-5', $r['json']['models'] ?? [], true), $r);

echo "Gemini\n";
$g = ['provider' => 'custom', 'protocol' => 'gemini', 'base_url' => "$mock/gemini/v1beta", 'api_key' => 'test-key', 'model' => 'models/gemini-2.5-flash'];
$r = call('save_config', $g);
$r = call('analyze', ['mode' => 'ask', 'question' => 'هل يوجد درج؟', 'images' => [['data' => jpeg()]]]);
check('gemini analyze (thought part skipped, hazard parsed)', $r['code'] === 200 && $r['json']['hazard'] === true && !str_contains($r['json']['say'], 'thinking'), $r);
$r = call('models', $g);
check('gemini models filtered to generateContent', ($r['json']['models'] ?? []) === ['gemini-2.5-flash'], $r);
$r = call('test', ['api_key' => 'bad'] + $g);
check('gemini bad key → key message', $r['code'] === 502 && str_contains($r['json']['error'], 'مفتاح'), $r);

echo "الصوت المدمج (بدون خادم صوت)\n";
$r = call('voices');
check('voices list has Arabic and English voices', count(array_filter($r['json']['voices'] ?? [], fn ($v) => $v['lang'] === 'ar')) >= 2 && count(array_filter($r['json']['voices'] ?? [], fn ($v) => $v['lang'] === 'en')) >= 2, $r);
check('voices never mention engine names', !preg_match('/supertonic|kokoro|piper|sherpa/i', json_encode(array_column($r['json']['voices'] ?? [], 'name'))), $r['json']['voices'] ?? null);
$r = call('tts', ['text' => 'مرحبا', 'lang' => 'ar']);
check('tts without voice server → 503 JSON (client falls back to device voice)', $r['code'] === 503 && $r['json']['ok'] === false, $r);
$r = call('status');
check('status reports per-language voice readiness', isset($r['json']['voices']['ar']['ready'], $r['json']['voices']['en']['ready']), $r['json']['voices'] ?? null);
$r = call('save_config', $g + ['pin' => '', 'settings' => ['voice_ar' => 'ar-noura', 'voice_en' => 'bogus']]);
check('voice settings validated (unknown voice → default)', ($r['json']['settings']['voice_ar'] ?? '') === 'ar-noura' && ($r['json']['settings']['voice_en'] ?? 'x') === '', $r['json']['settings'] ?? $r);

echo "اللغة الإنجليزية\n";
$r = call('analyze&lang=en', ['mode' => 'navigate', 'images' => [['data' => jpeg()]], 'lang' => 'en']);
$log = file_get_contents(getenv('MOCK_LOG'));
check('English request sends English prompts', $r['code'] === 200 && str_contains($log, 'step-by-step walking guidance'), $r);
$r = call('analyze&lang=en', ['mode' => 'bogus', 'images' => [['data' => jpeg()]]]);
check('English error messages', $r['code'] === 400 && $r['json']['error'] === 'Unknown mode.', $r);

echo "رمز الحماية\n";
$r = call('save_config', $g + ['new_pin' => '1234']);
check('pin set', $r['json']['pin_required'] === true, $r);
$r = call('save_config', $g);
check('save without pin → 403', $r['code'] === 403, $r);
$r = call('save_config', $g + ['pin' => '1234']);
check('save with pin ok', $r['code'] === 200, $r);
$r = call('reset', ['pin' => '1234']);
check('reset', $r['code'] === 200 && $r['json']['configured'] === false, $r);

echo "\n$passes نجح، $fails فشل\n";
exit($fails ? 1 : 0);
