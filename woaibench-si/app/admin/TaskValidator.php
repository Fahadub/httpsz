<?php
/**
 * Validates task definitions against ARCHITECTURE §5.1 (rubric / checks / tests / answer) and §5.6 (agent).
 * Errors block saving; warnings are advisory. Every message is prefixed with the JSON path it refers to.
 */
declare(strict_types=1);

namespace WB\Admin;

defined('WB') || exit;

final class TaskValidator
{
    public const OUTPUT_TYPES = ['html', 'js', 'answer', 'agent', 'text'];
    public const VISIBILITY = ['public', 'holdout'];

    /** Static check type => required fields. */
    public const STATIC_TYPES = [
        'contains' => ['pattern'], 'not_contains' => ['pattern'], 'selector' => ['sel'], 'max_bytes' => ['value'],
        'min_bytes' => ['value'], 'no_external' => [], 'viewport' => [], 'lang_attr' => [], 'dir_rtl' => [],
        'html_parses' => [], 'single_file' => [], 'regex_count' => ['pattern', 'min'], 'js_parses' => [],
    ];

    /** Browser check type => required fields. */
    public const BROWSER_TYPES = [
        'loads' => [], 'no_errors' => [], 'nonblank' => [], 'selector' => ['sel'], 'text' => ['contains'],
        'animates' => [], 'fps' => ['min'], 'responsive' => [], 'keyboard' => ['keys'], 'click' => [], 'rtl' => [],
        'a11y_basic' => [], 'canvas' => [],
    ];

    public const CMP = ['deep', 'approx', 'set', 'sorted', 'truthy'];

    /** Answer type => required fields. */
    public const ANSWER_TYPES = [
        'exact' => ['value'], 'number' => ['value'], 'regex' => ['pattern'], 'set' => ['value'], 'json' => ['value'], 'one_of' => ['values'],
    ];

    public const AGENT_TOOLS = [
        'list_dir', 'read_file', 'write_file', 'append_file', 'delete_file', 'move_file', 'search_files', 'http_get',
        'query_records', 'update_record', 'send_email', 'calendar_list', 'calendar_add', 'calc', 'finish',
    ];

    /** Agent goal check type => required fields. */
    public const GOAL_TYPES = [
        'file_equals' => ['path', 'value'], 'file_contains' => ['path', 'value'], 'file_json_equals' => ['path', 'value'],
        'file_exists' => ['path'], 'file_absent' => ['path'], 'record_equals' => ['table', 'id', 'field', 'value'],
        'email_sent' => ['to'], 'email_count' => [], 'calendar_has' => [], 'answer_contains' => ['value'],
        'answer_number' => ['value'], 'tool_not_called' => ['tool'], 'no_write_outside' => ['prefix'], 'max_steps' => ['value'],
    ];

    private array $errors = [];
    private array $warnings = [];

    /** Spec shared with the client-side validator (admin.js). */
    public static function spec(): array
    {
        return [
            'output_types' => self::OUTPUT_TYPES,
            'static' => self::STATIC_TYPES,
            'browser' => self::BROWSER_TYPES,
            'cmp' => self::CMP,
            'answer' => self::ANSWER_TYPES,
            'agent_tools' => self::AGENT_TOOLS,
            'goals' => self::GOAL_TYPES,
        ];
    }

    /**
     * Validate a complete task in import format (rubric/checks may be arrays or JSON strings).
     * $trackSlugs: known track slugs (pass null to skip). Returns ['errors' => [...], 'warnings' => [...], 'task' => normalised].
     */
    public static function task(array $t, ?array $trackSlugs = null): array
    {
        $v = new self();
        $norm = $v->checkTask($t, $trackSlugs);
        return ['errors' => $v->errors, 'warnings' => $v->warnings, 'task' => $norm];
    }

