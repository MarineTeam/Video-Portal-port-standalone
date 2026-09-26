<?php
/**
 * Plugin Name: Service plans
 * Slug:        service-plans
 * Version:     1.0.0
 * Description: Lets staff publish the running order of hymns for a service, which members open as one list at /services.
 * Author:      Marine Team
 * Requires PHP: 8.2
 * Requires App: 3.0
 */

declare(strict_types=1);

require_once __DIR__ . '/src/Services.php';
require_once __DIR__ . '/src/Rota.php';

use App\Core\ApiError;
use App\Core\App;
use App\Core\Db;
use App\Core\Hooks;
use App\Core\Id;
use App\Core\Json;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Url;
use App\Core\Validator;
use App\Modules\Audit\Audit;
use App\Modules\Library\ContentAccess;
use App\Modules\Plugins\BasePlugin;
use App\Modules\Profile\Inbox;
use App\Support\Ics;
use MarineTeam\Plugins\ServicePlans\Rota;
use MarineTeam\Plugins\ServicePlans\Services;

/**
 * A service's running order, and the rota beside it.
 *
 * Each row of a plan points at a file: a hymn that is its own file, or a
 * whole book with a number written beside it. Where the row opens, whether
 * it can go on a screen and what its number means are all decided in
 * Services; the rota's asks, answers, cover requests and blockouts are in
 * Rota. A plan outlives the library it points at, so every row is checked
 * against the file as it stands now rather than as it stood when somebody
 * typed the order.
 */
