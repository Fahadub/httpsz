<?php
/**
 * بصير — محرك الصوت المدمج: يحمّل نماذج الكلام مرة واحدة في الذاكرة عبر PHP FFI
 * (مكتبة sherpa-onnx مفتوحة المصدر، Apache-2.0) ثم يولّد صوت كل جملة في جزء من الثانية.
 * يُستخدم داخل خادم الصوت الدائم (voice_daemon.php) وليس داخل طلبات الويب.
 */
declare(strict_types=1);

final class SherpaTts
{
    /** تعريفات C المطابقة لـ sherpa-onnx/c-api/c-api.h (الإصدار المثبّت في VoiceCatalog::RUNTIME_VERSION). */
    private const CDEF = <<<'C'
typedef struct { const char *model; const char *lexicon; const char *tokens; const char *data_dir; float noise_scale; float noise_scale_w; float length_scale; const char *dict_dir; } VitsCfg;
typedef struct { const char *acoustic_model; const char *vocoder; const char *lexicon; const char *tokens; const char *data_dir; float noise_scale; float length_scale; const char *dict_dir; } MatchaCfg;
typedef struct { const char *model; const char *voices; const char *tokens; const char *data_dir; float length_scale; const char *dict_dir; const char *lexicon; const char *lang; } KokoroCfg;
typedef struct { const char *model; const char *voices; const char *tokens; const char *data_dir; float length_scale; } KittenCfg;
typedef struct { const char *tokens; const char *encoder; const char *decoder; const char *vocoder; const char *data_dir; const char *lexicon; float feat_scale; float t_shift; float target_rms; float guidance_scale; } ZipvoiceCfg;
typedef struct { const char *lm_flow; const char *lm_main; const char *encoder; const char *decoder; const char *text_conditioner; const char *vocab_json; const char *token_scores_json; int32_t voice_embedding_cache_capacity; } PocketCfg;
typedef struct { const char *duration_predictor; const char *text_encoder; const char *vector_estimator; const char *vocoder; const char *tts_json; const char *unicode_indexer; const char *voice_style; } SupertonicCfg;
typedef struct { VitsCfg vits; int32_t num_threads; int32_t debug; const char *provider; MatchaCfg matcha; KokoroCfg kokoro; KittenCfg kitten; ZipvoiceCfg zipvoice; PocketCfg pocket; SupertonicCfg supertonic; } ModelCfg;
typedef struct { ModelCfg model; const char *rule_fsts; int32_t max_num_sentences; const char *rule_fars; float silence_scale; } TtsCfg;
typedef struct { const float *samples; int32_t n; int32_t sample_rate; } GeneratedAudio;
typedef struct { float silence_scale; float speed; int32_t sid; const float *reference_audio; int32_t reference_audio_len; int32_t reference_sample_rate; const char *reference_text; int32_t num_steps; const char *extra; } GenCfg;
typedef struct SherpaOnnxOfflineTts SherpaOnnxOfflineTts;
const char *SherpaOnnxGetVersionStr();
const SherpaOnnxOfflineTts *SherpaOnnxCreateOfflineTts(const TtsCfg *config);
void SherpaOnnxDestroyOfflineTts(const SherpaOnnxOfflineTts *tts);
int32_t SherpaOnnxOfflineTtsSampleRate(const SherpaOnnxOfflineTts *tts);
const GeneratedAudio *SherpaOnnxOfflineTtsGenerateWithConfig(const SherpaOnnxOfflineTts *tts, const char *text, const GenCfg *config, void *callback, void *arg);
void SherpaOnnxDestroyOfflineTtsGeneratedAudio(const GeneratedAudio *p);
int64_t SherpaOnnxWaveFileSize(int32_t n_samples);
void SherpaOnnxWriteWaveToBuffer(const float *samples, int32_t n, int32_t sample_rate, char *buffer);
C;

    private FFI $ffi;
    /** مكتبات تابعة حُمّلت مسبقاً (ويندوز لا يبحث عنها بجوار المكتبة الرئيسية). */
    private array $deps = [];
    /** @var array<string, array{tts: mixed, engine: string}> النماذج المحمّلة */
    private array $models = [];
    /** نُبقي النصوص الممرّرة للمكتبة حيّة طوال عمر المحرك. */
    private array $keep = [];

