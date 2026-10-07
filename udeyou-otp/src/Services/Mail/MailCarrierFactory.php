<?php

declare(strict_types=1);

namespace Udeyou\Services\Mail;

use Udeyou\Core\Config;

final class MailCarrierFactory
{
    /** MAIL_DRIVER=smtp (production) or log (local testing: writes emails to storage/logs/mail.log). */
    public static function make(): MailCarrier
    {
        return match (Config::get('MAIL_DRIVER', 'smtp')) {
            'log'   => new LogMailCarrier(dirname(__DIR__, 3) . '/storage/logs/mail.log'),
            default => new SmtpMailCarrier(),
        };
    }
}
