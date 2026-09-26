<?php
/**
 * Plugin Name: Events
 * Slug:        events
 * Version:     1.0.0
 * Description: Published events at /events with sign-up: capacity, a waiting list that moves when somebody drops out, and guests.
 * Author:      Marine Team
 * Requires PHP: 8.2
 * Requires App: 3.0
 */

declare(strict_types=1);

require_once __DIR__ . '/src/Recurrence.php';
require_once __DIR__ . '/src/Events.php';
require_once __DIR__ . '/src/Series.php';

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
use App\Services\Email\Message;
use App\Support\Ics;
use App\Support\Slug;
use MarineTeam\Plugins\Events\Events;
use MarineTeam\Plugins\Events\Recurrence;
use MarineTeam\Plugins\Events\Series;

/**
 * What's on, at /events, and who is coming.
 *
 * Sign-up is a switch rather than an assumption; an account is not needed
 * for it, because the people a church most wants at a men's breakfast are
 * the ones who have never made one. A members-only event is invisible to
 * anybody not signed in rather than refused, because a title is a leak
 * too. Everything that decides "is there room" happens under a lock on the
 * event, so four people pressing the button in the same second get one yes
 * and three places on the list.
 *
 * A repeating event is a rule, not twelve typed dates: every date it
 * produces is an ordinary event row with its own page and its own list,
 * kept filled in six months ahead by a daily job.
 */
