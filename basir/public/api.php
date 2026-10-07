<?php
/**
 * بصير — واجهة JSON الوحيدة للتطبيق. لا قاعدة بيانات: كل شيء في مجلد data/.
 *
 *   GET  api.php?action=status        حالة الإعداد (بدون كشف المفتاح)
 *   GET  api.php?action=presets       قائمة الموفرين الجاهزين
 *   POST api.php?action=save_config   حفظ الموفر والمفتاح (مرة واحدة)
 *   POST api.php?action=test          اختبار الاتصال بالموفر (نص + صورة)
 *   POST api.php?action=models        جلب قائمة النماذج من الموفر
 *   POST api.php?action=reset         حذف الإعدادات
 *   POST api.php?action=analyze       تحليل صورة/صور: navigate|describe|ask|read|survey
 *   GET  api.php?action=memory        ذاكرة المكان المحفوظة من المسح
 *   POST api.php?action=memory_clear  نسيان المكان
 *   POST api.php?action=node_frame    جوال إضافي يرسل آخر صورة لاتجاهه
 *   POST api.php?action=node_leave    جوال إضافي يفصل نفسه
 *   GET  api.php?action=nodes         الجوالات المتصلة الآن
 */
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

const NODE_DIRS = ['front' => 'الأمام', 'right' => 'اليمين', 'back' => 'الخلف', 'left' => 'اليسار'];
const NODE_FRESH_SECONDS = 12;
const MAX_BODY_BYTES = 30 * 1024 * 1024;

final class ApiError extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 400)
    {
        parent::__construct($message);
    }
}

send_cors_headers();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

try {
    $action = (string) ($_GET['action'] ?? 'status');
    $result = match ($action) {
        'status'       => action_status(),
        'presets'      => ['ok' => true, 'presets' => Providers::PRESETS, 'protocols' => Providers::PROTOCOLS],
        'save_config'  => action_save_config(body()),
        'test'         => action_test(body()),
        'models'       => action_models(body()),
        'reset'        => action_reset(body()),
        'analyze'      => action_analyze(body()),
        'memory'       => ['ok' => true, 'memory' => read_json(data_path('memory.json')) ?: null],
        'memory_clear' => action_memory_clear(body()),
        'node_frame'   => action_node_frame(body()),
        'node_leave'   => action_node_leave(body()),
        'nodes'        => ['ok' => true, 'nodes' => fresh_nodes()],
        default        => throw new ApiError('أمر غير معروف.', 404),
    };
    respond($result);
} catch (ApiError $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], $e->status);
} catch (ProviderError $e) {
    respond(['ok' => false, 'error' => $e->getMessage(), 'detail' => $e->detail, 'provider_status' => $e->status], 502);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => 'حدث خطأ داخلي في الخادم.', 'detail' => $e->getMessage()], 500);
}

// ───────────────────────── أدوات الطلب والرد ─────────────────────────

function respond(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * جسم الطلب JSON. نشترط Content-Type: application/json لأن المتصفح لا يسمح لموقع غريب
 * بإرساله دون إذن CORS — هذا يمنع أي موقع خارجي من تغيير إعداداتك (CSRF).
 */
function body(): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new ApiError('يجب استخدام POST.', 405);
    }
    $type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
    if (!str_starts_with($type, 'application/json')) {
        throw new ApiError('نوع المحتوى يجب أن يكون application/json.', 415);
    }
    $raw = file_get_contents('php://input', false, null, 0, MAX_BODY_BYTES + 1);
    if ($raw === false || strlen($raw) > MAX_BODY_BYTES) {
        throw new ApiError('الطلب كبير جداً.', 413);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new ApiError('صيغة JSON غير صحيحة.');
    }
    return $data;
}

function send_cors_headers(): void
{
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '') {
        return;
    }
    // أصول تطبيق الجوال (Capacitor) + أي أصول إضافية من متغير البيئة
    $allowed = ['capacitor://localhost', 'ionic://localhost', 'http://localhost', 'https://localhost'];
    foreach (explode(',', (string) getenv('BASIR_CORS_ORIGINS')) as $o) {
        if (trim($o) !== '') {
            $allowed[] = rtrim(trim($o), '/');
        }
    }
    if (in_array($origin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 600');
    }
}

// ───────────────────────── الإعدادات ─────────────────────────

