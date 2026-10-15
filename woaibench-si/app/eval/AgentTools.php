<?php
/**
 * The simulated workspace of an agentic episode (ARCHITECTURE §5.6): an in-memory file system,
 * canned HTTP responses, record tables, an email outbox and a calendar, plus the tool
 * implementations operating on them. Purely in-memory: nothing touches the real disk or network.
 */
declare(strict_types=1);

namespace WB\Eval;

defined('WB') || exit;

final class AgentTools
{
    public const ALL = [
        'list_dir', 'read_file', 'write_file', 'append_file', 'delete_file', 'move_file', 'search_files',
        'http_get', 'query_records', 'update_record', 'send_email', 'calendar_list', 'calendar_add', 'calc', 'finish',
    ];
    private const MAX_READ_CHARS = 16000;
    private const MAX_FILE_BYTES = 1024 * 1024;
    private const MAX_ROWS = 200;
    private const MAX_WORKSPACE_BYTES = 4 * 1024 * 1024;

    /** @var array<string,string> */
    private array $files = [];
    /** @var array<string,bool> */
    private array $dirs = ['/' => true];
    private array $http = [];
    private array $records = [];
    private array $calendar = [];
    private array $outbox = [];
    private string $now;
    private array $enabled;

    /** Every attempted tool call: ['tool','ok','step']. */
    public array $calls = [];
    /** Paths changed by successful write/append/delete/move calls. */
    public array $writes = [];
    public bool $finished = false;
    public ?string $finalAnswer = null;

    /** Build the workspace from checks.agent.env; $enabled = tool names exposed to the model. */
    public function __construct(array $env, array $enabled)
    {
        foreach ((array) ($env['files'] ?? []) as $path => $content) {
            if (is_int($path) && is_array($content) && is_string($content['path'] ?? null)) {
                // Also accept a list of {"path": …, "content": …} objects.
                [$path, $content] = [$content['path'], $content['content'] ?? ''];
            }
            $p = self::path((string) $path);
            if ($p !== null && $p !== '/') {
                $this->files[$p] = is_string($content) ? $content : (string) json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $this->addParents($p);
            }
        }
        foreach ((array) ($env['dirs'] ?? []) as $d) {
            $p = is_string($d) ? self::path($d) : null;
            if ($p !== null) {
                $this->dirs[$p] = true;
                $this->addParents($p);
            }
        }
        $this->http = is_array($env['http'] ?? null) ? $env['http'] : [];
        foreach ((array) ($env['records'] ?? []) as $table => $rows) {
            $this->records[(string) $table] = array_values(array_filter((array) $rows, 'is_array'));
        }
        foreach ((array) ($env['calendar'] ?? []) as $ev) {
            if (is_array($ev)) {
                $this->calendar[] = $ev;
            }
        }
        $this->now = is_string($env['now'] ?? null) ? $env['now'] : gmdate('Y-m-d\TH:i');
        $this->enabled = array_values(array_unique(array_merge(array_intersect($enabled, self::ALL), ['finish'])));
    }

    /** Enabled tool names (finish is always available). */
    public function enabled(): array
    {
        return $this->enabled;
    }

