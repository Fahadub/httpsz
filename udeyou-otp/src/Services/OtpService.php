<?php

declare(strict_types=1);

namespace Udeyou\Services;

use PDO;
use Udeyou\Core\Clock;
use Udeyou\Core\Config;
use Udeyou\Core\Database;
use Udeyou\Repositories\OtpRepository;
use Udeyou\Services\Mail\MailCarrier;
use Udeyou\Services\Mail\MailDeliveryException;
use Udeyou\Services\Mail\MailMessage;

final class OtpService
{
    public function __construct(
        private readonly MailCarrier $carrier,
        private readonly OtpRepository $otps = new OtpRepository(),
        private readonly CreditService $credits = new CreditService(),
    ) {
    }

    /**
     * Validate -> rate-limit -> reserve credit -> send email -> confirm (or refund).
     *
     * @param array $key  authenticated key row (key_id, client_id, mode, ...)
     * @throws ApiException
     */
    public function send(array $key, array $input, string $ip): array
    {
        $data = $this->validateSendInput($input);
        $clientId = (int) $key['client_id'];
        $mode = $key['mode'];

        $this->enforceRateLimits($clientId, $data['to']);

        $code = $data['code'] ?? self::generateCode($data['code_length']);
        $publicId = 'otp_' . bin2hex(random_bytes(12));
        $row = [
            'public_id'       => $publicId,
            'client_id'       => $clientId,
            'api_key_id'      => (int) $key['key_id'],
            'mode'            => $mode,
            'recipient_email' => $data['to'],
            'sender_name'     => $data['sender_name'],
            'code_hash'       => self::hashCode($publicId, $code),
            'status'          => 'pending',
            'max_attempts'    => Config::int('OTP_MAX_ATTEMPTS', 5),
            'expires_at'      => Clock::plusSeconds($data['expires_in']),
            'ip_address'      => $ip,
            'created_at'      => Clock::now(),
        ];

        // Test keys: no email, no charge — the code is returned in the response instead.
        if ($mode === 'test') {
            $otpId = $this->otps->create($row);
            $this->otps->markSent($otpId);
            $this->otps->expireOthersForRecipient($clientId, $mode, $data['to'], $otpId);
            return $this->sendResult($row, $data, $this->credits->balance($clientId)) + ['test_mode' => true, 'code' => $code];
        }

        // Live: reserve the credit and create the record atomically.
        $otpId = Database::transaction(function (PDO $pdo) use ($clientId, $publicId, $row) {
            if (!$this->credits->reserve($pdo, $clientId, $publicId)) {
                throw new ApiException('insufficient_credits', 'Your balance is empty. Top up your credits to keep sending.', 402);
            }
            return $this->otps->create($row);
        });

        $email = OtpTemplate::render($code, $data['sender_name'], $data['expires_in'], $data['lang'], $data['template'], $data['subject']);

        try {
            $this->carrier->send(new MailMessage(
                to: $data['to'],
                fromName: $data['sender_name'],
                subject: $email['subject'],
                html: $email['html'],
                text: $email['text'],
                replyTo: $data['reply_to'],
            ));
        } catch (MailDeliveryException $e) {
            $this->otps->markFailed($otpId, $e->getMessage());
            $this->credits->refund($clientId, $publicId);
            error_log("[udeyou] OTP {$publicId} delivery failed: " . $e->getMessage());
            throw new ApiException('delivery_failed', 'The email could not be delivered. No credit was charged. Try again later.', 502);
        }

        $this->otps->markSent($otpId);
        $this->otps->expireOthersForRecipient($clientId, $mode, $data['to'], $otpId);

        return $this->sendResult($row, $data, $this->credits->balance($clientId));
    }

    /** @throws ApiException */
    public function verify(array $key, array $input): array
    {
        $code = is_scalar($input['code'] ?? null) ? trim((string) $input['code']) : '';
        if (!preg_match('/^[0-9A-Za-z]{4,10}$/', $code)) {
            throw new ApiException('validation_error', 'Field "code" is required (4-10 letters/digits).', 422);
        }

        $clientId = (int) $key['client_id'];
        $otpId = is_string($input['otp_id'] ?? null) ? trim($input['otp_id']) : '';
        $to = is_string($input['to'] ?? null) ? strtolower(trim($input['to'])) : '';

        if ($otpId !== '') {
            $otp = $this->otps->findByPublicId($clientId, $key['mode'], $otpId);
        } elseif ($to !== '') {
            $otp = $this->otps->findLatestForRecipient($clientId, $key['mode'], $to);
        } else {
            throw new ApiException('validation_error', 'Send either "otp_id" or "to" with the code.', 422);
        }

        if ($otp === null || $otp['status'] === 'failed' || $otp['status'] === 'pending') {
            throw new ApiException('otp_not_found', 'No OTP found for this request.', 404);
        }
        if ($otp['status'] === 'verified') {
            throw new ApiException('otp_already_used', 'This code was already used.', 409);
        }
        if ($otp['expires_at'] <= Clock::now()) {
            throw new ApiException('otp_expired', 'This code has expired. Request a new one.', 410);
        }
        if (!$this->otps->consumeAttempt((int) $otp['id'])) {
            throw new ApiException('too_many_attempts', 'Too many wrong attempts. Request a new code.', 429);
        }

        if (!hash_equals($otp['code_hash'], self::hashCode($otp['public_id'], $code))) {
            $remaining = max(0, (int) $otp['max_attempts'] - (int) $otp['attempts'] - 1);
            throw new ApiException('invalid_code', 'The code is incorrect.', 400, ['attempts_remaining' => $remaining]);
        }

        if (!$this->otps->markVerified((int) $otp['id'])) {
            throw new ApiException('otp_already_used', 'This code was already used.', 409);
        }

        return [
            'verified'    => true,
            'otp_id'      => $otp['public_id'],
            'to'          => $otp['recipient_email'],
            'verified_at' => Clock::toIso(Clock::now()),
        ];
    }

