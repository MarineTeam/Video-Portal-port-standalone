<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Core\Cache;
use App\Core\Http;

/**
 * Microsoft 365 through Graph's sendMail, with an Entra app registration's
 * client credentials (application permission Mail.Send, ideally limited
 * to the one mailbox by an application access policy).
 */
final class GraphProvider extends HttpEmailProvider
{
    public static function id(): string
    {
        return 'graph';
    }

    public static function label(): string
    {
        return 'Microsoft 365 (Graph)';
    }

    public static function limits(): string
    {
        return 'Needs outbound HTTPS. Sends as the mailbox named in "Send as"; the app needs the Mail.Send application permission.';
    }

    protected static function credentialFields(): array
    {
        return [
            ['key' => 'tenant_id', 'label' => 'Directory (tenant) ID', 'type' => 'text', 'required' => true],
            ['key' => 'client_id', 'label' => 'Application (client) ID', 'type' => 'text', 'required' => true],
            ['key' => 'client_secret', 'label' => 'Client secret', 'type' => 'password', 'secret' => true, 'required' => true],
        ];
    }

    /** A token for Graph, kept until shortly before it expires. */
    private function token(): string
    {
        $key = 'graph-token:' . hash('sha256', $this->str('tenant_id') . $this->str('client_id') . $this->str('client_secret'));
        $cached = Cache::get($key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        $r = Http::request('POST', 'https://login.microsoftonline.com/' . rawurlencode($this->str('tenant_id')) . '/oauth2/v2.0/token', ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'], http_build_query([
            'client_id' => $this->str('client_id'),
            'client_secret' => $this->str('client_secret'),
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials',
        ]));
        $d = $r->json();
        if (!$r->ok() || !is_string($d['access_token'] ?? null)) {
            throw new \RuntimeException('Microsoft refused the app credentials: ' . (string) ($d['error_description'] ?? $d['error'] ?? 'HTTP ' . $r->status));
        }
        Cache::set($key, $d['access_token'], max(60, (int) ($d['expires_in'] ?? 3600) - 300));
        return $d['access_token'];
    }

    protected function checkCredentials(): ?string
    {
        try {
            $this->token();
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }
        return null;
    }

    public function send(Message $message, string $from): SendResult
    {
        $sender = self::parseAddress($this->sender($from));
        $mail = [
            'subject' => $message->subject,
            'body' => $message->html !== null ? ['contentType' => 'HTML', 'content' => $message->html] : ['contentType' => 'Text', 'content' => $message->text],
            'toRecipients' => [['emailAddress' => ['address' => $message->to]]],
        ];
        if ($message->replyTo !== null) {
            $mail['replyTo'] = [['emailAddress' => ['address' => $message->replyTo]]];
        }
        try {
            $r = Http::json('POST', 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($sender['email']) . '/sendMail', ['message' => $mail, 'saveToSentItems' => false], ['Authorization' => 'Bearer ' . $this->token()]);
        } catch (\Throwable $e) {
            return SendResult::failed($e->getMessage());
        }
        return $r->ok() ? SendResult::sent($r->header('request-id')) : SendResult::failed(self::said($r));
    }
}
