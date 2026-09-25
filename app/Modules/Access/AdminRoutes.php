<?php

declare(strict_types=1);

namespace App\Modules\Access;

use App\Core\ApiError;
use App\Core\App;
use App\Core\Cache;
use App\Core\Db;
use App\Core\Json;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Validator;
use App\Modules\Audit\Audit;

/**
 * Who gets in and what they may do: members and roles, the allowlist, the
 * guest switch, refused attempts, permission groups and their assignments,
 * the content-editor grants, and the audit log.
 *
 * Two rules hold everywhere here: only an ADMIN makes an ADMIN (no group can
 * carry it, and manage_users can't promote), and the last way in can't be
 * removed — the last active allowlist address, the last administrator.
 */
final class AdminRoutes
{
    public const PAGE_SIZE = 50;

    public static function register(Router $r, App $app): void
    {
        $self = new self($app);
        $users = Middleware::can($app, 'manage_users');
        $perms = Middleware::can($app, 'manage_permissions');
        $audit = Middleware::can($app, 'view_audit_log');

        $r->get('/admin/users', [$self, 'usersPage'], [$users]);
        $r->get('/api/admin/users', [$self, 'usersList'], [$users]);
        $r->post('/api/admin/users', [$self, 'usersCreate'], [$users]);
        $r->add('PATCH', '/api/admin/users/[id]', [$self, 'usersUpdate'], [$users]);
        $r->add('DELETE', '/api/admin/users/[id]', [$self, 'usersDelete'], [$users]);

        $r->get('/admin/authorized-emails', [$self, 'allowlistPage'], [$users]);
        $r->get('/api/admin/authorized-emails', [$self, 'allowlistList'], [$users]);
        $r->post('/api/admin/authorized-emails', [$self, 'allowlistCreate'], [$users]);
        $r->add('PATCH', '/api/admin/authorized-emails/[id]', [$self, 'allowlistUpdate'], [$users]);
        $r->add('DELETE', '/api/admin/authorized-emails/[id]', [$self, 'allowlistDelete'], [$users]);
        $r->get('/api/admin/guest-login', [$self, 'guestGet'], [$users]);
        $r->add('PATCH', '/api/admin/guest-login', [$self, 'guestSet'], [$users]);

        $r->get('/admin/access-attempts', [$self, 'attemptsPage'], [$audit]);
        $r->get('/api/admin/access-attempts', [$self, 'attemptsList'], [$audit]);
        $r->post('/api/admin/access-attempts', [$self, 'attemptsAction'], [$audit]);

        $r->get('/admin/permissions', [$self, 'permissionsPage'], [$perms]);
        $r->get('/api/admin/permission-groups', [$self, 'groupsList'], [$perms]);
        $r->post('/api/admin/permission-groups', [$self, 'groupsCreate'], [$perms]);
        $r->add('PATCH', '/api/admin/permission-groups/[id]', [$self, 'groupsUpdate'], [$perms]);
        $r->add('DELETE', '/api/admin/permission-groups/[id]', [$self, 'groupsDelete'], [$perms]);
        $r->get('/api/admin/group-assignments', [$self, 'assignmentsList'], [$perms]);
        $r->post('/api/admin/group-assignments', [$self, 'assignmentsCreate'], [$perms]);
        $r->add('DELETE', '/api/admin/group-assignments/[id]', [$self, 'assignmentsDelete'], [$perms]);

        $r->get('/api/admin/editors', [$self, 'editorsList'], [$users]);
        $r->post('/api/admin/editors/category', fn (Request $q) => $self->editorsCreate($q, 'category'), [$users]);
        $r->post('/api/admin/editors/series', fn (Request $q) => $self->editorsCreate($q, 'series'), [$users]);
        $r->add('DELETE', '/api/admin/editors/category/[id]', fn (Request $q, array $p) => $self->editorsDelete('category', $p['id']), [$users]);
        $r->add('DELETE', '/api/admin/editors/series/[id]', fn (Request $q, array $p) => $self->editorsDelete('series', $p['id']), [$users]);

        $r->get('/admin/audit', [$self, 'auditPage'], [$audit]);
        $r->get('/api/admin/audit', [$self, 'auditList'], [$audit]);
        $r->get('/api/admin/audit/export', [$self, 'auditExport'], [$audit]);
    }

    public function __construct(private readonly App $app)
    {
    }

    private function db(): Db
    {
        return $this->app->db();
    }

    private function actor(): string
    {
        return (string) $this->app->currentUser()->email();
    }

