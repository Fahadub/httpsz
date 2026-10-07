<?php

declare(strict_types=1);

namespace Udeyou\Services\Mail;

/** Development carrier: appends the email to a log file instead of sending it. */
final class LogMailCarrier implements MailCarrier
{
    public function __construct(private readonly string $file)
    {
    }

    public function send(MailMessage $message): void
    {
        $entry = sprintf(
            "[%s] To: %s | From: \"%s\" | Subject: %s\n%s\n%s\n",
            gmdate('c'),
            $message->to,
            $message->fromName,
            $message->subject,
            $message->text,
            str_repeat('-', 60)
        );
        if (@file_put_contents($this->file, $entry, FILE_APPEND | LOCK_EX) === false) {
            throw new MailDeliveryException("Cannot write to {$this->file}");
        }
    }
}
