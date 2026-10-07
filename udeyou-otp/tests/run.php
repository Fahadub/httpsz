<?php

/**
 * End-to-end tests against a running server + real MySQL.
 *
 *   php -S 127.0.0.1:8080 -t public public/index.php     (with MAIL_DRIVER=log in .env)
 *   php tests/run.php [http://127.0.0.1:8080]
 */

declare(strict_types=1);

use Udeyou\Core\Database;
use Udeyou\Repositories\ApiKeyRepository;
use Udeyou\Repositories\ClientRepository;
use Udeyou\Services\ApiException;
use Udeyou\Services\ApiKeyService;
use Udeyou\Services\CreditService;
use Udeyou\Services\Mail\MailCarrier;
use Udeyou\Services\Mail\MailDeliveryException;
use Udeyou\Services\Mail\MailMessage;
use Udeyou\Services\OtpService;

require dirname(__DIR__) . '/bootstrap.php';

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8080', '/');
$mailLog = dirname(__DIR__) . '/storage/logs/mail.log';
$passed = 0;
$failed = 0;

function check(string $name, bool $ok, mixed $debug = null): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  \033[32m✔\033[0m {$name}\n";
    } else {
        $failed++;
        echo "  \033[31m✘ {$name}\033[0m\n";
        if ($debug !== null) {
            echo '      ' . json_encode($debug, JSON_UNESCAPED_UNICODE) . "\n";
        }
    }
}

function api(string $method, string $path, ?string $key, mixed $body = null, bool $raw = false): array
{
    global $base;
    $ch = curl_init($base . $path);
    $headers = ['Content-Type: application/json'];
    if ($key !== null) {
        $headers[] = "Authorization: Bearer {$key}";
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 20,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $raw ? $body : json_encode($body));
    }
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$status, json_decode((string) $response, true) ?? ['raw' => $response]];
}

function lastMailCodeFor(string $email): ?string
{
    global $mailLog;
    $log = (string) @file_get_contents($mailLog);
    $pos = strrpos($log, "To: {$email} ");
    if ($pos === false || !preg_match('/\n(\d{6})\n/', substr($log, $pos), $m)) {
        return null;
    }
    return $m[1];
}

// ---------------------------------------------------------------- setup
$suffix = bin2hex(random_bytes(4));
$clients = new ClientRepository();
$credits = new CreditService();
$clientId = $clients->create("Test Store {$suffix}", "owner-{$suffix}@example.com", 'password123', false);
$credits->add($clientId, 20, 'topup', null, 'test');
$keys = new ApiKeyService();
$live = $keys->generate($clientId, 'live', 'live');
$test = $keys->generate($clientId, 'test', 'test');
$rcpt = static fn (string $tag): string => "{$tag}-{$suffix}@example.com";

echo "\nAPI keys & auth\n";
check('key format udy_live_ + 40 chars', (bool) preg_match('/^udy_live_[0-9A-Za-z]{40}$/', $live));
$stored = Database::connection()->query("SELECT key_hash, key_prefix FROM api_keys WHERE client_id = {$clientId} AND mode = 'live'")->fetch();
check('only sha256 hash stored', $stored['key_hash'] === hash('sha256', $live) && !str_contains(json_encode($stored), substr($live, 15)));
[$s, $r] = api('GET', '/api/v1/health', null);
check('health 200', $s === 200);
[$s, $r] = api('POST', '/api/v1/otp/send', null, ['to' => 'a@b.com']);
check('missing key -> 401', $s === 401 && $r['error']['code'] === 'invalid_api_key', $r);
[$s, $r] = api('POST', '/api/v1/otp/send', 'udy_live_' . str_repeat('x', 40), ['to' => 'a@b.com']);
check('unknown key -> 401', $s === 401, $r);
[$s, $r] = api('GET', '/api/v1/balance', $live);
check('balance = 20', $s === 200 && $r['data']['credits'] === 20, $r);
[$s, $r] = api('GET', '/api/v1/otp/send', $live);
check('GET on POST endpoint -> 405', $s === 405, $r);

echo "\nValidation\n";
[$s, $r] = api('POST', '/api/v1/otp/send', $live, '{not json', true);
check('invalid json -> 400', $s === 400 && $r['error']['code'] === 'invalid_json', $r);
[$s, $r] = api('POST', '/api/v1/otp/send', $live, ['to' => 'not-an-email', 'sender_name' => 'x']);
check('bad fields -> 422 with field errors', $s === 422 && isset($r['error']['fields']['to'], $r['error']['fields']['sender_name']), $r);
[$s, $r] = api('POST', '/api/v1/otp/send', $live, ['to' => $rcpt('v'), 'sender_name' => 'Shop', 'template' => 'no placeholder']);
check('template without {{code}} -> 422', $s === 422 && isset($r['error']['fields']['template']), $r);
[$s, $r] = api('POST', '/api/v1/otp/send', $live, ['to' => $rcpt('v'), 'sender_name' => 'Shop', 'expires_in' => 5]);
check('expires_in out of range -> 422', $s === 422 && isset($r['error']['fields']['expires_in']), $r);