    private function page(string $template, array $vars): Response
    {
        $response = $this->app->page($template, $vars, 200, 'layouts/admin');
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    private static function pageNumber(Request $req): int
    {
        return max(1, (int) ($req->query('page') ?? 1));
    }

    // Members & roles ---------------------------------------------------------

    /** @return array{items: list<array<string, mixed>>, total: int, page: int, pageSize: int} */
    private function users(Request $req): array
    {
        $q = trim((string) $req->query('q'));
        $where = '1=1';
        $params = [];
        if ($q !== '') {
            $where = '(u.email LIKE ? OR u.name LIKE ? OR u.display_name LIKE ?)';
            $like = '%' . Db::likeEscape(mb_substr($q, 0, 100)) . '%';
            $params = [$like, $like, $like];
        }
        $page = self::pageNumber($req);
        $total = (int) $this->db()->value("SELECT COUNT(*) FROM {{users}} u WHERE $where", $params);
        $rows = $this->db()->all(
            "SELECT u.id, u.email, u.name, u.display_name, u.role, u.authorized, u.created_at, u.updated_at,
                    (SELECT GROUP_CONCAT(DISTINCT i.provider) FROM {{user_identities}} i WHERE i.user_id = u.id) AS providers,
                    (SELECT MAX(i.last_login_at) FROM {{user_identities}} i WHERE i.user_id = u.id) AS last_login_at,
                    a.status AS allowlist_status
             FROM {{users}} u LEFT JOIN {{authorized_emails}} a ON a.email = u.email
             WHERE $where ORDER BY u.authorized ASC, u.created_at DESC LIMIT " . self::PAGE_SIZE . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE),
            $params,
        );
        $items = array_map(function (array $r): array {
            $out = Json::row('users', $r);
            $out['providers'] = $r['providers'] === null ? [] : explode(',', (string) $r['providers']);
            $out['lastLoginAt'] = Json::instant($r['last_login_at']);
            $out['allowlistStatus'] = $r['allowlist_status'];
            return $out;
        }, $rows);
        return ['items' => $items, 'total' => $total, 'page' => $page, 'pageSize' => self::PAGE_SIZE];
    }

    public function usersPage(Request $req): Response
    {
        return $this->page('admin/users', [
            'title' => 'Members & roles',
            'list' => $this->users($req),
            'q' => (string) $req->query('q'),
            'isAdmin' => $this->app->currentUser()->isAdmin(),
            'editors' => $this->editors(),
            'categories' => $this->db()->all('SELECT id, name FROM {{categories}} WHERE deleted_at IS NULL ORDER BY name'),
            'series' => $this->db()->all('SELECT id, title FROM {{series}} WHERE deleted_at IS NULL ORDER BY title'),
        ]);
    }

    public function usersList(Request $req): Response
    {
        return Response::json($this->users($req));
    }

    /** Pre-authorizes an address before the person ever signs in. */
    public function usersCreate(Request $req): Response
    {
        $data = Validator::check($req->input(), ['email' => ['email', 'required'], 'name' => ['string', 'nullable', 'max' => 200]]);
        $email = $data['email'];
        $this->db()->transaction(function (Db $db) use ($email, $data) {
            $db->run('INSERT IGNORE INTO {{users}} (id, email, name, role, authorized) VALUES (?, ?, ?, \'MEMBER\', 1)', [\App\Core\Id::new(), $email, $data['name'] ?? null]);
            $this->allowlistUpsert($email, 'ACTIVE', null);
        });
        Audit::log($this->db(), $this->actor(), 'user.preauthorize', 'User', null, $email);
        $row = $this->db()->one('SELECT * FROM {{users}} WHERE email = ?', [$email]);
        return Response::json(Json::row('users', (array) $row, ['password_hash', 'calendar_token']), 201);
    }

    public function usersUpdate(Request $req, array $p): Response
    {
        $data = Validator::check($req->input(), [
            'role' => ['enum', 'enum' => ['MEMBER', 'ADMIN']],
            'authorized' => ['bool'],
        ], partial: true);
        $user = $this->db()->one('SELECT * FROM {{users}} WHERE id = ?', [$p['id']]) ?? throw ApiError::notFound();
        if (isset($data['role']) && $data['role'] !== $user['role']) {
            // Only an administrator changes a role in either direction.
            if (!$this->app->currentUser()->isAdmin()) {
                throw ApiError::forbidden('Only an administrator can change who is an administrator.');
            }
            if ($data['role'] === 'MEMBER' && $this->adminCount() <= 1) {
                throw ApiError::conflict('This is the last administrator. Make someone else an administrator first.');
            }
            $this->db()->update('users', ['role' => $data['role']], ['id' => $user['id']]);
            // A privilege change ends the member's sessions: they sign in again on the new role.
            $this->db()->delete('sessions', ['user_id' => $user['id']]);
            Audit::log($this->db(), $this->actor(), 'user.role', 'User', (string) $user['id'], (string) $user['email'] . ' → ' . $data['role']);
        }
        if (isset($data['authorized'])) {
            if (!$data['authorized'] && $user['role'] === 'ADMIN' && $this->adminCount() <= 1) {
                throw ApiError::conflict('This is the last administrator; revoking their access would lock everyone out.');
            }
            $this->allowlistUpsert((string) $user['email'], $data['authorized'] ? 'ACTIVE' : 'SUSPENDED', null);
            $this->db()->update('users', ['authorized' => $data['authorized']], ['id' => $user['id']]);
            Audit::log($this->db(), $this->actor(), $data['authorized'] ? 'user.grant' : 'user.revoke', 'User', (string) $user['id'], (string) $user['email']);
        }
        Cache::forgetMemo();
        $row = $this->db()->one('SELECT * FROM {{users}} WHERE id = ?', [$user['id']]);
        return Response::json(Json::row('users', (array) $row, ['password_hash', 'calendar_token']));
    }

    public function usersDelete(Request $req, array $p): Response
    {
        $user = $this->db()->one('SELECT * FROM {{users}} WHERE id = ?', [$p['id']]) ?? throw ApiError::notFound();
        if ($user['role'] === 'ADMIN') {
            if (!$this->app->currentUser()->isAdmin()) {
                throw ApiError::forbidden('Only an administrator can delete an administrator.');
            }
            if ($this->adminCount() <= 1) {
                throw ApiError::conflict('This is the last administrator.');
            }
        }
        $this->db()->delete('users', ['id' => $user['id']]);
        Audit::log($this->db(), $this->actor(), 'user.delete', 'User', (string) $user['id'], (string) $user['email']);
        return Response::json(['ok' => true]);
    }

    private function adminCount(): int
    {
        return (int) $this->db()->value("SELECT COUNT(*) FROM {{users}} WHERE role = 'ADMIN'");
    }

    // Who can sign in ---------------------------------------------------------

    private function allowlistUpsert(string $email, string $status, ?bool $exempt): void
    {
        $email = Authorization::normalizeEmail($email);
        $me = $this->app->currentUser();
        $existing = $this->db()->one('SELECT id FROM {{authorized_emails}} WHERE email = ?', [$email]);
        if ($existing === null) {
            $this->db()->insert('authorized_emails', [
                'email' => $email,
                'status' => $status,
                'organization_exempt' => $exempt ?? false,
                'added_by_id' => $me->id(),
                'added_by_email' => $me->email(),
            ]);
        } else {
            $set = ['status' => $status];
            if ($exempt !== null) {
                $set['organization_exempt'] = $exempt;
            }
            $this->db()->update('authorized_emails', $set, ['id' => $existing['id']]);
        }
        Cache::forgetMemo();
    }

    /** @return array{items: list<array<string, mixed>>, total: int, page: int, pageSize: int} */
    private function allowlist(Request $req): array
    {
        $q = trim((string) $req->query('q'));
        $status = $req->query('status');
        $conditions = ['1=1'];
        $params = [];
        if ($q !== '') {
            $conditions[] = '(email LIKE ? OR note LIKE ?)';
            $like = '%' . Db::likeEscape(mb_substr($q, 0, 100)) . '%';
            array_push($params, $like, $like);
        }
        if (in_array($status, ['ACTIVE', 'SUSPENDED'], true)) {
            $conditions[] = 'status = ?';
            $params[] = $status;
        }
        $where = implode(' AND ', $conditions);
        $page = self::pageNumber($req);
        $total = (int) $this->db()->value("SELECT COUNT(*) FROM {{authorized_emails}} WHERE $where", $params);
        $rows = $this->db()->all(
            "SELECT * FROM {{authorized_emails}} WHERE $where ORDER BY created_at DESC LIMIT " . self::PAGE_SIZE . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE),
            $params,
        );
        return ['items' => Json::rows('authorized_emails', $rows), 'total' => $total, 'page' => $page, 'pageSize' => self::PAGE_SIZE];
    }

