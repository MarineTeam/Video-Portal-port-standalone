<?php

declare(strict_types=1);

namespace App\Modules\Library\Admin;

use App\Core\ApiError;
use App\Core\App;
use App\Core\Db;
use App\Core\Id;
use App\Core\Json;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Validator;

/**
 * "Restricted viewing" on a series or video: permission groups (as roles)
 * and named people who may view it. The first grant makes the item theirs
 * alone (and admins'), whatever "Members only" says; removing the last one
 * puts it back under "Members only".
 */
final class ViewersAdmin
{
    private const KINDS = [
        'series' => ['type' => 'series', 'users' => 'series_viewers', 'groups' => 'series_viewer_groups', 'column' => 'series_id'],
        'videos' => ['type' => 'video', 'users' => 'video_viewers', 'groups' => 'video_viewer_groups', 'column' => 'video_id'],
    ];

    public function __construct(private readonly App $app, private readonly Catalog $catalog)
    {
    }

    public static function register(Router $r, App $app): void
    {
        $self = new self($app, new Catalog($app));
        foreach (self::KINDS as $path => $kind) {
            $can = Middleware::can($app, $kind['type'] === 'series' ? 'manage_series' : 'manage_videos', anywhere: true);
            $r->get("/api/admin/$path/[id]/viewers", fn (Request $q, array $p) => $self->users($path, $p['id']), [$can]);
            $r->post("/api/admin/$path/[id]/viewers", fn (Request $q, array $p) => $self->addUser($path, $p['id'], $q), [$can]);
            $r->get("/api/admin/$path/[id]/viewer-groups", fn (Request $q, array $p) => $self->groups($path, $p['id']), [$can]);
            $r->post("/api/admin/$path/[id]/viewer-groups", fn (Request $q, array $p) => $self->addGroup($path, $p['id'], $q), [$can]);
            $r->add('DELETE', "/api/admin/$path/viewers/[id]", fn (Request $q, array $p) => $self->remove($path, 'users', $p['id']), [$can]);
            $r->add('DELETE', "/api/admin/$path/viewer-groups/[id]", fn (Request $q, array $p) => $self->remove($path, 'groups', $p['id']), [$can]);
        }
    }

    private function db(): Db
    {
        return $this->app->db();
    }

    /** @return array<string, mixed> the item, once the caller may manage it */
    private function item(string $path, string $id): array
    {
        $type = self::KINDS[$path]['type'];
        $row = $this->catalog->find($type, $id);
        $this->catalog->require(Catalog::TYPES[$type]['capability'], $this->catalog->scopeOf($type, $row));
        return $row;
    }

    /** @return array{users: list<array<string, mixed>>, groups: list<array<string, mixed>>} */
    public function listFor(string $path, string $id): array
    {
        $kind = self::KINDS[$path];
        return [
            'users' => array_map(fn ($r) => Json::row($kind['users'], $r) + ['email' => $r['email'], 'name' => $r['name']], $this->db()->all(
                "SELECT v.id, v.user_id, v.created_at, u.email, COALESCE(u.display_name, u.name) AS name FROM {{{$kind['users']}}} v JOIN {{users}} u ON u.id = v.user_id WHERE v.{$kind['column']} = ? ORDER BY u.email",
                [$id],
            )),
            'groups' => array_map(fn ($r) => Json::row($kind['groups'], $r) + ['name' => $r['name']], $this->db()->all(
                "SELECT v.id, v.group_id, v.created_at, g.name FROM {{{$kind['groups']}}} v JOIN {{permission_groups}} g ON g.id = v.group_id WHERE v.{$kind['column']} = ? ORDER BY g.name",
                [$id],
            )),
        ];
    }

    private function users(string $path, string $id): Response
    {
        $this->item($path, $id);
        return Response::json($this->listFor($path, $id)['users']);
    }

    private function groups(string $path, string $id): Response
    {
        $this->item($path, $id);
        return Response::json($this->listFor($path, $id)['groups']);
    }

    private function addUser(string $path, string $id, Request $req): Response
    {
        $item = $this->item($path, $id);
        $kind = self::KINDS[$path];
        $data = Validator::check($req->input(), ['email' => ['email', 'required']]);
        $userId = $this->db()->value('SELECT id FROM {{users}} WHERE email = ?', [$data['email']]);
        if ($userId === null) {
            throw ApiError::notFound('Nobody with that address has signed in yet. They need an account before they can be named here.');
        }
        $this->db()->run(
            "INSERT IGNORE INTO {{{$kind['users']}}} (id, {$kind['column']}, user_id) VALUES (?, ?, ?)",
            [Id::new(), $item['id'], $userId],
        );
        $this->catalog->audit('viewers.grant', $kind['type'], (string) $item['id'], (string) $data['email']);
        return Response::json($this->listFor($path, $id)['users'], 201);
    }

    private function addGroup(string $path, string $id, Request $req): Response
    {
        $item = $this->item($path, $id);
        $kind = self::KINDS[$path];
        $data = Validator::check($req->input(), ['groupId' => ['id', 'required']]);
        $name = $this->db()->value('SELECT name FROM {{permission_groups}} WHERE id = ?', [$data['groupId']]);
        if ($name === null) {
            throw ApiError::notFound('That group doesn’t exist.');
        }
        $this->db()->run(
            "INSERT IGNORE INTO {{{$kind['groups']}}} (id, {$kind['column']}, group_id) VALUES (?, ?, ?)",
            [Id::new(), $item['id'], $data['groupId']],
        );
        $this->catalog->audit('viewers.grant_group', $kind['type'], (string) $item['id'], (string) $name);
        return Response::json($this->listFor($path, $id)['groups'], 201);
    }

    private function remove(string $path, string $which, string $grantId): Response
    {
        $kind = self::KINDS[$path];
        $table = $kind[$which];
        $grant = Id::isValid($grantId) ? $this->db()->one("SELECT * FROM {{{$table}}} WHERE id = ?", [$grantId]) : null;
        if ($grant === null) {
            throw ApiError::notFound();
        }
        $item = $this->item($path, (string) $grant[$kind['column']]);
        $this->db()->delete($table, ['id' => $grantId]);
        $this->catalog->audit($which === 'users' ? 'viewers.revoke' : 'viewers.revoke_group', $kind['type'], (string) $item['id']);
        return Response::json(['ok' => true]);
    }
}
