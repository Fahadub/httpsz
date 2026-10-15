<?php
/**
 * Dev-only bridge used by tools/dev/validate-agentic.mjs: replays scripted agent trajectories through
 * the real WB\Eval\AgentSim (with a scripted ChatClient, in native tool-calling and JSON-protocol
 * mode) and runs WB\Eval\StaticChecks on reference outputs. Reads one JSON job from STDIN, writes
 * one JSON result to STDOUT. Never writes to the database and never uses the network (it only reads
 * settings and, for "db_slug" jobs, the seeded task row).
 *
 * STDIN:  {"agent":[{"id","task":{prompt,output_type,checks} | "db_slug":"<slug>","steps":[{"tool","args"}],"native":bool}],
 *          "static":[{"id","code","output_type","checks":[…static checks…]}]}
 * STDOUT: {"agent":{"<id>":{auto_score,steps,end_reason,goals:[…]}},"static":{"<id>":[…]}}
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('WB', true);
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use WB\Eval\AgentSim;
use WB\Eval\ChatClient;
use WB\Eval\StaticChecks;

/** Replays a fixed list of tool calls, one per model turn, then stops calling tools. */
final class ScriptedAgentClient implements ChatClient
{
    private int $i = 0;

    public function __construct(private array $steps, private bool $native)
    {
    }

    public function chat(array $payload, int $timeout = 240): array
    {
        $step = $this->steps[$this->i++] ?? null;
        $base = ['ok' => true, 'status' => 200, 'reasoning' => null, 'finish_reason' => 'stop', 'usage' => [], 'provider' => 'script', 'id' => 'script-' . $this->i, 'ms' => 1, 'error' => null, 'retryable' => false];
        if ($step === null) {
            return $base + ['content' => 'I have nothing more to do.', 'tool_calls' => [], 'message' => []];
        }
        $args = is_array($step['args'] ?? null) ? $step['args'] : [];
        $argJson = json_encode((object) $args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($this->native) {
            $call = ['id' => 'call_' . $this->i, 'type' => 'function', 'function' => ['name' => (string) $step['tool'], 'arguments' => $argJson]];
            return $base + ['content' => '', 'tool_calls' => [$call], 'message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [$call]], 'finish_reason' => 'tool_calls'];
        }
        $text = "Next step.\n```json\n" . json_encode(['tool' => (string) $step['tool'], 'args' => (object) $args], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n```";
        return $base + ['content' => $text, 'tool_calls' => [], 'message' => []];
    }
}

$job = json_decode((string) stream_get_contents(STDIN), true);
if (!is_array($job)) {
    fwrite(STDERR, "validate-agentic.php: expected a JSON job on STDIN\n");
    exit(2);
}

$out = ['agent' => [], 'static' => [], 'php' => PHP_VERSION];
foreach ((array) ($job['agent'] ?? []) as $a) {
    $native = (bool) ($a['native'] ?? true);
    $client = new ScriptedAgentClient((array) ($a['steps'] ?? []), $native);
    $model = ['or_id' => 'script/replay', 'provider' => 'script', 'supports_tools' => $native ? 1 : 0, 'price_in' => 0, 'price_out' => 0];
    $task = (array) ($a['task'] ?? []);
    if (is_string($a['db_slug'] ?? null)) {
        // Replay against the row the Seeder stored (checks_json as canonicalised in the database).
        $task = DB::one('SELECT * FROM tasks WHERE slug = ?', [$a['db_slug']]) ?? [];
        if (!$task) {
            $out['agent'][(string) $a['id']] = ['ok' => false, 'error' => 'task not in database: ' . $a['db_slug'], 'auto_score' => 0, 'steps' => 0, 'end_reason' => null, 'protocol' => null, 'goals' => []];
            continue;
        }
    }
    $r = AgentSim::run($task, $model, $client, ['_retries' => 0, '_retry_sleep_ms' => 0, '_wall_s' => 60]);
    $out['agent'][(string) $a['id']] = [
        'ok' => $r['ok'], 'error' => $r['error'], 'auto_score' => $r['auto_score'], 'steps' => $r['steps'],
        'end_reason' => $r['end_reason'], 'protocol' => $r['protocol'], 'goals' => $r['goal_results'],
    ];
}
foreach ((array) ($job['static'] ?? []) as $s) {
    $out['static'][(string) $s['id']] = StaticChecks::run((string) $s['code'], (string) ($s['output_type'] ?? 'html'), (array) ($s['checks'] ?? []));
}
echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
