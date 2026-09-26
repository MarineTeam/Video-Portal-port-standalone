<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Services\TestResult;

/** Sinch. The region is part of the address, and is chosen on the row. */
final class SinchProvider extends BaseSmsProvider
{
    public const REGIONS = ['us' => 'United States', 'eu' => 'Europe', 'au' => 'Australia', 'br' => 'Brazil', 'ca' => 'Canada'];

    public static function id(): string
    {
        return 'sinch';
    }

    public static function label(): string
    {
        return 'Sinch';
    }

    public static function limits(): string
    {
        return 'Needs outbound HTTPS. The region must match the one the service plan was created in, or Sinch answers 404 rather than routing it.';
    }

    public static function configSchema(): array
    {
        return [
            ['key' => 'service_plan_id', 'label' => 'Service plan ID', 'type' => 'text', 'required' => true],
            ['key' => 'api_token', 'label' => 'API token', 'type' => 'password', 'secret' => true, 'required' => true],
            ['key' => 'region', 'label' => 'Region', 'type' => 'select', 'required' => true, 'default' => 'us', 'options' => self::REGIONS],
            ...self::commonSchema(),
        ];
    }

    private function base(): string
    {
        $region = $this->str('region', 'us');
        $region = isset(self::REGIONS[$region]) ? $region : 'us';
        return "https://$region.sms.api.sinch.com/xms/v1/" . rawurlencode($this->str('service_plan_id'));
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer ' . $this->str('api_token')];
    }

    protected function checkAccount(): TestResult
    {
        $batches = $this->get($this->base() . '/batches?page_size=1', $this->auth());
        if ($batches->status === 401) {
            return TestResult::fail('Sinch refused the API token.');
        }
        if ($batches->status === 404) {
            return TestResult::fail('Sinch has no such service plan in the ' . $this->str('region', 'us') . ' region. Check the region against where the plan was created.');
        }
        if (!$batches->ok()) {
            return TestResult::fail("Sinch answered HTTP {$batches->status}.");
        }
        return TestResult::ok('Service plan reached.', ['Service plan found in the ' . $this->str('region', 'us') . ' region']);
    }

    public function send(string $to, string $body): SmsResult
    {
        $response = $this->post($this->base() . '/batches', [
            'from' => $this->from(),
            'to' => [$to],
            'body' => $body,
        ], $this->auth());
        $json = $response->json() ?? [];
        if (!$response->ok()) {
            throw new SmsError((string) ($json['text'] ?? "Sinch answered HTTP {$response->status}"), isset($json['code']) ? (string) $json['code'] : null);
        }
        return new SmsResult((string) ($json['id'] ?? ''));
    }

    public function readReceipt(array $payload): array
    {
        $status = strtoupper((string) ($payload['status'] ?? ''));
        return [
            'messageId' => isset($payload['batch_id']) ? (string) $payload['batch_id'] : null,
            'status' => match ($status) {
                'DELIVERED' => 'DELIVERED',
                'FAILED', 'EXPIRED', 'REJECTED', 'ABORTED' => 'FAILED',
                default => null,
            },
            'reason' => isset($payload['code']) ? 'Sinch code ' . $payload['code'] : null,
        ];
    }

    public function readInbound(array $payload): array
    {
        return ['from' => isset($payload['from']) ? (string) $payload['from'] : null, 'body' => isset($payload['body']) ? (string) $payload['body'] : null];
    }
}
