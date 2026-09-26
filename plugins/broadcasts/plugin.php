<?php
/**
 * Plugin Name: Broadcasts
 * Slug:        broadcasts
 * Version:     1.0.0
 * Description: One message to everybody, or to one group, by email, text and push — with the count it will reach, and the reason for the rest, before it goes.
 * Author:      Marine Team
 * Requires PHP: 8.2
 * Requires App: 3.0
 */

declare(strict_types=1);

require_once __DIR__ . '/src/Broadcast.php';
require_once __DIR__ . '/src/Skipped.php';

use App\Core\ApiError;
use App\Core\App;
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
use App\Modules\Jobs\Scheduler;
use App\Modules\Plugins\BasePlugin;
use App\Modules\Push\Push;
use App\Services\Email\Message;
use App\Services\Sms\SmsError;
use App\Services\Sms\Texter;
use App\Support\Sms;
use MarineTeam\Plugins\Broadcasts\Broadcast;
use MarineTeam\Plugins\Broadcasts\Skipped;

/**
 * One message to everybody, or to one group, from /admin/broadcasts.
 *
 * The list is frozen before anything goes out — one row per person per
 * channel, with the address copied in — and each is marked as it goes. That
 * is what makes a send resumable across a closed laptop: the rest go later,
 * rather than everybody getting it twice. A unique index on (broadcast,
 * channel, address) is the backstop against a double-clicked button.
 *
 * The batch loop lives in the browser: the admin screen calls /send
 * repeatedly. One request that tried to send four hundred emails would be
 * killed at the host's timeout with no record of how far it got, and this
 * way the same mechanism draws a progress bar. The job is the backstop for a
 * closed laptop, not the delivery path.
 */
