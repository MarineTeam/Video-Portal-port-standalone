<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Services\TestResult;

/** Textlocal — common in the UK and India. */
final class TextlocalProvider extends BaseSmsProvider
{
    private const API = 'https://api.txtlocal.com';

    public static function id(): string
    {
        return 'textlocal';
    }

    public static function label(): string
    {
        return 'Textlocal';
    }

    public static function limits(): string
    {
        return 'Needs outbound HTTPS. The sender name must be one registered in the Textlocal account; its callbacks are not signed, so this app gives it a secret in the callback address instead.';
    }

    public static function configSchema(): array
    {
        return [
            ['key' => 'api_key', 'label' => 'API key', 'type' => 'password', 'secret' => true, 'required' => true],
            ...self::commonSchema(),
        ];
    }

    protected function checkAccount(): TestResult
    {
        $balance = $this->post(self::API . '/balance/', ['apikey' => $this->str('api_key')], [], true);
        $json = $balance->json() ?? [];
        if (($json['status'] ?? '') !== 'success') {
            return TestResult::fail((string) ($json['errors'][0]['message'] ?? "Textlocal answered HTTP {$balance->status}."));
        }
        $left = (int) ($json['balance']['sms'] ?? 0);
        $steps = ["Account reached, $left credits"];
        if ($left <= 0) {
            return TestResult::fail('This Textlocal account has no credits, so nothing would be delivered.', $steps);
        }
        return TestResult::ok('Account reached.', $steps);
    }

    public function send(string $to, string $body): SmsResult
    {
        $response = $this->post(self::API . '/send/', [
            'apikey' => $this->str('api_key'),
            'numbers' => ltrim($to, '+'),
            'sender' => $this->from(),
            'message' => $body,
        ], [], true);
        $json = $response->json() ?? [];
        if (($json['status'] ?? '') !== 'success') {
            $said = (string) ($json['errors'][0]['message'] ?? "Textlocal answered HTTP {$response->status}");
            $code = isset($json['errors'][0]['code']) ? (string) $json['errors'][0]['code'] : null;
            throw $code === '5' ? SmsError::permanent($said, $code) : new SmsError($said, $code);
        }
        return new SmsResult((string) ($json['message_id'] ?? $json['batch_id'] ?? ''));
    }

    public function readReceipt(array $payload): array
    {
        $status = strtolower((string) ($payload['status'] ?? ''));
        return [
            'messageId' => isset($payload['messageId']) ? (string) $payload['messageId'] : null,
            'status' => match ($status) {
                'd', 'delivered' => 'DELIVERED',
                'u', 'f', 'failed', 'undelivered' => 'FAILED',
                default => null,
            },
            'reason' => null,
        ];
    }

    public function readInbound(array $payload): array
    {
        return ['from' => isset($payload['sender']) ? '+' . ltrim((string) $payload['sender'], '+') : null, 'body' => isset($payload['content']) ? (string) $payload['content'] : null];
    }
}