    public function allowlistPage(Request $req): Response
    {
        return $this->page('admin/authorized-emails', [
            'title' => 'Who can sign in',
            'list' => $this->allowlist($req),
            'q' => (string) $req->query('q'),
            'mode' => Access::authorization($this->app)->mode(),
            'guest' => $this->guestEnabled(),
            'primary' => $this->app->services()->activeId('auth'),
        ]);
    }

    public function allowlistList(Request $req): Response
    {
        return Response::json($this->allowlist($req) + ['mode' => Access::authorization($this->app)->mode(), 'guestLoginEnabled' => $this->guestEnabled()]);
    }

    public function allowlistCreate(Request $req): Response
    {
        $data = Validator::check($req->input(), [
            'email' => ['email', 'required'],
            'note' => ['string', 'nullable', 'max' => 500],
            'organizationExempt' => ['bool'],
        ]);
        $me = $this->app->currentUser();
        try {
            $id = $this->db()->insert('authorized_emails', [
                'email' => $data['email'],
                'status' => 'ACTIVE',
                'organization_exempt' => $data['organizationExempt'] ?? false,
                'note' => $data['note'] ?? null,
                'added_by_id' => $me->id(),
                'added_by_email' => $me->email(),
            ]);
        } catch (\Throwable $e) {
            if (Db::isDuplicate($e)) {
                throw ApiError::conflict('That address is already on the list.');
            }
            throw $e;
        }
        Audit::log($this->db(), $this->actor(), 'allowlist.add', 'AuthorizedEmail', $id, $data['email']);
        return Response::json(Json::row('authorized_emails', (array) $this->db()->one('SELECT * FROM {{authorized_emails}} WHERE id = ?', [$id])), 201);
    }

