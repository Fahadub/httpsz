<?php
/**
 * One generation attempt: builds the request for a run (model × task), calls the model, extracts
 * the artefact and applies the deterministic checks that need no browser (static checks, exact
 * answers, agent goal checks). Infrastructure errors come back as ok=false + retryable; model
 * failures (empty reply, no code) come back as ok=true with code=null so they are scored as zero.
 */
declare(strict_types=1);

namespace WB\Eval;

defined('WB') || exit;

final class Generator
{
    private const RESERVED = ['model', 'messages', 'tools', 'tool_choice', 'functions', 'stream'];

    /**
     * Generate for one run. $run / $task / $model are DB rows (JSON columns as text; $run['gen_params']
     * — e.g. joined from campaigns.gen_params — overrides $model['gen_params'], which overrides defaults).
     */
    public static function run(array $run, array $task, array $model, ?ChatClient $client = null): array
    {
        $client ??= new OpenRouter();
        $type = (string) ($task['output_type'] ?? 'html');
        $checks = isset($task['checks']) && is_array($task['checks']) ? $task['checks'] : json_arr(isset($task['checks_json']) ? (string) $task['checks_json'] : null);
        $out = self::blank();
        if (trim((string) ($model['or_id'] ?? '')) === '') {
            $out['error'] = 'Model has no OpenRouter id';
            return $out;
        }
        $params = self::params($run, $model);
        $timeout = max(10, (int) setting('gen_timeout_s', 240));
        if ($type === 'agent') {
            return self::agent($task, $model, $client, $params + ['_timeout' => $timeout]);
        }

        // "_"-prefixed keys are runner options (e.g. for AgentSim), never API parameters.
        $params = array_filter($params, static fn($k) => !is_string($k) || !str_starts_with($k, '_'), ARRAY_FILTER_USE_KEY);
        $payload = array_merge($params, ['model' => (string) $model['or_id'], 'messages' => Prompts::messages($task)]);
        $res = $client->chat($payload, $timeout);
        $out = self::withUsage($out, $res, $model);
        if (empty($res['ok'])) {
            $out['retryable'] = (bool) ($res['retryable'] ?? true);
            $out['error'] = (string) ($res['error'] ?? 'unknown API error');
            return $out;
        }
        $content = (string) ($res['content'] ?? '');
        $out['raw_output'] = $content;
        if (trim($content) === '') {
            if (($res['finish_reason'] ?? null) === null && (int) ($res['usage']['completion_tokens'] ?? 0) === 0) {
                $out['retryable'] = true;
                $out['error'] = 'Provider returned an empty response';
                return $out;
            }
            // The model answered with nothing (or spent its whole budget on reasoning): a model failure.
            $out['ok'] = true;
            $out['note'] = 'empty completion (finish_reason: ' . ($res['finish_reason'] ?? 'none') . ')';
            $out['static'] = StaticChecks::run('', $type, self::list($checks['static'] ?? []));
            $out['answer'] = $type === 'answer' && is_array($checks['answer'] ?? null) ? AnswerCheck::check('', $checks['answer']) : null;
            return $out;
        }
        $code = Extractor::extract($content, $type);
        $out['ok'] = true;
        $out['code'] = $code;
        $out['code_bytes'] = strlen($code ?? '');
        if ($code === null) {
            $out['note'] = 'no ' . $type . ' could be extracted from the reply';
        } elseif (($res['finish_reason'] ?? null) === 'length') {
            $out['note'] = 'output hit max_tokens (may be truncated)';
        }
        $out['static'] = StaticChecks::run($code ?? '', $type, self::list($checks['static'] ?? []));
        $out['answer'] = $type === 'answer' && is_array($checks['answer'] ?? null) ? AnswerCheck::check($content, $checks['answer']) : null;
        return $out;
    }

    /**
     * Request parameters for a run: max_tokens + temperature from settings, then models.gen_params,
     * then run/campaign gen_params (later wins). Reserved keys (model, messages, tools, stream) are dropped.
     */
    public static function params(array $run, array $model): array
    {
        $p = ['max_tokens' => max(256, (int) setting('gen_max_tokens', 32000))];
        $temp = setting('gen_temperature', '');
        if ($temp !== '' && $temp !== null && is_numeric($temp)) {
            $p['temperature'] = (float) $temp;
        }
        foreach ([$model['gen_params'] ?? null, $run['gen_params'] ?? null] as $gp) {
            $gp = is_array($gp) ? $gp : json_arr(is_string($gp) ? $gp : null);
            if ($gp && !array_is_list($gp)) {
                $p = array_replace($p, $gp);
            }
        }
        foreach (self::RESERVED as $k) {
            unset($p[$k]);
        }
        return $p;
    }

    /* ------------------------------------------------------------------ internals */

    private static function agent(array $task, array $model, ChatClient $client, array $params): array
    {
        $a = AgentSim::run($task, $model, $client, $params);
        $out = self::blank();
        $out['ok'] = $a['ok'];
        $out['retryable'] = $a['retryable'];
        $out['error'] = $a['error'];
        $out['raw_output'] = $a['raw_output'];
        $out['code'] = $a['final_answer'];
        $out['code_bytes'] = strlen($a['final_answer'] ?? '');
        $out['prompt_tokens'] = (int) $a['usage']['prompt_tokens'];
        $out['completion_tokens'] = (int) $a['usage']['completion_tokens'];
        $out['reasoning_tokens'] = (int) $a['usage']['reasoning_tokens'];
        $out['cost_usd'] = round((float) $a['usage']['cost'], 8);
        $out['latency_ms'] = (int) $a['latency_ms'];
        $out['finish_reason'] = $a['end_reason'];
        $out['provider_used'] = $a['provider'] ?? null;
        $out['gen_id'] = $a['gen_id'] ?? null;
        $out['agent'] = $a;
        return $out;
    }

    private static function blank(): array
    {
        return [
            'ok' => false, 'retryable' => false, 'error' => null, 'raw_output' => '', 'code' => null, 'code_bytes' => 0,
            'prompt_tokens' => 0, 'completion_tokens' => 0, 'reasoning_tokens' => 0, 'cost_usd' => 0.0, 'latency_ms' => 0,
            'finish_reason' => null, 'provider_used' => null, 'gen_id' => null, 'static' => [], 'answer' => null, 'agent' => null,
            'note' => null,
        ];
    }

    private static function withUsage(array $out, array $res, array $model): array
    {
        $u = is_array($res['usage'] ?? null) ? $res['usage'] : [];
        $out['prompt_tokens'] = (int) ($u['prompt_tokens'] ?? 0);
        $out['completion_tokens'] = (int) ($u['completion_tokens'] ?? 0);
        $out['reasoning_tokens'] = (int) ($u['reasoning_tokens'] ?? 0);
        $out['cost_usd'] = round(OpenRouter::costFromUsage($u, $model), 8);
        $out['latency_ms'] = (int) ($res['ms'] ?? 0);
        $out['finish_reason'] = isset($res['finish_reason']) ? (string) $res['finish_reason'] : null;
        $out['provider_used'] = isset($res['provider']) ? (string) $res['provider'] : null;
        $out['gen_id'] = isset($res['id']) ? (string) $res['id'] : null;
        return $out;
    }

    private static function list(mixed $v): array
    {
        return is_array($v) ? array_values(array_filter($v, 'is_array')) : [];
    }
}
