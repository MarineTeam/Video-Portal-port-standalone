<?php
/**
 * Plugin Name: Live streaming
 * Slug:        live-streaming
 * Version:     1.0.0
 * Description: Shows a "Live now" banner and /live page for admin-scheduled live streams, with a push notification when one goes live.
 * Author:      Marine Team
 * Requires PHP: 8.2
 * Requires App: 3.0
 */

declare(strict_types=1);

require_once __DIR__ . '/src/Chat.php';

use App\Core\ApiError;
use App\Core\App;
use App\Core\Cache;
use App\Core\Db;
use App\Core\Hooks;
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
use App\Modules\Plugins\PluginStates;
use App\Modules\Profile\Inbox;
use App\Modules\Push\Push;
use MarineTeam\Plugins\Live\Chat;

/**
 * A live event: rows pointing at a stream already hosted somewhere else
 * (YouTube, Boxcast, Resi — Bunny Stream has no live ingest), so all this
 * holds is when it starts, where the player is, and whether there is a
 * chat beside it.
 *
 * /live shows whatever is on now and a countdown to the next one
 * otherwise; a "Live now" banner and a nav entry appear across the site
 * while a stream is running; publishing one notifies members the way
 * publishing a video does. Managed at /admin/live (manage_plugins);
 * the chat is moderated by anybody with moderate_comments.
 */
