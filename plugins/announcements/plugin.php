<?php
/**
 * Plugin Name: Announcements
 * Slug:        announcements
 * Version:     1.0.0
 * Description: Shows a dismissible site-wide banner message.
 * Author:      Marine Team
 * Requires PHP: 8.2
 * Requires App: 3.0
 */

declare(strict_types=1);

use App\Core\ApiError;
use App\Core\App;
use App\Core\Cache;
use App\Core\Db;
use App\Core\Hooks;
use App\Core\Id;
use App\Core\Json;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Validator;
use App\Modules\Audit\Audit;
use App\Modules\Plugins\BasePlugin;

/**
 * One banner across the top of every page: the newest active announcement
 * for the reader — guests, members or everyone — inside its optional
 * publish/expiry window. Dismissing it hides it for the rest of that
 * browser session. Site-wide only: it is a global message, so no category
 * can switch it off. Managed at /admin/announcements.
 */
return new class (__DIR__) extends BasePlugin {
    public const AUDIENCES = ['ALL', 'GUESTS', 'MEMBERS'];
    /** The banner is cached per audience; a window edge is at most this late. */
    private const CACHE_SECONDS = 60;

    public function boot(Hooks $hooks, App $app): void
    {
        $this->useLang($hooks);
        $this->useTemplates($hooks);
        $hooks->on('render.page_top', function (App $app): void {
            $banner = $this->active($app, $app->currentUser()->isSignedIn());
            if ($banner !== null) {
                echo $app->view()->partial('announcements/banner', ['banner' => $banner, 'script' => $this->asset('banner.js')]);
            }
        });
        $hooks->on('routes.register', function (Router $r) use ($app): void {
            $can = Middleware::can($app, 'manage_plugins');
            $r->get('/admin/announcements', fn () => $app->page('announcements/admin', ['title' => 'Announcements', 'rows' => $this->all($app->db())], 200, 'layouts/admin'), [$can]);
            $r->get('/api/admin/announcements', fn () => Response::json($this->all($app->db())), [$can]);
            $r->post('/api/admin/announcements', fn (Request $req) => $this->save($app, $req, null), [$can]);
            $r->add('PATCH', '/api/admin/announcements/[id]', fn (Request $req, array $p) => $this->save($app, $req, $p['id']), [$can]);
            $r->add('DELETE', '/api/admin/announcements/[id]', function (Request $req, array $p) use ($app): Response {
                $row = $this->find($app->db(), $p['id']);
                $app->db()->delete('announcements', ['id' => $row['id']]);
                $this->changed($app, 'announcement.delete', (string) $row['id']);
                return Response::json(['ok' => true]);
            }, [$can]);
        });
    }

    /** @return array<string, mixed>|null the newest one this reader should see now */
    private function active(App $app, bool $member): ?array
    {
        $audience = $member ? 'MEMBERS' : 'GUESTS';
        $row = Cache::remember('announcement:' . $audience, self::CACHE_SECONDS, function () use ($app, $audience) {
            $now = Db::datetime(new \DateTimeImmutable());
            return $app->db()->one(
                "SELECT id, message FROM {{announcements}}
                 WHERE active = 1 AND audience IN ('ALL', ?) AND (publish_at IS NULL OR publish_at <= ?) AND (expires_at IS NULL OR expires_at > ?)
                 ORDER BY created_at DESC LIMIT 1",
                [$audience, $now, $now],
            ) ?? false;
        });
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string, mixed>> */
    private function all(Db $db): array
    {
        return Json::rows('announcements', $db->all('SELECT * FROM {{announcements}} ORDER BY created_at DESC'));
    }

    /** @return array<string, mixed> */
    private function find(Db $db, string $id): array
    {
        $row = Id::isValid($id) ? $db->one('SELECT * FROM {{announcements}} WHERE id = ?', [$id]) : null;
        return $row ?? throw ApiError::notFound();
    }

    private function save(App $app, Request $req, ?string $id): Response
    {
        $data = Validator::check($req->input(), [
            'message' => ['text', 'required', 'max' => 1000, 'min' => 1],
            'active' => ['bool'],
            'publishAt' => ['datetime', 'nullable'],
            'expiresAt' => ['datetime', 'nullable'],
            'audience' => ['enum', 'enum' => self::AUDIENCES],
        ], partial: $id !== null);
        $row = [];
        foreach (['message' => 'message', 'active' => 'active', 'audience' => 'audience'] as $key => $column) {
            if (array_key_exists($key, $data)) {
                $row[$column] = is_bool($data[$key]) ? (int) $data[$key] : $data[$key];
            }
        }
        foreach (['publishAt' => 'publish_at', 'expiresAt' => 'expires_at'] as $key => $column) {
            if (array_key_exists($key, $data)) {
                $row[$column] = $data[$key] instanceof \DateTimeInterface ? Db::datetime($data[$key]) : null;
            }
        }
        $db = $app->db();
        if ($id === null) {
            $id = $db->insert('announcements', $row + ['id' => Id::new()]);
            $status = 201;
        } else {
            $this->find($db, $id);
            if ($row !== []) {
                $db->update('announcements', $row, ['id' => $id]);
            }
            $status = 200;
        }
        $saved = $this->find($db, $id);
        if ($saved['publish_at'] !== null && $saved['expires_at'] !== null && $saved['expires_at'] <= $saved['publish_at']) {
            throw ApiError::invalid('It would expire before it starts.');
        }
        $this->changed($app, $status === 201 ? 'announcement.create' : 'announcement.update', $id);
        return Response::json(Json::row('announcements', $saved), $status);
    }

    private function changed(App $app, string $action, string $id): void
    {
        foreach (self::AUDIENCES as $audience) {
            Cache::forget('announcement:' . $audience);
        }
        Audit::log($app->db(), (string) $app->currentUser()->email(), $action, 'Announcement', $id);
    }
};
