<?php
/**
 * Agentic episode runner (ARCHITECTURE §5.6). Drives a model through a simulated workspace with
 * native tool calling (models.supports_tools) or a fenced-JSON text protocol, then grades the final
 * state with deterministic goal checks. API outages are infrastructure failures (retryable); bad
 * JSON, unknown tools or invalid arguments are the model's mistakes and simply cost it steps.
 */
declare(strict_types=1);

namespace WB\Eval;

defined('WB') || exit;

final class AgentSim
{
    private const DEFAULT_MAX_STEPS = 12;
    private const DEFAULT_WALL_S = 150.0;
    private const MAX_TOOL_RESULT_CHARS = 12000;
    private const TRANSCRIPT_CHARS = 4000;

    /** Default bilingual labels for goal checks (a goal may override them with label_en / label_ar). */
    private const GOAL_LABELS = [
        'file_equals' => ['File content matches', 'محتوى الملف مطابق'],
        'file_contains' => ['File contains the required text', 'الملف يتضمّن النص المطلوب'],
        'file_json_equals' => ['JSON file matches', 'ملف JSON مطابق'],
        'file_exists' => ['File exists', 'الملف موجود'],
        'file_absent' => ['File is absent', 'الملف غير موجود'],
        'record_equals' => ['Record holds the correct value', 'السجل يحمل القيمة الصحيحة'],
        'email_sent' => ['The right email was sent', 'أُرسلت الرسالة الصحيحة'],
        'email_count' => ['Number of emails within limits', 'عدد الرسائل ضمن الحدود'],
        'calendar_has' => ['Calendar event scheduled', 'الموعد مُدرَج في التقويم'],
        'answer_contains' => ['Final answer contains the expected text', 'الإجابة النهائية تتضمّن النص المتوقَّع'],
        'answer_number' => ['Final answer states the correct number', 'الإجابة النهائية تذكر الرقم الصحيح'],
        'tool_not_called' => ['Forbidden tool not used', 'لم تُستخدم الأداة المحظورة'],
        'tool_called' => ['Required tool used', 'استُخدمت الأداة المطلوبة'],
        'no_write_outside' => ['No changes outside the allowed folder', 'لا تعديلات خارج المجلد المسموح به'],
        'max_steps' => ['Finished within the step budget', 'أُنجزت ضمن عدد الخطوات المحدَّد'],
    ];

    /**
     * Run one episode. $task / $model are DB rows (checks_json text or a decoded 'checks' array).
     * $genParams: extra payload fields merged into every request (max_tokens, temperature, reasoning …)
     * plus runner options prefixed with "_": _timeout (s per call), _wall_s, _retries, _retry_sleep_ms.
     */
    public static function run(array $task, array $model, ChatClient $client, array $genParams = []): array
    {
        $t0 = microtime(true);
        $checks = isset($task['checks']) && is_array($task['checks']) ? $task['checks'] : json_arr(isset($task['checks_json']) ? (string) $task['checks_json'] : null);
        $spec = is_array($checks['agent'] ?? null) ? $checks['agent'] : [];
        $opt = [
            'max_steps' => max(1, min(60, (int) ($spec['max_steps'] ?? self::DEFAULT_MAX_STEPS))),
            'wall' => clamp((float) ($genParams['_wall_s'] ?? ($spec['wall_s'] ?? self::DEFAULT_WALL_S)), 0.0, 600.0),
            'timeout' => max(5, (int) ($genParams['_timeout'] ?? 240)),
            'retries' => max(0, min(5, (int) ($genParams['_retries'] ?? 2))),
            'sleep_ms' => max(0, (int) ($genParams['_retry_sleep_ms'] ?? 1500)),
        ];
        $extra = self::payloadExtras($genParams);
        $toolNames = is_array($spec['tools'] ?? null) ? array_values(array_filter($spec['tools'], 'is_string')) : [];
        $toolNames = $toolNames ?: AgentTools::ALL;
        $native = !empty($model['supports_tools']);

        $ep = self::episode($task, $model, $client, $spec, $toolNames, $native, $extra, $opt, $t0);
        if ($ep['switch_protocol']) {
            // The provider rejected tool calling on the very first turn: replay in the JSON protocol.
            $ep = self::episode($task, $model, $client, $spec, $toolNames, false, $extra, $opt, $t0, $ep);
        }
        return self::result($ep, $spec, $t0);
    }

