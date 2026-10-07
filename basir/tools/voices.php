<?php
/**
 * بصير — تثبيت الأصوات المدمجة وإدارتها (مرة واحدة، ثم تعمل بلا إنترنت).
 *
 *   php tools/voices.php install            يثبّت المحرك والصوت المختار لكل لغة (عربي + إنجليزي)
 *   php tools/voices.php install --quick    ما يكفي ليتكلم بصير فوراً (~140 MB): الصوت الكبير المختار
 *                                           يُستبدل مؤقتاً بالصوت الخفيف حتى يُنزَّل لاحقاً
 *   php tools/voices.php install --reload   بعد التثبيت يُبلغ خادم الصوت ليستخدم الأصوات الجديدة
 *   php tools/voices.php install --all      يثبّت كل الأصوات المتاحة
 *   php tools/voices.php install ar-kareem  يثبّت أصواتاً محددة (أو نماذج: supertonic3 kokoro kareem)
 *   php tools/voices.php status             ما هو مثبت
 *   php tools/voices.php say ar "مرحبا"      تجربة: يكتب data/voices/test.wav
 *
 * يُشغَّل تلقائياً من start.sh / start.bat.
 */
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/VoiceCatalog.php';
require __DIR__ . '/../src/SherpaTts.php';
require __DIR__ . '/../src/Voice.php';

const STATE = 'installed.json';
// --quick: النماذج الأكبر من هذا تُؤجَّل إلى التنزيل في الخلفية
const QUICK_MAX_BYTES = 200 * 1048576;

// تنزيل النماذج الكبيرة يحتاج ذاكرة ووقتاً أطول من إعدادات PHP الافتراضية
ini_set('memory_limit', '512M');
set_time_limit(0);

function out(string $msg): void
{
    fwrite(STDOUT, $msg . PHP_EOL);
}

function state(): array
{
    return read_json(VoiceCatalog::dir() . '/' . STATE, ['runtime' => null, 'voices' => []]);
}

function save_state(array $s): void
{
    write_json(VoiceCatalog::dir() . '/' . STATE, $s);
}

/** تنزيل مع شريط تقدم، ثم التحقق من الحجم والبصمة. */
function download(string $url, string $dest, int $size, string $sha256, string $label): void
{
    if (is_file($dest) && filesize($dest) === $size && hash_file('sha256', $dest) === $sha256) {
        return;
    }
    ensure_dir(dirname($dest));
    $tmp = $dest . '.part';
    $fh = fopen($tmp, 'wb');
    if (!$fh) {
        throw new RuntimeException("cannot write $tmp");
    }
    $last = -1;
    $progress = static function ($ch, $total, $now) use (&$last, $label, $size) {
        $total = $total ?: $size;
        $pct = $total ? (int) floor($now * 100 / $total) : 0;
        if ($pct !== $last && $pct % 5 === 0) {
            $last = $pct;
            fwrite(STDOUT, sprintf("\r  %s: %3d%% (%.0f MB)", $label, $pct, $total / 1048576));
        }
        return 0;
    };
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP curl extension is required to download voices');
    }
    $ch = curl_init($url);
    $opts = [
        CURLOPT_FILE => $fh,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 1800,
        CURLOPT_NOPROGRESS => false,
        CURLOPT_PROGRESSFUNCTION => $progress,
        CURLOPT_FAILONERROR => true,
        CURLOPT_USERAGENT => 'basir-voice-installer',
    ];
    if ($ca = getenv('BASIR_CA_BUNDLE')) {
        $opts[CURLOPT_CAINFO] = $ca;
    } elseif (PHP_OS_FAMILY === 'Windows' && !ini_get('curl.cainfo') && defined('CURLSSLOPT_NATIVE_CA')) {
        // PHP لويندوز غالباً بلا شهادات: نستخدم مخزن شهادات ويندوز نفسه
        $opts[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
    }
    curl_setopt_array($ch, $opts);
    $ok = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    fclose($fh);
    fwrite(STDOUT, PHP_EOL);
    if (!$ok) {
        @unlink($tmp);
        throw new RuntimeException("download failed: $err ($url)");
    }
    if (filesize($tmp) !== $size || hash_file('sha256', $tmp) !== $sha256) {
        @unlink($tmp);
        throw new RuntimeException("checksum mismatch for $label — the file may be corrupted, try again");
    }
    rename($tmp, $dest);
}

