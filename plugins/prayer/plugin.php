<?php
/**
 * Plugin Name: Prayer wall
 * Slug:        prayer
 * Version:     1.0.0
 * Description: A moderated wall of prayer requests at /prayer, with an anonymous option and an "I prayed for this" count. Nothing appears until it is let through.
 * Author:      Marine Team
 * Requires PHP: 8.2
 * Requires App: 3.0
 */

declare(strict_types=1);

require_once __DIR__ . '/src/Prayer.php';

use App\Core\ApiError;
use App\Core\App;
use App\Core\Db;
use App\Core\Hooks;
use App\Core\Id;
use App\Core\Middleware;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Validator;
use App\Modules\Audit\Audit;
use App\Modules\Plugins\BasePlugin;
use App\Modules\Plugins\PluginStates;
use MarineTeam\Plugins\Prayer\Prayer;

/**
 * Ask for prayer at /prayer, and pray for what others have asked.
 *
 * Nothing appears until somebody reads it — there is no setting to turn
 * that off. Anonymous means anonymous: the row keeps whose it is so the
 * writer can take it down and a moderator can act on abuse, but no screen
 * anywhere shows the name, the moderator's own queue included. "I prayed
 * for this" is a number, never a list of names. The page is never
 * indexed, and nothing here writes the request's words to the audit log.
 */
