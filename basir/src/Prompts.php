<?php
/**
 * بصير — تعليمات النموذج لكل وضع (بالعربية أو الإنجليزية حسب لغة الطلب)، وتحليل ردّه إلى صيغة موحّدة.
 */
declare(strict_types=1);

final class Prompts
{
    public const MODES = ['navigate', 'describe', 'ask', 'read', 'survey'];

    public const ACTIONS = ['forward', 'slight_left', 'slight_right', 'left', 'right', 'stop', 'back', 'turn_around', 'wait', 'none'];

    private const JSON_NAV = '{"say":"%s","action":"forward|slight_left|slight_right|left|right|stop|back|turn_around|wait|none","steps":0,"hazard":false}';
    private const JSON_SURVEY = '{"say":"%s","memory":"%s","best_direction":"front|right|back|left|none","hazard":false}';

    /** @param bool $tashkeel اطلب نصاً مشكولاً بالكامل (لأصوات النطق التي تحتاجه) */
    public static function system(float $stepM, bool $tashkeel = false): string
    {
        $step = rtrim(rtrim(number_format($stepM, 2, '.', ''), '0'), '.');
        $numbers = $tashkeel
            ? 'شكّل نص say تشكيلاً كاملاً (الفتحة والضمة والكسرة والسكون والشدة والتنوين) حتى يُنطق صحيحاً، واكتب الأعداد كلماتٍ مُشكّلة لا أرقاماً، مثل: «تَقَدَّمْ ثَلَاثَ خُطُوَاتٍ».'
            : 'اكتب الأعداد في نص say كلماتٍ لا أرقاماً، مثل: «تقدّم ثلاث خطوات».';
        if (basir_lang() === 'en') {
            return <<<TXT
You are "Basir", the eyes of a blind person. The person holds a phone in front of their chest (camera height about 1.3 m); the rear camera faces forward, tilted slightly down.
Your words are read aloud by a speech engine: write plain English in very short sentences, no symbols, formatting or emoji, and write numbers as words (for example: "walk three steps").
Directions are always relative to the person's body: ahead, on your right, on your left, behind you, slightly right, slightly left.
Distances: estimate them in metres from the sizes of familiar objects (a door is about 2 m high and 0.9 m wide, a chair seat 0.45 m, a person about 1.7 m, floor tiles) and from their position in the image, then convert to steps where one step ≈ {$step} m, rounded to a whole number.
Safety first: warn immediately about stairs, kerbs and edges, holes, glass doors, cars, bicycles, cables and objects at head height. If you are not sure, say so and ask the person to stop instead of guessing.
If the image is dark, blurred or covered, ask the person to stop and adjust the phone.
Always answer with a single JSON object and nothing before or after it.
TXT;
        }
        return <<<TXT
أنت «بصير»، عيون شخص كفيف. الشخص يحمل جوالاً أمام صدره (ارتفاع الكاميرا تقريباً 1.3 متر)، والكاميرا الخلفية موجّهة للأمام مع ميل بسيط للأسفل.
كلامك سيُقرأ بصوت عالٍ بمحرك نطق آلي: اكتب بعربية فصحى بسيطة وجمل قصيرة جداً، بلا رموز ولا تنسيق ولا إيموجي ولا كلمات بحروف لاتينية.
{$numbers}
الاتجاهات دائماً بالنسبة لجسم الشخص: أمامك، يمينك، يسارك، خلفك، يمين قليلاً، يسار قليلاً.
المسافات: قدّرها بالأمتار من أحجام الأشياء المعروفة (الباب نحو 2 متر ارتفاعاً و0.9 عرضاً، مقعد الكرسي 0.45 متر، الإنسان نحو 1.7 متر، بلاط الأرض) ومن موضعها في الصورة، ثم حوّلها إلى خطوات علماً أن الخطوة ≈ {$step} متر، وقرّب لعدد صحيح.
السلامة أولاً: نبّه فوراً إلى الدرج والحواف والحفر والأبواب الزجاجية والسيارات والدراجات والأسلاك والأشياء على مستوى الرأس. إذا لم تكن متأكداً فقل ذلك واطلب التوقف بدلاً من التخمين.
إذا كانت الصورة مظلمة أو مهزوزة أو مغطاة فاطلب التوقف وتعديل الجوال.
أجب دائماً بكائن JSON واحد فقط بدون أي نص قبله أو بعده.
TXT;
    }

