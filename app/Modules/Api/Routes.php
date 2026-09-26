<?php

declare(strict_types=1);

namespace App\Modules\Api;

use App\Core\App;
use App\Core\Db;
use App\Core\Id;
use App\Core\Json;
use App\Core\Log;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Url;

/**
 * The read API (/api/v1).
 *
 * Another system reading this one: a noticeboard in the foyer, a
 * spreadsheet, the main church website, a migration off some other platform.
 *
 * Everything here is a read. There is no way to change anything through v1,
 * and that is an order of work rather than an oversight: a token that can
 * rewrite the diary is a much bigger decision than one that can read it.
 *
 * Content comes back including drafts and members-only items, with flags
 * saying which is which. A key is the organisation reading its own
 * catalogue, and hiding half of it would make the API useless for the
 * reporting and migration jobs it exists for; what a *visitor* may see is a
 * different question, answered on the pages.
 */
final class Routes
{
    public static function register(Router $r, App $app): void
    {
        // No key: an API whose own documentation needs a credential wastes
        // the first five minutes on the wrong problem.
        $r->get('/api/v1', fn () => self::describe($app));

        foreach ([
            'me' => ['scope' => null, 'fn' => 'me'],
            'categories' => ['scope' => 'content:read', 'fn' => 'categories'],
            'series' => ['scope' => 'content:read', 'fn' => 'series'],
            'videos' => ['scope' => 'content:read', 'fn' => 'videos'],
            'files' => ['scope' => 'content:read', 'fn' => 'files'],
            'events' => ['scope' => 'events:read', 'fn' => 'events'],
            'schedules' => ['scope' => 'schedules:read', 'fn' => 'schedules'],
            'calendar-events' => ['scope' => 'schedules:read', 'fn' => 'calendarEvents'],
            'groups' => ['scope' => 'groups:read', 'fn' => 'groups'],
            'analytics' => ['scope' => 'analytics:read', 'fn' => 'analytics'],
        ] as $path => $endpoint) {
            $r->get("/api/v1/$path", function (Request $req) use ($app, $endpoint) {
                return self::guarded($app, $req, $endpoint['scope'], fn (array $key) => self::{$endpoint['fn']}($app, $req, $key));
            });
        }
        $r->get('/api/v1/events/[id]/registrations', fn (Request $req, array $p) => self::guarded(
            $app,
            $req,
            'events:registrations',
            fn () => self::registrations($app, $req, (string) $p['id']),
        ));
    }

    /**
     * Authenticate, rate-limit, and check the one scope this endpoint needs.
     *
     * @param callable(array<string, mixed>): Response $then
     */
    private static function guarded(App $app, Request $req, ?string $scope, callable $then): Response
    {
        $given = Keys::bearerFrom($req->header('authorization'));
        if ($given === null) {
            return V1::fail('unauthorized', 'This endpoint needs an API key: Authorization: Bearer mt_live_…', 401);
        }
        $db = $app->db();
        $key = $db->one('SELECT * FROM {{api_keys}} WHERE hashed_key = ?', [Keys::hash($given)]);
        if ($key === null) {
            return V1::fail('unauthorized', 'That key is not one of ours.', 401);
        }
        $state = Keys::state($key);
        if ($state !== Keys::OK) {
            return V1::fail($state, $state === Keys::REVOKED ? 'That key has been revoked.' : 'That key has expired.', 401);
        }
        // Counted before the work, and a refusal still counts: it cost a
        // lookup either way.
        $limit = Keys::hit($db, (string) $key['id']);
        if (!$limit['allowed']) {
            return V1::fail('rate_limited', 'Too many requests for this key. It may make ' . Keys::PER_MINUTE . ' a minute.', 429, $limit['retryAfter']);
        }
        $held = self::scopesOf($key);
        if ($scope !== null && !Keys::allows($held, $scope)) {
            return V1::fail('forbidden', "This key does not hold the $scope scope.", 403);
        }
        try {
            return $then($key);
        } catch (\LogicException $e) {
            // assertNoSecrets threw: a query changed shape. Nothing goes out.
            Log::error('api/v1 refused to answer: ' . $e->getMessage(), ['key' => (string) $key['prefix']]);
            return V1::fail('server_error', 'Something went wrong.', 500);
        }
    }

