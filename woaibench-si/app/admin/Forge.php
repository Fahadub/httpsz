<?php
/**
 * Task Forge: asks an LLM for fresh, original benchmark tasks in the exact import format,
 * validates the drafts and stores approved ones as inactive hold-out tasks.
 */
declare(strict_types=1);

namespace WB\Admin;

defined('WB') || exit;

use DB;
use Config;
use Throwable;

final class Forge
{
    /** Model used for drafting: setting('forge_model') or the first judge model. */
    public static function model(): ?string
    {
        $m = trim((string) \setting('forge_model', ''));
        if ($m !== '') {
            return $m;
        }
        $judge = DB::val('SELECT or_id FROM models WHERE is_judge = 1 ORDER BY judge_order, id LIMIT 1');
        return $judge ? (string) $judge : null;
    }

    /** System prompt: format contract + quality bar for the generator. */
    public static function systemPrompt(): string
    {
        $static = implode(', ', array_keys(TaskValidator::STATIC_TYPES));
        $browser = implode(', ', array_keys(TaskValidator::BROWSER_TYPES));
        $goals = implode(', ', array_keys(TaskValidator::GOAL_TYPES));
        $tools = implode(', ', TaskValidator::AGENT_TOOLS);
        return <<<TXT
You are a senior benchmark designer writing NEW, ORIGINAL evaluation tasks for a rigorous, bilingual (Arabic/English) benchmark of AI coding models.
Each task is given verbatim to the model under test, its output is graded by deterministic checks and (optionally) a blind panel of LLM judges with a weighted rubric.

QUALITY BAR
- Original: never reuse famous exercises (todo apps, FizzBuzz, LeetCode classics, Advent of Code, well-known game clones by name). Invent fresh but realistic scenarios to avoid training-data contamination.
- Self-contained and unambiguous: the prompt states every requirement, input/output format, function signature and constraint needed to succeed. No external files, no API keys, no network unless it is a CDN library explicitly allowed.
- Discriminative: a strong model should succeed, a weak one should visibly fall short. Calibrate to the requested difficulty (1 easy, 2 medium, 3 hard, 4 expert).
- Gradable: every requirement in the prompt maps to a check, a test or a rubric criterion.
- Titles and summaries in natural English AND fluent Modern Standard Arabic (not literal translation). The prompt itself is written in English unless the theme asks for Arabic/RTL content.

OUTPUT FORMAT — reply with ONE JSON object and nothing else: {"tasks":[ TASK, ... ]}
TASK = {
 "slug": "kebab-case-unique-slug (3-60 chars, a-z 0-9 -)",
 "track": "<the requested track slug>",
 "title_en": "...", "title_ar": "...",
 "summary_en": "one sentence, what is being tested", "summary_ar": "...",
 "prompt": "the full instructions shown to the model (for html: ask for ONE complete self-contained HTML file in a single ```html code block; for js: ask for plain JavaScript (no imports, no DOM) defining the exact function/class named in tests.entry, in one ```js block; for answer: ask to finish with <answer>...</answer>; for agent: describe the goal and the available tools)",
 "output_type": "html | js | answer | agent | text",
 "difficulty": 1-4,
 "rubric": [ {"key":"snake_case","label_en":"...","label_ar":"...","weight":1-5,"guide":"what 9-10 vs 5-6 vs 0-2 looks like"} ] (4-6 criteria; use [] for purely deterministic js/answer/agent tasks),
 "checks": {
   "static":  [ {"id":"...","type":"...", ...fields, "weight":1-3, "label_en":"...","label_ar":"..."} ],
   "browser": [ {"id":"...","type":"...", ...fields, "weight":1-3, "label_en":"...","label_ar":"..."} ],
   "tests":   {"entry":"functionName","timeout_ms":2000,"cases":[{"name":"...","args":[...],"expect":...,"cmp":"deep|approx|set|sorted|truthy","tol":1e-6}]},
   "answer":  {"type":"exact|number|regex|set|json|one_of", ...},
   "agent":   {"max_steps":12,"tools":[...],"env":{"files":{},"http":{},"records":{},"calendar":[],"now":"2026-10-19T08:00"},"goal_checks":[...]}
 },
 "auto_weight": null or 0..1 (share of automated checks in the final score; null = track default),
 "visibility": "holdout"
}

ALLOWED CHECK TYPES (use only these)
- static: {$static}. Fields: contains/not_contains {pattern, regex?:bool, ci?:bool}; selector {sel, min?, max?} (simple CSS: tag, #id, .class, [attr], [attr=val], tag.class); max_bytes/min_bytes {value}; no_external {allow:[hosts]}; lang_attr {value?}; regex_count {pattern, min}.
- browser: {$browser}. Fields: selector {sel, min?}; text {contains, ci?}; fps {min}; keyboard {keys:["ArrowLeft",...]}; click {sel?}.
- tests (js only): each case calls entry(...args) and compares with expect, OR uses {"name","script":"const c = new LRU(2); c.put(1,1); return c.get(1);","expect":1}. Provide 6-12 cases including edge cases. COMPUTE EVERY EXPECTED VALUE CAREFULLY by reasoning step by step before writing it; a wrong expectation ruins the task.
- answer (answer only): exact {value, ci?}; number {value, tolerance}; regex {pattern}; set {value:[...], ci?}; json {value}; one_of {values:[...]}. The puzzle must have exactly one correct answer — double-check it.
- agent goal_checks: {$goals}. Agent tools: {$tools}. The env must contain every file/record/URL the goal requires; goals must be verifiable from the final environment state.

RULES
- Every check id is unique within the task; every weight is a positive number.
- html tasks: include at least "loads", "no_errors", "nonblank" and "responsive" browser checks plus 2-5 task-specific checks; static "viewport" and "single_file" are usually appropriate.
- js tasks: checks.tests is mandatory; rubric optional (code quality only).
- answer tasks: checks.answer is mandatory, rubric [].
- agent tasks: checks.agent is mandatory, rubric [], include "finish" in tools.
- Output strictly valid JSON (double quotes, no comments, no trailing commas).
TXT;
    }

    /** User prompt for one forge request. */
    public static function userPrompt(array $track, int $difficulty, string $outputType, int $count, string $theme): string
    {
        $lines = [
            'Create ' . $count . ' new task' . ($count > 1 ? 's' : '') . ' for this track:',
            '- track slug: ' . $track['slug'],
            '- track: ' . $track['name_en'] . ' — ' . trim((string) $track['desc_en']),
            '- difficulty: ' . $difficulty . ' (1 easy · 2 medium · 3 hard · 4 expert)',
            '- output_type: ' . $outputType,
            '- visibility: holdout',
        ];
        if ($theme !== '') {
            $lines[] = '- theme / inspiration: ' . $theme;
        }
        if ($count > 1) {
            $lines[] = 'Make the tasks clearly different from each other (different domains and skills).';
        }
        $lines[] = 'Return only the JSON object {"tasks":[...]}.';
        return implode("\n", $lines);
    }

    /**
     * Ask the model for drafts. Returns ['ok', 'drafts' => [['json' => string, 'errors' => [], 'warnings' => []]], 'error', 'model', 'cost'].
     */
    public static function generate(array $track, int $difficulty, string $outputType, int $count, string $theme): array
    {
        $model = self::model();
        $fail = static fn(string $msg): array => ['ok' => false, 'drafts' => [], 'error' => $msg, 'model' => $model, 'cost' => null];
        if ($model === null) {
            return $fail(\__('admin.forge.err_model'));
        }
        if ((string) Config::get('openrouter_key', '') === '') {
            return $fail(\__('admin.sync.err_key'));
        }
        if (!Admin::engine('OpenRouter')) {
            return $fail(\__('admin.engine_missing', ['c' => 'WB\\Eval\\OpenRouter']));
        }
        \wb_time_limit(300);
        try {
            $res = (new \WB\Eval\OpenRouter())->chat([
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => self::systemPrompt()],
                    ['role' => 'user', 'content' => self::userPrompt($track, $difficulty, $outputType, $count, $theme)],
                ],
                'temperature' => 0.8,
                'max_tokens' => 24000,
                'response_format' => ['type' => 'json_object'],
            ], 240);
        } catch (Throwable $e) {
            \log_error('Forge chat failed', ['error' => $e->getMessage()]);
            return $fail($e->getMessage());
        }
        if (empty($res['ok'])) {
            return $fail((string) ($res['error'] ?? 'OpenRouter error'));
        }
        $data = self::extractJson((string) ($res['content'] ?? ''));
        $tasks = is_array($data) ? ($data['tasks'] ?? (array_is_list($data) ? $data : null)) : null;
        if (!is_array($tasks) || $tasks === []) {
            return $fail(\__('admin.forge.err_parse'));
        }
        $slugs = array_keys(TaskStore::trackIds());
        $drafts = [];
        foreach (array_slice($tasks, 0, 5) as $t) {
            if (!is_array($t)) {
                continue;
            }
            $t['visibility'] = 'holdout';
            $t['track'] = $t['track'] ?? $track['slug'];
            if (isset($t['slug']) && is_string($t['slug']) && DB::val('SELECT id FROM tasks WHERE slug = ?', [$t['slug']])) {
                $t['slug'] = TaskStore::freeSlug($t['slug']);
            }
            $drafts[] = self::draft($t, $slugs);
        }
        return ['ok' => true, 'drafts' => $drafts, 'error' => null, 'model' => $model, 'cost' => $res['usage']['cost'] ?? null];
    }

    /** Validate one draft and keep its pretty JSON for the editor. */
    public static function draft(array $t, array $trackSlugs): array
    {
        $v = TaskValidator::task($t, $trackSlugs);
        return [
            'json' => json_encode($t, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            'errors' => $v['errors'],
            'warnings' => $v['warnings'],
            'title' => (string) ($t['title_en'] ?? $t['slug'] ?? '?'),
        ];
    }

    /** First balanced JSON object/array in a text (handles ```json fences and chatter). */
    public static function extractJson(string $text): ?array
    {
        $text = trim($text);
        $direct = json_decode($text, true);
        if (is_array($direct)) {
            return $direct;
        }
        if (preg_match('/```(?:json)?\s*(.+?)```/s', $text, $m)) {
            $d = json_decode(trim($m[1]), true);
            if (is_array($d)) {
                return $d;
            }
        }
        $start = strpos($text, '{');
        if ($start === false) {
            return null;
        }
        $depth = 0;
        $inStr = false;
        $esc = false;
        $len = strlen($text);
        for ($i = $start; $i < $len; $i++) {
            $ch = $text[$i];
            if ($inStr) {
                if ($esc) {
                    $esc = false;
                } elseif ($ch === '\\') {
                    $esc = true;
                } elseif ($ch === '"') {
                    $inStr = false;
                }
                continue;
            }
            if ($ch === '"') {
                $inStr = true;
            } elseif ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    $d = json_decode(substr($text, $start, $i - $start + 1), true);
                    return is_array($d) ? $d : null;
                }
            }
        }
        return null;
    }

    /** Save a validated draft as an inactive hold-out task (source forge). Returns the id. */
    public static function save(array $normalizedTask): int
    {
        $normalizedTask['visibility'] = 'holdout';
        return TaskStore::insert($normalizedTask, TaskStore::trackIds(), 'forge', false);
    }
}
