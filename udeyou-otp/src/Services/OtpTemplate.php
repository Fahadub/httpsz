<?php

declare(strict_types=1);

namespace Udeyou\Services;

use Udeyou\Core\Config;

/**
 * Renders the OTP email. Client templates are PLAIN TEXT with placeholders
 * ({{code}}, {{sender_name}}, {{minutes}}); they are HTML-escaped so the platform
 * cannot be abused to send arbitrary HTML/phishing content from our domain.
 */
final class OtpTemplate
{
    private const DEFAULTS = [
        'ar' => [
            'subject' => 'رمز التحقق الخاص بك - {{sender_name}}',
            'body'    => "مرحباً،\n\nرمز التحقق الخاص بك في {{sender_name}} هو:\n{{code}}\n\nالرمز صالح لمدة {{minutes}} دقائق. لا تشارك هذا الرمز مع أي شخص.\nإذا لم تطلب هذا الرمز فتجاهل هذه الرسالة.",
            'footer'  => 'أُرسلت هذه الرسالة نيابةً عن {{sender_name}} عبر {{platform}}.',
        ],
        'en' => [
            'subject' => 'Your verification code - {{sender_name}}',
            'body'    => "Hello,\n\nYour {{sender_name}} verification code is:\n{{code}}\n\nThis code expires in {{minutes}} minutes. Never share it with anyone.\nIf you didn't request this code, you can ignore this email.",
            'footer'  => 'Sent on behalf of {{sender_name}} via {{platform}}.',
        ],
    ];

    public static function render(
        string $code,
        string $senderName,
        int $ttlSeconds,
        string $lang,
        ?string $customBody,
        ?string $customSubject,
    ): array {
        $lang = isset(self::DEFAULTS[$lang]) ? $lang : 'ar';
        $vars = [
            '{{sender_name}}' => $senderName,
            '{{minutes}}'     => (string) max(1, intdiv($ttlSeconds, 60)),
            '{{platform}}'    => Config::get('APP_NAME', 'Udeyou OTP'),
        ];

        $subject = strtr($customSubject ?? self::DEFAULTS[$lang]['subject'], $vars + ['{{code}}' => $code]);
        $body    = $customBody ?? self::DEFAULTS[$lang]['body'];
        $footer  = strtr(self::DEFAULTS[$lang]['footer'], $vars);

        $text = strtr($body, $vars + ['{{code}}' => $code]) . "\n\n--\n" . $footer;

        // HTML: escape everything first, then inject the (digits/letters only) code with styling.
        $codeHtml = '<span style="display:inline-block;font-size:30px;font-weight:bold;letter-spacing:6px;'
                  . 'padding:10px 18px;margin:8px 0;background:#f2f4f7;border-radius:8px;color:#111;direction:ltr">'
                  . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</span>';
        $escapedBody = htmlspecialchars(strtr($body, $vars), ENT_QUOTES, 'UTF-8');
        $bodyHtml = nl2br(str_replace(htmlspecialchars('{{code}}', ENT_QUOTES, 'UTF-8'), $codeHtml, $escapedBody));

        $senderHtml = htmlspecialchars($senderName, ENT_QUOTES, 'UTF-8');
        $footerHtml = htmlspecialchars($footer, ENT_QUOTES, 'UTF-8');
        $dir = $lang === 'ar' ? 'rtl' : 'ltr';
        $html = <<<HTML
<!doctype html>
<html lang="{$lang}" dir="{$dir}">
<body style="margin:0;padding:24px;background:#f6f7f9;font-family:Tahoma,Arial,sans-serif;">
  <div style="max-width:480px;margin:auto;background:#fff;border-radius:12px;padding:28px;color:#222;font-size:15px;line-height:1.7;text-align:start">
    <h2 style="margin-top:0;font-size:18px">{$senderHtml}</h2>
    <div>{$bodyHtml}</div>
    <hr style="border:none;border-top:1px solid #eee;margin:24px 0 12px">
    <div style="font-size:12px;color:#888">{$footerHtml}</div>
  </div>
</body>
</html>
HTML;

        return ['subject' => $subject, 'html' => $html, 'text' => $text];
    }
}
