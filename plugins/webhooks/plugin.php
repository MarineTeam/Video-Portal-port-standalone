<?php
/**
 * Plugin Name: Webhooks
 * Slug:        webhooks
 * Version:     1.0.0
 * Description: Posts a JSON payload to admin-configured URLs whenever a series or video is published.
 * Author:      Marine Team
 * Requires PHP: 8.2
 * Requires App: 3.0
 */

declare(strict_types=1);

use App\Core\ApiError;
use App\Core\App;
use App\Core\Crypto;
use App\Core\Hooks;
use App\Core\Http;
use App\Core\HttpException;
use App\Core\Id;
use App\Core\Json;
use App\Core\Log;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Url;
use App\Core\Validator;
use App\Modules\Audit\Audit;
use App\Modules\Plugins\BasePlugin;

/**
 * When a series or video goes live, every active webhook gets a JSON POST:
 * {event, id, title, slug, url, memberOnly, publishedAt}, signed with the
 * webhook's secret, when it has one, as X-Webhook-Signature (hex
 * HMAC-SHA256 of the body). The addresses are typed by an administrator,
 * so each is checked as a public address when saved and again, through the
 * untrusted-URL fetcher, on every delivery. Deliveries go out after the
 * admin's response has been sent where the host allows, so a slow receiver
 * never holds up a save; a failure is logged, not retried.
 */