    /**
     * @param array{goal?:string,question?:string,history?:string[],memory?:string,labels?:string[]} $ctx
     */
    public static function user(string $mode, array $ctx): string
    {
        return basir_lang() === 'en' ? self::userEn($mode, $ctx) : self::userAr($mode, $ctx);
    }

    private static function userAr(string $mode, array $ctx): string
    {
        $lines = [];
        if (!empty($ctx['labels']) && count($ctx['labels']) > 1) {
            $lines[] = 'الصور معنونة باتجاهها بالنسبة لجسم الشخص: ' . implode('، ', $ctx['labels']) . '.';
        }
        if (!empty($ctx['memory']) && $mode !== 'survey') {
            $lines[] = 'ما نعرفه عن المكان من مسح سابق (الاتجاهات فيه نسبةً لوضع الشخص وقت المسح، وقد يكون تغيّر): ' . $ctx['memory'];
        }
        $json = sprintf(self::JSON_NAV, 'النص المنطوق');

        switch ($mode) {
            case 'navigate':
                if (!empty($ctx['goal'])) {
                    $lines[] = 'هدف الشخص: ' . $ctx['goal'] . '. وجّهه نحوه، وإذا لم يظهر في الصورة فاقترح أن يستدير ليبحث عنه.';
                }
                if (!empty($ctx['history'])) {
                    $lines[] = 'آخر إرشاداتك له: ' . implode(' | ', $ctx['history']);
                }
                $lines[] = 'المهمة: إرشاد التنقل خطوة بخطوة. أعطِ أمراً واحداً واضحاً فيه الاتجاه وعدد الخطوات الآمنة، مثل: «تقدّم 3 خطوات للأمام، الطريق خالٍ.» أو «توقف. كرسي أمامك بعد خطوتين، انعطف يميناً قليلاً.»';
                $lines[] = 'إذا كان الطريق آمناً لـ 3 خطوات أو أكثر فاذكر العدد. جملتان كحد أقصى. لا تكرر وصفاً قلته من قبل إلا إذا تغيّر شيء مهم.';
                break;
            case 'describe':
                $lines[] = 'المهمة: صف ما حول الشخص في 3 جمل كحد أقصى: أهم الأشياء مع اتجاهها ومسافتها بالخطوات، ثم أي خطر، ثم أي نص مكتوب مهم.';
                break;
            case 'ask':
                $lines[] = 'سؤال الشخص: «' . ($ctx['question'] ?? '') . '».';
                $lines[] = 'المهمة: أجب عن السؤال من الصور بإيجاز (جملتان كحد أقصى) مع الاتجاه والمسافة بالخطوات إن لزم. إذا لم يظهر المطلوب فقل ذلك واقترح أن يستدير.';
                break;
            case 'read':
                $lines[] = 'المهمة: اقرأ النص الظاهر في الصورة كما هو، بترتيب القراءة الطبيعي. إذا كان طويلاً فابدأ بالعناوين وأهم المعلومات (أسعار، تواريخ، أرقام، تحذيرات). إذا لم يوجد نص واضح فقل: لا يوجد نص واضح، واقترح تقريب الجوال.';
                break;
            case 'survey':
                $lines[] = 'هذه لقطات التقطها الشخص وهو يستدير حول نفسه في مكانه، وكل صورة معنونة بزاويتها بالنسبة لوضعه الأول (الأمام 0 درجة، اليمين 90، الخلف 180، اليسار 270).';
                $lines[] = 'المطلوب في say: ملخص صوتي من 3 إلى 5 جمل قصيرة: ماذا يوجد في كل اتجاه مع المسافات بالخطوات، وأين الباب أو الممر الآمن، وأفضل اتجاه للمشي، وأي خطر.';
                $lines[] = 'المطلوب في memory: وصف دقيق للمكان لاستخدامه لاحقاً في الإرشاد (نوع المكان، الأشياء ومواقعها واتجاهاتها ومسافاتها، المخارج، الأخطار) في حدود 120 كلمة.';
                $json = sprintf(self::JSON_SURVEY, 'الملخص الصوتي', 'وصف المكان');
                break;
        }
        $lines[] = 'أجب بكائن JSON فقط بهذا الشكل: ' . $json;
        return implode("\n", $lines);
    }

