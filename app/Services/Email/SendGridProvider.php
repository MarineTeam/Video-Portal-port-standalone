<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Core\Http;

/** SendGrid's v3 mail send API. */
final class SendGridProvider extends HttpEmailProvider
{
    private const API = 'https://api.sendgrid.com/v3';

    public static function id(): string
    {
        return 'sendgrid';
    }

    public static function label(): string
    {
        return 'SendGrid';
    }

    public static function limits(): string
    {
        return 'Needs outbound HTTPS. The "send as" address must be a verified sender or on an authenticated domain.';
    }

    protected static function credentialFields(): array
    {
        return [['key' => 'api_key', 'label' => 'API key', 'type' => 'password', 'secret' => true, 'required' => true, 'help' => 'With “Mail Send” access.']];
    }

    /** @return array<string, string> */
    private function auth(): array
    {
        return ['Authorization' => 'Bearer ' . $this->str('api_key')];
    }

    protected function checkCredentials(): ?string
    {
        $r = Http::json('GET', self::API . '/scopes', null, $this->auth());
        if ($r->status === 401 || $r->status === 403) {
            return 'SendGrid refused the API key.';
        }
        if (!$r->ok()) {
            return 'SendGrid answered ' . self::said($r) . '.';
        }
        return in_array('mail.send', (array) ($r->json()['scopes'] ?? []), true) ? null : 'The API key can’t send mail: give it “Mail Send” access.';
    }

    public function send(Message $message, string $from): SendResult
    {
        $sender = self::parseAddress($this->sender($from));
        $content = [['type' => 'text/plain', 'value' => $message->text]];
        if ($message->html !== null) {
            $content[] = ['type' => 'text/html', 'value' => $message->html];
        }
        $payload = [
            'personalizations' => [['to' => [['email' => $message->to]]]],
            'from' => array_filter(['email' => $sender['email'], 'name' => $sender['name']]),
            'subject' => $message->subject,
            'content' => $content,
        ];
        if ($message->replyTo !== null) {
            $payload['reply_to'] = ['email' => $message->replyTo];
        }
        try {
            $r = Http::json('POST', self::API . '/mail/send', $payload, $this->auth());
        } catch (\Throwable $e) {
            return SendResult::failed($e->getMessage());
        }
        return $r->ok() ? SendResult::sent($r->header('x-message-id')) : SendResult::failed(self::said($r));
    }
}
