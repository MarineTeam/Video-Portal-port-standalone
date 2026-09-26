<?php

declare(strict_types=1);

namespace App\Modules\Tools;

use App\Core\ApiError;
use App\Core\App;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Modules\Audit\Audit;
use App\Modules\Tools\Import\FilePull;
use App\Modules\Tools\Import\Importer;
use App\Modules\Tools\Import\Mapping;
use App\Modules\Uploads\Uploads;

/**
 * /admin/tools: the database backup and the uploaded files, each downloaded
 * in pieces a shared host can produce. (The import from the Next.js site
 * shares the page.)
 */
final class Routes
{
    public function __construct(private readonly App $app)
    {
    }

    public static function register(Router $r, App $app): void
    {
        $self = new self($app);
        $admin = Middleware::admin($app);
        $r->get('/admin/tools', [$self, 'page'], [$admin]);
        $r->post('/api/admin/tools/backup', [$self, 'backupStart'], [$admin]);
        $r->post('/api/admin/tools/backup/[id]/step', [$self, 'backupStep'], [$admin]);
        $r->get('/api/admin/tools/backup/[id]/download', [$self, 'backupDownload'], [$admin]);
        $r->get('/api/admin/tools/uploads/[part]', [$self, 'uploadsPart'], [$admin]);
        $r->post('/api/admin/tools/import', [$self, 'importStart'], [$admin]);
        $r->post('/api/admin/tools/import/[id]/step', [$self, 'importStep'], [$admin]);
        $r->get('/api/admin/tools/import/[id]', [$self, 'importState'], [$admin]);
        $r->post('/api/admin/tools/pull-files', [$self, 'pullFiles'], [$admin]);
    }

    private function actor(): string
    {
        return (string) $this->app->currentUser()->email();
    }

    public function page(Request $req): Response
    {
        $parts = (new UploadsArchive($this->app))->parts();
        $pull = new FilePull($this->app);
        return $this->app->page('admin/tools', [
            'title' => 'Backup & import',
            'parts' => array_map(fn ($p) => ['files' => count($p['files']), 'bytes' => $p['bytes']], $parts),
            'zip' => class_exists(\ZipArchive::class),
            'tables' => count((new Backup($this->app))->tables()),
            'inBunny' => $pull->needed() ? $pull->pending() : null,
        ], 200, 'layouts/admin');
    }

    public function backupStart(Request $req): Response
    {
        $state = (new Backup($this->app))->start();
        Audit::log($this->app->db(), $this->actor(), 'backup.database', 'Site', $state['id']);
        return Response::json(self::publicState($state), 201);
    }