    private static function userEn(string $mode, array $ctx): string
    {
        $lines = [];
        if (!empty($ctx['labels']) && count($ctx['labels']) > 1) {
            $lines[] = 'Each image is labelled with its direction relative to the person\'s body: ' . implode(', ', $ctx['labels']) . '.';
        }
        if (!empty($ctx['memory']) && $mode !== 'survey') {
            $lines[] = 'What we know about this place from an earlier scan (its directions are relative to how the person stood during the scan, and things may have changed): ' . $ctx['memory'];
        }
        $json = sprintf(self::JSON_NAV, 'the sentence to speak');

        switch ($mode) {
            case 'navigate':
                if (!empty($ctx['goal'])) {
                    $lines[] = 'The person\'s goal: ' . $ctx['goal'] . '. Guide them towards it; if it is not visible, suggest turning to look for it.';
                }
                if (!empty($ctx['history'])) {
                    $lines[] = 'Your last instructions: ' . implode(' | ', $ctx['history']);
                }
                $lines[] = 'Task: step-by-step walking guidance. Give one clear instruction with the direction and the number of safe steps, for example: "Walk 3 steps forward, the way is clear." or "Stop. A chair is 2 steps ahead, turn slightly right."';
                $lines[] = 'If the way is safe for 3 steps or more, say how many. At most two sentences. Do not repeat earlier descriptions unless something important changed.';
                break;
            case 'describe':
                $lines[] = 'Task: describe the surroundings in at most 3 sentences: the most important objects with their direction and distance in steps, then any hazard, then any important written text.';
                break;
            case 'ask':
                $lines[] = 'The person asks: "' . ($ctx['question'] ?? '') . '".';
                $lines[] = 'Task: answer from the images briefly (at most two sentences), with direction and distance in steps when useful. If the thing is not visible, say so and suggest turning around.';
                break;
            case 'read':
                $lines[] = 'Task: read the visible text exactly, in natural reading order. If it is long, start with headings and key facts (prices, dates, numbers, warnings). If there is no clear text, say: no clear text, and suggest moving the phone closer.';
                break;
            case 'survey':
                $lines[] = 'These frames were taken while the person turned around on the spot; each is labelled with its angle relative to their starting position (front 0 degrees, right 90, back 180, left 270).';
                $lines[] = 'In "say": a spoken summary of 3 to 5 short sentences: what is in each direction with distances in steps, where the door or a safe path is, the best direction to walk, and any hazard.';
                $lines[] = 'In "memory": a precise description of the place for later guidance (type of place, objects with their directions and distances, exits, hazards) in at most 120 words.';
                $json = sprintf(self::JSON_SURVEY, 'spoken summary', 'place description');
                break;
        }
        $lines[] = 'Answer with a JSON object only, in this shape: ' . $json;
        return implode("\n", $lines);
    }

    public static function maxTokens(string $mode): int
    {
        return match ($mode) {
            'survey', 'read' => 1200,
            default => 500,
        };
    }

