# Udeyou OTP — notes for Claude Code

Vanilla PHP 8.1+ (no framework), MySQL, PHPMailer. PSR-4 namespace `Udeyou\` → `src/`.

## Run locally
```bash
composer install
cp .env.example .env   # set APP_SECRET, DB_*, MAIL_DRIVER=log, APP_ENV=local, SESSION_SECURE_COOKIE=false
php bin/install.php    # creates tables from database/schema.sql
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8080 -t public public/index.php
```
No MySQL? `apt-get install -y mariadb-server && mysqld_safe &` then create the DB/user.

## Test
`php tests/run.php [base-url]` — end-to-end against the running server; needs `MAIL_DRIVER=log`
(emails land in `storage/logs/mail.log`). Must print `0 failed`.

## Conventions
- Routes live in `public/index.php`. API errors: throw `Services\ApiException(code, message, status)`.
- SQL only in `src/Repositories` (or `CreditService` for balance changes); always prepared statements.
- All times UTC `Y-m-d H:i:s` via `Core\Clock`.
- Never store plain API keys or OTP codes (sha256 / HMAC only).
- New mail provider = new class implementing `Services\Mail\MailCarrier` + a case in `MailCarrierFactory`.
