<?php

declare(strict_types=1);

namespace Udeyou\Repositories;

use Udeyou\Core\Clock;
use Udeyou\Core\Database;

final class OtpRepository
{
    public function create(array $row): int
    {
        $columns = array_keys($row);
        $sql = sprintf(
            'INSERT INTO otps (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?'))
        );
        Database::connection()->prepare($sql)->execute(array_values($row));
        return (int) Database::connection()->lastInsertId();
    }

    public function markSent(int $id): void
    {
        Database::connection()
            ->prepare('UPDATE otps SET status = \'sent\', sent_at = ? WHERE id = ?')
            ->execute([Clock::now(), $id]);
    }

    public function markFailed(int $id, string $error): void
    {
        Database::connection()
            ->prepare('UPDATE otps SET status = \'failed\', error_message = ? WHERE id = ?')
            ->execute([mb_substr($error, 0, 255), $id]);
    }

    /** A new code makes the previous still-valid codes for the same recipient unusable. */
    public function expireOthersForRecipient(int $clientId, string $mode, string $email, int $exceptId): void
    {
        Database::connection()->prepare(
            'UPDATE otps SET expires_at = ? WHERE client_id = ? AND mode = ? AND recipient_email = ?
             AND id <> ? AND status = \'sent\' AND expires_at > ?'
        )->execute([Clock::now(), $clientId, $mode, $email, $exceptId, Clock::now()]);
    }

    public function findByPublicId(int $clientId, string $mode, string $publicId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM otps WHERE client_id = ? AND mode = ? AND public_id = ?');
        $stmt->execute([$clientId, $mode, $publicId]);
        return $stmt->fetch() ?: null;
    }

    public function findLatestForRecipient(int $clientId, string $mode, string $email): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM otps WHERE client_id = ? AND mode = ? AND recipient_email = ? AND status IN (\'sent\', \'verified\')
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$clientId, $mode, $email]);
        return $stmt->fetch() ?: null;
    }

    /** Atomically consume one attempt. False when no attempts are left (or OTP not verifiable). */
    public function consumeAttempt(int $id): bool
    {
        $stmt = Database::connection()->prepare(
            'UPDATE otps SET attempts = attempts + 1 WHERE id = ? AND status = \'sent\' AND attempts < max_attempts'
        );
        $stmt->execute([$id]);
        return $stmt->rowCount() === 1;
    }

    /** Atomic: only one concurrent request can flip sent -> verified. */
    public function markVerified(int $id): bool
    {
        $stmt = Database::connection()->prepare(
            'UPDATE otps SET status = \'verified\', verified_at = ? WHERE id = ? AND status = \'sent\''
        );
        $stmt->execute([Clock::now(), $id]);
        return $stmt->rowCount() === 1;
    }

    public function countForRecipientSince(int $clientId, string $email, string $since): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM otps WHERE client_id = ? AND recipient_email = ? AND created_at >= ? AND status <> \'failed\''
        );
        $stmt->execute([$clientId, $email, $since]);
        return (int) $stmt->fetchColumn();
    }

    public function countForClientSince(int $clientId, string $since): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM otps WHERE client_id = ? AND created_at >= ?');
        $stmt->execute([$clientId, $since]);
        return (int) $stmt->fetchColumn();
    }

    public function recentForClient(int $clientId, int $limit = 20): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT public_id, mode, recipient_email, sender_name, status, attempts, created_at, verified_at, error_message
             FROM otps WHERE client_id = ? ORDER BY id DESC LIMIT ' . max(1, min($limit, 100))
        );
        $stmt->execute([$clientId]);
        return $stmt->fetchAll();
    }

    public function statsForClient(int $clientId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT
                SUM(status IN (\'sent\',\'verified\')) AS sent,
                SUM(status = \'verified\')              AS verified,
                SUM(status = \'failed\')                AS failed
             FROM otps WHERE client_id = ? AND mode = \'live\''
        );
        $stmt->execute([$clientId]);
        return array_map('intval', $stmt->fetch() ?: []);
    }

    public function deleteOlderThan(string $before): int
    {
        $stmt = Database::connection()->prepare('DELETE FROM otps WHERE created_at < ?');
        $stmt->execute([$before]);
        return $stmt->rowCount();
    }
}
