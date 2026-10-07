<?php
/**
 * بصير — كتالوج الأصوات المدمجة: محرك الكلام لكل نظام تشغيل، والنماذج، والأصوات لكل لغة.
 * كل ملف مثبّت بإصدار وبصمة SHA-256، ويُنزَّل مرة واحدة فقط إلى data/voices ثم يعمل بلا إنترنت.
 *
 * المحرك: sherpa-onnx ‏(Apache-2.0) — https://github.com/k2-fsa/sherpa-onnx
 * لماذا هذه الأصوات؟ قيست كلها على جمل إرشاد حقيقية (السرعة + وضوح النطق عبر Whisper):
 *   - Supertonic 3: يقرأ العربية غير المشكولة بوضوح عالٍ، ونفس النموذج يتكلم الإنجليزية.
 *   - Kokoro 82M: أفضل صوت إنجليزي مفتوح خفيف (Apache-2.0).
 *   - كريم (Piper): الأسرع للأجهزة الضعيفة، لكنه يحتاج نصاً مشكولاً.
 */
declare(strict_types=1);

final class VoiceCatalog
{
    public const RUNTIME_VERSION = '1.13.8';
    public const DAEMON_PORT = 7778;

    private const RELEASES = 'https://github.com/k2-fsa/sherpa-onnx/releases/download';

    /** مكتبة المحرك لكل منصة: [اسم الملف، الحجم، SHA-256، اسم المكتبة داخل lib/، مكتبات تُحمَّل قبلها] */
    public const RUNTIMES = [
        'windows-x64' => ['win-x64-shared-MT-Release-lib', 8032957, 'b8eedf41bd6d3779218887b48367bb7a3ece5aaa7667f01f69ee823a12b0a9e7', 'sherpa-onnx-c-api.dll', ['onnxruntime_providers_shared.dll', 'onnxruntime.dll']],
        'windows-arm64' => ['win-arm64-shared-MT-Release-lib', 7622849, '22cd2b2b5e35c1132abc74a91ee77f683645437382617af6a4bd5818b9c509f4', 'sherpa-onnx-c-api.dll', ['onnxruntime_providers_shared.dll', 'onnxruntime.dll']],
        'linux-x64' => ['linux-x64-shared-lib', 9816899, '3892d184be41027e18165e67f549cd4e4cdd8dcd73ac5579e97afd55e14e30b6', 'libsherpa-onnx-c-api.so', []],
        'linux-arm64' => ['linux-aarch64-shared-cpu-lib', 12595068, 'fb98b80628a383909bf83626daca2e0d0ffc93bf1f7d222eaaa4282b388f072f', 'libsherpa-onnx-c-api.so', []],
        'macos-arm64' => ['osx-arm64-shared-lib', 8773198, 'ae77050cdae565496059d96f5ab33d77b397a0864282a4e4a26e3b3b3effb948', 'libsherpa-onnx-c-api.dylib', []],
        'macos-x64' => ['osx-x64-shared-lib', 10014197, '0f88371565a06372889c76266e253abd1b415fcc6f98232cabd968933efb0711', 'libsherpa-onnx-c-api.dylib', []],
    ];

