<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Services\BaseProvider;
use App\Services\TestContext;
use App\Services\TestResult;

/**
 * PHP's mail(), through the host's own mail transfer agent. mail() returning
 * true proves only that the MTA took the message, so the test sends a code
 * the admin must type back before the switch commits.
 */
final class PhpMailProvider extends BaseProvider implements EmailProvider
{
    public static function slot(): string
    {
        return 'email';
    }

    public static function id(): string
    {
        return 'mail';
    }

    public static function label(): string
    {
        return 'PHP mail() — the host’s own mail server';
    }

    public static function limits(): string
    {
        return 'Delivery depends entirely on the host; messages often land in spam unless the domain’s SPF and DKIM include the host’s servers.';
    }

    public function test(TestContext $context): TestResult
    {
        if (!function_exists('mail')) {
            return TestResult::fail('This host has disabled PHP’s mail() function. Choose SMTP instead.');
        }
        $code = (string) random_int(100000, 999999);
        $result = $this->send(Message::plain(
            $context->adminEmail,
            'Marine Team email test',
            "This is the test message from your site's email settings.\n\nYour confirmation code is: $code",
        ), $context->adminEmail);
        if (!$result->ok()) {
            return TestResult::fail('mail() refused the message: ' . $result->error);
        }
        return TestResult::needsCode(
            "A message was handed to the host's mail server for {$context->adminEmail}. Type the six-digit code it contains to confirm it arrived.",
            $code,
            ['mail() is available', 'Test message accepted by the host'],
        );
    }

    public function send(Message $message, string $from): SendResult
    {
        if (!function_exists('mail')) {
            return SendResult::failed('mail() is disabled on this host.');
        }
        if (preg_match('/[\r\n]/', $from)) {
            return SendResult::failed('Bad sender.');
        }
        $boundary = 'mt-' . bin2hex(random_bytes(12));
        $headers = [
            'From: ' . $from,
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        if ($message->replyTo !== null) {
            $headers[] = 'Reply-To: ' . $message->replyTo;
        }
        $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($message->text))
            . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($message->html ?? htmlspecialchars($message->text)))
            . "--$boundary--\r\n";
        $subject = '=?UTF-8?B?' . base64_encode($message->subject) . '?=';
        $envelope = preg_match('/<([^>]+)>/', $from, $m) ? $m[1] : $from;
        // The envelope sender helps delivery on most hosts; only a plain
        // address is ever passed, since this string reaches sendmail's argv.
        $params = preg_match('/^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/', $envelope) ? '-f' . $envelope : '';
        $ok = @mail($message->to, $subject, $body, implode("\r\n", $headers), $params);
        return $ok ? SendResult::sent() : SendResult::failed(error_get_last()['message'] ?? 'mail() returned false.');
    }
}