    public static function generateCode(int $length): string
    {
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= random_int(0, 9);
        }
        return $code;
    }

    /** HMAC bound to the OTP id, so a hash can't be reused across records or brute-forced offline without the secret. */
    private static function hashCode(string $publicId, string $code): string
    {
        return hash_hmac('sha256', $publicId . '|' . $code, Config::require('APP_SECRET'));
    }

    private function enforceRateLimits(int $clientId, string $to): void
    {
        $perMinute = Config::int('RATE_LIMIT_PER_MINUTE', 60);
        if ($this->otps->countForClientSince($clientId, Clock::minusSeconds(60)) >= $perMinute) {
            throw new ApiException('rate_limited', "Rate limit reached ({$perMinute} requests/minute).", 429, ['retry_after' => 60]);
        }

        $cooldown = Config::int('OTP_RESEND_COOLDOWN', 60);
        if ($cooldown > 0 && $this->otps->countForRecipientSince($clientId, $to, Clock::minusSeconds($cooldown)) > 0) {
            throw new ApiException('resend_too_soon', "Wait {$cooldown} seconds before sending another code to this address.", 429, ['retry_after' => $cooldown]);
        }

        $perHour = Config::int('OTP_MAX_PER_RECIPIENT_HOUR', 5);
        if ($this->otps->countForRecipientSince($clientId, $to, Clock::minusSeconds(3600)) >= $perHour) {
            throw new ApiException('recipient_limit', "Max {$perHour} codes per hour for the same address.", 429, ['retry_after' => 3600]);
        }
    }

    private function sendResult(array $row, array $data, int $balance): array
    {
        return [
            'otp_id'            => $row['public_id'],
            'to'                => $row['recipient_email'],
            'status'            => 'sent',
            'mode'              => $row['mode'],
            'expires_in'        => $data['expires_in'],
            'expires_at'        => Clock::toIso($row['expires_at']),
            'credits_remaining' => $balance,
        ];
    }

    /** @throws ApiException */
    private function validateSendInput(array $in): array
    {
        $errors = [];
        $str = static fn (string $k): ?string => is_string($in[$k] ?? null) ? trim($in[$k]) : null;

        $to = strtolower($str('to') ?? '');
        if ($to === '' || strlen($to) > 190 || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $errors['to'] = 'A valid recipient email is required.';
        }

        // Strip control chars (CR/LF header injection) and collapse whitespace.
        $senderName = trim(preg_replace('/[\s\x00-\x1F\x7F]+/u', ' ', $str('sender_name') ?? '') ?? '');
        if (mb_strlen($senderName) < 2 || mb_strlen($senderName) > 60) {
            $errors['sender_name'] = 'sender_name is required (2-60 characters), e.g. your store name.';
        }

        $template = $str('template');
        if ($template !== null && $template !== '') {
            if (mb_strlen($template) > 1000) {
                $errors['template'] = 'template must be at most 1000 characters.';
            } elseif (!str_contains($template, '{{code}}')) {
                $errors['template'] = 'template must contain the {{code}} placeholder.';
            }
        } else {
            $template = null;
        }

        $subject = $str('subject');
        $subject = $subject === null || $subject === '' ? null : trim(preg_replace('/[\s\x00-\x1F\x7F]+/u', ' ', $subject) ?? '');
        if ($subject !== null && mb_strlen($subject) > 120) {
            $errors['subject'] = 'subject must be at most 120 characters.';
        }

        $replyTo = $str('reply_to');
        if ($replyTo === '') {
            $replyTo = null;
        }
        if ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL) === false) {
            $errors['reply_to'] = 'reply_to must be a valid email.';
        }

        $code = isset($in['code']) && is_scalar($in['code']) ? trim((string) $in['code']) : null;
        if ($code !== null && !preg_match('/^[0-9A-Za-z]{4,10}$/', $code)) {
            $errors['code'] = 'code must be 4-10 letters/digits (or omit it and we generate one).';
        }

        $codeLength = $in['code_length'] ?? Config::int('OTP_LENGTH', 6);
        if (!is_int($codeLength) || $codeLength < 4 || $codeLength > 10) {
            $errors['code_length'] = 'code_length must be an integer between 4 and 10.';
        }

        $expiresIn = $in['expires_in'] ?? Config::int('OTP_TTL', 300);
        if (!is_int($expiresIn) || $expiresIn < 60 || $expiresIn > 3600) {
            $errors['expires_in'] = 'expires_in must be an integer between 60 and 3600 seconds.';
        }

        $lang = $str('lang') ?? 'ar';
        if (!in_array($lang, ['ar', 'en'], true)) {
            $errors['lang'] = 'lang must be "ar" or "en".';
        }

        if ($errors) {
            throw new ApiException('validation_error', 'Some fields are invalid.', 422, ['fields' => $errors]);
        }

        return [
            'to'          => $to,
            'sender_name' => $senderName,
            'template'    => $template,
            'subject'     => $subject,
            'reply_to'    => $replyTo,
            'code'        => $code,
            'code_length' => $codeLength,
            'expires_in'  => $expiresIn,
            'lang'        => $lang,
        ];
    }
}