function action_status(): array
{
    $cfg = load_config();
    $configured = is_configured($cfg);
    $memory = read_json(data_path('memory.json'));
    return [
        'ok' => true,
        'version' => BASIR_VERSION,
        'configured' => $configured,
        'provider' => $configured ? [
            'id' => $cfg['provider'],
            'label' => $cfg['label'] ?? Providers::preset($cfg['provider'])['label'],
            'protocol' => $cfg['protocol'],
            'base_url' => $cfg['base_url'],
            'model' => $cfg['model'],
            'has_key' => !empty($cfg['api_key']),
            'key_hint' => key_hint((string) ($cfg['api_key'] ?? '')),
            'updated_at' => $cfg['updated_at'] ?? null,
        ] : null,
        'settings' => settings_of($cfg),
        'pin_required' => !empty($cfg['admin_pin_hash']),
        'memory' => $memory ? [
            'updated_at' => $memory['updated_at'] ?? null,
            'say' => $memory['say'] ?? '',
            'summary' => $memory['summary'] ?? '',
        ] : null,
        'nodes' => fresh_nodes(),
    ];
}

function require_pin(array $cfg, array $in): void
{
    if (!empty($cfg['admin_pin_hash']) && !password_verify((string) ($in['pin'] ?? ''), $cfg['admin_pin_hash'])) {
        throw new ApiError('رمز حماية الإعدادات غير صحيح.', 403);
    }
}

/**
 * يبني إعداد الموفر من مدخلات صفحة الإعداد. إذا تُرك حقل المفتاح فارغاً يُعاد استخدام
 * المفتاح المحفوظ — لكن فقط إذا لم يتغيّر البروتوكول والرابط، كي لا يُرسل المفتاح لخادم آخر.
 */
