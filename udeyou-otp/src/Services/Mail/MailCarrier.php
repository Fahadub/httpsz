<?php

declare(strict_types=1);

namespace Udeyou\Services\Mail;

/**
 * Any transport (SMTP, Amazon SES, Postmark, ...) only has to implement this,
 * so swapping providers later never touches the OTP logic.
 */
interface MailCarrier
{
    /** @throws MailDeliveryException */
    public function send(MailMessage $message): void;
}
