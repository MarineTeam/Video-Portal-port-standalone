<?php

declare(strict_types=1);

namespace App\Modules\Library\Admin;

use App\Core\ApiError;
use App\Core\App;
use App\Core\Db;
use App\Core\Json;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\ErrorPage;

/**
 * /admin/trash: what was deleted, for restoring or deleting for good. The
 * queue spans all four kinds, so it needs one of their capabilities held
 * site-wide. Permanent deletion is the only point a video's or file's asset
 * at its provider is removed — trashing leaves it where it is.
 */
final class TrashAdmin
{
    private const PATH_TYPES = ['category' => 'category', 'categories' => 'category', 'series' => 'series', 'video' => 'video', 'videos' => 'video', 'file' => 'file', 'files' => 'file'];

    public function __construct(private readonly App $app, private readonly Catalog $catalog)
    {
    }

    public static function register(Router $r, App $app): void
    {
        $self = new self($app, new Catalog($app));
        $r->get('/admin/trash', [$self, 'page']);
        $r->get('/api/admin/trash', [$self, 'list']);
        $r->post('/api/admin/trash/[type]/[id]', [$self, 'restore']);
        $r->add('DELETE', '/api/admin/trash/[type]/[id]', [$self, 'purge']);
    }

    private function db(): Db
    {
        return $this->app->db();
    }

    private function allowed(): bool
    {
        $current = $this->app->currentUser();
        if (!$current->isSignedIn()) {
            return false;
        }
        foreach (['manage_categories', 'manage_series', 'manage_videos', 'manage_files'] as $cap) {
            if ($current->can($cap)) {
                return true;
            }
        }
        return false;
    }

    private function guard(): void
    {
        if (!$this->app->currentUser()->isSignedIn()) {
            throw ApiError::unauthorized();
        }
        if (!$this->allowed()) {
            throw ApiError::forbidden('The trash needs one of the content permissions held across the whole site.');
        }
    }

    /** @return list<array<string, mixed>> */
    private function items(): array
    {
        $out = [];
        foreach (Catalog::TYPES as $type => $meta) {
            if (!$this->app->currentUser()->can($meta['capability'])) {
                continue;
            }
            $title = $meta['title'];
            foreach ($this->db()->all("SELECT id, $title AS title, deleted_at FROM {{{$meta['table']}}} WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC LIMIT 500") as $row) {
                $out[] = ['type' => $type, 'id' => $row['id'], 'title' => $row['title'], 'deletedAt' => Json::instant((string) $row['deleted_at'])];
            }
        }
        usort($out, fn ($a, $b) => strcmp((string) $b['deletedAt'], (string) $a['deletedAt']));
        return $out;
    }

    public function page(Request $req): Response
    {
        if (!$this->app->currentUser()->isSignedIn()) {
            return \App\Core\Response::redirect(\App\Core\Url::to('/auth/login', ['returnTo' => '/admin/trash']));
        }
        if (!$this->allowed()) {
            return ErrorPage::render(403);
        }
        return $this->app->page('admin/trash', ['title' => 'Trash', 'items' => $this->items()], 200, 'layouts/admin');
    }

    public function list(Request $req): Response
    {
        $this->guard();
        return Response::json($this->items());
    }

    /** @param array<string, string> $p @return array{0: string, 1: array<string, mixed>} */
    private function target(array $p): array
    {
        $type = self::PATH_TYPES[$p['type']] ?? null;
        if ($type === null) {
            throw ApiError::notFound();
        }
        $row = $this->catalog->find($type, $p['id'], withTrashed: true);
        if ($row['deleted_at'] === null) {
            throw ApiError::invalid('That isn’t in the trash.');
        }
        if (!$this->app->currentUser()->can(Catalog::TYPES[$type]['capability'])) {
            throw ApiError::forbidden();
        }
        return [$type, $row];
    }

    /** @param array<string, string> $p */
    public function restore(Request $req, array $p): Response
    {
        $this->guard();
        [$type, $row] = $this->target($p);
        $this->db()->update(Catalog::TYPES[$type]['table'], ['deleted_at' => null], ['id' => $row['id']]);
        $this->catalog->audit($type . '.restore', $type, (string) $row['id']);
        $this->app->hooks->do("$type.restored", $row, $this->app);
        return Response::json(['ok' => true]);
    }

    /** @param array<string, string> $p */
    public function purge(Request $req, array $p): Response
    {
        $this->guard();
        [$type, $row] = $this->target($p);
        // The asset goes first: a row left behind can be purged again, an
        // asset orphaned by a vanished row can't be found.
        $this->app->hooks->do("$type.purging", $row, $this->app);
        if ($type === 'video') {
            \App\Modules\Library\Admin\VideoAssets::delete($this->app, $row);
        } elseif ($type === 'file') {
            \App\Modules\Library\Admin\FileAssets::delete($this->app, $row);
        }
        $this->db()->delete(Catalog::TYPES[$type]['table'], ['id' => $row['id']]);
        $this->catalog->audit($type . '.purge', $type, (string) $row['id'], (string) ($row[Catalog::TYPES[$type]['title']] ?? ''));
        $this->app->hooks->do("$type.purged", $row, $this->app);
        return Response::json(['ok' => true]);
    }
}