return new class (__DIR__) extends BasePlugin {
    /** How many recipients one call works through. */
    public const BATCH = 25;

    /** How long a batch may run before it stops and asks to be called again. */
    public const BUDGET = 12.0;

    public function boot(Hooks $hooks, App $app): void
    {
        $this->useLang($hooks);
        $this->useTemplates($hooks);
        $hooks->on('routes.register', function (Router $r) use ($app): void {
            $manage = Middleware::can($app, 'manage_events');

            $r->get('/admin/broadcasts', fn () => $this->listPage($app), [$manage]);
            $r->get('/admin/broadcasts/[id]', fn (Request $req, array $p) => $this->onePage($app, (string) $p['id']), [$manage]);
            $r->get('/api/admin/broadcasts', fn () => Response::json(['broadcasts' => $this->recent($app)]), [$manage]);
            $r->post('/api/admin/broadcasts', fn (Request $req) => $this->save($app, $req, null), [$manage]);
            $r->post('/api/admin/broadcasts/preview', fn (Request $req) => $this->preview($app, $req), [$manage]);
            $r->get('/api/admin/broadcasts/[id]', fn (Request $req, array $p) => Response::json($this->present($app, $this->find($app->db(), (string) $p['id']))), [$manage]);
            $r->add('PATCH', '/api/admin/broadcasts/[id]', fn (Request $req, array $p) => $this->save($app, $req, (string) $p['id']), [$manage]);
            $r->add('DELETE', '/api/admin/broadcasts/[id]', fn (Request $req, array $p) => $this->delete($app, (string) $p['id']), [$manage]);
            $r->post('/api/admin/broadcasts/[id]/test', fn (Request $req, array $p) => $this->sendTest($app, (string) $p['id']), [$manage]);
            $r->post('/api/admin/broadcasts/[id]/send', fn (Request $req, array $p) => $this->send($app, (string) $p['id']), [$manage]);
            $r->post('/api/admin/broadcasts/[id]/cancel', fn (Request $req, array $p) => $this->cancel($app, (string) $p['id']), [$manage]);
        });
        $hooks->on('jobs.register', function (Scheduler $s) use ($app): void {
            // The backstop for a closed laptop, not the delivery path.
            $s->register('broadcasts', 300, fn (float $deadline) => $this->drain($app, $deadline), 15.0);
        });
    }

    private function texter(App $app): Texter
    {
        return new Texter($app->services());
    }

    // -- Reading --------------------------------------------------------------

    /** @return array<string, mixed> */
    private function find(Db $db, string $id): array
    {
        $row = Id::isValid($id) ? $db->one('SELECT * FROM {{broadcasts}} WHERE id = ?', [$id]) : null;
        return $row ?? throw ApiError::notFound();
    }

    /** @return list<string> */
    private function channelsOf(array $broadcast): array
    {
        $channels = json_decode((string) $broadcast['channels'], true);
        return array_values(array_intersect(Broadcast::CHANNELS, is_array($channels) ? $channels : []));
    }

    /**
     * @param array<string, mixed> $broadcast
     * @return array<string, mixed>
     */
    private function present(App $app, array $broadcast): array
    {
        $db = $app->db();
        $counts = [];
        foreach ($db->all('SELECT status, COUNT(*) AS n FROM {{broadcast_recipients}} WHERE broadcast_id = ? GROUP BY status', [$broadcast['id']]) as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }
        $failures = $db->all(
            'SELECT name, address, channel, error FROM {{broadcast_recipients}} WHERE broadcast_id = ? AND status = ? ORDER BY name LIMIT 200',
            [$broadcast['id'], Broadcast::FAILED],
        );
        $delivered = (int) $db->value('SELECT COUNT(*) FROM {{broadcast_recipients}} WHERE broadcast_id = ? AND delivery_status = ?', [$broadcast['id'], 'DELIVERED']);
        return [
            'id' => (string) $broadcast['id'],
            'subject' => (string) $broadcast['subject'],
            'body' => (string) $broadcast['body'],
            'channels' => $this->channelsOf($broadcast),
            'audience' => (string) $broadcast['audience'],
            'audienceId' => $broadcast['audience_id'],
            'audienceName' => $broadcast['audience_name'],
            'audienceLabel' => Broadcast::audienceLabel(['audience' => $broadcast['audience'], 'audienceName' => $broadcast['audience_name']]),
            'status' => (string) $broadcast['status'],
            'createdBy' => (string) $broadcast['created_by'],
            'createdAt' => Json::instant((string) $broadcast['created_at']),
            'sentAt' => Json::instant($broadcast['sent_at']),
            'progress' => Broadcast::progressOf($counts),
            'delivered' => $delivered,
            'failures' => array_map(fn (array $f) => [
                'name' => $f['name'],
                'address' => (string) $f['address'],
                'channel' => (string) $f['channel'],
                'error' => $f['error'],
            ], $failures),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function recent(App $app): array
    {
        return array_map(
            fn (array $b) => $this->present($app, $b),
            $app->db()->all('SELECT * FROM {{broadcasts}} ORDER BY created_at DESC LIMIT 50'),
        );
    }

    private function listPage(App $app): Response
    {
        return $app->page('broadcasts/admin', [
            'title' => t('broadcasts.title'),
            'broadcasts' => $this->recent($app),
            'audiences' => $this->audienceOptions($app),
            'smsReady' => $this->texter($app)->isConfigured(),
            'pushReady' => Push::vapid($app) !== null,
            'script' => $this->asset('broadcasts.js'),
        ], 200, 'layouts/admin');
    }

    private function onePage(App $app, string $id): Response
    {
        $broadcast = $this->present($app, $this->find($app->db(), $id));
        return $app->page('broadcasts/one', [
            'title' => $broadcast['subject'],
            'broadcast' => $broadcast,
            'script' => $this->asset('broadcasts.js'),
        ], 200, 'layouts/admin');
    }

    /**
     * What can be chosen, with the names as they read now.
     *
     * @return array<string, list<array{id: string, name: string}>>
     */
    private function audienceOptions(App $app): array
    {
        $db = $app->db();
        $rows = static fn (string $sql) => array_map(
            fn (array $r) => ['id' => (string) $r['id'], 'name' => (string) $r['name']],
            $db->all($sql),
        );
        $out = [Broadcast::PERMISSION_GROUP => $rows('SELECT id, name FROM {{permission_groups}} ORDER BY name LIMIT 200')];
        // Only what this site actually has: a church with no events plugin
        // should not be offered an audience it cannot fill.
        foreach ([
            Broadcast::EVENT => 'SELECT id, title AS name FROM {{events}} ORDER BY starts_at DESC LIMIT 100',
            Broadcast::SMALL_GROUP => 'SELECT id, name FROM {{small_groups}} WHERE deleted_at IS NULL ORDER BY name LIMIT 200',
            Broadcast::TEAM => 'SELECT id, name FROM {{service_teams}} ORDER BY name LIMIT 200',
        ] as $audience => $sql) {
            try {
                $out[$audience] = $rows($sql);
            } catch (\Throwable) {
                $out[$audience] = [];
            }
        }
        return $out;
    }

    // -- Who it goes to ---------------------------------------------------------

    /**
     * The audience, as people rather than rows: everything planDelivery needs
     * to decide each of the three consent rules.
     *
     * @return list<array<string, mixed>>
     */
    private function peopleFor(App $app, string $audience, ?string $audienceId): array
    {
        $db = $app->db();
        $members = static fn (string $where, array $params) => $db->all(
            'SELECT u.id AS userId, u.name, u.email, u.phone, u.broadcast_emails, u.sms_opt_in,
                    (SELECT COUNT(*) FROM {{push_subscriptions}} s WHERE s.user_id = u.id) AS devices
             FROM {{users}} u ' . $where . ' LIMIT 5000',
            $params,
        );
        $shape = static fn (array $rows) => array_map(fn (array $u) => [
            'userId' => (string) $u['userId'],
            'name' => $u['name'],
            'email' => $u['email'],
            'phone' => $u['phone'],
            'broadcastEmails' => (bool) $u['broadcast_emails'],
            'smsOptIn' => (bool) $u['sms_opt_in'],
            'pushDevices' => (int) $u['devices'],
        ], $rows);

        if ($audience === Broadcast::EVERYONE) {
            return $shape($members('WHERE u.authorized = 1', []));
        }
        if ($audienceId === null || !Id::isValid($audienceId)) {
            throw ApiError::invalid(t('broadcasts.audience'));
        }
        if ($audience === Broadcast::PERMISSION_GROUP) {
            return $shape($members(
                'JOIN {{group_assignments}} g ON g.user_id = u.id AND g.group_id = ? GROUP BY u.id',
                [$audienceId],
            ));
        }
        if ($audience === Broadcast::SMALL_GROUP) {
            return $shape($members(
                'JOIN {{small_group_members}} m ON m.user_id = u.id AND m.group_id = ? AND m.status = ? ',
                [$audienceId, 'ACTIVE'],
            ));
        }
        if ($audience === Broadcast::TEAM) {
            return $shape($members(
                'JOIN {{service_team_members}} m ON m.user_id = u.id AND m.team_id = ? ',
                [$audienceId],
            ));
        }
        // An event's sign-ups: most of them have no account, and are
        // reachable at the address they typed and nowhere else.
        $out = [];
        foreach ($db->all(
            'SELECT r.name, r.email, r.phone, r.user_id, u.broadcast_emails, u.sms_opt_in, u.phone AS account_phone,
                    (SELECT COUNT(*) FROM {{push_subscriptions}} s WHERE s.user_id = r.user_id) AS devices
             FROM {{event_registrations}} r LEFT JOIN {{users}} u ON u.id = r.user_id
             WHERE r.event_id = ? AND r.status = ? LIMIT 5000',
            [$audienceId, 'GOING'],
        ) as $row) {
            $out[] = [
                'userId' => $row['user_id'],
                'name' => $row['name'],
                'email' => $row['email'],
                // The number on the form is not consent, and is not used: a
                // member is texted on the number they typed into their own
                // settings, and nobody else is texted at all.
                'phone' => $row['user_id'] === null ? null : $row['account_phone'],
                'broadcastEmails' => $row['user_id'] === null ? true : (bool) $row['broadcast_emails'],
                'smsOptIn' => $row['user_id'] !== null && (bool) $row['sms_opt_in'],
                'pushDevices' => (int) $row['devices'],
            ];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $broadcast
     * @return array{rows: list<array<string, mixed>>, skips: list<array<string, mixed>>, reached: int, missed: int}
     */
    private function plan(App $app, array $broadcast): array
    {
        return Broadcast::planDelivery(
            $this->peopleFor($app, (string) $broadcast['audience'], $broadcast['audience_id']),
            $this->channelsOf($broadcast),
            ['defaultCountry' => $this->texter($app)->defaultCountry()],
        );
    }

    /**
     * Who this will reach, before it is sent. Without that number "I told
     * everyone" is false and the people who got it assume everybody did.
     */
    private function preview(App $app, Request $req): Response
    {
        $data = Validator::check($req->input(), [
            'audience' => ['string', 'required', 'max' => 32],
            'audienceId' => ['id', 'nullable'],
            'channels' => ['array', 'required', 'max' => 3],
            'body' => ['text', 'nullable', 'max' => 20000],
        ]);
        $plan = Broadcast::planDelivery(
            $this->peopleFor($app, $this->audienceOf($data), $data['audienceId'] ?? null),
            array_map('strval', (array) $data['channels']),
            ['defaultCountry' => $this->texter($app)->defaultCountry()],
        );
        return Response::json([
            'reached' => $plan['reached'],
            'missed' => $plan['missed'],
            'messages' => count($plan['rows']),
            'skips' => Broadcast::summariseSkips($plan['skips'], $plan['rows']),
            'sms' => in_array(Broadcast::SMS, (array) $data['channels'], true)
                ? $this->texter($app)->cost((string) ($data['body'] ?? ''))
                : null,
        ]);
    }

    /** @param array<string, mixed> $data */
    private function audienceOf(array $data): string
    {
        $audience = (string) ($data['audience'] ?? Broadcast::EVERYONE);
        return in_array($audience, Broadcast::AUDIENCES, true) ? $audience : throw ApiError::invalid(t('broadcasts.audience'));
    }

    // -- Writing ----------------------------------------------------------------

    private function save(App $app, Request $req, ?string $id): Response
    {
        $db = $app->db();
        $existing = $id === null ? null : $this->find($db, $id);
        if ($existing !== null && (string) $existing['status'] !== Broadcast::DRAFT) {
            // A message already on its way is not editable: half the
            // recipients would have the old one.
            throw ApiError::conflict(t('broadcasts.alreadySent'));
        }
        $data = Validator::check($req->input(), [
            'subject' => ['string', ...($id === null ? ['required'] : []), 'max' => 500],
            'body' => ['text', ...($id === null ? ['required'] : []), 'max' => 20000],
            'channels' => ['array', 'max' => 3],
            'audience' => ['string', 'max' => 32],
            'audienceId' => ['id', 'nullable'],
        ]);
        $set = [];
        foreach (['subject' => 'subject', 'body' => 'body'] as $in => $column) {
            if (array_key_exists($in, $data)) {
                $set[$column] = trim((string) $data[$in]);
            }
        }
        if (array_key_exists('channels', $data)) {
            $channels = array_values(array_intersect(Broadcast::CHANNELS, array_map('strval', (array) $data['channels'])));
            if ($channels === []) {
                throw ApiError::invalid(t('broadcasts.needsChannel'));
            }
            $set['channels'] = (string) json_encode($channels);
        }
        if (array_key_exists('audience', $data)) {
            $set['audience'] = $this->audienceOf($data);
            $set['audience_id'] = $set['audience'] === Broadcast::EVERYONE ? null : ($data['audienceId'] ?? null);
            // Copied now, so the list still reads sensibly after the group is
            // renamed or deleted.
            $set['audience_name'] = $this->nameOfAudience($app, $set['audience'], $set['audience_id']);
        }
        if (($set['body'] ?? null) === '') {
            throw ApiError::invalid(t('broadcasts.needsBody'));
        }
        if ($existing === null) {
            $set += ['channels' => (string) json_encode([Broadcast::EMAIL]), 'audience' => Broadcast::EVERYONE];
            $broadcastId = $db->insert('broadcasts', $set + ['created_by' => (string) $app->currentUser()->email(), 'status' => Broadcast::DRAFT]);
        } else {
            $broadcastId = (string) $existing['id'];
            if ($set !== []) {
                $db->update('broadcasts', $set, ['id' => $broadcastId]);
            }
        }
        return Response::json($this->present($app, $this->find($db, $broadcastId)), $existing === null ? 201 : 200);
    }

    private function nameOfAudience(App $app, string $audience, ?string $audienceId): ?string
    {
        if ($audience === Broadcast::EVERYONE || $audienceId === null) {
            return null;
        }
        foreach ($this->audienceOptions($app)[$audience] ?? [] as $option) {
            if ($option['id'] === $audienceId) {
                return $option['name'];
            }
        }
        return null;
    }

    private function delete(App $app, string $id): Response
    {
        $db = $app->db();
        $broadcast = $this->find($db, $id);
        $db->delete('broadcasts', ['id' => $broadcast['id']]);
        Audit::log($db, (string) $app->currentUser()->email(), 'broadcast.delete', 'Broadcast', (string) $broadcast['id']);
        return Response::json(['ok' => true]);
    }

    /**
     * Send yourself a test first. The one thing that makes a typo in a
     * message to four hundred people survivable is having read it on a phone.
     */
    private function sendTest(App $app, string $id): Response
    {
        $broadcast = $this->find($app->db(), $id);
        $me = $app->currentUser()->user();
        if ($me === null) {
            throw ApiError::forbidden();
        }
        $done = [];
        foreach ($this->channelsOf($broadcast) as $channel) {
            $decided = Broadcast::forChannel([
                'userId' => (string) $me['id'],
                'email' => $me['email'],
                'phone' => $me['phone'],
                // A test goes to whoever asked for it, whatever they have
                // switched off: they are asking to see it.
                'broadcastEmails' => true,
                'smsOptIn' => true,
                'pushDevices' => (int) $app->db()->value('SELECT COUNT(*) FROM {{push_subscriptions}} WHERE user_id = ?', [$me['id']]),
            ], $channel, $this->texter($app)->defaultCountry());
            if ($decided['address'] === null) {
                continue;
            }
            try {
                $this->deliver($app, $broadcast, $channel, (string) $decided['address'], true);
                $done[] = $channel;
            } catch (\Throwable $e) {
                throw ApiError::invalid($e->getMessage());
            }
        }
        return Response::json(['ok' => true, 'channels' => $done]);
    }

    // -- Sending -----------------------------------------------------------------

    /**
     * Freeze the list, then work through it a batch at a time.
     *
     * Resolving happens once: the second call finds the rows already there
     * and carries on from where the first one stopped.
     */
    private function send(App $app, string $id): Response
    {
        $db = $app->db();
        $broadcast = $this->find($db, $id);
        if ((string) $broadcast['status'] === Broadcast::SENT) {
            throw ApiError::conflict(t('broadcasts.alreadySent'));
        }
        if ((string) $broadcast['status'] === Broadcast::DRAFT) {
            $written = $this->freeze($app, $broadcast);
            if ($written === 0) {
                throw ApiError::invalid(t('broadcasts.nothingToSend'));
            }
            Audit::log($db, (string) $app->currentUser()->email(), 'broadcast.send', 'Broadcast', (string) $broadcast['id'], $written . ' recipients');
        }
        $this->workThrough($app, $broadcast, microtime(true) + self::BUDGET);
        return Response::json($this->present($app, $this->find($db, $id)));
    }

    /**
     * One row per person per channel, with the address copied in, before
     * anything is sent — so a changed number cannot split a broadcast between
     * the old one and the new.
     *
     * @param array<string, mixed> $broadcast
     */
    private function freeze(App $app, array $broadcast): int
    {
        $db = $app->db();
        $plan = $this->plan($app, $broadcast);
        $written = 0;
        $db->transaction(function () use ($db, $broadcast, $plan, &$written): void {
            foreach ($plan['rows'] as $row) {
                try {
                    $db->insert('broadcast_recipients', [
                        'broadcast_id' => (string) $broadcast['id'],
                        'user_id' => $row['userId'],
                        'channel' => $row['channel'],
                        'address' => mb_substr((string) $row['address'], 0, 255),
                        'name' => $row['name'] === null ? null : mb_substr((string) $row['name'], 0, 255),
                        'status' => Broadcast::PENDING,
                    ]);
                    $written++;
                } catch (\Throwable $e) {
                    // The unique index doing its job against a double click.
                    if (!Db::isDuplicate($e)) {
                        throw $e;
                    }
                }
            }
            if ($written > 0) {
                $db->update('broadcasts', ['status' => Broadcast::SENDING], ['id' => $broadcast['id']]);
            }
        });
        return $written;
    }

    /** @param array<string, mixed> $broadcast */
    private function workThrough(App $app, array $broadcast, float $deadline): int
    {
        $db = $app->db();
        $done = 0;
        while (microtime(true) < $deadline) {
            $batch = $db->all(
                'SELECT * FROM {{broadcast_recipients}} WHERE broadcast_id = ? AND status = ? ORDER BY id LIMIT ' . self::BATCH,
                [$broadcast['id'], Broadcast::PENDING],
            );
            if ($batch === []) {
                break;
            }
            foreach ($batch as $recipient) {
                if (microtime(true) >= $deadline) {
                    break;
                }
                $this->one($app, $broadcast, $recipient);
                $done++;
            }
        }
        $left = (int) $db->value('SELECT COUNT(*) FROM {{broadcast_recipients}} WHERE broadcast_id = ? AND status = ?', [$broadcast['id'], Broadcast::PENDING]);
        if ($left === 0 && (string) $broadcast['status'] !== Broadcast::CANCELLED) {
            $db->update('broadcasts', ['status' => Broadcast::SENT, 'sent_at' => Db::now()], ['id' => $broadcast['id'], 'status' => Broadcast::SENDING]);
        }
        return $done;
    }

    /**
     * One person, one channel, marked as it goes — which is what makes a
     * send resumable, and one bad address not stop the rest.
     *
     * @param array<string, mixed> $broadcast
     * @param array<string, mixed> $recipient
     */
    private function one(App $app, array $broadcast, array $recipient): void
    {
        $db = $app->db();
        // Claim it first: two callers racing must not both send it.
        $claimed = $db->run(
            'UPDATE {{broadcast_recipients}} SET status = ?, sent_at = ? WHERE id = ? AND status = ?',
            [Broadcast::OK, Db::now(), $recipient['id'], Broadcast::PENDING],
        )->rowCount();
        if ($claimed === 0) {
            return;
        }
        try {
            $sent = $this->deliver($app, $broadcast, (string) $recipient['channel'], (string) $recipient['address'], false);
            if ($sent !== null) {
                $db->update('broadcast_recipients', ['provider' => $sent['provider'], 'provider_message_id' => $sent['messageId']], ['id' => $recipient['id']]);
            }
        } catch (\Throwable $e) {
            $db->update('broadcast_recipients', [
                'status' => $e instanceof Skipped ? Broadcast::SKIPPED : Broadcast::FAILED,
                'error' => mb_substr(Log::mask($e->getMessage()), 0, 500),
                'sent_at' => null,
            ], ['id' => $recipient['id']]);
        }
    }

    /**
     * @param array<string, mixed> $broadcast
     * @return array{provider: string, messageId: string}|null
     */
    private function deliver(App $app, array $broadcast, string $channel, string $address, bool $isTest): ?array
    {
        $subject = (string) $broadcast['subject'];
        $body = (string) $broadcast['body'];
        if ($isTest) {
            $subject = '[test] ' . $subject;
        }
        if ($channel === Broadcast::EMAIL) {
            $result = $app->mailer()->send(Message::plain($address, $subject, $body));
            if ($result->status === 'skipped') {
                // Nothing was wrong with the address: there is no way to
                // reach anybody on this channel at all, which is the enum's
                // own meaning of skipped.
                throw new Skipped((string) ($result->error ?? 'Email is not set up.'));
            }
            if (!$result->ok()) {
                throw new \RuntimeException((string) ($result->error ?? 'The message was not accepted.'));
            }
            return ['provider' => (string) ($app->services()->activeId('email') ?? 'email'), 'messageId' => (string) ($result->messageId ?? '')];
        }
        if ($channel === Broadcast::SMS) {
            $texter = $this->texter($app);
            if (!$texter->isConfigured()) {
                // Same as email with no provider: nothing is wrong with the
                // number, there is simply nothing to send it with.
                throw new Skipped(t('broadcasts.smsMissing'));
            }
            $sent = $texter->send($address, $subject === '' ? $body : $subject . ': ' . $body);
            return ['provider' => (string) $app->services()->activeId('sms'), 'messageId' => $sent->messageId];
        }
        if ($channel === Broadcast::PUSH) {
            $reached = Push::send($app, [$address], ['title' => $subject, 'body' => mb_substr($body, 0, 400), 'url' => Url::absolute('/profile/inbox')]);
            if ($reached === 0) {
                throw new \RuntimeException('No device took the message.');
            }
            return null;
        }
        throw new \RuntimeException('Unknown channel.');
    }

    /** Stopped by hand part-way: the rest are not sent, and say so. */
    private function cancel(App $app, string $id): Response
    {
        $db = $app->db();
        $broadcast = $this->find($db, $id);
        $db->transaction(function () use ($db, $broadcast): void {
            $db->update('broadcasts', ['status' => Broadcast::CANCELLED], ['id' => $broadcast['id']]);
            $db->run(
                'UPDATE {{broadcast_recipients}} SET status = ?, error = ? WHERE broadcast_id = ? AND status = ?',
                [Broadcast::SKIPPED, 'Stopped before this one was sent.', $broadcast['id'], Broadcast::PENDING],
            );
        });
        Audit::log($db, (string) $app->currentUser()->email(), 'broadcast.cancel', 'Broadcast', (string) $broadcast['id']);
        return Response::json($this->present($app, $this->find($db, $id)));
    }

    /**
     * The backstop: a laptop closed half-way through a send is picked up
     * here, rather than the rest never going at all.
     */
    private function drain(App $app, float $deadline): string
    {
        $done = 0;
        foreach ($app->db()->all('SELECT * FROM {{broadcasts}} WHERE status = ? ORDER BY created_at LIMIT 5', [Broadcast::SENDING]) as $broadcast) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $done += $this->workThrough($app, $broadcast, $deadline);
        }
        return "sent $done";
    }
};
