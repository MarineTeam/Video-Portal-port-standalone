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
use App\Modules\Library\Presenter;

/**
 * /admin/series and its API: the list (filtered, scoped to the editor's part
 * of the library), one series' edit page with "Save as draft", its tags, its
 * viewer restrictions and download setting; moving it between categories.
 */
final class SeriesAdmin
{
    public const PAGE = 50;

    public function __construct(private readonly App $app, private readonly Catalog $catalog)
    {
    }

    public static function register(Router $r, App $app): void
    {
        $self = new self($app, new Catalog($app));
        $can = Middleware::can($app, 'manage_series', anywhere: true);
        $r->get('/admin/series', [$self, 'page'], [$can]);
        $r->get('/admin/series/[id]', [$self, 'editPage'], [$can]);
        $r->get('/api/admin/series', [$self, 'list'], [$can]);
        $r->post('/api/admin/series', [$self, 'create'], [$can]);
        $r->get('/api/admin/series/[id]', [$self, 'show'], [$can]);
        $r->add('PATCH', '/api/admin/series/[id]', [$self, 'update'], [$can]);
        $r->add('DELETE', '/api/admin/series/[id]', [$self, 'trash'], [$can]);
        $r->get('/api/admin/series/[id]/draft', [$self, 'draftGet'], [$can]);
        $r->add('PUT', '/api/admin/series/[id]/draft', [$self, 'draftPut'], [$can]);
        $r->add('DELETE', '/api/admin/series/[id]/draft', [$self, 'draftDelete'], [$can]);
    }