    /** @return list<string> */
    private static function scopesOf(array $key): array
    {
        $scopes = json_decode((string) $key['scopes'], true);
        return Keys::cleanScopes(is_array($scopes) ? $scopes : []);
    }

    /** What the whole thing is, for somebody with a terminal and no key yet. */
    private static function describe(App $app): Response
    {
        $scopes = [];
        foreach (Keys::SCOPES as $name => $about) {
            $scopes[] = ['scope' => $name, 'label' => $about['label'], 'about' => $about['hint'], 'personalData' => $about['personal']];
        }
        return V1::ok([
            'name' => 'Marine Team read API',
            'version' => 'v1',
            'readOnly' => true,
            'about' => 'Everything here is a read. There is no way to change anything through v1.',
            // Not 'auth': that key is on the export's forbidden list (it is
            // a push subscription's), and the guard is right to stop it.
            'authentication' => 'Authorization: Bearer mt_live_…, from an API key made at /admin/api-keys.',
            'rateLimit' => ['perMinute' => Keys::PER_MINUTE, 'per' => 'key'],
            'paging' => [
                'style' => 'cursor',
                'parameters' => ['limit' => 'up to ' . Keys::MAX_PAGE . ', ' . Keys::DEFAULT_PAGE . ' by default', 'cursor' => 'the nextCursor from the page before'],
                'about' => 'nextCursor is absent on the last page rather than null.',
            ],
            'scopes' => $scopes,
            'endpoints' => [
                ['path' => '/api/v1/me', 'scope' => null, 'about' => 'What this key is and what it may read.'],
                ['path' => '/api/v1/categories', 'scope' => 'content:read', 'filters' => ['updatedSince']],
                ['path' => '/api/v1/series', 'scope' => 'content:read', 'filters' => ['updatedSince', 'categoryId']],
                ['path' => '/api/v1/videos', 'scope' => 'content:read', 'filters' => ['updatedSince', 'seriesId', 'categoryId', 'speakerId']],
                // A file is replaced rather than edited and has no updatedAt,
                // so calling this updatedSince would let a sync job believe it
                // had seen every change.
                ['path' => '/api/v1/files', 'scope' => 'content:read', 'filters' => ['addedSince', 'seriesId']],
                ['path' => '/api/v1/events', 'scope' => 'events:read', 'filters' => ['updatedSince', 'from', 'to']],
                ['path' => '/api/v1/events/{id}/registrations', 'scope' => 'events:registrations', 'about' => 'Personal data.'],
                ['path' => '/api/v1/schedules', 'scope' => 'schedules:read'],
                ['path' => '/api/v1/calendar-events', 'scope' => 'schedules:read', 'filters' => ['updatedSince', 'from', 'to', 'scheduleId']],
                ['path' => '/api/v1/groups', 'scope' => 'groups:read', 'about' => 'Never an address, and never who is in one.'],
                ['path' => '/api/v1/analytics', 'scope' => 'analytics:read'],
            ],
            'documentation' => Url::absolute('/api/v1'),
        ]);
    }

    // -- The endpoints -------------------------------------------------------

    private static function me(App $app, Request $req, array $key): Response
    {
        return V1::ok([
            'name' => (string) $key['name'],
            'prefix' => (string) $key['prefix'],
            'scopes' => self::scopesOf($key),
            'createdAt' => Json::instant((string) $key['created_at']),
            'expiresAt' => Json::instant($key['expires_at']),
            'rateLimit' => ['perMinute' => Keys::PER_MINUTE],
            'readOnly' => true,
        ]);
    }

    /**
     * One page of rows, with the cursor rule every endpoint here shares:
     * ordered by (updated_at, id) so a row that changes while somebody is
     * reading moves to the end rather than being skipped.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $present
     */
    private static function listing(App $app, Request $req, string $table, string $sort, callable $present, string $where = '1 = 1', array $params = [], string $alias = 't'): Response
    {
        $paging = V1::paging($req);
        $sql = "SELECT $alias.* FROM {{{$table}}} $alias WHERE $where";
        $after = V1::decodeCursor($paging['cursor']);
        if ($after !== null) {
            // Strictly after the cursor row, so a page never repeats the
            // last row of the page before it.
            $sql .= " AND ($alias.$sort > ? OR ($alias.$sort = ? AND $alias.id > ?))";
            array_push($params, $after[0], $after[0], $after[1]);
        }
        $sql .= " ORDER BY $alias.$sort, $alias.id LIMIT " . $paging['fetch'];
        $rows = $app->db()->all($sql, $params);
        $page = V1::page($rows, $paging['limit'], fn (array $row) => V1::encodeCursor((string) $row[$sort], (string) $row['id']));
        return V1::ok(array_map($present, $page['rows']), $page['nextCursor']);
    }

