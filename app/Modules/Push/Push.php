<?php

declare(strict_types=1);

namespace App\Modules\Push;

use App\Core\ApiError;
use App\Core\App;
use App\Core\Http;
use App\Core\HttpException;
use App\Core\Id;
use App\Core\Log;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Validator;
use App\Modules\Admin\Integrations;
use App\Services\TestResult;
use App\Support\WebPush;

/**
 * Web Push for everything that notifies — publishing, live streams,
 * broadcasts. The keys are the "Web Push" integration under Admin →
 * Services; without them nothing is sent and the subscribe route says so.
 * Members subscribe a browser with POST /api/push/subscribe (a push
 * service's own address only, at most eight per member) and every send
 * forgets a subscription the push service says is gone.
 */
final class Push
{
    public const FIELDS = [
        ['key' => 'publicKey', 'label' => 'Public key (VAPID, base64url)', 'type' => 'text', 'required' => true],
        ['key' => 'privateKey', 'label' => 'Private key (VAPID, base64url)', 'type' => 'password', 'secret' => true, 'required' => true],
        ['key' => 'subject', 'label' => 'Contact (mailto: or https: address)', 'type' => 'text', 'required' => true, 'help' => 'The push services use it to reach the site’s owner, e.g. mailto:office@example.org.'],
        ['key' => 'extraHosts', 'label' => 'Other push service hosts (optional, comma separated)', 'type' => 'text', 'help' => 'For a browser whose push service isn’t one of the known ones.'],
    ];

    /** @return array{publicKey: string, privateKey: string, subject: string, extraHosts: string}|null */
    public static function vapid(App $app): ?array
    {
        $c = Integrations::config($app, 'webpush');
        $public = trim((string) ($c['publicKey'] ?? ''));
        $private = trim((string) ($c['privateKey'] ?? ''));
        if ($public === '' || $private === '') {
            return null;
        }
        return ['publicKey' => $public, 'privateKey' => $private, 'subject' => trim((string) ($c['subject'] ?? '')), 'extraHosts' => (string) ($c['extraHosts'] ?? '')];
    }

    /** @param array<string, mixed> $config */
    public static function test(array $config): TestResult
    {
        $public = trim((string) ($config['publicKey'] ?? ''));
        $private = trim((string) ($config['privateKey'] ?? ''));
        if (!WebPush::validKeys($public, $private)) {
            return TestResult::fail('Those keys aren’t a VAPID pair. Generate a new pair, or paste both halves of the old one.');
        }
        $subject = trim((string) ($config['subject'] ?? ''));
        if (!preg_match('/^(mailto:[^@\s]+@[^@\s]+|https:\/\/\S+)$/', $subject)) {
            return TestResult::fail('The contact must be a mailto: or https: address.');
        }
        return TestResult::ok('The keys are a pair and sign. Save, then turn notifications on from a browser in Profile → Inbox.', ['Signed a VAPID token and verified it with the public key']);
    }

    /** @return array<string, string> fresh values for the form's key fields */
    public static function generate(): array
    {
        return WebPush::generateKeys();
    }

    public static function register(Router $r, App $app): void
    {
        $member = Middleware::member($app);
        $r->post('/api/push/subscribe', fn (Request $req) => self::subscribe($app, $req), [$member]);
        $r->post('/api/push/unsubscribe', function (Request $req) use ($app): Response {
            $endpoint = (string) ($req->input()['endpoint'] ?? '');
            $app->db()->run('DELETE FROM {{push_subscriptions}} WHERE endpoint_hash = ? AND user_id = ?', [hash('sha256', $endpoint), $app->currentUser()->id()]);
            return Response::json(['ok' => true]);
        }, [$member]);
    }

