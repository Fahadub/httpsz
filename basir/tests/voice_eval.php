<?php
/**
 * تقييم أصوات بصير: يولّد جملاً نموذجية بكل صوت عبر محرك FFI (نفس محرك التطبيق)، ويقيس السرعة،
 * ثم يحوّل الصوت إلى نص بـ Whisper ويحسب نسبة الخطأ في الحروف (CER) — مقياس موضوعي للوضوح.
 *
 *   php -d ffi.enable=1 tests/voice_eval.php <lib> <asr-bin> <whisper-dir> <out-dir> <lang> <name=engine:dir[:sid]>...
 *   engine: vits | kokoro
 */
declare(strict_types=1);

require __DIR__ . '/../src/SherpaTts.php';

[$_, $lib, $asrBin, $whisperDir, $outDir, $lang] = array_slice($argv, 0, 6);
$voices = array_slice($argv, 6);

$sentences = [
    'ar' => [
        'تقدم ثلاث خطوات للأمام، الطريق خال.',
        'توقف. يوجد كرسي أمامك بعد خطوتين، انعطف يمينا قليلا.',
        'انتبه، يوجد درج للأسفل أمامك مباشرة.',
        'الباب على يسارك بعد خمس خطوات.',
        'يوجد شخص يقف على يمينك، امش ببطء.',
        'الطاولة أمامك وعليها كوب ماء.',
        'لم أسمع شيئا. اضغط وتكلم مرة أخرى.',
        'مرحبا، أنا بصير. الموفر المحفوظ هو جيميني.',
        'تقدم 3 خطوات للأمام.',
    ],
    // نفس الجمل مُشكَّلة بالكامل — لقياس أثر التشكيل على وضوح النطق
    'ar_diac' => [
        'تَقَدَّمْ ثَلَاثَ خُطُوَاتٍ لِلْأَمَامِ، الطَّرِيقُ خَالٍ.',
        'تَوَقَّفْ. يُوجَدُ كُرْسِيٌّ أَمَامَكَ بَعْدَ خُطْوَتَيْنِ، انْعَطِفْ يَمِينًا قَلِيلًا.',
        'انْتَبِهْ، يُوجَدُ دَرَجٌ لِلْأَسْفَلِ أَمَامَكَ مُبَاشَرَةً.',
        'الْبَابُ عَلَى يَسَارِكَ بَعْدَ خَمْسِ خُطُوَاتٍ.',
        'يُوجَدُ شَخْصٌ يَقِفُ عَلَى يَمِينِكَ، امْشِ بِبُطْءٍ.',
        'الطَّاوِلَةُ أَمَامَكَ وَعَلَيْهَا كُوبُ مَاءٍ.',
        'لَمْ أَسْمَعْ شَيْئًا. اضْغَطْ وَتَكَلَّمْ مَرَّةً أُخْرَى.',
        'مَرْحَبًا، أَنَا بَصِيرٌ. الْمُوَفِّرُ الْمَحْفُوظُ هُوَ جِيمِينِي.',
        'تَقَدَّمْ ثَلَاثَ خُطُوَاتٍ لِلْأَمَامِ.',
    ],
    'en' => [
        'Walk three steps forward, the way is clear.',
        'Stop. There is a chair two steps ahead, turn slightly right.',
        'Careful, there are stairs going down right in front of you.',
        'The door is on your left after five steps.',
        'A person is standing on your right, walk slowly.',
        'The table is in front of you with a glass of water on it.',
        'I did not hear anything. Tap and speak again.',
        'Hello, I am Basir. The saved provider is Gemini.',
        'Walk 3 steps forward.',
    ],
][$lang];
$asrLang = $lang === 'ar_diac' ? 'ar' : $lang;

function norm(string $s, string $lang): string
{
    $s = mb_strtolower($s);
    if ($lang === 'ar' || $lang === 'ar_diac') {
        $s = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $s);
        $s = strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي']);
    }
    $s = preg_replace('/[^\p{L}\p{N}]+/u', '', $s);
    return $s;
}

function cer(string $ref, string $hyp): float
{
    $r = preg_split('//u', $ref, -1, PREG_SPLIT_NO_EMPTY);
    $h = preg_split('//u', $hyp, -1, PREG_SPLIT_NO_EMPTY);
    $n = count($r);
    $m = count($h);
    $prev = range(0, $m);
    for ($i = 1; $i <= $n; $i++) {
        $cur = [$i];
        for ($j = 1; $j <= $m; $j++) {
            $cur[$j] = min($prev[$j] + 1, $cur[$j - 1] + 1, $prev[$j - 1] + ($r[$i - 1] === $h[$j - 1] ? 0 : 1));
        }
        $prev = $cur;
    }
    return $n ? $prev[$m] / $n : 0.0;
}

