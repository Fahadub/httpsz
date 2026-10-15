<?php
/**
 * DEV ONLY — create or reset a back-office administrator in a LOCAL database.
 *
 *   WB_DB_NAME=wb_admin php tools/dev/create-admin.php admin@example.test 'long-password' ["Display Name"]
 *
 * Refuses to run against non-local database hosts. On production use the web installer instead.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
define('WB', true);
require dirname(__DIR__, 2) . '/app/bootstrap.php';

$host = (string) Config::get('db.host');
if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "Refusing: create-admin only runs against a local database (db.host = {$host}).\n");
    exit(1);
}

[, $email, $password, $name] = $argv + [null, '', '', ''];
$email = strtolower(trim((string) $email));
$password = (string) $password;
$name = trim((string) $name) ?: strstr($email, '@', true);

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 10) {
    fwrite(STDERR, "Usage: php tools/dev/create-admin.php email password(>=10 chars) [name]\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$id = DB::val('SELECT id FROM admins WHERE email = ?', [$email]);
if ($id) {
    DB::update('admins', ['pass_hash' => $hash, 'name' => $name], 'id = ?', [(int) $id]);
    echo "Updated admin #{$id} <{$email}> in " . Config::get('db.name') . "\n";
} else {
    $id = DB::insert('admins', ['email' => $email, 'name' => $name, 'pass_hash' => $hash, 'role' => 'owner']);
    echo "Created admin #{$id} <{$email}> in " . Config::get('db.name') . "\n";
}
