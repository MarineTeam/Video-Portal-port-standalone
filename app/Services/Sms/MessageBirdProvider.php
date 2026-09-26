<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Services\TestResult;

/** MessageBird, now Bird. */
final class MessageBirdProvider extends BaseSmsProvider
{
    private const API = 'https://rest.messagebird.com';

    public static function id(): string
    {
        return 'messagebird';
    }

    public static function label(): string
    {
        return 'MessageBird (Bird)';
    }

    public static function limits(): string
    {
        return 'Needs outbound HTTPS. Sender IDs must be registered in most countries; MessageBird substitutes a number where they are not allowed.';
    }

    public static function configSchema(): array
    {
        return [
            ['key' => 'access_key', 'label' => 'Live access key', 'type' => 'password', 'secret' => true, 'required' => true],
            ...self::commonSchema(),
            ['key' => 'signing_key', 'label' => 'Signing key', 'type' => 'password', 'secret' => true, 'help' => 'From Developers → Settings, for checking delivery receipts and replies.'],
        ];
    }

    private function auth(): array
    {
        return ['Authorization' => 'AccessKey ' . $this->str('access_key')];
    }

    protected function checkAccount(): TestResult
    {
        $balance = $this->get(self::API . '/balance', $this->auth());
        if ($balance->status === 401) {
            return TestResult::fail('MessageBird refused the access key.');
        }
        if (!$balance->ok()) {
            return TestResult::fail("MessageBird answered HTTP {$balance->status}.");
        }
        $json = $balance->json() ?? [];
        return TestResult::ok('Account reached.', ['Balance ' . (string) ($json['amount'] ?? '?') . ' ' . (string) ($json['type'] ?? '')]);
    }

    public function send(string $to, string $body): SmsResult
    {
        $response = $this->post(self::API . '/messages', [
            'originator' => $this->from(),
            'recipients' => [$to],
            'body' => $body,
        ], $this->auth());
        $json = $response->json() ?? [];
        if (!$response->ok()) {
            $said = (string) ($json['errors'][0]['description'] ?? "MessageBird answered HTTP {$response->status}");
            $code = isset($json['errors'][0]['code']) ? (string) $json['errors'][0]['code'] : null;
            throw $code === '21' ? SmsError::permanent($said, $code) : new SmsError($said, $code);
        }
        return new SmsResult((string) ($json['id'] ?? ''));
    }

    /**
     * MessageBird signs the request with a timestamp and a body hash. The
     * timestamp is checked five minutes either way, so a signature somebody
     * kept cannot be replayed.
     */
    public static function signsCallbacks(): bool
    {
        return true;
    }

    public function verifyCallback(string $url, string $rawBody, array $headers): bool
    {
        $key = $this->str('signing_key');
        $given = (string) ($headers['messagebird-signature'] ?? '');
        $timestamp = (string) ($headers['messagebird-request-timestamp'] ?? '');
        if ($key === '' || $given === '' || $timestamp === '') {
            return false;
        }
        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }
        $query = (string) (parse_url($url, PHP_URL_QUERY) ?? '');
        $parts = [$timestamp, $query, hash('sha256', $rawBody, true)];
        return hash_equals(base64_encode(hash_hmac('sha256', implode("\n", $parts), $key, true)), $given);
    }

    public function readReceipt(array $payload): array
    {
        $status = strtolower((string) ($payload['status'] ?? ''));
        return [
            'messageId' => isset($payload['id']) ? (string) $payload['id'] : null,
            'status' => match ($status) {
                'delivered' => 'DELIVERED',
                'delivery_failed', 'expired', 'rejected' => 'FAILED',
                default => null,
            },
            'reason' => isset($payload['statusReason']) ? (string) $payload['statusReason'] : null,
        ];
    }

    public function readInbound(array $payload): array
    {
        return ['from' => isset($payload['originator']) ? (string) $payload['originator'] : null, 'body' => isset($payload['body']) ? (string) $payload['body'] : null];
    }
}