    /** OpenAI function-calling schemas for the given tool names. */
    public static function schemas(array $names): array
    {
        $str = static fn(string $d) => ['type' => 'string', 'description' => $d];
        $defs = [
            'list_dir' => ['List the direct children (files and folders) of a directory.', ['path' => $str('Absolute directory path, e.g. /inbox')], ['path']],
            'read_file' => ['Read the full text content of a file.', ['path' => $str('Absolute file path')], ['path']],
            'write_file' => ['Create or overwrite a file with the given text content.', ['path' => $str('Absolute file path'), 'content' => $str('Complete new file content')], ['path', 'content']],
            'append_file' => ['Append text to the end of a file (creates it if missing).', ['path' => $str('Absolute file path'), 'content' => $str('Text to append')], ['path', 'content']],
            'delete_file' => ['Delete a file.', ['path' => $str('Absolute file path')], ['path']],
            'move_file' => ['Move or rename a file.', ['from' => $str('Current absolute path'), 'to' => $str('New absolute path (or an existing folder)')], ['from', 'to']],
            'search_files' => ['Case-insensitive search of file contents; returns matching paths with the first matching line.', ['query' => $str('Text to search for'), 'path' => $str('Optional folder to limit the search to')], ['query']],
            'http_get' => ['HTTP GET request; returns the status code and response body.', ['url' => $str('Full URL including query string')], ['url']],
            'query_records' => ['Query rows of a record table.', [
                'table' => $str('Table name'),
                'where' => ['type' => 'object', 'description' => 'Optional equality filters, e.g. {"status": "open"}. A value may also be an operator object such as {"gt": 10} (gt, gte, lt, lte, ne, contains, in).'],
                'sort' => $str('Optional sort field; prefix with "-" for descending, e.g. "-total"'),
                'limit' => ['type' => 'integer', 'description' => 'Optional maximum number of rows (default 50, max 200)'],
            ], ['table']],
            'update_record' => ['Update fields of one record identified by its id.', [
                'table' => $str('Table name'),
                'id' => $str('The record id'),
                'fields' => ['type' => 'object', 'description' => 'Fields to set, e.g. {"status": "shipped"}'],
            ], ['table', 'id', 'fields']],
            'send_email' => ['Send an email.', ['to' => $str('Recipient email address (several may be separated by commas)'), 'subject' => $str('Subject line'), 'body' => $str('Plain-text body')], ['to', 'subject', 'body']],
            'calendar_list' => ['List calendar events, optionally only those overlapping a time range.', ['from' => $str('Optional range start, YYYY-MM-DDTHH:MM'), 'to' => $str('Optional range end, YYYY-MM-DDTHH:MM')], []],
            'calendar_add' => ['Add a calendar event.', ['title' => $str('Event title'), 'start' => $str('Start, YYYY-MM-DDTHH:MM'), 'end' => $str('End, YYYY-MM-DDTHH:MM')], ['title', 'start', 'end']],
            'calc' => ['Evaluate an arithmetic expression (+ - * / ^, parentheses, decimals, sqrt/abs/round/floor/ceil).', ['expression' => $str('Expression, e.g. (12.5 + 7) * 3')], ['expression']],
            'finish' => ['Finish the task and give your final answer. Call this exactly once, when everything is done.', ['answer' => $str('Final answer or short summary, including any value the task asks for')], ['answer']],
        ];
        $out = [];
        foreach ($names as $name) {
            if (!isset($defs[$name])) {
                continue;
            }
            [$desc, $props, $req] = $defs[$name];
            $params = ['type' => 'object', 'properties' => $props];
            if ($req) {
                $params['required'] = $req;
            }
            $out[] = ['type' => 'function', 'function' => ['name' => $name, 'description' => $desc, 'parameters' => $params]];
        }
        return $out;
    }

    /**
     * Execute one tool call → ['ok' => bool, 'result' => array|null, 'error' => ?string].
     * Errors are returned to the model (never thrown): they are the model's mistakes.
     */
    public function execute(string $name, mixed $args, int $step = 0): array
    {
        $this->calls[] = ['tool' => $name, 'ok' => false, 'step' => $step];
        if (!in_array($name, $this->enabled, true)) {
            return self::err("Unknown tool '{$name}'. Available tools: " . implode(', ', $this->enabled));
        }
        if ($args === null || $args === '') {
            $args = [];
        }
        if (!is_array($args) || ($args !== [] && array_is_list($args))) {
            return self::err('Tool arguments must be a JSON object');
        }
        try {
            $result = match ($name) {
                'list_dir' => $this->listDir($args),
                'read_file' => $this->readFile($args),
                'write_file' => $this->writeFile($args, false),
                'append_file' => $this->writeFile($args, true),
                'delete_file' => $this->deleteFile($args),
                'move_file' => $this->moveFile($args),
                'search_files' => $this->searchFiles($args),
                'http_get' => $this->httpGet($args),
                'query_records' => $this->queryRecords($args),
                'update_record' => $this->updateRecord($args),
                'send_email' => $this->sendEmail($args),
                'calendar_list' => $this->calendarList($args),
                'calendar_add' => $this->calendarAdd($args),
                'calc' => ['result' => self::calc(self::argStr($args, 'expression'))],
                'finish' => $this->finish($args),
            };
        } catch (\InvalidArgumentException $e) {
            return self::err($e->getMessage());
        }
        $this->calls[count($this->calls) - 1]['ok'] = true;
        return ['ok' => true, 'result' => $result, 'error' => null];
    }

