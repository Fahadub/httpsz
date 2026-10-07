<?php

// Top up / adjust a client's balance:  php bin/credits.php client@email.com 1000 "Invoice #12"

declare(strict_types=1);

use Udeyou\Repositories\ClientRepository;
use Udeyou\Services\CreditService;

require dirname(__DIR__) . '/bootstrap.php';

if (PHP_SAPI !== 'cli' || $argc < 3 || !preg_match('/^-?\d+$/', $argv[2])) {
    exit("Usage: php bin/credits.php <client-email> <amount> [note]\n");
}

$client = (new ClientRepository())->findByEmail($argv[1]);
if ($client === null) {
    fwrite(STDERR, "Client not found: {$argv[1]}\n");
    exit(1);
}

$amount = (int) $argv[2];
$service = new CreditService();
$service->add((int) $client['id'], $amount, $amount > 0 ? 'topup' : 'adjustment', null, $argv[3] ?? null);

printf("%s balance is now %d credits\n", $client['email'], $service->balance((int) $client['id']));
