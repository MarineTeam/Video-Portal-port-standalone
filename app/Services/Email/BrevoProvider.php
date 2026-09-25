<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Core\Http;

/** Brevo (formerly Sendinblue) transactional email. */
final class BrevoProvider extends HttpEmailProvider
{
    private const API = 'https://api.brevo.com/v3';

    public static function id(): string
    {
        return 'brevo';
    }

    public static function label(): string
    {
        return 'Brevo';
    }

    public static function limits(): string
    {
        return 'Needs outbound HTTPS. The "send as" address must be a verified sender in Brevo.';
    }

    protected static function credentialFields(): array
    {
        return [['key' => 'api_key', 'label' => 'API key (v3)', 'type' => 'password', 'secret' => true, 'required' => true]];
    }

    /** @return array<string, string> */
    private function auth(): array
    {
        return ['api-key' => $this->str('api_key')];
    }

    protected function checkCredentials(): ?string
    {
        $r = Http::json('GET', self::API . '/account', null, $this->auth());
        return $r->status === 401 ? 'Brevo refused the API key.' : (!$r->ok() ? 'Brevo answered ' . self::said($r) . '.' : null);
    }

    public function send(Message $message, string $from): SendResult
    {
        $sender = self::parseAddress($this->sender($from));
        $payload = [
            'sender' => array_filter(['email' => $sender['email'], 'name' => $sender['name']]),
            'to' => [['email' => $message->to]],
            'subject' => $message->subject,
            'textContent' => $message->text,
        ];
        if ($message->html !== null) {
            $payload['htmlContent'] = $message->html;
        }
        if ($message->replyTo !== null) {
            $payload['replyTo'] = ['email' => $message->replyTo];
        }
        try {
            $r = Http::json('POST', self::API . '/smtp/email', $payload, $this->auth());
        } catch (\Throwable $e) {
            return SendResult::failed($e->getMessage());
        }
        return $r->ok() ? SendResult::sent((string) ($r->json()['messageId'] ?? '')) : SendResult::failed(self::said($r));
    }
}