    private static function categories(App $app, Request $req, array $key): Response
    {
        $where = '1 = 1';
        $params = [];
        if (($since = V1::since($req)) !== null) {
            $where .= ' AND t.updated_at >= ?';
            $params[] = $since;
        }
        return self::listing($app, $req, 'categories', 'updated_at', fn (array $c) => [
            'id' => (string) $c['id'],
            'name' => (string) $c['name'],
            'slug' => (string) $c['slug'],
            'parentId' => $c['parent_id'],
            'memberOnly' => (bool) $c['member_only'],
            'published' => (bool) $c['published'],
            'updatedAt' => Json::instant((string) $c['updated_at']),
        ], $where, $params);
    }

    private static function series(App $app, Request $req, array $key): Response
    {
        [$where, $params] = self::filters($req, ['categoryId' => 't.category_id'], 't.updated_at');
        return self::listing($app, $req, 'series', 'updated_at', fn (array $s) => [
            'id' => (string) $s['id'],
            'title' => (string) $s['title'],
            'slug' => (string) $s['slug'],
            'categoryId' => $s['category_id'],
            'description' => $s['description'],
            'memberOnly' => (bool) $s['member_only'],
            'published' => (bool) $s['published'],
            'hymnPerFile' => (bool) $s['hymn_per_file'],
            'updatedAt' => Json::instant((string) $s['updated_at']),
        ], $where, $params);
    }

    private static function videos(App $app, Request $req, array $key): Response
    {
        [$where, $params] = self::filters($req, [
            'seriesId' => 't.series_id',
            'categoryId' => 't.category_id',
            'speakerId' => 't.speaker_id',
        ], 't.updated_at');
        $where .= ' AND t.deleted_at IS NULL';
        return self::listing($app, $req, 'videos', 'updated_at', fn (array $v) => [
            'id' => (string) $v['id'],
            'title' => (string) $v['title'],
            'slug' => (string) $v['slug'],
            'description' => $v['description'],
            'seriesId' => $v['series_id'],
            'categoryId' => $v['category_id'],
            'speakerId' => $v['speaker_id'],
            'provider' => (string) $v['provider'],
            'externalId' => $v['external_id'],
            'durationSeconds' => $v['duration_seconds'] === null ? null : (int) $v['duration_seconds'],
            'language' => $v['language'],
            // With flags, so a reporting job can tell a draft from a
            // published one rather than being handed half a catalogue.
            'published' => (bool) $v['published'],
            'hidden' => (bool) $v['hidden'],
            'memberOnly' => (bool) $v['member_only'],
            'publishAt' => Json::instant($v['publish_at']),
            'viewCount' => (int) $v['view_count'],
            'updatedAt' => Json::instant((string) $v['updated_at']),
        ], $where, $params);
    }

    private static function files(App $app, Request $req, array $key): Response
    {
        // addedSince, not updatedSince: a file has no updatedAt because it is
        // replaced rather than edited, and calling it the same thing would
        // let a sync job believe it had seen every change.
        $where = '1 = 1 AND t.deleted_at IS NULL';
        $params = [];
        if (($since = V1::since($req, 'addedSince')) !== null) {
            $where .= ' AND t.created_at >= ?';
            $params[] = $since;
        }
        if (($seriesId = self::idParam($req, 'seriesId')) !== null) {
            $where .= ' AND t.series_id = ?';
            $params[] = $seriesId;
        }
        return self::listing($app, $req, 'file_assets', 'created_at', fn (array $f) => [
            'id' => (string) $f['id'],
            'title' => (string) $f['title'],
            'seriesId' => $f['series_id'],
            'categoryId' => $f['category_id'],
            'mimeType' => $f['mime_type'],
            'sizeBytes' => $f['size_bytes'] === null ? null : (int) $f['size_bytes'],
            'published' => (bool) $f['published'],
            'memberOnly' => (bool) $f['member_only'],
            'hymnNumber' => $f['page_number'] === null ? null : (int) $f['page_number'],
            'url' => Url::absolute('/api/files/' . $f['id'] . '/content'),
            'addedAt' => Json::instant((string) $f['created_at']),
        ], $where, $params);
    }

