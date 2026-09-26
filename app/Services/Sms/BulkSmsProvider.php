<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Services\TestResult;

/** BulkSMS. */
final class BulkSmsProvider extends BaseSmsProvider
{
    private const API = 'https://api.bulksms.com/v1';

    public static function id(): string
    {
        return 'bulksms';
    }

    public static function label(): string
    {
        return 'BulkSMS';
    }

    public static function limits(): string
    {
        return 'Needs outbound HTTPS. Its callbacks are not signed, so this app gives it a secret in the callback address instead.';
    }

    public static function configSchema(): array
    {
        return [
            ['key' => 'username', 'label' => 'Username or token ID', 'type' => 'text', 'required' => true],
            ['key' => 'password', 'label' => 'Password or token secret', 'type' => 'password', 'secret' => true, 'required' => true],
            ...self::commonSchema(),
        ];
    }

    private function auth(): array
    {
        return ['Authorization' => self::basic($this->str('username'), $this->str('password'))];
    }

    protected function checkAccount(): TestResult
    {
        $profile = $this->get(self::API . '/profile', $this->auth());
        if ($profile->status === 401 || $profile->status === 403) {
            return TestResult::fail('BulkSMS refused the username and password.');
        }
        if (!$profile->ok()) {
            return TestResult::fail("BulkSMS answered HTTP {$profile->status}.");
        }
        $json = $profile->json() ?? [];
        return TestResult::ok('Account reached.', ['Credits ' . (string) ($json['credits']['balance'] ?? '?')]);
    }

    public function send(string $to, string $body): SmsResult
    {
        $response = $this->post(self::API . '/messages', [
            'to' => $to,
            'from' => $this->from(),
            'body' => $body,
        ], $this->auth());
        $json = $response->json() ?? [];
        if (!$response->ok()) {
            $said = (string) ($json['detail'] ?? $json['title'] ?? "BulkSMS answered HTTP {$response->status}");
            throw new SmsError($said);
        }
        return new SmsResult((string) ($json[0]['id'] ?? $json['id'] ?? ''));
    }

    public function readReceipt(array $payload): array
    {
        $status = strtoupper((string) ($payload['status']['type'] ?? $payload['status'] ?? ''));
        return [
            'messageId' => isset($payload['id']) ? (string) $payload['id'] : null,
            'status' => match ($status) {
                'DELIVERED' => 'DELIVERED',
                'FAILED' => 'FAILED',
                default => null,
            },
            'reason' => isset($payload['status']['subtype']) ? (string) $payload['status']['subtype'] : null,
        ];
    }

    public function readInbound(array $payload): array
    {
        return ['from' => isset($payload['from']) ? (string) $payload['from'] : null, 'body' => isset($payload['body']) ? (string) $payload['body'] : null];
    }
}
