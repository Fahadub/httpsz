<?php

declare(strict_types=1);

namespace Udeyou\Repositories;

use Udeyou\Core\Clock;
use Udeyou\Core\Database;

final class ClientRepository
{
    public function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM clients WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM clients WHERE email = ?');
        $stmt->execute([strtolower($email)]);
        return $stmt->fetch() ?: null;
    }

    public function create(string $companyName, string $email, string $password, bool $isAdmin): int
    {
        $now = Clock::now();
        $stmt = Database::connection()->prepare(
            'INSERT INTO clients (company_name, email, password_hash, credits, is_admin, status, created_at, updated_at)
             VALUES (?, ?, ?, 0, ?, \'active\', ?, ?)'
        );
        $stmt->execute([$companyName, strtolower($email), password_hash($password, PASSWORD_DEFAULT), (int) $isAdmin, $now, $now]);
        return (int) Database::connection()->lastInsertId();
    }

    public function all(): array
    {
        return Database::connection()->query(
            'SELECT c.*, (SELECT COUNT(*) FROM otps o WHERE o.client_id = c.id AND o.mode = \'live\' AND o.status IN (\'sent\',\'verified\')) AS sent_count
             FROM clients c ORDER BY c.id DESC'
        )->fetchAll();
    }

    public function setStatus(int $id, string $status): void
    {
        $stmt = Database::connection()->prepare('UPDATE clients SET status = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$status, Clock::now(), $id]);
    }
}