return new class (__DIR__) extends BasePlugin {
    /** A member's own flood limit, on top of slow mode. */
    public const PER_MINUTE = 20;
    /** The banner asks the database at most this often. */
    private const BANNER_SECONDS = 30;
    /** Take-downs a poll carries back, so a tab a few seconds behind drops them too. */
    private const REMOVED_LIMIT = 200;

    /** @var list<array<string, mixed>> streams published this request */
    private array $published = [];

    public function boot(Hooks $hooks, App $app): void
    {
        $this->useLang($hooks);
        $this->useTemplates($hooks);
        $hooks->on('routes.register', function (Router $r) use ($app): void {
            $member = Middleware::member($app);
            $flood = Middleware::throttle($app, 'live-chat', self::PER_MINUTE, 60);
            $moderate = Middleware::can($app, 'moderate_comments', anywhere: true);
            $manage = Middleware::can($app, 'manage_plugins');

            $r->get('/live', fn () => $this->page($app));
            $r->get('/api/live/[id]/chat', fn (Request $req, array $p) => $this->poll($app, $req, $p['id']));
            $r->post('/api/live/[id]/chat', fn (Request $req, array $p) => $this->say($app, $req, $p['id']), [$member, $flood]);
            $r->post('/api/live/[id]/chat/mute', fn (Request $req, array $p) => $this->mute($app, $req, $p['id']), [$moderate]);
            $r->add('DELETE', '/api/live/[id]/chat/[messageId]', fn (Request $req, array $p) => $this->takeDown($app, $p['id'], $p['messageId']), [$member]);

            $r->get('/admin/live', fn () => $app->page('live-streaming/admin', ['title' => 'Live streams', 'rows' => $this->all($app->db())], 200, 'layouts/admin'), [$manage]);
            $r->get('/api/admin/live', fn () => Response::json($this->all($app->db())), [$manage]);
            $r->post('/api/admin/live', fn (Request $req) => $this->save($app, $req, null), [$manage]);
            $r->add('PATCH', '/api/admin/live/[id]', fn (Request $req, array $p) => $this->save($app, $req, $p['id']), [$manage]);
            $r->add('DELETE', '/api/admin/live/[id]', function (Request $req, array $p) use ($app): Response {
                $row = $this->find($app->db(), $p['id']);
                $app->db()->delete('live_streams', ['id' => $row['id']]);
                $this->changed($app, 'live.delete', (string) $row['id']);
                return Response::json(['ok' => true]);
            }, [$manage]);
        });
        $hooks->on('render.page_top', function (App $app): void {
            $now = $this->liveNow($app);
            if ($now !== null && $app->request()->path !== '/live') {
                echo $app->view()->partial('live-streaming/banner', ['title' => (string) $now['title']]);
            }
        });
        $hooks->filter('nav.sections', function (array $nav) use ($app): array {
            return $this->liveNow($app) === null ? $nav : [...$nav, ['href' => '/live', 'label' => t('live.now'), 'icon' => 'live']];
        });
        $hooks->filter('sitemap.urls', fn (array $urls) => [...$urls, '/live']);
        $hooks->on('live.published', function (array $stream) use ($app): void {
            if ($this->published === []) {
                register_shutdown_function(function () use ($app): void {
                    if (function_exists('fastcgi_finish_request')) {
                        fastcgi_finish_request();
                    }
                    foreach ($this->published as $row) {
                        try {
                            $this->notify($app, $row);
                        } catch (\Throwable $e) {
                            Log::error('Notifying members of a stream failed: ' . $e->getMessage(), ['stream' => $row['id']]);
                        }
                    }
                });
            }
            $this->published[] = $stream;
        });
    }

    // -- The streams ------------------------------------------------------

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private static function at(mixed $value): ?\DateTimeImmutable
    {
        return $value === null || $value === '' ? null : new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC'));
    }

    /**
     * The published stream running right now, or null. Cached briefly: it
     * is asked for on every page in the site.
     *
     * @return array<string, mixed>|null
     */
    private function liveNow(App $app): ?array
    {
        $row = Cache::remember('live:now', self::BANNER_SECONDS, function () use ($app) {
            $now = Db::datetime($this->now());
            $assumed = Chat::ASSUMED_LENGTH;
            return $app->db()->one(
                "SELECT id, title FROM {{live_streams}}
                 WHERE published = 1 AND start_at <= ?
                   AND COALESCE(end_at, start_at + INTERVAL $assumed SECOND) >= ?
                 ORDER BY start_at DESC LIMIT 1",
                [$now, $now],
            ) ?? false;
        });
        return is_array($row) ? $row : null;
    }

    /**
     * What /live shows: the stream whose evening this is — from half an
     * hour before it starts until the chat closes an hour after it ends —
     * and the ones still to come.
     *
     * @return array{current: ?array<string, mixed>, upcoming: list<array<string, mixed>>}
     */
    private function schedule(Db $db): array
    {
        $now = Db::datetime($this->now());
        $early = Chat::OPENS_BEFORE;
        $over = Chat::ASSUMED_LENGTH + Chat::CLOSES_AFTER;
        $current = $db->one(
            "SELECT * FROM {{live_streams}}
             WHERE published = 1 AND start_at <= ? + INTERVAL $early SECOND
               AND COALESCE(end_at + INTERVAL " . Chat::CLOSES_AFTER . " SECOND, start_at + INTERVAL $over SECOND) >= ?
             ORDER BY start_at DESC LIMIT 1",
            [$now, $now],
        );
        $upcoming = $db->all(
            'SELECT * FROM {{live_streams}} WHERE published = 1 AND start_at > ? AND id <> ? ORDER BY start_at LIMIT 10',
            [$now, $current['id'] ?? ''],
        );
        return ['current' => $current, 'upcoming' => $upcoming];
    }

    private function page(App $app): Response
    {
        ['current' => $current, 'upcoming' => $upcoming] = $this->schedule($app->db());
        // The chat belongs to the stream whose evening this is, never to one
        // three weeks off that the page is only counting down to.
        $chat = $current === null ? null : $this->chatView($app, $current);
        $stream = $current ?? array_shift($upcoming);
        $start = $stream !== null ? self::at($stream['start_at']) : null;
        $started = $start !== null && $start <= $this->now();
        if ($started && $stream !== null) {
            $host = parse_url((string) $stream['embed_url'], PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $app->addCspSource('frame', 'https://' . $host);
            }
        }
        return $app->page('live-streaming/page', [
            'title' => t('live.title'),
            'stream' => $stream === null ? null : Json::row('live_streams', $stream, ['chat_enabled', 'chat_slow_mode']),
            'started' => $started,
            'startsAt' => $start !== null ? Json::instant(Db::datetime($start)) : null,
            'upcoming' => array_map(fn (array $s) => [
                'title' => (string) $s['title'],
                'startsAt' => Json::instant((string) $s['start_at']),
            ], $upcoming),
            'chat' => $chat,
            'script' => $this->asset('live-chat.js'),
        ]);
    }

    // -- The chat ---------------------------------------------------------

    /**
     * The chat as the page first draws it.
     *
     * @param array<string, mixed> $stream
     * @return array<string, mixed>
     */
    private function chatView(App $app, array $stream): array
    {
        $state = $this->state($stream);
        $viewer = $app->currentUser();
        return [
            'streamId' => (string) $stream['id'],
            'state' => $state,
            'slowMode' => (int) $stream['chat_slow_mode'],
            'signedIn' => $viewer->isSignedIn(),
            'muted' => $this->isMuted($app->db(), (string) $stream['id'], $viewer->id()),
            'canModerate' => $viewer->canAnywhere('moderate_comments'),
            'messages' => $state === Chat::OFF ? [] : $this->messages($app, $stream, null),
        ];
    }

    /** @param array<string, mixed> $stream */
    private function state(array $stream): string
    {
        return Chat::state(
            (bool) $stream['chat_enabled'],
            self::at($stream['start_at']) ?? $this->now(),
            self::at($stream['end_at']),
            $this->now(),
        );
    }

    /**
     * @param array<string, mixed> $stream
     * @return list<array<string, mixed>>
     */
    private function messages(App $app, array $stream, ?string $since): array
    {
        $db = $app->db();
        $params = [$stream['id']];
        $sql = 'SELECT * FROM {{live_chat_messages}} WHERE stream_id = ?';
        if ($since !== null) {
            $sql .= ' AND id > ?';
            $params[] = $since;
        }
        $rows = $db->all($sql . ' ORDER BY id LIMIT 200', $params);
        if ($since === null && count($rows) === 200) {
            $rows = array_slice($rows, -200);
        }
        return Chat::visible($rows, $app->currentUser()->id(), $app->currentUser()->canAnywhere('moderate_comments'));
    }

    /**
     * GET /api/live/[id]/chat?since=<id>: everything after the last message
     * this tab saw, plus the ids taken down since — so a tab a few seconds
     * behind drops a removed message rather than keeping it on screen.
     */
    private function poll(App $app, Request $req, string $id): Response
    {
        $stream = $this->readable($app, $id);
        $data = Validator::check($req->query, ['since' => ['id', 'nullable']]);
        $state = $this->state($stream);
        if ($state === Chat::OFF) {
            return Response::json(['state' => $state, 'slowMode' => 0, 'messages' => [], 'removed' => []]);
        }
        return Response::json([
            'state' => $state,
            'slowMode' => (int) $stream['chat_slow_mode'],
            'muted' => $this->isMuted($app->db(), (string) $stream['id'], $app->currentUser()->id()),
            'messages' => $this->messages($app, $stream, isset($data['since']) ? (string) $data['since'] : null),
            'removed' => array_map('strval', $app->db()->column(
                'SELECT id FROM {{live_chat_messages}} WHERE stream_id = ? AND hidden = 1 ORDER BY id DESC LIMIT ' . self::REMOVED_LIMIT,
                [$stream['id']],
            )),
        ]);
    }

    /** POST /api/live/[id]/chat: a member says something. */
    private function say(App $app, Request $req, string $id): Response
    {
        $stream = $this->readable($app, $id);
        if ($this->state($stream) !== Chat::OPEN) {
            throw ApiError::invalid(t('live.chatClosed'));
        }
        $db = $app->db();
        $userId = (string) $app->currentUser()->id();
        if ($this->isMuted($db, (string) $stream['id'], $userId)) {
            throw ApiError::forbidden(t('live.muted'));
        }
        $data = Validator::check($req->input(), ['body' => ['text', 'required', 'min' => 1, 'max' => Chat::MAX_LENGTH * 20]]);
        $body = Chat::clean((string) $data['body']);
        if ($body === null) {
            throw ApiError::invalid(t('live.tooLong'));
        }
        $last = $db->value('SELECT created_at FROM {{live_chat_messages}} WHERE stream_id = ? AND user_id = ? ORDER BY id DESC LIMIT 1', [$stream['id'], $userId]);
        $wait = Chat::waitSeconds((int) $stream['chat_slow_mode'], self::at($last), $this->now());
        if ($wait > 0) {
            throw new ApiError(t('live.slowMode', ['seconds' => (string) $wait]), 429, 'slow_mode');
        }
        $row = ['id' => Id::new(), 'stream_id' => $stream['id'], 'user_id' => $userId, 'author_name' => $this->byline($app), 'body' => $body];
        $db->insert('live_chat_messages', $row);
        $saved = $db->one('SELECT * FROM {{live_chat_messages}} WHERE id = ?', [$row['id']]);
        return Response::json(Chat::visible([$saved ?? $row + ['hidden' => 0, 'created_at' => Db::now()]], $userId, false)[0], 201);
    }

    /** DELETE /api/live/[id]/chat/[messageId]: one's own, or anybody's for a moderator. */
    private function takeDown(App $app, string $id, string $messageId): Response
    {
        $stream = $this->readable($app, $id);
        $db = $app->db();
        $message = Id::isValid($messageId) ? $db->one('SELECT * FROM {{live_chat_messages}} WHERE id = ? AND stream_id = ?', [$messageId, $stream['id']]) : null;
        if ($message === null) {
            throw ApiError::notFound();
        }
        $mine = $message['user_id'] === $app->currentUser()->id();
        $moderator = $app->currentUser()->canAnywhere('moderate_comments');
        if (!$mine && !$moderator) {
            throw ApiError::forbidden();
        }
        // Hidden rather than deleted: the same message can't be re-sent past
        // a moderator by reposting, and the record of the decision survives.
        $db->update('live_chat_messages', ['hidden' => 1], ['id' => $message['id']]);
        if (!$mine) {
            Audit::log($db, (string) $app->currentUser()->email(), 'live.chat.hide', 'LiveChatMessage', (string) $message['id']);
        }
        return Response::json(['ok' => true]);
    }

    /**
     * POST /api/live/[id]/chat/mute {messageId, muted?}: stops whoever wrote
     * that message from writing in this stream's chat, and hides what they
     * have already written. The person is named by their message, so no
     * account id ever travels to a chat. Lifting a mute lets them write
     * again; what was taken down stays down.
     */
    private function mute(App $app, Request $req, string $id): Response
    {
        $stream = $this->readable($app, $id);
        $db = $app->db();
        $data = Validator::check($req->input(), ['messageId' => ['id', 'required'], 'muted' => ['bool']]);
        $message = $db->one('SELECT * FROM {{live_chat_messages}} WHERE id = ? AND stream_id = ?', [$data['messageId'], $stream['id']]);
        if ($message === null) {
            throw ApiError::notFound();
        }
        $muted = $data['muted'] ?? true;
        $userId = (string) $message['user_id'];
        if ($muted) {
            $db->run('INSERT IGNORE INTO {{live_chat_mutes}} (id, stream_id, user_id, muted_by) VALUES (?, ?, ?, ?)', [Id::new(), $stream['id'], $userId, (string) $app->currentUser()->email()]);
            $db->run('UPDATE {{live_chat_messages}} SET hidden = 1 WHERE stream_id = ? AND user_id = ?', [$stream['id'], $userId]);
        } else {
            $db->run('DELETE FROM {{live_chat_mutes}} WHERE stream_id = ? AND user_id = ?', [$stream['id'], $userId]);
        }
        Audit::log($db, (string) $app->currentUser()->email(), $muted ? 'live.chat.mute' : 'live.chat.unmute', 'LiveStream', (string) $stream['id']);
        return Response::json(['muted' => (bool) $muted]);
    }

    private function isMuted(Db $db, string $streamId, ?string $userId): bool
    {
        return $userId !== null && $db->value('SELECT 1 FROM {{live_chat_mutes}} WHERE stream_id = ? AND user_id = ?', [$streamId, $userId]) !== null;
    }

    /** The name a message carries: never an email address. */
    private function byline(App $app): string
    {
        $user = $app->currentUser()->user() ?? [];
        $fields = PluginStates::enabled($app->db(), 'profiles') ? ['display_name', 'name'] : ['name'];
        foreach ($fields as $field) {
            $value = trim((string) ($user[$field] ?? ''));
            if ($value !== '' && !str_contains($value, '@')) {
                return mb_substr($value, 0, 255);
            }
        }
        return t('live.aMember');
    }

    /**
     * The stream behind a chat call: published for everybody, unpublished
     * for whoever may manage it, 404 otherwise.
     *
     * @return array<string, mixed>
     */
    private function readable(App $app, string $id): array
    {
        $stream = $this->find($app->db(), $id);
        if (!$stream['published'] && !$app->currentUser()->can('manage_plugins')) {
            throw ApiError::notFound();
        }
        return $stream;
    }

    // -- Administration ---------------------------------------------------

    /** @return list<array<string, mixed>> */
    private function all(Db $db): array
    {
        return Json::rows('live_streams', $db->all('SELECT * FROM {{live_streams}} ORDER BY start_at DESC LIMIT 500'));
    }

    /** @return array<string, mixed> */
    private function find(Db $db, string $id): array
    {
        $row = Id::isValid($id) ? $db->one('SELECT * FROM {{live_streams}} WHERE id = ?', [$id]) : null;
        return $row ?? throw ApiError::notFound();
    }

    private function save(App $app, Request $req, ?string $id): Response
    {
        $data = Validator::check($req->input(), [
            'title' => ['text', 'required', 'min' => 1, 'max' => 255],
            'description' => ['text', 'nullable', 'max' => 5000],
            'embedUrl' => ['url', 'required'],
            'coverImageUrl' => ['url', 'nullable'],
            'published' => ['bool'],
            'startAt' => ['datetime', 'required'],
            'endAt' => ['datetime', 'nullable'],
            'chatEnabled' => ['bool'],
            'chatSlowMode' => ['int', 'min' => 0, 'max' => 3600],
        ], partial: $id !== null);
        foreach (['embedUrl', 'coverImageUrl'] as $key) {
            // An http embed inside an https page is blocked by the browser
            // anyway, and says out loud where the congregation is watching.
            if (isset($data[$key]) && !str_starts_with(strtolower((string) $data[$key]), 'https://')) {
                throw ApiError::invalid(t('live.httpsOnly'));
            }
        }
        $row = [];
        foreach (['title' => 'title', 'description' => 'description', 'embedUrl' => 'embed_url', 'coverImageUrl' => 'cover_image_url', 'chatSlowMode' => 'chat_slow_mode'] as $key => $column) {
            if (array_key_exists($key, $data)) {
                $row[$column] = $data[$key];
            }
        }
        foreach (['published' => 'published', 'chatEnabled' => 'chat_enabled'] as $key => $column) {
            if (array_key_exists($key, $data)) {
                $row[$column] = (int) (bool) $data[$key];
            }
        }
        foreach (['startAt' => 'start_at', 'endAt' => 'end_at'] as $key => $column) {
            if (array_key_exists($key, $data)) {
                $row[$column] = $data[$key] instanceof \DateTimeInterface ? Db::datetime($data[$key]) : null;
            }
        }
        $db = $app->db();
        $was = $id === null ? null : $this->find($db, $id);
        if ($id === null) {
            $id = $db->insert('live_streams', $row + ['id' => Id::new()]);
        } elseif ($row !== []) {
            $db->update('live_streams', $row, ['id' => $id]);
        }
        $saved = $this->find($db, $id);
        if ($saved['end_at'] !== null && $saved['end_at'] <= $saved['start_at']) {
            throw ApiError::invalid(t('live.endsBeforeItStarts'));
        }
        $this->changed($app, $was === null ? 'live.create' : 'live.update', $id);
        if ($saved['published'] && !($was['published'] ?? false)) {
            $app->hooks->do('live.published', $saved);
        }
        return Response::json(Json::row('live_streams', $saved), $was === null ? 201 : 200);
    }

    private function changed(App $app, string $action, string $id): void
    {
        Cache::forget('live:now');
        Audit::log($app->db(), (string) $app->currentUser()->email(), $action, 'LiveStream', $id);
    }

    /**
     * Publishing a stream tells members, the way publishing a video does:
     * a stream is public content, so everybody who may sign in hears of it.
     *
     * @param array<string, mixed> $stream
     */
    public function notify(App $app, array $stream): int
    {
        $db = $app->db();
        $title = t('live.notifyTitle');
        $when = self::at($stream['start_at']);
        $body = (string) $stream['title'] . ($when !== null ? ' — ' . $when->format('D j M, H:i') . ' UTC' : '');
        $url = Url::absolute('/live');
        $ids = array_map('strval', $db->column('SELECT id FROM {{users}} WHERE authorized = 1'));
        foreach ($ids as $userId) {
            Inbox::add($db, $userId, $title, $body, $url);
        }
        return Push::send($app, $ids, ['title' => $title, 'body' => $body, 'url' => $url]);
    }
};
