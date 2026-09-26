<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Services\TestResult;

/** ClickSend. */
final class ClickSendProvider extends BaseSmsProvider
{
    private const API = 'https://rest.clicksend.com/v3';

    public static function id(): string
    {
        return 'clicksend';
    }

    public static function label(): string
    {
        return 'ClickSend';
    }

    public static function limits(): string
    {
        return 'Needs outbound HTTPS. Its callbacks are not signed, so this app gives it a secret in the callback address instead.';
    }

    public static function configSchema(): array
    {
        return [
            ['key' => 'username', 'label' => 'Username', 'type' => 'text', 'required' => true],
            ['key' => 'api_key', 'label' => 'API key', 'type' => 'password', 'secret' => true, 'required' => true],
            ...self::commonSchema(),
        ];
    }

    private function auth(): array
    {
        return ['Authorization' => self::basic($this->str('username'), $this->str('api_key'))];
    }

    protected function checkAccount(): TestResult
    {
        $account = $this->get(self::API . '/account', $this->auth());
        if ($account->status === 401 || $account->status === 403) {
            return TestResult::fail('ClickSend refused the username and API key.');
        }
        if (!$account->ok()) {
            return TestResult::fail("ClickSend answered HTTP {$account->status}.");
        }
        $data = (array) ($account->json()['data'] ?? []);
        return TestResult::ok('Account reached.', ['Balance ' . (string) ($data['balance'] ?? '?') . ' ' . (string) ($data['currency']['currency_name_short'] ?? '')]);
    }

    public function send(string $to, string $body): SmsResult
    {
        $response = $this->post(self::API . '/sms/send', [
            'messages' => [['source' => 'php', 'from' => $this->from(), 'to' => $to, 'body' => $body]],
        ], $this->auth());
        $json = $response->json() ?? [];
        $message = (array) ($json['data']['messages'][0] ?? []);
        if (!$response->ok() || strtoupper((string) ($message['status'] ?? '')) !== 'SUCCESS') {
            $said = (string) ($message['status'] ?? $json['response_msg'] ?? "ClickSend answered HTTP {$response->status}");
            throw new SmsError($said);
        }
        return new SmsResult((string) ($message['message_id'] ?? ''));
    }

    public function readReceipt(array $payload): array
    {
        $status = strtolower((string) ($payload['status'] ?? ''));
        return [
            'messageId' => isset($payload['message_id']) ? (string) $payload['message_id'] : null,
            'status' => match ($status) {
                'delivered' => 'DELIVERED',
                'failed', 'undelivered' => 'FAILED',
                default => null,
            },
            'reason' => isset($payload['error_code']) ? 'ClickSend error ' . $payload['error_code'] : null,
        ];
    }

    public function readInbound(array $payload): array
    {
        return ['from' => isset($payload['from']) ? (string) $payload['from'] : null, 'body' => isset($payload['body']) ? (string) $payload['body'] : (isset($payload['message']) ? (string) $payload['message'] : null)];
    }
}