echo "\nTest mode (udy_test_)\n";
[$s, $r] = api('POST', '/api/v1/otp/send', $test, ['to' => $rcpt('t'), 'sender_name' => 'Shop']);
check('test send -> 201 with code', $s === 201 && ($r['data']['test_mode'] ?? false) && preg_match('/^\d{6}$/', $r['data']['code'] ?? ''), $r);
check('test send does not charge', $r['data']['credits_remaining'] === 20, $r);
$testOtp = $r['data'];
[$s, $r] = api('POST', '/api/v1/otp/verify', $test, ['otp_id' => $testOtp['otp_id'], 'code' => '000000' === $testOtp['code'] ? '111111' : '000000']);
check('wrong code -> 400, 4 attempts left', $s === 400 && $r['error']['attempts_remaining'] === 4, $r);
[$s, $r] = api('POST', '/api/v1/otp/verify', $live, ['otp_id' => $testOtp['otp_id'], 'code' => $testOtp['code']]);
check('live key cannot verify test OTP -> 404', $s === 404, $r);
[$s, $r] = api('POST', '/api/v1/otp/verify', $test, ['otp_id' => $testOtp['otp_id'], 'code' => $testOtp['code']]);
check('correct code -> 200 verified', $s === 200 && $r['data']['verified'] === true, $r);
[$s, $r] = api('POST', '/api/v1/otp/verify', $test, ['otp_id' => $testOtp['otp_id'], 'code' => $testOtp['code']]);
check('re-using code -> 409', $s === 409 && $r['error']['code'] === 'otp_already_used', $r);

echo "\nLive send + email delivery\n";
$to = $rcpt('live');
[$s, $r] = api('POST', '/api/v1/otp/send', $live, [
    'to' => strtoupper($to), 'sender_name' => "متجر الورد\r\nBcc: evil@x.com", 'reply_to' => 'support@shop.com',
    'template' => 'رمزك هو {{code}} لمدة {{minutes}} دقائق', 'expires_in' => 120,
]);
check('live send -> 201', $s === 201 && $r['data']['status'] === 'sent' && !isset($r['data']['code']), $r);
check('1 credit deducted (20 -> 19)', ($r['data']['credits_remaining'] ?? null) === 19, $r);
$liveOtp = $r['data'];
$log = (string) file_get_contents($mailLog);
check('email logged with store name as From (CRLF stripped)', str_contains($log, "To: {$to} | From: \"متجر الورد Bcc: evil@x.com\""));
check('custom template rendered', str_contains($log, 'لمدة 2 دقائق'));
$code = lastMailCodeFor($to) ?? (preg_match('/رمزك هو (\d{6})/u', substr($log, (int) strrpos($log, "To: {$to}")), $m) ? $m[1] : null);
check('code found in email', $code !== null);
$plain = Database::connection()->query("SELECT code_hash FROM otps WHERE public_id = '{$liveOtp['otp_id']}'")->fetchColumn();
check('code not stored in plain text', $plain !== $code && strlen($plain) === 64);
[$s, $r] = api('POST', '/api/v1/otp/send', $live, ['to' => $to, 'sender_name' => 'Shop']);
check('resend within cooldown -> 429', $s === 429 && $r['error']['code'] === 'resend_too_soon', $r);
[$s, $r] = api('POST', '/api/v1/otp/verify', $live, ['to' => $to, 'code' => $code]);
check('verify by "to" -> 200', $s === 200 && $r['data']['otp_id'] === $liveOtp['otp_id'], $r);

echo "\nExpiry & brute-force protection\n";
[$s, $r] = api('POST', '/api/v1/otp/send', $test, ['to' => $rcpt('exp'), 'sender_name' => 'Shop', 'code' => '4242']);
check('client-provided code accepted', $s === 201 && $r['data']['code'] === '4242', $r);
Database::connection()->exec("UPDATE otps SET expires_at = '2000-01-01 00:00:00' WHERE public_id = '{$r['data']['otp_id']}'");
[$s, $r] = api('POST', '/api/v1/otp/verify', $test, ['otp_id' => $r['data']['otp_id'], 'code' => '4242']);
check('expired -> 410', $s === 410 && $r['error']['code'] === 'otp_expired', $r);

[$s, $r] = api('POST', '/api/v1/otp/send', $test, ['to' => $rcpt('bf'), 'sender_name' => 'Shop']);
$bf = $r['data'];
$wrong = $bf['code'] === '999999' ? '888888' : '999999';
for ($i = 0; $i < 5; $i++) {
    api('POST', '/api/v1/otp/verify', $test, ['otp_id' => $bf['otp_id'], 'code' => $wrong]);
}
[$s, $r] = api('POST', '/api/v1/otp/verify', $test, ['otp_id' => $bf['otp_id'], 'code' => $bf['code']]);
check('locked after 5 wrong attempts, even with right code -> 429', $s === 429 && $r['error']['code'] === 'too_many_attempts', $r);