return new class (__DIR__) extends BasePlugin {
    /** Sign-ups from one address in an hour. */
    public const PER_HOUR_IP = 20;

    public function boot(Hooks $hooks, App $app): void
    {
        $this->useLang($hooks);
        $this->useTemplates($hooks);
        $hooks->on('routes.register', function (Router $r) use ($app): void {
            $member = Middleware::member($app);
            $manage = Middleware::can($app, 'manage_events');

            $r->get('/events', fn () => $this->listPage($app));
            $r->get('/events/calendar.ics', fn () => $this->calendarFeed($app));
            $r->get('/events/[slug]', fn (Request $req, array $p) => $this->eventPage($app, (string) $p['slug']));
            $r->get('/events/[slug]/event.ics', fn (Request $req, array $p) => $this->eventFeed($app, (string) $p['slug']));
            $r->post('/api/events/[slug]/register', fn (Request $req, array $p) => $this->register($app, $req, (string) $p['slug']));
            $r->add('DELETE', '/api/events/[slug]/register', fn (Request $req, array $p) => $this->cancel($app, $req, (string) $p['slug']));
            $r->get('/profile/events', fn () => $this->minePage($app), [$member]);

            $r->get('/admin/events', fn () => $app->page('events/admin', [
                'title' => 'Events',
                'rows' => $this->adminEvents($app),
                'series' => $this->adminSeries($app),
                'shapes' => Series::SHAPES,
                'zone' => $this->zone($app),
            ], 200, 'layouts/admin'), [$manage]);
            $r->get('/admin/events/[id]', fn (Request $req, array $p) => $this->adminEventPage($app, (string) $p['id']), [$manage]);
            $r->get('/api/admin/events', fn () => Response::json($this->adminEvents($app)), [$manage]);
            $r->post('/api/admin/events', fn (Request $req) => $this->saveEvent($app, $req, null), [$manage]);
            $r->add('PATCH', '/api/admin/events/[id]', fn (Request $req, array $p) => $this->saveEvent($app, $req, (string) $p['id']), [$manage]);
            $r->add('DELETE', '/api/admin/events/[id]', fn (Request $req, array $p) => $this->deleteEvent($app, (string) $p['id']), [$manage]);
            $r->get('/api/admin/events/series', fn () => Response::json($this->adminSeries($app)), [$manage]);
            $r->post('/api/admin/events/series', fn (Request $req) => $this->saveSeries($app, $req, null), [$manage]);
            $r->add('PATCH', '/api/admin/events/series/[id]', fn (Request $req, array $p) => $this->saveSeries($app, $req, (string) $p['id']), [$manage]);
            $r->add('DELETE', '/api/admin/events/series/[id]', fn (Request $req, array $p) => $this->stopSeries($app, (string) $p['id']), [$manage]);
            $r->get('/api/admin/events/[id]/registrations', fn (Request $req, array $p) => $this->registrations($app, $req, (string) $p['id']), [$manage]);
            $r->add('DELETE', '/api/admin/events/[id]/registrations/[registrationId]', fn (Request $req, array $p) => $this->removeRegistration($app, (string) $p['id'], (string) $p['registrationId']), [$manage]);
        });
        $hooks->filter('nav.sections', fn (array $nav) => [...$nav, ['href' => '/events', 'label' => t('events.title'), 'icon' => 'ticket']]);
        $hooks->filter('profile.sections', fn (array $sections) => [...$sections, ['href' => '/profile/events', 'label' => t('events.mine')]]);
        $hooks->filter('sitemap.urls', function (array $urls) use ($app) {
            foreach ($this->publicEvents($app->db()) as $event) {
                $urls[] = ['/events/' . $event['slug'], $event['updated_at']];
            }
            return [...$urls, '/events'];
        });
        $hooks->filter('profile.overview', fn (array $cards, array $user) => [...$cards, [
            'title' => t('events.mine'),
            'count' => (int) $app->db()->value(
                'SELECT COUNT(*) FROM {{event_registrations}} r JOIN {{events}} e ON e.id = r.event_id WHERE r.user_id = ? AND r.status <> ? AND e.starts_at >= ?',
                [$user['id'], Events::CANCELLED, Db::now()],
            ),
            'href' => '/profile/events',
        ]]);
        // The member's own diary feed: what they have signed up for.
        $hooks->filter('calendar.entries', fn (array $entries, App $app, array $user) => [...$entries, ...$this->diaryEntries($app, (string) $user['id'])]);
        $hooks->on('jobs.register', function (Scheduler $s) use ($app): void {
            $s->register('extend-events', 86400, fn (float $deadline) => $this->extend($app, $deadline), 20.0, '02:20');
        });
    }

    private function zone(App $app): string
    {
        $zone = (string) ($app->settings()->get('site.timezone') ?? 'UTC');
        return Recurrence::isKnownTimeZone($zone) ? $zone : 'UTC';
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    // -- Reading ----------------------------------------------------------

    /**
     * A members-only event is invisible to anybody not signed in, rather
     * than refused: a title is a leak too.
     */
    private function visibleSql(App $app): string
    {
        return 'published = 1' . ($app->currentUser()->isSignedIn() ? '' : ' AND member_only = 0');
    }

    /** @return list<array<string, mixed>> published events with nothing members-only in them */
    private function publicEvents(Db $db): array
    {
        return $db->all('SELECT * FROM {{events}} WHERE published = 1 AND member_only = 0 AND starts_at >= ? ORDER BY starts_at LIMIT 500', [Db::datetime($this->now()->modify('-1 day'))]);
    }

    /** @return array<string, mixed>|null */
    private function findBySlug(App $app, string $slug): ?array
    {
        return $app->db()->one('SELECT * FROM {{events}} WHERE slug = ? AND ' . $this->visibleSql($app), [$slug]);
    }

    /** @return list<array<string, mixed>> */
    private function registrationsOf(Db $db, string $eventId): array
    {
        return $db->all('SELECT * FROM {{event_registrations}} WHERE event_id = ? AND status <> ? ORDER BY created_at, id', [$eventId, Events::CANCELLED]);
    }

    /**
     * One event as a page or an API answer shows it: never anybody else's
     * name, only the numbers and this reader's own place.
     *
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function present(App $app, array $event, ?array $registrations = null): array
    {
        $registrations ??= $this->registrationsOf($app->db(), (string) $event['id']);
        $taken = Events::seatsTaken($registrations);
        $state = Events::registrationState($event, $taken, $this->now());
        $left = Events::placesLeft(Events::capacity($event), $taken);
        $userId = $app->currentUser()->id();
        $mine = null;
        foreach ($registrations as $one) {
            if ($userId !== null && (string) ($one['user_id'] ?? '') === $userId) {
                $mine = ['status' => (string) $one['status'], 'guests' => (int) $one['guests'], 'promoted' => $one['promoted_at'] !== null];
            }
        }
        return Json::row('events', $event) + [
            'when' => Events::eventWhen($event, $this->zone($app)),
            'state' => $state,
            'placesLeft' => $left,
            'placesMessage' => Events::registrationMessage($state, $left),
            'going' => $taken,
            'waiting' => count(array_filter($registrations, fn (array $r) => (string) $r['status'] === Events::WAITLIST)),
            'mine' => $mine,
            'url' => Url::absolute('/events/' . $event['slug']),
        ];
    }

    private function listPage(App $app): Response
    {
        $db = $app->db();
        $rows = $db->all(
            'SELECT * FROM {{events}} WHERE ' . $this->visibleSql($app) . ' AND (ends_at IS NULL OR ends_at >= ?) AND starts_at >= ? ORDER BY starts_at LIMIT 200',
            [Db::now(), Db::datetime($this->now()->modify('-1 day'))],
        );
        return $app->page('events/list', [
            'title' => t('events.title'),
            'events' => array_map(fn (array $e) => $this->present($app, $e), $rows),
            'feed' => url('/events/calendar.ics'),
        ]);
    }

    private function eventPage(App $app, string $slug): Response
    {
        $event = $this->findBySlug($app, $slug);
        if ($event === null) {
            throw ApiError::notFound();
        }
        $series = $event['series_id'] !== null ? $app->db()->one('SELECT * FROM {{event_series}} WHERE id = ?', [$event['series_id']]) : null;
        $siblings = $series === null ? [] : $app->db()->all(
            'SELECT slug, title, starts_at FROM {{events}} WHERE series_id = ? AND id <> ? AND starts_at >= ? AND ' . $this->visibleSql($app) . ' ORDER BY starts_at LIMIT 5',
            [$series['id'], $event['id'], Db::now()],
        );
        return $app->page('events/event', [
            'title' => (string) $event['title'],
            'event' => $this->present($app, $event),
            'signedIn' => $app->currentUser()->isSignedIn(),
            'me' => $app->currentUser()->user(),
            'series' => $series === null ? null : Series::describeSeries($series),
            'siblings' => array_map(fn (array $s) => [
                'title' => (string) $s['title'],
                'href' => '/events/' . $s['slug'],
                'when' => Events::eventWhen($s + ['all_day' => $event['all_day']], $this->zone($app)),
            ], $siblings),
            'script' => $this->asset('events.js'),
        ]);
    }

    private function minePage(App $app): Response
    {
        $rows = $app->db()->all(
            'SELECT e.*, r.status AS my_status, r.guests AS my_guests FROM {{event_registrations}} r JOIN {{events}} e ON e.id = r.event_id
             WHERE r.user_id = ? AND r.status <> ? ORDER BY e.starts_at DESC LIMIT 200',
            [$app->currentUser()->id(), Events::CANCELLED],
        );
        return $app->page('events/mine', [
            'title' => t('events.mine'),
            'sections' => (new \App\Modules\Profile\Routes($app))->sections(),
            'rows' => array_map(fn (array $e) => [
                'title' => (string) $e['title'],
                'href' => '/events/' . $e['slug'],
                'slug' => (string) $e['slug'],
                'when' => Events::eventWhen($e, $this->zone($app)),
                'waiting' => (string) $e['my_status'] === Events::WAITLIST,
                'guests' => (int) $e['my_guests'],
                'past' => Events::finishedAt($e) < $this->now(),
            ], $rows),
        ]);
    }

    // -- Calendars --------------------------------------------------------

    /** @param array<string, mixed> $event @return array<string, mixed> */
    private function entry(array $event, ?string $status = null, string $suffix = ''): array
    {
        return [
            'uid' => 'event-' . $event['id'] . '@' . (parse_url(Url::absolute('/'), PHP_URL_HOST) ?: 'church'),
            'summary' => (string) $event['title'] . $suffix,
            'starts' => Events::at($event['starts_at']),
            'ends' => Events::at($event['ends_at'] ?? null),
            'allDay' => (bool) $event['all_day'],
            'description' => $event['description'] ?? null,
            'location' => $event['location'] ?? null,
            'url' => Url::absolute('/events/' . $event['slug']),
            'relatedTo' => $event['series_id'] !== null ? 'series-' . $event['series_id'] : null,
            'status' => $status,
        ];
    }

    private function ics(string $body, string $filename): Response
    {
        $response = new Response(200, $body);
        $response->header('Content-Type', 'text/calendar; charset=utf-8');
        $response->header('Content-Disposition', 'inline; filename="' . Ics::icsFilename($filename) . '"');
        return $response;
    }

    /** What's on: a public feed to subscribe to once. Member-only events are absent. */
    private function calendarFeed(App $app): Response
    {
        $rows = $this->publicEvents($app->db());
        return $this->ics(Ics::icsCalendar(array_map(fn (array $e) => $this->entry($e), $rows), t('events.title')), 'whats-on');
    }

    /**
     * One event. A members-only event refuses this outright rather than
     * gating it on a session: the URL is opened by a calendar application,
     * and there is nobody there to check.
     */
    private function eventFeed(App $app, string $slug): Response
    {
        $event = $app->db()->one('SELECT * FROM {{events}} WHERE slug = ? AND published = 1 AND member_only = 0', [$slug]);
        if ($event === null) {
            throw ApiError::notFound();
        }
        return $this->ics(Ics::icsCalendar([$this->entry($event)], (string) $event['title']), (string) $event['slug']);
    }

    /** @return list<array<string, mixed>> the member's own sign-ups, for their diary feed */
    private function diaryEntries(App $app, string $userId): array
    {
        $rows = $app->db()->all(
            'SELECT e.*, r.status AS my_status FROM {{event_registrations}} r JOIN {{events}} e ON e.id = r.event_id
             WHERE r.user_id = ? AND r.status <> ? AND e.starts_at >= ? ORDER BY e.starts_at LIMIT 500',
            [$userId, Events::CANCELLED, Db::datetime($this->now()->modify('-3 months'))],
        );
        // A place on the waiting list says so in its title: it is exactly
        // what somebody forgets between signing up and the day.
        return array_map(fn (array $e) => $this->entry($e, null, (string) $e['my_status'] === Events::WAITLIST ? ' ' . t('events.waitingSuffix') : ''), $rows);
    }

    // -- Signing up -------------------------------------------------------

    private function register(App $app, Request $req, string $slug): Response
    {
        $event = $this->findBySlug($app, $slug);
        if ($event === null) {
            throw ApiError::notFound();
        }
        $user = $app->currentUser()->user();
        if ((bool) $event['member_only'] && $user === null) {
            throw ApiError::notFound();
        }
        $data = Validator::check($req->input(), [
            'name' => ['text', 'required', 'min' => 1, 'max' => 255],
            'email' => ['email', 'required'],
            'phone' => ['string', 'nullable', 'max' => 64],
            'guests' => ['int', 'min' => 0, 'max' => 50],
            'note' => ['text', 'nullable', 'max' => 1000],
            'website' => ['string', 'nullable', 'max' => 255],
        ]);
        if (trim((string) ($data['website'] ?? '')) !== '') {
            // The honeypot: a person never sees this field.
            return Response::json(['status' => Events::GOING], 201);
        }
        if ($user === null && !(new \App\Core\RateLimiter($app->db()))->hit(\App\Core\RateLimiter::bucket('event-signup', 'ip:' . $req->ip), self::PER_HOUR_IP, 3600)) {
            throw new ApiError(t('events.slowDown'), 429, 'rate_limited');
        }
        $guests = (int) ($data['guests'] ?? 0);
        if ($guests > (int) $event['max_guests']) {
            throw ApiError::invalid(t('events.tooManyGuests', ['count' => (string) $event['max_guests']]));
        }
        $db = $app->db();
        $result = $db->transaction(function (Db $db) use ($event, $data, $guests, $user): array {
            // Everything that decides "is there room" happens under this lock.
            $locked = $db->one('SELECT * FROM {{events}} WHERE id = ? FOR UPDATE', [$event['id']]);
            $registrations = $this->registrationsOf($db, (string) $event['id']);
            $state = Events::registrationState((array) $locked, Events::seatsTaken($registrations), $this->now());
            if (!in_array($state, [Events::OPEN, Events::LIST_OPEN], true)) {
                throw ApiError::invalid(t('events.state.' . strtolower($state)));
            }
            $left = Events::placesLeft(Events::capacity((array) $locked), Events::seatsTaken($registrations));
            $status = $left !== null && $left < 1 + $guests ? Events::WAITLIST : Events::GOING;
            if ($status === Events::WAITLIST && !(bool) $locked['waitlist']) {
                throw ApiError::invalid(t('events.state.full'));
            }
            $row = [
                'event_id' => $event['id'],
                'user_id' => $user['id'] ?? null,
                'name' => trim((string) $data['name']),
                'email' => (string) $data['email'],
                'phone' => $data['phone'] ?? null,
                'guests' => $guests,
                'note' => $data['note'] ?? null,
                'status' => $status,
                'promoted_at' => null,
                'cancelled_at' => null,
            ];
            $existing = $user === null
                ? $db->one('SELECT * FROM {{event_registrations}} WHERE event_id = ? AND user_id IS NULL AND email = ?', [$event['id'], $row['email']])
                : $db->one('SELECT * FROM {{event_registrations}} WHERE event_id = ? AND user_id = ?', [$event['id'], $user['id']]);
            if ($existing !== null) {
                if ((string) $existing['status'] !== Events::CANCELLED) {
                    throw ApiError::conflict(t('events.alreadySignedUp'));
                }
                // Cancelling kept the row: it is the record that they were
                // coming, so signing up again reuses it.
                $db->update('event_registrations', $row, ['id' => $existing['id']]);
                return ['id' => (string) $existing['id'], 'status' => $status];
            }
            return ['id' => $db->insert('event_registrations', $row + ['id' => Id::new()]), 'status' => $status];
        });
        $this->tell($app, $event, (string) $data['email'], (string) $data['name'], $result['status'] === Events::WAITLIST ? 'waiting' : 'going');
        return Response::json($this->present($app, (array) $db->one('SELECT * FROM {{events}} WHERE id = ?', [$event['id']])) + ['status' => $result['status']], 201);
    }

    private function cancel(App $app, Request $req, string $slug): Response
    {
        $event = $this->findBySlug($app, $slug);
        if ($event === null) {
            throw ApiError::notFound();
        }
        $user = $app->currentUser()->user();
        $email = $user === null ? (string) (Validator::check($req->input(), ['email' => ['email', 'required']])['email']) : (string) $user['email'];
        $db = $app->db();
        $mine = $user === null
            ? $db->one('SELECT * FROM {{event_registrations}} WHERE event_id = ? AND user_id IS NULL AND email = ? AND status <> ?', [$event['id'], $email, Events::CANCELLED])
            : $db->one('SELECT * FROM {{event_registrations}} WHERE event_id = ? AND user_id = ? AND status <> ?', [$event['id'], $user['id'], Events::CANCELLED]);
        if ($mine === null) {
            throw ApiError::notFound();
        }
        $db->update('event_registrations', ['status' => Events::CANCELLED, 'cancelled_at' => Db::now()], ['id' => $mine['id']]);
        $this->promote($app, (string) $event['id']);
        return Response::json($this->present($app, (array) $db->one('SELECT * FROM {{events}} WHERE id = ?', [$event['id']])) + ['status' => Events::CANCELLED]);
    }

    /**
     * The waiting list moves by itself: when somebody drops out, and again
     * when an organiser raises the capacity. Whoever moves up is told.
     *
     * @return list<string> the ids promoted
     */
    private function promote(App $app, string $eventId): array
    {
        $db = $app->db();
        $promoted = $db->transaction(function (Db $db) use ($eventId): array {
            $event = $db->one('SELECT * FROM {{events}} WHERE id = ? FOR UPDATE', [$eventId]);
            if ($event === null) {
                return [];
            }
            $registrations = $this->registrationsOf($db, $eventId);
            $waiting = array_values(array_filter($registrations, fn (array $r) => (string) $r['status'] === Events::WAITLIST));
            $ids = Events::promotable($waiting, Events::capacity((array) $event), Events::seatsTaken($registrations));
            foreach ($ids as $id) {
                $db->update('event_registrations', ['status' => Events::GOING, 'promoted_at' => Db::now()], ['id' => $id]);
            }
            return $ids;
        });
        if ($promoted !== []) {
            $event = (array) $db->one('SELECT * FROM {{events}} WHERE id = ?', [$eventId]);
            foreach ($promoted as $id) {
                $one = $db->one('SELECT * FROM {{event_registrations}} WHERE id = ?', [$id]);
                if ($one !== null) {
                    $this->tell($app, $event, (string) $one['email'], (string) $one['name'], 'promoted');
                }
            }
        }
        return $promoted;
    }

    /** @param array<string, mixed> $event */
    private function tell(App $app, array $event, string $email, string $name, string $what): void
    {
        $mailer = $app->mailer();
        if (!$mailer->isConfigured()) {
            return;
        }
        $when = Events::eventWhen($event, $this->zone($app));
        $body = t('events.mail.' . $what, ['name' => $name, 'title' => (string) $event['title'], 'when' => $when])
            . "\n\n" . Url::absolute('/events/' . $event['slug']) . "\n";
        try {
            $mailer->send(new Message($email, t('events.mail.subject.' . $what, ['title' => (string) $event['title']]), $body));
        } catch (\Throwable $e) {
            Log::warning('Event email failed: ' . $e->getMessage(), ['event' => $event['id']]);
        }
    }

    // -- Administration ---------------------------------------------------

    /** @return list<array<string, mixed>> */
    private function adminEvents(App $app): array
    {
        $rows = $app->db()->all(
            'SELECT e.*, (SELECT COUNT(*) FROM {{event_registrations}} r WHERE r.event_id = e.id AND r.status = ?) AS going,
                    (SELECT COUNT(*) FROM {{event_registrations}} r WHERE r.event_id = e.id AND r.status = ?) AS waiting
             FROM {{events}} e ORDER BY e.starts_at DESC LIMIT 500',
            [Events::GOING, Events::WAITLIST],
        );
        return array_map(fn (array $e) => Json::row('events', $e) + ['when' => Events::eventWhen($e, $this->zone($app))], $rows);
    }

    /** @return list<array<string, mixed>> */
    private function adminSeries(App $app): array
    {
        return array_map(
            fn (array $s) => Json::row('event_series', $s) + ['describe' => Series::describeSeries($s), 'dates' => (int) $app->db()->value('SELECT COUNT(*) FROM {{events}} WHERE series_id = ?', [$s['id']])],
            $app->db()->all('SELECT * FROM {{event_series}} ORDER BY created_at DESC LIMIT 200'),
        );
    }

    /** One event's own screen: its fields, and the list for the door. */
    private function adminEventPage(App $app, string $id): Response
    {
        $db = $app->db();
        $event = $this->findEvent($db, $id);
        $rows = $db->all('SELECT * FROM {{event_registrations}} WHERE event_id = ? ORDER BY status, created_at', [$event['id']]);
        return $app->page('events/admin-event', [
            'title' => (string) $event['title'],
            'event' => Json::row('events', $event) + ['when' => Events::eventWhen($event, $this->zone($app))],
            'registrations' => array_map(fn (array $r) => Json::row('event_registrations', $r, ['user_id']) + ['member' => $r['user_id'] !== null], $rows),
            'taken' => Events::seatsTaken($rows),
        ], 200, 'layouts/admin');
    }

    /** @return array<string, mixed> */
    private function findEvent(Db $db, string $id): array
    {
        $row = Id::isValid($id) ? $db->one('SELECT * FROM {{events}} WHERE id = ?', [$id]) : null;
        return $row ?? throw ApiError::notFound();
    }

    private function uniqueSlug(Db $db, string $wanted, ?string $exceptId = null): string
    {
        return Slug::unique($wanted, fn (string $slug) => $db->value(
            'SELECT 1 FROM {{events}} WHERE slug = ?' . ($exceptId !== null ? ' AND id <> ?' : ''),
            $exceptId !== null ? [$slug, $exceptId] : [$slug],
        ) !== null, 'event');
    }

    private function saveEvent(App $app, Request $req, ?string $id): Response
    {
        $data = Validator::check($req->input(), [
            'title' => ['text', 'required', 'min' => 1, 'max' => 255],
            'slug' => ['string', 'nullable', 'max' => 191],
            'description' => ['text', 'nullable', 'max' => 20000],
            'location' => ['text', 'nullable', 'max' => 500],
            'startsAt' => ['datetime', 'required'],
            'endsAt' => ['datetime', 'nullable'],
            'allDay' => ['bool'],
            'published' => ['bool'],
            'memberOnly' => ['bool'],
            'registration' => ['bool'],
            'capacity' => ['int', 'nullable', 'min' => 0, 'max' => 100000],
            'waitlist' => ['bool'],
            'opensAt' => ['datetime', 'nullable'],
            'closesAt' => ['datetime', 'nullable'],
            'maxGuests' => ['int', 'min' => 0, 'max' => 50],
        ], partial: $id !== null);
        $db = $app->db();
        $was = $id === null ? null : $this->findEvent($db, $id);
        $row = [];
        foreach (['title' => 'title', 'description' => 'description', 'location' => 'location', 'capacity' => 'capacity', 'maxGuests' => 'max_guests'] as $key => $column) {
            if (array_key_exists($key, $data)) {
                $row[$column] = $data[$key];
            }
        }
        foreach (['allDay' => 'all_day', 'published' => 'published', 'memberOnly' => 'member_only', 'registration' => 'registration', 'waitlist' => 'waitlist'] as $key => $column) {
            if (array_key_exists($key, $data)) {
                $row[$column] = (int) (bool) $data[$key];
            }
        }
        foreach (['startsAt' => 'starts_at', 'endsAt' => 'ends_at', 'opensAt' => 'opens_at', 'closesAt' => 'closes_at'] as $key => $column) {
            if (array_key_exists($key, $data)) {
                $row[$column] = $data[$key] instanceof \DateTimeInterface ? Db::datetime($data[$key]) : null;
            }
        }
        if (isset($data['slug']) && trim((string) $data['slug']) !== '') {
            $row['slug'] = $this->uniqueSlug($db, (string) $data['slug'], $id);
        }
        if ($id === null) {
            $row['slug'] ??= $this->uniqueSlug($db, (string) $data['title']);
            $id = $db->insert('events', $row + ['id' => Id::new()]);
        } elseif ($row !== []) {
            $db->update('events', $row, ['id' => $id]);
        }
        $saved = $this->findEvent($db, $id);
        if ($saved['ends_at'] !== null && $saved['ends_at'] < $saved['starts_at']) {
            throw ApiError::invalid(t('events.endsBeforeItStarts'));
        }
        // Raising the capacity moves the waiting list, the same as somebody dropping out.
        if ($was !== null && (Events::capacity($saved) === null || Events::capacity($saved) > (Events::capacity($was) ?? 0))) {
            $this->promote($app, $id);
        }
        Audit::log($db, (string) $app->currentUser()->email(), $was === null ? 'event.create' : 'event.update', 'Event', $id);
        return Response::json(Json::row('events', $this->findEvent($db, $id)), $was === null ? 201 : 200);
    }

    private function deleteEvent(App $app, string $id): Response
    {
        $db = $app->db();
        $event = $this->findEvent($db, $id);
        // Removing one date of a series sticks: the date goes onto the
        // series' exclusion list in the same transaction, so tonight's
        // generator doesn't put the cancelled meeting straight back.
        $db->transaction(function (Db $db) use ($event): void {
            if ($event['series_id'] !== null && $event['occurrence_date'] !== null) {
                $series = $db->one('SELECT * FROM {{event_series}} WHERE id = ? FOR UPDATE', [$event['series_id']]);
                if ($series !== null) {
                    $excluded = (array) json_decode((string) $series['excluded_dates'], true);
                    $excluded[] = substr((string) $event['occurrence_date'], 0, 10);
                    $db->update('event_series', ['excluded_dates' => array_values(array_unique(array_filter($excluded, 'is_string')))], ['id' => $series['id']]);
                }
            }
            $db->delete('events', ['id' => $event['id']]);
        });
        Audit::log($db, (string) $app->currentUser()->email(), 'event.delete', 'Event', (string) $event['id']);
        return Response::json(['ok' => true]);
    }

    /** The list for the door, on screen or as a CSV with a column saying who is a member. */
    private function registrations(App $app, Request $req, string $id): Response
    {
        $db = $app->db();
        $event = $this->findEvent($db, $id);
        $rows = $db->all(
            'SELECT r.*, (r.user_id IS NOT NULL) AS is_member FROM {{event_registrations}} r WHERE r.event_id = ? ORDER BY r.status, r.created_at',
            [$event['id']],
        );
        if (($req->query('format') ?? '') !== 'csv') {
            return Response::json(Json::rows('event_registrations', $rows, ['user_id']));
        }
        $csv = "Name,Email,Phone,Guests,Status,Member,Note,Signed up\n";
        foreach ($rows as $r) {
            $csv .= implode(',', array_map(
                fn (mixed $value) => '"' . str_replace('"', '""', (string) $value) . '"',
                [$r['name'], $r['email'], $r['phone'] ?? '', $r['guests'], $r['status'], $r['is_member'] ? 'yes' : 'no', $r['note'] ?? '', $r['created_at']],
            )) . "\n";
        }
        $response = Response::text($csv);
        $response->header('Content-Type', 'text/csv; charset=utf-8');
        $response->header('Content-Disposition', 'attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $event['slug']) . '-signups.csv"');
        return $response;
    }

    private function removeRegistration(App $app, string $id, string $registrationId): Response
    {
        $db = $app->db();
        $event = $this->findEvent($db, $id);
        $one = Id::isValid($registrationId) ? $db->one('SELECT * FROM {{event_registrations}} WHERE id = ? AND event_id = ?', [$registrationId, $event['id']]) : null;
        if ($one === null) {
            throw ApiError::notFound();
        }
        $db->update('event_registrations', ['status' => Events::CANCELLED, 'cancelled_at' => Db::now()], ['id' => $one['id']]);
        $this->promote($app, (string) $event['id']);
        Audit::log($db, (string) $app->currentUser()->email(), 'event.registration.remove', 'EventRegistration', (string) $one['id']);
        return Response::json(['ok' => true]);
    }

    // -- Something that repeats -------------------------------------------

    /** @return array<string, mixed> */
    private function findSeries(Db $db, string $id): array
    {
        $row = Id::isValid($id) ? $db->one('SELECT * FROM {{event_series}} WHERE id = ?', [$id]) : null;
        return $row ?? throw ApiError::notFound();
    }

    private function saveSeries(App $app, Request $req, ?string $id): Response
    {
        $input = $req->input();
        $data = Validator::check($input, [
            'rule' => ['string', 'nullable', 'max' => 500],
            'shape' => ['enum', 'nullable', 'enum' => Series::SHAPES],
            'days' => ['array', 'nullable', 'of' => 'string', 'max' => 7],
            'interval' => ['int', 'nullable', 'min' => 1, 'max' => 52],
            'count' => ['int', 'nullable', 'min' => 1, 'max' => 500],
            'until' => ['date', 'nullable'],
            'timeZone' => ['string', 'nullable', 'max' => 64],
            'startDate' => ['date', 'nullable'],
            'startTime' => ['string', 'nullable', 'max' => 5, 'pattern' => '/^\d{1,2}:\d{2}$/'],
            'durationMinutes' => ['int', 'nullable', 'min' => 0, 'max' => 10080],
            'allDay' => ['bool'],
            'title' => ['text', 'nullable', 'max' => 255],
            'description' => ['text', 'nullable', 'max' => 20000],
            'location' => ['text', 'nullable', 'max' => 500],
            'published' => ['bool'],
            'memberOnly' => ['bool'],
            'registration' => ['bool'],
            'capacity' => ['int', 'nullable', 'min' => 0, 'max' => 100000],
            'waitlist' => ['bool'],
            'maxGuests' => ['int', 'min' => 0, 'max' => 50],
            'opensDaysBefore' => ['int', 'nullable', 'min' => 0, 'max' => 365],
            'closesDaysBefore' => ['int', 'nullable', 'min' => 0, 'max' => 365],
        ], partial: true);
        $db = $app->db();
        $was = $id === null ? null : $this->findSeries($db, $id);
        $row = $was === null ? [
            'time_zone' => $this->zone($app), 'all_day' => 0, 'published' => 0, 'member_only' => 0,
            'registration' => 0, 'waitlist' => 1, 'max_guests' => 0, 'excluded_dates' => [], 'rule' => 'FREQ=WEEKLY',
        ] : [];
        foreach (['timeZone' => 'time_zone', 'startDate' => 'start_date', 'startTime' => 'start_time', 'durationMinutes' => 'duration_minutes',
                  'title' => 'title', 'description' => 'description', 'location' => 'location', 'capacity' => 'capacity',
                  'maxGuests' => 'max_guests', 'opensDaysBefore' => 'opens_days_before', 'closesDaysBefore' => 'closes_days_before'] as $key => $column) {
            if (array_key_exists($key, $data)) {
                $row[$column] = $data[$key];
            }
        }
        foreach (['allDay' => 'all_day', 'published' => 'published', 'memberOnly' => 'member_only', 'registration' => 'registration', 'waitlist' => 'waitlist'] as $key => $column) {
            if (array_key_exists($key, $data)) {
                $row[$column] = (int) (bool) $data[$key];
            }
        }
        $startDate = (string) ($row['start_date'] ?? $was['start_date'] ?? '');
        $startDate = substr($startDate, 0, 10);
        if (isset($data['shape'])) {
            // Nobody types an RRULE: this is what their answers come to.
            $row['rule'] = Series::ruleFromChoices([
                'shape' => (string) $data['shape'],
                'days' => array_map('strval', (array) ($data['days'] ?? [])),
                'interval' => (int) ($data['interval'] ?? 1),
                'count' => $data['count'] ?? null,
                'until' => $data['until'] ?? null,
            ], $startDate !== '' ? $startDate : gmdate('Y-m-d'));
        } elseif (isset($data['rule'])) {
            $row['rule'] = (string) $data['rule'];
        }
        $candidate = array_merge($was ?? [], $row, ['start_date' => $startDate]);
        $problems = Series::seriesProblems($candidate);
        if ($problems !== []) {
            throw ApiError::invalid(implode(' ', $problems));
        }
        if ($id === null) {
            $id = $db->insert('event_series', $row + ['id' => Id::new()]);
        } elseif ($row !== []) {
            $db->update('event_series', $row, ['id' => $id]);
        }
        $series = $this->findSeries($db, $id);
        // Changing the timing leaves booked dates where they are: empty
        // future ones are cleared out and laid down again from the new rule.
        $timingChanged = $was !== null && array_intersect_key($row, array_flip(['rule', 'start_date', 'start_time', 'duration_minutes', 'time_zone', 'all_day'])) !== []
            && (string) ($was['rule'] . $was['start_date'] . $was['start_time'] . $was['duration_minutes'] . $was['time_zone'] . $was['all_day'])
               !== (string) ($series['rule'] . $series['start_date'] . $series['start_time'] . $series['duration_minutes'] . $series['time_zone'] . $series['all_day']);
        if ($timingChanged) {
            $this->clearEmptyFuture($db, (string) $series['id']);
            $db->update('event_series', ['generated_through' => null], ['id' => $series['id']]);
            $series = $this->findSeries($db, $id);
        }
        $made = $this->generate($app, $series);
        Audit::log($db, (string) $app->currentUser()->email(), $was === null ? 'event.series.create' : 'event.series.update', 'EventSeries', (string) $series['id']);
        return Response::json(Json::row('event_series', $this->findSeries($db, $id)) + ['describe' => Series::describeSeries($series), 'created' => $made], $was === null ? 201 : 200);
    }

    /** Future dates nobody has signed up for, cleared so the new rule can lay them down again. */
    private function clearEmptyFuture(Db $db, string $seriesId): int
    {
        $rows = $db->all(
            'SELECT e.id FROM {{events}} e WHERE e.series_id = ? AND e.starts_at >= ?
               AND NOT EXISTS (SELECT 1 FROM {{event_registrations}} r WHERE r.event_id = e.id AND r.status <> ?)',
            [$seriesId, Db::now(), Events::CANCELLED],
        );
        $ids = array_map('strval', array_column($rows, 'id'));
        if ($ids !== []) {
            $db->run('DELETE FROM {{events}} WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
        }
        return count($ids);
    }

    /**
     * Lays down the dates the rule wants that aren't there yet, up to the
     * horizon. Idempotent: the daily job, a missed run and an admin
     * pressing save all produce one diary.
     *
     * @param array<string, mixed> $series
     */
    private function generate(App $app, array $series, ?string $through = null): int
    {
        $db = $app->db();
        $through ??= Series::horizonEnd($this->now());
        $start = substr((string) $series['start_date'], 0, 10);
        $wanted = Recurrence::occurrencesBetween((string) $series['rule'], $start, $start, $through);
        $existing = array_map(fn (mixed $d) => substr((string) $d, 0, 10), $db->column('SELECT occurrence_date FROM {{events}} WHERE series_id = ?', [$series['id']]));
        $excluded = array_map('strval', array_filter((array) json_decode((string) $series['excluded_dates'], true), 'is_string'));
        $dates = Series::datesToCreate($wanted, $existing, $excluded);
        $made = 0;
        foreach (Series::planOccurrences($series, $dates) as $plan) {
            $row = [
                'id' => Id::new(),
                'slug' => $this->uniqueSlug($db, (string) $series['title'] . ' ' . $plan['date']),
                'title' => $series['title'],
                'description' => $series['description'],
                'location' => $series['location'],
                'starts_at' => Db::datetime($plan['startsAt']),
                'ends_at' => $plan['endsAt'] === null ? null : Db::datetime($plan['endsAt']),
                'all_day' => (int) (bool) $series['all_day'],
                'published' => (int) (bool) $series['published'],
                'member_only' => (int) (bool) $series['member_only'],
                'registration' => (int) (bool) $series['registration'],
                'capacity' => $series['capacity'],
                'waitlist' => (int) (bool) $series['waitlist'],
                'opens_at' => $plan['opensAt'] === null ? null : Db::datetime($plan['opensAt']),
                'closes_at' => $plan['closesAt'] === null ? null : Db::datetime($plan['closesAt']),
                'max_guests' => (int) $series['max_guests'],
                'series_id' => $series['id'],
                'occurrence_date' => $plan['date'],
            ];
            try {
                $db->insert('events', $row);
                $made++;
            } catch (\Throwable) {
                // The unique index on (series, date) is the backstop; asking
                // what already exists is what stops it firing.
                continue;
            }
        }
        $db->update('event_series', ['generated_through' => $through], ['id' => $series['id']]);
        return $made;
    }

    /**
     * Stopping a series never deletes somebody's place: empty future dates
     * go, and anything past or booked survives as an ordinary one-off.
     */
    private function stopSeries(App $app, string $id): Response
    {
        $db = $app->db();
        $series = $this->findSeries($db, $id);
        $today = $this->now()->format('Y-m-d');
        $rows = $db->all(
            'SELECT e.id, e.starts_at, (SELECT COUNT(*) FROM {{event_registrations}} r WHERE r.event_id = e.id AND r.status <> ?) AS signups
             FROM {{events}} e WHERE e.series_id = ?',
            [Events::CANCELLED, $series['id']],
        );
        $plan = Series::stopSeriesPlan(array_map(fn (array $e) => [
            'id' => (string) $e['id'],
            'date' => substr((string) $e['starts_at'], 0, 10),
            'registrations' => (int) $e['signups'],
        ], $rows), $today);
        $db->transaction(function (Db $db) use ($plan, $series): void {
            if ($plan['delete'] !== []) {
                $db->run('DELETE FROM {{events}} WHERE id IN (' . implode(',', array_fill(0, count($plan['delete']), '?')) . ')', $plan['delete']);
            }
            // Detaching leaves what was booked exactly where it was.
            $db->delete('event_series', ['id' => $series['id']]);
        });
        Audit::log($db, (string) $app->currentUser()->email(), 'event.series.stop', 'EventSeries', (string) $series['id']);
        return Response::json(['ok' => true, 'removed' => count($plan['delete']), 'kept' => count($plan['keep'])]);
    }

    /** The daily job: every series kept filled in to the horizon. */
    public function extend(App $app, float $deadline): string
    {
        $db = $app->db();
        $through = Series::horizonEnd($this->now());
        $made = 0;
        $seen = 0;
        foreach ($db->all('SELECT * FROM {{event_series}} WHERE generated_through IS NULL OR generated_through < ? ORDER BY generated_through IS NOT NULL, generated_through LIMIT 200', [$through]) as $series) {
            if (microtime(true) > $deadline) {
                break;
            }
            $seen++;
            $made += $this->generate($app, $series, $through);
        }
        return "extended $seen series, $made dates";
    }
};