    private function db(): Db
    {
        return $this->app->db();
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    private function query(Request $req): array
    {
        $scope = $this->catalog->listScope('manage_series', 's.category_id', 's.id');
        if ($scope === null) {
            return ['rows' => [], 'total' => 0];
        }
        [$where, $params] = $scope;
        $where = "s.deleted_at IS NULL AND $where";
        $q = trim((string) ($req->query('q') ?? ''));
        if ($q !== '') {
            $where .= ' AND s.title LIKE ?';
            $params[] = '%' . Db::likeEscape($q) . '%';
        }
        $category = $req->query('categoryId');
        if ($category === 'none') {
            $where .= ' AND s.category_id IS NULL';
        } elseif (is_string($category) && Id::isValid($category)) {
            $where .= ' AND s.category_id = ?';
            $params[] = $category;
        }
        $page = max(1, (int) ($req->query('page') ?? 1));
        $total = (int) $this->db()->value("SELECT COUNT(*) FROM {{series}} s WHERE $where", $params);
        $rows = $this->db()->all(
            "SELECT s.*, c.name AS category_name,
                (SELECT COUNT(*) FROM {{videos}} v WHERE v.series_id = s.id AND v.deleted_at IS NULL) AS video_count,
                (SELECT COUNT(*) FROM {{file_assets}} f WHERE f.series_id = s.id AND f.deleted_at IS NULL) AS file_count
             FROM {{series}} s LEFT JOIN {{categories}} c ON c.id = s.category_id
             WHERE $where ORDER BY c.position IS NULL, c.position, s.pinned DESC, s.position, s.title
             LIMIT " . self::PAGE . ' OFFSET ' . (($page - 1) * self::PAGE),
            $params,
        );
        return ['rows' => $rows, 'total' => $total];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function listItem(array $row): array
    {
        return Presenter::series($row) + [
            'categoryName' => $row['category_name'] ?? null,
            'videoCount' => (int) ($row['video_count'] ?? 0),
            'fileCount' => (int) ($row['file_count'] ?? 0),
        ];
    }

    public function page(Request $req): Response
    {
        $result = $this->query($req);
        return $this->app->page('admin/series', [
            'title' => 'Series',
            'series' => array_map([self::class, 'listItem'], $result['rows']),
            'total' => $result['total'],
            'page' => max(1, (int) ($req->query('page') ?? 1)),
            'perPage' => self::PAGE,
            'q' => (string) ($req->query('q') ?? ''),
            'categoryId' => (string) ($req->query('categoryId') ?? ''),
            'categories' => (new CategoriesAdmin($this->app, $this->catalog))->tree(),
            'canPublish' => $this->catalog->can('publish_content', ['categoryId' => null, 'seriesId' => null]) || $this->app->currentUser()->canAnywhere('publish_content'),
        ], 200, 'layouts/admin');
    }

    public function list(Request $req): Response
    {
        $result = $this->query($req);
        return Response::json(['series' => array_map([self::class, 'listItem'], $result['rows']), 'total' => $result['total']]);
    }

    /** @param array<string, string> $p */
    public function editPage(Request $req, array $p): Response
    {
        $series = $this->catalog->find('series', $p['id']);
        $scope = $this->catalog->scopeOf('series', $series);
        if (!$this->catalog->can('manage_series', $scope)) {
            return \App\Core\ErrorPage::render(403);
        }
        $draft = $this->db()->one('SELECT data, updated_at FROM {{draft_revisions}} WHERE entity_type = ? AND entity_id = ?', ['SERIES', $series['id']]);
        return $this->app->page('admin/series-edit', [
            'title' => 'Edit series',
            'series' => Presenter::series($series),
            'categories' => (new CategoriesAdmin($this->app, $this->catalog))->tree(),
            'draft' => $draft === null ? null : ['data' => json_decode((string) $draft['data'], true), 'updatedAt' => Json::instant((string) $draft['updated_at'])],
            'canPublish' => $this->catalog->can('publish_content', $scope),
            'viewers' => (new ViewersAdmin($this->app, $this->catalog))->listFor('series', (string) $series['id']),
            'groups' => $this->db()->all('SELECT id, name FROM {{permission_groups}} ORDER BY name'),
        ], 200, 'layouts/admin');
    }

    /** @param array<string, string> $p */
    public function show(Request $req, array $p): Response
    {
        $series = $this->catalog->find('series', $p['id']);
        $this->catalog->require('manage_series', $this->catalog->scopeOf('series', $series));
        return Response::json(Presenter::series($series));
    }

    public function create(Request $req): Response
    {
        $data = Fields::read(Fields::SERIES, $req->input(), false);
        $scope = ['categoryId' => $data['categoryId'] ?? null, 'seriesId' => null];
        $this->catalog->require('manage_series', $scope);
        $this->catalog->requirePublishIfTouched($data, $scope);
        if (($data['categoryId'] ?? null) !== null && $this->db()->value('SELECT 1 FROM {{categories}} WHERE id = ? AND deleted_at IS NULL', [$data['categoryId']]) === null) {
            throw ApiError::invalid('That category doesn’t exist.');
        }
        $row = Fields::columns(Fields::SERIES, $data);
        $row['id'] = Id::new();
        $row['slug'] = $this->catalog->uniqueSlug('series', (string) ($data['slug'] ?? $data['title']));
        $row['tags'] ??= [];
        // New series start unpublished unless the creator may and did publish.
        $row['published'] ??= false;
        $row['position'] = $this->catalog->nextPosition('series', $row);
        $this->db()->insert('series', $row);
        $this->catalog->syncSeriesTags($row['id'], $row['tags']);
        $this->catalog->audit('series.create', 'series', $row['id'], (string) $row['title']);
        return Response::json(Presenter::series($this->catalog->find('series', $row['id'])), 201);
    }

    /** @param array<string, string> $p */
    public function update(Request $req, array $p): Response
    {
        $series = $this->catalog->find('series', $p['id']);
        $scope = $this->catalog->scopeOf('series', $series);
        $this->catalog->require('manage_series', $scope);
        $input = $req->input();
        if (isset($input['move'])) {
            $to = $input['move'];
            if (!in_array($to, ['up', 'down'], true) && !is_int($to)) {
                throw ApiError::invalid('move must be up, down or a position.');
            }
            $this->catalog->move('series', $series, $to);
            $this->catalog->audit('series.reorder', 'series', (string) $series['id']);
            return Response::json(Presenter::series($this->catalog->find('series', $p['id'])));
        }
        $data = Fields::read(Fields::SERIES, $input, true);
        $this->catalog->requirePublishIfTouched($data, $scope);
        $row = Fields::columns(Fields::SERIES, $data);
        if (array_key_exists('categoryId', $data) && $data['categoryId'] !== $series['category_id']) {
            if ($data['categoryId'] !== null && $this->db()->value('SELECT 1 FROM {{categories}} WHERE id = ? AND deleted_at IS NULL', [$data['categoryId']]) === null) {
                throw ApiError::invalid('That category doesn’t exist.');
            }
            $this->catalog->require('manage_series', ['categoryId' => $data['categoryId'], 'seriesId' => null]);
            $row['position'] = $this->catalog->nextPosition('series', ['category_id' => $data['categoryId']]);
        }
        if (isset($data['slug']) && $data['slug'] !== $series['slug']) {
            $row['slug'] = $this->catalog->uniqueSlug('series', (string) $data['slug'], (string) $series['id']);
            $this->catalog->recordSlugChange('series', (string) $series['id'], (string) $series['slug'], (string) $row['slug']);
        }
        $this->db()->transaction(function (Db $db) use ($row, $series, $data): void {
            if ($row !== []) {
                $db->update('series', $row, ['id' => $series['id']]);
            }
            if (array_key_exists('tags', $data)) {
                $this->catalog->syncSeriesTags((string) $series['id'], $data['tags']);
            }
            // Publishing what's in the form supersedes whatever was staged.
            $db->run('DELETE FROM {{draft_revisions}} WHERE entity_type = ? AND entity_id = ?', ['SERIES', $series['id']]);
        });
        $fresh = $this->catalog->find('series', $p['id']);
        $this->catalog->audit('series.update', 'series', (string) $series['id'], implode(', ', array_keys($data)));
        if (!\App\Modules\Library\Visibility::isLive($series, new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            && \App\Modules\Library\Visibility::isLive($fresh, new \DateTimeImmutable('now', new \DateTimeZone('UTC')))) {
            $this->app->hooks->do('series.published', $fresh, $this->app);
        }
        $this->app->hooks->do('series.saved', $fresh, $this->app);
        return Response::json(Presenter::series($fresh));
    }

    /** @param array<string, string> $p */
    public function trash(Request $req, array $p): Response
    {
        $series = $this->catalog->find('series', $p['id']);
        $this->catalog->require('manage_series', $this->catalog->scopeOf('series', $series));
        $this->db()->update('series', ['deleted_at' => Db::now()], ['id' => $series['id']]);
        $this->catalog->audit('series.trash', 'series', (string) $series['id'], (string) $series['title']);
        $this->app->hooks->do('series.trashed', $series, $this->app);
        return Response::json(['ok' => true]);
    }

    // Drafts ---------------------------------------------------------------------------

    /** @param array<string, string> $p */
    public function draftGet(Request $req, array $p): Response
    {
        $series = $this->catalog->find('series', $p['id']);
        $this->catalog->require('manage_series', $this->catalog->scopeOf('series', $series));
        $draft = $this->db()->one('SELECT data, updated_at FROM {{draft_revisions}} WHERE entity_type = ? AND entity_id = ?', ['SERIES', $series['id']]);
        return Response::json($draft === null ? null : ['data' => json_decode((string) $draft['data'], true), 'updatedAt' => Json::instant((string) $draft['updated_at'])]);
    }

    /**
     * Stages the form as it stands, without touching the live series: one
     * pending draft per series, replaced rather than versioned.
     *
     * @param array<string, string> $p
     */
    public function draftPut(Request $req, array $p): Response
    {
        $series = $this->catalog->find('series', $p['id']);
        $this->catalog->require('manage_series', $this->catalog->scopeOf('series', $series));
        $data = Fields::read(Fields::SERIES, $req->input(), true);
        $stored = [];
        foreach ($data as $key => $value) {
            $stored[$key] = $value instanceof \DateTimeImmutable ? $value->format('Y-m-d\TH:i:s.v\Z') : $value;
        }
        $this->db()->run(
            'INSERT INTO {{draft_revisions}} (id, entity_type, entity_id, data) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data)',
            [Id::new(), 'SERIES', $series['id'], json_encode($stored, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
        );
        $this->catalog->audit('series.draft', 'series', (string) $series['id']);
        return $this->draftGet($req, $p);
    }

    /** @param array<string, string> $p */
    public function draftDelete(Request $req, array $p): Response
    {
        $series = $this->catalog->find('series', $p['id']);
        $this->catalog->require('manage_series', $this->catalog->scopeOf('series', $series));
        $this->db()->run('DELETE FROM {{draft_revisions}} WHERE entity_type = ? AND entity_id = ?', ['SERIES', $series['id']]);
        return Response::json(null);
    }
}
