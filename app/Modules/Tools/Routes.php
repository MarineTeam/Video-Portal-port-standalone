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
    }

    private function actor(): string
    {
        return (string) $this->app->currentUser()->email();
    }

    public function page(Request $req): Response
    {
        $parts = (new UploadsArchive($this->app))->parts();
        return $this->app->page('admin/tools', [
            'title' => 'Backup & import',
            'parts' => array_map(fn ($p) => ['files' => count($p['files']), 'bytes' => $p['bytes']], $parts),
            'zip' => class_exists(\ZipArchive::class),
            'tables' => count((new Backup($this->app))->tables()),
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
