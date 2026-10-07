<?php

declare(strict_types=1);

namespace Udeyou\Services\Mail;

final class MailMessage
{
    public function __construct(
        public readonly string $to,
        public readonly string $fromName,   // dynamic: the client's store name
        public readonly string $subject,
        public readonly string $html,
        public readonly string $text,
        public readonly ?string $replyTo = null,
    ) {
    }
}
