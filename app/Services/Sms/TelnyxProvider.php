<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Services\TestResult;
use App\Support\Jwt;

/** Telnyx. Its callbacks are signed with Ed25519, which PHP can check. */
final class TelnyxProvider extends BaseSmsProvider
{
    private const API = 'https://api.telnyx.com/v2';

    public static function id(): string
    {
        return 'telnyx';
    }

    public static function label(): string
    {
        return 'Telnyx';
    }

    public static function limits(): string
    {
        return 'Needs outbound HTTPS. Checking its signed callbacks needs PHP’s sodium extension, which nearly every host has; without it the callbacks are refused rather than trusted.';
    }

    public static function configSchema(): array
    {
        return [
            ['key' => 'api_key', 'label' => 'API key', 'type' => 'password', 'secret' => true, 'required' => true],
            ['key' => 'messaging_profile_id', 'label' => 'Messaging profile ID', 'type' => 'text', 'help' => 'Optional; needed when sending from a number pool rather than one number.'],
            ...self::commonSchema(),
            ['key' => 'public_key', 'label' => 'Webhook public key', 'type' => 'text', 'help' => 'From the portal, for checking delivery receipts and replies.'],
        ];
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer ' . $this->str('api_key')];
    }

    protected function checkAccount(): TestResult
    {
        $balance = $this->get(self::API . '/balance', $this->auth());
        if ($balance->status === 401) {
            return TestResult::fail('Telnyx refused the API key.');
        }
        if (!$balance->ok()) {
            return TestResult::fail("Telnyx answered HTTP {$balance->status}.");
        }
        $data = (array) ($balance->json()['data'] ?? []);
        return TestResult::ok('Account reached.', ['Balance ' . (string) ($data['balance'] ?? '?') . ' ' . (string) ($data['currency'] ?? '')]);
    }

    public function send(string $to, string $body): SmsResult
    {
        $payload = ['from' => $this->from(), 'to' => $to, 'text' => $body];
        if ($this->str('messaging_profile_id') !== '') {
            $payload['messaging_profile_id'] = $this->str('messaging_profile_id');
        }
        $response = $this->post(self::API . '/messages', $payload, $this->auth());
        $json = $response->json() ?? [];
        if (!$response->ok()) {
            $error = (array) ($json['errors'][0] ?? []);
            $said = (string) ($error['detail'] ?? $error['title'] ?? "Telnyx answered HTTP {$response->status}");
            throw new SmsError($said, isset($error['code']) ? (string) $error['code'] : null);
        }
        return new SmsResult((string) ($json['data']['id'] ?? ''));
    }

    /** Ed25519 over "timestamp|body", with the timestamp five minutes either way. */
    public static function signsCallbacks(): bool
    {
        return true;
    }

    public function verifyCallback(string $url, string $rawBody, array $headers): bool
    {
        $key = $this->str('public_key');
        $signature = (string) ($headers['telnyx-signature-ed25519'] ?? '');
        $timestamp = (string) ($headers['telnyx-timestamp'] ?? '');
        if ($key === '' || $signature === '' || $timestamp === '' || !function_exists('sodium_crypto_sign_verify_detached')) {
            return false;
        }
        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }
        $raw = base64_decode($signature, true);
        $public = base64_decode($key, true);
        if ($raw === false || $public === false || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }
        try {
            return sodium_crypto_sign_verify_detached($raw, $timestamp . '|' . $rawBody, $public);
        } catch (\Throwable) {
            return false;
        }
    }

    public function readReceipt(array $payload): array
    {
        $data = (array) ($payload['data']['payload'] ?? []);
        $status = strtolower((string) ($data['to'][0]['status'] ?? ''));
        return [
            'messageId' => isset($data['id']) ? (string) $data['id'] : null,
            'status' => match ($status) {
                'delivered' => 'DELIVERED',
                'delivery_failed', 'sending_failed' => 'FAILED',
                default => null,
            },
            'reason' => isset($data['errors'][0]['detail']) ? (string) $data['errors'][0]['detail'] : null,
        ];
    }

    public function readInbound(array $payload): array
    {
        $data = (array) ($payload['data']['payload'] ?? []);
        return ['from' => isset($data['from']['phone_number']) ? (string) $data['from']['phone_number'] : null, 'body' => isset($data['text']) ? (string) $data['text'] : null];
    }
}