echo "\nCredits\n";
Database::connection()->exec("UPDATE clients SET credits = 3 WHERE id = {$clientId}");
// Fire 8 concurrent live sends with only 3 credits: exactly 3 must succeed.
$mh = curl_multi_init();
$handles = [];
for ($i = 0; $i < 8; $i++) {
    $ch = curl_init("{$base}/api/v1/otp/send");
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer {$live}", 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['to' => $rcpt("race{$i}"), 'sender_name' => 'Shop']),
    ]);
    curl_multi_add_handle($mh, $ch);
    $handles[] = $ch;
}
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh);
} while ($running > 0);
$codes = array_map(static fn ($ch) => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $handles);
$ok = count(array_filter($codes, static fn ($c) => $c === 201));
$broke = count(array_filter($codes, static fn ($c) => $c === 402));
check("8 concurrent sends with 3 credits -> 3x201 + 5x402 (got {$ok}/{$broke})", $ok === 3 && $broke === 5, $codes);
check('balance is exactly 0, never negative', $credits->balance($clientId) === 0);
[$s, $r] = api('POST', '/api/v1/otp/send', $live, ['to' => $rcpt('broke'), 'sender_name' => 'Shop']);
check('no credits -> 402', $s === 402 && $r['error']['code'] === 'insufficient_credits', $r);

// Delivery failure: credit must be refunded (in-process, with a carrier that always fails).
$credits->add($clientId, 1, 'topup');
$failing = new class implements MailCarrier {
    public function send(MailMessage $message): void
    {
        throw new MailDeliveryException('SMTP connect() failed');
    }
};
$keyRow = (new ApiKeyRepository())->findActiveByHash(ApiKeyService::hash($live));
try {
    (new OtpService($failing))->send($keyRow, ['to' => $rcpt('fail'), 'sender_name' => 'Shop'], '127.0.0.1');
    check('failed delivery throws', false);
} catch (ApiException $e) {
    check('failed delivery -> delivery_failed (502)', $e->errorCode === 'delivery_failed' && $e->httpStatus === 502);
}
check('credit refunded after failed delivery', $credits->balance($clientId) === 1);

echo "\nKey revocation & suspension\n";
$keyId = (int) $keyRow['key_id'];
(new ApiKeyRepository())->revoke($clientId, $keyId);
[$s, $r] = api('GET', '/api/v1/balance', $live);
check('revoked key -> 401', $s === 401, $r);
$clients->setStatus($clientId, 'suspended');
[$s, $r] = api('GET', '/api/v1/balance', $test);
check('suspended account -> 403', $s === 403 && $r['error']['code'] === 'account_suspended', $r);

echo "\nWeb dashboard\n";
$jar = tempnam(sys_get_temp_dir(), 'jar');
$web = static function (string $path, ?array $post = null) use ($base, $jar): array {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => true]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $html = (string) curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$status, $html];
};
$csrf = static fn (string $html): string => preg_match('/name="_csrf" value="([^"]+)"/', $html, $m) ? $m[1] : '';
[$s, $html] = $web('/register');
[$s, $html] = $web('/register', ['_csrf' => 'bad', 'company_name' => 'X', 'email' => 'x@x.com', 'password' => '12345678']);
check('POST without valid CSRF -> 419', $s === 419);
[, $html] = $web('/register');
$webEmail = "web-{$suffix}@example.com";
[$s, $html] = $web('/register', ['_csrf' => $csrf($html), 'company_name' => 'Web Shop', 'email' => $webEmail, 'password' => 'password123']);
check('register -> dashboard with signup bonus', $s === 200 && str_contains($html, 'Web Shop') && str_contains($html, 'رصيد ترحيبي'));
[$s, $html] = $web('/keys', ['_csrf' => $csrf($html), 'name' => 'My key', 'mode' => 'live']);
check('create key shows full key once', (bool) preg_match('/udy_live_[0-9A-Za-z]{40}/', $html, $km));
$webKey = $km[0] ?? '';
[$s, $html] = $web('/dashboard');
check('key not shown again on reload', !str_contains($html, $webKey) && str_contains($html, substr($webKey, 0, 14)));
[$s, $r] = api('GET', '/api/v1/balance', $webKey);
check('dashboard key works on API', $s === 200 && $r['data']['company'] === 'Web Shop', $r);
[$s, $html] = $web('/admin');
check('non-admin cannot open /admin -> 403', $s === 403);
[$s, $html] = $web('/docs');
check('docs page renders', $s === 200 && str_contains($html, '/api/v1/otp/send'));

// ---------------------------------------------------------------- cleanup
Database::connection()->exec("DELETE FROM clients WHERE email IN ('owner-{$suffix}@example.com', '{$webEmail}')");
@unlink($jar);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
