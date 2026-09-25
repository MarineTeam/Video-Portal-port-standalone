<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Core\Http;
use App\Core\HttpResponse;
use App\Support\SigV4;

/** Amazon SES, API v2, signed with SigV4. */
final class SesProvider extends HttpEmailProvider
{
    public static function id(): string
    {
        return 'ses';
    }

    public static function label(): string
    {
        return 'Amazon SES';
    }

    public static function limits(): string
    {
        return 'Needs outbound HTTPS. While the account is in the SES sandbox it can only send to verified addresses.';
    }

    protected static function credentialFields(): array
    {
        return [
            ['key' => 'access_key_id', 'label' => 'Access key ID', 'type' => 'text', 'required' => true],
            ['key' => 'secret_access_key', 'label' => 'Secret access key', 'type' => 'password', 'secret' => true, 'required' => true, 'help' => 'An IAM user allowed ses:SendEmail and ses:GetAccount.'],
            ['key' => 'region', 'label' => 'Region', 'type' => 'text', 'required' => true, 'default' => 'us-east-1', 'help' => 'e.g. eu-west-1'],
        ];
    }

    private function url(string $path): string
    {
        $region = preg_match('/^[a-z]{2}(-[a-z]+)+-\d$/', $this->str('region')) ? $this->str('region') : 'us-east-1';
        return "https://email.$region.amazonaws.com/v2/email/$path";
    }

    private function call(string $method, string $path, ?array $payload = null): HttpResponse
    {
        $url = $this->url($path);
        $body = $payload === null ? '' : (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
        $region = (string) (explode('.', (string) parse_url($url, PHP_URL_HOST))[1] ?? 'us-east-1');
        $signer = new SigV4($this->str('access_key_id'), $this->str('secret_access_key'), $region, 'ses');
        $headers = $signer->sign($method, $url, $payload === null ? [] : ['content-type' => 'application/json'], $body);
        return Http::request($method, $url, $headers + ['Accept' => 'application/json'], $payload === null ? null : $body);
    }

    protected function checkCredentials(): ?string
    {
        $r = $this->call('GET', 'account');
        if ($r->status === 403) {
            return 'Amazon refused the keys (or they lack ses:GetAccount): ' . self::said($r);
        }
        if (!$r->ok()) {
            return 'SES answered ' . self::said($r) . '.';
        }
        return ($r->json()['SendingEnabled'] ?? true) === false ? 'Sending is paused on this SES account.' : null;
    }

    public function send(Message $message, string $from): SendResult
    {
        $body = ['Text' => ['Data' => $message->text, 'Charset' => 'UTF-8']];
        if ($message->html !== null) {
            $body['Html'] = ['Data' => $message->html, 'Charset' => 'UTF-8'];
        }
        $payload = [
            'FromEmailAddress' => $this->sender($from),
            'Destination' => ['ToAddresses' => [$message->to]],
            'Content' => ['Simple' => ['Subject' => ['Data' => $message->subject, 'Charset' => 'UTF-8'], 'Body' => $body]],
        ];
        if ($message->replyTo !== null) {
            $payload['ReplyToAddresses'] = [$message->replyTo];
        }
        try {
            $r = $this->call('POST', 'outbound-emails', $payload);
        } catch (\Throwable $e) {
            return SendResult::failed($e->getMessage());
        }
        return $r->ok() ? SendResult::sent((string) ($r->json()['MessageId'] ?? '')) : SendResult::failed(self::said($r));
    }
}
