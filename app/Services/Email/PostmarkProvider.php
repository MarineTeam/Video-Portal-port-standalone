<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Core\Http;

/** Postmark's email API, on the transactional ("outbound") stream. */
final class PostmarkProvider extends HttpEmailProvider
{
    private const API = 'https://api.postmarkapp.com';

    public static function id(): string
    {
        return 'postmark';
    }

    public static function label(): string
    {
        return 'Postmark';
    }

    public static function limits(): string
    {
        return 'Needs outbound HTTPS. The "send as" address must be a confirmed sender signature or on a verified domain.';
    }

    protected static function credentialFields(): array
    {
        return [['key' => 'server_token', 'label' => 'Server API token', 'type' => 'password', 'secret' => true, 'required' => true]];
    }

    /** @return array<string, string> */
    private function auth(): array
    {
        return ['X-Postmark-Server-Token' => $this->str('server_token')];
    }

    protected function checkCredentials(): ?string
    {
        $r = Http::json('GET', self::API . '/server', null, $this->auth());
        return $r->status === 401 ? 'Postmark refused the server token.' : (!$r->ok() ? 'Postmark answered ' . self::said($r) . '.' : null);
    }

    public function send(Message $message, string $from): SendResult
    {
        $payload = ['From' => $this->sender($from), 'To' => $message->to, 'Subject' => $message->subject, 'TextBody' => $message->text, 'MessageStream' => 'outbound'];
        if ($message->html !== null) {
            $payload['HtmlBody'] = $message->html;
        }
        if ($message->replyTo !== null) {
            $payload['ReplyTo'] = $message->replyTo;
        }
        try {
            $r = Http::json('POST', self::API . '/email', $payload, $this->auth());
        } catch (\Throwable $e) {
            return SendResult::failed($e->getMessage());
        }
        $d = $r->json();
        return $r->ok() && (int) ($d['ErrorCode'] ?? 0) === 0 ? SendResult::sent((string) ($d['MessageID'] ?? '')) : SendResult::failed(self::said($r));
    }
}
