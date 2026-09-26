<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Core\Http;
use App\Services\TestResult;

/**
 * A gateway of the church's own: a national provider with an API nobody else
 * here speaks, an office phone system, a Raspberry Pi with a SIM in it.
 *
 * What it asks of a gateway is the least it can: a POST of
 * `{to, from, body}`, a 2xx meaning accepted, and optionally a message id in
 * the answer. The address is one somebody typed, so it goes through the
 * untrusted fetcher — which refuses loopback and private addresses on every
 * hop — exactly like every other URL a person can put into this app.
 */
final class WebhookProvider extends BaseSmsProvider
{
    public static function id(): string
    {
        return 'webhook';
    }

    public static function label(): string
    {
        return 'JSON webhook';
    }

    public static function limits(): string
    {
        return 'Posts {"to","from","body"} to an address of yours and treats any 2xx as accepted. It cannot report delivery or receive replies unless your gateway calls this site back; it will not reach a private or loopback address, so a gateway on the same machine needs a public name.';
    }

    public static function configSchema(): array
    {
        return [
            ['key' => 'url', 'label' => 'Gateway address', 'type' => 'text', 'required' => true, 'help' => 'An https:// address of yours. It receives {"to","from","body"} as JSON.'],
            ['key' => 'token', 'label' => 'Bearer token', 'type' => 'password', 'secret' => true, 'help' => 'Optional. Sent as Authorization: Bearer …, so your gateway can tell this site from anybody else.'],
            ...self::commonSchema(),
        ];
    }

    private function headers(): array
    {
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        if ($this->str('token') !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->str('token');
        }
        return $headers;
    }

    protected function checkAccount(): TestResult
    {
        $url = $this->str('url');
        if (!preg_match('#^https?://#i', $url)) {
            return TestResult::fail('The gateway address must start with http:// or https://.');
        }
        try {
            $response = Http::fetchUntrusted('POST', $url, $this->headers(), (string) json_encode(['ping' => true]));
        } catch (\Throwable $e) {
            return TestResult::fail('Couldn’t reach the gateway: ' . $e->getMessage());
        }
        if (!$response->ok()) {
            return TestResult::fail("The gateway answered HTTP {$response->status} to a ping. It should answer 2xx to {\"ping\": true} without sending anything.");
        }
        return TestResult::ok('The gateway answered the ping.', ['Ping accepted (HTTP ' . $response->status . ')']);
    }

    public function send(string $to, string $body): SmsResult
    {
        try {
            $response = Http::fetchUntrusted('POST', $this->str('url'), $this->headers(), (string) json_encode([
                'to' => $to,
                'from' => $this->from(),
                'body' => $body,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
            throw new SmsError('Couldn’t reach the gateway: ' . $e->getMessage());
        }
        if (!$response->ok()) {
            $json = $response->json() ?? [];
            throw new SmsError((string) ($json['error'] ?? $json['message'] ?? "The gateway answered HTTP {$response->status}"));
        }
        $json = $response->json() ?? [];
        return new SmsResult((string) ($json['id'] ?? $json['messageId'] ?? ''));
    }

    public function readReceipt(array $payload): array
    {
        $status = strtolower((string) ($payload['status'] ?? ''));
        return [
            'messageId' => isset($payload['id']) ? (string) $payload['id'] : (isset($payload['messageId']) ? (string) $payload['messageId'] : null),
            'status' => match ($status) {
                'delivered', 'ok', 'success' => 'DELIVERED',
                'failed', 'error', 'undelivered' => 'FAILED',
                default => null,
            },
            'reason' => isset($payload['error']) ? (string) $payload['error'] : null,
        ];
    }

    public function readInbound(array $payload): array
    {
        return ['from' => isset($payload['from']) ? (string) $payload['from'] : null, 'body' => isset($payload['body']) ? (string) $payload['body'] : null];
    }
}
