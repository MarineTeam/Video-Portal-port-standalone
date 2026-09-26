<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Core\Http;
use App\Core\HttpResponse;
use App\Services\TestResult;
use App\Support\SigV4;

/** Amazon SNS, through the same SigV4 signer SES uses. */
final class SnsProvider extends BaseSmsProvider
{
    public static function id(): string
    {
        return 'sns';
    }

    public static function label(): string
    {
        return 'Amazon SNS';
    }

    public static function limits(): string
    {
        return 'Needs outbound HTTPS. SNS has no delivery receipts or replies without wiring up CloudWatch and a topic, so a broadcast says "sent" rather than "reached". A new account is in the SMS sandbox, where only verified numbers receive anything.';
    }

    public static function configSchema(): array
    {
        return [
            ['key' => 'access_key_id', 'label' => 'Access key ID', 'type' => 'text', 'required' => true],
            ['key' => 'secret_access_key', 'label' => 'Secret access key', 'type' => 'password', 'secret' => true, 'required' => true],
            ['key' => 'region', 'label' => 'Region', 'type' => 'text', 'required' => true, 'default' => 'us-east-1', 'help' => 'e.g. us-east-1, eu-west-1. Not every region can send texts.'],
            ['key' => 'from', 'label' => 'Sender ID', 'type' => 'text', 'help' => 'Optional and only honoured where the destination country allows one; the US and Canada ignore it.'],
            ['key' => 'default_country', 'label' => 'Default dialling code', 'type' => 'text', 'help' => 'For members who typed a national number, e.g. 44.'],
        ];
    }

    private function region(): string
    {
        $region = $this->str('region', 'us-east-1');
        return preg_match('/^[a-z]{2}(-[a-z]+)+-\d$/', $region) ? $region : 'us-east-1';
    }

    /** @param array<string, string> $query */
    private function call(array $query): HttpResponse
    {
        $region = $this->region();
        $url = "https://sns.$region.amazonaws.com/";
        $body = http_build_query($query + ['Version' => '2010-03-31']);
        $signer = new SigV4($this->str('access_key_id'), $this->str('secret_access_key'), $region, 'sns');
        $headers = $signer->sign('POST', $url, ['content-type' => 'application/x-www-form-urlencoded'], $body);
        try {
            return Http::request('POST', $url, $headers, $body, ['timeout' => 15]);
        } catch (\Throwable $e) {
            throw new SmsError('Couldn’t reach Amazon SNS from this host: ' . $e->getMessage());
        }
    }

    private static function said(HttpResponse $r): string
    {
        return preg_match('#<Message>(.*?)</Message>#s', $r->body, $m) === 1 ? html_entity_decode($m[1]) : "HTTP {$r->status}";
    }

    protected function checkAccount(): TestResult
    {
        $attributes = $this->call(['Action' => 'GetSMSAttributes']);
        if ($attributes->status === 403) {
            return TestResult::fail('Amazon refused the keys (or they lack sns:GetSMSAttributes): ' . self::said($attributes));
        }
        if (!$attributes->ok()) {
            return TestResult::fail('SNS answered ' . self::said($attributes) . '.');
        }
        $steps = ['Account reached in ' . $this->region()];
        $limit = preg_match('#<key>MonthlySpendLimit</key>\s*<value>([^<]+)</value>#s', $attributes->body, $m) === 1 ? (float) $m[1] : null;
        if ($limit !== null && $limit <= 1.0) {
            // The default limit is one dollar, which is a handful of texts.
            $steps[] = "Monthly spend limit is \${$limit} — the account default. Raise it with AWS support before a real broadcast.";
        }
        $sandbox = $this->call(['Action' => 'ListSMSSandboxPhoneNumbers']);
        if ($sandbox->ok()) {
            $steps[] = 'This account is still in the SMS sandbox: only verified numbers receive anything.';
        }
        return TestResult::ok('Account reached.', $steps);
    }

    public function send(string $to, string $body): SmsResult
    {
        $query = [
            'Action' => 'Publish',
            'PhoneNumber' => $to,
            'Message' => $body,
            'MessageAttributes.entry.1.Name' => 'AWS.SNS.SMS.SMSType',
            'MessageAttributes.entry.1.Value.DataType' => 'String',
            'MessageAttributes.entry.1.Value.StringValue' => 'Transactional',
        ];
        if ($this->from() !== '') {
            $query += [
                'MessageAttributes.entry.2.Name' => 'AWS.SNS.SMS.SenderID',
                'MessageAttributes.entry.2.Value.DataType' => 'String',
                'MessageAttributes.entry.2.Value.StringValue' => $this->from(),
            ];
        }
        $response = $this->call($query);
        if (!$response->ok()) {
            $said = self::said($response);
            throw str_contains($said, 'not valid') || str_contains($said, 'Invalid parameter: PhoneNumber')
                ? SmsError::permanent($said)
                : new SmsError($said);
        }
        return new SmsResult(preg_match('#<MessageId>([^<]+)</MessageId>#', $response->body, $m) === 1 ? $m[1] : '');
    }
}