    /** @param string[] $preload مكتبات يجب تحميلها قبل المكتبة الرئيسية (onnxruntime على ويندوز) */
    public function __construct(string $libraryPath, array $preload = [])
    {
        if (!extension_loaded('ffi')) {
            throw new RuntimeException('PHP FFI extension is not enabled (php -d extension=ffi -d ffi.enable=1)');
        }
        // ويندوز يريد مسارات بشرطة مائلة عكسية
        $native = static fn (string $p): string => realpath($p) ?: $p;
        foreach ($preload as $dep) {
            $this->deps[] = FFI::cdef('', $native($dep));
        }
        $this->ffi = FFI::cdef(self::CDEF, $native($libraryPath));
    }

    public function version(): string
    {
        return (string) $this->ffi->SherpaOnnxGetVersionStr();
    }

    public function has(string $modelKey): bool
    {
        return isset($this->models[$modelKey]);
    }

    /**
     * يحمّل نموذجاً من الكتالوج (مرة واحدة حتى لو استخدمته عدة أصوات).
     * @param array{engine:string,files:array<string,string>,lang?:string} $model
     */
    public function load(string $modelKey, array $model, string $dir, int $threads): void
    {
        if (isset($this->models[$modelKey])) {
            return;
        }
        $cfg = $this->ffi->new('TtsCfg');
        $path = fn (string $key): FFI\CData => $this->str($dir . '/' . $model['files'][$key]);
        $has = static fn (string $key): bool => isset($model['files'][$key]);

        switch ($model['engine']) {
            case 'vits':
                $m = $cfg->model->vits;
                $m->model = $path('model');
                $m->tokens = $path('tokens');
                if ($has('data_dir')) $m->data_dir = $path('data_dir');
                if ($has('lexicon')) $m->lexicon = $path('lexicon');
                $m->noise_scale = 0.667;
                $m->noise_scale_w = 0.8;
                $m->length_scale = 1.0;
                break;
            case 'kokoro':
                $m = $cfg->model->kokoro;
                $m->model = $path('model');
                $m->voices = $path('voices');
                $m->tokens = $path('tokens');
                if ($has('data_dir')) $m->data_dir = $path('data_dir');
                if ($has('lexicon')) $m->lexicon = $path('lexicon');
                if (!empty($model['lang'])) $m->lang = $this->str($model['lang']);
                $m->length_scale = 1.0;
                break;
            case 'supertonic':
                $m = $cfg->model->supertonic;
                foreach (['duration_predictor', 'text_encoder', 'vector_estimator', 'vocoder', 'tts_json', 'unicode_indexer', 'voice_style'] as $f) {
                    $m->$f = $path($f);
                }
                break;
            default:
                throw new RuntimeException('Unsupported voice engine: ' . $model['engine']);
        }
        $cfg->model->num_threads = max(1, $threads);
        $cfg->model->provider = $this->str('cpu');
        $cfg->max_num_sentences = 1;
        $cfg->silence_scale = 0.2;

        $tts = $this->ffi->SherpaOnnxCreateOfflineTts(FFI::addr($cfg));
        if ($tts === null) {
            throw new RuntimeException("Could not load model $modelKey from $dir");
        }
        $this->models[$modelKey] = ['tts' => $tts, 'engine' => $model['engine']];
    }

    /**
     * ينطق جملة بصوت من الكتالوج ويُرجع ملف WAV (16-bit mono) بلا صمت زائد في البداية والنهاية.
     * @param array{model:string,sid?:int,lang_code?:string,steps?:int,diacritics?:string} $voice
     */
    public function speak(array $voice, string $text, float $speed = 1.0): string
    {
        $key = $voice['model'];
        if (!isset($this->models[$key])) {
            throw new RuntimeException("Model $key is not loaded");
        }
        if (($voice['diacritics'] ?? 'keep') === 'strip') {
            $text = self::stripTashkeel($text);
        }
        $engine = $this->models[$key]['engine'];

        // النماذج متعددة اللغات: كل مقطع بلغته (اسم «OpenAI» داخل جملة عربية يُنطق بالإنجليزية)
        $segments = $engine === 'supertonic' ? self::scriptRuns($text, (string) ($voice['lang_code'] ?? 'en')) : [[$text, null]];
        $pcm = '';
        $rate = 0;
        foreach ($segments as [$segText, $lang]) {
            if (!preg_match('/[\p{L}\p{N}]/u', $segText)) {
                continue;
            }
            if ($engine === 'supertonic' && $lang === 'ar') {
                // Supertonic يقرأ «3» داخل جملة عربية بالإنجليزية: نحوّل الأرقام إلى كلمات عربية
                $segText = self::arabicNumbers($segText);
            }
            [$sr, $data] = self::pcm($this->generate($key, $voice, $segText, $speed, $lang));
            $data = self::trim($data, $sr);
            if ($pcm !== '') {
                $pcm .= str_repeat("\0\0", (int) ($sr * 0.08)); // فاصل قصير بين المقاطع
            }
            $pcm .= $data;
            $rate = $sr;
        }
        if ($pcm === '') {
            throw new RuntimeException('No audio generated');
        }
        return self::wav($pcm, $rate);
    }