    private static function events(App $app, Request $req, array $key): Response
    {
        [$where, $params] = self::filters($req, [], 't.updated_at');
        foreach (['from' => '>=', 'to' => '<='] as $name => $test) {
            $given = trim((string) ($req->query($name) ?? ''));
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $given) === 1) {
                $where .= " AND t.starts_at $test ?";
                $params[] = $given . (strlen($given) === 10 ? ($name === 'from' ? ' 00:00:00' : ' 23:59:59') : '');
            }
        }
        $db = $app->db();
        return self::listing($app, $req, 'events', 'updated_at', fn (array $e) => [
            'id' => (string) $e['id'],
            'title' => (string) $e['title'],
            'slug' => (string) $e['slug'],
            'description' => $e['description'],
            'location' => $e['location'],
            'startsAt' => Json::instant((string) $e['starts_at']),
            'endsAt' => Json::instant($e['ends_at']),
            'allDay' => (bool) $e['all_day'],
            'published' => (bool) $e['published'],
            'memberOnly' => (bool) $e['member_only'],
            'registration' => (bool) $e['registration'],
            'capacity' => $e['capacity'] === null ? null : (int) $e['capacity'],
            // "Forty people are coming" — which is events:read. Who they are
            // is a scope of its own.
            'going' => (int) $db->value('SELECT COALESCE(SUM(1 + guests), 0) FROM {{event_registrations}} WHERE event_id = ? AND status = ?', [$e['id'], 'GOING']),
            'updatedAt' => Json::instant((string) $e['updated_at']),
        ], $where, $params);
    }

    /** The names and numbers people typed into a sign-up form. */
    private static function registrations(App $app, Request $req, string $eventId): Response
    {
        $db = $app->db();
        if (!Id::isValid($eventId) || $db->one('SELECT id FROM {{events}} WHERE id = ?', [$eventId]) === null) {
            return V1::fail('not_found', 'No such event.', 404);
        }
        $paging = V1::paging($req);
        $params = [$eventId];
        $sql = 'SELECT t.* FROM {{event_registrations}} t WHERE t.event_id = ?';
        $after = V1::decodeCursor($paging['cursor']);
        if ($after !== null) {
            $sql .= ' AND t.id > ?';
            $params[] = $after[1];
        }
        $rows = $db->all($sql . ' ORDER BY t.id LIMIT ' . $paging['fetch'], $params);
        $page = V1::page($rows, $paging['limit'], fn (array $row) => V1::encodeCursor('', (string) $row['id']));
        return V1::ok(array_map(fn (array $r) => [
            'id' => (string) $r['id'],
            'eventId' => (string) $r['event_id'],
            'userId' => $r['user_id'],
            'name' => (string) $r['name'],
            'email' => (string) $r['email'],
            'phone' => $r['phone'],
            'guests' => (int) $r['guests'],
            'status' => (string) $r['status'],
            'note' => $r['note'],
            'createdAt' => Json::instant((string) $r['created_at']),
        ], $page['rows']), $page['nextCursor']);
    }

    private static function schedules(App $app, Request $req, array $key): Response
    {
        return self::listing($app, $req, 'schedules', 'updated_at', fn (array $s) => [
            'id' => (string) $s['id'],
            'name' => (string) $s['name'],
            'slug' => (string) $s['slug'],
            'description' => $s['description'],
            'enabled' => (bool) $s['enabled'],
            'displayOrder' => (int) $s['display_order'],
            'sourceType' => (string) $s['source_type'],
            'updatedAt' => Json::instant((string) $s['updated_at']),
        ], 't.deleted_at IS NULL');
    }

    private static function calendarEvents(App $app, Request $req, array $key): Response
    {
        [$where, $params] = self::filters($req, ['scheduleId' => 't.schedule_id'], 't.updated_at');
        $where .= ' AND t.deleted_at IS NULL';
        foreach (['from' => '>=', 'to' => '<='] as $name => $test) {
            $given = trim((string) ($req->query($name) ?? ''));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $given) === 1) {
                $where .= " AND t.date $test ?";
                $params[] = $given;
            }
        }
        $db = $app->db();
        return self::listing($app, $req, 'calendar_events', 'updated_at', fn (array $e) => [
            'id' => (string) $e['id'],
            'scheduleId' => (string) $e['schedule_id'],
            'date' => (string) $e['date'],
            'endDate' => $e['end_date'],
            'startTime' => $e['start_time'],
            'title' => $e['title'],
            'notes' => $e['notes'],
            'location' => $e['location'],
            'status' => (string) $e['status'],
            // The names on a rota: what schedules:read is for, and why it is
            // marked as carrying personal data on the form.
            'people' => array_map(fn (array $p) => ['personId' => (string) $p['person_id'], 'displayName' => (string) $p['display_name'], 'role' => $p['role']], $db->all(
                'SELECT ep.person_id, ep.role, p.display_name FROM {{calendar_event_people}} ep
                 JOIN {{people}} p ON p.id = ep.person_id WHERE ep.event_id = ? ORDER BY ep.position',
                [$e['id']],
            )),
            'updatedAt' => Json::instant((string) $e['updated_at']),
        ], $where, $params);
    }

    /**
     * The groups, and when they meet.
     *
     * Never an address and never who is in one: an address travels only with
     * a leader's yes, and a machine cannot be given one. There is no
     * combination of scopes that returns either.
     */
    private static function groups(App $app, Request $req, array $key): Response
    {
        $db = $app->db();
        return self::listing($app, $req, 'small_groups', 'updated_at', fn (array $g) => [
            'id' => (string) $g['id'],
            'name' => (string) $g['name'],
            'slug' => (string) $g['slug'],
            'description' => $g['description'],
            'meetsWhen' => $g['meets_when'],
            'area' => $g['area'],
            'published' => (bool) $g['published'],
            'openToJoin' => (bool) $g['open_to_join'],
            'waitlist' => (bool) $g['waitlist'],
            'capacity' => $g['capacity'] === null ? null : (int) $g['capacity'],
            'memberCount' => (int) $db->value('SELECT COUNT(*) FROM {{small_group_members}} WHERE group_id = ? AND status = ?', [$g['id'], 'ACTIVE']),
            'updatedAt' => Json::instant((string) $g['updated_at']),
        ]);
    }

    private static function analytics(App $app, Request $req, array $key): Response
    {
        $db = $app->db();
        $count = fn (string $sql, array $params = []) => (int) $db->value($sql, $params);
        return V1::ok([
            'videos' => [
                'total' => $count('SELECT COUNT(*) FROM {{videos}} WHERE deleted_at IS NULL'),
                'published' => $count('SELECT COUNT(*) FROM {{videos}} WHERE deleted_at IS NULL AND published = 1'),
                'memberOnly' => $count('SELECT COUNT(*) FROM {{videos}} WHERE deleted_at IS NULL AND member_only = 1'),
                'views' => $count('SELECT COALESCE(SUM(view_count), 0) FROM {{videos}} WHERE deleted_at IS NULL'),
            ],
            'series' => ['total' => $count('SELECT COUNT(*) FROM {{series}} WHERE deleted_at IS NULL')],
            'files' => ['total' => $count('SELECT COUNT(*) FROM {{file_assets}} WHERE deleted_at IS NULL')],
            'members' => [
                'total' => $count('SELECT COUNT(*) FROM {{users}}'),
                'authorized' => $count('SELECT COUNT(*) FROM {{users}} WHERE authorized = 1'),
            ],
            'generatedAt' => Json::instant(Db::now()),
        ]);
    }

    /**
     * The filters every listing shares: a timestamp, and any id columns.
     *
     * @param array<string, string> $ids query name => column
     * @return array{0: string, 1: list<mixed>}
     */
    private static function filters(Request $req, array $ids, string $updatedColumn): array
    {
        $where = '1 = 1';
        $params = [];
        if (($since = V1::since($req)) !== null) {
            $where .= " AND $updatedColumn >= ?";
            $params[] = $since;
        }
        foreach ($ids as $name => $column) {
            $value = self::idParam($req, $name);
            if ($value !== null) {
                $where .= " AND $column = ?";
                $params[] = $value;
            }
        }
        return [$where, $params];
    }

    private static function idParam(Request $req, string $name): ?string
    {
        $given = trim((string) ($req->query($name) ?? ''));
        return $given !== '' && Id::isValid($given) ? $given : null;
    }
}