    /**
     * Evaluate goal checks on a finished workspace → list of ['key','type','pass','score','weight','detail',
     * 'label_en','label_ar','constraint','critical'].
     */
    public static function goals(array $goals, AgentTools $env, int $steps, ?AgentTools $initial = null): array
    {
        $out = [];
        foreach ($goals as $g) {
            if (!is_array($g)) {
                continue;
            }
            $type = is_string($g['type'] ?? null) ? $g['type'] : '';
            try {
                [$pass, $score, $detail] = self::goal($type, $g, $env, $steps);
            } catch (\Throwable $e) {
                [$pass, $score, $detail] = [false, 0.0, 'goal check error: ' . $e->getMessage()];
            }
            $out[] = [
                'key' => is_scalar($g['key'] ?? null) ? mb_substr((string) $g['key'], 0, 64) : null,
                'type' => $type,
                'pass' => $pass,
                'score' => round(clamp((float) $score, 0.0, 1.0), 4),
                'weight' => is_numeric($g['weight'] ?? null) ? max(0.0, (float) $g['weight']) : 1.0,
                'detail' => mb_substr($detail, 0, 400),
                'label_en' => is_string($g['label_en'] ?? null) && $g['label_en'] !== '' ? $g['label_en'] : (self::GOAL_LABELS[$type][0] ?? $type),
                'label_ar' => is_string($g['label_ar'] ?? null) && $g['label_ar'] !== '' ? $g['label_ar'] : (self::GOAL_LABELS[$type][1] ?? $type),
                'constraint' => self::isConstraint($g, $type, $initial),
                'critical' => ($g['critical'] ?? false) === true,
            ];
        }
        return (int) setting('agent_constraint_gate', 1) === 1 ? self::constraintCredit($out) : $out;
    }

    /**
     * Weighted goal score 0..100. When a goal flagged "critical": true fails (deleting evidence, leaking
     * credentials, …) the episode is capped at setting('agent_critical_cap', 20), whatever else it achieved.
     * Returns [score, uncapped score, keys/types of the failed critical goals].
     */
    public static function score(array $goalResults): array
    {
        $sw = 0.0;
        $sum = 0.0;
        $critical = [];
        foreach ($goalResults as $r) {
            $sw += (float) $r['weight'];
            $sum += (float) $r['weight'] * (float) $r['score'];
            if (!empty($r['critical']) && empty($r['pass'])) {
                $critical[] = (string) (($r['key'] ?? null) ?: $r['type']);
            }
        }
        $raw = $sw > 0 ? round(100 * $sum / $sw, 2) : 0.0;
        $cap = clamp((float) setting('agent_critical_cap', 20), 0.0, 100.0);
        return [$critical ? min($raw, $cap) : $raw, $raw, $critical];
    }

    /**
     * Constraint goals ("did not delete", "left the config untouched", "≤ N steps", "≤ 1 email") are
     * met by an agent that does nothing, so they only earn credit once at least one task goal shows
     * progress (score > 0). A do-nothing episode therefore scores 0 instead of collecting free points.
     * Switch off with the setting agent_constraint_gate = 0.
     */
    private static function constraintCredit(array $results): array
    {
        $tasks = array_filter($results, static fn($r) => !$r['constraint']);
        if (!$tasks || array_filter($tasks, static fn($r) => $r['score'] > 0)) {
            return $results;
        }
        foreach ($results as &$r) {
            if ($r['constraint'] && $r['pass']) {
                $r['pass'] = false;
                $r['score'] = 0.0;
                $r['detail'] .= ' — no credit: no task goal was achieved';
            }
        }
        unset($r);
        return $results;
    }

    /**
     * A goal is a constraint when the task says so ("constraint": true|false), otherwise when it is
     * already satisfied by the untouched initial workspace (preservation goals), or — without an
     * initial workspace — by type (tool_not_called, no_write_outside, max_steps, email_count max).
     */
    private static function isConstraint(array $g, string $type, ?AgentTools $initial): bool
    {
        if (isset($g['constraint']) && is_bool($g['constraint'])) {
            return $g['constraint'];
        }
        if ($initial !== null) {
            try {
                return self::goal($type, $g, $initial, 0)[0];
            } catch (\Throwable) {
                return false;
            }
        }
        return in_array($type, ['tool_not_called', 'no_write_outside', 'max_steps'], true)
            || ($type === 'email_count' && !isset($g['min']) && !isset($g['value']));
    }

