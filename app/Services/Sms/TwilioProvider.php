<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Services\TestResult;

/** Twilio — what the original texted with. */
final class TwilioProvider extends BaseSmsProvider
{
    private const API = 'https://api.twilio.com/2010-04-01';

    public static function id(): string
    {
        return 'twilio';
    }

    public static function label(): string
    {
        return 'Twilio';
    }

    public static function limits(): string
    {
        return 'Needs outbound HTTPS. US and Canadian numbers need a registered campaign (A2P 10DLC) before they will deliver. Twilio answers STOP itself on those numbers; this app honours the reply as well, because the same number may be here under another provider tomorrow.';
    }

    public static function appendsOptOut(): bool
    {
        // Twilio appends the opt-out language where the destination requires it.
        return true;
    }

    public static function configSchema(): array
    {
        return [
            ['key' => 'account_sid', 'label' => 'Account SID', 'type' => 'text', 'required' => true],
            ['key' => 'auth_token', 'label' => 'Auth token', 'type' => 'password', 'secret' => true, 'required' => true],
            ...self::commonSchema(),
            ['key' => 'messaging_service_sid', 'label' => 'Messaging Service SID', 'type' => 'text', 'help' => 'Optional. When set, Twilio picks the sender from the service’s pool and "Send from" is ignored.'],
        ];
    }

    private function auth(): array
    {
        return ['Authorization' => self::basic($this->str('account_sid'), $this->str('auth_token'))];
    }

    protected function checkAccount(): TestResult
    {
        $account = $this->get(self::API . '/Accounts/' . rawurlencode($this->str('account_sid')) . '.json', $this->auth());
        if ($account->status === 401) {
            return TestResult::fail('Twilio refused the account SID and auth token.');
        }
        if (!$account->ok()) {
            return TestResult::fail("Twilio answered HTTP {$account->status}.");
        }
        $status = (string) ($account->json()['status'] ?? '');
        $steps = ['Account reached' . ($status !== '' ? " ($status)" : '')];
        if ($status === 'suspended' || $status === 'closed') {
            return TestResult::fail("This Twilio account is $status, so nothing would be delivered.", $steps);
        }
        return TestResult::ok('Account reached.', $steps);
    }

    public function send(string $to, string $body): SmsResult
    {
        $payload = ['To' => $to, 'Body' => $body];
        if ($this->str('messaging_service_sid') !== '') {
            $payload['MessagingServiceSid'] = $this->str('messaging_service_sid');
        } else {
            $payload['From'] = $this->from();
        }
        $response = $this->post(self::API . '/Accounts/' . rawurlencode($this->str('account_sid')) . '/Messages.json', $payload, $this->auth(), true);
        $json = $response->json() ?? [];
        if (!$response->ok()) {
            $said = (string) ($json['message'] ?? "Twilio answered HTTP {$response->status}");
            // 21211 and its neighbours are the number itself: trying again
            // tomorrow will fail in exactly the same way.
            $code = isset($json['code']) ? (string) $json['code'] : null;
            throw in_array($code, ['21211', '21214', '21610', '21614'], true)
                ? SmsError::permanent($said, $code)
                : new SmsError($said, $code);
        }
        return new SmsResult((string) ($json['sid'] ?? ''), (string) ($json['status'] ?? ''));
    }

    /**
     * X-Twilio-Signature: the URL with the POST fields appended in key order,
     * HMAC-SHA1 under the auth token.
     */
    public static function signsCallbacks(): bool
    {
        return true;
    }

    public function verifyCallback(string $url, string $rawBody, array $headers): bool
    {
        $given = (string) ($headers['x-twilio-signature'] ?? '');
        if ($given === '') {
            return false;
        }
        parse_str($rawBody, $fields);
        ksort($fields);
        $data = $url;
        foreach ($fields as $key => $value) {
            $data .= $key . (is_array($value) ? implode('', $value) : (string) $value);
        }
        return hash_equals(base64_encode(hash_hmac('sha1', $data, $this->str('auth_token'), true)), $given);
    }

    public function readReceipt(array $payload): array
    {
        $status = strtolower((string) ($payload['MessageStatus'] ?? $payload['SmsStatus'] ?? ''));
        return [
            'messageId' => isset($payload['MessageSid']) ? (string) $payload['MessageSid'] : null,
            'status' => match ($status) {
                'delivered' => 'DELIVERED',
                'failed', 'undelivered' => 'FAILED',
                default => null,
            },
            'reason' => isset($payload['ErrorCode']) ? 'Twilio error ' . $payload['ErrorCode'] : null,
        ];
    }

    public function readInbound(array $payload): array
    {
        return ['from' => isset($payload['From']) ? (string) $payload['From'] : null, 'body' => isset($payload['Body']) ? (string) $payload['Body'] : null];
    }
}
