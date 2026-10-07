<?php

// Creates the database tables:  php bin/install.php
// (On hosting without SSH: import database/schema.sql from phpMyAdmin instead.)

declare(strict_types=1);

use Udeyou\Core\Config;
use Udeyou\Core\Database;

require dirname(__DIR__) . '/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
if (strlen((string) Config::get('APP_SECRET')) < 32 || Config::get('APP_SECRET') === 'CHANGE_ME') {
    fwrite(STDERR, "Set APP_SECRET in .env first:  php -r \"echo bin2hex(random_bytes(32));\"\n");
    exit(1);
}

$sql = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
$sql = preg_replace('/^\s*--.*$/m', '', $sql);
foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
    Database::connection()->exec($statement);
}

echo "Database tables are ready.\n";
