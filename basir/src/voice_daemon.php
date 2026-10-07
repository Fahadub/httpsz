<?php
/**
 * بصير — خادم الصوت الدائم. يحمّل الأصوات المثبتة مرة واحدة ثم يستقبل طلبات الكلام من api.php
 * على 127.0.0.1 فقط (لا يُفتح للشبكة). يشغّله start.sh / start.bat تلقائياً:
 *
 *   php -d ffi.enable=1 src/voice_daemon.php
 *
 * البروتوكول: سطر JSON واحد لكل اتصال:
 *   {"op":"ping"}                                   → سطر JSON
 *   {"op":"say","lang":"ar","text":"...","speed":1} → "OK <bytes>\n" ثم بيانات WAV، أو "ERR <رسالة>\n"
 *   {"op":"reload"}                                 → يعيد تحميل الأصوات المختارة في الإعداد
 */
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/SherpaTts.php';
require __DIR__ . '/VoiceCatalog.php';

set_time_limit(0);
ini_set('memory_limit', '2048M');

function vlog(string $msg): void
{
    fwrite(STDERR, '[' . date('H:i:s') . "] basir-voice: $msg\n");
}

/**
 * يحمّل النموذج اللازم لصوت كل لغة (النموذج المشترك يُحمّل مرة واحدة).
 * @return array{0:SherpaTts,1:array<string,array>} [المحرك، الصوت الجاهز لكل لغة]
 */
function load_voices(array $runtime, int $threads): array
{
    $voices = VoiceCatalog::readyVoices(settings_of(load_config()));
    $engine = new SherpaTts($runtime['library'], $runtime['preload'] ?? []);
    $t0 = microtime(true);
    foreach ($voices as $lang => $v) {
        try {
            $engine->load($v['model'], VoiceCatalog::MODELS[$v['model']], $v['dir'], $threads);
            vlog(sprintf('%s voice: %s', $lang, $v['id']));
        } catch (Throwable $e) {
            vlog("failed to load $lang voice {$v['id']}: " . $e->getMessage());
            unset($voices[$lang]);
        }
    }
    vlog(sprintf('engine %s ready in %.1fs (%d threads)', $engine->version(), microtime(true) - $t0, $threads));
    // تسخين: أول جملة أبطأ قليلاً، فننطقها الآن بدل أن ينتظرها الكفيف
    foreach ($voices as $lang => $v) {
        try {
            $engine->speak($v, $lang === 'en' ? 'Ready.' : 'جاهز.');
        } catch (Throwable) {
        }
    }
    return [$engine, $voices];
}

$runtime = VoiceCatalog::runtime();
if (!$runtime) {
    vlog('voice engine not installed — run: php tools/voices.php install');
    exit(2);
}
$threads = (int) (getenv('BASIR_VOICE_THREADS') ?: VoiceCatalog::defaultThreads());
try {
    [$engine, $voices] = load_voices($runtime, $threads);
} catch (Throwable $e) {
    vlog('cannot start voice engine: ' . $e->getMessage());
    exit(3);
}
if (!$voices) {
    vlog('no voices installed — run: php tools/voices.php install');
    exit(2);
}

$port = VoiceCatalog::daemonPort();
$server = @stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if (!$server) {
    vlog("cannot listen on 127.0.0.1:$port ($errstr) — another voice server is probably running");
    exit(4);
}
/** يسجّل الأصوات الجاهزة حتى تعرفها الواجهة البرمجية وهذا الخادم مشغول بنطق جملة طويلة. */
function announce(int $port, array $voices): void
{
    write_json(data_path('voices/daemon.json'), ['pid' => getmypid(), 'port' => $port, 'started' => gmdate('c'),
        'voices' => array_map(static fn ($v) => $v['id'], $voices)]);
}
announce($port, $voices);
vlog("listening on 127.0.0.1:$port");

while (true) {
    $conn = @stream_socket_accept($server, -1);
    if (!$conn) {
        continue;
    }
    stream_set_timeout($conn, 10);
    $line = fgets($conn, 64 * 1024);
    $req = is_string($line) ? json_decode($line, true) : null;
    $op = is_array($req) ? (string) ($req['op'] ?? '') : '';
    try {
        switch ($op) {
            case 'ping':
                fwrite($conn, json_encode([
                    'ok' => true, 'pid' => getmypid(), 'engine' => $engine->version(),
                    'voices' => array_map(static fn ($v) => $v['id'], $voices),
                ]) . "\n");
                break;
            case 'say':
                $lang = (string) ($req['lang'] ?? 'ar');
                $text = trim((string) ($req['text'] ?? ''));
                if (!isset($voices[$lang])) {
                    throw new RuntimeException("no $lang voice");
                }
                if ($text === '' || strlen($text) > 6000) {
                    throw new RuntimeException('empty or too long text');
                }
                $wav = $engine->speak($voices[$lang], $text, (float) ($req['speed'] ?? 1.0));
                fwrite($conn, 'OK ' . strlen($wav) . "\n");
                fwrite($conn, $wav);
                break;
            case 'reload':
                // صوت مختلف اختير في صفحة الإعداد (المحرك القديم يُحرَّر بعد نجاح تحميل الجديد)
                [$engine, $voices] = load_voices($runtime, $threads);
                announce($port, $voices);
                fwrite($conn, "OK 0\n");
                break;
            case 'quit':
                fwrite($conn, "OK 0\n");
                fclose($conn);
                @unlink(data_path('voices/daemon.json'));
                exit(0);
            default:
                throw new RuntimeException('bad request');
        }
    } catch (Throwable $e) {
        @fwrite($conn, 'ERR ' . str_replace("\n", ' ', $e->getMessage()) . "\n");
    }
    @fclose($conn);
}
