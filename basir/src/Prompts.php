<?php
/**
 * بصير — تعليمات النموذج (بالعربية) لكل وضع، وتحليل ردّه إلى صيغة موحّدة.
 */
declare(strict_types=1);

final class Prompts
{
    public const MODES = ['navigate', 'describe', 'ask', 'read', 'survey'];

    public const ACTIONS = ['forward', 'slight_left', 'slight_right', 'left', 'right', 'stop', 'back', 'turn_around', 'wait', 'none'];

    public static function system(float $stepM): string
    {
        $step = rtrim(rtrim(number_format($stepM, 2, '.', ''), '0'), '.');
        return <<<TXT
أنت «بصير»، عيون شخص كفيف. الشخص يحمل جوالاً أمام صدره (ارتفاع الكاميرا تقريباً 1.3 متر)، والكاميرا الخلفية موجّهة للأمام مع ميل بسيط للأسفل.
كلامك سيُقرأ بصوت عالٍ: اكتب بعربية بسيطة وجمل قصيرة جداً، بلا رموز ولا تنسيق ولا إيموجي، واكتب الأعداد بالأرقام.
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
        $lines = [];
        if (!empty($ctx['labels']) && count($ctx['labels']) > 1) {
            $lines[] = 'الصور معنونة باتجاهها بالنسبة لجسم الشخص: ' . implode('، ', $ctx['labels']) . '.';
        }
        if (!empty($ctx['memory']) && $mode !== 'survey') {
            $lines[] = 'ما نعرفه عن المكان من مسح سابق (الاتجاهات فيه نسبةً لوضع الشخص وقت المسح، وقد يكون تغيّر): ' . $ctx['memory'];
        }

        $json = '{"say":"النص الذي سيُنطق","action":"forward|slight_left|slight_right|left|right|stop|back|turn_around|wait|none","steps":0,"hazard":false}';

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
                $json = '{"say":"الملخص الصوتي","memory":"وصف المكان","best_direction":"front|right|back|left|none","hazard":false}';
                break;
        }
        $lines[] = 'أجب بكائن JSON فقط بهذا الشكل: ' . $json;
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
            return ['say' => $say !== '' ? $say : 'لم أفهم الصورة. حاول مرة أخرى.', 'action' => 'none', 'steps' => 0, 'hazard' => false, 'memory' => $mode === 'survey' ? $say : ''];
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
        $n = $steps > 0 ? " $steps خطوات" : '';
        return match ($action) {
            'forward' => 'تقدّم للأمام' . $n . '.',
            'slight_left' => 'اتجه يساراً قليلاً' . ($n ? ' ثم تقدّم' . $n : '') . '.',
            'slight_right' => 'اتجه يميناً قليلاً' . ($n ? ' ثم تقدّم' . $n : '') . '.',
            'left' => 'انعطف يساراً.',
            'right' => 'انعطف يميناً.',
            'stop' => 'توقف.',
            'back' => 'ارجع للخلف' . $n . '.',
            'turn_around' => 'استدر للخلف.',
            'wait' => 'انتظر قليلاً.',
            default => 'لا يوجد ما يُذكر.',
        };
    }
}
