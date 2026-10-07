<?php
/**
 * يتحقق من وضوح الصوت المدمج: يحوّل كل ملف إلى نص بـ Whisper ويقارنه بالنص المتوقع (نسبة الخطأ CER).
 *   php tests/asr_check.php <sherpa-onnx-offline> <whisper-dir> <dir-with-manifest>...
 */
declare(strict_types=1);

[$_, $asr, $whisper] = array_slice($argv, 0, 3);
$dirs = array_slice($argv, 3);

function norm(string $s): string
{
    $s = mb_strtolower($s);
    $s = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $s);
    $s = strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي']);
    // أرقام وكلماتها تُعامل كشيء واحد
    $s = strtr($s, ['3' => 'ثلاث', 'three' => '3', 'ثلاثه' => 'ثلاث', 'gpt' => 'جي بي تي']);
    return preg_replace('/[^\p{L}\p{N}]+/u', '', $s);
}

function cer(string $ref, string $hyp): float
{
    $r = preg_split('//u', $ref, -1, PREG_SPLIT_NO_EMPTY);
    $h = preg_split('//u', $hyp, -1, PREG_SPLIT_NO_EMPTY);
    $prev = range(0, count($h));
    foreach ($r as $i => $rc) {
        $cur = [$i + 1];
        foreach ($h as $j => $hc) {
            $cur[$j + 1] = min($prev[$j + 1] + 1, $cur[$j] + 1, $prev[$j] + ($rc === $hc ? 0 : 1));
        }
        $prev = $cur;
    }
    return $r ? end($prev) / count($r) : 0.0;
}

$worst = 0.0;
$rows = 0;
foreach ($dirs as $dir) {
    $manifest = json_decode((string) @file_get_contents("$dir/manifest.json"), true) ?: [];
    echo "== $dir\n";
    foreach ($manifest as $m) {
        $wav = file_get_contents("$dir/{$m['file']}");
        // صمت قصير في النهاية حتى لا يُسقط Whisper آخر كلمة
        $sr = unpack('V', substr($wav, 24, 4))[1];
        $data = substr($wav, 44) . str_repeat("\0\0", (int) ($sr * 0.6));
        $padded = 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVE' . substr($wav, 12, 24) . 'data' . pack('V', strlen($data)) . $data;
        $tmp = sys_get_temp_dir() . '/asr-' . md5($dir . $m['file']) . '.wav';
        file_put_contents($tmp, $padded);
        $cmd = [$asr, "--whisper-encoder=$whisper/turbo-encoder.int8.onnx", "--whisper-decoder=$whisper/turbo-decoder.int8.onnx",
            "--tokens=$whisper/turbo-tokens.txt", "--whisper-language={$m['lang']}", '--whisper-task=transcribe', '--num-threads=2', $tmp];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $o = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        proc_close($p);
        $hyp = preg_match('/"text":\s*"((?:[^"\\\\]|\\\\.)*)"/u', $o, $mm) ? json_decode('"' . $mm[1] . '"') : '';
        $c = cer(norm($m['text']), norm((string) $hyp));
        $worst = max($worst, $c);
        $rows++;
        printf("  CER %3.0f%%  %-14s «%s» → «%s»\n", $c * 100, $m['file'], $m['text'], $hyp);
    }
}
printf("\n%d files, worst CER %.0f%%\n", $rows, $worst * 100);
// 35%+ يعني كلاماً غير مفهوم (مثلاً نص عربي تشوّه في الطريق)
exit($rows > 0 && $worst < 0.35 ? 0 : 1);
