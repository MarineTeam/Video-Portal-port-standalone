<?php
/**
 * Plugin Name: Schedules
 * Slug:        schedules
 * Version:     1.0.0
 * Description: Rotas anyone can read at /calendar — fed from a Google Sheet or managed here. Names rather than accounts, for the people who never log in.
 * Author:      Marine Team
 * Requires PHP: 8.2
 * Requires App: 3.0
 */

declare(strict_types=1);

require_once __DIR__ . '/src/Names.php';
require_once __DIR__ . '/src/Dates.php';
require_once __DIR__ . '/src/Parse.php';
require_once __DIR__ . '/src/Logic.php';
require_once __DIR__ . '/src/Visibility.php';
require_once __DIR__ . '/src/Duplicates.php';
require_once __DIR__ . '/src/People.php';
require_once __DIR__ . '/src/Sheets.php';
require_once __DIR__ . '/src/Sync.php';
require_once __DIR__ . '/src/Snapshot.php';

use App\Core\ApiError;
use App\Core\App;
use App\Core\Db;
use App\Core\Hooks;
use App\Core\Id;
use App\Core\Log;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Url;
use App\Core\Validator;
use App\Modules\Audit\Audit;
use App\Modules\Profile\Inbox;
use App\Modules\Jobs\Scheduler;
use App\Modules\Plugins\BasePlugin;
use App\Modules\Push\Push;
use App\Support\Slug;
use MarineTeam\Plugins\Schedules\Dates;
use MarineTeam\Plugins\Schedules\Duplicates;
use MarineTeam\Plugins\Schedules\Logic;
use MarineTeam\Plugins\Schedules\Names;
use MarineTeam\Plugins\Schedules\Parse;
use MarineTeam\Plugins\Schedules\People;
use MarineTeam\Plugins\Schedules\Sheets;
use MarineTeam\Plugins\Schedules\Snapshot;
use MarineTeam\Plugins\Schedules\Sync;
use MarineTeam\Plugins\Schedules\Visibility;

/**
 * The other kind of rota, brought over from the calendar app: any number of
 * recurring schedules — Breakbread, Welcome, Sound, Senior Visit — read by
 * people who never log in, and fed either from a Google Sheet somebody
 * already maintains or from the admin screens here.
 *
 * Deliberately separate from service plans. That one puts accounts against a
 * service's running order; this one puts *names* against recurring rotas,
 * and most of those names have no account and are not going to make one.
 *
 * The dates are public; the names need a sign-in. Which rotas exist, on what
 * days, with what notes and where — anyone with the URL. Who is on them —
 * members. It is enforced by handing a signed-out reader events with nobody
 * on them (Visibility), not events with names to be hidden: /api/people is a
 * 403 without a session, and a personId filter is refused, because "which
 * days is this id on" is "who is this", sideways.
 */