$engine = new SherpaTts($lib);
@mkdir($outDir, 0777, true);
$threads = (int) (getenv('THREADS') ?: 2);
$summary = [];

foreach ($voices as $spec) {
    [$name, $rest] = explode('=', $spec, 2);
    $parts = explode(':', $rest);
    [$engineName, $dir] = $parts;
    $sid = (int) ($parts[2] ?? 0);
    $files = match ($engineName) {
        'kokoro', 'kitten' => ['model' => basename(glob("$dir/model*.onnx")[0]), 'voices' => 'voices.bin', 'tokens' => 'tokens.txt', 'data_dir' => 'espeak-ng-data'],
        'supertonic' => ['model' => 'tts.json', 'duration_predictor' => 'duration_predictor.int8.onnx', 'text_encoder' => 'text_encoder.int8.onnx',
            'vector_estimator' => 'vector_estimator.int8.onnx', 'vocoder' => 'vocoder.int8.onnx', 'tts_json' => 'tts.json',
            'unicode_indexer' => 'unicode_indexer.bin', 'voice_style' => 'voice.bin'],
        default => ['model' => basename(glob("$dir/*.onnx")[0]), 'tokens' => 'tokens.txt', 'data_dir' => 'espeak-ng-data'],
    };
    if ($engineName === 'kokoro' && is_file("$dir/lexicon-us-en.txt") && $lang === 'en') {
        $files['lexicon'] = 'lexicon-us-en.txt';
    }
    $voice = ['id' => $name, 'engine' => $engineName, 'files' => $files, 'sid' => $sid,
        'lang' => $engineName === 'kokoro' ? ($lang === 'en' ? 'en-us' : '') : '',
        'lang_code' => $asrLang, 'steps' => (int) ($parts[3] ?? 6)];
    $t = microtime(true);
    $engine->load($name, $voice, $dir, $threads);
    $load = microtime(true) - $t;

    $gen = 0.0;
    $audio = 0.0;
    $cers = [];
    $rows = [];
    foreach ($sentences as $i => $text) {
        $t = microtime(true);
        $wav = $engine->synthesize($name, $text, 1.0);
        $dt = microtime(true) - $t;
        $gen += $dt;
        $secs = (strlen($wav) - 44) / 2 / unpack('V', substr($wav, 24, 4))[1];
        $audio += $secs;
        $file = "$outDir/$name-$i.wav";
        file_put_contents($file, $wav);
        // للتقييم فقط: نضيف صمتاً قصيراً حتى لا يُسقط Whisper آخر كلمة
        $sr = unpack('V', substr($wav, 24, 4))[1];
        $pad = str_repeat("\0\0", (int) ($sr * 0.6));
        $data = substr($wav, 44) . $pad;
        $padded = substr($wav, 0, 4) . pack('V', 36 + strlen($data)) . substr($wav, 8, 32) . pack('V', strlen($data)) . $data;
        $asrFile = "$outDir/$name-$i.asr.wav";
        file_put_contents($asrFile, $padded);

        $cmd = [$asrBin, "--whisper-encoder=$whisperDir/turbo-encoder.int8.onnx", "--whisper-decoder=$whisperDir/turbo-decoder.int8.onnx",
            "--tokens=$whisperDir/turbo-tokens.txt", "--whisper-language=$asrLang", '--whisper-task=transcribe', '--num-threads=4', $asrFile];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $outText = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        proc_close($p);
        $hyp = '';
        if (preg_match('/"text":\s*"((?:[^"\\\\]|\\\\.)*)"/u', $outText, $mm)) {
            $hyp = json_decode('"' . $mm[1] . '"');
        }
        $c = cer(norm($text, $asrLang), norm((string) $hyp, $asrLang));
        $cers[] = $c;
        $rows[] = sprintf("    %.2fs gen / %.1fs audio  CER %4.0f%%  «%s» → «%s»", $dt, $secs, $c * 100, $text, $hyp);
    }
    $avgCer = array_sum($cers) / count($cers);
    $summary[] = sprintf('%-22s load %.2fs  RTF %.3f  avg gen %.2fs  CER %.1f%%', $name, $load, $gen / max($audio, 0.01), $gen / count($sentences), $avgCer * 100);
    echo "== $name\n" . implode("\n", $rows) . "\n";
}
echo "\nSUMMARY ($lang, $threads threads)\n" . implode("\n", $summary) . "\n";
