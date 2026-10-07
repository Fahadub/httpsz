<?php

declare(strict_types=1);

namespace Udeyou\Repositories;

use Udeyou\Core\Clock;
use Udeyou\Core\Database;

final class ApiKeyRepository
{
    public function create(int $clientId, string $name, string $mode, string $prefix, string $hash): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO api_keys (client_id, name, mode, key_prefix, key_hash, created_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$clientId, $name, $mode, $prefix, $hash, Clock::now()]);
        return (int) Database::connection()->lastInsertId();
    }

    /** Active (non-revoked) key joined with its owner client. */
    public function findActiveByHash(string $hash): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT k.id AS key_id, k.mode, k.name AS key_name, c.id AS client_id, c.company_name, c.credits, c.status
             FROM api_keys k JOIN clients c ON c.id = k.client_id
             WHERE k.key_hash = ? AND k.revoked_at IS NULL'
        );
        $stmt->execute([$hash]);
        return $stmt->fetch() ?: null;
    }

    public function touch(int $keyId): void
    {
        $stmt = Database::connection()->prepare('UPDATE api_keys SET last_used_at = ? WHERE id = ?');
        $stmt->execute([Clock::now(), $keyId]);
    }

    public function listForClient(int $clientId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM api_keys WHERE client_id = ? ORDER BY revoked_at IS NULL DESC, id DESC');
        $stmt->execute([$clientId]);
        return $stmt->fetchAll();
    }

    public function revoke(int $clientId, int $keyId): bool
    {
        $stmt = Database::connection()->prepare(
            'UPDATE api_keys SET revoked_at = ? WHERE id = ? AND client_id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([Clock::now(), $keyId, $clientId]);
        return $stmt->rowCount() === 1;
    }
}