    private function generate(string $key, array $voice, string $text, float $speed, ?string $lang): string
    {
        $g = $this->ffi->new('GenCfg');
        $g->speed = max(0.5, min(2.0, $speed));
        $g->sid = (int) ($voice['sid'] ?? 0);
        $g->silence_scale = 0.2;
        if ($this->models[$key]['engine'] === 'supertonic') {
            $g->num_steps = (int) ($voice['steps'] ?? 5);
            $g->extra = $this->str(json_encode(['lang' => $lang ?? (string) ($voice['lang_code'] ?? 'en')]), true);
        }
        $audio = $this->ffi->SherpaOnnxOfflineTtsGenerateWithConfig($this->models[$key]['tts'], $text, FFI::addr($g), null, null);
        if ($audio === null || $audio->n <= 0) {
            if ($audio !== null) {
                $this->ffi->SherpaOnnxDestroyOfflineTtsGeneratedAudio($audio);
            }
            throw new RuntimeException('No audio generated');
        }
        try {
            $size = (int) $this->ffi->SherpaOnnxWaveFileSize($audio->n);
            $buf = $this->ffi->new("char[$size]");
            $this->ffi->SherpaOnnxWriteWaveToBuffer($audio->samples, $audio->n, $audio->sample_rate, $buf);
            return FFI::string($buf, $size);
        } finally {
            $this->ffi->SherpaOnnxDestroyOfflineTtsGeneratedAudio($audio);
        }
    }

    // ───────────────────────── أدوات النص والصوت ─────────────────────────

    public static function stripTashkeel(string $text): string
    {
        return (string) preg_replace('/[\x{064B}-\x{0652}\x{0670}\x{0640}]/u', '', $text);
    }

