<?php

declare(strict_types=1);

namespace Udeyou\Services;

use PDO;
use Udeyou\Core\Clock;
use Udeyou\Core\Database;

/**
 * Credits are reserved BEFORE sending (atomic UPDATE ... WHERE credits >= 1) so that
 * concurrent requests can never overdraw a balance, and refunded if delivery fails.
 */
final class CreditService
{
    /** Reserve 1 credit. Must be called inside a transaction. Returns false if balance is 0. */
    public function reserve(PDO $pdo, int $clientId, string $reference): bool
    {
        $stmt = $pdo->prepare('UPDATE clients SET credits = credits - 1, updated_at = ? WHERE id = ? AND credits >= 1');
        $stmt->execute([Clock::now(), $clientId]);
        if ($stmt->rowCount() !== 1) {
            return false;
        }
        $this->log($pdo, $clientId, -1, 'otp_send', $reference, null);
        return true;
    }

    public function refund(int $clientId, string $reference): void
    {
        $this->add($clientId, 1, 'refund', $reference, 'Delivery failed');
    }

    public function add(int $clientId, int $amount, string $type, ?string $reference = null, ?string $note = null): void
    {
        Database::transaction(function (PDO $pdo) use ($clientId, $amount, $type, $reference, $note) {
            // GREATEST() keeps the unsigned column from going below zero on negative adjustments.
            $pdo->prepare('UPDATE clients SET credits = GREATEST(CAST(credits AS SIGNED) + ?, 0), updated_at = ? WHERE id = ?')
                ->execute([$amount, Clock::now(), $clientId]);
            $this->log($pdo, $clientId, $amount, $type, $reference, $note);
        });
    }

    public function balance(int $clientId): int
    {
        $stmt = Database::connection()->prepare('SELECT credits FROM clients WHERE id = ?');
        $stmt->execute([$clientId]);
        return (int) $stmt->fetchColumn();
    }

    public function history(int $clientId, int $limit = 10): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT amount, type, reference, note, created_at FROM credit_transactions
             WHERE client_id = ? AND type <> \'otp_send\' ORDER BY id DESC LIMIT ' . max(1, min($limit, 100))
        );
        $stmt->execute([$clientId]);
        return $stmt->fetchAll();
    }

    private function log(PDO $pdo, int $clientId, int $amount, string $type, ?string $reference, ?string $note): void
    {
        $pdo->prepare('INSERT INTO credit_transactions (client_id, amount, type, reference, note, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$clientId, $amount, $type, $reference, $note, Clock::now()]);
    }
}