    /** Final environment for storage/goal checks (http fixtures omitted). */
    public function state(): array
    {
        ksort($this->files);
        return [
            'files' => $this->files,
            'records' => $this->records,
            'outbox' => $this->outbox,
            'calendar' => $this->calendar,
            'now' => $this->now,
        ];
    }

    public function file(string $path): ?string
    {
        $p = self::path($path);
        return $p !== null ? ($this->files[$p] ?? null) : null;
    }

    public function records(string $table): array
    {
        return $this->records[$table] ?? [];
    }

    public function outbox(): array
    {
        return $this->outbox;
    }

    public function calendar(): array
    {
        return $this->calendar;
    }

    /** Normalize a workspace path: "/a/./b/../c" → "/a/c"; relative paths resolve from "/"; null if invalid. */
    public static function path(string $p): ?string
    {
        $p = trim(str_replace('\\', '/', $p));
        if ($p === '' || strlen($p) > 1024 || str_contains($p, "\0")) {
            return null;
        }
        $parts = [];
        foreach (explode('/', $p) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $seg;
        }
        return '/' . implode('/', $parts);
    }

    /** Normalize "2026-10-21 10:00", "2026-10-21T10:00:00Z" … → "2026-10-21T10:00"; null if unparsable. */
    public static function dt(mixed $v): ?string
    {
        if (!is_string($v) || !preg_match('/^\s*(\d{4}-\d{2}-\d{2})[T ](\d{1,2}):(\d{2})(?::(\d{2})(?:\.\d+)?)?\s*(?:Z|[+-]00:?00)?\s*$/i', $v, $m)) {
            return null;
        }
        if ((int) $m[2] > 23 || (int) $m[3] > 59 || !checkdate((int) substr($m[1], 5, 2), (int) substr($m[1], 8, 2), (int) substr($m[1], 0, 4))) {
            return null;
        }
        return $m[1] . 'T' . str_pad($m[2], 2, '0', STR_PAD_LEFT) . ':' . $m[3];
    }

