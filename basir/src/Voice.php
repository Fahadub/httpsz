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

    /** حالة الأصوات لكل لغة، لواجهة التطبيق وصفحة الإعداد. */
    public static function status(array $settings): array
    {
        $ping = self::request(['op' => 'ping'], 0.4);
        $live = is_array($ping) ? ($ping['voices'] ?? []) : [];
        $out = ['daemon' => is_array($ping), 'engine_installed' => VoiceCatalog::runtime() !== null, 'langs' => []];
        foreach (array_keys(VoiceCatalog::VOICES) as $lang) {
            $id = $live[$lang] ?? VoiceCatalog::chosenVoice($lang, $settings);
            $v = $id ? VoiceCatalog::voice($id) : null;
            $out['langs'][$lang] = ['ready' => isset($live[$lang]), 'voice' => $id, 'name' => $v['name'] ?? null];
        }
        return $out;
    }

    /** ملف WAV لجملة بصوت اللغة المطلوبة. يرمي استثناءً إذا لم يكن الصوت المدمج متاحاً. */
    public static function speak(string $lang, string $text, float $speed, array $settings): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            throw new RuntimeException('empty text');
        }
        $voice = VoiceCatalog::readyVoices($settings)[$lang]['id'] ?? null;
        if (!$voice) {
            throw new RuntimeException("no $lang voice installed");
        }
        $speed = round(max(0.5, min(2.0, $speed)), 2);
        $cacheDir = VoiceCatalog::dir() . '/cache';
        $file = $cacheDir . '/' . sha1("$voice|$speed|$text") . '.wav';
        if (is_file($file)) {
            @touch($file);
            return (string) file_get_contents($file);
        }

        $wav = self::request(['op' => 'say', 'lang' => $lang, 'text' => $text, 'speed' => $speed], 30.0);
        if (!is_string($wav) || strlen($wav) < 44) {
            throw new RuntimeException('voice server unavailable');
        }
        try {
            ensure_dir($cacheDir);
            file_put_contents($file . '.tmp', $wav);
            rename($file . '.tmp', $file);
            self::prune($cacheDir);
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
     * طلب واحد لخادم الصوت. يُرجع: مصفوفة لردود JSON، أو نص WAV لطلب say، أو null عند الفشل.
     */
    private static function request(array $req, float $timeout): array|string|null
    {
        $port = VoiceCatalog::daemonPort();
        $conn = @stream_socket_client("tcp://127.0.0.1:$port", $errno, $errstr, min(1.0, $timeout));
        if (!$conn) {
            return null;
        }
        stream_set_timeout($conn, (int) ceil($timeout));
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
        if (preg_match('/^OK (\d+)/', $head, $m)) {
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
            return strlen($data) === $len ? $data : null;
        }
        fclose($conn);
        return null;
    }

    private static function prune(string $dir): void
    {
        if (random_int(1, 20) !== 1) {
            return;
        }
        $files = glob($dir . '/*.wav') ?: [];
        if (count($files) <= self::CACHE_MAX_FILES) {
            return;
        }
        usort($files, static fn ($a, $b) => filemtime($a) <=> filemtime($b));
        foreach (array_slice($files, 0, count($files) - self::CACHE_MAX_FILES) as $f) {
            @unlink($f);
        }
    }
}