/** فك ضغط ‎.tar.bz2 بأداة tar الموجودة في ويندوز 10+ ولينكس وماك. */
function extract_tbz(string $archive, string $into): void
{
    ensure_dir($into);
    $tar = 'tar';
    if (PHP_OS_FAMILY === 'Windows') {
        // tar المدمج في ويندوز 10+ (bsdtar)؛ نسخة GNU في Git Bash تخطئ في مسارات C:
        $sys = (getenv('SystemRoot') ?: 'C:\\Windows') . '\\System32\\tar.exe';
        if (is_file($sys)) {
            $tar = $sys;
        }
    }
    $p = proc_open([$tar, '-xjf', $archive, '-C', $into], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) {
        throw new RuntimeException('tar is not available');
    }
    $msg = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    if (proc_close($p) !== 0) {
        throw new RuntimeException('extract failed: ' . trim($msg));
    }
}

function install_runtime(array &$state): void
{
    $platform = VoiceCatalog::platform();
    if (!$platform) {
        throw new RuntimeException('this operating system / CPU is not supported by the built-in voice (' . PHP_OS_FAMILY . ' ' . php_uname('m') . ')');
    }
    [$asset, $size, $sha, $libName, $preload] = VoiceCatalog::RUNTIMES[$platform];
    $base = VoiceCatalog::dir() . '/runtime';
    $folder = "$base/sherpa-onnx-v" . VoiceCatalog::RUNTIME_VERSION . "-$asset/lib";
    $lib = "$folder/$libName";
    if (!is_file($lib)) {
        $archive = VoiceCatalog::dir() . "/downloads/runtime-$platform.tar.bz2";
        out("Downloading voice engine for $platform …");
        download(VoiceCatalog::runtimeUrl($platform), $archive, $size, $sha, 'engine');
        extract_tbz($archive, $base);
        @unlink($archive);
    }
    if (!is_file($lib)) {
        throw new RuntimeException("engine library missing after extract: $lib");
    }
    $state['runtime'] = [
        'platform' => $platform,
        'version' => VoiceCatalog::RUNTIME_VERSION,
        'library' => $lib,
        'preload' => array_values(array_filter(array_map(static fn ($f) => "$folder/$f", $preload), 'is_file')),
    ];
    save_state($state);
    out("✓ voice engine ready ($platform)");
}

/** @return bool هل نُزِّل الآن (لا كان مثبتاً من قبل) */
function install_model(array &$state, string $key): bool
{
    $m = VoiceCatalog::MODELS[$key] ?? null;
    if (!$m) {
        throw new RuntimeException("unknown model $key");
    }
    $fresh = !VoiceCatalog::modelInstalled($key);
    if ($fresh) {
        $archive = VoiceCatalog::dir() . "/downloads/$key.tar.bz2";
        out(sprintf('Downloading voice model "%s" (%d MB) …', $key, round($m['size'] / 1048576)));
        download(VoiceCatalog::modelUrl($key), $archive, $m['size'], $m['sha256'], $key);
        extract_tbz($archive, VoiceCatalog::dir() . '/models');
        @unlink($archive);
    }
    if (!VoiceCatalog::modelInstalled($key)) {
        throw new RuntimeException("voice files missing after extract: " . VoiceCatalog::modelDir($key));
    }
    $state['models'][$key] ??= ['installed_at' => gmdate('c')];
    save_state($state);
    out("✓ voice model $key ready");
    return $fresh;
}

