<?php
/**
 * Generation prompts. The system prompt fixes the output contract per output_type; the user message
 * is always the task prompt verbatim so every model sees exactly the same task.
 */
declare(strict_types=1);

namespace WB\Eval;

defined('WB') || exit;

final class Prompts
{
    /** CDN hosts generated pages may load libraries from (mirrored by the render sandbox CSP). */
    public const CDN_HOSTS = ['cdnjs.cloudflare.com', 'cdn.jsdelivr.net', 'unpkg.com'];

    /** System prompt for an output type (html | js | answer | agent | text). */
    public static function system(string $outputType, array $task): string
    {
        $checks = self::checks($task);
        return match ($outputType) {
            'html' => self::html(),
            'js' => self::js($checks),
            'answer' => self::answer($checks),
            'agent' => self::agent($checks),
            default => self::text(),
        };
    }

    /** [system, user] messages for a generation request (user = tasks.prompt verbatim). */
    public static function messages(array $task): array
    {
        $type = (string) ($task['output_type'] ?? 'html');
        return [
            ['role' => 'system', 'content' => self::system($type, $task)],
            ['role' => 'user', 'content' => (string) ($task['prompt'] ?? '')],
        ];
    }

    /** Extra system-prompt section for models without native tool calling (JSON text protocol). */
    public static function agentJsonProtocol(array $toolSchemas): string
    {
        $lines = [];
        foreach ($toolSchemas as $t) {
            $fn = $t['function'] ?? $t;
            $lines[] = '- ' . $fn['name'] . ': ' . $fn['description'] . "\n  args schema: "
                . json_encode($fn['parameters'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        return "## Tool protocol\n"
            . "You call tools by writing text. To call a tool, reply with exactly ONE fenced ```json block containing an object "
            . "of the form {\"tool\": \"<tool name>\", \"args\": { ... }} — for example:\n"
            . "```json\n{\"tool\": \"read_file\", \"args\": {\"path\": \"/inbox/a.txt\"}}\n```\n"
            . "Make one tool call per reply. The tool result is returned to you in the next message. "
            . "When the task is complete, call the finish tool the same way.\n\n"
            . "## Available tools\n" . implode("\n", $lines);
    }

    /* ------------------------------------------------------------------ per type */

    private static function html(): string
    {
        $cdns = implode(', ', self::CDN_HOSTS);
        return <<<TXT
You are an expert front-end engineer taking part in a coding benchmark. Build exactly what the user asks for, to a professional, production-ready standard.

Output contract (strict):
- Reply with ONE complete, self-contained HTML document (starting with <!DOCTYPE html>) inside a single fenced ```html code block. Do not output any other code block.
- Put all CSS in <style> and all JavaScript in <script> inside that one file. Do not reference local files (no separate .css, .js or image files); draw graphics with inline SVG, CSS, canvas or data: URIs.
- External libraries are allowed only from {$cdns}, loaded by <script src> or <link href> with pinned versions. No other network access: no fetch/XHR/WebSocket calls, no remote images, fonts or APIs.
- The page is opened directly in a sandboxed modern browser with no server and no setup. It must work immediately, be responsive from a 390 px wide phone to a desktop, and include <meta name="viewport" content="width=device-width, initial-scale=1">.
- No placeholders, TODOs or omitted sections: the file is executed and evaluated exactly as written.
- Keep any explanation outside the code block to a few short sentences.
TXT;
    }

    private static function js(array $checks): string
    {
        $entry = $checks['tests']['entry'] ?? null;
        $entryLine = is_string($entry) && preg_match('/^[A-Za-z_$][\w$]*$/', $entry)
            ? "\n- The entry point is `{$entry}`: define it at the top level with exactly this name."
            : '';
        return <<<TXT
You are an expert JavaScript engineer taking part in a coding benchmark. Solve the user's task with correct, efficient code.

Output contract (strict):
- Reply with ONE fenced ```javascript code block containing the complete solution.
- Define the requested top-level function or class with exactly the requested name and signature.{$entryLine}
- Plain modern JavaScript (ES2022) that runs in a bare JavaScript engine or Web Worker: no import, export or require, no DOM access, no network, no reading of input.
- Helper functions and constants may live in the same block. Do not include tests or example calls that run on load.
- The code is executed against hidden unit tests with a time limit: handle edge cases and keep the complexity reasonable.
- Keep any explanation outside the code block brief.
TXT;
    }

    private static function answer(array $checks): string
    {
        $hint = match ((string) ($checks['answer']['type'] ?? '')) {
            'number' => ' The answer is a single number: write it with digits only (no units, no thousands separators unless asked).',
            'set' => ' The answer is a list: write the items separated by commas.',
            'json' => ' The answer must be valid JSON.',
            default => '',
        };
        return <<<TXT
You are taking part in a reasoning benchmark. Think the problem through carefully; you may reason step by step before answering.

Output contract (strict):
- End your reply with the final answer inside <answer></answer> tags, for example <answer>42</answer>.
- Put only the answer itself inside the tags: no explanation, no units unless the task asks for them.{$hint}
- Write exactly one <answer> block, at the very end of your reply.
TXT;
    }

    private static function agent(array $checks): string
    {
        $agent = is_array($checks['agent'] ?? null) ? $checks['agent'] : [];
        $max = max(1, (int) ($agent['max_steps'] ?? 12));
        $now = (string) ($agent['env']['now'] ?? '');
        $nowLine = $now !== '' ? "\n- The current date and time in the workspace is {$now}." : '';
        return <<<TXT
You are an autonomous agent completing a task by operating tools inside a sandboxed, simulated workspace.

Rules:
- The workspace (files, HTTP endpoints, record tables, an email outbox and a calendar) exists only through the provided tools. Nothing else is reachable.
- File paths are absolute, for example /inbox/notes.txt.
- Inspect before you change things, change only what the task requires, and never take destructive actions the task does not ask for.
- You have at most {$max} tool calls, so work efficiently.{$nowLine}
- When the task is complete, call finish with a short final answer that includes any value the task asks you to report. The episode ends when you call finish.
TXT;
    }

    private static function text(): string
    {
        return 'You are taking part in a benchmark. Respond to the user\'s request accurately, completely and concisely.';
    }

    private static function checks(array $task): array
    {
        if (isset($task['checks']) && is_array($task['checks'])) {
            return $task['checks'];
        }
        return json_arr(isset($task['checks_json']) ? (string) $task['checks_json'] : null);
    }
}