    private static function subscribe(App $app, Request $req): Response
    {
        $vapid = self::vapid($app);
        if ($vapid === null) {
            throw new ApiError('Notifications aren’t set up on this site yet.', 409, 'push_not_configured');
        }
        $input = $req->input();
        $keys = is_array($input['keys'] ?? null) ? $input['keys'] : [];
        $data = Validator::check(['endpoint' => $input['endpoint'] ?? null, 'p256dh' => $keys['p256dh'] ?? null, 'auth' => $keys['auth'] ?? null], [
            'endpoint' => ['string', 'required', 'max' => 1000],
            'p256dh' => ['string', 'required', 'max' => 255, 'pattern' => '/^[A-Za-z0-9_-]{80,100}$/'],
            'auth' => ['string', 'required', 'max' => 255, 'pattern' => '/^[A-Za-z0-9_-]{16,32}$/'],
        ]);
        if (!PushEndpoint::isPushServiceEndpoint((string) $data['endpoint'], PushEndpoint::extraSuffixes($vapid['extraHosts']))) {
            throw ApiError::invalid('That isn’t a browser push service’s address.');
        }
        $db = $app->db();
        $userId = (string) $app->currentUser()->id();
        $hash = hash('sha256', (string) $data['endpoint']);
        $db->transaction(function () use ($db, $userId, $hash, $data): void {
            // Another member's browser handing over its subscription moves it here.
            $db->run('DELETE FROM {{push_subscriptions}} WHERE endpoint_hash = ?', [$hash]);
            $existing = array_map(fn ($r) => ['id' => (string) $r['id'], 'createdAt' => (string) $r['created_at']], $db->all('SELECT id, created_at FROM {{push_subscriptions}} WHERE user_id = ?', [$userId]));
            foreach (PushEndpoint::subscriptionsToEvict($existing) as $old) {
                $db->delete('push_subscriptions', ['id' => $old]);
            }
            $db->insert('push_subscriptions', ['id' => Id::new(), 'user_id' => $userId, 'endpoint' => $data['endpoint'], 'endpoint_hash' => $hash, 'p256dh' => $data['p256dh'], 'auth' => $data['auth']]);
        });
        return Response::json(['ok' => true], 201);
    }

    /** How many of these members have a browser signed up. @param list<string> $userIds */
    public static function subscribers(App $app, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        return array_values(array_unique(array_map('strval', $app->db()->column('SELECT DISTINCT user_id FROM {{push_subscriptions}} WHERE user_id IN (' . implode(',', array_fill(0, count($userIds), '?')) . ')', $userIds))));
    }

    /**
     * Sends {title, body, url} to every browser these members signed up.
     * Returns how many went; subscriptions the service calls gone (404, 410)
     * are forgotten.
     *
     * @param list<string> $userIds
     * @param array{title: string, body: string, url?: string} $message
     */
    public static function send(App $app, array $userIds, array $message): int
    {
        $vapid = self::vapid($app);
        if ($vapid === null || $userIds === []) {
            return 0;
        }
        $db = $app->db();
        $payload = (string) json_encode(['title' => mb_substr($message['title'], 0, 120), 'body' => mb_substr($message['body'], 0, 400), 'url' => $message['url'] ?? null], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $sent = 0;
        foreach (array_chunk($userIds, 200) as $chunk) {
            $subs = $db->all('SELECT * FROM {{push_subscriptions}} WHERE user_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk);
            foreach ($subs as $sub) {
                // Stored only after passing the push-service check; checked again in case the admin's list shrank.
                if (!PushEndpoint::isPushServiceEndpoint((string) $sub['endpoint'], PushEndpoint::extraSuffixes($vapid['extraHosts']))) {
                    continue;
                }
                try {
                    $req = WebPush::request(['endpoint' => (string) $sub['endpoint'], 'p256dh' => (string) $sub['p256dh'], 'auth' => (string) $sub['auth']], $payload, $vapid);
                    $r = Http::request('POST', $req['url'], $req['headers'], $req['body'], ['timeout' => 10, 'followRedirects' => false]);
                } catch (HttpException | \InvalidArgumentException $e) {
                    Log::warning('Push not sent: ' . $e->getMessage(), ['subscription' => $sub['id']]);
                    continue;
                }
                if ($r->status === 404 || $r->status === 410) {
                    $db->delete('push_subscriptions', ['id' => $sub['id']]);
                } elseif ($r->ok()) {
                    $sent++;
                } else {
                    Log::warning('Push refused (' . $r->status . ')', ['subscription' => $sub['id']]);
                }
            }
        }
        return $sent;
    }
}