    public function allowlistUpdate(Request $req, array $p): Response
    {
        $data = Validator::check($req->input(), [
            'status' => ['enum', 'enum' => ['ACTIVE', 'SUSPENDED']],
            'organizationExempt' => ['bool'],
            'note' => ['string', 'nullable', 'max' => 500],
        ], partial: true);
        $row = $this->db()->one('SELECT * FROM {{authorized_emails}} WHERE id = ?', [$p['id']]) ?? throw ApiError::notFound();
        if (($data['status'] ?? null) === 'SUSPENDED' && $row['status'] === 'ACTIVE' && $this->activeAllowlistCount() <= 1) {
            throw ApiError::conflict('This is the last active address; suspending it would lock everyone out.');
        }
        $set = [];
        foreach (['status' => 'status', 'organizationExempt' => 'organization_exempt', 'note' => 'note'] as $in => $col) {
            if (array_key_exists($in, $data)) {
                $set[$col] = $data[$in];
            }
        }
        if ($set !== []) {
            $this->db()->update('authorized_emails', $set, ['id' => $row['id']]);
            Audit::log($this->db(), $this->actor(), 'allowlist.update', 'AuthorizedEmail', (string) $row['id'], $row['email'] . ' ' . json_encode($data));
        }
        Cache::forgetMemo();
        return Response::json(Json::row('authorized_emails', (array) $this->db()->one('SELECT * FROM {{authorized_emails}} WHERE id = ?', [$row['id']])));
    }

    public function allowlistDelete(Request $req, array $p): Response
    {
        $row = $this->db()->one('SELECT * FROM {{authorized_emails}} WHERE id = ?', [$p['id']]) ?? throw ApiError::notFound();
        if ($row['status'] === 'ACTIVE' && $this->activeAllowlistCount() <= 1) {
            throw ApiError::conflict('This is the last active address; removing it would lock everyone out of the admin area.');
        }
        $this->db()->delete('authorized_emails', ['id' => $row['id']]);
        Audit::log($this->db(), $this->actor(), 'allowlist.remove', 'AuthorizedEmail', (string) $row['id'], (string) $row['email']);
        return Response::json(['ok' => true]);
    }

    private function activeAllowlistCount(): int
    {
        return (int) $this->db()->value("SELECT COUNT(*) FROM {{authorized_emails}} WHERE status = 'ACTIVE'");
    }

    private function guestEnabled(): bool
    {
        return GuestLogin::enabled($this->db());
    }

    public function guestGet(Request $req): Response
    {
        return Response::json(['enabled' => $this->guestEnabled()]);
    }

    public function guestSet(Request $req): Response
    {
        $data = Validator::check($req->input(), ['enabled' => ['bool', 'required']]);
        GuestLogin::set($this->db(), $data['enabled']);
        Audit::log($this->db(), $this->actor(), $data['enabled'] ? 'guest_login.open' : 'guest_login.close', 'AuthSettings', 'singleton');
        return Response::json(['enabled' => $data['enabled']]);
    }

