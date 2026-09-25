<?php

declare(strict_types=1);

namespace App\Modules\Library\Admin;

use App\Core\ApiError;
use App\Core\App;
use App\Core\Db;
use App\Core\Id;
use App\Core\Log;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Validator;
use App\Modules\Files\UploadTypes;
use App\Modules\Library\Presenter;
use App\Modules\Library\Visibility;
use App\Modules\Uploads\Uploads;
use App\Services\Files\FilesProvider;

/**
 * /admin/files and its API: documents, audio and images in the library,
 * uploaded through the site's chunked uploader and stored with the Files
 * slot's provider. Every link to one goes through /api/files/[id]/content.
 * A book's contents, lyrics and text arrive with the book plugins; the Bunny
 * Storage listing and import with the Bunny Storage provider.
 */
final class FilesAdmin
{
    public const PAGE = 50;

    public function __construct(private readonly App $app, private readonly Catalog $catalog)
    {
    }

    public static function register(Router $r, App $app): void
    {
        $self = new self($app, new Catalog($app));
        $can = Middleware::can($app, 'manage_files', anywhere: true);
        $r->get('/admin/files', [$self, 'page'], [$can]);
        $r->get('/api/admin/files', [$self, 'list'], [$can]);
        $r->post('/api/admin/files', [$self, 'create'], [$can]);
        $r->post('/api/admin/files/bulk', [$self, 'bulk'], [$can]);
        $r->add('PATCH', '/api/admin/files/[id]', [$self, 'update'], [$can]);
        $r->add('DELETE', '/api/admin/files/[id]', [$self, 'trash'], [$can]);
        $r->post('/api/admin/files/[id]/replace', [$self, 'replace'], [$can]);
        $r->get('/api/admin/files/bunny-storage', [$self, 'storageList'], [$can]);
        $r->post('/api/admin/files/import', [$self, 'import'], [$can]);
    }

    private function db(): Db
    {
        return $this->app->db();
    }

