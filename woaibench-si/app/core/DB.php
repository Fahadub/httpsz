<?php
/**
 * PDO wrapper. All queries MUST use bound parameters.
 *
 *   DB::all('SELECT * FROM models WHERE is_active = ?', [1]);
 *   DB::one('SELECT * FROM models WHERE slug = :s', ['s' => $slug]);
 *   DB::val('SELECT COUNT(*) FROM runs');
 *   DB::insert('models', ['slug' => 'x', ...]);       // returns new id
 *   DB::update('models', ['name' => 'y'], 'id = ?', [5]); // returns affected rows
 *   DB::exec('DELETE FROM runs WHERE id = ?', [9]);    // returns affected rows
 *   DB::tx(fn() => ...);
 */
declare(strict_types=1);

defined('WB') || exit;

final class DB
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        self::$pdo = self::connect(
            (string) Config::get('db.host', 'localhost'),
            (int) Config::get('db.port', 3306),
            (string) Config::get('db.name', ''),
            (string) Config::get('db.user', ''),
            (string) Config::get('db.pass', '')
        );
        return self::$pdo;
    }

    /** Open a new connection (also used by the installer to test credentials). */
    public static function connect(string $host, int $port, string $name, string $user, #[\SensitiveParameter] string $pass): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port ?: 3306, $name);
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_TIMEOUT => 10,
        ]);
        // ONLY_FULL_GROUP_BY off for this session: MariaDB has no functional-dependency detection, so a host that
        // enables it globally would otherwise turn valid `SELECT t.* … GROUP BY t.id` queries into errors.
        $pdo->exec("SET time_zone = '+00:00', NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, "
            . "sql_mode = REPLACE(@@SESSION.sql_mode, 'ONLY_FULL_GROUP_BY', '')");
        return $pdo;
    }

    public static function setPdo(?PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        foreach ($params as $k => $v) {
            $name = is_int($k) ? $k + 1 : (str_starts_with((string) $k, ':') ? $k : ':' . $k);
            $type = match (true) {
                is_int($v) => PDO::PARAM_INT,
                is_bool($v) => PDO::PARAM_INT,
                $v === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($name, is_bool($v) ? (int) $v : $v, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function val(string $sql, array $params = []): mixed
    {
        $v = self::query($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** First column of every row. */
    public static function col(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Rows keyed by their first column. */
    public static function keyed(string $sql, array $params = []): array
    {
        $out = [];
        foreach (self::all($sql, $params) as $row) {
            $out[reset($row)] = $row;
        }
        return $out;
    }

    public static function exec(string $sql, array $params = []): int
    {
        return self::query($sql, $params)->rowCount();
    }

    public static function insert(string $table, array $data): int
    {
        self::assertIdent($table);
        $cols = array_keys($data);
        array_walk($cols, [self::class, 'assertIdent']);
        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $table,
            implode('`,`', $cols),
            implode(',', array_fill(0, count($cols), '?'))
        );
        self::query($sql, array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    /** INSERT ... ON DUPLICATE KEY UPDATE for the given $updateCols (defaults to all non-key data). */
    public static function upsert(string $table, array $data, ?array $updateCols = null): void
    {
        self::assertIdent($table);
        $cols = array_keys($data);
        array_walk($cols, [self::class, 'assertIdent']);
        $updateCols ??= $cols;
        $sets = implode(',', array_map(static fn($c) => "`$c` = VALUES(`$c`)", $updateCols));
        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
            $table,
            implode('`,`', $cols),
            implode(',', array_fill(0, count($cols), '?')),
            $sets
        );
        self::query($sql, array_values($data));
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        self::assertIdent($table);
        if (!$data) {
            return 0;
        }
        $sets = [];
        $params = [];
        foreach ($data as $col => $val) {
            self::assertIdent($col);
            $sets[] = "`$col` = ?";
            $params[] = $val;
        }
        if (array_is_list($whereParams)) {
            $params = array_merge($params, $whereParams);
        } else {
            throw new InvalidArgumentException('DB::update expects positional where params');
        }
        return self::exec(sprintf('UPDATE `%s` SET %s WHERE %s', $table, implode(', ', $sets), $where), $params);
    }

    /** Run $fn in a transaction; rolls back and rethrows on failure. */
    public static function tx(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Build "?,?,?" for IN() lists. */
    public static function in(array $values): string
    {
        return $values ? implode(',', array_fill(0, count($values), '?')) : 'NULL';
    }

    private static function assertIdent(string $name): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new InvalidArgumentException('Invalid SQL identifier: ' . $name);
        }
    }
}
