<?php

declare(strict_types=1);

namespace Udeyou\Services\Mail;

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use Udeyou\Core\Config;

/**
 * Sends through the platform's own mailbox (e.g. Hostinger SMTP: smtp.hostinger.com:465/SSL).
 *
 * The "From" ADDRESS is always the platform mailbox (no-reply@udeyou.com) so SPF/DKIM/DMARC pass;
 * only the "From" NAME changes per request, so the inbox shows: "My Store <no-reply@udeyou.com>".
 */
final class SmtpMailCarrier implements MailCarrier
{
    public function send(MailMessage $message): void
    {
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = Config::require('MAIL_HOST');
            $mail->Port       = Config::int('MAIL_PORT', 465);
            $mail->SMTPAuth   = true;
            $mail->Username   = Config::require('MAIL_USERNAME');
            $mail->Password   = Config::require('MAIL_PASSWORD');
            $mail->SMTPSecure = Config::get('MAIL_ENCRYPTION', 'ssl') === 'tls'
                ? PHPMailer::ENCRYPTION_STARTTLS
                : PHPMailer::ENCRYPTION_SMTPS;
            $mail->Timeout    = Config::int('MAIL_TIMEOUT', 15);
            $mail->CharSet    = PHPMailer::CHARSET_UTF8;
            $mail->Encoding   = PHPMailer::ENCODING_BASE64;

            $mail->setFrom(Config::require('MAIL_FROM_ADDRESS'), $message->fromName, false);
            $mail->Sender = Config::require('MAIL_FROM_ADDRESS'); // envelope / Return-Path
            $mail->addAddress($message->to);
            if ($message->replyTo !== null) {
                $mail->addReplyTo($message->replyTo, $message->fromName);
            }

            $mail->isHTML(true);
            $mail->Subject = $message->subject;
            $mail->Body    = $message->html;
            $mail->AltBody = $message->text;
            $mail->addCustomHeader('X-Auto-Response-Suppress', 'All');

            $mail->send();
        } catch (PHPMailerException $e) {
            throw new MailDeliveryException($mail->ErrorInfo ?: $e->getMessage(), 0, $e);
        }
    }
}
