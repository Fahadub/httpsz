<?php
/**
 * بصير — الصوت المدمج من جهة الواجهة البرمجية: يطلب الكلام من خادم الصوت الدائم (voice_daemon.php)
 * ويحفظ النتيجة في ذاكرة على القرص حتى تُنطق العبارات المتكررة فوراً.
 */
declare(strict_types=1);

require_once __DIR__ . '/VoiceCatalog.php';

final class Voice
{
    private const CACHE_MAX_FILES = 400;
    private const CACHE_MAX_BYTES = 150 * 1048576;
    private const CACHE_MAX_ENTRY = 2 * 1048576;

    /** حالة الأصوات لكل لغة، لواجهة التطبيق وصفحة الإعداد. */
    public static function status(array $settings): array
    {
        $ping = self::request(['op' => 'ping'], 0.4, $reached);
        if (!is_array($ping) && $reached) {
            // الخادم يعمل لكنه مشغول بنطق جملة طويلة: نعتمد الأصوات التي أعلنها عند تشغيله
            $info = read_json(data_path('voices/daemon.json'));
            $ping = is_array($info['voices'] ?? null) ? ['voices' => $info['voices']] : null;
        }
        $live = is_array($ping) ? ($ping['voices'] ?? []) : [];
        $out = ['daemon' => is_array($ping), 'engine_installed' => VoiceCatalog::runtime() !== null, 'langs' => []];
        // تقدم التنزيل في الخلفية (tools/voices.php run) لصفحة الإعداد
        $progress = read_json(VoiceCatalog::dir() . '/progress.json');
        if (($progress['stage'] ?? 'done') !== 'done' && ($progress['at'] ?? 0) > time() - 900) {
            $out['install'] = $progress;
        }
        foreach (array_keys(VoiceCatalog::VOICES) as $lang) {
            $id = $live[$lang] ?? VoiceCatalog::chosenVoice($lang, $settings);
            $v = $id ? VoiceCatalog::voice($id) : null;
            $out['langs'][$lang] = ['ready' => isset($live[$lang]), 'voice' => $id, 'name' => $v['name'] ?? null];
        }
        return $out;
    }

    /** الصوت الذي يستخدمه خادم الصوت الآن لكل لغة (من ملفه، بلا انتظار). */
    public static function liveIds(): array
    {
        $ids = read_json(data_path('voices/daemon.json'))['voices'] ?? [];
        return is_array($ids) ? $ids : [];
    }

    /** ملف WAV لجملة بصوت اللغة المطلوبة. يرمي استثناءً إذا لم يكن الصوت المدمج متاحاً. */
    public static function speak(string $lang, string $text, float $speed, array $settings): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            throw new RuntimeException('empty text');
        }
        // الصوت الذي يستخدمه خادم الصوت الآن (قد يختلف عن المختار ريثما يكتمل تنزيله)
        $voice = read_json(data_path('voices/daemon.json'))['voices'][$lang]
            ?? VoiceCatalog::readyVoices($settings)[$lang]['id'] ?? null;
        if (!$voice) {
            throw new RuntimeException("no $lang voice installed");
        }
        $speed = round(max(0.5, min(2.0, $speed)), 2);
        $cacheDir = VoiceCatalog::dir() . '/cache';
        $cached = static fn (string $v) => $cacheDir . '/' . sha1("$v|$speed|$text") . '.wav';
        $file = $cached((string) $voice);
        if (is_file($file) && filesize($file) > 44) {
            @touch($file);
            return (string) file_get_contents($file);
        }

        $res = self::request(['op' => 'say', 'lang' => $lang, 'text' => $text, 'speed' => $speed], 30.0);
        $wav = is_array($res) ? ($res['wav'] ?? null) : null;
        if (!is_string($wav) || strlen($wav) < 44) {
            throw new RuntimeException('voice server unavailable');
        }
        try {
            ensure_dir($cacheDir);
            $file = $cached((string) ($res['voice'] ?? $voice));
            // الجمل الطويلة جداً لا تتكرر: لا داعي لحفظها
            if (strlen($wav) <= self::CACHE_MAX_ENTRY) {
                $tmp = $file . '.' . getmypid() . '.tmp';
                if (@file_put_contents($tmp, $wav) === strlen($wav)) {
                    @rename($tmp, $file);
                } else {
                    @unlink($tmp); // قرص ممتلئ: لا نحفظ ملفاً ناقصاً
                }
                self::prune($cacheDir);
            }
        } catch (Throwable) {
            // الذاكرة اختيارية
        }
        return $wav;
    }

    /** يطلب من خادم الصوت تحميل الصوت المختار من جديد (بعد تغييره في الإعداد). */
    public static function reload(): bool
    {
        return self::request(['op' => 'reload'], 30.0) !== null;
    }

    /**
     * طلب واحد لخادم الصوت. يُرجع: مصفوفة لردود JSON، أو ['wav' => ..., 'voice' => ...] لطلب say، أو null عند الفشل.
     */
    private static function request(array $req, float $timeout, ?bool &$reached = null): array|string|null
    {
        $port = VoiceCatalog::daemonPort();
        $conn = @stream_socket_client("tcp://127.0.0.1:$port", $errno, $errstr, min(1.0, $timeout));
        $reached = (bool) $conn;
        if (!$conn) {
            return null;
        }
        stream_set_timeout($conn, (int) $timeout, (int) (fmod($timeout, 1.0) * 1e6));
        fwrite($conn, json_encode($req, JSON_UNESCAPED_UNICODE) . "\n");
        $head = fgets($conn, 8192);
        if (!is_string($head)) {
            fclose($conn);
            return null;
        }
        if (str_starts_with($head, '{')) {
            fclose($conn);
            $j = json_decode($head, true);
            return is_array($j) ? $j : null;
        }
        if (preg_match('/^OK (\d+)(?: (\S+))?/', $head, $m)) {
            $len = (int) $m[1];
            $data = '';
            while (strlen($data) < $len && !feof($conn)) {
                $chunk = fread($conn, min(65536, $len - strlen($data)));
                if ($chunk === false || $chunk === '') {
                    $info = stream_get_meta_data($conn);
                    if ($info['timed_out']) {
                        break;
                    }
                    continue;
                }
                $data .= $chunk;
            }
            fclose($conn);
            if ($len === 0) {
                return [];
            }
            return strlen($data) === $len ? ['wav' => $data, 'voice' => $m[2] ?? null] : null;
        }
        fclose($conn);
        return null;
    }

    private static function prune(string $dir): void
    {
        foreach (glob($dir . '/*.tmp') ?: [] as $f) {
            if (filemtime($f) < time() - 600) {
                @unlink($f); // بقايا كتابة انقطعت
            }
        }
        $files = glob($dir . '/*.wav') ?: [];
        $sizes = array_map('filesize', $files);
        $total = array_sum($sizes);
        if (count($files) <= self::CACHE_MAX_FILES && $total <= self::CACHE_MAX_BYTES) {
            return;
        }
        // الأقدم استخداماً أولاً، حتى يعود العدد والحجم تحت الحد
        $by = array_combine($files, $sizes);
        uksort($by, static fn ($a, $b) => filemtime($a) <=> filemtime($b));
        $count = count($by);
        foreach ($by as $f => $size) {
            if ($count <= self::CACHE_MAX_FILES && $total <= self::CACHE_MAX_BYTES) {
                break;
            }
            if (@unlink($f)) {
                $count--;
                $total -= $size;
            }
        }
    }
}