    /** النماذج (ملف واحد قد يخدم عدة أصوات). المسارات داخل مجلد النموذج بعد فك الضغط. */
    public const MODELS = [
        'supertonic3' => [
            'archive' => 'sherpa-onnx-supertonic-3-tts-int8-2026-05-11', 'size' => 128774318,
            'sha256' => '82fa96f91c4ef8abaae3a14a3f4153facf88bed821d1f7331cec2700f432c427',
            'engine' => 'supertonic',
            'files' => [
                'duration_predictor' => 'duration_predictor.int8.onnx', 'text_encoder' => 'text_encoder.int8.onnx',
                'vector_estimator' => 'vector_estimator.int8.onnx', 'vocoder' => 'vocoder.int8.onnx',
                'tts_json' => 'tts.json', 'unicode_indexer' => 'unicode_indexer.bin', 'voice_style' => 'voice.bin',
            ],
            'license' => 'Supertonic 3 by Supertone — OpenRAIL-M',
        ],
        'kokoro' => [
            'archive' => 'kokoro-multi-lang-v1_0', 'size' => 349906910,
            'sha256' => 'c5f7e2d2caf082bc1d20fb70334a61d99d20b484500aad32e7cf84c128ea3298',
            'engine' => 'kokoro', 'lang' => 'en-us',
            'files' => ['model' => 'model.onnx', 'voices' => 'voices.bin', 'tokens' => 'tokens.txt', 'data_dir' => 'espeak-ng-data', 'lexicon' => 'lexicon-us-en.txt'],
            'license' => 'Kokoro-82M by hexgrad — Apache-2.0',
        ],
        'kareem' => [
            'archive' => 'vits-piper-ar_JO-kareem-medium', 'size' => 67177830,
            'sha256' => '9ebbcea30e0fbd588f7b2cb45ee897d6aeb1bf5791cbc037a7b5a3f641e3dbce',
            'engine' => 'vits',
            'files' => ['model' => 'ar_JO-kareem-medium.onnx', 'tokens' => 'tokens.txt', 'data_dir' => 'espeak-ng-data'],
            'license' => 'Piper voice ar_JO-kareem (dataset: github.com/AliMokhammad/arabicttstrain)',
        ],
    ];

    /**
     * الأصوات كما يراها المستخدم: اسم صوت فقط (بلا ذكر المحرك).
     * sid: رقم المتحدث داخل النموذج. lang_code: لغة النطق للنماذج متعددة اللغات.
     * diacritics: strip = يُزال التشكيل قبل النطق (النموذج تدرّب على نص عادي)، keep = يحتاج نصاً مشكولاً.
     */
    public const VOICES = [
        'ar' => [
            'ar-salem' => ['name' => 'سالم', 'gender' => 'male', 'model' => 'supertonic3', 'sid' => 5, 'lang_code' => 'ar', 'steps' => 5, 'diacritics' => 'strip', 'default' => true],
            'ar-noura' => ['name' => 'نورة', 'gender' => 'female', 'model' => 'supertonic3', 'sid' => 0, 'lang_code' => 'ar', 'steps' => 5, 'diacritics' => 'strip'],
            'ar-kareem' => ['name' => 'كريم (الأسرع)', 'gender' => 'male', 'model' => 'kareem', 'sid' => 0, 'diacritics' => 'keep'],
        ],
        'en' => [
            'en-hannah' => ['name' => 'Hannah', 'gender' => 'female', 'model' => 'kokoro', 'sid' => 3, 'default' => true],
            'en-michael' => ['name' => 'Michael', 'gender' => 'male', 'model' => 'kokoro', 'sid' => 16],
            'en-salem' => ['name' => 'Salem (light)', 'gender' => 'male', 'model' => 'supertonic3', 'sid' => 5, 'lang_code' => 'en', 'steps' => 5],
            'en-noura' => ['name' => 'Noura (light)', 'gender' => 'female', 'model' => 'supertonic3', 'sid' => 0, 'lang_code' => 'en', 'steps' => 5],
        ],
    ];

    /** المنصة الحالية مثل windows-x64 أو linux-arm64، أو null إن لم تكن مدعومة. */
    public static function platform(): ?string
    {
        $os = match (PHP_OS_FAMILY) {
            'Windows' => 'windows',
            'Linux' => 'linux',
            'Darwin' => 'macos',
            default => null,
        };
        $m = strtolower(php_uname('m'));
        $arch = match (true) {
            in_array($m, ['x86_64', 'amd64', 'x64'], true) => 'x64',
            in_array($m, ['aarch64', 'arm64', 'armv8', 'armv8l'], true) => 'arm64',
            default => null,
        };
        $key = $os && $arch ? "$os-$arch" : null;
        return $key && isset(self::RUNTIMES[$key]) ? $key : null;
    }

    public static function runtimeUrl(string $platform): string
    {
        $asset = 'sherpa-onnx-v' . self::RUNTIME_VERSION . '-' . self::RUNTIMES[$platform][0] . '.tar.bz2';
        return self::RELEASES . '/v' . self::RUNTIME_VERSION . '/' . $asset;
    }

    public static function modelUrl(string $modelKey): string
    {
        return self::RELEASES . '/tts-models/' . self::MODELS[$modelKey]['archive'] . '.tar.bz2';
    }