return new class (__DIR__) extends BasePlugin {
    /** Where the service account key lives. */
    public const KEY_SETTING = 'schedules.googleKey';

    /** Colours a schedule chip can take, matching the theme's accents. */
    public const COLORS = ['slate', 'blue', 'green', 'amber', 'rose', 'violet', 'teal'];

    public function boot(Hooks $hooks, App $app): void
    {
        $this->useLang($hooks);
        $this->useTemplates($hooks);
        $hooks->on('routes.register', function (Router $r) use ($app): void {
            $manage = Middleware::can($app, 'manage_events');

            $r->get('/calendar', fn (Request $req) => $this->calendarPage($app, $req));
            $r->get('/api/schedules', fn () => Response::json(['schedules' => $this->publicSchedules($app)]));
            $r->get('/api/schedules/[id]/events', fn (Request $req, array $p) => $this->scheduleEvents($app, $req, (string) $p['id']));
            $r->get('/api/calendar-events', fn (Request $req) => $this->calendarEvents($app, $req));
            // A 403 rather than the usual 401: this is not "sign in to
            // continue", it is "that is not yours to ask".
            $r->get('/api/people', fn () => $this->signedIn($app)
                ? Response::json(['people' => Snapshot::people($app->db())])
                : throw ApiError::forbidden(t('schedules.namesNeedSignIn')));
            $r->get('/api/sync/snapshot', fn (Request $req) => $this->snapshot($app, $req));

            $r->get('/admin/schedules', fn () => $app->page('schedules/admin', [
                'title' => t('schedules.admin'),
                'schedules' => $this->adminSchedules($app),
                'keySet' => $app->settings()->isSecretSet(self::KEY_SETTING),
                'colors' => self::COLORS,
                'script' => $this->asset('admin-schedules.js'),
            ], 200, 'layouts/admin'), [$manage]);
            $r->get('/admin/schedules/[id]', fn (Request $req, array $p) => $this->adminSchedulePage($app, (string) $p['id']), [$manage]);
            $r->get('/admin/people', fn () => $this->adminPeoplePage($app), [$manage]);

            $r->get('/api/admin/schedules', fn () => Response::json(['schedules' => $this->adminSchedules($app)]), [$manage]);
            $r->post('/api/admin/schedules', fn (Request $req) => $this->saveSchedule($app, $req, null), [$manage]);
            $r->post('/api/admin/schedules/reorder', fn (Request $req) => $this->reorder($app, $req), [$manage]);
            $r->get('/api/admin/schedules/[id]', fn (Request $req, array $p) => Response::json($this->presentAdminSchedule($app, $this->findSchedule($app->db(), (string) $p['id']))), [$manage]);
            $r->add('PATCH', '/api/admin/schedules/[id]', fn (Request $req, array $p) => $this->saveSchedule($app, $req, (string) $p['id']), [$manage]);
            $r->add('DELETE', '/api/admin/schedules/[id]', fn (Request $req, array $p) => $this->deleteSchedule($app, (string) $p['id']), [$manage]);
            $r->get('/api/admin/schedules/[id]/events', fn (Request $req, array $p) => $this->adminEvents($app, $req, (string) $p['id']), [$manage]);
            $r->post('/api/admin/schedules/[id]/events', fn (Request $req, array $p) => $this->saveEvent($app, $req, (string) $p['id'], null), [$manage]);
            $r->post('/api/admin/schedules/[id]/sync', fn (Request $req, array $p) => $this->syncNow($app, (string) $p['id']), [$manage]);
            $r->post('/api/admin/schedules/[id]/validate', fn (Request $req, array $p) => $this->validate($app, $req, (string) $p['id']), [$manage]);
            $r->get('/api/admin/calendar-events/[id]', fn (Request $req, array $p) => Response::json($this->presentAdminEvent($app->db(), $this->findEvent($app->db(), (string) $p['id']))), [$manage]);
            $r->add('PATCH', '/api/admin/calendar-events/[id]', fn (Request $req, array $p) => $this->saveEvent($app, $req, null, (string) $p['id']), [$manage]);
            $r->add('DELETE', '/api/admin/calendar-events/[id]', fn (Request $req, array $p) => $this->deleteEvent($app, (string) $p['id']), [$manage]);

            $r->get('/api/admin/people', fn (Request $req) => $this->adminPeople($app, $req), [$manage]);
            $r->post('/api/admin/people', fn (Request $req) => $this->savePerson($app, $req, null), [$manage]);
            $r->post('/api/admin/people/merge', fn (Request $req) => $this->mergePeople($app, $req), [$manage]);
            $r->add('PATCH', '/api/admin/people/[id]', fn (Request $req, array $p) => $this->savePerson($app, $req, (string) $p['id']), [$manage]);
            $r->add('DELETE', '/api/admin/people/[id]', fn (Request $req, array $p) => $this->deletePerson($app, (string) $p['id']), [$manage]);
            $r->post('/api/admin/schedules/key', fn (Request $req) => $this->saveKey($app, $req), [$manage]);
            $r->add('DELETE', '/api/admin/schedules/key', fn () => $this->forgetKey($app), [$manage]);
        });
        $hooks->filter('nav.sections', fn (array $nav) => [...$nav, ['href' => '/calendar', 'label' => t('schedules.title'), 'icon' => 'calendar']]);
        // The dates a rota names somebody on, in their own diary feed.
        $hooks->filter('calendar.entries', fn (array $entries, App $app, array $user) => [...$entries, ...$this->diaryEntries($app, (string) $user['id'])]);
        $hooks->on('jobs.register', function (Scheduler $s) use ($app): void {
            // Before the reminders, so they go out on this morning's data
            // rather than yesterday's.
            $s->register('sync-schedules', 86400, fn (float $deadline) => $this->syncDue($app, $deadline), 20.0, '05:30');
            $s->register('schedule-reminders', 86400, fn (float $deadline) => $this->remind($app), 10.0, '06:30');
        });
    }

    private function today(): string
    {
        return gmdate('Y-m-d');
    }

    private function signedIn(App $app): bool
    {
        return $app->currentUser()->isSignedIn();
    }

    // -- Reading ----------------------------------------------------------

    /** @return list<array<string, mixed>> */
    private function publicSchedules(App $app): array
    {
        return array_map(fn (array $s) => [
            'id' => (string) $s['id'],
            'slug' => (string) $s['slug'],
            'name' => (string) $s['name'],
            'description' => $s['description'],
            'icon' => (string) $s['icon'],
            'color' => (string) $s['color'],
            'displayOrder' => (int) $s['display_order'],
        ], $app->db()->all('SELECT * FROM {{schedules}} WHERE enabled = 1 AND deleted_at IS NULL ORDER BY display_order, name LIMIT 200'));
    }

    /**
     * The events themselves, already shaped for the wire.
     *
     * @param list<string> $scheduleIds
     * @return list<array<string, mixed>>
     */
    private function eventsBetween(Db $db, array $scheduleIds, string $from, string $to, ?string $personId = null): array
    {
        if ($scheduleIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($scheduleIds), '?'));
        $sql = "SELECT e.* FROM {{calendar_events}} e WHERE e.schedule_id IN ($in) AND e.deleted_at IS NULL
                AND COALESCE(e.end_date, e.date) >= ? AND e.date <= ?";
        $params = [...$scheduleIds, $from, $to];
        if ($personId !== null) {
            $sql .= ' AND EXISTS (SELECT 1 FROM {{calendar_event_people}} ep WHERE ep.event_id = e.id AND ep.person_id = ?)';
            $params[] = $personId;
        }
        $sql .= ' ORDER BY e.date, e.start_time IS NULL DESC, e.start_time LIMIT 2000';
        $rows = $db->all($sql, $params);
        $people = Snapshot::peopleByEvent($db, array_map(fn (array $r) => (string) $r['id'], $rows));
        return array_map(fn (array $r) => [
            'id' => (string) $r['id'],
            'scheduleId' => (string) $r['schedule_id'],
            'date' => (string) $r['date'],
            'endDate' => $r['end_date'],
            'allDay' => (bool) $r['all_day'],
            'startTime' => $r['start_time'],
            'endTime' => $r['end_time'],
            'title' => $r['title'],
            'notes' => $r['notes'],
            'location' => $r['location'],
            'status' => (string) $r['status'],
            'people' => $people[(string) $r['id']] ?? [],
        ], $rows);
    }

    /**
     * Anybody may ask which days a rota runs. Only a member may ask which
     * days one *person* is on — the answer to that is a name.
     */
    private function wantedPerson(App $app, Request $req): ?string
    {
        $personId = trim((string) ($req->query('personId') ?? ''));
        if ($personId === '') {
            return null;
        }
        if (!$this->signedIn($app)) {
            throw ApiError::forbidden(t('schedules.namesNeedSignIn'));
        }
        return Id::isValid($personId) ? $personId : throw ApiError::invalid(t('schedules.noSuchPerson'));
    }

    /** @return array{from: string, to: string} */
    private function window(Request $req): array
    {
        $today = $this->today();
        $from = (string) ($req->query('from') ?? '');
        $to = (string) ($req->query('to') ?? '');
        $valid = static fn (string $d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;
        return [
            'from' => $valid($from) ? $from : (new \DateTimeImmutable($today, new \DateTimeZone('UTC')))->modify('-' . Snapshot::DAYS_BACK . ' days')->format('Y-m-d'),
            'to' => $valid($to) ? $to : (new \DateTimeImmutable($today, new \DateTimeZone('UTC')))->modify('+' . Snapshot::DAYS_FORWARD . ' days')->format('Y-m-d'),
        ];
    }

    private function calendarEvents(App $app, Request $req): Response
    {
        $schedules = $this->publicSchedules($app);
        $wanted = array_values(array_filter(array_map('trim', explode(',', (string) ($req->query('scheduleId') ?? '')))));
        $ids = array_map(fn (array $s) => (string) $s['id'], $schedules);
        if ($wanted !== []) {
            $ids = array_values(array_intersect($ids, $wanted));
        }
        $window = $this->window($req);
        $events = $this->eventsBetween($app->db(), $ids, $window['from'], $window['to'], $this->wantedPerson($app, $req));
        return Response::json([
            'events' => Visibility::visibleEvents($events, $this->signedIn($app)),
            'from' => $window['from'],
            'to' => $window['to'],
        ]);
    }

    private function scheduleEvents(App $app, Request $req, string $id): Response
    {
        $schedule = $app->db()->one('SELECT * FROM {{schedules}} WHERE (id = ? OR slug = ?) AND enabled = 1 AND deleted_at IS NULL', [$id, $id]);
        if ($schedule === null) {
            throw ApiError::notFound();
        }
        $window = $this->window($req);
        $events = $this->eventsBetween($app->db(), [(string) $schedule['id']], $window['from'], $window['to'], $this->wantedPerson($app, $req));
        return Response::json(['events' => Visibility::visibleEvents($events, $this->signedIn($app))]);
    }

    private function snapshot(App $app, Request $req): Response
    {
        $since = trim((string) ($req->query('since') ?? ''));
        $snapshot = Snapshot::build($app->db(), [
            'since' => $since === '' ? null : $since,
            'signedIn' => $this->signedIn($app),
        ]);
        $response = Response::json($snapshot);
        // It carries names; a shared cache must not keep it.
        $response->header('Cache-Control', 'private, no-store');
        return $response;
    }

    private function calendarPage(App $app, Request $req): Response
    {
        $signedIn = $this->signedIn($app);
        $schedules = $this->publicSchedules($app);
        $window = $this->window($req);
        $events = Visibility::visibleEvents(
            $this->eventsBetween($app->db(), array_map(fn (array $s) => (string) $s['id'], $schedules), $window['from'], $window['to']),
            $signedIn,
        );
        $upcoming = Logic::upcomingEvents(Logic::filterEvents($events), $this->today(), ['includeToday' => true, 'limit' => 60]);
        return $app->page('schedules/calendar', [
            'title' => t('schedules.title'),
            // Even without names it says when the building is in use.
            'noindex' => true,
            'schedules' => $schedules,
            'days' => Logic::groupByDay($upcoming),
            'people' => Visibility::visiblePeople(Snapshot::people($app->db()), $signedIn),
            'signedIn' => $signedIn,
            'today' => $this->today(),
            'script' => $this->asset('calendar.js'),
        ]);
    }

    // -- A member's own diary ---------------------------------------------

    /** @return list<array<string, mixed>> */
    private function diaryEntries(App $app, string $userId): array
    {
        $personId = $app->db()->value('SELECT id FROM {{people}} WHERE user_id = ? AND deleted_at IS NULL', [$userId]);
        if (!is_string($personId)) {
            return [];
        }
        $host = parse_url(Url::absolute('/'), PHP_URL_HOST) ?: 'church';
        $rows = $app->db()->all(
            'SELECT e.*, s.name AS schedule_name FROM {{calendar_events}} e
             JOIN {{schedules}} s ON s.id = e.schedule_id
             JOIN {{calendar_event_people}} ep ON ep.event_id = e.id
             WHERE ep.person_id = ? AND e.deleted_at IS NULL AND s.enabled = 1 AND s.deleted_at IS NULL AND e.date >= ?
             ORDER BY e.date LIMIT 400',
            [$personId, (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-3 months')->format('Y-m-d')],
        );
        return array_map(fn (array $e) => [
            'uid' => 'rota-' . $e['id'] . '@' . $host,
            'summary' => (string) $e['schedule_name'] . (($e['title'] ?? null) !== null ? ' — ' . (string) $e['title'] : ''),
            'starts' => new \DateTimeImmutable((string) $e['date'] . ' ' . ((string) ($e['start_time'] ?? '') ?: '00:00'), new \DateTimeZone('UTC')),
            'ends' => null,
            'allDay' => (bool) $e['all_day'],
            'url' => Url::absolute('/calendar'),
            'location' => $e['location'],
            'status' => (string) $e['status'] === Logic::CANCELLED ? 'CANCELLED' : null,
        ], $rows);
    }

    // -- Administration: schedules ----------------------------------------

    /** @return array<string, mixed> */
    private function findSchedule(Db $db, string $id): array
    {
        $row = Id::isValid($id) ? $db->one('SELECT * FROM {{schedules}} WHERE id = ?', [$id]) : null;
        return $row ?? throw ApiError::notFound();
    }

    /** @return array<string, mixed> */
    private function findEvent(Db $db, string $id): array
    {
        $row = Id::isValid($id) ? $db->one('SELECT * FROM {{calendar_events}} WHERE id = ?', [$id]) : null;
        return $row ?? throw ApiError::notFound();
    }

    /** @return list<array<string, mixed>> */
    private function adminSchedules(App $app): array
    {
        $rows = $app->db()->all('SELECT * FROM {{schedules}} WHERE deleted_at IS NULL ORDER BY display_order, name LIMIT 200');
        return array_map(fn (array $s) => $this->presentAdminSchedule($app, $s), $rows);
    }

    /**
     * @param array<string, mixed> $schedule
     * @return array<string, mixed>
     */
    private function presentAdminSchedule(App $app, array $schedule): array
    {
        $db = $app->db();
        $source = $db->one('SELECT * FROM {{schedule_sources}} WHERE schedule_id = ?', [$schedule['id']]);
        return [
            'id' => (string) $schedule['id'],
            'slug' => (string) $schedule['slug'],
            'name' => (string) $schedule['name'],
            'description' => $schedule['description'],
            'icon' => (string) $schedule['icon'],
            'color' => (string) $schedule['color'],
            'enabled' => (bool) $schedule['enabled'],
            'displayOrder' => (int) $schedule['display_order'],
            'sourceType' => (string) $schedule['source_type'],
            'eventCount' => (int) $db->value('SELECT COUNT(*) FROM {{calendar_events}} WHERE schedule_id = ? AND deleted_at IS NULL', [$schedule['id']]),
            'source' => $source === null ? null : [
                'spreadsheetId' => $source['spreadsheet_id'],
                'sheetName' => $source['sheet_name'],
                'range' => $source['range'],
                'format' => $source['format'],
                'parserConfig' => json_decode((string) $source['parser_config'], true) ?: new \stdClass(),
                'syncIntervalMinutes' => (int) $source['sync_interval_minutes'],
                'lastSyncedAt' => $source['last_synced_at'],
                'lastSyncStatus' => (string) $source['last_sync_status'],
                'lastSyncError' => $source['last_sync_error'],
            ],
        ];
    }

    private function adminSchedulePage(App $app, string $id): Response
    {
        $schedule = $this->findSchedule($app->db(), $id);
        $window = ['from' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-60 days')->format('Y-m-d'), 'to' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+400 days')->format('Y-m-d')];
        $events = $this->eventsBetween($app->db(), [(string) $schedule['id']], $window['from'], $window['to']);
        return $app->page('schedules/admin-schedule', [
            'title' => (string) $schedule['name'],
            'schedule' => $this->presentAdminSchedule($app, $schedule),
            'events' => $events,
            'colors' => self::COLORS,
            'formats' => Parse::FORMATS,
            'keySet' => $app->settings()->isSecretSet(self::KEY_SETTING),
            'script' => $this->asset('admin-schedules.js'),
        ], 200, 'layouts/admin');
    }

    private function saveSchedule(App $app, Request $req, ?string $id): Response
    {
        $db = $app->db();
        $existing = $id === null ? null : $this->findSchedule($db, $id);
        $data = Validator::check($req->input(), [
            'name' => ['string', ...($id === null ? ['required'] : []), 'max' => 255],
            'description' => ['text', 'nullable', 'max' => 2000],
            'icon' => ['string', 'max' => 64],
            'color' => ['string', 'max' => 32],
            'enabled' => ['bool'],
            'sourceType' => ['string', 'max' => 32],
        ]);
        $set = [];
        if (isset($data['name'])) {
            $set['name'] = trim((string) $data['name']);
            if ($set['name'] === '') {
                throw ApiError::invalid(t('schedules.nameNeeded'));
            }
        }
        foreach (['description' => 'description', 'icon' => 'icon'] as $in => $column) {
            if (array_key_exists($in, $data)) {
                $set[$column] = $data[$in];
            }
        }
        if (isset($data['color'])) {
            $set['color'] = in_array((string) $data['color'], self::COLORS, true) ? (string) $data['color'] : 'slate';
        }
        if (array_key_exists('enabled', $data)) {
            $set['enabled'] = (bool) $data['enabled'] ? 1 : 0;
        }
        if (isset($data['sourceType'])) {
            $set['source_type'] = in_array((string) $data['sourceType'], ['WEB', 'GOOGLE_SHEET'], true) ? (string) $data['sourceType'] : 'WEB';
        }
        if ($existing === null) {
            $set['slug'] = Slug::unique((string) $set['name'], fn (string $slug) => $db->value('SELECT 1 FROM {{schedules}} WHERE slug = ?', [$slug]) !== null, 'rota');
            $set['display_order'] = (int) $db->value('SELECT COALESCE(MAX(display_order), 0) + 1 FROM {{schedules}}');
            $scheduleId = $db->insert('schedules', $set);
        } else {
            $scheduleId = (string) $existing['id'];
            if ($set !== []) {
                $db->update('schedules', $set, ['id' => $scheduleId]);
            }
        }
        $this->saveSource($app, $req, $scheduleId);
        Audit::log($db, (string) $app->currentUser()->email(), $existing === null ? 'schedule.create' : 'schedule.update', 'Schedule', $scheduleId);
        return Response::json($this->presentAdminSchedule($app, $this->findSchedule($db, $scheduleId)), $existing === null ? 201 : 200);
    }

    /**
     * A schedule is managed here or fed by a sheet, and everything
     * downstream cannot tell which; switching one over keeps the events
     * already imported, as ordinary editable rows.
     */
    private function saveSource(App $app, Request $req, string $scheduleId): void
    {
        $input = $req->input();
        if (!array_key_exists('source', $input)) {
            return;
        }
        $db = $app->db();
        if ($input['source'] === null) {
            $db->delete('schedule_sources', ['schedule_id' => $scheduleId]);
            return;
        }
        $source = Validator::check(is_array($input['source']) ? $input['source'] : [], [
            'spreadsheetId' => ['string', 'required', 'max' => 191],
            'sheetName' => ['string', 'nullable', 'max' => 191],
            'range' => ['string', 'nullable', 'max' => 64],
            'format' => ['string', 'max' => 32],
            'syncIntervalMinutes' => ['int', 'min' => 15, 'max' => 10080],
            'parserConfig' => ['json'],
        ]);
        $format = (string) ($source['format'] ?? Parse::DATE_NAMES);
        $row = [
            'type' => 'GOOGLE_SHEET',
            'spreadsheet_id' => $this->spreadsheetId((string) $source['spreadsheetId']),
            'sheet_name' => $source['sheetName'] ?? null,
            'range' => $source['range'] ?? null,
            'format' => in_array($format, Parse::FORMATS, true) ? $format : Parse::DATE_NAMES,
            'parser_config' => json_encode((array) ($source['parserConfig'] ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'sync_interval_minutes' => (int) ($source['syncIntervalMinutes'] ?? 1440),
        ];
        $existing = $db->one('SELECT id FROM {{schedule_sources}} WHERE schedule_id = ?', [$scheduleId]);
        if ($existing === null) {
            $db->insert('schedule_sources', $row + ['schedule_id' => $scheduleId, 'last_sync_status' => Sync::NEVER]);
        } else {
            $db->update('schedule_sources', $row, ['id' => $existing['id']]);
        }
        $db->update('schedules', ['source_type' => 'GOOGLE_SHEET'], ['id' => $scheduleId]);
    }

    /** People paste the whole URL, which is the sensible thing to have to hand. */
    private function spreadsheetId(string $given): string
    {
        if (preg_match('#/spreadsheets/d/([a-zA-Z0-9_-]+)#', $given, $m) === 1) {
            return $m[1];
        }
        return trim($given);
    }

    private function reorder(App $app, Request $req): Response
    {
        $data = Validator::check($req->input(), ['ids' => ['array', 'required']]);
        $db = $app->db();
        $db->transaction(function () use ($db, $data): void {
            $at = 0;
            foreach ((array) $data['ids'] as $id) {
                if (is_string($id) && Id::isValid($id)) {
                    $db->update('schedules', ['display_order' => $at++], ['id' => $id]);
                }
            }
        });
        return Response::json(['ok' => true, 'schedules' => $this->adminSchedules($app)]);
    }

    private function deleteSchedule(App $app, string $id): Response
    {
        $db = $app->db();
        $schedule = $this->findSchedule($db, $id);
        $db->update('schedules', ['deleted_at' => Db::now(), 'enabled' => 0], ['id' => $schedule['id']]);
        Audit::log($db, (string) $app->currentUser()->email(), 'schedule.delete', 'Schedule', (string) $schedule['id']);
        return Response::json(['ok' => true]);
    }

    // -- Administration: the sheet ----------------------------------------

    /** @return array<string, mixed> the decoded service account key */
    private function key(App $app): array
    {
        $raw = $app->settings()->get(self::KEY_SETTING);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!Sheets::looksLikeKey($decoded)) {
            throw ApiError::invalid(t('schedules.noKey'));
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function saveKey(App $app, Request $req): Response
    {
        $data = Validator::check($req->input(), ['key' => ['string', 'required', 'max' => 20000]]);
        $decoded = json_decode((string) $data['key'], true);
        if (!Sheets::looksLikeKey($decoded)) {
            throw ApiError::invalid(t('schedules.badKey'));
        }
        $app->settings()->setSecret(self::KEY_SETTING, (string) json_encode($decoded));
        Audit::log($app->db(), (string) $app->currentUser()->email(), 'schedule.key', 'Setting', self::KEY_SETTING);
        return Response::json(['ok' => true, 'clientEmail' => (string) $decoded['client_email']]);
    }

    private function forgetKey(App $app): Response
    {
        $app->settings()->delete(self::KEY_SETTING);
        Audit::log($app->db(), (string) $app->currentUser()->email(), 'schedule.key.forget', 'Setting', self::KEY_SETTING);
        return Response::json(['ok' => true]);
    }

    /**
     * Test connection: the first few events exactly as the parser read them,
     * and every row it skipped and why, before anything is imported. A
     * column mapping is guesswork until you can see what it made of the
     * sheet.
     */
    private function validate(App $app, Request $req, string $id): Response
    {
        $schedule = $this->findSchedule($app->db(), $id);
        $source = $app->db()->one('SELECT * FROM {{schedule_sources}} WHERE schedule_id = ?', [$schedule['id']]);
        $input = $req->input();
        $config = is_array($input['parserConfig'] ?? null) ? $input['parserConfig'] : ($source === null ? [] : Sync::parserConfig($source));
        if (isset($input['format']) && in_array((string) $input['format'], Parse::FORMATS, true)) {
            $config['format'] = (string) $input['format'];
        }
        $spreadsheetId = $this->spreadsheetId((string) ($input['spreadsheetId'] ?? ($source['spreadsheet_id'] ?? '')));
        if ($spreadsheetId === '') {
            throw ApiError::invalid(t('schedules.noSpreadsheet'));
        }
        $key = $this->key($app);
        try {
            $rows = Sheets::rows(
                $key,
                $spreadsheetId,
                is_string($input['sheetName'] ?? null) ? $input['sheetName'] : ($source['sheet_name'] ?? null),
                is_string($input['range'] ?? null) ? $input['range'] : ($source['range'] ?? null),
            );
        } catch (\RuntimeException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()]);
        }
        $parsed = Parse::parse($rows, $config);
        return Response::json([
            'ok' => true,
            'rows' => count($rows),
            'events' => array_slice($parsed['events'], 0, 20),
            'total' => count($parsed['events']),
            'skipped' => array_slice($parsed['skipped'], 0, 50),
            'truncated' => $parsed['truncated'],
        ]);
    }

    private function syncNow(App $app, string $id): Response
    {
        $schedule = $this->findSchedule($app->db(), $id);
        $source = $app->db()->one('SELECT * FROM {{schedule_sources}} WHERE schedule_id = ?', [$schedule['id']]);
        if ($source === null) {
            throw ApiError::invalid(t('schedules.notFromASheet'));
        }
        $result = $this->syncOne($app, $source);
        return Response::json($result);
    }

    /**
     * One import. Whatever goes wrong, nothing already imported is touched.
     *
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function syncOne(App $app, array $source): array
    {
        $db = $app->db();
        try {
            $rows = Sheets::rows(
                $this->key($app),
                (string) $source['spreadsheet_id'],
                $source['sheet_name'] === null ? null : (string) $source['sheet_name'],
                $source['range'] === null ? null : (string) $source['range'],
            );
            $parsed = Parse::parse($rows, Sync::parserConfig($source));
            $fingerprint = Parse::fingerprintEvents($parsed['events']);
            $counts = Sync::apply($db, (string) $source['schedule_id'], $parsed['events'], $fingerprint, $source['last_sync_hash'] === null ? null : (string) $source['last_sync_hash']);
            $db->update('schedule_sources', [
                'last_synced_at' => Db::now(),
                'last_sync_status' => $counts['status'],
                'last_sync_error' => null,
                'last_sync_hash' => $fingerprint,
            ], ['id' => $source['id']]);
            return $counts + ['skipped' => $parsed['skipped']];
        } catch (\Throwable $e) {
            // Deletes nothing: an empty rota on a Sunday morning is worse
            // than a stale one.
            $db->update('schedule_sources', [
                'last_synced_at' => Db::now(),
                'last_sync_status' => Sync::FAILED,
                'last_sync_error' => mb_substr($e->getMessage(), 0, 2000),
            ], ['id' => $source['id']]);
            Log::warning('schedules: sync failed', ['schedule' => (string) $source['schedule_id'], 'error' => $e->getMessage()]);
            return ['status' => Sync::FAILED, 'error' => $e->getMessage(), 'imported' => 0, 'updated' => 0, 'removed' => 0, 'people' => 0, 'skipped' => []];
        }
    }

    private function syncDue(App $app, float $deadline): string
    {
        $done = 0;
        $failed = 0;
        foreach ($app->db()->all('SELECT s.* FROM {{schedule_sources}} s JOIN {{schedules}} c ON c.id = s.schedule_id WHERE c.deleted_at IS NULL AND c.enabled = 1') as $source) {
            if (microtime(true) > $deadline) {
                break;
            }
            if (!Sync::isDue($source)) {
                continue;
            }
            $result = $this->syncOne($app, $source);
            $done++;
            if (($result['status'] ?? '') === Sync::FAILED) {
                $failed++;
            }
        }
        return "synced $done, failed $failed";
    }

    // -- Administration: events -------------------------------------------

    private function adminEvents(App $app, Request $req, string $id): Response
    {
        $schedule = $this->findSchedule($app->db(), $id);
        $window = $this->window($req);
        return Response::json(['events' => $this->eventsBetween($app->db(), [(string) $schedule['id']], $window['from'], $window['to'])]);
    }

    /** @param array<string, mixed> $event */
    private function presentAdminEvent(Db $db, array $event): array
    {
        $people = Snapshot::peopleByEvent($db, [(string) $event['id']]);
        return [
            'id' => (string) $event['id'],
            'scheduleId' => (string) $event['schedule_id'],
            'date' => (string) $event['date'],
            'endDate' => $event['end_date'],
            'allDay' => (bool) $event['all_day'],
            'startTime' => $event['start_time'],
            'endTime' => $event['end_time'],
            'title' => $event['title'],
            'notes' => $event['notes'],
            'location' => $event['location'],
            'status' => (string) $event['status'],
            'origin' => (string) $event['origin'],
            'people' => $people[(string) $event['id']] ?? [],
        ];
    }

    private function saveEvent(App $app, Request $req, ?string $scheduleId, ?string $eventId): Response
    {
        $db = $app->db();
        $existing = $eventId === null ? null : $this->findEvent($db, $eventId);
        $schedule = $this->findSchedule($db, $scheduleId ?? (string) $existing['schedule_id']);
        $data = Validator::check($req->input(), [
            'date' => ['date', ...($existing === null ? ['required'] : [])],
            'endDate' => ['date', 'nullable'],
            'startTime' => ['string', 'nullable', 'max' => 5],
            'endTime' => ['string', 'nullable', 'max' => 5],
            'title' => ['string', 'nullable', 'max' => 255],
            'notes' => ['text', 'nullable', 'max' => 5000],
            'location' => ['string', 'nullable', 'max' => 500],
            'status' => ['string', 'max' => 32],
            'people' => ['array'],
        ]);
        $set = [];
        foreach ([
            'date' => 'date', 'endDate' => 'end_date', 'title' => 'title',
            'notes' => 'notes', 'location' => 'location',
        ] as $in => $column) {
            if (array_key_exists($in, $data)) {
                $set[$column] = $data[$in];
            }
        }
        foreach (['startTime' => 'start_time', 'endTime' => 'end_time'] as $in => $column) {
            if (array_key_exists($in, $data)) {
                $set[$column] = $data[$in] === null || trim((string) $data[$in]) === '' ? null : Dates::parseSheetTime((string) $data[$in]);
            }
        }
        if (array_key_exists('start_time', $set)) {
            $set['all_day'] = ($set['start_time'] ?? null) === null ? 1 : 0;
        }
        if (isset($data['status'])) {
            $set['status'] = (string) $data['status'] === Logic::CANCELLED ? Logic::CANCELLED : Logic::CONFIRMED;
        }
        if (($set['end_date'] ?? null) !== null && (string) ($set['end_date']) < (string) ($set['date'] ?? $existing['date'] ?? '')) {
            throw ApiError::invalid(t('schedules.endsBeforeItStarts'));
        }
        $result = $db->transaction(function () use ($db, $set, $existing, $schedule, $data, $eventId) {
            if ($existing === null) {
                $id = $db->insert('calendar_events', $set + ['schedule_id' => (string) $schedule['id'], 'origin' => Sync::ORIGIN_WEB, 'all_day' => ($set['start_time'] ?? null) === null ? 1 : 0]);
            } else {
                $id = (string) $eventId;
                if ($set !== []) {
                    $db->update('calendar_events', $set, ['id' => $id]);
                }
            }
            if (array_key_exists('people', $data)) {
                $this->setPeople($db, $id, (array) $data['people']);
            }
            return $id;
        });
        Audit::log($db, (string) $app->currentUser()->email(), $existing === null ? 'calendarEvent.create' : 'calendarEvent.update', 'CalendarEvent', (string) $result);
        return Response::json($this->presentAdminEvent($db, $this->findEvent($db, (string) $result)), $existing === null ? 201 : 200);
    }

    /**
     * Who is on, given as names or as ids — the admin screen sends names,
     * because that is what somebody reading a rota has.
     *
     * @param list<mixed> $wanted
     */
    private function setPeople(Db $db, string $eventId, array $wanted): void
    {
        $ids = [];
        foreach ($wanted as $one) {
            $name = null;
            $role = null;
            if (is_string($one)) {
                $name = $one;
            } elseif (is_array($one)) {
                $name = is_string($one['displayName'] ?? null) ? $one['displayName'] : null;
                $role = is_string($one['role'] ?? null) && trim($one['role']) !== '' ? trim($one['role']) : null;
                if (is_string($one['personId'] ?? null) && Id::isValid($one['personId'])) {
                    $ids[$one['personId']] = $role;
                    continue;
                }
            }
            if ($name === null || !Names::isPlausibleName($name)) {
                continue;
            }
            $personId = People::resolve($db, $name);
            if ($personId !== null) {
                $ids[$personId] = $role;
            }
        }
        $db->delete('calendar_event_people', ['event_id' => $eventId]);
        $position = 0;
        foreach ($ids as $personId => $role) {
            $db->insert('calendar_event_people', ['event_id' => $eventId, 'person_id' => (string) $personId, 'role' => $role, 'position' => $position++]);
        }
    }

    private function deleteEvent(App $app, string $id): Response
    {
        $db = $app->db();
        $event = $this->findEvent($db, $id);
        $db->update('calendar_events', ['deleted_at' => Db::now()], ['id' => $event['id']]);
        Audit::log($db, (string) $app->currentUser()->email(), 'calendarEvent.delete', 'CalendarEvent', (string) $event['id']);
        return Response::json(['ok' => true]);
    }

    // -- Administration: people -------------------------------------------

    private function adminPeoplePage(App $app): Response
    {
        $people = $this->peopleWithCounts($app->db());
        return $app->page('schedules/admin-people', [
            'title' => t('schedules.people'),
            'people' => $people,
            'duplicates' => Duplicates::possibleDuplicates(array_map(fn (array $p) => ['id' => $p['id'], 'displayName' => $p['displayName']], $people)),
            'script' => $this->asset('admin-schedules.js'),
        ], 200, 'layouts/admin');
    }

    /** @return list<array<string, mixed>> */
    private function peopleWithCounts(Db $db): array
    {
        $rows = $db->all(
            'SELECT p.*, u.email AS user_email,
                    (SELECT COUNT(*) FROM {{calendar_event_people}} ep JOIN {{calendar_events}} e ON e.id = ep.event_id
                      WHERE ep.person_id = p.id AND e.deleted_at IS NULL) AS dates
             FROM {{people}} p LEFT JOIN {{users}} u ON u.id = p.user_id
             WHERE p.deleted_at IS NULL ORDER BY p.display_name LIMIT 2000',
        );
        return array_map(fn (array $p) => [
            'id' => (string) $p['id'],
            'displayName' => (string) $p['display_name'],
            'normalizedName' => (string) $p['normalized_name'],
            'active' => (bool) $p['active'],
            'userEmail' => $p['user_email'],
            'dates' => (int) $p['dates'],
        ], $rows);
    }

    private function adminPeople(App $app, Request $req): Response
    {
        $people = $this->peopleWithCounts($app->db());
        $query = trim((string) ($req->query('q') ?? ''));
        if ($query !== '') {
            $key = Names::normalizeName($query);
            $people = array_values(array_filter($people, fn (array $p) => $key !== '' && str_contains($p['normalizedName'], $key)));
        }
        return Response::json([
            'people' => $people,
            'duplicates' => Duplicates::possibleDuplicates(array_map(fn (array $p) => ['id' => $p['id'], 'displayName' => $p['displayName']], $people)),
        ]);
    }

    private function savePerson(App $app, Request $req, ?string $id): Response
    {
        $db = $app->db();
        $data = Validator::check($req->input(), [
            'displayName' => ['string', ...($id === null ? ['required'] : []), 'max' => 255],
            'active' => ['bool'],
            'userId' => ['id', 'nullable'],
        ]);
        if ($id === null) {
            $name = (string) $data['displayName'];
            if (!Names::isPlausibleName($name)) {
                throw ApiError::invalid(t('schedules.notAName'));
            }
            $personId = People::resolve($db, $name);
            if ($personId === null) {
                throw ApiError::invalid(t('schedules.notAName'));
            }
        } else {
            $person = $db->one('SELECT * FROM {{people}} WHERE id = ? AND deleted_at IS NULL', [Id::isValid($id) ? $id : '']);
            if ($person === null) {
                throw ApiError::notFound();
            }
            $personId = (string) $person['id'];
            $set = [];
            if (isset($data['displayName'])) {
                $name = trim((string) $data['displayName']);
                if (!Names::isPlausibleName($name)) {
                    throw ApiError::invalid(t('schedules.notAName'));
                }
                // The spelling that was there stays as an alias, so the next
                // sync resolves it here rather than making it again.
                $was = (string) $person['normalized_name'];
                $now = Names::normalizeName($name);
                $set = ['display_name' => $name, 'normalized_name' => $now];
                if ($now !== $was) {
                    try {
                        $db->insert('person_aliases', ['person_id' => $personId, 'normalized_name' => $was]);
                    } catch (\Throwable $e) {
                        if (!Db::isDuplicate($e)) {
                            throw $e;
                        }
                    }
                }
            }
            if (array_key_exists('active', $data)) {
                $set['active'] = (bool) $data['active'] ? 1 : 0;
            }
            if (array_key_exists('userId', $data)) {
                $set['user_id'] = $data['userId'];
            }
            if ($set !== []) {
                try {
                    $db->update('people', $set, ['id' => $personId]);
                } catch (\Throwable $e) {
                    throw Db::isDuplicate($e) ? ApiError::conflict(t('schedules.alreadyAPerson')) : $e;
                }
            }
        }
        Audit::log($db, (string) $app->currentUser()->email(), $id === null ? 'person.create' : 'person.update', 'Person', $personId);
        return Response::json(['ok' => true, 'people' => $this->peopleWithCounts($db)], $id === null ? 201 : 200);
    }

    /**
     * Never automatic: "Dave" and "Davey" may well be two people, and a
     * merge moves one person's whole history onto another.
     */
    private function mergePeople(App $app, Request $req): Response
    {
        $data = Validator::check($req->input(), ['keepId' => ['id', 'required'], 'loseId' => ['id', 'required']]);
        if ($data['keepId'] === $data['loseId']) {
            throw ApiError::invalid(t('schedules.mergeSameName'));
        }
        $db = $app->db();
        People::merge($db, (string) $data['keepId'], (string) $data['loseId']);
        Audit::log($db, (string) $app->currentUser()->email(), 'person.merge', 'Person', (string) $data['keepId'], 'merged ' . $data['loseId']);
        return Response::json(['ok' => true, 'people' => $this->peopleWithCounts($db)]);
    }

    private function deletePerson(App $app, string $id): Response
    {
        $db = $app->db();
        if (!Id::isValid($id) || $db->one('SELECT id FROM {{people}} WHERE id = ? AND deleted_at IS NULL', [$id]) === null) {
            throw ApiError::notFound();
        }
        $db->update('people', ['deleted_at' => Db::now(), 'active' => 0], ['id' => $id]);
        Audit::log($db, (string) $app->currentUser()->email(), 'person.delete', 'Person', $id);
        return Response::json(['ok' => true]);
    }

    // -- Reminders ---------------------------------------------------------

    /**
     * What somebody is on for tomorrow: one message however many rotas, and
     * only to the names linked to an account. Somebody on a rota with no
     * account gets no reminder — the calendar is the source of truth, and
     * linking a name to an account is what turns reminders on.
     */
    private function remind(App $app): string
    {
        $db = $app->db();
        $tomorrow = (new \DateTimeImmutable($this->today(), new \DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
        $rows = $db->all(
            'SELECT p.user_id, s.name AS schedule_name, e.title, e.start_time FROM {{calendar_events}} e
             JOIN {{schedules}} s ON s.id = e.schedule_id
             JOIN {{calendar_event_people}} ep ON ep.event_id = e.id
             JOIN {{people}} p ON p.id = ep.person_id
             WHERE e.date = ? AND e.deleted_at IS NULL AND e.status = ? AND s.enabled = 1 AND s.deleted_at IS NULL
               AND p.user_id IS NOT NULL AND p.deleted_at IS NULL AND p.active = 1
             ORDER BY s.display_order, s.name LIMIT 2000',
            [$tomorrow, Logic::CONFIRMED],
        );
        $byUser = [];
        foreach ($rows as $row) {
            $what = (string) $row['schedule_name'] . (($row['start_time'] ?? null) !== null ? ' (' . (string) $row['start_time'] . ')' : '');
            $byUser[(string) $row['user_id']][] = $what;
        }
        $url = Url::absolute('/calendar');
        foreach ($byUser as $userId => $what) {
            $body = implode(', ', array_unique($what));
            Inbox::add($db, (string) $userId, t('schedules.tomorrowTitle'), $body, $url);
            Push::send($app, [(string) $userId], ['title' => t('schedules.tomorrowTitle'), 'body' => $body, 'url' => $url]);
        }
        return 'reminded ' . count($byUser);
    }
};
