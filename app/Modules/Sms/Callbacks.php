<?php

declare(strict_types=1);

namespace App\Modules\Sms;

use App\Core\App;
use App\Core\Crypto;
use App\Core\Db;
use App\Core\Log;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Url;
use App\Modules\Audit\Audit;
use App\Services\Sms\SmsProvider;
use App\Support\Sms;

/**
 * What a texting provider tells us back: whether a message arrived, and what
 * somebody replied.
 *
 * Both endpoints are unauthenticated by design — a provider's server cannot
 * sign in — and are verified by signature instead: Twilio's
 * X-Twilio-Signature, Vonage's signed JWT, MessageBird's signature header,
 * Telnyx's Ed25519, each a few lines of pure PHP. A provider with no
 * signature scheme of its own gets a per-install secret in its callback
 * address instead, which is why the address is shown rather than guessed at.
 *
 * A reply that is a stop word switches that member's texting off, even from
 * a provider that handles stop words itself: the same number can be on the
 * list under a different provider tomorrow.
 */
final class Callbacks
{
    /** Callbacks from one address in an hour, before it is refused. */
    public const PER_HOUR = 600;

    /** How stale a signed callback may be. */
    public const MAX_AGE = 300;

    /**
     * What somebody sends when they have had enough, in both the languages
     * this app speaks. Carriers treat the English ones as standard; the
     * Spanish ones are what people actually send.
     */
    public const STOP_WORDS = [
        'stop', 'stopall', 'unsubscribe', 'cancel', 'end', 'quit', 'optout', 'opt-out', 'revoke',
        'alto', 'parar', 'pare', 'baja', 'cancelar', 'eliminar', 'fin', 'basta', 'no',
    ];

    /** And what they send when they change their minds. */
    public const START_WORDS = ['start', 'unstop', 'yes', 'si', 'sí', 'alta', 'suscribir'];

    public static function register(Router $r, App $app): void
    {
        $r->post('/api/sms/status/[provider]', fn (Request $req, array $p) => self::status($app, $req, (string) $p['provider']));
        $r->post('/api/sms/inbound/[provider]', fn (Request $req, array $p) => self::inbound($app, $req, (string) $p['provider']));
        // Some providers can only be told a GET address.
        $r->get('/api/sms/status/[provider]', fn (Request $req, array $p) => self::status($app, $req, (string) $p['provider']));
        $r->get('/api/sms/inbound/[provider]', fn (Request $req, array $p) => self::inbound($app, $req, (string) $p['provider']));
    }

    /**
     * The address to give a provider. It carries a per-install secret for
     * the providers that sign nothing, so a stranger cannot post receipts.
     */
    public static function url(App $app, string $kind, string $providerId): string
    {
        $provider = $app->services()->get('sms', $providerId);
        $path = "/api/sms/$kind/" . rawurlencode($providerId);
        return $provider instanceof SmsProvider && !$provider::signsCallbacks()
            ? Url::absolute($path . '?k=' . self::secret($providerId))
            : Url::absolute($path);
    }

    /** A secret per install and per provider, derived rather than stored. */
    public static function secret(string $providerId): string
    {
        return substr(Crypto::hmac('sms-callback', $providerId), 0, 32);
    }

