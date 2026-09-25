<?php

declare(strict_types=1);

namespace App\Services\Email;

/**
 * One email. Addresses and the subject are checked for CR and LF at
 * construction — the header-injection path mail() and SMTP both have.
 */
final class Message
{
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $text,
        public readonly ?string $html = null,
        public readonly ?string $replyTo = null,
        /** Carries a live token (a reset or sign-in link): its body is never logged. */
        public readonly bool $sensitive = false,
    ) {
        foreach ([$to, $subject, (string) $replyTo] as $header) {
            if (preg_match('/[\r\n\0]/', $header)) {
                throw new \InvalidArgumentException('Email headers may not contain line breaks.');
            }
        }
        if (!\App\Core\Validator::isEmail($to) || ($replyTo !== null && !\App\Core\Validator::isEmail($replyTo))) {
            throw new \InvalidArgumentException('Not an email address.');
        }
    }

    /**
     * A message from a plain-text body, with the HTML version escaped from it
     * — the only way untrusted text reaches an email body.
     */
    public static function plain(string $to, string $subject, string $text, ?string $actionUrl = null, ?string $actionLabel = null, bool $sensitive = false): self
    {
        $html = '<div style="font-family:system-ui,sans-serif;font-size:15px;line-height:1.5;color:#18181b">'
            . nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        if ($actionUrl !== null) {
            $html .= '<p style="margin-top:20px"><a href="' . htmlspecialchars($actionUrl, ENT_QUOTES) . '" style="background:#0288d1;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none">'
                . htmlspecialchars($actionLabel ?? 'Open', ENT_QUOTES) . '</a></p>';
            $text .= "\n\n" . ($actionLabel ?? 'Open') . ': ' . $actionUrl;
        }
        $html .= '</div>';
        return new self($to, $subject, $text, $html, null, $sensitive);
    }
}
