<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Services\TestResult;

/** Plivo. */
final class PlivoProvider extends BaseSmsProvider
{
    private const API = 'https://api.plivo.com/v1/Account';

    public static function id(): string
    {
        return 'plivo';
    }

    public static function label(): string
    {
        return 'Plivo';
    }

    public static function limits(): string
    {
        return 'Needs outbound HTTPS. US and Canadian numbers need a registered campaign before they will deliver.';
    }

    public static function configSchema(): array
    {
        return [
            ['key' => 'auth_id', 'label' => 'Auth ID', 'type' => 'text', 'required' => true],
            ['key' => 'auth_token', 'label' => 'Auth token', 'type' => 'password', 'secret' => true, 'required' => true],
            ...self::commonSchema(),
        ];
    }

    private function auth(): array
    {
        return ['Authorization' => self::basic($this->str('auth_id'), $this->str('auth_token'))];
    }

    private function base(): string
    {
        return self::API . '/' . rawurlencode($this->str('auth_id'));
    }

    protected function checkAccount(): TestResult
    {
        $account = $this->get($this->base() . '/', $this->auth());
        if ($account->status === 401) {
            return TestResult::fail('Plivo refused the auth ID and token.');
        }
        if (!$account->ok()) {
            return TestResult::fail("Plivo answered HTTP {$account->status}.");
        }
        return TestResult::ok('Account reached.', ['Cash credits ' . (string) ($account->json()['cash_credits'] ?? '?')]);
    }

    public function send(string $to, string $body): SmsResult
    {
        $response = $this->post($this->base() . '/Message/', [
            'src' => $this->from(),
            'dst' => $to,
            'text' => $body,
        ], $this->auth());
        $json = $response->json() ?? [];
        if (!$response->ok()) {
            throw new SmsError((string) ($json['error'] ?? "Plivo answered HTTP {$response->status}"));
        }
        return new SmsResult((string) ($json['message_uuid'][0] ?? ''));
    }

    /** X-Plivo-Signature-V3: the URL and a nonce, HMAC-SHA256 under the token. */
    public static function signsCallbacks(): bool
    {
        return true;
    }

    public function verifyCallback(string $url, string $rawBody, array $headers): bool
    {
        $given = (string) ($headers['x-plivo-signature-v3'] ?? '');
        $nonce = (string) ($headers['x-plivo-signature-v3-nonce'] ?? '');
        if ($given === '' || $nonce === '') {
            return false;
        }
        $expected = base64_encode(hash_hmac('sha256', $url . $nonce, $this->str('auth_token'), true));
        foreach (explode(',', $given) as $one) {
            if (hash_equals($expected, trim($one))) {
                return true;
            }
        }
        return false;
    }

    public function readReceipt(array $payload): array
    {
        $status = strtolower((string) ($payload['Status'] ?? ''));
        return [
            'messageId' => isset($payload['MessageUUID']) ? (string) $payload['MessageUUID'] : null,
            'status' => match ($status) {
                'delivered' => 'DELIVERED',
                'undelivered', 'failed' => 'FAILED',
                default => null,
            },
            'reason' => isset($payload['ErrorCode']) ? 'Plivo error ' . $payload['ErrorCode'] : null,
        ];
    }

    public function readInbound(array $payload): array
    {
        return ['from' => isset($payload['From']) ? (string) $payload['From'] : null, 'body' => isset($payload['Text']) ? (string) $payload['Text'] : null];
    }
}