    /**
     * @return array{provider: SmsProvider, payload: array<string, mixed>}|Response
     */
    private static function accept(App $app, Request $req, string $providerId): array|Response
    {
        if (!(new RateLimiter($app->db()))->hit(RateLimiter::bucket('sms:callback', $req->ip), self::PER_HOUR, 3600)) {
            return Response::text('Too many', 429);
        }
        $provider = $app->services()->get('sms', $providerId);
        if (!$provider instanceof SmsProvider) {
            // Never say which providers exist; a probe learns nothing.
            return Response::text('No', 404);
        }
        $raw = $req->body;
        $headers = [];
        foreach ($req->headers as $name => $value) {
            $headers[strtolower((string) $name)] = is_array($value) ? (string) reset($value) : (string) $value;
        }
        if ($provider::signsCallbacks()) {
            $verified = $provider->verifyCallback(Url::absolute($req->path) . ($req->query('k') !== null ? '?k=' . $req->query('k') : ''), $raw, $headers);
        } else {
            // No signature scheme of its own: the secret in the address is
            // the whole of the check, so it is compared in constant time.
            $given = (string) ($req->query('k') ?? '');
            $verified = $given !== '' && hash_equals(self::secret($providerId), $given);
        }
        if (!$verified) {
            Log::warning('sms: a callback failed its signature check', ['provider' => $providerId, 'ip' => $req->ip]);
            return Response::text('No', 403);
        }
        return ['provider' => $provider, 'payload' => $req->input()];
    }

    /** A delivery receipt: "sent" becomes "reached", or a reason it did not. */
    private static function status(App $app, Request $req, string $providerId): Response
    {
        $accepted = self::accept($app, $req, $providerId);
        if ($accepted instanceof Response) {
            return $accepted;
        }
        $receipt = $accepted['provider']->readReceipt($accepted['payload']);
        $messageId = $receipt['messageId'];
        if ($messageId === null || $messageId === '' || $receipt['status'] === null) {
            // A status we have no word for — "queued", "sending" — is not an
            // error; there is simply nothing to record yet.
            return Response::text('OK');
        }
        $app->db()->run(
            'UPDATE {{broadcast_recipients}} SET delivery_status = ?, delivered_at = ?, error = COALESCE(?, error)
             WHERE provider = ? AND provider_message_id = ?',
            [
                $receipt['status'],
                $receipt['status'] === 'DELIVERED' ? Db::now() : null,
                $receipt['status'] === 'FAILED' ? mb_substr((string) ($receipt['reason'] ?? 'The carrier did not deliver it.'), 0, 500) : null,
                $providerId,
                $messageId,
            ],
        );
        return Response::text('OK');
    }

    /**
     * A reply. Only one thing is acted on — asking to stop, or to start
     * again — because this app has no inbox for anything else, and pretending
     * otherwise would lose somebody's message silently.
     */
    private static function inbound(App $app, Request $req, string $providerId): Response
    {
        $accepted = self::accept($app, $req, $providerId);
        if ($accepted instanceof Response) {
            return $accepted;
        }
        $reply = $accepted['provider']->readInbound($accepted['payload']);
        $word = self::wordFor((string) ($reply['body'] ?? ''));
        $from = Sms::normalizePhone((string) ($reply['from'] ?? ''), null);
        if ($word === null || $from === null) {
            return Response::text('OK');
        }
        $db = $app->db();
        $users = $db->all('SELECT id, email, phone FROM {{users}} WHERE phone IS NOT NULL AND phone <> ?', ['']);
        $told = 0;
        foreach ($users as $user) {
            if (Sms::normalizePhone((string) $user['phone'], null) !== $from) {
                continue;
            }
            $db->update('users', ['sms_opt_in' => $word === 'stop' ? 0 : 1], ['id' => $user['id']]);
            Audit::log($db, (string) $user['email'], $word === 'stop' ? 'sms.stop' : 'sms.start', 'User', (string) $user['id'], 'by text, through ' . $providerId);
            $told++;
        }
        if ($told === 0) {
            Log::warning('sms: a stop word came from a number nobody here has', ['provider' => $providerId]);
        }
        return Response::text('OK');
    }

    /** 'stop', 'start' or nothing at all. */
    public static function wordFor(string $body): ?string
    {
        $word = strtolower(trim((string) preg_replace('/[^\p{L}\p{N}\s-]+/u', '', $body)));
        if ($word === '') {
            return null;
        }
        if (in_array($word, self::STOP_WORDS, true)) {
            return 'stop';
        }
        return in_array($word, self::START_WORDS, true) ? 'start' : null;
    }
}
