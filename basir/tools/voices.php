<?php
/**
 * بصير — تثبيت الأصوات المدمجة وإدارتها (مرة واحدة، ثم تعمل بلا إنترنت).
 *
 *   php tools/voices.php run                خدمة الصوت كاملة (يشغّلها start.sh / start.bat في الخلفية):
 *                                           تنزيل مع إعادة المحاولة، خادم الصوت، الأصوات الأكبر لاحقاً،
 *                                           وإعادة تشغيل خادم الصوت إن توقف فجأة
 *   php tools/voices.php install            يثبّت المحرك والصوت المختار لكل لغة (عربي + إنجليزي)
 *   php tools/voices.php install --quick    ما يكفي ليتكلم بصير فوراً (~140 MB): الصوت الكبير المختار
 *                                           يُستبدل مؤقتاً بالصوت الخفيف حتى يُنزَّل لاحقاً
 *   php tools/voices.php install --reload   بعد التثبيت يُبلغ خادم الصوت ليستخدم الأصوات الجديدة
 *   php tools/voices.php install --all      يثبّت كل الأصوات المتاحة
 *   php tools/voices.php install ar-kareem  يثبّت أصواتاً محددة (أو نماذج: supertonic3 kokoro kareem)
 *   php tools/voices.php status             ما هو مثبت
 *   php tools/voices.php say ar "مرحبا"      تجربة: يكتب data/voices/test.wav
 *   php tools/voices.php stop               يوقف خادم الصوت
 */
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/VoiceCatalog.php';
require __DIR__ . '/../src/SherpaTts.php';
require __DIR__ . '/../src/Voice.php';

const STATE = 'installed.json';
// --quick: النماذج الأكبر من هذا تُؤجَّل إلى التنزيل في الخلفية
const QUICK_MAX_BYTES = 200 * 1048576;
// PHP 8.1 لا يعرّف الثابت لكن مكتبة curl فيه تدعمه (CURLSSLOPT_NATIVE_CA = 16)
const SSL_NATIVE_CA = 16;

// تنزيل النماذج الكبيرة يحتاج ذاكرة ووقتاً أطول من إعدادات PHP الافتراضية
ini_set('memory_limit', '512M');
set_time_limit(0);

function out(string $msg): void
{
    fwrite(STDOUT, '[' . date('H:i:s') . "] $msg" . PHP_EOL);
}

function state(): array
{
    return read_json(VoiceCatalog::dir() . '/' . STATE, ['runtime' => null, 'voices' => []]);
}

function save_state(array $s): void
{
    write_json(VoiceCatalog::dir() . '/' . STATE, $s);
}

/** تقدّم التنزيل لصفحة الإعداد (يقرؤه Voice::status). */
function progress(array $p): void
{
    try {
        write_json(VoiceCatalog::dir() . '/progress.json', $p + ['at' => time()]);
    } catch (Throwable) {
    }
}

/**
 * تنزيل يُستأنف من حيث انقطع (ملف ‎.part)، ثم التحقق من الحجم والبصمة.
 * لا حد لمدة التنزيل، لكن يُقطع إذا توقف تماماً دقيقة كاملة (ثم يُستأنف في المحاولة التالية).
 */