function candidate_config(array $in, array $current): array
{
    $provider = array_key_exists((string) ($in['provider'] ?? ''), Providers::PRESETS) ? (string) $in['provider'] : 'custom';
    $preset = Providers::preset($provider);

    $protocol = $provider === 'custom' ? (string) ($in['protocol'] ?? 'openai') : $preset['protocol'];
    if (!in_array($protocol, Providers::PROTOCOLS, true)) {
        throw new ApiError('البروتوكول غير مدعوم.');
    }

    $base = trim((string) ($in['base_url'] ?? '')) ?: $preset['base_url'];
    $base = rtrim($base, '/');
    $scheme = strtolower((string) parse_url($base, PHP_URL_SCHEME));
    if ($base === '' || !filter_var($base, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
        throw new ApiError('الرابط الأساسي (Base URL) غير صحيح. مثال: https://api.openai.com/v1');
    }

    $model = trim((string) ($in['model'] ?? '')) ?: $preset['model'];
    if ($model === '' || strlen($model) > 200) {
        throw new ApiError('اكتب اسم النموذج (Model) الذي يدعم الصور.');
    }

    $key = trim((string) ($in['api_key'] ?? ''));
    if ($key === ''
        && !empty($current['api_key'])
        && ($current['protocol'] ?? '') === $protocol
        && rtrim((string) ($current['base_url'] ?? ''), '/') === $base) {
        $key = (string) $current['api_key'];
    }
    if ($key === '' && $preset['needs_key']) {
        throw new ApiError('أدخل مفتاح الموفر (API Key).');
    }

    $s = $in['settings'] ?? [];
    $settings = [
        'speech_lang' => preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', (string) ($s['speech_lang'] ?? '')) ? (string) $s['speech_lang'] : BASIR_DEFAULT_SETTINGS['speech_lang'],
        'speech_rate' => clamp_float($s['speech_rate'] ?? null, 0.5, 2.0, BASIR_DEFAULT_SETTINGS['speech_rate']),
        'step_m'      => clamp_float($s['step_m'] ?? null, 0.3, 1.2, BASIR_DEFAULT_SETTINGS['step_m']),
        'interval_s'  => clamp_float($s['interval_s'] ?? null, 0.5, 30, BASIR_DEFAULT_SETTINGS['interval_s']),
        'image_px'    => (int) clamp_float($s['image_px'] ?? null, 320, 1600, BASIR_DEFAULT_SETTINGS['image_px']),
    ];

    $label = trim((string) ($in['label'] ?? ''));
    return [
        'provider' => $provider,
        'label' => $label !== '' ? mb_substr($label, 0, 60) : $preset['label'],
        'protocol' => $protocol,
        'base_url' => $base,
        'api_key' => $key,
        'model' => $model,
        'settings' => $settings,
        'admin_pin_hash' => $current['admin_pin_hash'] ?? null,
    ];
}

function action_save_config(array $in): array
{
    $current = load_config();
    require_pin($current, $in);
    $cfg = candidate_config($in, $current);
    $newPin = trim((string) ($in['new_pin'] ?? ''));
    if ($newPin !== '') {
        if (!preg_match('/^\d{4,12}$/', $newPin)) {
            throw new ApiError('رمز الحماية يجب أن يكون من 4 إلى 12 رقماً.');
        }
        $cfg['admin_pin_hash'] = password_hash($newPin, PASSWORD_DEFAULT);
    }
    save_config($cfg);
    return action_status() + ['saved' => true];
}

function action_test(array $in): array
{
    $current = load_config();
    require_pin($current, $in);
    $cfg = candidate_config($in, $current);
    $images = [];
    if (function_exists('imagecreatetruecolor')) {
        $images[] = ['data' => test_image(), 'mime' => 'image/jpeg', 'label' => 'صورة اختبار'];
    }
    $t = microtime(true);
    $raw = Providers::complete(
        $cfg,
        'أنت تختبر الاتصال. أجب بكائن JSON فقط.',
        $images ? 'صف الصورة بكلمات قليلة. أجب بهذا الشكل: {"say":"..."}' : 'أجب بهذا الشكل: {"say":"جاهز"}',
        $images,
        200
    );
    $parsed = Prompts::parse($raw, 'describe');
    return [
        'ok' => true,
        'reply' => $parsed['say'],
        'vision_tested' => (bool) $images,
        'latency_ms' => (int) round((microtime(true) - $t) * 1000),
    ];
}

/** صورة اختبار صغيرة: خلفية زرقاء ومربع أصفر — للتأكد من أن النموذج يقبل الصور. */
function test_image(): string
{
    $im = imagecreatetruecolor(160, 120);
    imagefill($im, 0, 0, imagecolorallocate($im, 30, 90, 200));
    imagefilledrectangle($im, 50, 30, 110, 90, imagecolorallocate($im, 250, 210, 30));
    ob_start();
    imagejpeg($im, null, 85);
    imagedestroy($im);
    return base64_encode((string) ob_get_clean());
}

function action_models(array $in): array
{
    $current = load_config();
    require_pin($current, $in);
    $in['model'] = ($in['model'] ?? '') ?: 'x'; // النموذج غير مطلوب لجلب القائمة
    $cfg = candidate_config($in, $current);
    return ['ok' => true, 'models' => Providers::listModels($cfg)];
}

function action_reset(array $in): array
{
    $current = load_config();
    require_pin($current, $in);
    foreach (['config.json', 'compat.json'] as $f) {
        if (is_file(data_path($f))) {
            @unlink(data_path($f));
        }
    }
    return action_status();
}

function action_memory_clear(array $in): array
{
    if (is_file(data_path('memory.json'))) {
        @unlink(data_path('memory.json'));
    }
    return ['ok' => true];
}

// ───────────────────────── التحليل ─────────────────────────

/** يفك صورة base64 (أو data URL) ويتحقق من نوعها وحجمها. */
function decode_image(mixed $value, int $maxBytes = 4 * 1024 * 1024): array
{
    if (!is_string($value) || $value === '') {
        throw new ApiError('صورة فارغة.');
    }
    $b64 = preg_replace('#^data:image/[a-z+]+;base64,#i', '', $value);
    $bin = base64_decode($b64, true);
    if ($bin === false || strlen($bin) < 100) {
        throw new ApiError('صورة غير صالحة.');
    }
    if (strlen($bin) > $maxBytes) {
        throw new ApiError('الصورة كبيرة جداً.', 413);
    }
    $mime = match (true) {
        str_starts_with($bin, "\xFF\xD8\xFF") => 'image/jpeg',
        str_starts_with($bin, "\x89PNG") => 'image/png',
        str_starts_with($bin, 'RIFF') && substr($bin, 8, 4) === 'WEBP' => 'image/webp',
        default => throw new ApiError('نوع الصورة غير مدعوم (JPEG/PNG/WebP فقط).'),
    };
    return ['bin' => $bin, 'mime' => $mime];
}

function short_text(mixed $v, int $max): string
{
    return is_string($v) ? mb_substr(trim(preg_replace('/\s+/u', ' ', $v)), 0, $max) : '';
}

function action_analyze(array $in): array
{
    $cfg = load_config();
    if (!is_configured($cfg)) {
        throw new ApiError('لم يتم إعداد موفر الذكاء الاصطناعي بعد. اطلب من مساعد فتح صفحة الإعدادات.', 409);
    }
    $settings = settings_of($cfg);
    $mode = (string) ($in['mode'] ?? 'navigate');
    if (!in_array($mode, Prompts::MODES, true)) {
        throw new ApiError('وضع غير معروف.');
    }

    $images = [];
    $labels = [];
    $list = is_array($in['images'] ?? null) ? array_slice($in['images'], 0, 12) : [];
    foreach ($list as $i => $img) {
        $d = decode_image(is_array($img) ? ($img['data'] ?? '') : $img);
        $label = short_text(is_array($img) ? ($img['label'] ?? '') : '', 80) ?: 'الأمام';
        $labels[] = $label;
        $images[] = ['data' => base64_encode($d['bin']), 'mime' => $d['mime'], 'label' => 'صورة ' . ($i + 1) . ' — ' . $label];
    }

    $nodesUsed = [];
    if (!empty($in['include_nodes']) && in_array($mode, ['navigate', 'describe', 'ask'], true)) {
        foreach (fresh_nodes() as $node) {
            $file = data_path('nodes/' . $node['dir'] . '.jpg');
            $bin = @file_get_contents($file);
            if ($bin === false || $bin === '') {
                continue;
            }
            $label = NODE_DIRS[$node['dir']] . ' (جوال إضافي)';
            $labels[] = $label;
            $nodesUsed[] = $node['dir'];
            $images[] = ['data' => base64_encode($bin), 'mime' => 'image/jpeg', 'label' => 'صورة ' . (count($images) + 1) . ' — ' . $label];
        }
    }
    if (!$images) {
        throw new ApiError('لا توجد صورة. تأكد من تشغيل الكاميرا.');
    }

    $memory = read_json(data_path('memory.json'));
    $history = array_values(array_filter(array_map(
        static fn ($h) => short_text($h, 200),
        array_slice(is_array($in['history'] ?? null) ? $in['history'] : [], -3)
    )));

    $ctx = [
        'labels' => $labels,
        'goal' => short_text($in['goal'] ?? '', 150),
        'question' => short_text($in['question'] ?? '', 300),
        'history' => $history,
        'memory' => short_text($memory['summary'] ?? '', 1500),
    ];

    $t = microtime(true);
    $raw = Providers::complete($cfg, Prompts::system((float) $settings['step_m']), Prompts::user($mode, $ctx), $images, Prompts::maxTokens($mode));
    $result = Prompts::parse($raw, $mode);
    $latency = (int) round((microtime(true) - $t) * 1000);

    if ($mode === 'survey') {
        write_json(data_path('memory.json'), [
            'updated_at' => gmdate('c'),
            'say' => $result['say'],
            'summary' => $result['memory'] ?? $result['say'],
            'best_direction' => $result['best_direction'] ?? 'none',
            'frames' => count($images),
        ]);
    }

    return ['ok' => true, 'mode' => $mode] + $result + ['latency_ms' => $latency, 'nodes_used' => $nodesUsed, 'images' => count($images)];
}

// ───────────────────────── الجوالات الإضافية ─────────────────────────

function node_dir_of(array $in): string
{
    $dir = (string) ($in['dir'] ?? '');
    if (!array_key_exists($dir, NODE_DIRS)) {
        throw new ApiError('اتجاه غير صحيح.');
    }
    return $dir;
}

function action_node_frame(array $in): array
{
    $dir = node_dir_of($in);
    $img = decode_image($in['image'] ?? '', 2 * 1024 * 1024);
    if ($img['mime'] !== 'image/jpeg') {
        throw new ApiError('صورة الجوال الإضافي يجب أن تكون JPEG.');
    }
    write_file_atomic(data_path("nodes/$dir.jpg"), $img['bin']);
    write_json(data_path("nodes/$dir.json"), [
        'ts' => time(),
        'device' => short_text($in['device'] ?? '', 40),
    ]);
    return ['ok' => true, 'nodes' => fresh_nodes()];
}

function action_node_leave(array $in): array
{
    $dir = node_dir_of($in);
    foreach (["nodes/$dir.jpg", "nodes/$dir.json"] as $f) {
        if (is_file(data_path($f))) {
            @unlink(data_path($f));
        }
    }
    return ['ok' => true, 'nodes' => fresh_nodes()];
}

/** @return array<int,array{dir:string,name:string,age_s:int}> */
function fresh_nodes(): array
{
    $out = [];
    foreach (NODE_DIRS as $dir => $name) {
        $meta = read_json(data_path("nodes/$dir.json"));
        if (!$meta || !is_file(data_path("nodes/$dir.jpg"))) {
            continue;
        }
        $age = time() - (int) ($meta['ts'] ?? 0);
        if ($age <= NODE_FRESH_SECONDS) {
            $out[] = ['dir' => $dir, 'name' => $name, 'age_s' => $age];
        }
    }
    return $out;
}
