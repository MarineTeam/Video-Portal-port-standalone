<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Services\TestResult;
use App\Support\Jwt;

/** Vonage (formerly Nexmo). */
final class VonageProvider extends BaseSmsProvider
{
    private const API = 'https://rest.nexmo.com';

    public static function id(): string
    {
        return 'vonage';
    }

    public static function label(): string
    {
        return 'Vonage';
    }

    public static function limits(): string
    {
        return 'Needs outbound HTTPS. An alphanumeric sender ID works in much of Europe and not in the US; Vonage rejects the message rather than silently swapping it.';
    }

    public static function configSchema(): array
    {
        return [
            ['key' => 'api_key', 'label' => 'API key', 'type' => 'text', 'required' => true],
            ['key' => 'api_secret', 'label' => 'API secret', 'type' => 'password', 'secret' => true, 'required' => true],
            ...self::commonSchema(),
            ['key' => 'signature_secret', 'label' => 'Signature secret', 'type' => 'password', 'secret' => true, 'help' => 'From the dashboard, for checking delivery receipts and replies. Without it those callbacks are refused.'],
        ];
    }

    protected function checkAccount(): TestResult
    {
        $balance = $this->get(self::API . '/account/get-balance?' . http_build_query([
            'api_key' => $this->str('api_key'),
            'api_secret' => $this->str('api_secret'),
        ]));
        if ($balance->status === 401) {
            return TestResult::fail('Vonage refused the API key and secret.');
        }
        if (!$balance->ok()) {
            return TestResult::fail("Vonage answered HTTP {$balance->status}.");
        }
        $left = (float) ($balance->json()['value'] ?? 0);
        $steps = ['Account reached, balance ' . number_format($left, 2)];
        if ($left <= 0) {
            return TestResult::fail('This Vonage account has no balance, so nothing would be delivered.', $steps);
        }
        return TestResult::ok('Account reached.', $steps);
    }

    public function send(string $to, string $body): SmsResult
    {
        $response = $this->post(self::API . '/sms/json', [
            'api_key' => $this->str('api_key'),
            'api_secret' => $this->str('api_secret'),
            'to' => ltrim($to, '+'),
            'from' => $this->from(),
            'text' => $body,
            'type' => preg_match('/[^\x00-\x7F]/', $body) === 1 ? 'unicode' : 'text',
        ], [], true);
        $message = ($response->json()['messages'][0] ?? null);
        if (!is_array($message)) {
            throw new SmsError("Vonage answered HTTP {$response->status} with nothing to read.");
        }
        // Vonage answers 200 whatever happened; status 0 is the only success.
        $status = (string) ($message['status'] ?? '');
        if ($status !== '0') {
            $said = (string) ($message['error-text'] ?? "Vonage status $status");
            throw in_array($status, ['3', '6', '15'], true) ? SmsError::permanent($said, $status) : new SmsError($said, $status);
        }
        return new SmsResult((string) ($message['message-id'] ?? ''));
    }

    /** A signed JWT in the Authorization header, under the signature secret. */
    public static function signsCallbacks(): bool
    {
        return true;
    }

    public function verifyCallback(string $url, string $rawBody, array $headers): bool
    {
        $secret = $this->str('signature_secret');
        $header = (string) ($headers['authorization'] ?? '');
        if ($secret === '' || !str_starts_with(strtolower($header), 'bearer ')) {
            return false;
        }
        try {
            $payload = Jwt::verify(substr($header, 7), [], ['HS256'], $secret);
        } catch (\Throwable) {
            return false;
        }
        // Vonage puts a hash of the body in the token, which is what ties the
        // signature to this particular callback rather than any of them.
        $hash = (string) ($payload['payload_hash'] ?? '');
        return $hash === '' || hash_equals($hash, hash('sha256', $rawBody));
    }

    public function readReceipt(array $payload): array
    {
        $status = strtolower((string) ($payload['status'] ?? ''));
        return [
            'messageId' => isset($payload['messageId']) ? (string) $payload['messageId'] : (isset($payload['message-id']) ? (string) $payload['message-id'] : null),
            'status' => match ($status) {
                'delivered' => 'DELIVERED',
                'failed', 'rejected', 'expired' => 'FAILED',
                default => null,
            },
            'reason' => isset($payload['err-code']) ? 'Vonage error ' . $payload['err-code'] : null,
        ];
    }

    public function readInbound(array $payload): array
    {
        return ['from' => isset($payload['msisdn']) ? '+' . ltrim((string) $payload['msisdn'], '+') : null, 'body' => isset($payload['text']) ? (string) $payload['text'] : null];
    }
}