    /** يحوّل رد النموذج (JSON أو نص حر) إلى صيغة موحّدة. */
    public static function parse(string $raw, string $mode): array
    {
        $data = self::extractJson($raw);
        if ($data === null) {
            $say = self::clean($raw);
            return [
                'say' => $say !== '' ? $say : tr('لَمْ أَفْهَمِ الصُّورَةَ. حَاوِلْ مَرَّةً أُخْرَى.', 'I could not understand the image. Please try again.'),
                'action' => 'none', 'steps' => 0, 'hazard' => false,
                'memory' => $mode === 'survey' ? $say : '',
            ];
        }

        $action = strtolower((string) ($data['action'] ?? 'none'));
        if (!in_array($action, self::ACTIONS, true)) {
            $action = 'none';
        }
        $steps = is_numeric($data['steps'] ?? null) ? max(0, min(50, (int) round((float) $data['steps']))) : 0;
        $hazard = filter_var($data['hazard'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $say = self::clean((string) ($data['say'] ?? $data['speech'] ?? $data['text'] ?? ''));
        if ($say === '') {
            $say = self::fallbackSay($action, $steps);
        }

        $out = ['say' => $say, 'action' => $action, 'steps' => $steps, 'hazard' => $hazard];
        if ($mode === 'survey') {
            $out['memory'] = self::clean((string) ($data['memory'] ?? $say));
            $best = strtolower((string) ($data['best_direction'] ?? 'none'));
            $out['best_direction'] = in_array($best, ['front', 'right', 'back', 'left'], true) ? $best : 'none';
        }
        return $out;
    }

    private static function extractJson(string $raw): ?array
    {
        $s = trim($raw);
        $s = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $s);
        $d = json_decode($s, true);
        if (is_array($d)) {
            return $d;
        }
        $a = strpos($s, '{');
        $b = strrpos($s, '}');
        if ($a !== false && $b !== false && $b > $a) {
            $d = json_decode(substr($s, $a, $b - $a + 1), true);
            if (is_array($d)) {
                return $d;
            }
        }
        return null;
    }

    private static function clean(string $s): string
    {
        $s = preg_replace('/[*_#`>|]+/u', ' ', $s);
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }

    private static function fallbackSay(string $action, int $steps): string
    {
        if (basir_lang() === 'en') {
            $n = $steps > 0 ? " $steps steps" : '';
            return match ($action) {
                'forward' => 'Walk forward' . $n . '.',
                'slight_left' => 'Bear slightly left' . ($n ? ', then walk' . $n : '') . '.',
                'slight_right' => 'Bear slightly right' . ($n ? ', then walk' . $n : '') . '.',
                'left' => 'Turn left.',
                'right' => 'Turn right.',
                'stop' => 'Stop.',
                'back' => 'Step back' . $n . '.',
                'turn_around' => 'Turn around.',
                'wait' => 'Wait a moment.',
                default => 'Nothing to report.',
            };
        }
        $n = $steps > 0 ? ' ' . self::stepsAr($steps) : '';
        return match ($action) {
            'forward' => 'تَقَدَّمْ لِلْأَمَامِ' . $n . '.',
            'slight_left' => 'اتَّجِهْ يَسَارًا قَلِيلًا' . ($n ? '، ثُمَّ تَقَدَّمْ' . $n : '') . '.',
            'slight_right' => 'اتَّجِهْ يَمِينًا قَلِيلًا' . ($n ? '، ثُمَّ تَقَدَّمْ' . $n : '') . '.',
            'left' => 'انْعَطِفْ يَسَارًا.',
            'right' => 'انْعَطِفْ يَمِينًا.',
            'stop' => 'تَوَقَّفْ.',
            'back' => 'ارْجِعْ لِلْخَلْفِ' . $n . '.',
            'turn_around' => 'اسْتَدِرْ لِلْخَلْفِ.',
            'wait' => 'انْتَظِرْ قَلِيلًا.',
            default => 'لَا يُوجَدُ مَا يُذْكَرُ.',
        };
    }

    /** «3 خطوات» بالكلمات المشكولة للنطق الصحيح. */
    private static function stepsAr(int $n): string
    {
        $words = [1 => 'خُطْوَةً وَاحِدَةً', 2 => 'خُطْوَتَيْنِ', 3 => 'ثَلَاثَ خُطُوَاتٍ', 4 => 'أَرْبَعَ خُطُوَاتٍ', 5 => 'خَمْسَ خُطُوَاتٍ',
            6 => 'سِتَّ خُطُوَاتٍ', 7 => 'سَبْعَ خُطُوَاتٍ', 8 => 'ثَمَانِيَ خُطُوَاتٍ', 9 => 'تِسْعَ خُطُوَاتٍ', 10 => 'عَشْرَ خُطُوَاتٍ'];
        return $words[$n] ?? "$n خُطْوَةً";
    }
}
