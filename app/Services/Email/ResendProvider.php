<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Core\Http;
use App\Services\BaseProvider;
use App\Services\TestContext;
use App\Services\TestResult;

/** Resend, over HTTPS — what the original sent with. */
final class ResendProvider extends BaseProvider implements EmailProvider
{
    private const API = 'https://api.resend.com';

    public static function slot(): string
    {
        return 'email';
    }

    public static function id(): string
    {
        return 'resend';
    }

    public static function label(): string
    {
        return 'Resend';
    }

    public static function requiresOutboundHttps(): bool
    {
        return true;
    }

    public static function limits(): string
    {
        return 'Needs outbound HTTPS from this host. The "send as" address must be on a domain verified in Resend.';
    }

    public static function configSchema(): array
    {
        return [
            ['key' => 'api_key', 'label' => 'API key', 'type' => 'password', 'secret' => true, 'required' => true, 'help' => 'From resend.com → API Keys, with sending access.'],
            ['key' => 'from', 'label' => 'Send as', 'type' => 'text', 'required' => true, 'help' => 'e.g. Grace Church <notifications@gracechurch.org>'],
        ];
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer ' . $this->str('api_key')];
    }

    public function test(TestContext $context): TestResult
    {
        $steps = [];
        try {
            $domains = Http::json('GET', self::API . '/domains', null, $this->headers());
        } catch (\Throwable $e) {
            return TestResult::fail('Couldn’t reach Resend from this host: ' . $e->getMessage());
        }
        if ($domains->status === 401 || $domains->status === 403) {
            return TestResult::fail('Resend refused the API key.');
        }
        if (!$domains->ok()) {
            return TestResult::fail("Resend answered HTTP {$domains->status}.");
        }
        $steps[] = 'API key accepted';
        $from = $this->str('from');
        $address = preg_match('/<([^>]+)>/', $from, $m) ? $m[1] : $from;
        $domain = strtolower((string) substr(strrchr($address, '@') ?: '', 1));
        $verified = false;
        foreach ((array) ($domains->json()['data'] ?? []) as $d) {
            if (strtolower((string) ($d['name'] ?? '')) === $domain && ($d['status'] ?? '') === 'verified') {
                $verified = true;
            }
        }
        if (!$verified) {
            return TestResult::fail("The domain $domain is not verified in Resend, so messages from $address would be refused.", $steps);
        }
        $steps[] = "$domain is verified";
        $result = $this->send(Message::plain($context->adminEmail, 'Marine Team email test', 'This is the test message from your site’s Resend settings.'), $from);
        if (!$result->ok()) {
            return TestResult::fail('Resend refused the test message: ' . $result->error, $steps);
        }
        $steps[] = "Test message sent to {$context->adminEmail}";
        return TestResult::ok("Sent a test message to {$context->adminEmail}.", $steps);
    }

    public function send(Message $message, string $from): SendResult
    {
        $payload = [
            'from' => $this->str('from', $from),
            'to' => [$message->to],
            'subject' => $message->subject,
            'text' => $message->text,
        ];
        if ($message->html !== null) {
            $payload['html'] = $message->html;
        }
        if ($message->replyTo !== null) {
            $payload['reply_to'] = $message->replyTo;
        }
        try {
            $response = Http::json('POST', self::API . '/emails', $payload, $this->headers());
        } catch (\Throwable $e) {
            return SendResult::failed($e->getMessage());
        }
        if (!$response->ok()) {
            $said = $response->json()['message'] ?? "HTTP {$response->status}";
            return SendResult::failed((string) $said);
        }
        return SendResult::sent((string) ($response->json()['id'] ?? ''));
    }
}