return new class (__DIR__) extends BasePlugin {
    public function boot(Hooks $hooks, App $app): void
    {
        $this->useLang($hooks);
        $this->useTemplates($hooks);
        $hooks->on('routes.register', function (Router $r) use ($app): void {
            $member = Middleware::member($app);
            $manage = Middleware::can($app, 'manage_files', anywhere: true);

            $r->get('/services', fn () => $this->listPage($app));
            $r->get('/services/[id]', fn (Request $req, array $p) => $this->planPage($app, (string) $p['id']));
            $r->get('/profile/rota', fn () => $this->rotaPage($app), [$member]);
            $r->post('/api/rota', fn (Request $req) => $this->rotaAction($app, $req), [$member]);
            $r->add('DELETE', '/api/rota', fn (Request $req) => $this->removeBlockout($app, $req), [$member]);

            $r->get('/admin/services', fn () => $app->page('service-plans/admin', ['title' => 'Service plans', 'rows' => $this->adminPlans($app)], 200, 'layouts/admin'), [$manage]);
            $r->get('/admin/services/report', fn (Request $req) => $this->reportPage($app, $req), [$manage]);
            $r->get('/admin/services/[id]', fn (Request $req, array $p) => $this->adminPlanPage($app, (string) $p['id']), [$manage]);
            $r->get('/api/admin/services', fn () => Response::json($this->adminPlans($app)), [$manage]);
            $r->post('/api/admin/services', fn (Request $req) => $this->savePlan($app, $req, null), [$manage]);
            $r->get('/api/admin/services/report', fn (Request $req) => $this->report($app, $req), [$manage]);
            $r->add('PATCH', '/api/admin/services/[id]', fn (Request $req, array $p) => $this->savePlan($app, $req, (string) $p['id']), [$manage]);
            $r->add('DELETE', '/api/admin/services/[id]', fn (Request $req, array $p) => $this->deletePlan($app, (string) $p['id']), [$manage]);
            $r->get('/api/admin/services/[id]/rota', fn (Request $req, array $p) => Response::json($this->rotaFor($app, (string) $p['id'])), [$manage]);

            $r->get('/admin/teams', fn () => $app->page('service-plans/admin-teams', ['title' => 'Teams', 'teams' => $this->teams($app)], 200, 'layouts/admin'), [$manage]);
            $r->get('/api/admin/teams', fn () => Response::json($this->teams($app)), [$manage]);
            $r->post('/api/admin/teams', fn (Request $req) => $this->saveTeam($app, $req, null), [$manage]);
            $r->add('PATCH', '/api/admin/teams/[id]', fn (Request $req, array $p) => $this->saveTeam($app, $req, (string) $p['id']), [$manage]);
            $r->add('DELETE', '/api/admin/teams/[id]', fn (Request $req, array $p) => $this->deleteTeam($app, (string) $p['id']), [$manage]);
        });
        $hooks->filter('nav.sections', fn (array $nav) => [...$nav, ['href' => '/services', 'label' => t('services.title'), 'icon' => 'book']]);
        $hooks->filter('profile.sections', fn (array $sections, ?array $user) => $user === null ? $sections : [...$sections, ['href' => '/profile/rota', 'label' => t('services.myRota')]]);
        $hooks->filter('sitemap.urls', fn (array $urls) => [...$urls, '/services']);
        // What somebody is serving at, in their own calendar.
        $hooks->filter('calendar.entries', fn (array $entries, App $app, array $user) => [...$entries, ...$this->diaryEntries($app, (string) $user['id'])]);
        $hooks->filter('profile.overview', fn (array $cards, array $user) => [...$cards, [
            'title' => t('services.myRota'),
            'count' => (int) $app->db()->value(
                'SELECT COUNT(*) FROM {{service_assignments}} a JOIN {{service_plans}} p ON p.id = a.plan_id
                 WHERE a.user_id = ? AND a.status <> ? AND (p.service_date IS NULL OR p.service_date >= ?)',
                [$user['id'], Rota::DECLINED, Db::now()],
            ),
            'href' => '/profile/rota',
        ]]);
    }

    private function canManage(App $app): bool
    {
        return $app->currentUser()->canAnywhere('manage_files');
    }

    // -- Reading a plan ---------------------------------------------------

    /** @return array<string, mixed> */
    private function findPlan(Db $db, string $id): array
    {
        $row = Id::isValid($id) ? $db->one('SELECT * FROM {{service_plans}} WHERE id = ?', [$id]) : null;
        return $row ?? throw ApiError::notFound();
    }

    /**
     * A plan's rows, each resolved against the file as it stands now — a
     * plan outlives the library it points at.
     *
     * @param array<string, mixed> $plan
     * @return list<array<string, mixed>>
     */
    private function items(App $app, array $plan): array
    {
        $db = $app->db();
        $access = ContentAccess::for($app);
        $rows = $db->all(
            'SELECT i.*, f.title AS file_title, f.mime_type, f.page_number, f.lyrics_text, f.published, f.hidden, f.deleted_at,
                    f.member_only, f.series_id, f.category_id, f.ccli_number, f.song_author, f.song_copyright
             FROM {{service_plan_items}} i JOIN {{file_assets}} f ON f.id = i.file_id
             WHERE i.plan_id = ? ORDER BY i.position, i.created_at',
            [$plan['id']],
        );
        $out = [];
        foreach ($rows as $row) {
            $file = ['id' => (string) $row['file_id']] + $row;
            $series = $row['series_id'] !== null ? $db->one('SELECT * FROM {{series}} WHERE id = ?', [$row['series_id']]) : null;
            $readable = Services::planItemReadable($file, $access->file($file, $series) === ContentAccess::OK);
            $number = Services::planItemNumber($row, $file);
            $detail = null;
            if (!Services::isHymnFile($series) && ($row['hymn_number'] ?? null) !== null) {
                $detail = $db->one('SELECT * FROM {{book_hymn_details}} WHERE file_id = ? AND number = ?', [$row['file_id'], $row['hymn_number']]);
            }
            $presentable = $readable && Services::planItemPresentable($row, $file, $series, $detail);
            $out[] = [
                'id' => (string) $row['id'],
                'fileId' => (string) $row['file_id'],
                'title' => Services::isHymnFile($series)
                    ? (string) $row['file_title']
                    : $this->songTitle($db, (string) $row['file_id'], ($row['hymn_number'] ?? null) === null ? null : (int) $row['hymn_number'], (string) $row['file_title']),
                'note' => $row['note'],
                'number' => $number,
                'readable' => $readable,
                'href' => $readable ? Services::planItemHref($row, $file, $series) : null,
                'presentHref' => $presentable ? Services::presentHref($row, $file, $series, (string) $plan['id']) : null,
                'credits' => $this->credits($file, $detail),
            ];
        }
        return $out;
    }

    /**
     * What to call the row: the hymn inside the book where the contents
     * knows its name, else the file itself.
     */
    private function songTitle(Db $db, string $fileId, ?int $number, string $fallback): string
    {
        if ($number === null) {
            return $fallback;
        }
        $title = $db->value('SELECT title FROM {{book_hymns}} WHERE file_id = ? AND number = ? ORDER BY position LIMIT 1', [$fileId, $number]);
        return $title === null ? $fallback : (string) $title;
    }

    /**
     * @param array<string, mixed> $file
     * @param array<string, mixed>|null $detail
     * @return array{ccli: ?string, author: ?string, copyright: ?string}
     */
    private function credits(array $file, ?array $detail): array
    {
        return [
            'ccli' => $detail['ccli_number'] ?? $file['ccli_number'] ?? null,
            'author' => $detail['author'] ?? $file['song_author'] ?? null,
            'copyright' => $detail['copyright'] ?? $file['song_copyright'] ?? null,
        ];
    }

    private function listPage(App $app): Response
    {
        $db = $app->db();
        $where = $this->canManage($app) ? '1 = 1' : 'published = 1';
        $rows = $db->all("SELECT * FROM {{service_plans}} WHERE $where ORDER BY service_date IS NULL, service_date DESC, created_at DESC LIMIT 100");
        return $app->page('service-plans/list', [
            'title' => t('services.title'),
            'plans' => array_map(fn (array $p) => [
                'id' => (string) $p['id'],
                'title' => (string) $p['title'],
                'date' => $p['service_date'] === null ? null : substr((string) $p['service_date'], 0, 10),
                'published' => (bool) $p['published'],
                'items' => (int) $db->value('SELECT COUNT(*) FROM {{service_plan_items}} WHERE plan_id = ?', [$p['id']]),
            ], $rows),
        ]);
    }

    private function planPage(App $app, string $id): Response
    {
        $db = $app->db();
        $plan = $this->findPlan($db, $id);
        if (!$plan['published'] && !$this->canManage($app)) {
            throw ApiError::notFound();
        }
        return $app->page('service-plans/plan', [
            'title' => (string) $plan['title'],
            'plan' => [
                'id' => (string) $plan['id'],
                'title' => (string) $plan['title'],
                'date' => $plan['service_date'] === null ? null : substr((string) $plan['service_date'], 0, 10),
                'notes' => $plan['notes'],
                'published' => (bool) $plan['published'],
            ],
            'items' => $this->items($app, $plan),
            'rota' => $this->rotaFor($app, (string) $plan['id'], namesOnly: true),
        ]);
    }

    // -- The rota ---------------------------------------------------------

    /**
     * Who is on for a plan. Names are for members: a signed-out reader is
     * given the shape of the rota — which jobs, which teams — and nobody's
     * name on it.
     *
     * @return list<array<string, mixed>>
     */
    private function rotaFor(App $app, string $planId, bool $namesOnly = false): array
    {
        $db = $app->db();
        $signedIn = $app->currentUser()->isSignedIn();
        $rows = $db->all(
            'SELECT a.*, t.name AS team_name, t.position AS team_position, u.name AS user_name, u.display_name,
                    c.name AS covered_for_name, c.display_name AS covered_for_display
             FROM {{service_assignments}} a JOIN {{service_teams}} t ON t.id = a.team_id JOIN {{users}} u ON u.id = a.user_id
             LEFT JOIN {{users}} c ON c.id = a.covered_for_id
             WHERE a.plan_id = ? ORDER BY t.position, t.name, a.position, a.created_at',
            [$planId],
        );
        $out = [];
        foreach ($rows as $row) {
            $shape = [
                'id' => (string) $row['id'],
                'team' => (string) $row['team_name'],
                'role' => Rota::assignmentRole($row, ['name' => $row['team_name']]),
                'status' => (string) $row['status'],
                'coverWanted' => (bool) $row['cover_wanted'],
                'coverNote' => $namesOnly ? null : $row['cover_note'],
            ];
            if ($signedIn) {
                // Rota names are for members; the structure is for anybody.
                $shape['name'] = Rota::personName(['display_name' => $row['display_name'], 'name' => $row['user_name']]);
                $covered = Rota::personName(['display_name' => $row['covered_for_display'], 'name' => $row['covered_for_name']]);
                $shape['coveredFor'] = $covered === '' ? null : $covered;
            }
            if (!$namesOnly) {
                $shape['userId'] = (string) $row['user_id'];
                $shape['teamId'] = (string) $row['team_id'];
                $shape['note'] = $row['note'];
            }
            $out[] = $shape;
        }
        return $out;
    }

    private function rotaPage(App $app): Response
    {
        $db = $app->db();
        $userId = (string) $app->currentUser()->id();
        $mine = $db->all(
            'SELECT a.*, p.title AS plan_title, p.service_date, t.name AS team_name FROM {{service_assignments}} a
             JOIN {{service_plans}} p ON p.id = a.plan_id JOIN {{service_teams}} t ON t.id = a.team_id
             WHERE a.user_id = ? ORDER BY p.service_date IS NULL, p.service_date DESC LIMIT 200',
            [$userId],
        );
        $blockouts = $db->all('SELECT * FROM {{service_blockouts}} WHERE user_id = ? ORDER BY start_date', [$userId]);
        // Somebody else's slot that is going begging, on a team this member is on.
        $wanted = $db->all(
            'SELECT a.*, p.title AS plan_title, p.service_date, t.name AS team_name FROM {{service_assignments}} a
             JOIN {{service_plans}} p ON p.id = a.plan_id JOIN {{service_teams}} t ON t.id = a.team_id
             WHERE a.cover_wanted = 1 AND a.user_id <> ? AND t.id IN (SELECT team_id FROM {{service_team_members}} WHERE user_id = ?)
             ORDER BY p.service_date',
            [$userId, $userId],
        );
        $shape = fn (array $a) => [
            'id' => (string) $a['id'],
            'plan' => (string) $a['plan_title'],
            'planId' => (string) $a['plan_id'],
            'date' => $a['service_date'] === null ? null : substr((string) $a['service_date'], 0, 10),
            'role' => Rota::assignmentRole($a, ['name' => $a['team_name']]),
            'status' => (string) $a['status'],
            'coverWanted' => (bool) $a['cover_wanted'],
            'coverNote' => $a['cover_note'],
            'away' => Rota::isBlockedOut($blockouts, $a['service_date'] === null ? null : (string) $a['service_date']),
        ];
        return $app->page('service-plans/rota', [
            'title' => t('services.myRota'),
            'sections' => (new \App\Modules\Profile\Routes($app))->sections(),
            'assignments' => array_map($shape, $mine),
            'cover' => array_map($shape, $wanted),
            'blockouts' => array_map(fn (array $b) => [
                'id' => (string) $b['id'],
                'from' => substr((string) $b['start_date'], 0, 10),
                'to' => substr((string) $b['end_date'], 0, 10),
                'reason' => $b['reason'],
            ], $blockouts),
        ]);
    }

    /**
     * A member's own rota actions: answering an ask, asking for cover,
     * taking somebody else's slot, and saying when they are away.
     */
    private function rotaAction(App $app, Request $req): Response
    {
        $db = $app->db();
        $userId = (string) $app->currentUser()->id();
        $input = $req->input();
        if (isset($input['startDate']) || isset($input['endDate'])) {
            $data = Validator::check($input, [
                'startDate' => ['date', 'required'],
                'endDate' => ['date', 'required'],
                'reason' => ['text', 'nullable', 'max' => 500],
            ]);
            if ($data['endDate'] < $data['startDate']) {
                throw ApiError::invalid(t('services.backwards'));
            }
            $id = $db->insert('service_blockouts', [
                'id' => Id::new(), 'user_id' => $userId,
                'start_date' => $data['startDate'] . ' 00:00:00', 'end_date' => $data['endDate'] . ' 00:00:00',
                'reason' => $data['reason'] ?? null,
            ]);
            return Response::json(['id' => $id], 201);
        }
        $data = Validator::check($input, [
            'assignmentId' => ['id', 'required'],
            'status' => ['enum', 'nullable', 'enum' => Rota::STATUSES],
            'note' => ['text', 'nullable', 'max' => 1000],
            'coverWanted' => ['bool'],
            'takeCover' => ['bool'],
        ]);
        $assignment = $db->one('SELECT * FROM {{service_assignments}} WHERE id = ?', [$data['assignmentId']]);
        if ($assignment === null) {
            throw ApiError::notFound();
        }
        if (($data['takeCover'] ?? false) === true) {
            return $this->takeCover($app, $assignment, $userId);
        }
        if ((string) $assignment['user_id'] !== $userId) {
            throw ApiError::forbidden();
        }
        $set = [];
        if (isset($data['status'])) {
            $set['status'] = (string) $data['status'];
            $set['responded_at'] = Db::now();
            $set['note'] = $data['note'] ?? null;
        }
        if (array_key_exists('coverWanted', $data)) {
            // Asking for cover is a flag on the slot: what is being asked
            // for is this slot, and covering it means it changes hands.
            $set['cover_wanted'] = (int) (bool) $data['coverWanted'];
            $set['cover_note'] = $data['coverWanted'] ? ($data['note'] ?? null) : null;
            $set['cover_asked_at'] = $data['coverWanted'] ? Db::now() : null;
        }
        if ($set === []) {
            throw ApiError::invalid(t('services.nothingToDo'));
        }
        $db->update('service_assignments', $set, ['id' => $assignment['id']]);
        return Response::json(['ok' => true] + $set);
    }

    /**
     * Somebody takes a slot that is going begging. The slot changes hands
     * and remembers who had it, so the rota still shows what happened
     * rather than quietly reading as though they were never on.
     *
     * @param array<string, mixed> $assignment
     */
    private function takeCover(App $app, array $assignment, string $userId): Response
    {
        $db = $app->db();
        if (!(bool) $assignment['cover_wanted']) {
            throw ApiError::invalid(t('services.notGoingBegging'));
        }
        if ((string) $assignment['user_id'] === $userId) {
            throw ApiError::invalid(t('services.alreadyYours'));
        }
        if ($db->value('SELECT 1 FROM {{service_team_members}} WHERE team_id = ? AND user_id = ?', [$assignment['team_id'], $userId]) === null) {
            throw ApiError::forbidden();
        }
        $db->update('service_assignments', [
            'user_id' => $userId,
            'status' => Rota::ACCEPTED,
            'responded_at' => Db::now(),
            'cover_wanted' => 0,
            'cover_note' => null,
            'covered_for_id' => $assignment['user_id'],
            'covered_at' => Db::now(),
        ], ['id' => $assignment['id']]);
        $plan = $db->one('SELECT * FROM {{service_plans}} WHERE id = ?', [$assignment['plan_id']]);
        Inbox::add($db, (string) $assignment['user_id'], t('services.coveredTitle'), (string) ($plan['title'] ?? ''), Url::absolute('/profile/rota'));
        return Response::json(['ok' => true, 'covered' => true]);
    }

    private function removeBlockout(App $app, Request $req): Response
    {
        $data = Validator::check($req->input(), ['id' => ['id', 'required']]);
        $n = $app->db()->run('DELETE FROM {{service_blockouts}} WHERE id = ? AND user_id = ?', [$data['id'], $app->currentUser()->id()])->rowCount();
        if ($n === 0) {
            throw ApiError::notFound();
        }
        return Response::json(['ok' => true]);
    }

    /** @return list<array<string, mixed>> what this member is serving at, for their diary feed */
    private function diaryEntries(App $app, string $userId): array
    {
        $rows = $app->db()->all(
            'SELECT a.*, p.title AS plan_title, p.service_date, t.name AS team_name FROM {{service_assignments}} a
             JOIN {{service_plans}} p ON p.id = a.plan_id JOIN {{service_teams}} t ON t.id = a.team_id
             WHERE a.user_id = ? AND p.service_date IS NOT NULL AND p.service_date >= ? ORDER BY p.service_date LIMIT 200',
            [$userId, Db::datetime((new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-3 months'))],
        );
        return array_map(fn (array $a) => [
            'uid' => 'rota-' . $a['id'] . '@' . (parse_url(Url::absolute('/'), PHP_URL_HOST) ?: 'church'),
            'summary' => Rota::assignmentRole($a, ['name' => $a['team_name']]) . ' — ' . $a['plan_title'],
            'starts' => new \DateTimeImmutable((string) $a['service_date'], new \DateTimeZone('UTC')),
            'ends' => null,
            'allDay' => true,
            'url' => Url::absolute('/services/' . $a['plan_id']),
            // A declined date is written as cancelled rather than left out:
            // omitting it would leave the entry sitting on the phone of the
            // one person who already said no.
            'status' => (string) $a['status'] === Rota::DECLINED ? 'CANCELLED' : null,
        ], $rows);
    }

    // -- Administration ---------------------------------------------------

    /** @return list<array<string, mixed>> */
    private function adminPlans(App $app): array
    {
        $db = $app->db();
        return array_map(fn (array $p) => Json::row('service_plans', $p) + [
            'items' => (int) $db->value('SELECT COUNT(*) FROM {{service_plan_items}} WHERE plan_id = ?', [$p['id']]),
            'onTheRota' => (int) $db->value('SELECT COUNT(*) FROM {{service_assignments}} WHERE plan_id = ?', [$p['id']]),
        ], $db->all('SELECT * FROM {{service_plans}} ORDER BY service_date IS NULL, service_date DESC, created_at DESC LIMIT 200'));
    }

    private function adminPlanPage(App $app, string $id): Response
    {
        $db = $app->db();
        $plan = $this->findPlan($db, $id);
        return $app->page('service-plans/admin-plan', [
            'title' => (string) $plan['title'],
            'plan' => Json::row('service_plans', $plan),
            'items' => $this->items($app, $plan),
            'rota' => $this->rotaFor($app, (string) $plan['id']),
            'teams' => $this->teams($app),
            'script' => $this->asset('plans.js'),
            'files' => $db->all(
                'SELECT f.id, f.title, f.page_number, s.title AS series_title, s.hymn_per_file FROM {{file_assets}} f
                 LEFT JOIN {{series}} s ON s.id = f.series_id
                 WHERE f.deleted_at IS NULL AND f.published = 1 ORDER BY s.title, f.page_number, f.title LIMIT 1000',
            ),
        ], 200, 'layouts/admin');
    }

    private function savePlan(App $app, Request $req, ?string $id): Response
    {
        $input = $req->input();
        $data = Validator::check($input, [
            'title' => ['text', 'required', 'min' => 1, 'max' => 255],
            'serviceDate' => ['date', 'nullable'],
            'notes' => ['text', 'nullable', 'max' => 20000],
            'published' => ['bool'],
            'items' => ['array', 'nullable', 'max' => 200],
            'assignments' => ['array', 'nullable', 'max' => 200],
        ], partial: $id !== null);
        $db = $app->db();
        $was = $id === null ? null : $this->findPlan($db, $id);
        $row = [];
        foreach (['title' => 'title', 'notes' => 'notes'] as $key => $column) {
            if (array_key_exists($key, $data)) {
                $row[$column] = $data[$key];
            }
        }
        if (array_key_exists('serviceDate', $data)) {
            $row['service_date'] = $data['serviceDate'] === null ? null : $data['serviceDate'] . ' 00:00:00';
        }
        if (array_key_exists('published', $data)) {
            $row['published'] = (int) (bool) $data['published'];
        }
        if ($id === null) {
            $id = $db->insert('service_plans', $row + ['id' => Id::new()]);
        } elseif ($row !== []) {
            $db->update('service_plans', $row, ['id' => $id]);
        }
        if (array_key_exists('items', $data)) {
            $this->replaceItems($db, $id, (array) ($data['items'] ?? []));
        }
        if (array_key_exists('assignments', $data)) {
            $this->replaceAssignments($app, $id, (array) ($data['assignments'] ?? []));
        }
        Audit::log($db, (string) $app->currentUser()->email(), $was === null ? 'service.create' : 'service.update', 'ServicePlan', $id);
        $plan = $this->findPlan($db, $id);
        return Response::json(Json::row('service_plans', $plan) + ['items' => $this->items($app, $plan)], $was === null ? 201 : 200);
    }

    /**
     * The running order, in the order it was sent. Rows are replaced rather
     * than patched: an order is one thing, and moving a hymn up is the same
     * act as adding one.
     *
     * @param list<mixed> $items
     */
    private function replaceItems(Db $db, string $planId, array $items): void
    {
        $db->transaction(function (Db $db) use ($planId, $items): void {
            $db->run('DELETE FROM {{service_plan_items}} WHERE plan_id = ?', [$planId]);
            $position = 0;
            foreach ($items as $item) {
                if (!is_array($item) || !is_string($item['fileId'] ?? null) || !Id::isValid($item['fileId'])) {
                    continue;
                }
                if ($db->value('SELECT 1 FROM {{file_assets}} WHERE id = ? AND deleted_at IS NULL', [$item['fileId']]) === null) {
                    continue;
                }
                $number = ($item['hymnNumber'] ?? '') === '' ? null : (int) $item['hymnNumber'];
                $db->insert('service_plan_items', [
                    'id' => Id::new(),
                    'plan_id' => $planId,
                    'file_id' => (string) $item['fileId'],
                    'hymn_number' => $number !== null && $number > 0 ? $number : null,
                    'note' => is_string($item['note'] ?? null) && trim($item['note']) !== '' ? mb_substr(trim($item['note']), 0, 500) : null,
                    'position' => $position++,
                ]);
            }
        });
    }

    /**
     * Who is asked, and for what. An ask that is already there keeps its
     * answer; one that has gone is withdrawn.
     *
     * @param list<mixed> $assignments
     */
    private function replaceAssignments(App $app, string $planId, array $assignments): void
    {
        $db = $app->db();
        $wanted = [];
        foreach ($assignments as $assignment) {
            if (!is_array($assignment) || !is_string($assignment['userId'] ?? null) || !is_string($assignment['teamId'] ?? null)) {
                continue;
            }
            $position = is_string($assignment['position'] ?? null) ? mb_substr(trim($assignment['position']), 0, 191) : '';
            $wanted[(string) $assignment['userId'] . '|' . $position] = ['user_id' => (string) $assignment['userId'], 'team_id' => (string) $assignment['teamId'], 'position' => $position];
        }
        $told = [];
        $db->transaction(function (Db $db) use ($planId, $wanted, &$told): void {
            $existing = [];
            foreach ($db->all('SELECT * FROM {{service_assignments}} WHERE plan_id = ?', [$planId]) as $row) {
                $existing[(string) $row['user_id'] . '|' . (string) $row['position']] = $row;
            }
            foreach ($existing as $key => $row) {
                if (!isset($wanted[$key])) {
                    $db->delete('service_assignments', ['id' => $row['id']]);
                }
            }
            foreach ($wanted as $key => $one) {
                if (isset($existing[$key])) {
                    // Keep the answer somebody already gave.
                    $db->update('service_assignments', ['team_id' => $one['team_id']], ['id' => $existing[$key]['id']]);
                    continue;
                }
                $db->insert('service_assignments', $one + ['id' => Id::new(), 'plan_id' => $planId, 'status' => Rota::INVITED]);
                $told[] = $one['user_id'];
            }
        });
        if ($told !== []) {
            $plan = $db->one('SELECT * FROM {{service_plans}} WHERE id = ?', [$planId]);
            foreach (array_unique($told) as $userId) {
                Inbox::add($db, $userId, t('services.askedTitle'), (string) ($plan['title'] ?? ''), Url::absolute('/profile/rota'));
            }
        }
    }

    private function deletePlan(App $app, string $id): Response
    {
        $db = $app->db();
        $plan = $this->findPlan($db, $id);
        $db->delete('service_plans', ['id' => $plan['id']]);
        Audit::log($db, (string) $app->currentUser()->email(), 'service.delete', 'ServicePlan', (string) $plan['id']);
        return Response::json(['ok' => true]);
    }

    /** @return list<array<string, mixed>> */
    private function teams(App $app): array
    {
        $db = $app->db();
        return array_map(function (array $team) use ($db) {
            $members = $db->all(
                'SELECT m.*, u.name, u.display_name, u.email FROM {{service_team_members}} m JOIN {{users}} u ON u.id = m.user_id
                 WHERE m.team_id = ? ORDER BY u.name',
                [$team['id']],
            );
            return Json::row('service_teams', $team) + [
                'members' => array_map(fn (array $m) => [
                    'id' => (string) $m['id'],
                    'userId' => (string) $m['user_id'],
                    'name' => Rota::personName($m) ?: (string) $m['email'],
                    'email' => (string) $m['email'],
                    'position' => $m['position'],
                ], $members),
            ];
        }, $db->all('SELECT * FROM {{service_teams}} ORDER BY position, name LIMIT 200'));
    }

    private function saveTeam(App $app, Request $req, ?string $id): Response
    {
        $db = $app->db();
        $data = Validator::check($req->input(), [
            'name' => ['text', 'required', 'min' => 1, 'max' => 255],
            'position' => ['int', 'min' => 0, 'max' => 1000],
            'addEmail' => ['email', 'nullable'],
            'addPosition' => ['text', 'nullable', 'max' => 255],
            'removeMemberId' => ['id', 'nullable'],
        ], partial: $id !== null);
        $row = [];
        foreach (['name' => 'name', 'position' => 'position'] as $key => $column) {
            if (array_key_exists($key, $data)) {
                $row[$column] = $data[$key];
            }
        }
        if ($id === null) {
            $id = $db->insert('service_teams', $row + ['id' => Id::new()]);
        } elseif ($row !== []) {
            $db->update('service_teams', $row, ['id' => $id]);
        }
        if (isset($data['addEmail'])) {
            $user = $db->one('SELECT id FROM {{users}} WHERE email = ?', [$data['addEmail']]);
            if ($user === null) {
                throw ApiError::invalid(t('services.noSuchMember', ['email' => (string) $data['addEmail']]));
            }
            $db->run(
                'INSERT INTO {{service_team_members}} (id, team_id, user_id, position) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE position = VALUES(position)',
                [Id::new(), $id, $user['id'], $data['addPosition'] ?? null],
            );
        }
        if (isset($data['removeMemberId'])) {
            $db->run('DELETE FROM {{service_team_members}} WHERE id = ? AND team_id = ?', [$data['removeMemberId'], $id]);
        }
        foreach ($this->teams($app) as $team) {
            if ((string) $team['id'] === $id) {
                return Response::json($team, 201);
            }
        }
        throw ApiError::notFound();
    }

    private function deleteTeam(App $app, string $id): Response
    {
        $db = $app->db();
        if (!Id::isValid($id) || $db->value('SELECT 1 FROM {{service_teams}} WHERE id = ?', [$id]) === null) {
            throw ApiError::notFound();
        }
        $db->delete('service_teams', ['id' => $id]);
        return Response::json(['ok' => true]);
    }

    // -- What we sang -----------------------------------------------------

    /**
     * Every song in a plan dated inside a window, and how many services it
     * was sung in — the shape a licence return asks for.
     *
     * @return list<array<string, mixed>>
     */
    private function sang(App $app, string $from, string $to): array
    {
        $db = $app->db();
        $rows = $db->all(
            'SELECT i.file_id, i.hymn_number, f.title AS file_title, f.ccli_number, f.song_author, f.song_copyright, f.page_number,
                    p.id AS plan_id, p.title AS plan_title, p.service_date
             FROM {{service_plan_items}} i JOIN {{service_plans}} p ON p.id = i.plan_id JOIN {{file_assets}} f ON f.id = i.file_id
             WHERE p.service_date >= ? AND p.service_date <= ? ORDER BY p.service_date',
            [$from . ' 00:00:00', $to . ' 23:59:59'],
        );
        $out = [];
        foreach ($rows as $row) {
            $key = (string) $row['file_id'] . '|' . (string) ($row['hymn_number'] ?? '');
            $detail = $row['hymn_number'] === null ? null : $db->one('SELECT * FROM {{book_hymn_details}} WHERE file_id = ? AND number = ?', [$row['file_id'], $row['hymn_number']]);
            $credits = $this->credits((array) $row, $detail);
            $out[$key] ??= [
                'title' => $this->songTitle($db, (string) $row['file_id'], $row['hymn_number'] === null ? null : (int) $row['hymn_number'], (string) $row['file_title']),
                'number' => $row['hymn_number'] === null ? ($row['page_number'] === null ? null : (int) $row['page_number']) : (int) $row['hymn_number'],
                'ccli' => $credits['ccli'],
                'author' => $credits['author'],
                'copyright' => $credits['copyright'],
                'services' => 0,
                'dates' => [],
            ];
            $out[$key]['services']++;
            $out[$key]['dates'][] = substr((string) $row['service_date'], 0, 10);
        }
        $out = array_values($out);
        usort($out, fn (array $a, array $b) => [$b['services'], $a['title']] <=> [$a['services'], $b['title']]);
        return $out;
    }

    /** @return array{from: string, to: string} */
    private function window(Request $req): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $from = (string) ($req->query('from') ?? $now->modify('-1 year')->format('Y-m-d'));
        $to = (string) ($req->query('to') ?? $now->format('Y-m-d'));
        $ok = fn (string $d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;
        return ['from' => $ok($from) ? $from : $now->modify('-1 year')->format('Y-m-d'), 'to' => $ok($to) ? $to : $now->format('Y-m-d')];
    }

    private function report(App $app, Request $req): Response
    {
        ['from' => $from, 'to' => $to] = $this->window($req);
        $rows = $this->sang($app, $from, $to);
        if (($req->query('format') ?? '') !== 'csv') {
            return Response::json(['from' => $from, 'to' => $to, 'songs' => $rows]);
        }
        $quote = fn (mixed $value) => '"' . str_replace('"', '""', (string) $value) . '"';
        $csv = "Title,Number,CCLI,Author,Copyright,Services,Dates\n";
        foreach ($rows as $row) {
            $csv .= implode(',', array_map($quote, [
                $row['title'], $row['number'] ?? '', $row['ccli'] ?? '', $row['author'] ?? '', $row['copyright'] ?? '',
                $row['services'], implode(' ', $row['dates']),
            ])) . "\n";
        }
        $response = Response::text($csv);
        $response->header('Content-Type', 'text/csv; charset=utf-8');
        $response->header('Content-Disposition', 'attachment; filename="what-we-sang-' . $from . '-to-' . $to . '.csv"');
        return $response;
    }

    private function reportPage(App $app, Request $req): Response
    {
        ['from' => $from, 'to' => $to] = $this->window($req);
        return $app->page('service-plans/report', [
            'title' => 'What we sang',
            'from' => $from,
            'to' => $to,
            'songs' => $this->sang($app, $from, $to),
        ], 200, 'layouts/admin');
    }
};
