<?php
/**
 * فحص الصوت المدمج عبر الواجهة البرمجية (يُشغَّل في CI على ويندوز ولينكس وماك):
 * ينتظر جاهزية الصوتين، ينطق جملاً عربية وإنجليزية، يقيس الزمن، ويحفظ الملفات مع قائمة النصوص
 * المتوقعة ليتحقق منها Whisper لاحقاً (tests/asr_check.php).
 *
 *   php tests/voice_ci.php <out-dir> [http://127.0.0.1:7777]
 */
declare(strict_types=1);

$out = rtrim($argv[1] ?? 'voice-out', '/\\');
$base = rtrim($argv[2] ?? 'http://127.0.0.1:7777', '/');
@mkdir($out, 0777, true);
$fail = 0;

function req(string $base, string $action, ?array $body = null, int $timeout = 60): array
{
    $ch = curl_init("$base/api.php?action=$action");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_HEADER => false]);
    if ($body !== null) {
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    }
    $t = microtime(true);
    $raw = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    return ['code' => $info['http_code'], 'type' => (string) $info['content_type'], 'body' => (string) $raw, 'secs' => microtime(true) - $t];
}

// 1) جاهزية الصوت المدمج: أولاً أي صوت (قد يكون الخفيف ريثما يُنزَّل الإنجليزي في الخلفية)، ثم الأصوات الافتراضية
$ready = false;
$first = null;
for ($i = 0; $i < 250 && !$ready; $i++) {
    $r = req($base, 'status', null, 5);
    $j = json_decode($r['body'], true);
    $v = $j['voices'] ?? [];
    if (!$first && !empty($v['ar']['ready']) && !empty($v['en']['ready'])) {
        $first = $v;
        echo "  first voices: ar={$v['ar']['voice']} en={$v['en']['voice']}\n";
    }
    $ready = $first && ($v['ar']['voice'] ?? '') === 'ar-salem' && ($v['en']['voice'] ?? '') === 'en-hannah' && !empty($v['en']['ready']);
    if (!$ready) {
        sleep(2);
    }
}
echo $ready ? "✓ built-in voices ready: " . json_encode($j['voices'], JSON_UNESCAPED_UNICODE) . "\n" : "✗ voices not ready: " . ($r['body'] ?? '') . "\n";
if (!$ready) {
    exit(1);
}

// 2) النطق
$cases = [
    ['ar', 'ar-nav', 'تقدم ثلاث خطوات للأمام، الطريق خال.'],
    ['ar', 'ar-stop', 'توقف. يوجد كرسي أمامك بعد خطوتين، انعطف يمينا قليلا.'],
    ['ar', 'ar-stairs', 'انتبه، يوجد درج للأسفل أمامك مباشرة.'],
    ['ar', 'ar-mixed', 'مرحباً، أنا بصير. الموفر المحفوظ: OpenAI، والنموذج: gpt 4.1 mini.'],
    ['ar', 'ar-digits', 'تقدم 3 خطوات للأمام.'],
    ['ar', 'ar-diac', 'تَوَقَّفْ. يُوجَدُ كُرْسِيٌّ أَمَامَكَ بَعْدَ خُطْوَتَيْنِ.'],
    ['en', 'en-nav', 'Walk three steps forward, the way is clear.'],
    ['en', 'en-stop', 'Stop. There is a chair two steps ahead, turn slightly right.'],
];
$manifest = [];
printf("%-10s %-4s %8s %8s %9s\n", 'case', 'lang', 'first', 'cached', 'audio');
foreach ($cases as [$lang, $name, $text]) {
    $r1 = req($base, 'tts', ['text' => $text, 'lang' => $lang]);
    $r2 = req($base, 'tts', ['text' => $text, 'lang' => $lang]);
    $ok = $r1['code'] === 200 && str_starts_with($r1['type'], 'audio/') && str_starts_with($r1['body'], 'RIFF');
    $secs = 0.0;
    if ($ok) {
        $sr = unpack('V', substr($r1['body'], 24, 4))[1];
        $secs = (strlen($r1['body']) - 44) / 2 / $sr;
        $ok = $secs > 0.4 && $r2['body'] === $r1['body'];
        file_put_contents("$out/$name.wav", $r1['body']);
        $manifest[] = ['file' => "$name.wav", 'lang' => $lang, 'text' => $text];
    }
    printf("%-10s %-4s %7.2fs %7.3fs %8.2fs %s\n", $name, $lang, $r1['secs'], $r2['secs'], $secs, $ok ? '✓' : '✗ ' . substr($r1['body'], 0, 200));
    $fail += $ok ? 0 : 1;
}
file_put_contents("$out/manifest.json", json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

// 3) تغيير الصوت من الإعداد (نورة بدل سالم) يعيد تحميل خادم الصوت
$cfg = ['provider' => 'ollama', 'model' => 'qwen2.5vl', 'settings' => ['voice_ar' => 'ar-noura']];
$r = req($base, 'save_config', $cfg, 120);
$j = json_decode($r['body'], true);
$switched = ($j['voices']['ar']['voice'] ?? '') === 'ar-noura' && !empty($j['voices']['ar']['ready']);
$r = req($base, 'tts', ['text' => 'مرحبا، أنا نورة.', 'lang' => 'ar']);
$switched = $switched && $r['code'] === 200;
if ($switched) {
    file_put_contents("$out/ar-noura.wav", $r['body']);
    $manifest[] = ['file' => 'ar-noura.wav', 'lang' => 'ar', 'text' => 'مرحبا، أنا نورة.'];
    file_put_contents("$out/manifest.json", json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}
echo $switched ? "✓ switching the Arabic voice reloads the voice server\n" : "✗ voice switch failed: " . substr($r['body'], 0, 300) . "\n";
$fail += $switched ? 0 : 1;

echo $fail ? "\n$fail failed\n" : "\nall voice checks passed\n";
exit($fail ? 1 : 0);