    /** صوت مع لغته ومعرّفه. */
    public static function voice(string $id): ?array
    {
        foreach (self::VOICES as $lang => $voices) {
            if (isset($voices[$id])) {
                return $voices[$id] + ['id' => $id, 'lang' => $lang];
            }
        }
        return null;
    }

    public static function defaultVoice(string $lang): ?string
    {
        foreach (self::VOICES[$lang] ?? [] as $id => $v) {
            if (!empty($v['default'])) {
                return $id;
            }
        }
        return array_key_first(self::VOICES[$lang] ?? []);
    }

    /** الصوت المختار للغة: من الإعدادات إن كان صحيحاً، وإلا الافتراضي. */
    public static function chosenVoice(string $lang, array $settings): ?string
    {
        $want = (string) ($settings['voice_' . $lang] ?? '');
        $v = $want !== '' ? self::voice($want) : null;
        return $v && $v['lang'] === $lang ? $want : self::defaultVoice($lang);
    }

    /** هل يحتاج الصوت العربي المختار نصاً مشكولاً من الذكاء الاصطناعي؟ */
    public static function needsTashkeel(array $settings): bool
    {
        $id = self::chosenVoice('ar', $settings);
        return $id !== null && (self::voice($id)['diacritics'] ?? 'strip') === 'keep';
    }

    public static function dir(): string
    {
        return data_path('voices');
    }

    public static function modelDir(string $modelKey): string
    {
        return self::dir() . '/models/' . self::MODELS[$modelKey]['archive'];
    }

    public static function modelInstalled(string $modelKey): bool
    {
        $m = self::MODELS[$modelKey];
        $first = $m['files']['model'] ?? reset($m['files']);
        return is_file(self::modelDir($modelKey) . '/' . $first);
    }

    public static function daemonPort(): int
    {
        return (int) (getenv('BASIR_VOICE_PORT') ?: self::DAEMON_PORT);
    }

    public static function defaultThreads(): int
    {
        $n = 0;
        if (PHP_OS_FAMILY === 'Windows') {
            $n = (int) getenv('NUMBER_OF_PROCESSORS');
        } elseif (is_readable('/proc/cpuinfo')) {
            $n = substr_count((string) file_get_contents('/proc/cpuinfo'), "\nprocessor") + 1;
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            $n = (int) @shell_exec('sysctl -n hw.ncpu');
        }
        return max(1, min(4, $n ?: 2));
    }

    /** مكتبة المحرك المثبتة: ['library' => ..., 'preload' => [...]] أو null. */
    public static function runtime(): ?array
    {
        $rt = read_json(self::dir() . '/installed.json')['runtime'] ?? null;
        return $rt && is_file($rt['library'] ?? '') ? $rt : null;
    }

    /**
     * الأصوات الجاهزة فعلاً لكل لغة (المختار إن كان مثبتاً، وإلا أي صوت مثبت لنفس اللغة).
     * @return array<string, array> lang => voice (مع model و dir)
     */
    public static function readyVoices(array $settings): array
    {
        $out = [];
        foreach (array_keys(self::VOICES) as $lang) {
            $ids = array_keys(self::VOICES[$lang]);
            $chosen = self::chosenVoice($lang, $settings);
            usort($ids, static fn ($a, $b) => ($b === $chosen) <=> ($a === $chosen));
            foreach ($ids as $id) {
                $v = self::voice($id);
                if (self::modelInstalled($v['model'])) {
                    $out[$lang] = $v + ['dir' => self::modelDir($v['model'])];
                    break;
                }
            }
        }
        return $out;
    }

    /** قائمة الأصوات لصفحة الإعداد: الاسم واللغة وهل هو مثبت وحجم التنزيل. */
    public static function listForSetup(): array
    {
        $list = [];
        foreach (self::VOICES as $lang => $voices) {
            foreach ($voices as $id => $v) {
                $list[] = [
                    'id' => $id, 'lang' => $lang, 'name' => $v['name'], 'gender' => $v['gender'],
                    'default' => !empty($v['default']), 'installed' => self::modelInstalled($v['model']),
                    'size_mb' => (int) round(self::MODELS[$v['model']]['size'] / 1048576),
                ];
            }
        }
        return $list;
    }
}
