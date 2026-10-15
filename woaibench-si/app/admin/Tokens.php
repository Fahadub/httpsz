<?php
/**
 * API tokens for the headless verifier: the plain token is shown once, only its sha256 is stored.
 */
declare(strict_types=1);

namespace WB\Admin;

defined('WB') || exit;

use DB;

final class Tokens
{
    public const SCOPES = ['verifier'];

    /** Create a token and return the plain value (never stored). */
    public static function create(string $name, string $scope = 'verifier'): string
    {
        $plain = 'wbv_' . bin2hex(random_bytes(24));
        DB::insert('api_tokens', [
            'name' => mb_substr($name, 0, 120),
            'token_hash' => hash('sha256', $plain),
            'scope' => in_array($scope, self::SCOPES, true) ? $scope : 'verifier',
        ]);
        return $plain;
    }

    /** Delete a token (immediately invalid). */
    public static function revoke(int $id): bool
    {
        return DB::exec('DELETE FROM api_tokens WHERE id = ?', [$id]) > 0;
    }

    /** All tokens, newest first (hash prefix only, for identification). */
    public static function all(): array
    {
        return DB::all('SELECT id, name, scope, LEFT(token_hash, 8) AS hash_prefix, last_used_at, created_at FROM api_tokens ORDER BY id DESC');
    }
}