    /** Validate only rubric + checks (used by the editor's JSON fields). */
    public static function definition(mixed $rubric, mixed $checks, string $outputType): array
    {
        $v = new self();
        $r = $v->decodeField($rubric, 'rubric');
        $c = $v->decodeField($checks, 'checks');
        if (!$v->errors) {
            $v->checkRubric($r ?? []);
            $v->checkChecks($c ?? [], $outputType, is_array($r) && $r !== []);
            $v->checkUnjudgedBrowserTask($outputType, $c, $r, null);
        }
        return ['errors' => $v->errors, 'warnings' => $v->warnings];
    }

    /* ------------------------------------------------------------------ internals */

    private function err(string $path, string $key, array $rep = []): void
    {
        $this->errors[] = ($path !== '' ? $path . ': ' : '') . \__('admin.v.' . $key, $rep);
    }

    private function warn(string $path, string $key, array $rep = []): void
    {
        $this->warnings[] = ($path !== '' ? $path . ': ' : '') . \__('admin.v.' . $key, $rep);
    }

    /** Accept arrays, JSON text, or empty; report JSON syntax errors precisely. */
    private function decodeField(mixed $value, string $path): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value)) {
            $this->err($path, 'bad_type', ['type' => 'JSON']);
            return null;
        }
        try {
            return json_decode($value, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->err($path, 'json_syntax', ['msg' => $e->getMessage()]);
            return null;
        }
    }

    private function checkTask(array $t, ?array $trackSlugs): array
    {
        $slug = trim((string) ($t['slug'] ?? ''));
        if ($slug === '') {
            $this->err('slug', 'required');
        } elseif (!preg_match('/^[a-z0-9][a-z0-9\-]{1,118}[a-z0-9]$/', $slug)) {
            $this->err('slug', 'slug_format');
        }
        $track = trim((string) ($t['track'] ?? ''));
        if ($track === '') {
            $this->err('track', 'required');
        } elseif ($trackSlugs !== null && !in_array($track, $trackSlugs, true)) {
            $this->err('track', 'unknown_track', ['v' => $track]);
        }
        foreach (['title_en', 'title_ar'] as $f) {
            $val = trim((string) ($t[$f] ?? ''));
            if ($val === '') {
                $this->err($f, 'required');
            } elseif (mb_strlen($val) > 200) {
                $this->err($f, 'too_long', ['n' => 200]);
            }
        }
        foreach (['summary_en', 'summary_ar'] as $f) {
            if (trim((string) ($t[$f] ?? '')) === '') {
                $this->warn($f, 'recommended');
            }
        }
        $prompt = (string) ($t['prompt'] ?? '');
        if (trim($prompt) === '') {
            $this->err('prompt', 'required');
        } elseif (mb_strlen($prompt) < 40) {
            $this->warn('prompt', 'prompt_short');
        } elseif (strlen($prompt) > 200000) {
            $this->err('prompt', 'too_long', ['n' => 200000]);
        }
        $type = (string) ($t['output_type'] ?? '');
        if (!in_array($type, self::OUTPUT_TYPES, true)) {
            $this->err('output_type', 'one_of', ['list' => implode(', ', self::OUTPUT_TYPES)]);
        }
        $diff = $t['difficulty'] ?? 2;
        if (!is_numeric($diff) || (int) $diff != $diff || (int) $diff < 1 || (int) $diff > 4) {
            $this->err('difficulty', 'range', ['min' => 1, 'max' => 4]);
        }
        $vis = (string) ($t['visibility'] ?? 'public');
        if (!in_array($vis, self::VISIBILITY, true)) {
            $this->err('visibility', 'one_of', ['list' => 'public, holdout']);
        }
        $aw = $t['auto_weight'] ?? null;
        if ($aw !== null && $aw !== '' && (!is_numeric($aw) || (float) $aw < 0 || (float) $aw > 1)) {
            $this->err('auto_weight', 'range', ['min' => 0, 'max' => 1]);
        }
        $rubric = $this->decodeField($t['rubric'] ?? ($t['rubric_json'] ?? null), 'rubric');
        $checks = $this->decodeField($t['checks'] ?? ($t['checks_json'] ?? null), 'checks');
        if ($rubric !== null) {
            $this->checkRubric($rubric);
        }
        $this->checkChecks($checks ?? [], in_array($type, self::OUTPUT_TYPES, true) ? $type : 'html', is_array($rubric) && $rubric !== []);
        $this->checkUnjudgedBrowserTask($type, $checks, $rubric, $aw);
        return [
            'slug' => $slug,
            'track' => $track,
            'title_en' => trim((string) ($t['title_en'] ?? '')),
            'title_ar' => trim((string) ($t['title_ar'] ?? '')),
            'summary_en' => trim((string) ($t['summary_en'] ?? '')),
            'summary_ar' => trim((string) ($t['summary_ar'] ?? '')),
            'prompt' => $prompt,
            'output_type' => $type,
            'difficulty' => is_numeric($diff) ? (int) $diff : 2,
            'rubric' => is_array($rubric) ? $rubric : [],
            'checks' => is_array($checks) ? $checks : [],
            'auto_weight' => ($aw === null || $aw === '') ? null : (float) $aw,
            'visibility' => $vis,
        ];
    }

    private function checkRubric(mixed $rubric): void
    {
        if (!is_array($rubric) || !array_is_list($rubric)) {
            $this->err('rubric', 'must_be_list');
            return;
        }
        if ($rubric !== [] && (count($rubric) < 2 || count($rubric) > 8)) {
            $this->warn('rubric', 'rubric_count');
        }
        $keys = [];
        foreach ($rubric as $i => $c) {
            $p = "rubric[$i]";
            if (!is_array($c) || array_is_list($c)) {
                $this->err($p, 'must_be_object');
                continue;
            }
            $key = $c['key'] ?? null;
            if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key)) {
                $this->err($p . '.key', 'key_format');
            } elseif (isset($keys[$key])) {
                $this->err($p . '.key', 'duplicate', ['v' => $key]);
            } else {
                $keys[$key] = true;
            }
            foreach (['label_en', 'label_ar'] as $f) {
                if (!is_string($c[$f] ?? null) || trim($c[$f]) === '') {
                    $this->err($p . '.' . $f, 'required');
                }
            }
            $this->positiveWeight($c, $p, true);
            if (!is_string($c['guide'] ?? null) || trim($c['guide']) === '') {
                $this->warn($p . '.guide', 'guide_recommended');
            }
        }
    }

    private function positiveWeight(array $item, string $path, bool $required): void
    {
        if (!array_key_exists('weight', $item)) {
            if ($required) {
                $this->err($path . '.weight', 'required');
            }
            return;
        }
        $w = $item['weight'];
        if (!is_int($w) && !is_float($w)) {
            $this->err($path . '.weight', 'number');
        } elseif ($w <= 0) {
            $this->err($path . '.weight', 'weight_positive');
        }
    }

    private function checkChecks(mixed $checks, string $type, bool $hasRubric): void
    {
        if ($checks === [] || $checks === null) {
            $checks = [];
        } elseif (!is_array($checks) || array_is_list($checks)) {
            $this->err('checks', 'must_be_object');
            return;
        }
        $known = ['static', 'browser', 'tests', 'answer', 'agent'];
        foreach (array_keys($checks) as $k) {
            if (!in_array($k, $known, true)) {
                $this->err('checks.' . $k, 'unknown_key', ['list' => implode(', ', $known)]);
            }
        }
        $ids = [];
        if (isset($checks['static'])) {
            $this->checkList($checks['static'], 'checks.static', self::STATIC_TYPES, $ids);
        }
        if (isset($checks['browser'])) {
            $this->checkList($checks['browser'], 'checks.browser', self::BROWSER_TYPES, $ids);
        }
        if (isset($checks['tests'])) {
            $this->checkTests($checks['tests']);
        }
        if (isset($checks['answer'])) {
            $this->checkAnswer($checks['answer']);
        }
        if (isset($checks['agent'])) {
            $this->checkAgent($checks['agent']);
        }
        // Consistency with the output type.
        $hasAuto = !empty($checks['static']) || !empty($checks['browser']) || !empty($checks['tests']);
        if ($type === 'answer' && empty($checks['answer'])) {
            $this->err('checks.answer', 'needed_for', ['type' => 'answer']);
        }
        if ($type === 'agent' && empty($checks['agent'])) {
            $this->err('checks.agent', 'needed_for', ['type' => 'agent']);
        }
        if ($type === 'js' && empty($checks['tests'])) {
            $this->err('checks.tests', 'needed_for', ['type' => 'js']);
        }
        if (($type === 'html' || $type === 'text') && !$hasAuto && !$hasRubric) {
            $this->err('checks', 'nothing_to_score');
        }
        if ($type === 'html' && empty($checks['browser'])) {
            $this->warn('checks.browser', 'browser_recommended');
        }
        if (!empty($checks['answer']) && $type !== 'answer') {
            $this->warn('checks.answer', 'ignored_for', ['type' => $type]);
        }
        if (!empty($checks['agent']) && $type !== 'agent') {
            $this->warn('checks.agent', 'ignored_for', ['type' => $type]);
        }
        if (!empty($checks['tests']) && $type !== 'js') {
            $this->warn('checks.tests', 'ignored_for', ['type' => $type]);
        }
    }

    /**
     * An html/js task whose automated score rests on browser checks / tests and that has no judges (empty rubric or
     * auto_weight = 1) can only be scored fairly when it is verified: warn unless verification is 'required'.
     */
    private function checkUnjudgedBrowserTask(string $type, mixed $checks, mixed $rubric, mixed $autoWeight): void
    {
        $mode = (string) \setting('verify_mode', 'required');
        if ($mode === 'required' || !in_array($type, ['html', 'js'], true) || !is_array($checks)
            || (empty($checks['browser']) && empty($checks['tests']))) {
            return;
        }
        $judged = is_array($rubric) && $rubric !== [] && !(is_numeric($autoWeight) && (float) $autoWeight >= 1.0);
        if (!$judged) {
            $this->warn('checks', 'unjudged_unverified', ['mode' => $mode]);
        }
    }

    /** Static / browser check lists. */
    private function checkList(mixed $list, string $path, array $types, array &$ids): void
    {
        if (!is_array($list) || !array_is_list($list)) {
            $this->err($path, 'must_be_list');
            return;
        }
        foreach ($list as $i => $c) {
            $p = $path . "[$i]";
            if (!is_array($c) || array_is_list($c)) {
                $this->err($p, 'must_be_object');
                continue;
            }
            $id = $c['id'] ?? null;
            if (!is_string($id) || !preg_match('/^[A-Za-z0-9_\-]{1,40}$/', $id)) {
                $this->err($p . '.id', 'id_format');
            } elseif (isset($ids[$id])) {
                $this->err($p . '.id', 'duplicate', ['v' => $id]);
            } else {
                $ids[$id] = true;
            }
            $type = $c['type'] ?? null;
            if (!is_string($type) || !isset($types[$type])) {
                $this->err($p . '.type', 'unknown_type', ['v' => is_string($type) ? $type : '?', 'list' => implode(', ', array_keys($types))]);
                continue;
            }
            foreach ($types[$type] as $f) {
                if (!array_key_exists($f, $c) || $c[$f] === '' || $c[$f] === null) {
                    $this->err($p . '.' . $f, 'required_for', ['type' => $type]);
                }
            }
            $this->positiveWeight($c, $p, false);
            if (!isset($c['label_en']) || !isset($c['label_ar'])) {
                $this->warn($p, 'labels_recommended');
            }
            $this->checkCheckFields($c, $p, $type);
        }
    }

    private function checkCheckFields(array $c, string $p, string $type): void
    {
        $isRegex = $type === 'regex_count' || (in_array($type, ['contains', 'not_contains'], true) && !empty($c['regex']));
        if ($isRegex && is_string($c['pattern'] ?? null) && !self::regexOk($c['pattern'])) {
            $this->err($p . '.pattern', 'bad_regex');
        }
        if (isset($c['pattern']) && !is_string($c['pattern'])) {
            $this->err($p . '.pattern', 'bad_type', ['type' => 'string']);
        }
        foreach (['value' => ['max_bytes', 'min_bytes'], 'min' => ['regex_count', 'fps', 'selector'], 'max' => ['selector']] as $f => $forTypes) {
            if (in_array($type, $forTypes, true) && array_key_exists($f, $c) && (!is_numeric($c[$f]) || $c[$f] < 0)) {
                $this->err($p . '.' . $f, 'number');
            }
        }
        if ($type === 'keyboard' && isset($c['keys']) && (!is_array($c['keys']) || $c['keys'] === [] || !array_is_list($c['keys']))) {
            $this->err($p . '.keys', 'must_be_list');
        }
        if ($type === 'no_external' && isset($c['allow']) && (!is_array($c['allow']) || !array_is_list($c['allow']))) {
            $this->err($p . '.allow', 'must_be_list');
        }
        if (isset($c['sel']) && (!is_string($c['sel']) || trim($c['sel']) === '')) {
            $this->err($p . '.sel', 'bad_type', ['type' => 'string']);
        }
    }

    private function checkTests(mixed $tests): void
    {
        $p = 'checks.tests';
        if (!is_array($tests) || array_is_list($tests)) {
            $this->err($p, 'must_be_object');
            return;
        }
        $cases = $tests['cases'] ?? null;
        if (!is_array($cases) || !array_is_list($cases) || $cases === []) {
            $this->err($p . '.cases', 'nonempty_list');
            return;
        }
        if (isset($tests['timeout_ms']) && (!is_int($tests['timeout_ms']) || $tests['timeout_ms'] < 50 || $tests['timeout_ms'] > 60000)) {
            $this->err($p . '.timeout_ms', 'range', ['min' => 50, 'max' => 60000]);
        }
        $this->positiveWeight($tests, $p, false);
        $needsEntry = false;
        $names = [];
        foreach ($cases as $i => $c) {
            $cp = $p . ".cases[$i]";
            if (!is_array($c) || array_is_list($c)) {
                $this->err($cp, 'must_be_object');
                continue;
            }
            $name = $c['name'] ?? null;
            if (!is_string($name) || trim($name) === '') {
                $this->err($cp . '.name', 'required');
            } elseif (isset($names[$name])) {
                $this->err($cp . '.name', 'duplicate', ['v' => $name]); // results are matched to cases by name
            } else {
                $names[$name] = true;
            }
            $hasArgs = array_key_exists('args', $c);
            $hasScript = array_key_exists('script', $c);
            if ($hasArgs === $hasScript) {
                $this->err($cp, 'args_or_script');
            } elseif ($hasArgs) {
                $needsEntry = true;
                if (!is_array($c['args']) || !array_is_list($c['args'])) {
                    $this->err($cp . '.args', 'must_be_list');
                }
            } elseif (!is_string($c['script']) || trim($c['script']) === '') {
                $this->err($cp . '.script', 'required');
            }
            $cmp = $c['cmp'] ?? 'deep';
            if (!in_array($cmp, self::CMP, true)) {
                $this->err($cp . '.cmp', 'one_of', ['list' => implode(', ', self::CMP)]);
            }
            if ($cmp !== 'truthy' && !array_key_exists('expect', $c)) {
                $this->err($cp . '.expect', 'required');
            }
            if (isset($c['tol']) && (!is_numeric($c['tol']) || $c['tol'] < 0)) {
                $this->err($cp . '.tol', 'number');
            }
        }
        if ($needsEntry) {
            $entry = $tests['entry'] ?? null;
            if (!is_string($entry) || !preg_match('/^[A-Za-z_$][\w$]*(\.[A-Za-z_$][\w$]*)*$/', $entry)) {
                $this->err($p . '.entry', 'entry_format');
            }
        }
        if (count($cases) < 3) {
            $this->warn($p . '.cases', 'few_cases');
        }
    }

    private function checkAnswer(mixed $a): void
    {
        $p = 'checks.answer';
        if (!is_array($a) || array_is_list($a)) {
            $this->err($p, 'must_be_object');
            return;
        }
        $type = $a['type'] ?? null;
        if (!is_string($type) || !isset(self::ANSWER_TYPES[$type])) {
            $this->err($p . '.type', 'unknown_type', ['v' => is_string($type) ? $type : '?', 'list' => implode(', ', array_keys(self::ANSWER_TYPES))]);
            return;
        }
        foreach (self::ANSWER_TYPES[$type] as $f) {
            if (!array_key_exists($f, $a)) {
                $this->err($p . '.' . $f, 'required_for', ['type' => $type]);
            }
        }
        if ($type === 'number') {
            if (array_key_exists('value', $a) && !is_numeric($a['value'])) {
                $this->err($p . '.value', 'number');
            }
            if (isset($a['tolerance']) && (!is_numeric($a['tolerance']) || $a['tolerance'] < 0)) {
                $this->err($p . '.tolerance', 'number');
            }
        }
        if ($type === 'regex' && is_string($a['pattern'] ?? null) && !self::regexOk($a['pattern'])) {
            $this->err($p . '.pattern', 'bad_regex');
        }
        if ($type === 'set' && array_key_exists('value', $a) && (!is_array($a['value']) || !array_is_list($a['value']) || $a['value'] === [])) {
            $this->err($p . '.value', 'nonempty_list');
        }
        if ($type === 'one_of' && array_key_exists('values', $a) && (!is_array($a['values']) || !array_is_list($a['values']) || $a['values'] === [])) {
            $this->err($p . '.values', 'nonempty_list');
        }
        if ($type === 'exact' && array_key_exists('value', $a) && !is_scalar($a['value'])) {
            $this->err($p . '.value', 'bad_type', ['type' => 'string']);
        }
    }

    private function checkAgent(mixed $ag): void
    {
        $p = 'checks.agent';
        if (!is_array($ag) || array_is_list($ag)) {
            $this->err($p, 'must_be_object');
            return;
        }
        if (isset($ag['max_steps']) && (!is_int($ag['max_steps']) || $ag['max_steps'] < 1 || $ag['max_steps'] > 50)) {
            $this->err($p . '.max_steps', 'range', ['min' => 1, 'max' => 50]);
        }
        $tools = $ag['tools'] ?? null;
        if (!is_array($tools) || !array_is_list($tools) || $tools === []) {
            $this->err($p . '.tools', 'nonempty_list');
        } else {
            foreach ($tools as $i => $tool) {
                if (!is_string($tool) || !in_array($tool, self::AGENT_TOOLS, true)) {
                    $this->err($p . ".tools[$i]", 'unknown_type', ['v' => is_string($tool) ? $tool : '?', 'list' => implode(', ', self::AGENT_TOOLS)]);
                }
            }
            if (!in_array('finish', $tools, true)) {
                $this->warn($p . '.tools', 'finish_missing');
            }
        }
        $env = $ag['env'] ?? [];
        if (!is_array($env) || ($env !== [] && array_is_list($env))) {
            $this->err($p . '.env', 'must_be_object');
        } else {
            $this->checkAgentEnv($env, $p . '.env');
        }
        $goals = $ag['goal_checks'] ?? null;
        if (!is_array($goals) || !array_is_list($goals) || $goals === []) {
            $this->err($p . '.goal_checks', 'nonempty_list');
            return;
        }
        foreach ($goals as $i => $g) {
            $gp = $p . ".goal_checks[$i]";
            if (!is_array($g) || array_is_list($g)) {
                $this->err($gp, 'must_be_object');
                continue;
            }
            $type = $g['type'] ?? null;
            if (!is_string($type) || !isset(self::GOAL_TYPES[$type])) {
                $this->err($gp . '.type', 'unknown_type', ['v' => is_string($type) ? $type : '?', 'list' => implode(', ', array_keys(self::GOAL_TYPES))]);
                continue;
            }
            foreach (self::GOAL_TYPES[$type] as $f) {
                if (!array_key_exists($f, $g)) {
                    $this->err($gp . '.' . $f, 'required_for', ['type' => $type]);
                }
            }
            $this->positiveWeight($g, $gp, false);
            if ($type === 'calendar_has' && !isset($g['title_contains']) && !isset($g['start'])) {
                $this->err($gp, 'calendar_has_fields');
            }
            if ($type === 'email_count' && !isset($g['max']) && !isset($g['min'])) {
                $this->err($gp, 'email_count_fields');
            }
            if ($type === 'tool_not_called' && is_string($g['tool'] ?? null) && !in_array($g['tool'], self::AGENT_TOOLS, true)) {
                $this->err($gp . '.tool', 'unknown_type', ['v' => $g['tool'], 'list' => implode(', ', self::AGENT_TOOLS)]);
            }
            if (in_array($type, ['answer_number', 'max_steps'], true) && array_key_exists('value', $g) && !is_numeric($g['value'])) {
                $this->err($gp . '.value', 'number');
            }
            if (isset($g['path']) && (!is_string($g['path']) || !str_starts_with($g['path'], '/'))) {
                $this->err($gp . '.path', 'abs_path');
            }
        }
    }

    private function checkAgentEnv(array $env, string $p): void
    {
        if (isset($env['files'])) {
            if (!is_array($env['files']) || ($env['files'] !== [] && array_is_list($env['files']))) {
                $this->err($p . '.files', 'must_be_object');
            } else {
                foreach ($env['files'] as $path => $content) {
                    if (!str_starts_with((string) $path, '/')) {
                        $this->err($p . '.files["' . $path . '"]', 'abs_path');
                    }
                    if (!is_string($content)) {
                        $this->err($p . '.files["' . $path . '"]', 'bad_type', ['type' => 'string']);
                    }
                }
            }
        }
        if (isset($env['http'])) {
            if (!is_array($env['http']) || ($env['http'] !== [] && array_is_list($env['http']))) {
                $this->err($p . '.http', 'must_be_object');
            } else {
                foreach ($env['http'] as $u => $resp) {
                    if (!preg_match('#^https?://#', (string) $u)) {
                        $this->err($p . '.http["' . $u . '"]', 'url_format');
                    }
                    if (!is_array($resp) || !isset($resp['body'])) {
                        $this->err($p . '.http["' . $u . '"].body', 'required');
                    } elseif (isset($resp['status']) && !is_int($resp['status'])) {
                        $this->err($p . '.http["' . $u . '"].status', 'number');
                    }
                }
            }
        }
        if (isset($env['records'])) {
            if (!is_array($env['records']) || ($env['records'] !== [] && array_is_list($env['records']))) {
                $this->err($p . '.records', 'must_be_object');
            } else {
                foreach ($env['records'] as $table => $rows) {
                    if (!is_array($rows) || !array_is_list($rows)) {
                        $this->err($p . '.records.' . $table, 'must_be_list');
                        continue;
                    }
                    foreach ($rows as $i => $row) {
                        if (!is_array($row) || !array_key_exists('id', $row)) {
                            $this->err($p . '.records.' . $table . "[$i].id", 'required');
                        }
                    }
                }
            }
        }
        if (isset($env['calendar']) && (!is_array($env['calendar']) || !array_is_list($env['calendar']))) {
            $this->err($p . '.calendar', 'must_be_list');
        }
        if (isset($env['now']) && (!is_string($env['now']) || strtotime($env['now']) === false)) {
            $this->err($p . '.now', 'date_format');
        }
    }

    /** True when the pattern compiles (accepts bare patterns; delimiter chosen like the engine). */
    public static function regexOk(string $pattern): bool
    {
        set_error_handler(static fn() => true);
        try {
            $ok = preg_match('~' . str_replace('~', '\~', $pattern) . '~u', '') !== false;
        } finally {
            restore_error_handler();
        }
        return $ok;
    }
}