    /** @param array<string, string> $p */
    public function backupStep(Request $req, array $p): Response
    {
        try {
            return Response::json(self::publicState((new Backup($this->app))->step($p['id'])));
        } catch (\RuntimeException $e) {
            throw ApiError::conflict($e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function publicState(array $state): array
    {
        return [
            'id' => $state['id'],
            'stage' => $state['stage'],
            'table' => $state['table'],
            'tables' => count($state['tables']),
            'rows' => $state['rows'],
            'bytes' => $state['bytes'] ?? 0,
        ];
    }

    // -- The import from the Next.js site ------------------------------------

    public function importStart(Request $req): Response
    {
        $uploadId = (string) ($req->input()['upload'] ?? '');
        $upload = Uploads::take($this->app, $uploadId, 'import');
        try {
            $state = (new Importer($this->app))->start($upload['path']);
        } catch (\RuntimeException $e) {
            throw ApiError::invalid($e->getMessage());
        } finally {
            Uploads::discard($this->app, $uploadId);
        }
        Audit::log($this->app->db(), $this->actor(), 'import.start', 'Site', (string) $state['id']);
        return Response::json(self::importPublic($state), 201);
    }

    /** @param array<string, string> $p */
    public function importStep(Request $req, array $p): Response
    {
        $importer = new Importer($this->app);
        $was = $importer->state($p['id'])['phase'] ?? null;
        try {
            $state = $importer->step($p['id']);
        } catch (\RuntimeException $e) {
            throw ApiError::conflict($e->getMessage());
        }
        // On the step that finishes it, not on every poll after.
        if ($state['phase'] === 'done' && $was !== 'done') {
            Audit::log($this->app->db(), $this->actor(), 'import.finish', 'Site', (string) $state['id']);
        }
        return Response::json(self::importPublic($state));
    }

    /** @param array<string, string> $p */
    public function importState(Request $req, array $p): Response
    {
        $state = (new Importer($this->app))->state($p['id']);
        if ($state === null) {
            throw ApiError::notFound('That import has gone; upload the export again.');
        }
        return Response::json(self::importPublic($state));
    }

    /**
     * Files still in the old site's Bunny Storage, a batch onto this server.
     */
    public function pullFiles(Request $req): Response
    {
        try {
            $result = (new FilePull($this->app))->step();
        } catch (\RuntimeException $e) {
            throw ApiError::invalid($e->getMessage());
        }
        if ($result['moved'] > 0) {
            Audit::log($this->app->db(), $this->actor(), 'import.files', 'Site', (string) $result['moved'] . ' files');
        }
        return Response::json($result);
    }

    /**
     * What the screen is told. The whole state holds byte offsets and a
     * table order nobody needs to read; this is the progress and the counts.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function importPublic(array $state): array
    {
        $models = is_array($state['models']) ? $state['models'] : [];
        $errors = [];
        foreach (is_array($state['errors']) ? $state['errors'] : [] as $model => $list) {
            foreach (is_array($list) ? $list : [] as $one) {
                $errors[] = ['model' => (string) $model] + (array) $one;
            }
        }
        $dropped = [];
        foreach (is_array($state['dropped']) ? $state['dropped'] : [] as $model => $fields) {
            $dropped[] = ['model' => (string) $model, 'fields' => array_values((array) $fields)];
        }
        return [
            'id' => $state['id'],
            'phase' => $state['phase'],
            'at' => $state['at'],
            'models' => count($models),
            'model' => $models[$state['at']] ?? null,
            'exportedAt' => $state['exportedAt'] ?? null,
            'report' => Importer::report($state),
            'errors' => $errors,
            'dropped' => $dropped,
            'skipped' => array_map(
                static fn (string $model) => ['model' => $model, 'why' => Mapping::SKIPPED[$model]],
                array_values((array) ($state['skippedModels'] ?? [])),
            ),
            'unknown' => array_values((array) ($state['unknownModels'] ?? [])),
        ];
    }

    /** @param array<string, string> $p */
    public function backupDownload(Request $req, array $p): Response
    {
        $backup = new Backup($this->app);
        $path = $backup->path($p['id']);
        if ($path === null) {
            throw ApiError::notFound('That backup has already been downloaded or has expired; make another.');
        }
        $name = 'marine-team-' . gmdate('Ymd-Hi') . '.sql.gz';
        return self::sendOnce($path, $name, 'application/gzip', fn () => $backup->delete($p['id']));
    }

    /** @param array<string, string> $p */
    public function uploadsPart(Request $req, array $p): Response
    {
        $index = (int) $p['part'];
        $archive = new UploadsArchive($this->app);
        if ((string) $index !== $p['part'] || $index < 1 || $index > count($archive->parts())) {
            throw ApiError::notFound('That part doesn’t exist any more; reload the page.');
        }
        try {
            $path = $archive->build($index - 1);
        } catch (\RuntimeException $e) {
            throw ApiError::invalid($e->getMessage());
        }
        Audit::log($this->app->db(), $this->actor(), 'backup.uploads', 'Site', 'part ' . $index);
        return self::sendOnce($path, sprintf('marine-team-files-%s-part%02d.zip', gmdate('Ymd'), $index), 'application/zip', fn () => @unlink($path));
    }

    /**
     * Streams a file and then removes it — also when the download is
     * abandoned part way, since PHP still runs its shutdown functions.
     */
    private static function sendOnce(string $path, string $name, string $type, callable $cleanup): Response
    {
        $done = false;
        $clean = static function () use (&$done, $cleanup): void {
            if (!$done) {
                $done = true;
                $cleanup();
            }
        };
        register_shutdown_function($clean);
        return Response::stream(static function () use ($path, $clean): void {
            $fh = fopen($path, 'rb');
            if ($fh !== false) {
                while (!feof($fh)) {
                    echo fread($fh, 1 << 20);
                    flush();
                }
                fclose($fh);
            }
            $clean();
        }, 200, [
            'Content-Type' => $type,
            'Content-Length' => (string) filesize($path),
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