    /** @return array<string, mixed> the file, once the caller may manage it */
    private function file(string $id): array
    {
        $row = $this->catalog->find('file', $id);
        $this->catalog->require('manage_files', $this->catalog->scopeOf('file', $row));
        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function present(array $row): array
    {
        $out = Presenter::file($row);
        $out['seriesTitle'] = $row['series_title'] ?? null;
        $out['categoryName'] = $row['category_name'] ?? null;
        $out['kind'] = UploadTypes::TYPES[UploadTypes::extensionOf((string) $row['storage_path'])]['kind'] ?? 'other';
        return $out;
    }

    private function provider(): FilesProvider
    {
        $p = $this->app->services()->active('files');
        if (!$p instanceof FilesProvider) {
            throw ApiError::invalid('No file storage is chosen. Pick one under Admin → Services.');
        }
        return $p;
    }

    /** @return array{rows: list<array<string, mixed>>, total: int} */
    private function query(Request $req): array
    {
        $scope = $this->catalog->listScope('manage_files', 'COALESCE(f.category_id, s.category_id)', 'f.series_id');
        if ($scope === null) {
            return ['rows' => [], 'total' => 0];
        }
        [$where, $params] = $scope;
        $where = "f.deleted_at IS NULL AND $where";
        $q = trim((string) ($req->query('q') ?? ''));
        if ($q !== '') {
            $where .= ' AND f.title LIKE ?';
            $params[] = '%' . Db::likeEscape($q) . '%';
        }
        $series = $req->query('seriesId');
        if ($series === 'none') {
            $where .= ' AND f.series_id IS NULL';
        } elseif (is_string($series) && Id::isValid($series)) {
            $where .= ' AND f.series_id = ?';
            $params[] = $series;
        }
        $kind = $req->query('kind');
        if (is_string($kind) && in_array($kind, ['document', 'audio', 'image'], true)) {
            $exts = array_keys(array_filter(UploadTypes::TYPES, fn ($t) => $t['kind'] === $kind));
            $where .= ' AND (' . implode(' OR ', array_fill(0, count($exts), 'f.storage_path LIKE ?')) . ')';
            foreach ($exts as $ext) {
                $params[] = '%.' . $ext;
            }
        }
        $page = max(1, (int) ($req->query('page') ?? 1));
        $from = '{{file_assets}} f LEFT JOIN {{series}} s ON s.id = f.series_id LEFT JOIN {{categories}} c ON c.id = COALESCE(f.category_id, s.category_id)';
        $total = (int) $this->db()->value("SELECT COUNT(*) FROM $from WHERE $where", $params);
        $rows = $this->db()->all(
            "SELECT f.*, s.title AS series_title, c.name AS category_name FROM $from WHERE $where
             ORDER BY f.series_id IS NULL, s.title, f.position, f.created_at DESC LIMIT " . self::PAGE . ' OFFSET ' . (($page - 1) * self::PAGE),
            $params,
        );
        return ['rows' => $rows, 'total' => $total];
    }

    public function page(Request $req): Response
    {
        $result = $this->query($req);
        return $this->app->page('admin/files', [
            'title' => 'Files',
            'files' => array_map([$this, 'present'], $result['rows']),
            'total' => $result['total'],
            'page' => max(1, (int) ($req->query('page') ?? 1)),
            'perPage' => self::PAGE,
            'q' => (string) ($req->query('q') ?? ''),
            'seriesId' => (string) ($req->query('seriesId') ?? ''),
            'kind' => (string) ($req->query('kind') ?? ''),
            'series' => $this->db()->all('SELECT id, title FROM {{series}} WHERE deleted_at IS NULL ORDER BY title'),
            'categories' => (new CategoriesAdmin($this->app, $this->catalog))->tree(),
            'storedWith' => ($p = $this->app->services()->active('files')) === null ? null : $p::label(),
            'canPublish' => $this->catalog->can('publish_content', ['categoryId' => null, 'seriesId' => null]),
            'bunnyStorage' => $this->app->services()->savedConfig('files', 'bunny') !== [],
            'podcastZone' => \App\Modules\Library\PodcastMirror::provider($this->app) !== null,
            'accept' => implode(',', array_map(fn ($e) => '.' . $e, array_keys(array_filter(UploadTypes::TYPES, fn ($t) => in_array($t['kind'], UploadTypes::PURPOSES['file'], true))))),
        ], 200, 'layouts/admin');
    }

    public function list(Request $req): Response
    {
        $result = $this->query($req);
        return Response::json(['files' => array_map([$this, 'present'], $result['rows']), 'total' => $result['total']]);
    }

    /**
     * Stores a finished chunked upload with the Files provider.
     *
     * @return array{backend: string, storage_path: string, size_bytes: int, mime_type: string}
     */
    private function store(string $uploadId, string $fileId): array
    {
        $provider = $this->provider();
        $upload = Uploads::take($this->app, $uploadId, 'file');
        try {
            $object = UploadTypes::objectName($fileId, $upload['fileName']) ?? throw ApiError::invalid(UploadTypes::refusal('file'));
            $type = UploadTypes::uploadType($upload['fileName'], 'file') ?? throw ApiError::invalid(UploadTypes::refusal('file'));
            if (!UploadTypes::bytesMatch($upload['path'], $type['ext'])) {
                throw ApiError::invalid('That file’s contents don’t match its name (.' . $type['ext'] . ').');
            }
            try {
                $provider->put($upload['path'], $object);
            } catch (\Throwable $e) {
                Log::warning('File store failed: ' . $e->getMessage());
                throw new ApiError('The file couldn’t be stored: ' . $e->getMessage(), 502, 'provider_error');
            }
            return ['backend' => $provider::id(), 'storage_path' => $object, 'size_bytes' => $upload['size'], 'mime_type' => $type['type']];
        } finally {
            Uploads::discard($this->app, $uploadId);
        }
    }

    public function create(Request $req): Response
    {
        $input = $req->input();
        $data = Validator::check($input, [
            'upload' => ['string', 'required', 'max' => 40],
            'title' => ['string', 'max' => 255],
            'seriesId' => ['id', 'nullable'],
            'categoryId' => ['id', 'nullable'],
            'published' => ['bool'],
            'memberOnly' => ['bool'],
        ]);
        $seriesId = $data['seriesId'] ?? null;
        if ($seriesId !== null && $this->db()->value('SELECT 1 FROM {{series}} WHERE id = ? AND deleted_at IS NULL', [$seriesId]) === null) {
            throw ApiError::invalid('That series doesn’t exist.');
        }
        $categoryId = $seriesId === null ? ($data['categoryId'] ?? null) : null;
        if ($categoryId !== null && $this->db()->value('SELECT 1 FROM {{categories}} WHERE id = ? AND deleted_at IS NULL', [$categoryId]) === null) {
            throw ApiError::invalid('That category doesn’t exist.');
        }
        $scope = $this->catalog->scopeOf('file', ['series_id' => $seriesId, 'category_id' => $categoryId]);
        $this->catalog->require('manage_files', $scope);
        $published = $data['published'] ?? true;
        if ($published) {
            $this->catalog->requirePublishIfTouched(['published' => true], $scope);
        }
        $id = Id::new();
        $upload = (string) $data['upload'];
        $fileName = (string) ($this->db()->value('SELECT file_name FROM {{uploads}} WHERE id = ?', [$upload]) ?? '');
        $title = trim((string) ($data['title'] ?? '')) !== '' ? (string) $data['title'] : \App\Support\Filename::titleFromFilename($fileName);
        $stored = $this->store($upload, $id);
        $row = ['series_id' => $seriesId, 'category_id' => $categoryId];
        $this->db()->insert('file_assets', $stored + $row + [
            'id' => $id,
            'title' => mb_substr($title !== '' ? $title : 'Untitled file', 0, 255),
            'url' => \App\Core\Url::to('/api/files/' . $id . '/content'),
            'published' => $published,
            'member_only' => $data['memberOnly'] ?? false,
            'position' => $this->catalog->nextPosition('file', $row),
        ]);
        $file = $this->catalog->find('file', $id);
        $this->catalog->audit('file.create', 'file', $id, (string) $file['title']);
        $this->app->hooks->do('file.saved', $file, $this->app);
        return Response::json(['id' => $id, 'file' => $this->present($file)], 201);
    }

    /** @param array<string, string> $p */
    public function update(Request $req, array $p): Response
    {
        $file = $this->file($p['id']);
        $scope = $this->catalog->scopeOf('file', $file);
        $input = $req->input();
        if (isset($input['move'])) {
            $to = $input['move'];
            if (!in_array($to, ['up', 'down'], true) && !is_int($to)) {
                throw ApiError::invalid('move must be up, down or a position.');
            }
            $this->catalog->move('file', $file, $to);
            return Response::json($this->present($this->catalog->find('file', $p['id'])));
        }
        $data = Fields::read(Fields::FILE, $input, true);
        $this->catalog->requirePublishIfTouched($data, $scope);
        $row = Fields::columns(Fields::FILE, $data);
        if (array_key_exists('seriesId', $data) || array_key_exists('categoryId', $data)) {
            $seriesId = array_key_exists('seriesId', $data) ? $data['seriesId'] : $file['series_id'];
            if ($seriesId !== null && $this->db()->value('SELECT 1 FROM {{series}} WHERE id = ? AND deleted_at IS NULL', [$seriesId]) === null) {
                throw ApiError::invalid('That series doesn’t exist.');
            }
            $target = ['series_id' => $seriesId, 'category_id' => $seriesId === null ? ($data['categoryId'] ?? $file['category_id']) : null];
            $this->catalog->require('manage_files', $this->catalog->scopeOf('file', $target));
            $row = $target + $row;
            $row['position'] = $this->catalog->nextPosition('file', $target);
        }
        if ($row !== []) {
            $this->db()->update('file_assets', $row, ['id' => $file['id']]);
        }
        $fresh = $this->catalog->find('file', $p['id']);
        $this->catalog->audit('file.update', 'file', (string) $file['id'], implode(', ', array_keys($data)));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if (!Visibility::isLive($file, $now) && Visibility::isLive($fresh, $now)) {
            $this->app->hooks->do('file.published', $fresh, $this->app);
        }
        $this->app->hooks->do('file.saved', $fresh, $this->app);
        return Response::json($this->present($fresh));
    }

    /** @param array<string, string> $p */
    public function trash(Request $req, array $p): Response
    {
        $file = $this->file($p['id']);
        $this->db()->update('file_assets', ['deleted_at' => Db::now()], ['id' => $file['id']]);
        $this->catalog->audit('file.trash', 'file', (string) $file['id'], (string) $file['title']);
        $this->app->hooks->do('file.trashed', $file, $this->app);
        return Response::json(['ok' => true]);
    }

    /**
     * New bytes for the same row (a re-scanned book keeps its id, links and
     * indexes). The old object goes once the new one is stored.
     *
     * @param array<string, string> $p
     */
    public function replace(Request $req, array $p): Response
    {
        $file = $this->file($p['id']);
        $data = Validator::check($req->input(), ['upload' => ['string', 'nullable', 'max' => 40], 'storagePath' => ['string', 'nullable', 'max' => 1000]]);
        if (($data['storagePath'] ?? null) !== null) {
            // A big scan uploaded to Bunny Storage directly, chosen from its listing.
            $stored = $this->fromStorage((string) $data['storagePath']);
        } elseif (($data['upload'] ?? null) !== null) {
            // A fresh object name, so a cached copy of the old bytes can't answer for the new.
            $stored = $this->store((string) $data['upload'], Id::new());
        } else {
            throw ApiError::invalid('Upload a file, or choose one from storage.');
        }
        $this->db()->update('file_assets', $stored + ['contents_indexed_at' => null, 'text_indexed_at' => null, 'cover_data_url' => null], ['id' => $file['id']]);
        // Only an object this site wrote goes: an imported one is the zone owner's.
        if ($file['storage_path'] !== $stored['storage_path'] && str_starts_with((string) $file['storage_path'], 'files/')) {
            try {
                FileAssets::delete($this->app, $file);
            } catch (ApiError $e) {
                Log::warning('Replaced file’s old object stayed: ' . $e->getMessage());
            }
        }
        $fresh = $this->catalog->find('file', $p['id']);
        $this->catalog->audit('file.replace', 'file', (string) $file['id'], (string) $file['title']);
        $this->app->hooks->do('file.replaced', $fresh, $this->app);
        return Response::json($this->present($fresh));
    }

    // Bunny Storage: what is already in the zone ---------------------------------------------

    private function bunny(): \App\Services\Files\BunnyStorageProvider
    {
        $p = $this->app->services()->get('files', 'bunny');
        if (!$p instanceof \App\Services\Files\BunnyStorageProvider || $this->app->services()->savedConfig('files', 'bunny') === []) {
            throw ApiError::invalid('Bunny Storage isn’t set up under Services.');
        }
        return $p;
    }

    /**
     * A row's storage columns for an object already in the zone.
     *
     * @return array{backend: string, storage_path: string, size_bytes: ?int, mime_type: string}
     */
    private function fromStorage(string $path): array
    {
        $path = ltrim($path, '/');
        if ($path === '' || str_contains($path, '..') || preg_match('/[\x00-\x1f]/', $path)) {
            throw ApiError::invalid('That isn’t a file in storage.');
        }
        $type = UploadTypes::uploadType(basename($path), 'file') ?? throw ApiError::invalid(UploadTypes::refusal('file'));
        $dir = dirname($path) === '.' ? '' : dirname($path);
        $size = null;
        try {
            foreach ($this->bunny()->list($dir) as $entry) {
                if (!$entry['isDirectory'] && $entry['path'] === $path) {
                    $size = $entry['size'];
                }
            }
        } catch (\RuntimeException $e) {
            throw new ApiError($e->getMessage(), 502, 'provider_error');
        }
        if ($size === null) {
            throw ApiError::notFound('There’s no file at ' . $path . ' in the storage zone.');
        }
        return ['backend' => 'bunny', 'storage_path' => $path, 'size_bytes' => $size, 'mime_type' => $type['type']];
    }

    public function storageList(Request $req): Response
    {
        $this->catalog->require('manage_files', ['categoryId' => null, 'seriesId' => null]);
        $dir = trim((string) ($req->query('dir') ?? ''), '/');
        if (str_contains($dir, '..')) {
            throw ApiError::invalid('Bad folder.');
        }
        try {
            $entries = $this->bunny()->list($dir);
        } catch (\RuntimeException $e) {
            throw new ApiError($e->getMessage(), 502, 'provider_error');
        }
        $paths = array_column(array_filter($entries, fn ($e) => !$e['isDirectory']), 'path');
        $known = $paths === [] ? [] : array_flip(array_map('strval', $this->db()->column(
            "SELECT storage_path FROM {{file_assets}} WHERE backend = 'bunny' AND storage_path IN (" . implode(', ', array_fill(0, count($paths), '?')) . ')',
            $paths,
        )));
        return Response::json(['dir' => $dir, 'entries' => array_map(fn ($e) => $e + [
            'imported' => isset($known[$e['path']]),
            'importable' => $e['isDirectory'] || UploadTypes::uploadType($e['name'], 'file') !== null,
        ], $entries)]);
    }

    public function import(Request $req): Response
    {
        $data = Validator::check($req->input(), [
            'paths' => ['array', 'required', 'max' => 200, 'of' => 'string', 'each' => ['max' => 1000]],
            'seriesId' => ['id', 'nullable'],
            'categoryId' => ['id', 'nullable'],
            'published' => ['bool'],
        ]);
        $seriesId = $data['seriesId'] ?? null;
        $categoryId = $seriesId === null ? ($data['categoryId'] ?? null) : null;
        $placement = ['series_id' => $seriesId, 'category_id' => $categoryId];
        $scope = $this->catalog->scopeOf('file', $placement);
        $this->catalog->require('manage_files', $scope);
        $published = $data['published'] ?? false;
        if ($published) {
            $this->catalog->requirePublishIfTouched(['published' => true], $scope);
        }
        $made = 0;
        $skipped = [];
        foreach (array_unique($data['paths']) as $path) {
            if ($this->db()->value("SELECT 1 FROM {{file_assets}} WHERE backend = 'bunny' AND storage_path = ?", [$path]) !== null) {
                $skipped[] = $path;
                continue;
            }
            try {
                $stored = $this->fromStorage((string) $path);
            } catch (ApiError $e) {
                $skipped[] = $path;
                continue;
            }
            $id = Id::new();
            $this->db()->insert('file_assets', $stored + $placement + [
                'id' => $id,
                'title' => mb_substr(\App\Support\Filename::titleFromFilename(basename((string) $path)) ?: 'Untitled file', 0, 255),
                'url' => \App\Core\Url::to('/api/files/' . $id . '/content'),
                'published' => $published,
                'position' => $this->catalog->nextPosition('file', $placement),
            ]);
            $made++;
        }
        $this->catalog->audit('file.import', 'file', 'bunny', "$made imported");
        return Response::json(['imported' => $made, 'skipped' => $skipped]);
    }

    public function bulk(Request $req): Response
    {
        $data = Validator::check($req->input(), [
            'ids' => ['array', 'required', 'max' => 500, 'of' => 'id'],
            'action' => ['enum', 'required', 'enum' => ['publish', 'unpublish', 'delete', 'move', 'podcast', 'unpodcast']],
            'seriesId' => ['id', 'nullable'],
        ]);
        $done = 0;
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        foreach (array_unique($data['ids']) as $id) {
            $file = $this->file((string) $id);
            $scope = $this->catalog->scopeOf('file', $file);
            $update = match ($data['action']) {
                'publish' => ['published' => true],
                'unpublish' => ['published' => false],
                'delete' => ['deleted_at' => Db::now()],
                'podcast' => ['podcast_published' => true],
                'unpodcast' => ['podcast_published' => false],
                default => ['series_id' => $data['seriesId'] ?? null], // move
            };
            if (in_array($data['action'], ['publish', 'unpublish'], true)) {
                $this->catalog->requirePublishIfTouched(['published' => true], $scope);
            }
            if ($data['action'] === 'move') {
                $target = ['series_id' => $data['seriesId'] ?? null, 'category_id' => $file['category_id']];
                $this->catalog->require('manage_files', $this->catalog->scopeOf('file', $target));
                $update['position'] = $this->catalog->nextPosition('file', $target);
            }
            $this->db()->update('file_assets', $update, ['id' => $file['id']]);
            $fresh = $this->catalog->find('file', (string) $id, withTrashed: true);
            if (!Visibility::isLive($file, $now) && Visibility::isLive($fresh, $now)) {
                $this->app->hooks->do('file.published', $fresh, $this->app);
            }
            $done++;
        }
        $this->catalog->audit('file.bulk', 'file', 'bulk', $data['action'] . ' × ' . $done);
        return Response::json(['updated' => $done]);
    }
}
