<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Core\Http;

/** Mailgun's messages API (US or EU region). */
final class MailgunProvider extends HttpEmailProvider
{
    public static function id(): string
    {
        return 'mailgun';
    }

    public static function label(): string
    {
        return 'Mailgun';
    }

    public static function limits(): string
    {
        return 'Needs outbound HTTPS. The sending domain must be verified in Mailgun.';
    }

    protected static function credentialFields(): array
    {
        return [
            ['key' => 'api_key', 'label' => 'Private API key', 'type' => 'password', 'secret' => true, 'required' => true],
            ['key' => 'domain', 'label' => 'Sending domain', 'type' => 'text', 'required' => true, 'help' => 'e.g. mg.gracechurch.org'],
            ['key' => 'region', 'label' => 'Region', 'type' => 'select', 'options' => ['us' => 'US', 'eu' => 'EU'], 'default' => 'us'],
        ];
    }

    private function base(): string
    {
        return ($this->str('region', 'us') === 'eu' ? 'https://api.eu.mailgun.net' : 'https://api.mailgun.net') . '/v3/' . rawurlencode($this->str('domain'));
    }

    /** @return array<string, string> */
    private function auth(): array
    {
        return ['Authorization' => 'Basic ' . base64_encode('api:' . $this->str('api_key'))];
    }

    protected function checkCredentials(): ?string
    {
        $r = Http::request('GET', str_replace('/v3/', '/v4/domains/', $this->base()), $this->auth() + ['Accept' => 'application/json']);
        return match (true) {
            $r->status === 401 => 'Mailgun refused the API key.',
            $r->status === 404 => 'Mailgun has no sending domain ' . $this->str('domain') . ' (check the region too).',
            !$r->ok() => 'Mailgun answered ' . self::said($r) . '.',
            default => null,
        };
    }

    public function send(Message $message, string $from): SendResult
    {
        $fields = ['from' => $this->sender($from), 'to' => $message->to, 'subject' => $message->subject, 'text' => $message->text];
        if ($message->html !== null) {
            $fields['html'] = $message->html;
        }
        if ($message->replyTo !== null) {
            $fields['h:Reply-To'] = $message->replyTo;
        }
        try {
            $r = Http::request('POST', $this->base() . '/messages', $this->auth() + ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'], http_build_query($fields));
        } catch (\Throwable $e) {
            return SendResult::failed($e->getMessage());
        }
        return $r->ok() ? SendResult::sent(trim((string) ($r->json()['id'] ?? ''), '<>')) : SendResult::failed(self::said($r));
    }
}