function download(string $url, string $dest, int $size, string $sha256, string $label): void
{
    if (is_file($dest) && filesize($dest) === $size && hash_file('sha256', $dest) === $sha256) {
        return;
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP curl extension is required to download voices');
    }
    ensure_dir(dirname($dest));
    $tmp = $dest . '.part';
    clearstatcache();
    $have = is_file($tmp) ? (int) filesize($tmp) : 0;
    if ($have >= $size) {
        // اكتمل في محاولة سابقة (أو تلف): نتحقق منه دون تنزيل
        if ($have === $size && hash_file('sha256', $tmp) === $sha256) {
            rename($tmp, $dest);
            return;
        }
        @unlink($tmp);
        $have = 0;
    }
    $fh = fopen($tmp, $have ? 'ab' : 'wb');
    if (!$fh) {
        throw new RuntimeException("cannot write $tmp");
    }
    if ($have) {
        out(sprintf('  resuming %s from %d MB', $label, $have / 1048576));
    }
    $last = -1;
    $progress = static function ($ch, $total, $now) use (&$last, $label, $size, &$have) {
        $pct = (int) floor(($have + $now) * 100 / $size);
        if ($pct !== $last && $pct % 5 === 0) {
            $last = $pct;
            fwrite(STDOUT, sprintf("\r  %s: %3d%% (%.0f MB)", $label, min(100, $pct), $size / 1048576));
            progress(['stage' => 'download', 'model' => $label, 'pct' => min(100, $pct), 'mb' => (int) round($size / 1048576)]);
        }
        return 0;
    };
    $checked = false;
    $write = static function ($ch, string $data) use ($fh, &$checked, &$have): int {
        if (!$checked) {
            $checked = true;
            // الخادم تجاهل طلب الاستئناف وأرسل الملف كاملاً: نبدأ من الصفر
            if ($have > 0 && curl_getinfo($ch, CURLINFO_RESPONSE_CODE) !== 206) {
                ftruncate($fh, 0);
                fseek($fh, 0);
                $have = 0;
            }
        }
        return (int) fwrite($fh, $data);
    };
    $ch = curl_init($url);
    $opts = [
        CURLOPT_WRITEFUNCTION => $write,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_LOW_SPEED_LIMIT => 1024,
        CURLOPT_LOW_SPEED_TIME => 60,
        CURLOPT_NOPROGRESS => false,
        CURLOPT_PROGRESSFUNCTION => $progress,
        CURLOPT_FAILONERROR => true,
        CURLOPT_USERAGENT => 'basir-voice-installer',
    ];
    if ($have) {
        $opts[CURLOPT_RANGE] = $have . '-';
    }
    if ($ca = getenv('BASIR_CA_BUNDLE')) {
        $opts[CURLOPT_CAINFO] = $ca;
    } elseif (PHP_OS_FAMILY === 'Windows' && !ini_get('curl.cainfo')) {
        // PHP لويندوز غالباً بلا شهادات: نستخدم مخزن شهادات ويندوز نفسه
        $opts[CURLOPT_SSL_OPTIONS] = defined('CURLSSLOPT_NATIVE_CA') ? CURLSSLOPT_NATIVE_CA : SSL_NATIVE_CA;
    }
    curl_setopt_array($ch, $opts);
    $ok = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    fclose($fh);
    fwrite(STDOUT, PHP_EOL);
    if (!$ok) {
        // نُبقي الجزء المنزَّل ليُستأنف في المحاولة التالية
        throw new RuntimeException("download interrupted: $err ($label)");
    }
    clearstatcache();
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
    // الإخراج إلى ملف لا أنبوب: لا يتوقف tar إذا كتب تحذيرات كثيرة
    $log = $into . '/.tar.log';
    $p = proc_open([$tar, '-xjf', $archive, '-C', $into], [1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes);
    if (!is_resource($p)) {
        throw new RuntimeException('tar is not available');
    }
    $code = proc_close($p);
    $msg = (string) @file_get_contents($log);
    @unlink($log);
    if ($code !== 0) {
        throw new RuntimeException('extract failed: ' . trim($msg));
    }
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir) || is_link($dir)) {
        @unlink($dir);
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

/**
 * يفك الضغط في مجلد مؤقت ثم ينقل المجلد كاملاً إلى مكانه دفعة واحدة:
 * انقطاع الكهرباء أثناء فك الضغط لا يترك نموذجاً ناقصاً يبدو مثبتاً.
 */
function extract_atomic(string $archive, string $into, string $top): void
{
    $tmp = "$into/.partial-$top";
    rrmdir($tmp);
    extract_tbz($archive, $tmp);
    if (!is_dir("$tmp/$top")) {
        rrmdir($tmp);
        throw new RuntimeException("unexpected archive layout: $top missing");
    }
    rrmdir("$into/$top"); // بقايا تثبيت ناقص قديم
    if (!rename("$tmp/$top", "$into/$top")) {
        throw new RuntimeException("cannot move $top into place");
    }
    rrmdir($tmp);
}

function install_runtime(array &$state): void
{
    $platform = VoiceCatalog::platform();
    if (!$platform) {
        throw new RuntimeException('this operating system / CPU is not supported by the built-in voice (' . PHP_OS_FAMILY . ' ' . php_uname('m') . ')');
    }
    [$asset, $size, $sha, $libName, $preload] = VoiceCatalog::RUNTIMES[$platform];
    $base = VoiceCatalog::dir() . '/runtime';
    $top = 'sherpa-onnx-v' . VoiceCatalog::RUNTIME_VERSION . "-$asset";
    $folder = "$base/$top/lib";
    $lib = "$folder/$libName";
    if (!is_file($lib)) {
        $archive = VoiceCatalog::dir() . "/downloads/runtime-$platform.tar.bz2";
        out("Downloading voice engine for $platform …");
        download(VoiceCatalog::runtimeUrl($platform), $archive, $size, $sha, 'engine');
        extract_atomic($archive, $base, $top);
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
        progress(['stage' => 'extract', 'model' => $key]);
        extract_atomic($archive, VoiceCatalog::dir() . '/models', $m['archive']);
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

/** @return bool هل نُزِّل شيء جديد */
function install_all(array $args): bool
{
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
    return $fresh;
}

/** يطلب من خادم الصوت استخدام الأصوات الجديدة (قد يكون ما زال يُحمِّل: نحاول لدقيقة). */
function reload_daemon(): void
{
    for ($i = 0; $i < 60 && !Voice::reload(); $i++) {
        sleep(1);
    }
    out($i < 60 ? '✓ voice server now uses the new voices' : 'voice server not reachable; the new voices are used after a restart');
}

function stop_daemon(): void
{
    $c = @stream_socket_client('tcp://127.0.0.1:' . VoiceCatalog::daemonPort(), $errno, $errstr, 0.5);
    if ($c) {
        stream_set_timeout($c, 5);
        fwrite($c, json_encode(['op' => 'quit']) . "\n");
        fgets($c);
        fclose($c);
        usleep(300000);
    }
}

/** خيارات PHP التي يحتاجها خادم الصوت (ffi، وإضافات ويندوز بلا php.ini) كما يحسبها php_args.php. */
function child_php_args(): array
{
    $p = proc_open([PHP_BINARY, __DIR__ . '/php_args.php', '--json'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $json = is_resource($p) ? stream_get_contents($pipes[1]) : '';
    if (is_resource($p)) {
        proc_close($p);
    }
    $args = json_decode((string) $json, true);
    return is_array($args) ? $args : ['-d', 'ffi.enable=1'];
}

/** @return resource|null */
function start_daemon(array $phpArgs)
{
    $log = data_path('voice.log');
    $p = proc_open(array_merge([PHP_BINARY], $phpArgs, [dirname(__DIR__) . '/src/voice_daemon.php']),
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
    if (!is_resource($p)) {
        out('✗ cannot start the voice server');
        return null;
    }
    out('voice server starting (log: data/voice.log)');
    return $p;
}

/**
 * خدمة الصوت في الخلفية: بصير يعمل من البداية (بصوت الجهاز)، وهذه الخدمة تجهّز الصوت المدمج
 * ثم تبقيه يعمل. يكفي أن تُغلق نافذة بصير لتتوقف كلها.
 */
function run_service(): void
{
    ensure_dir(VoiceCatalog::dir());
    $lock = fopen(VoiceCatalog::dir() . '/service.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        out('another voice service is already running');
        return;
    }
    $phpArgs = child_php_args();

    // 1) ما يكفي ليتكلم بصير (إعادة المحاولة إن لم يتوفر الإنترنت؛ التنزيل يُستأنف)
    for ($try = 1; ; $try++) {
        try {
            install_all(['--quick']);
            break;
        } catch (Throwable $e) {
            $wait = min(300, 30 * $try);
            progress(['stage' => 'error', 'error' => $e->getMessage(), 'retry_in' => $wait]);
            out('✗ ' . $e->getMessage() . " — retrying in {$wait}s");
            sleep($wait);
        }
    }

    // 2) خادم الصوت، و3) الأصوات الأكبر في الخلفية، و4) إعادة تشغيله إن توقف فجأة
    stop_daemon();
    $daemon = start_daemon($phpArgs);
    $restDone = false;
    $restAt = time() + 5;
    $restTry = 0;
    $nextCheck = 0;
    $crashes = [];
    while (true) {
        // صوت جديد اختير في صفحة الإعداد ولم يُنزَّل بعد
        if ($restDone && time() >= $nextCheck) {
            $nextCheck = time() + 30;
            $missing = array_filter(wanted_models([]), static fn ($k) => !VoiceCatalog::modelInstalled($k));
            if ($missing) {
                $restDone = false;
                $restAt = time();
                $restTry = 0;
            }
        }
        if (!$restDone && time() >= $restAt) {
            try {
                if (install_all([])) {
                    reload_daemon();
                }
                $restDone = true;
                progress(['stage' => 'done']);
            } catch (Throwable $e) {
                $wait = min(600, 60 * ++$restTry);
                $restAt = time() + $wait;
                progress(['stage' => 'error', 'error' => $e->getMessage(), 'retry_in' => $wait]);
                out('✗ ' . $e->getMessage() . " — retrying in {$wait}s");
            }
        }
        $st = $daemon ? proc_get_status($daemon) : ['running' => false, 'exitcode' => -1];
        if (!$st['running']) {
            if ($daemon) {
                proc_close($daemon);
            }
            $crashes = array_values(array_filter($crashes, static fn ($t) => $t > time() - 600));
            if (count($crashes) >= 5) {
                out('✗ the voice server keeps stopping (see data/voice.log); the device voice is used until Basir restarts');
                progress(['stage' => 'error', 'error' => 'voice server keeps stopping']);
                $daemon = null;
                sleep(60);
                continue;
            }
            $crashes[] = time();
            out('voice server stopped (exit ' . $st['exitcode'] . '); restarting');
            sleep($st['exitcode'] === 4 ? 15 : 3); // 4 = المنفذ مشغول (خادم قديم ما زال يتوقف)
            $daemon = start_daemon($phpArgs);
        }
        sleep(2);
    }
}

$cmd = $argv[1] ?? 'install';
$args = array_slice($argv, 2);

try {
    switch ($cmd) {
        case 'run':
            run_service();
            break;

        case 'install':
            if (install_all($args) && in_array('--reload', $args, true)) {
                reload_daemon();
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
            stop_daemon();
            break;

        default:
            out('usage: php tools/voices.php run|install|status|say|stop');
            exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, PHP_EOL . '✗ ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
