<?php

declare(strict_types=1);

namespace App\Modules\Library\Admin;

use App\Core\ApiError;
use App\Core\App;
use App\Core\Db;
use App\Core\Id;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Validator;
use App\Modules\Audit\Audit;
use App\Modules\Library\HomeRows;

/**
 * /admin/home-rows: turn the homepage's built-in rows on and off, rename
 * and reorder them, and add rows for a category or a tag. Built-in rows
 * can't be deleted, only turned off; curated ones can.
 */
final class HomeRowsAdmin
{
    public function __construct(private readonly App $app)
    {
    }

    public static function register(Router $r, App $app): void
    {
        $self = new self($app);
        $can = Middleware::can($app, 'manage_plugins');
        $r->get('/admin/home-rows', [$self, 'page'], [$can]);
        $r->get('/api/admin/home-rows', [$self, 'list'], [$can]);
        $r->post('/api/admin/home-rows', [$self, 'create'], [$can]);
        $r->add('PATCH', '/api/admin/home-rows/[id]', [$self, 'update'], [$can]);
        $r->add('DELETE', '/api/admin/home-rows/[id]', [$self, 'delete'], [$can]);
    }

    private function db(): Db
    {
        return $this->app->db();
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        HomeRows::ensureSeeded($this->db());
        return array_map(fn (array $r) => [
            'id' => $r['id'],
            'type' => $r['type'],
            'title' => $r['title'],
            'defaultTitle' => HomeRows::defaultTitle($r),
            'enabled' => (bool) $r['enabled'],
            'position' => (int) $r['position'],
            'categoryId' => $r['category_id'],
            'categoryName' => $r['category_name'],
            'tag' => $r['tag'],
            'builtIn' => in_array($r['type'], HomeRows::BUILT_IN, true),
        ], HomeRows::all($this->db()));
    }

    public function page(Request $req): Response
    {
        return $this->app->page('admin/home-rows', [
            'title' => 'Homepage rows',
            'rows' => $this->rows(),
            'categories' => $this->db()->all('SELECT id, name FROM {{categories}} WHERE deleted_at IS NULL ORDER BY name'),
            'plugins' => [
                'RECOMMENDATIONS' => \App\Modules\Plugins\PluginStates::enabled($this->db(), 'recommendations'),
                'TRENDING' => \App\Modules\Plugins\PluginStates::enabled($this->db(), 'view-counts'),
            ],
        ], 200, 'layouts/admin');
    }

    public function list(Request $req): Response
    {
        return Response::json($this->rows());
    }

    public function create(Request $req): Response
    {
        $data = Validator::check($req->input(), [
            'type' => ['enum', 'required', 'enum' => HomeRows::CURATED],
            'title' => ['string', 'nullable', 'max' => 255],
            'categoryId' => ['id', 'nullable'],
            'tag' => ['string', 'nullable', 'max' => 191],
        ]);
        $row = ['id' => Id::new(), 'type' => $data['type'], 'title' => self::title($data['title'] ?? null), 'enabled' => 1];
        if ($data['type'] === 'CATEGORY') {
            $id = (string) ($data['categoryId'] ?? '');
            if ($id === '' || $this->db()->value('SELECT 1 FROM {{categories}} WHERE id = ? AND deleted_at IS NULL', [$id]) === null) {
                throw ApiError::invalid('Choose the category the row shows.');
            }
            $row['category_id'] = $id;
        } else {
            $tag = trim((string) ($data['tag'] ?? ''), " \t#");
            if ($tag === '') {
                throw ApiError::invalid('Name the tag the row shows.');
            }
            $row['tag'] = $tag;
        }
        HomeRows::ensureSeeded($this->db());
        $row['position'] = (int) $this->db()->value('SELECT COALESCE(MAX(position), -1) + 1 FROM {{home_rows}}');
        $this->db()->insert('home_rows', $row);
        $this->audit('home_row.create', (string) $row['id'], (string) $data['type']);
        return Response::json($this->find((string) $row['id']), 201);
    }

    /** @param array<string, string> $p */
    public function update(Request $req, array $p): Response
    {
        $row = $this->row($p['id']);
        $data = Validator::check($req->input(), [
            'title' => ['string', 'nullable', 'max' => 255],
            'enabled' => ['bool'],
            'move' => ['enum', 'enum' => ['up', 'down']],
        ], partial: true);
        if (isset($data['move'])) {
            $this->move($row, (string) $data['move']);
        }
        $update = [];
        if (array_key_exists('title', $data)) {
            $update['title'] = self::title($data['title']);
        }
        if (array_key_exists('enabled', $data)) {
            $update['enabled'] = $data['enabled'] ? 1 : 0;
        }
        if ($update !== []) {
            $this->db()->update('home_rows', $update, ['id' => $row['id']]);
        }
        $this->audit('home_row.update', (string) $row['id'], (string) $row['type']);
        return Response::json($this->find((string) $row['id']));
    }

    /** @param array<string, string> $p */
    public function delete(Request $req, array $p): Response
    {
        $row = $this->row($p['id']);
        if (in_array($row['type'], HomeRows::BUILT_IN, true)) {
            throw ApiError::invalid('A built-in row can’t be deleted; turn it off instead.');
        }
        $this->db()->delete('home_rows', ['id' => $row['id']]);
        $this->audit('home_row.delete', (string) $row['id'], (string) $row['type']);
        return Response::json(['ok' => true]);
    }

    /** Swaps with the neighbour, after renumbering so positions are distinct. */
    private function move(array $row, string $direction): void
    {
        $this->db()->transaction(function (Db $db) use ($row, $direction) {
            $ids = array_column($db->all('SELECT id FROM {{home_rows}} ORDER BY position, created_at'), 'id');
            $i = array_search($row['id'], $ids, true);
            $j = $direction === 'up' ? $i - 1 : $i + 1;
            if ($i === false || $j < 0 || $j >= count($ids)) {
                return;
            }
            [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
            foreach ($ids as $position => $id) {
                $db->update('home_rows', ['position' => $position], ['id' => $id]);
            }
        });
    }

    private static function title(mixed $title): ?string
    {
        $title = trim((string) $title);
        return $title === '' ? null : $title;
    }

    /** @return array<string, mixed> */
    private function row(string $id): array
    {
        $row = Id::isValid($id) ? $this->db()->one('SELECT * FROM {{home_rows}} WHERE id = ?', [$id]) : null;
        return $row ?? throw ApiError::notFound();
    }

    /** @return array<string, mixed> */
    private function find(string $id): array
    {
        foreach ($this->rows() as $r) {
            if ($r['id'] === $id) {
                return $r;
            }
        }
        throw ApiError::notFound();
    }

    private function audit(string $action, string $id, string $detail): void
    {
        Audit::log($this->db(), (string) $this->app->currentUser()->email(), $action, 'HomeRow', $id, $detail);
    }
}