    /**
     * Safe arithmetic (shunting-yard, never eval): + - * / ^ (also × ÷ − **), unary minus,
     * parentheses, decimals, scientific notation, sqrt/abs/round/floor/ceil.
     */
    public static function calc(string $expr): float
    {
        $expr = trim($expr);
        if ($expr === '' || strlen($expr) > 500) {
            throw new \InvalidArgumentException('Expression must be 1–500 characters');
        }
        $expr = str_replace(['×', '÷', '−', '**'], ['*', '/', '-', '^'], $expr);
        $prec = ['+' => 1, '-' => 1, '*' => 2, '/' => 2, 'neg' => 3, '^' => 4];
        $funcs = ['sqrt', 'abs', 'round', 'floor', 'ceil'];
        $out = [];
        $ops = [];
        $prevOperand = false;
        $i = 0;
        $n = strlen($expr);
        while ($i < $n) {
            $ch = $expr[$i];
            if (ctype_space($ch)) {
                $i++;
                continue;
            }
            if (preg_match('/\G(?=\.?\d)(?:\d{1,3}(?:,\d{3})+(?!\d)|\d+)?(?:\.\d+)?(?:[eE][-+]?\d+)?/', $expr, $m, 0, $i) && $m[0] !== '') {
                if ($prevOperand) {
                    throw new \InvalidArgumentException("Unexpected number at position {$i}");
                }
                $out[] = (float) str_replace(',', '', $m[0]);
                $i += strlen($m[0]);
                $prevOperand = true;
                continue;
            }
            if (preg_match('/\G[a-z]+/i', $expr, $m, 0, $i)) {
                $fn = strtolower($m[0]);
                if (!in_array($fn, $funcs, true) || $prevOperand) {
                    throw new \InvalidArgumentException("Unknown name '{$m[0]}'");
                }
                $ops[] = 'fn:' . $fn;
                $i += strlen($m[0]);
                continue;
            }
            if ($ch === '(') {
                if ($prevOperand) {
                    throw new \InvalidArgumentException("Missing operator before '(' at position {$i}");
                }
                $ops[] = '(';
            } elseif ($ch === ')') {
                while ($ops && end($ops) !== '(') {
                    $out[] = array_pop($ops);
                }
                if (!$ops) {
                    throw new \InvalidArgumentException('Unbalanced parentheses');
                }
                array_pop($ops);
                if ($ops && str_starts_with((string) end($ops), 'fn:')) {
                    $out[] = array_pop($ops);
                }
                $prevOperand = true;
            } elseif (isset($prec[$ch])) {
                if (!$prevOperand) {
                    if ($ch === '-') {
                        $ops[] = 'neg';
                    } elseif ($ch !== '+') {
                        throw new \InvalidArgumentException("Unexpected operator '{$ch}' at position {$i}");
                    }
                    $i++;
                    continue;
                }
                while ($ops) {
                    $top = end($ops);
                    if ($top === '(' || str_starts_with($top, 'fn:')) {
                        break;
                    }
                    $higher = $prec[$top] > $prec[$ch] || ($prec[$top] === $prec[$ch] && $ch !== '^');
                    if (!$higher) {
                        break;
                    }
                    $out[] = array_pop($ops);
                }
                $ops[] = $ch;
                $prevOperand = false;
            } else {
                throw new \InvalidArgumentException("Unsupported character '{$ch}' at position {$i}");
            }
            $i++;
        }
        if (!$prevOperand) {
            throw new \InvalidArgumentException('Expression ends unexpectedly');
        }
        while ($ops) {
            $op = array_pop($ops);
            if ($op === '(') {
                throw new \InvalidArgumentException('Unbalanced parentheses');
            }
            $out[] = $op;
        }
        return self::evalRpn($out);
    }

    /* ------------------------------------------------------------------ tools */

    private function listDir(array $a): array
    {
        $dir = $this->argPath($a, 'path');
        if (isset($this->files[$dir])) {
            throw new \InvalidArgumentException("Not a directory: {$dir}");
        }
        if (!isset($this->dirs[$dir])) {
            throw new \InvalidArgumentException("No such directory: {$dir}");
        }
        $prefix = $dir === '/' ? '/' : $dir . '/';
        $entries = [];
        foreach ($this->files as $p => $content) {
            if (str_starts_with($p, $prefix) && !str_contains(substr($p, strlen($prefix)), '/')) {
                $entries[substr($p, strlen($prefix))] = ['name' => substr($p, strlen($prefix)), 'type' => 'file', 'size' => strlen($content)];
            }
        }
        foreach (array_keys($this->dirs) as $d) {
            if ($d !== $dir && str_starts_with($d, $prefix) && !str_contains(substr($d, strlen($prefix)), '/')) {
                $entries[substr($d, strlen($prefix))] = ['name' => substr($d, strlen($prefix)), 'type' => 'dir'];
            }
        }
        ksort($entries, SORT_STRING);
        return ['path' => $dir, 'entries' => array_values($entries)];
    }

    private function readFile(array $a): array
    {
        $p = $this->argPath($a, 'path');
        if (!isset($this->files[$p])) {
            throw new \InvalidArgumentException(isset($this->dirs[$p]) ? "Is a directory: {$p}" : "No such file: {$p}");
        }
        $content = $this->files[$p];
        $len = mb_strlen($content);
        $res = ['path' => $p, 'size' => strlen($content), 'content' => $len > self::MAX_READ_CHARS ? mb_substr($content, 0, self::MAX_READ_CHARS) : $content];
        if ($len > self::MAX_READ_CHARS) {
            $res['truncated'] = true;
            $res['note'] = 'Showing the first ' . self::MAX_READ_CHARS . " of {$len} characters";
        }
        return $res;
    }