return new class (__DIR__) extends BasePlugin {
    /** Requests one account may ask for in an hour. */
    public const PER_HOUR = 5;
    /** And one address: a church shares an office network, and a small group shares a phone hotspot. */
    public const PER_HOUR_IP = 20;

    public function boot(Hooks $hooks, App $app): void
    {
        $this->useLang($hooks);
        $this->useTemplates($hooks);
        $hooks->on('routes.register', function (Router $r) use ($app): void {
            $member = Middleware::member($app);
            $moderate = Middleware::can($app, 'moderate_prayer');

            $r->get('/prayer', fn () => $this->page($app));
            $r->get('/api/prayer', fn () => Response::json($this->wall($app)));
            $r->post('/api/prayer', fn (Request $req) => $this->ask($app, $req));
            $r->add('DELETE', '/api/prayer/[id]', fn (Request $req, array $p) => $this->remove($app, $p['id']));
            $r->post('/api/prayer/[id]/pray', fn (Request $req, array $p) => $this->pray($app, $p['id']), [$member]);

            $r->get('/admin/prayer', fn () => $app->page('prayer/admin', ['title' => 'Prayer wall', 'rows' => $this->queue($app)], 200, 'layouts/admin'), [$moderate]);
            $r->get('/api/admin/prayer', fn () => Response::json($this->queue($app)), [$moderate]);
            $r->add('PATCH', '/api/admin/prayer/[id]', fn (Request $req, array $p) => $this->moderate($app, $req, $p['id']), [$moderate]);
            $r->add('DELETE', '/api/admin/prayer/[id]', fn (Request $req, array $p) => $this->remove($app, $p['id']), [$moderate]);
        });
        $hooks->filter('nav.sections', fn (array $nav) => [...$nav, ['href' => '/prayer', 'label' => t('prayer.title'), 'icon' => 'hands']]);
    }

    private function moderator(App $app): bool
    {
        return $app->currentUser()->can('moderate_prayer');
    }

    /**
     * The wall as this reader sees it: what they may see, and no more.
     *
     * @return list<array<string, mixed>>
     */
    private function wall(App $app, bool $queue = false): array
    {
        $db = $app->db();
        $userId = $app->currentUser()->id();
        $moderator = $this->moderator($app);
        // A narrowing WHERE for the query planner. visibleTo is still what
        // decides, so widening this cannot widen who sees what.
        [$where, $params] = match (true) {
            $moderator => ['1 = 1', []],
            $userId !== null => ['status IN (?, ?) OR user_id = ?', [Prayer::APPROVED, Prayer::ANSWERED, $userId]],
            default => ['status IN (?, ?) AND visibility = ?', [Prayer::APPROVED, Prayer::ANSWERED, Prayer::EVERYONE]],
        };
        // The queue puts what is waiting first: it is a list of decisions.
        $order = $queue ? "status = '" . Prayer::PENDING . "' DESC, created_at DESC" : 'created_at DESC';
        $rows = $db->all("SELECT * FROM {{prayer_requests}} WHERE $where ORDER BY $order LIMIT 200", $params);
        if ($rows === []) {
            return [];
        }
        $ids = array_map('strval', array_column($rows, 'id'));
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $counts = [];
        foreach ($db->all("SELECT request_id, COUNT(*) AS n FROM {{prayer_intercessions}} WHERE request_id IN ($marks) GROUP BY request_id", $ids) as $row) {
            $counts[(string) $row['request_id']] = (int) $row['n'];
        }
        $prayed = $userId === null ? [] : array_map('strval', $db->column(
            "SELECT request_id FROM {{prayer_intercessions}} WHERE user_id = ? AND request_id IN ($marks)",
            [$userId, ...$ids],
        ));
        return Prayer::visibleTo($rows, $userId, $moderator, $counts, $prayed);
    }

    /** @return list<array<string, mixed>> what is waiting, hidden or answered */
    private function queue(App $app): array
    {
        return $this->wall($app, queue: true);
    }

    private function page(App $app): Response
    {
        $signedIn = $app->currentUser()->isSignedIn();
        return $app->page('prayer/page', [
            'title' => t('prayer.title'),
            // A prayer wall is not for search engines, whoever may read it.
            'noindex' => true,
            'requests' => $this->wall($app),
            'signedIn' => $signedIn,
            'moderator' => $this->moderator($app),
            'script' => $this->asset('prayer.js'),
        ]);
    }

    /** POST /api/prayer: anybody may ask; nobody is shown it until it is let through. */
    private function ask(App $app, Request $req): Response
    {
        $input = $req->input();
        // The honeypot: a person never sees this field.
        if (trim((string) ($input['website'] ?? '')) !== '') {
            return Response::json(['ok' => true, 'pending' => true], 201);
        }
        $db = $app->db();
        $user = $app->currentUser()->user();
        $limiter = new RateLimiter($db);
        $buckets = [['ip:' . $req->ip, self::PER_HOUR_IP]];
        if ($user !== null) {
            $buckets[] = ['user:' . $user['id'], self::PER_HOUR];
        }
        foreach ($buckets as [$key, $limit]) {
            if (!$limiter->hit(RateLimiter::bucket('prayer', $key), $limit, 3600)) {
                throw new ApiError(t('prayer.slowDown'), 429, 'rate_limited');
            }
        }
        $data = Validator::check($input, [
            'body' => ['text', 'required', 'min' => 1, 'max' => 2000],
            'name' => ['text', 'nullable', 'max' => 255],
            'anonymous' => ['bool'],
            'visibility' => ['enum', 'enum' => Prayer::VISIBILITIES],
        ]);
        // A member's name comes from their account as it stands now, so a
        // change of display name later doesn't rewrite old requests.
        $name = $user !== null ? $this->accountName($app, $user) : trim((string) ($data['name'] ?? ''));
        // Whoever asks chooses who may see it — a visitor included, since
        // "this shouldn't be a wall at all" is their decision too. Members
        // by default, and a moderator reads it before any of it happens.
        $visibility = (string) ($data['visibility'] ?? Prayer::MEMBERS);
        $id = $db->insert('prayer_requests', [
            'id' => Id::new(),
            'user_id' => $user['id'] ?? null,
            'name' => $name === '' ? null : mb_substr($name, 0, 255),
            'body' => trim((string) $data['body']),
            'anonymous' => (int) (bool) ($data['anonymous'] ?? false),
            'visibility' => $visibility,
            'status' => Prayer::PENDING,
        ]);
        $saved = $this->find($db, $id);
        return Response::json(Prayer::present($saved, $app->currentUser()->id(), false), 201);
    }

    /** @param array<string, mixed> $user */
    private function accountName(App $app, array $user): string
    {
        $fields = PluginStates::enabled($app->db(), 'profiles') ? ['display_name', 'name'] : ['name'];
        foreach ($fields as $field) {
            $value = trim((string) ($user[$field] ?? ''));
            if ($value !== '' && !str_contains($value, '@')) {
                return $value;
            }
        }
        return '';
    }

    /** POST /api/prayer/[id]/pray: a number, not a list of names. */
    private function pray(App $app, string $id): Response
    {
        $db = $app->db();
        $request = $this->find($db, $id);
        $userId = (string) $app->currentUser()->id();
        if (!Prayer::canPrayFor($request, $userId, $this->moderator($app))) {
            throw ApiError::notFound();
        }
        $db->run('INSERT IGNORE INTO {{prayer_intercessions}} (id, request_id, user_id) VALUES (?, ?, ?)', [Id::new(), $request['id'], $userId]);
        return Response::json([
            'prayers' => (int) $db->value('SELECT COUNT(*) FROM {{prayer_intercessions}} WHERE request_id = ?', [$request['id']]),
            'prayed' => true,
        ]);
    }

    /** DELETE /api/prayer/[id]: the writer's, and the moderator's. */
    private function remove(App $app, string $id): Response
    {
        $db = $app->db();
        $request = $this->find($db, $id);
        $userId = $app->currentUser()->id();
        $moderator = $this->moderator($app);
        if (!Prayer::canSee($request, $userId, $moderator) || !Prayer::canDelete($request, $userId, $moderator)) {
            throw ApiError::notFound();
        }
        $db->delete('prayer_requests', ['id' => $request['id']]);
        if (!Prayer::canDelete($request, $userId, false)) {
            // The decision has a name against it; the words never do.
            Audit::log($db, (string) $app->currentUser()->email(), 'prayer.delete', 'PrayerRequest', (string) $request['id']);
        }
        return Response::json(['ok' => true]);
    }

    /** PATCH /api/admin/prayer/[id]: let it through, take it down, or mark it answered. */
    private function moderate(App $app, Request $req, string $id): Response
    {
        $db = $app->db();
        $request = $this->find($db, $id);
        $data = Validator::check($req->input(), [
            'status' => ['enum', 'enum' => Prayer::STATUSES],
            'answeredNote' => ['text', 'nullable', 'max' => 1000],
            'visibility' => ['enum', 'enum' => Prayer::VISIBILITIES],
        ], partial: true);
        $set = ['moderated_by' => mb_substr((string) $app->currentUser()->email(), 0, 255), 'moderated_at' => Db::now()];
        if (isset($data['visibility'])) {
            $set['visibility'] = $data['visibility'];
        }
        if (isset($data['status'])) {
            $set['status'] = $data['status'];
            if ($data['status'] === Prayer::ANSWERED) {
                $set['answered_at'] = $request['answered_at'] ?? Db::now();
            }
        }
        if (array_key_exists('answeredNote', $data)) {
            $set['answered_note'] = $data['answeredNote'] !== null ? trim((string) $data['answeredNote']) : null;
        }
        $db->update('prayer_requests', $set, ['id' => $request['id']]);
        Audit::log($db, (string) $app->currentUser()->email(), 'prayer.' . strtolower((string) ($data['status'] ?? 'update')), 'PrayerRequest', (string) $request['id']);
        return Response::json(Prayer::present($this->find($db, $id), $app->currentUser()->id(), true));
    }

    /** @return array<string, mixed> */
    private function find(Db $db, string $id): array
    {
        $row = Id::isValid($id) ? $db->one('SELECT * FROM {{prayer_requests}} WHERE id = ?', [$id]) : null;
        return $row ?? throw ApiError::notFound();
    }
};
