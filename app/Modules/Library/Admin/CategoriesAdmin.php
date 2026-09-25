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
use App\Modules\Library\Presenter;
use App\Modules\Plugins\PluginStates;

/**
 * /admin/categories: the tree, nested as deep as anybody likes, each level
 * ordered by hand. The screen is for administrators (it shapes the whole
 * site); the API also answers anyone holding manage_categories where the
 * category sits.
 */
final class CategoriesAdmin
{
    public function __construct(private readonly App $app, private readonly Catalog $catalog)
    {
    }

    public static function register(Router $r, App $app): void
    {
        $self = new self($app, new Catalog($app));
        $admin = Middleware::admin($app);
        $staff = Middleware::staff($app);
        $r->get('/admin/categories', [$self, 'page'], [$admin]);
        $r->get('/admin/categories/[id]', [$self, 'editPage'], [$admin]);
        $r->get('/api/admin/categories', [$self, 'list'], [$staff]);
        $r->post('/api/admin/categories', [$self, 'create'], [$staff]);
        $r->add('PATCH', '/api/admin/categories/[id]', [$self, 'update'], [$staff]);
        $r->add('DELETE', '/api/admin/categories/[id]', [$self, 'trash'], [$staff]);
    }

    private function db(): Db
    {
        return $this->app->db();
    }

    /**
     * Every category, depth-first in position order, each with its depth.
     *
     * @return list<array<string, mixed>>
     */
    public function tree(): array
    {
        $rows = $this->db()->all('SELECT c.*, (SELECT COUNT(*) FROM {{series}} s WHERE s.category_id = c.id AND s.deleted_at IS NULL) AS series_count FROM {{categories}} c WHERE c.deleted_at IS NULL ORDER BY c.position, c.name');
        $byParent = [];
        $ids = array_column($rows, 'id');
        foreach ($rows as $row) {
            // An orphan whose parent was trashed shows at the top, not nowhere.
            $parent = $row['parent_id'] !== null && in_array($row['parent_id'], $ids, true) ? $row['parent_id'] : '';
            $byParent[$parent][] = $row;
        }
        $out = [];
        $walk = function (string $parent, int $depth) use (&$walk, &$out, $byParent): void {
            foreach ($byParent[$parent] ?? [] as $row) {
                $row['depth'] = $depth;
                $out[] = $row;
                if ($depth < 32) {
                    $walk((string) $row['id'], $depth + 1);
                }
            }
        };
        $walk('', 0);
        return $out;
    }

    public function page(Request $req): Response
    {
        return $this->app->page('admin/categories', ['title' => 'Categories', 'categories' => $this->tree()], 200, 'layouts/admin');
    }

    /** @param array<string, string> $p */
    public function editPage(Request $req, array $p): Response
    {
        $category = $this->catalog->find('category', $p['id']);
        $tree = $this->tree();
        $descendants = (new \App\Modules\Library\CategoryTree(array_column($this->db()->all('SELECT id, parent_id FROM {{categories}}'), 'parent_id', 'id')))->descendants([(string) $category['id']]);
        return $this->app->page('admin/category-edit', [
            'title' => 'Edit category',
            'category' => Presenter::category($category),
            'parents' => array_values(array_filter($tree, fn ($c) => !in_array($c['id'], $descendants, true))),
        ], 200, 'layouts/admin');
    }

    public function list(Request $req): Response
    {
        return Response::json(array_map(fn ($row) => Presenter::category($row) + ['depth' => (int) $row['depth'], 'seriesCount' => (int) $row['series_count']], $this->tree()));
    }

    public function create(Request $req): Response
    {
        $data = Fields::read(Fields::CATEGORY, $req->input(), false);
        $parentId = $data['parentId'] ?? null;
        // A top-level category shapes the whole site: administrators only.
        $this->catalog->require('manage_categories', ['categoryId' => $parentId, 'seriesId' => null]);
        if ($parentId === null && ($this->catalog->user()['role'] ?? null) !== 'ADMIN') {
            throw ApiError::forbidden('Only an administrator can add a top-level category.');
        }
        $this->catalog->requirePublishIfTouched($data, ['categoryId' => $parentId, 'seriesId' => null]);
        if ($parentId !== null) {
            $this->catalog->assertParentAllowed('', $parentId);
        }
        $row = Fields::columns(Fields::CATEGORY, $data);
        $row['id'] = Id::new();
        $row['slug'] = $this->catalog->uniqueSlug('category', (string) ($data['slug'] ?? $data['name']));
        $row['tags'] ??= [];
        $row['position'] = $this->catalog->nextPosition('category', $row);
        $this->db()->insert('categories', $row);
        $this->catalog->audit('category.create', 'category', $row['id'], (string) $row['name']);
        return Response::json(Presenter::category($this->catalog->find('category', $row['id'])), 201);
    }

    /** @param array<string, string> $p */
    public function update(Request $req, array $p): Response
    {
        $category = $this->catalog->find('category', $p['id']);
        $scope = $this->catalog->scopeOf('category', $category);
        $this->catalog->require('manage_categories', $scope);
        $input = $req->input();
        if (isset($input['move'])) {
            $to = $input['move'];
            if (!in_array($to, ['up', 'down'], true) && !is_int($to)) {
                throw ApiError::invalid('move must be up, down or a position.');
            }
            $this->catalog->move('category', $category, $to);
            $this->catalog->audit('category.reorder', 'category', (string) $category['id']);
            return Response::json(Presenter::category($this->catalog->find('category', $p['id'])));
        }
        $data = Fields::read(Fields::CATEGORY, $input, true);
        $this->catalog->requirePublishIfTouched($data, $scope);
        if (array_key_exists('parentId', $data) && $data['parentId'] !== $category['parent_id']) {
            $this->catalog->assertParentAllowed((string) $category['id'], $data['parentId']);
            // Moving is taking it out of one place and putting it in another.
            $this->catalog->require('manage_categories', ['categoryId' => $data['parentId'], 'seriesId' => null]);
            if ($data['parentId'] === null && ($this->catalog->user()['role'] ?? null) !== 'ADMIN') {
                throw ApiError::forbidden('Only an administrator can make a category top-level.');
            }
        }
        $row = Fields::columns(Fields::CATEGORY, $data);
        if (isset($data['slug'])) {
            $row['slug'] = $this->catalog->uniqueSlug('category', (string) $data['slug'], (string) $category['id']);
        }
        if (array_key_exists('parent_id', $row)) {
            $row['position'] = $this->catalog->nextPosition('category', ['parent_id' => $row['parent_id']]);
        }
        if ($row !== []) {
            $this->db()->update('categories', $row, ['id' => $category['id']]);
            PluginStates::forget();
        }
        $this->catalog->audit('category.update', 'category', (string) $category['id'], implode(', ', array_keys($data)));
        return Response::json(Presenter::category($this->catalog->find('category', $p['id'])));
    }

    /** @param array<string, string> $p */
    public function trash(Request $req, array $p): Response
    {
        $category = $this->catalog->find('category', $p['id']);
        $this->catalog->require('manage_categories', $this->catalog->scopeOf('category', $category));
        $this->db()->update('categories', ['deleted_at' => Db::now()], ['id' => $category['id']]);
        $this->catalog->audit('category.trash', 'category', (string) $category['id'], (string) $category['name']);
        return Response::json(['ok' => true]);
    }
}