    // Access attempts ---------------------------------------------------------

    /** @return array{items: list<array<string, mixed>>, total: int, page: int, pageSize: int} */
    private function attempts(Request $req): array
    {
        $conditions = ['1=1'];
        $params = [];
        if (($q = trim((string) $req->query('q'))) !== '') {
            $conditions[] = 'email LIKE ?';
            $params[] = '%' . Db::likeEscape(mb_substr($q, 0, 100)) . '%';
        }
        if (in_array($req->query('reason'), [Authorization::NOT_ORG_MEMBER, Authorization::EMAIL_NOT_AUTHORIZED, Authorization::BOTH_FAILED, Authorization::CALLBACK_ERROR], true)) {
            $conditions[] = 'reason = ?';
            $params[] = $req->query('reason');
        }
        foreach (['from' => '>=', 'to' => '<'] as $key => $op) {
            $value = $req->query($key);
            if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                $conditions[] = "created_at $op ?";
                $params[] = $key === 'to' ? Db::datetime(new \DateTimeImmutable($value . ' +1 day')) : $value . ' 00:00:00';
            }
        }
        if ($req->query('unreviewed') === '1') {
            $conditions[] = 'reviewed_at IS NULL';
        }
        $where = implode(' AND ', $conditions);
        $page = self::pageNumber($req);
        $total = (int) $this->db()->value("SELECT COUNT(*) FROM {{unauthorized_access_attempts}} WHERE $where", $params);
        $rows = $this->db()->all(
            "SELECT * FROM {{unauthorized_access_attempts}} WHERE $where ORDER BY created_at DESC LIMIT " . self::PAGE_SIZE . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE),
            $params,
        );
        return ['items' => Json::rows('unauthorized_access_attempts', $rows), 'total' => $total, 'page' => $page, 'pageSize' => self::PAGE_SIZE];
    }

    public function attemptsPage(Request $req): Response
    {
        return $this->page('admin/access-attempts', ['title' => 'Access attempts', 'list' => $this->attempts($req), 'query' => $req->query]);
    }

    public function attemptsList(Request $req): Response
    {
        return Response::json($this->attempts($req));
    }

    public function attemptsAction(Request $req): Response
    {
        $data = Validator::check($req->input(), [
            'action' => ['enum', 'required', 'enum' => ['review', 'prune']],
            'ids' => ['array', 'of' => 'id', 'max' => 500],
        ]);
        if ($data['action'] === 'prune') {
            $n = AccessAttempts::prune($this->db());
            Audit::log($this->db(), $this->actor(), 'access_attempts.prune', 'UnauthorizedAccessAttempt', null, "$n pruned");
            return Response::json(['pruned' => $n]);
        }
        $ids = $data['ids'] ?? [];
        if ($ids === []) {
            return Response::json(['reviewed' => 0]);
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $n = $this->db()->run(
            "UPDATE {{unauthorized_access_attempts}} SET reviewed_at = ?, reviewed_by_email = ? WHERE id IN ($in) AND reviewed_at IS NULL",
            [Db::now(), $this->actor(), ...$ids],
        )->rowCount();
        return Response::json(['reviewed' => $n]);
    }

    // Permission groups -------------------------------------------------------

    /** @return list<array<string, mixed>> */
    private function groups(): array
    {
        $rows = $this->db()->all(
            'SELECT g.*, (SELECT COUNT(*) FROM {{group_assignments}} a WHERE a.group_id = g.id) AS assignment_count
             FROM {{permission_groups}} g ORDER BY g.name',
        );
        return array_map(function (array $r): array {
            $out = Json::row('permission_groups', $r);
            $out['capabilities'] = Capabilities::sanitize($out['capabilities']);
            $out['assignmentCount'] = (int) $r['assignment_count'];
            return $out;
        }, $rows);
    }

    public function permissionsPage(Request $req): Response
    {
        return $this->page('admin/permissions', [
            'title' => 'Permissions',
            'groups' => $this->groups(),
            'capabilities' => Capabilities::all(),
            'assignments' => $this->assignments(null),
            'categories' => $this->db()->all('SELECT id, name FROM {{categories}} WHERE deleted_at IS NULL ORDER BY name'),
            'series' => $this->db()->all('SELECT id, title FROM {{series}} WHERE deleted_at IS NULL ORDER BY title'),
        ]);
    }

    public function groupsList(Request $req): Response
    {
        return Response::json(['groups' => $this->groups(), 'capabilities' => array_map(fn ($k, $c) => ['key' => $k] + $c, array_keys(Capabilities::all()), Capabilities::all())]);
    }

    public function groupsCreate(Request $req): Response
    {
        $data = Validator::check($req->input(), [
            'name' => ['string', 'required', 'max' => 100],
            'description' => ['string', 'nullable', 'max' => 500],
            'capabilities' => ['array', 'max' => 50],
        ]);
        try {
            $id = $this->db()->insert('permission_groups', [
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'capabilities' => Capabilities::sanitize($data['capabilities'] ?? []),
            ]);
        } catch (\Throwable $e) {
            if (Db::isDuplicate($e)) {
                throw ApiError::conflict('A group with that name already exists.');
            }
            throw $e;
        }
        Audit::log($this->db(), $this->actor(), 'permission_group.create', 'PermissionGroup', $id, $data['name']);
        return Response::json(['id' => $id], 201);
    }

    public function groupsUpdate(Request $req, array $p): Response
    {
        $data = Validator::check($req->input(), [
            'name' => ['string', 'max' => 100],
            'description' => ['string', 'nullable', 'max' => 500],
            'capabilities' => ['array', 'max' => 50],
        ], partial: true);
        $group = $this->db()->one('SELECT * FROM {{permission_groups}} WHERE id = ?', [$p['id']]) ?? throw ApiError::notFound();
        $set = [];
        if (isset($data['name'])) {
            $set['name'] = $data['name'];
        }
        if (array_key_exists('description', $data)) {
            $set['description'] = $data['description'];
        }
        if (isset($data['capabilities'])) {
            $caps = Capabilities::sanitize($data['capabilities']);
            // A group already assigned with a scope can't gain a site-wide-only capability.
            $scoped = (int) $this->db()->value('SELECT COUNT(*) FROM {{group_assignments}} WHERE group_id = ? AND (category_id IS NOT NULL OR series_id IS NOT NULL)', [$group['id']]);
            if ($scoped > 0 && array_filter($caps, [Capabilities::class, 'siteWideOnly']) !== []) {
                throw ApiError::invalid('This group is assigned to a category or series; site-wide capabilities can’t be added to it.');
            }
            $set['capabilities'] = $caps;
        }
        if ($set !== []) {
            try {
                $this->db()->update('permission_groups', $set, ['id' => $group['id']]);
            } catch (\Throwable $e) {
                if (Db::isDuplicate($e)) {
                    throw ApiError::conflict('A group with that name already exists.');
                }
                throw $e;
            }
            // Capabilities changed for everyone in the group: their sessions start over.
            $this->db()->run('DELETE FROM {{sessions}} WHERE user_id IN (SELECT user_id FROM {{group_assignments}} WHERE group_id = ?)', [$group['id']]);
            Audit::log($this->db(), $this->actor(), 'permission_group.update', 'PermissionGroup', (string) $group['id'], json_encode($data) ?: null);
        }
        return Response::json(['ok' => true]);
    }

    public function groupsDelete(Request $req, array $p): Response
    {
        $group = $this->db()->one('SELECT * FROM {{permission_groups}} WHERE id = ?', [$p['id']]) ?? throw ApiError::notFound();
        $this->db()->delete('permission_groups', ['id' => $group['id']]);
        Audit::log($this->db(), $this->actor(), 'permission_group.delete', 'PermissionGroup', (string) $group['id'], (string) $group['name']);
        return Response::json(['ok' => true]);
    }

    /** @return list<array<string, mixed>> */
    private function assignments(?string $userId): array
    {
        $rows = $this->db()->all(
            'SELECT a.*, u.email AS user_email, g.name AS group_name, c.name AS category_name, s.title AS series_title
             FROM {{group_assignments}} a
             JOIN {{users}} u ON u.id = a.user_id
             JOIN {{permission_groups}} g ON g.id = a.group_id
             LEFT JOIN {{categories}} c ON c.id = a.category_id
             LEFT JOIN {{series}} s ON s.id = a.series_id
             WHERE (? IS NULL OR a.user_id = ?) ORDER BY u.email, g.name',
            [$userId, $userId],
        );
        return array_map(fn (array $r) => Json::row('group_assignments', $r), $rows);
    }

    public function assignmentsList(Request $req): Response
    {
        $userId = $req->query('userId');
        return Response::json($this->assignments(is_string($userId) && $userId !== '' ? $userId : null));
    }

    public function assignmentsCreate(Request $req): Response
    {
        $data = Validator::check($req->input(), [
            'userId' => ['id', 'nullable'],
            'email' => ['email', 'nullable'],
            'groupId' => ['id', 'required'],
            'categoryId' => ['id', 'nullable'],
            'seriesId' => ['id', 'nullable'],
        ]);
        $userId = $data['userId'] ?? (($data['email'] ?? null) !== null ? $this->db()->value('SELECT id FROM {{users}} WHERE email = ?', [$data['email']]) : null);
        if ($userId === null) {
            throw ApiError::invalid('No member with that address has signed in yet. Add them under Members & roles first.');
        }
        if (($data['categoryId'] ?? null) !== null && ($data['seriesId'] ?? null) !== null) {
            throw ApiError::invalid('Scope a grant to a category or a series, not both.');
        }
        $group = $this->db()->one('SELECT * FROM {{permission_groups}} WHERE id = ?', [$data['groupId']]) ?? throw ApiError::notFound();
        $scoped = ($data['categoryId'] ?? null) !== null || ($data['seriesId'] ?? null) !== null;
        if ($scoped && array_filter(Capabilities::sanitize(json_decode((string) $group['capabilities'], true)), [Capabilities::class, 'siteWideOnly']) !== []) {
            throw ApiError::invalid('This group holds capabilities that only make sense site-wide; assign it without a category or series.');
        }
        $id = $this->db()->insert('group_assignments', [
            'user_id' => $userId,
            'group_id' => $group['id'],
            'category_id' => $data['categoryId'] ?? null,
            'series_id' => $data['seriesId'] ?? null,
        ]);
        $this->db()->delete('sessions', ['user_id' => $userId]);
        Audit::log($this->db(), $this->actor(), 'group_assignment.create', 'GroupAssignment', $id, $group['name'] . ' → ' . $userId);
        return Response::json(['id' => $id], 201);
    }

    public function assignmentsDelete(Request $req, array $p): Response
    {
        $row = $this->db()->one('SELECT * FROM {{group_assignments}} WHERE id = ?', [$p['id']]) ?? throw ApiError::notFound();
        $this->db()->delete('group_assignments', ['id' => $row['id']]);
        $this->db()->delete('sessions', ['user_id' => $row['user_id']]);
        Audit::log($this->db(), $this->actor(), 'group_assignment.delete', 'GroupAssignment', (string) $row['id']);
        return Response::json(['ok' => true]);
    }

    // Content-editor grants ---------------------------------------------------

    /** @return array{categoryEditors: list<array<string, mixed>>, seriesEditors: list<array<string, mixed>>} */
    private function editors(): array
    {
        $categories = $this->db()->all('SELECT e.*, u.email AS user_email, c.name AS category_name FROM {{category_editors}} e JOIN {{users}} u ON u.id = e.user_id JOIN {{categories}} c ON c.id = e.category_id ORDER BY u.email');
        $series = $this->db()->all('SELECT e.*, u.email AS user_email, s.title AS series_title FROM {{series_editors}} e JOIN {{users}} u ON u.id = e.user_id JOIN {{series}} s ON s.id = e.series_id ORDER BY u.email');
        return ['categoryEditors' => Json::rows('category_editors', $categories), 'seriesEditors' => Json::rows('series_editors', $series)];
    }

    public function editorsList(Request $req): Response
    {
        return Response::json($this->editors());
    }

    public function editorsCreate(Request $req, string $kind): Response
    {
        $key = $kind === 'category' ? 'categoryId' : 'seriesId';
        $data = Validator::check($req->input(), ['userId' => ['id', 'nullable'], 'email' => ['email', 'nullable'], $key => ['id', 'required']]);
        $userId = $data['userId'] ?? (($data['email'] ?? null) !== null ? $this->db()->value('SELECT id FROM {{users}} WHERE email = ?', [$data['email']]) : null);
        if ($userId === null) {
            throw ApiError::invalid('No member with that address. Add them under Members & roles first.');
        }
        try {
            $id = $this->db()->insert($kind === 'category' ? 'category_editors' : 'series_editors', [
                'user_id' => $userId,
                ($kind === 'category' ? 'category_id' : 'series_id') => $data[$key],
            ]);
        } catch (\Throwable $e) {
            if (Db::isDuplicate($e)) {
                throw ApiError::conflict('They already have that grant.');
            }
            throw $e;
        }
        $this->db()->delete('sessions', ['user_id' => $userId]);
        Audit::log($this->db(), $this->actor(), "editor.$kind.grant", $kind === 'category' ? 'CategoryEditor' : 'SeriesEditor', $id, $data[$key] . ' → ' . $userId);
        return Response::json(['id' => $id], 201);
    }

    public function editorsDelete(string $kind, string $id): Response
    {
        $table = $kind === 'category' ? 'category_editors' : 'series_editors';
        $row = $this->db()->one("SELECT * FROM {{{$table}}} WHERE id = ?", [$id]) ?? throw ApiError::notFound();
        $this->db()->delete($table, ['id' => $id]);
        $this->db()->delete('sessions', ['user_id' => $row['user_id']]);
        Audit::log($this->db(), $this->actor(), "editor.$kind.revoke", $kind === 'category' ? 'CategoryEditor' : 'SeriesEditor', $id);
        return Response::json(['ok' => true]);
    }

    // Audit log ---------------------------------------------------------------

    /** @return array{0: string, 1: list<mixed>} */
    private function auditWhere(Request $req): array
    {
        $conditions = ['1=1'];
        $params = [];
        if (($q = trim((string) $req->query('q'))) !== '') {
            $conditions[] = '(actor_email LIKE ? OR action LIKE ? OR entity_type LIKE ? OR detail LIKE ?)';
            $like = '%' . Db::likeEscape(mb_substr($q, 0, 100)) . '%';
            array_push($params, $like, $like, $like, $like);
        }
        foreach (['from' => '>=', 'to' => '<'] as $key => $op) {
            $value = $req->query($key);
            if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                $conditions[] = "created_at $op ?";
                $params[] = $key === 'to' ? Db::datetime(new \DateTimeImmutable($value . ' +1 day')) : $value . ' 00:00:00';
            }
        }
        return [implode(' AND ', $conditions), $params];
    }

    /** @return array{items: list<array<string, mixed>>, total: int, page: int, pageSize: int} */
    private function audit(Request $req): array
    {
        [$where, $params] = $this->auditWhere($req);
        $page = self::pageNumber($req);
        $total = (int) $this->db()->value("SELECT COUNT(*) FROM {{audit_logs}} WHERE $where", $params);
        $rows = $this->db()->all("SELECT * FROM {{audit_logs}} WHERE $where ORDER BY created_at DESC LIMIT " . self::PAGE_SIZE . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE), $params);
        return ['items' => Json::rows('audit_logs', $rows), 'total' => $total, 'page' => $page, 'pageSize' => self::PAGE_SIZE];
    }

    public function auditPage(Request $req): Response
    {
        return $this->page('admin/audit', ['title' => 'Audit log', 'list' => $this->audit($req), 'query' => $req->query]);
    }

    public function auditList(Request $req): Response
    {
        return Response::json($this->audit($req));
    }

    /** CSV or JSON of the whole filtered log, streamed a thousand rows at a time. */
    public function auditExport(Request $req): Response
    {
        [$where, $params] = $this->auditWhere($req);
        $format = $req->query('format') === 'json' ? 'json' : 'csv';
        $db = $this->db();
        $name = 'audit-log-' . gmdate('Y-m-d') . '.' . $format;
        Audit::log($db, $this->actor(), 'audit.export', 'AuditLog', null, $format);
        return Response::stream(static function () use ($db, $where, $params, $format): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            $format === 'csv' ? fputcsv($out, ['id', 'createdAt', 'actorEmail', 'action', 'entityType', 'entityId', 'detail'], ',', '"', '\\') : fwrite($out, '[');
            $offset = 0;
            $first = true;
            do {
                $rows = $db->all("SELECT * FROM {{audit_logs}} WHERE $where ORDER BY created_at DESC LIMIT 1000 OFFSET $offset", $params);
                foreach ($rows as $r) {
                    $row = Json::row('audit_logs', $r);
                    if ($format === 'csv') {
                        // A cell starting with =, +, - or @ is a formula to a spreadsheet.
                        $cells = array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v, [$row['id'], $row['createdAt'], $row['actorEmail'], $row['action'], $row['entityType'], $row['entityId'], $row['detail']]);
                        fputcsv($out, $cells, ',', '"', '\\');
                    } else {
                        fwrite($out, ($first ? '' : ',') . json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                        $first = false;
                    }
                }
                $offset += 1000;
            } while (count($rows) === 1000);
            if ($format === 'json') {
                fwrite($out, ']');
            }
            fclose($out);
        }, 200, [
            'Content-Type' => $format === 'json' ? 'application/json; charset=utf-8' : 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
        ]);
    }
}