    private function writeFile(array $a, bool $append): array
    {
        $p = $this->argPath($a, 'path');
        if ($p === '/' || isset($this->dirs[$p])) {
            throw new \InvalidArgumentException("Is a directory: {$p}");
        }
        foreach (array_keys($this->files) as $f) {
            if (str_starts_with($p, $f . '/')) {
                throw new \InvalidArgumentException("Parent path is a file: {$f}");
            }
        }
        if (!array_key_exists('content', $a)) {
            throw new \InvalidArgumentException("Missing argument 'content'");
        }
        $content = $a['content'];
        if (!is_string($content)) {
            $content = is_scalar($content) ? (string) $content : (string) json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $new = $append ? ($this->files[$p] ?? '') . $content : $content;
        if (strlen($new) > self::MAX_FILE_BYTES) {
            throw new \InvalidArgumentException('File too large (max 1 MB)');
        }
        $total = array_sum(array_map('strlen', $this->files)) - strlen($this->files[$p] ?? '') + strlen($new);
        if ($total > self::MAX_WORKSPACE_BYTES) {
            throw new \InvalidArgumentException('Workspace is full (max 4 MB in total)');
        }
        $created = !isset($this->files[$p]);
        $this->files[$p] = $new;
        $this->addParents($p);
        $this->writes[] = $p;
        return ['ok' => true, 'path' => $p, 'bytes' => strlen($new), 'created' => $created];
    }

    private function deleteFile(array $a): array
    {
        $p = $this->argPath($a, 'path');
        if (!isset($this->files[$p])) {
            throw new \InvalidArgumentException(isset($this->dirs[$p]) ? "Is a directory (only files can be deleted): {$p}" : "No such file: {$p}");
        }
        unset($this->files[$p]);
        $this->writes[] = $p;
        return ['ok' => true, 'deleted' => $p];
    }

    private function moveFile(array $a): array
    {
        $from = $this->argPath($a, isset($a['from']) ? 'from' : (isset($a['source']) ? 'source' : 'src'));
        $to = $this->argPath($a, isset($a['to']) ? 'to' : (isset($a['destination']) ? 'destination' : 'dst'));
        if (!isset($this->files[$from])) {
            throw new \InvalidArgumentException("No such file: {$from}");
        }
        if (isset($this->dirs[$to])) {
            $to = rtrim($to, '/') . '/' . basename($from);
        }
        if ($to === $from) {
            return ['ok' => true, 'from' => $from, 'to' => $to, 'note' => 'source and destination are the same'];
        }
        $overwrote = isset($this->files[$to]);
        $this->files[$to] = $this->files[$from];
        unset($this->files[$from]);
        $this->addParents($to);
        array_push($this->writes, $from, $to);
        return ['ok' => true, 'from' => $from, 'to' => $to, 'overwrote' => $overwrote];
    }

    private function searchFiles(array $a): array
    {
        $q = self::argStr($a, 'query');
        if (trim($q) === '') {
            throw new \InvalidArgumentException('Query must not be empty');
        }
        $scope = isset($a['path']) && is_string($a['path']) && $a['path'] !== '' ? self::path($a['path']) : '/';
        $prefix = $scope === '/' ? '/' : $scope . '/';
        $matches = [];
        foreach ($this->files as $p => $content) {
            if (!str_starts_with($p, $prefix)) {
                continue;
            }
            $pos = mb_stripos($content, $q);
            if ($pos === false) {
                continue;
            }
            $lineStart = mb_strrpos(mb_substr($content, 0, $pos), "\n");
            $line = mb_substr($content, $lineStart === false ? 0 : $lineStart + 1);
            $line = trim((string) strtok($line, "\n"));
            $matches[] = ['path' => $p, 'line' => mb_substr($line, 0, 200)];
            if (count($matches) >= 50) {
                break;
            }
        }
        return ['query' => $q, 'count' => count($matches), 'matches' => $matches];
    }

    private function httpGet(array $a): array
    {
        $url = trim(self::argStr($a, 'url'));
        if (!preg_match('#^https?://#i', $url)) {
            throw new \InvalidArgumentException('URL must start with http:// or https://');
        }
        $hit = $this->http[$url] ?? null;
        if ($hit === null) {
            $want = self::canonicalUrl($url);
            foreach ($this->http as $u => $resp) {
                if (self::canonicalUrl((string) $u) === $want) {
                    $hit = $resp;
                    break;
                }
            }
        }
        if ($hit === null) {
            return ['url' => $url, 'status' => 404, 'body' => 'Not Found'];
        }
        $status = is_array($hit) && isset($hit['status']) ? (int) $hit['status'] : 200;
        $body = is_array($hit) && array_key_exists('body', $hit) ? $hit['body'] : $hit;
        if (!is_string($body)) {
            $body = (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $res = ['url' => $url, 'status' => $status, 'body' => mb_substr($body, 0, self::MAX_READ_CHARS)];
        if (is_array($hit) && is_string($hit['content_type'] ?? null)) {
            $res['content_type'] = $hit['content_type'];
        }
        return $res;
    }

    private function queryRecords(array $a): array
    {
        $table = $this->argTable($a);
        $where = $a['where'] ?? [];
        if (is_string($where) && trim($where) !== '') {
            $where = json_decode($where, true);
        }
        if (!is_array($where)) {
            throw new \InvalidArgumentException("'where' must be an object of field filters");
        }
        $rows = array_values(array_filter($this->records[$table], fn($r) => $this->matches($r, $where)));
        $sort = isset($a['sort']) && is_string($a['sort']) ? trim($a['sort']) : '';
        if ($sort !== '') {
            $desc = str_starts_with($sort, '-') || (bool) preg_match('/\s+desc$/i', $sort);
            $field = trim(preg_replace('/\s+(asc|desc)$/i', '', ltrim($sort, '-+')) ?? '');
            usort($rows, static function ($x, $y) use ($field, $desc) {
                $c = self::compareValues($x[$field] ?? null, $y[$field] ?? null);
                return $desc ? -$c : $c;
            });
        }
        $limit = isset($a['limit']) && is_numeric($a['limit']) ? max(1, min(self::MAX_ROWS, (int) $a['limit'])) : 50;
        return ['table' => $table, 'count' => count($rows), 'rows' => array_slice($rows, 0, $limit)];
    }

    private function updateRecord(array $a): array
    {
        $table = $this->argTable($a);
        if (!array_key_exists('id', $a) || !is_scalar($a['id'])) {
            throw new \InvalidArgumentException("Missing argument 'id'");
        }
        $fields = $a['fields'] ?? null;
        if (is_string($fields)) {
            $fields = json_decode($fields, true);
        }
        if (!is_array($fields) || !$fields || array_is_list($fields)) {
            throw new \InvalidArgumentException("'fields' must be a non-empty object");
        }
        unset($fields['id']);
        foreach ($this->records[$table] as $i => $row) {
            if (isset($row['id']) && self::looseEqual($row['id'], $a['id'], false)) {
                $this->records[$table][$i] = array_merge($row, $fields);
                return ['ok' => true, 'record' => $this->records[$table][$i]];
            }
        }
        throw new \InvalidArgumentException("No record with id " . json_encode($a['id']) . " in table '{$table}'");
    }

    private function sendEmail(array $a): array
    {
        $toRaw = $a['to'] ?? null;
        $list = is_array($toRaw) ? $toRaw : preg_split('/[,;]+/', is_scalar($toRaw) ? (string) $toRaw : '');
        $to = [];
        foreach ($list ?: [] as $addr) {
            $addr = trim((string) (is_scalar($addr) ? $addr : ''));
            if (preg_match('/<([^>]+)>/', $addr, $m)) {
                $addr = trim($m[1]);
            }
            if ($addr === '') {
                continue;
            }
            if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException("Invalid email address: {$addr}");
            }
            $to[] = strtolower($addr);
        }
        if (!$to) {
            throw new \InvalidArgumentException("Missing recipient 'to'");
        }
        $email = [
            'id' => count($this->outbox) + 1,
            'to' => $to,
            'subject' => self::argStr($a, 'subject', true),
            'body' => self::argStr($a, 'body', true),
            'sent_at' => $this->now,
        ];
        $this->outbox[] = $email;
        return ['ok' => true, 'id' => $email['id'], 'to' => $to];
    }

    private function calendarList(array $a): array
    {
        $from = isset($a['from']) && $a['from'] !== '' ? self::dt($a['from']) : null;
        $to = isset($a['to']) && $a['to'] !== '' ? self::dt($a['to']) : null;
        if ((isset($a['from']) && $a['from'] !== '' && $from === null) || (isset($a['to']) && $a['to'] !== '' && $to === null)) {
            throw new \InvalidArgumentException('Dates must look like YYYY-MM-DDTHH:MM');
        }
        $events = [];
        foreach ($this->calendar as $i => $ev) {
            $s = self::dt($ev['start'] ?? null) ?? (string) ($ev['start'] ?? '');
            $e = self::dt($ev['end'] ?? null) ?? $s;
            if (($from !== null && $e <= $from) || ($to !== null && $s >= $to)) {
                continue;
            }
            $events[] = array_merge(['id' => $ev['id'] ?? $i + 1], $ev);
        }
        usort($events, static fn($x, $y) => strcmp((string) ($x['start'] ?? ''), (string) ($y['start'] ?? '')));
        return ['count' => count($events), 'events' => $events];
    }

    private function calendarAdd(array $a): array
    {
        $title = trim(self::argStr($a, 'title'));
        $start = self::dt($a['start'] ?? null);
        $end = self::dt($a['end'] ?? null);
        if ($title === '') {
            throw new \InvalidArgumentException('Title must not be empty');
        }
        if ($start === null || $end === null) {
            throw new \InvalidArgumentException('start and end must look like YYYY-MM-DDTHH:MM');
        }
        if ($end <= $start) {
            throw new \InvalidArgumentException('end must be after start');
        }
        $ev = ['title' => mb_substr($title, 0, 300), 'start' => $start, 'end' => $end];
        $this->calendar[] = $ev;
        return ['ok' => true, 'event' => ['id' => count($this->calendar)] + $ev];
    }

    private function finish(array $a): array
    {
        $ans = $a['answer'] ?? ($a['result'] ?? ($a['summary'] ?? ''));
        $this->finalAnswer = is_string($ans) ? $ans : (is_scalar($ans) ? (string) $ans : (string) json_encode($ans, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->finished = true;
        return ['ok' => true, 'finished' => true];
    }

    /* ------------------------------------------------------------------ helpers */

    private static function err(string $msg): array
    {
        return ['ok' => false, 'result' => null, 'error' => $msg];
    }

    private static function argStr(array $a, string $key, bool $optional = false): string
    {
        $v = $a[$key] ?? null;
        if ($v === null && $optional) {
            return '';
        }
        if (!is_scalar($v)) {
            throw new \InvalidArgumentException("Missing or invalid argument '{$key}'");
        }
        return (string) $v;
    }

    private function argPath(array $a, string $key): string
    {
        $p = self::path(self::argStr($a, $key));
        if ($p === null) {
            throw new \InvalidArgumentException("Invalid path in '{$key}'");
        }
        return $p;
    }

    private function argTable(array $a): string
    {
        $t = trim(self::argStr($a, 'table'));
        if (!isset($this->records[$t])) {
            throw new \InvalidArgumentException("Unknown table '{$t}'. Available tables: " . (implode(', ', array_keys($this->records)) ?: 'none'));
        }
        return $t;
    }

    private function addParents(string $p): void
    {
        $d = dirname($p);
        while ($d !== '/' && $d !== '.' && $d !== '') {
            $this->dirs[$d] = true;
            $d = dirname($d);
        }
    }

    /** Row filter: equality (loose, case-insensitive strings) or operator objects. */
    private function matches(array $row, array $where): bool
    {
        foreach ($where as $field => $cond) {
            $val = $row[$field] ?? null;
            if (is_array($cond) && !array_is_list($cond)) {
                foreach ($cond as $op => $arg) {
                    if (!self::opMatches((string) $op, $val, $arg)) {
                        return false;
                    }
                }
            } elseif (is_array($cond)) {
                if (!self::opMatches('in', $val, $cond)) {
                    return false;
                }
            } elseif (!self::looseEqual($val, $cond, true)) {
                return false;
            }
        }
        return true;
    }

    private static function opMatches(string $op, mixed $val, mixed $arg): bool
    {
        $op = strtolower(ltrim($op, '$'));
        return match ($op) {
            'eq', '=' => self::looseEqual($val, $arg, true),
            'ne', '!=' => !self::looseEqual($val, $arg, true),
            'gt', '>' => self::compareValues($val, $arg) > 0,
            'gte', '>=' => self::compareValues($val, $arg) >= 0,
            'lt', '<' => self::compareValues($val, $arg) < 0,
            'lte', '<=' => self::compareValues($val, $arg) <= 0,
            'contains' => is_scalar($val) && is_scalar($arg) && mb_stripos((string) $val, (string) $arg) !== false,
            'in' => is_array($arg) && (bool) array_filter($arg, static fn($x) => self::looseEqual($val, $x, true)),
            default => throw new \InvalidArgumentException("Unknown filter operator '{$op}'"),
        };
    }

    /** Numbers compare numerically, booleans with "true"/"false", strings trimmed (optionally case-insensitive). */
    public static function looseEqual(mixed $a, mixed $b, bool $ci): bool
    {
        if (is_numeric($a) && is_numeric($b)) {
            return abs((float) $a - (float) $b) < 1e-9;
        }
        if (is_bool($a) || is_bool($b)) {
            $norm = static fn($x) => is_bool($x) ? $x : in_array(strtolower(trim((string) (is_scalar($x) ? $x : ''))), ['true', '1', 'yes'], true);
            return $norm($a) === $norm($b);
        }
        if ($a === null || $b === null) {
            return $a === $b;
        }
        if (!is_scalar($a) || !is_scalar($b)) {
            return AnswerCheck::deepEqual($a, $b);
        }
        $x = trim((string) $a);
        $y = trim((string) $b);
        return $ci ? mb_strtolower($x) === mb_strtolower($y) : $x === $y;
    }

    private static function compareValues(mixed $a, mixed $b): int
    {
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a <=> (float) $b;
        }
        if ($a === null || $b === null) {
            return ($a === null) <=> ($b === null) ?: 0;
        }
        return strcmp(is_scalar($a) ? (string) $a : '', is_scalar($b) ? (string) $b : '');
    }

    private static function canonicalUrl(string $url): string
    {
        $p = parse_url(trim($url));
        if (!is_array($p)) {
            return $url;
        }
        $q = [];
        parse_str($p['query'] ?? '', $q);
        ksort($q);
        return strtolower(($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '')) . (isset($p['port']) ? ':' . $p['port'] : '')
            . rtrim($p['path'] ?? '', '/') . ($q ? '?' . http_build_query($q) : '');
    }

    private static function evalRpn(array $rpn): float
    {
        $st = [];
        foreach ($rpn as $tok) {
            if (is_float($tok)) {
                $st[] = $tok;
                continue;
            }
            if ($tok === 'neg' || str_starts_with($tok, 'fn:')) {
                if (!$st) {
                    throw new \InvalidArgumentException('Malformed expression');
                }
                $x = array_pop($st);
                $st[] = match ($tok) {
                    'neg' => -$x,
                    'fn:sqrt' => $x < 0 ? throw new \InvalidArgumentException('sqrt of a negative number') : sqrt($x),
                    'fn:abs' => abs($x),
                    'fn:round' => round($x),
                    'fn:floor' => floor($x),
                    'fn:ceil' => ceil($x),
                    default => throw new \InvalidArgumentException('Malformed expression'),
                };
                continue;
            }
            if (count($st) < 2) {
                throw new \InvalidArgumentException('Malformed expression');
            }
            $b = array_pop($st);
            $a = array_pop($st);
            if ($tok === '/' && $b == 0.0) {
                throw new \InvalidArgumentException('Division by zero');
            }
            $st[] = match ($tok) {
                '+' => $a + $b,
                '-' => $a - $b,
                '*' => $a * $b,
                '/' => $a / $b,
                '^' => $a < 0 && floor($b) != $b ? throw new \InvalidArgumentException('Fractional power of a negative number') : $a ** $b,
                default => throw new \InvalidArgumentException('Malformed expression'),
            };
        }
        if (count($st) !== 1) {
            throw new \InvalidArgumentException('Malformed expression');
        }
        $v = (float) $st[0];
        if (!is_finite($v)) {
            throw new \InvalidArgumentException('Result is not a finite number');
        }
        return (float) sprintf('%.12g', $v);
    }
}