return new class (__DIR__) extends BasePlugin {
    /** @var list<array<string, mixed>> */
    private array $pending = [];

    public function boot(Hooks $hooks, App $app): void
    {
        $this->useTemplates($hooks);
        foreach (['series', 'video'] as $kind) {
            $hooks->on("$kind.published", function (array $row) use ($kind, $app): void {
                $this->queue($app, [
                    'event' => "$kind.published",
                    'id' => (string) $row['id'],
                    'title' => (string) $row['title'],
                    'slug' => (string) $row['slug'],
                    'url' => Url::absolute(($kind === 'series' ? '/series/' : '/videos/') . $row['slug']),
                    'memberOnly' => (bool) ($row['member_only'] ?? false),
                    'publishedAt' => gmdate('Y-m-d\TH:i:s\Z'),
                ]);
            });
        }
        $hooks->on('routes.register', function (Router $r) use ($app): void {
            $admin = Middleware::admin($app);
            $r->get('/admin/webhooks', fn () => $app->page('webhooks/admin', ['title' => 'Webhooks', 'hooks' => $this->all($app)], 200, 'layouts/admin'), [$admin]);
            $r->get('/api/admin/webhooks', fn () => Response::json($this->all($app)), [$admin]);
            $r->post('/api/admin/webhooks', fn (Request $req) => $this->save($app, $req, null), [$admin]);
            $r->add('PATCH', '/api/admin/webhooks/[id]', fn (Request $req, array $p) => $this->save($app, $req, $p['id']), [$admin]);
            $r->add('DELETE', '/api/admin/webhooks/[id]', function (Request $req, array $p) use ($app): Response {
                $row = $this->find($app, $p['id']);
                $app->db()->delete('webhooks', ['id' => $row['id']]);
                Audit::log($app->db(), (string) $app->currentUser()->email(), 'webhook.delete', 'Webhook', (string) $row['id'], (string) $row['url']);
                return Response::json(['ok' => true]);
            }, [$admin]);
            // The port's: send a test delivery now and say what came back.
            $r->post('/api/admin/webhooks/[id]/test', function (Request $req, array $p) use ($app): Response {
                $row = $this->find($app, $p['id']);
                $result = $this->deliver($row, ['event' => 'test', 'sentAt' => gmdate('Y-m-d\TH:i:s\Z'), 'site' => Url::absolute('/')]);
                if (!$result['ok']) {
                    throw new ApiError('The test wasn’t delivered: ' . $result['detail'], 502, 'delivery_failed');
                }
                return Response::json($result);
            }, [$admin]);
        });
    }

    /** @param array<string, mixed> $payload */
    private function queue(App $app, array $payload): void
    {
        if ($this->pending === []) {
            register_shutdown_function(function () use ($app): void {
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
                $this->flush($app);
            });
        }
        $this->pending[] = $payload;
    }

    /** Sends what this request queued to every active webhook. */
    public function flush(App $app): int
    {
        $sent = 0;
        $hooks = $app->db()->all('SELECT * FROM {{webhooks}} WHERE active = 1');
        foreach ($this->pending as $payload) {
            foreach ($hooks as $hook) {
                $result = $this->deliver($hook, $payload);
                if (!$result['ok']) {
                    Log::warning('Webhook delivery failed: ' . $result['detail'], ['webhook' => $hook['id'], 'event' => $payload['event']]);
                }
                $sent++;
            }
        }
        $this->pending = [];
        return $sent;
    }

    /**
     * @param array<string, mixed> $hook
     * @param array<string, mixed> $payload
     * @return array{ok: bool, status: int, detail: string}
     */
    private function deliver(array $hook, array $payload): array
    {
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers = ['Content-Type' => 'application/json', 'User-Agent' => 'MarineTeam-Webhooks/1.0'];
        $secret = $hook['secret'] !== null && $hook['secret'] !== '' ? Crypto::decrypt((string) $hook['secret']) : null;
        if (is_string($secret) && $secret !== '') {
            $headers['X-Webhook-Signature'] = hash_hmac('sha256', $body, $secret);
        }
        try {
            $r = Http::fetchUntrusted('POST', (string) $hook['url'], $headers, $body, 64 * 1024);
        } catch (HttpException $e) {
            return ['ok' => false, 'status' => 0, 'detail' => $e->getMessage()];
        }
        return ['ok' => $r->ok(), 'status' => $r->status, 'detail' => $r->ok() ? 'Delivered.' : 'The receiver answered ' . $r->status . '.'];
    }

    /** @return list<array<string, mixed>> without the secret, which is only ever "set" */
    private function all(App $app): array
    {
        return array_map(fn (array $r) => ['secretSet' => $r['secret'] !== null && $r['secret'] !== ''] + Json::row('webhooks', $r, ['secret']), $app->db()->all('SELECT * FROM {{webhooks}} ORDER BY created_at'));
    }

    /** @return array<string, mixed> */
    private function find(App $app, string $id): array
    {
        $row = Id::isValid($id) ? $app->db()->one('SELECT * FROM {{webhooks}} WHERE id = ?', [$id]) : null;
        return $row ?? throw ApiError::notFound();
    }

    private function save(App $app, Request $req, ?string $id): Response
    {
        $data = Validator::check($req->input(), [
            'url' => ['url', 'required'],
            'secret' => ['string', 'nullable', 'max' => 500],
            'active' => ['bool'],
        ], partial: $id !== null);
        $row = [];
        if (isset($data['url'])) {
            if (!Http::isPublicHttpUrl((string) $data['url'])) {
                throw ApiError::invalid('A webhook must go to a public web address: not this machine, a private network or a bare name.');
            }
            $row['url'] = (string) $data['url'];
        }
        if (array_key_exists('secret', $data)) {
            // Empty clears it; the form never shows the saved one.
            $row['secret'] = $data['secret'] === null || $data['secret'] === '' ? null : Crypto::encrypt((string) $data['secret']);
        }
        if (array_key_exists('active', $data)) {
            $row['active'] = $data['active'] ? 1 : 0;
        }
        $db = $app->db();
        if ($id === null) {
            $id = $db->insert('webhooks', $row + ['id' => Id::new()]);
        } else {
            $this->find($app, $id);
            if ($row !== []) {
                $db->update('webhooks', $row, ['id' => $id]);
            }
        }
        Audit::log($db, (string) $app->currentUser()->email(), 'webhook.save', 'Webhook', $id, (string) ($row['url'] ?? ''));
        foreach ($this->all($app) as $hook) {
            if ($hook['id'] === $id) {
                return Response::json($hook, $req->method === 'POST' ? 201 : 200);
            }
        }
        throw ApiError::notFound();
    }
};