    /**
     * يقسم النص إلى مقاطع عربية ولاتينية: [[نص، لغة]...]. الأرقام وعلامات الترقيم تتبع المقطع السابق.
     * @return array<int, array{0:string,1:string}>
     */
    public static function scriptRuns(string $text, string $baseLang): array
    {
        $runs = [];
        $cur = '';
        $curLang = null;
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            $lang = preg_match('/\p{Arabic}/u', $ch) ? 'ar' : (preg_match('/[A-Za-z]/', $ch) ? 'en' : null);
            if ($lang !== null && $curLang !== null && $lang !== $curLang) {
                $runs[] = [$cur, $curLang];
                $cur = '';
            }
            if ($lang !== null) {
                $curLang = $lang;
            }
            $cur .= $ch;
        }
        if ($cur !== '') {
            $runs[] = [$cur, $curLang ?? $baseLang];
        }
        return $runs;
    }

    /** يستبدل الأرقام (العربية والهندية، مع الكسور العشرية) بكلمات عربية. */
    public static function arabicNumbers(string $text): string
    {
        $text = strtr($text, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٫' => '.']);
        return (string) preg_replace_callback('/\d+(?:[.,]\d+)?/', static function ($m) {
            $parts = preg_split('/[.,]/', $m[0]);
            $words = self::intToArabic((int) $parts[0]);
            if (isset($parts[1]) && $parts[1] !== '') {
                $words .= ' فاصلة ' . self::intToArabic((int) $parts[1]);
            }
            return $words;
        }, $text);
    }

    private static function intToArabic(int $n): string
    {
        static $ones = ['صفر', 'واحد', 'اثنان', 'ثلاثة', 'أربعة', 'خمسة', 'ستة', 'سبعة', 'ثمانية', 'تسعة'];
        static $teens = ['عشرة', 'أحد عشر', 'اثنا عشر', 'ثلاثة عشر', 'أربعة عشر', 'خمسة عشر', 'ستة عشر', 'سبعة عشر', 'ثمانية عشر', 'تسعة عشر'];
        static $tens = [2 => 'عشرون', 'ثلاثون', 'أربعون', 'خمسون', 'ستون', 'سبعون', 'ثمانون', 'تسعون'];
        static $hundreds = [1 => 'مئة', 'مئتان', 'ثلاثمئة', 'أربعمئة', 'خمسمئة', 'ستمئة', 'سبعمئة', 'ثمانمئة', 'تسعمئة'];
        if ($n < 10) {
            return $ones[$n];
        }
        if ($n < 20) {
            return $teens[$n - 10];
        }
        if ($n < 100) {
            return ($n % 10 ? $ones[$n % 10] . ' و' : '') . $tens[intdiv($n, 10)];
        }
        if ($n < 1000) {
            return $hundreds[intdiv($n, 100)] . ($n % 100 ? ' و' . self::intToArabic($n % 100) : '');
        }
        if ($n < 1000000) {
            $k = intdiv($n, 1000);
            $head = match (true) {
                $k === 1 => 'ألف',
                $k === 2 => 'ألفان',
                $k <= 10 => self::intToArabic($k) . ' آلاف',
                default => self::intToArabic($k) . ' ألف',
            };
            return $head . ($n % 1000 ? ' و' . self::intToArabic($n % 1000) : '');
        }
        return (string) $n;
    }

    /** @return array{0:int,1:string} [معدل العينة، بيانات PCM] */
    private static function pcm(string $wav): array
    {
        $sr = unpack('V', substr($wav, 24, 4))[1];
        $pos = strpos($wav, 'data', 12);
        $offset = $pos === false ? 44 : $pos + 8;
        return [$sr, substr($wav, $offset)];
    }

    /** يحذف الصمت في أول المقطع وآخره (بعض النماذج تضيف نصف ثانية من كل جهة). */
    private static function trim(string $pcm, int $sr): string
    {
        $n = intdiv(strlen($pcm), 2);
        if ($n === 0) {
            return $pcm;
        }
        $block = 256;
        $loud = static function (int $from) use ($pcm, $block, $n): bool {
            $count = min($block, $n - $from);
            foreach (unpack('v*', substr($pcm, $from * 2, $count * 2)) as $v) {
                if ($v > 500 && $v < 65036) { // |عينة| > 500 (القيم السالبة تُقرأ فوق 32768)
                    return true;
                }
            }
            return false;
        };
        $start = 0;
        while ($start < $n && !$loud($start)) {
            $start += $block;
        }
        if ($start >= $n) {
            return $pcm; // كله صمت تقريباً: نتركه كما هو
        }
        $end = $n;
        while ($end - $block > $start && !$loud($end - $block)) {
            $end -= $block;
        }
        $start = max(0, $start - (int) ($sr * 0.03));
        $end = min($n, $end + (int) ($sr * 0.12));
        return substr($pcm, $start * 2, ($end - $start) * 2);
    }

    private static function wav(string $pcm, int $sr): string
    {
        $len = strlen($pcm);
        return 'RIFF' . pack('V', 36 + $len) . 'WAVE'
            . 'fmt ' . pack('VvvVVvv', 16, 1, 1, $sr, $sr * 2, 2, 16)
            . 'data' . pack('V', $len) . $pcm;
    }

    /** نص C ثابت. $temporary = يُحرَّر بعد الطلب التالي بدل أن يبقى طوال عمر المحرك. */
    private function str(string $s, bool $temporary = false): FFI\CData
    {
        $len = strlen($s) + 1;
        $buf = $this->ffi->new("char[$len]", false);
        FFI::memcpy($buf, $s . "\0", $len);
        if ($temporary) {
            static $last = null;
            if ($last !== null) {
                FFI::free($last);
            }
            $last = $buf;
        } else {
            $this->keep[] = $buf;
        }
        return $this->ffi->cast('const char *', $buf);
    }

    public function __destruct()
    {
        foreach ($this->models as $m) {
            $this->ffi->SherpaOnnxDestroyOfflineTts($m['tts']);
        }
        foreach ($this->keep as $b) {
            FFI::free($b);
        }
    }
}