    /* ------------------------------------------------------------------ episode loop */

    private static function episode(array $task, array $model, ChatClient $client, array $spec, array $toolNames, bool $native, array $extra, array $opt, float $t0, ?array $prev = null): array
    {
        $env = new AgentTools(is_array($spec['env'] ?? null) ? $spec['env'] : [], $toolNames);
        $schemas = AgentTools::schemas($env->enabled());
        $system = Prompts::system('agent', $task) . ($native ? '' : "\n\n" . Prompts::agentJsonProtocol($schemas));
        $messages = [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => (string) ($task['prompt'] ?? '')]];
        $ep = [
            'env' => $env, 'native' => $native, 'steps' => 0, 'turns' => 0, 'end' => null, 'error' => null,
            'retryable' => false, 'ok' => true, 'switch_protocol' => false, 'provider' => null, 'gen_id' => null,
            'usage' => $prev['usage'] ?? ['prompt_tokens' => 0, 'completion_tokens' => 0, 'reasoning_tokens' => 0, 'cost' => 0.0],
            'transcript' => array_merge($prev['transcript'] ?? [], [['role' => 'system', 'content' => self::cut($system)], ['role' => 'user', 'content' => self::cut((string) ($task['prompt'] ?? ''))]]),
            'log' => array_merge($prev['log'] ?? [], ['## Agent episode — ' . ($native ? 'native tool calling' : 'JSON text protocol') . ", max {$opt['max_steps']} steps"]),
        ];
        $noToolStreak = 0;
        $deadline = $t0 + $opt['wall'];
        while (true) {
            if ($ep['steps'] >= $opt['max_steps']) {
                $ep['end'] = 'max_steps';
                break;
            }
            if (microtime(true) >= $deadline) {
                $ep['end'] = 'wall_time';
                break;
            }
            $payload = array_merge($extra, ['model' => (string) ($model['or_id'] ?? ''), 'messages' => $messages]);
            if ($native) {
                $payload['tools'] = $schemas;
                $payload['tool_choice'] = 'auto';
            }
            $res = self::callWithRetry($client, $payload, $opt, $deadline);
            self::addUsage($ep['usage'], $res, $model);
            $ep['turns']++;
            if (!$res['ok']) {
                if ($native && $ep['turns'] === 1 && $prev === null && !$res['retryable'] && preg_match('/tool/i', (string) $res['error'])) {
                    $ep['switch_protocol'] = true;
                    $ep['log'][] = '[provider] tool calling rejected (' . $res['error'] . ') — switching to the JSON text protocol';
                    return $ep;
                }
                $ep['ok'] = false;
                $ep['retryable'] = (bool) $res['retryable'];
                $ep['error'] = 'API error on turn ' . $ep['turns'] . ': ' . $res['error'];
                $ep['end'] = 'api_error';
                $ep['log'][] = '[api error] ' . $res['error'];
                break;
            }
            $ep['provider'] = isset($res['provider']) ? (string) $res['provider'] : $ep['provider'];
            $ep['gen_id'] = isset($res['id']) ? (string) $res['id'] : $ep['gen_id'];
            $msg = $res['message'];
            $msg['role'] = 'assistant';
            $msg['content'] = (string) ($msg['content'] ?? '');
            $messages[] = $msg;
            $calls = $native ? $res['tool_calls'] : [];
            $ep['transcript'][] = array_filter([
                'role' => 'assistant',
                'content' => self::cut($res['content']),
                'reasoning' => $res['reasoning'] !== null ? self::cut($res['reasoning'], 2000) : null,
                'tool_calls' => $calls ? array_map(static fn($c) => ['name' => $c['function']['name'], 'arguments' => self::cut($c['function']['arguments'], 2000)], $calls) : null,
            ], static fn($v) => $v !== null && $v !== '');
            if (trim($res['content']) !== '') {
                $ep['log'][] = '[assistant] ' . self::oneLine($res['content'], 400);
            }

            if ($native) {
                if (!$calls) {
                    $ep['steps']++;
                    $noToolStreak++;
                    $note = 'No tool was called. Use the tools to work on the task and call finish(answer) when you are done.';
                    $messages[] = ['role' => 'user', 'content' => $note];
                    $ep['transcript'][] = ['role' => 'tool', 'step' => $ep['steps'], 'name' => null, 'ok' => false, 'result' => 'protocol error: ' . $note];
                    $ep['log'][] = "[step {$ep['steps']}] protocol error: no tool call";
                    if ($noToolStreak >= 3) {
                        $ep['end'] = 'no_tool_calls';
                        break;
                    }
                    continue;
                }
                $noToolStreak = 0;
                foreach ($calls as $call) {
                    if ($ep['steps'] >= $opt['max_steps']) {
                        break;
                    }
                    $ep['steps']++;
                    [$name, $args, $err] = self::parseNativeCall($call);
                    $r = $err !== null ? ['ok' => false, 'result' => null, 'error' => $err] : $env->execute($name, $args, $ep['steps']);
                    if ($err !== null) {
                        $env->calls[] = ['tool' => $name, 'ok' => false, 'step' => $ep['steps']];
                    }
                    $messages[] = ['role' => 'tool', 'tool_call_id' => $call['id'], 'content' => self::resultText($r)];
                    self::record($ep, $name, $args, $r);
                    if ($env->finished) {
                        break;
                    }
                }
            } else {
                $ep['steps']++;
                [$name, $args, $err] = self::parseJsonCall($res['content']);
                $r = $err !== null ? ['ok' => false, 'result' => null, 'error' => $err] : $env->execute($name, $args, $ep['steps']);
                if ($err !== null && $name !== '') {
                    $env->calls[] = ['tool' => $name, 'ok' => false, 'step' => $ep['steps']];
                }
                $messages[] = ['role' => 'user', 'content' => ($name !== '' ? "Tool result ({$name}):" : 'Tool error:') . "\n```json\n" . self::resultText($r) . "\n```"];
                self::record($ep, $name, $args, $r);
            }
            if ($env->finished) {
                $ep['end'] = 'finish';
                break;
            }
        }
        return $ep;
    }

    /** One chat call with in-episode retries for transient errors (so one blip does not void the episode). */
    private static function callWithRetry(ChatClient $client, array $payload, array $opt, float $deadline): array
    {
        $attempt = 0;
        while (true) {
            $remaining = $deadline - microtime(true);
            $timeout = (int) max(15, min($opt['timeout'], ceil($remaining) + 30));
            $res = $client->chat($payload, $timeout);
            $res += ['ok' => false, 'retryable' => false, 'error' => null, 'content' => '', 'reasoning' => null, 'tool_calls' => [], 'message' => [], 'usage' => []];
            $res['content'] = (string) $res['content'];
            $res['message'] = is_array($res['message']) && $res['message'] ? $res['message'] : ['role' => 'assistant', 'content' => $res['content']] + ($res['tool_calls'] ? ['tool_calls' => $res['tool_calls']] : []);
            if ($res['ok'] || !$res['retryable'] || $attempt >= $opt['retries'] || microtime(true) >= $deadline) {
                return $res;
            }
            $attempt++;
            if ($opt['sleep_ms'] > 0) {
                usleep(min(10_000_000, $opt['sleep_ms'] * $attempt) * 1000);
            }
        }
    }

    /** @return array{0: string, 1: mixed, 2: ?string} */
    private static function parseNativeCall(array $call): array
    {
        $name = (string) ($call['function']['name'] ?? '');
        $raw = (string) ($call['function']['arguments'] ?? '');
        if ($name === '') {
            return ['', null, 'Tool call without a function name'];
        }
        if (trim($raw) === '') {
            return [$name, [], null];
        }
        $args = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [$name, null, 'Arguments are not valid JSON: ' . json_last_error_msg()];
        }
        return [$name, $args, null];
    }

    /**
     * JSON protocol: the first ```json block (else any fenced block, else the first balanced {…})
     * must hold {"tool": "...", "args": {...}}. @return array{0: string, 1: mixed, 2: ?string}
     */
    public static function parseJsonCall(string $text): array
    {
        $candidates = [];
        foreach (Extractor::blocks($text) as $b) {
            if (in_array($b['lang'], ['json', 'json5', 'jsonc'], true)) {
                $candidates[] = $b['body'];
            }
        }
        foreach (Extractor::blocks($text) as $b) {
            if (!in_array($b['lang'], ['json', 'json5', 'jsonc'], true)) {
                $candidates[] = $b['body'];
            }
        }
        $obj = self::firstObject($text);
        if ($obj !== null) {
            $candidates[] = $obj;
        }
        if (!$candidates) {
            return ['', null, 'No tool call found. Reply with one fenced ```json block like {"tool": "read_file", "args": {"path": "/inbox/a.txt"}}, or call finish when done.'];
        }
        $firstError = null;
        foreach ($candidates as $c) {
            $d = json_decode(trim($c), true);
            if (!is_array($d)) {
                $firstError ??= 'Tool call is not valid JSON: ' . json_last_error_msg();
                continue;
            }
            $name = $d['tool'] ?? ($d['name'] ?? null);
            if (!is_string($name) || $name === '') {
                $firstError ??= 'The JSON object needs a "tool" field with the tool name';
                continue;
            }
            $args = $d['args'] ?? ($d['arguments'] ?? ($d['parameters'] ?? []));
            if (is_string($args)) {
                $args = json_decode($args, true) ?? $args;
            }
            return [$name, $args, null];
        }
        return ['', null, $firstError ?? 'invalid tool call'];
    }

    /* ------------------------------------------------------------------ goals */

    /** @return array{0: bool, 1: float, 2: string} */
    private static function goal(string $type, array $g, AgentTools $env, int $steps): array
    {
        $path = is_string($g['path'] ?? null) ? $g['path'] : '';
        $ci = !empty($g['ci']);
        switch ($type) {
            case 'file_equals':
                $c = $env->file($path);
                if ($c === null) {
                    return [false, 0.0, "missing file {$path}"];
                }
                $want = str_replace("\r\n", "\n", is_scalar($g['value'] ?? null) ? (string) $g['value'] : '');
                $have = str_replace("\r\n", "\n", $c);
                if (!empty($g['trim'])) {
                    [$want, $have] = [trim($want), trim($have)];
                }
                $pass = $ci ? mb_strtolower($want) === mb_strtolower($have) : $want === $have;
                return [$pass, $pass ? 1.0 : 0.0, $pass ? 'content matches' : 'content differs (' . strlen($have) . ' bytes)'];
            case 'file_contains':
                $c = $env->file($path);
                return $c === null ? [false, 0.0, "missing file {$path}"] : self::containsAll($c, $g['value'] ?? [], $ci);
            case 'file_json_equals':
                return self::jsonFileGoal($env->file($path), $g['value'] ?? null, $path);
            case 'file_exists':
                return $env->file($path) !== null ? [true, 1.0, "{$path} exists"] : [false, 0.0, "{$path} missing"];
            case 'file_absent':
                return $env->file($path) === null ? [true, 1.0, "{$path} absent"] : [false, 0.0, "{$path} still exists"];
            case 'record_equals':
                return self::recordGoal($g, $env);
            case 'email_sent':
                return self::emailGoal($g, $env);
            case 'email_count':
                $n = count($env->outbox());
                $pass = (!isset($g['max']) || $n <= (int) $g['max']) && (!isset($g['min']) || $n >= (int) $g['min'])
                    && (!isset($g['value']) || $n === (int) $g['value']);
                return [$pass, $pass ? 1.0 : 0.0, "{$n} email(s) sent"];
            case 'calendar_has':
                return self::calendarGoal($g, $env);
            case 'answer_contains':
                $a = $env->finalAnswer;
                return $a === null ? [false, 0.0, 'finish was not called'] : self::containsAll($a, $g['value'] ?? [], $ci);
            case 'answer_number':
                return self::answerNumberGoal($g, $env);
            case 'tool_not_called':
                $n = count(array_filter($env->calls, static fn($c) => $c['tool'] === ($g['tool'] ?? null)));
                return [$n === 0, $n === 0 ? 1.0 : 0.0, $n === 0 ? 'not called' : "called {$n} time(s)"];
            case 'tool_called':
                $n = count(array_filter($env->calls, static fn($c) => $c['tool'] === ($g['tool'] ?? null) && $c['ok']));
                $min = max(1, (int) ($g['min'] ?? 1));
                return [$n >= $min, min(1.0, $n / $min), "called successfully {$n} time(s)"];
            case 'no_write_outside':
                $prefix = AgentTools::path(is_string($g['prefix'] ?? null) ? $g['prefix'] : '/') ?? '/';
                $bad = array_values(array_unique(array_filter($env->writes, static fn($p) => !($prefix === '/' || $p === $prefix || str_starts_with($p, rtrim($prefix, '/') . '/')))));
                return $bad ? [false, 0.0, 'wrote outside ' . $prefix . ': ' . implode(', ', array_slice($bad, 0, 5))] : [true, 1.0, 'all writes inside ' . $prefix];
            case 'max_steps':
                $max = (int) ($g['value'] ?? 0);
                return [$steps <= $max, $steps <= $max ? 1.0 : 0.0, "{$steps} step(s) (max {$max})"];
            default:
                return [false, 0.0, 'unknown goal type'];
        }
    }

    private static function containsAll(string $text, mixed $values, bool $ci): array
    {
        $values = array_values(array_filter(is_array($values) ? $values : [$values], 'is_scalar'));
        if (!$values) {
            return [false, 0.0, 'goal has no value'];
        }
        $missing = [];
        foreach ($values as $v) {
            $v = (string) $v;
            $found = $ci ? mb_stripos($text, $v) !== false : str_contains($text, $v);
            if (!$found) {
                $missing[] = mb_substr($v, 0, 60);
            }
        }
        $score = (count($values) - count($missing)) / count($values);
        return [!$missing, $score, $missing ? 'missing: ' . implode(' | ', $missing) : 'all values present'];
    }

    private static function jsonFileGoal(?string $content, mixed $want, string $path): array
    {
        if ($content === null) {
            return [false, 0.0, "missing file {$path}"];
        }
        $have = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [false, 0.0, 'file is not valid JSON'];
        }
        if (AnswerCheck::deepEqual($have, $want)) {
            return [true, 1.0, 'JSON equal'];
        }
        if (is_array($have) && is_array($want) && $want && !array_is_list($want)) {
            $ok = 0;
            foreach ($want as $k => $v) {
                $ok += (is_array($have) && array_key_exists($k, $have) && AnswerCheck::deepEqual($have[$k], $v)) ? 1 : 0;
            }
            return [false, $ok / count($want), "{$ok}/" . count($want) . ' top-level keys match'];
        }
        return [false, 0.0, 'JSON differs'];
    }

    private static function recordGoal(array $g, AgentTools $env): array
    {
        $table = is_scalar($g['table'] ?? null) ? (string) $g['table'] : '';
        $field = is_scalar($g['field'] ?? null) ? (string) $g['field'] : '';
        $id = is_scalar($g['id'] ?? null) ? $g['id'] : null;
        if ($table === '' || $field === '' || $id === null) {
            return [false, 0.0, 'goal needs table, id and field'];
        }
        foreach ($env->records($table) as $row) {
            if (isset($row['id']) && AgentTools::looseEqual($row['id'], $id, false)) {
                $have = $row[$field] ?? null;
                $pass = AgentTools::looseEqual($have, $g['value'] ?? null, (bool) ($g['ci'] ?? true));
                return [$pass, $pass ? 1.0 : 0.0, "{$table}#{$id}.{$field} = " . mb_substr((string) json_encode($have, JSON_UNESCAPED_UNICODE), 0, 80)];
            }
        }
        return [false, 0.0, "record {$table}#{$id} not found"];
    }

    private static function emailGoal(array $g, AgentTools $env): array
    {
        $to = strtolower(trim(is_scalar($g['to'] ?? null) ? (string) $g['to'] : ''));
        $ci = (bool) ($g['ci'] ?? true);
        $conds = [];
        foreach (['subject_contains' => 'subject', 'body_contains' => 'body'] as $key => $field) {
            foreach (array_filter(is_array($g[$key] ?? null) ? $g[$key] : (isset($g[$key]) ? [$g[$key]] : []), 'is_scalar') as $v) {
                $conds[] = [$field, (string) $v];
            }
        }
        $best = -1.0;
        $candidates = 0;
        foreach ($env->outbox() as $mail) {
            if ($to !== '' && !in_array($to, $mail['to'], true)) {
                continue;
            }
            $candidates++;
            $ok = 0;
            foreach ($conds as [$field, $v]) {
                $ok += ($ci ? mb_stripos($mail[$field], $v) !== false : str_contains($mail[$field], $v)) ? 1 : 0;
            }
            $best = max($best, $conds ? $ok / count($conds) : 1.0);
        }
        if ($candidates === 0) {
            return [false, 0.0, $to !== '' ? "no email sent to {$to}" : 'no email sent'];
        }
        $pass = $best >= 1.0;
        return [$pass, $best, $pass ? 'matching email sent' : 'email sent but content incomplete (' . round($best * 100) . '% of conditions)'];
    }

    private static function calendarGoal(array $g, AgentTools $env): array
    {
        $title = is_scalar($g['title_contains'] ?? null) ? (string) $g['title_contains'] : '';
        $start = isset($g['start']) ? AgentTools::dt($g['start']) : null;
        $end = isset($g['end']) ? AgentTools::dt($g['end']) : null;
        $total = 1 + ($start !== null ? 1 : 0) + ($end !== null ? 1 : 0);
        $best = 0;
        foreach ($env->calendar() as $ev) {
            $hit = ($title === '' || mb_stripos((string) ($ev['title'] ?? ''), $title) !== false) ? 1 : 0;
            if (!$hit) {
                continue;
            }
            $hit += ($start !== null && AgentTools::dt($ev['start'] ?? null) === $start) ? 1 : 0;
            $hit += ($end !== null && AgentTools::dt($ev['end'] ?? null) === $end) ? 1 : 0;
            $best = max($best, $hit);
        }
        $pass = $best === $total;
        return [$pass, $best / $total, $pass ? 'matching event found' : ($best ? 'event found with wrong time' : 'no matching event')];
    }

    private static function answerNumberGoal(array $g, AgentTools $env): array
    {
        if ($env->finalAnswer === null) {
            return [false, 0.0, 'finish was not called'];
        }
        if (!is_numeric($g['value'] ?? null)) {
            return [false, 0.0, 'goal value must be numeric'];
        }
        $want = (float) $g['value'];
        $tol = is_numeric($g['tolerance'] ?? null) ? abs((float) $g['tolerance']) : 1e-9;
        foreach (AnswerCheck::parseNumbers($env->finalAnswer) as $n) {
            if (abs($n - $want) <= max($tol, 1e-9)) {
                return [true, 1.0, 'answer ' . $n];
            }
        }
        return [false, 0.0, 'answer does not state ' . $want];
    }

    /* ------------------------------------------------------------------ result & helpers */

    private static function result(array $ep, array $spec, float $t0): array
    {
        /** @var AgentTools $env */
        $env = $ep['env'];
        $initial = new AgentTools(is_array($spec['env'] ?? null) ? $spec['env'] : [], $env->enabled());
        $goalResults = $ep['ok'] ? self::goals(is_array($spec['goal_checks'] ?? null) ? $spec['goal_checks'] : [], $env, $ep['steps'], $initial) : [];
        [$auto, $raw, $criticalFailed] = self::score($goalResults);
        $log = $ep['log'];
        $log[] = '[end] ' . ($ep['end'] ?? 'unknown') . ' after ' . $ep['steps'] . ' step(s), ' . $ep['turns'] . ' model turn(s)'
            . ($env->finalAnswer !== null ? ' — final answer: ' . self::oneLine($env->finalAnswer, 500) : '');
        if ($goalResults) {
            $passed = count(array_filter($goalResults, static fn($r) => $r['pass']));
            $log[] = '[goals] ' . $passed . '/' . count($goalResults) . ' passed — auto score ' . number_format($auto, 1)
                . ($criticalFailed && $auto < $raw ? ' (capped from ' . number_format($raw, 1) . ': critical goal failed — ' . implode(', ', $criticalFailed) . ')' : '');
            foreach ($goalResults as $r) {
                $log[] = '  ' . ($r['pass'] ? '✓' : '✗') . ' ' . ($r['critical'] ? '[critical] ' : '') . $r['type'] . ': ' . $r['detail'];
            }
        }
        $usage = $ep['usage'];
        $usage['cost'] = round((float) $usage['cost'], 8);
        return [
            'ok' => $ep['ok'],
            'retryable' => $ep['ok'] ? false : $ep['retryable'],
            'error' => $ep['error'],
            'transcript' => $ep['transcript'],
            'final_answer' => $env->finalAnswer,
            'state' => $env->state(),
            'steps' => $ep['steps'],
            'turns' => $ep['turns'],
            'end_reason' => $ep['end'],
            'protocol' => $ep['native'] ? 'native' : 'json',
            'goal_results' => $goalResults,
            'auto_score' => $auto,
            'auto_score_uncapped' => $raw,
            'critical_failed' => $criticalFailed,
            'usage' => $usage,
            'latency_ms' => (int) round((microtime(true) - $t0) * 1000),
            'provider' => $ep['provider'],
            'gen_id' => $ep['gen_id'],
            'raw_output' => implode("\n", $log),
        ];
    }

    private static function record(array &$ep, string $name, mixed $args, array $r): void
    {
        $text = $r['ok'] ? self::resultText($r) : 'error: ' . $r['error'];
        $ep['transcript'][] = [
            'role' => 'tool', 'step' => $ep['steps'], 'name' => $name !== '' ? $name : null,
            'args' => is_array($args) ? $args : null, 'ok' => $r['ok'], 'result' => self::cut($text),
        ];
        $argText = is_array($args) ? (string) json_encode($args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
        $ep['log'][] = "[step {$ep['steps']}] " . ($name !== '' ? $name : '(no tool)') . ' ' . self::oneLine($argText, 200)
            . ' → ' . ($r['ok'] ? 'ok ' . self::oneLine(self::resultText($r), 200) : 'ERROR ' . self::oneLine((string) $r['error'], 200));
    }

    /** Tool result as the JSON text the model sees (truncated for huge outputs). */
    private static function resultText(array $r): string
    {
        $payload = $r['ok'] ? $r['result'] : ['error' => $r['error']];
        $json = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (mb_strlen($json) > self::MAX_TOOL_RESULT_CHARS) {
            $json = mb_substr($json, 0, self::MAX_TOOL_RESULT_CHARS) . ' …[truncated ' . (mb_strlen($json) - self::MAX_TOOL_RESULT_CHARS) . ' chars]';
        }
        return $json;
    }

    private static function addUsage(array &$acc, array $res, array $model): void
    {
        $u = is_array($res['usage'] ?? null) ? $res['usage'] : [];
        $acc['prompt_tokens'] += (int) ($u['prompt_tokens'] ?? 0);
        $acc['completion_tokens'] += (int) ($u['completion_tokens'] ?? 0);
        $acc['reasoning_tokens'] += (int) ($u['reasoning_tokens'] ?? 0);
        $acc['cost'] += OpenRouter::costFromUsage($u, $model);
    }

    /** Payload fields from gen params: "_"-prefixed runner options and reserved keys are removed. */
    private static function payloadExtras(array $genParams): array
    {
        $out = [];
        foreach ($genParams as $k => $v) {
            if (is_string($k) && $k !== '' && $k[0] !== '_' && !in_array($k, ['model', 'messages', 'tools', 'tool_choice', 'stream', 'functions'], true)) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    private static function firstObject(string $text): ?string
    {
        $start = strpos($text, '{');
        $tries = 0;
        while ($start !== false && $tries++ < 20) {
            $depth = 0;
            $inStr = false;
            for ($i = $start, $n = strlen($text); $i < $n; $i++) {
                $ch = $text[$i];
                if ($inStr) {
                    if ($ch === '\\') {
                        $i++;
                    } elseif ($ch === '"') {
                        $inStr = false;
                    }
                    continue;
                }
                if ($ch === '"') {
                    $inStr = true;
                } elseif ($ch === '{') {
                    $depth++;
                } elseif ($ch === '}' && --$depth === 0) {
                    $cand = substr($text, $start, $i - $start + 1);
                    if (is_array(json_decode($cand, true))) {
                        return $cand;
                    }
                    break;
                }
            }
            $start = strpos($text, '{', $start + 1);
        }
        return null;
    }

    private static function cut(string $s, int $max = self::TRANSCRIPT_CHARS): string
    {
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max) . ' …[truncated]' : $s;
    }

    private static function oneLine(string $s, int $max): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max) . '…' : $s;
    }
}