/** النماذج اللازمة: أسماء أصوات أو نماذج من سطر الأوامر، أو الأصوات المختارة/الافتراضية لكل لغة. */
function wanted_models(array $args): array
{
    if (in_array('--all', $args, true)) {
        return array_keys(VoiceCatalog::MODELS);
    }
    $keys = [];
    foreach (array_filter($args, static fn ($a) => $a[0] !== '-') as $a) {
        if (isset(VoiceCatalog::MODELS[$a])) {
            $keys[] = $a;
        } elseif ($v = VoiceCatalog::voice($a)) {
            $keys[] = $v['model'];
        } else {
            throw new RuntimeException("unknown voice or model: $a");
        }
    }
    if (!$keys) {
        $settings = settings_of(load_config());
        $quick = in_array('--quick', $args, true);
        foreach (array_keys(VoiceCatalog::VOICES) as $lang) {
            $key = VoiceCatalog::voice(VoiceCatalog::chosenVoice($lang, $settings))['model'];
            if ($quick && !VoiceCatalog::modelInstalled($key) && VoiceCatalog::MODELS[$key]['size'] > QUICK_MAX_BYTES) {
                $key = VoiceCatalog::LIGHT_MODEL;
            }
            $keys[] = $key;
        }
    }
    return array_values(array_unique($keys));
}

$cmd = $argv[1] ?? 'install';
$args = array_slice($argv, 2);

try {
    switch ($cmd) {
        case 'install':
            if (PHP_OS_FAMILY === 'Windows' && preg_match('/[^\x20-\x7E]/', VoiceCatalog::dir())) {
                out('Warning: the Basir folder path contains non-English letters; the built-in voice may fail.');
                out('         Move the basir folder to a simple path such as C:\\basir');
            }
            $state = state();
            install_runtime($state);
            $fresh = false;
            foreach (wanted_models($args) as $key) {
                $fresh = install_model($state, $key) || $fresh;
            }
            if ($fresh && in_array('--reload', $args, true)) {
                // خادم الصوت قد يكون ما زال يُحمِّل: نحاول لدقيقة
                for ($i = 0; $i < 60 && !Voice::reload(); $i++) {
                    sleep(1);
                }
                out($i < 60 ? '✓ voice server now uses the new voices' : 'voice server not reachable; the new voices are used after a restart');
            }
            break;

        case 'status':
            out('engine: ' . (VoiceCatalog::runtime()['library'] ?? 'not installed'));
            foreach (VoiceCatalog::listForSetup() as $v) {
                out(sprintf('  [%s] %-12s %s %s', $v['installed'] ? 'x' : ' ', $v['id'], $v['lang'], $v['name']));
            }
            break;

        case 'say':
            $lang = $args[0] ?? 'ar';
            $text = $args[1] ?? ($lang === 'en' ? 'Hello, this is Basir.' : 'مرحبا، أنا بصير.');
            $runtime = VoiceCatalog::runtime();
            $voice = VoiceCatalog::readyVoices(settings_of(load_config()))[$lang] ?? null;
            if (!$runtime || !$voice) {
                throw new RuntimeException("no $lang voice installed");
            }
            $engine = new SherpaTts($runtime['library'], $runtime['preload'] ?? []);
            $t = microtime(true);
            $engine->load($voice['model'], VoiceCatalog::MODELS[$voice['model']], $voice['dir'], VoiceCatalog::defaultThreads());
            $load = microtime(true) - $t;
            $t = microtime(true);
            $wav = $engine->speak($voice, $text);
            $file = VoiceCatalog::dir() . '/test.wav';
            file_put_contents($file, $wav);
            out(sprintf('✓ %s: %s (load %.2fs, speak %.2fs, %d bytes)', $voice['id'], $file, $load, microtime(true) - $t, strlen($wav)));
            break;

        case 'stop':
            // إيقاف خادم صوت قديم (قبل تشغيل جديد)
            $c = @stream_socket_client('tcp://127.0.0.1:' . VoiceCatalog::daemonPort(), $errno, $errstr, 0.5);
            if ($c) {
                fwrite($c, json_encode(['op' => 'quit']) . "\n");
                fgets($c);
                fclose($c);
                usleep(300000);
            }
            break;

        default:
            out('usage: php tools/voices.php install|status|say|stop');
            exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, PHP_EOL . '✗ ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
