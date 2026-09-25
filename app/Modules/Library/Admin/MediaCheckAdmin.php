<?php

declare(strict_types=1);

namespace App\Modules\Library\Admin;

use App\Core\ApiError;
use App\Core\App;
use App\Core\Db;
use App\Core\Http;
use App\Core\HttpException;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Services\Files\FilesProvider;
use App\Services\Video\LocalVideoProvider;
use App\Services\Video\VideoRef;

/**
 * /admin/media-check: what in the library can't play or can't be found,
 * within the reader's part of it — videos whose service is gone, host-disk
 * videos whose file is missing, videos stuck processing or failed, failed
 * transcriptions, files missing from local storage — and, on request, the
 * pasted links that no longer answer. An administrator also sees video
 * files on the host's disk that no video uses, and may delete them.
 */
final class MediaCheckAdmin
{
    public const LIMIT = 200;
    public const STUCK_HOURS = 24;
    /** An unreferenced file younger than this may be an upload finishing now. */
    public const ORPHAN_GRACE = 3600;
    public const LINK_BATCH = 25;
    private const LINK_PROVIDERS = ['direct', 'dropbox'];

    public function __construct(private readonly App $app, private readonly Catalog $catalog)
    {
    }

    public static function register(Router $r, App $app): void
    {
        $self = new self($app, new Catalog($app));
        $can = Middleware::can($app, 'manage_videos', anywhere: true);
        $r->get('/admin/media-check', [$self, 'page'], [$can]);
        $r->get('/api/admin/media-check', [$self, 'report'], [$can]);
        $r->post('/api/admin/media-check/links', [$self, 'links'], [$can]);
        $r->add('DELETE', '/api/admin/media-check/orphans/[name]', [$self, 'deleteOrphan'], [Middleware::admin($app)]);
    }

    private function db(): Db
    {
        return $this->app->db();
    }

    public function page(Request $req): Response
    {
        return $this->app->page('admin/media-check', ['title' => 'Media check'] + $this->build(), 200, 'layouts/admin');
    }

    public function report(Request $req): Response
    {
        return Response::json($this->build());
    }

    /** @return array<string, mixed> */
    private function build(): array
    {
        $videoScope = $this->catalog->listScope('manage_videos', 'COALESCE(v.category_id, s.category_id)', 'v.series_id');
        $fileScope = $this->catalog->listScope('manage_files', 'COALESCE(f.category_id, s.category_id)', 'f.series_id');
        $admin = $this->app->currentUser()->isAdmin();
        return [
            'videos' => $videoScope === null ? [] : $this->videoProblems($videoScope),
            'files' => $fileScope === null ? [] : $this->missingFiles($fileScope),
            'orphans' => $admin ? $this->orphans() : null,
            'linkCount' => $videoScope === null ? 0 : (int) $this->db()->value(
                'SELECT COUNT(*) FROM {{videos}} v LEFT JOIN {{series}} s ON s.id = v.series_id WHERE v.deleted_at IS NULL AND v.provider IN (' . self::marks(self::LINK_PROVIDERS) . ') AND ' . $videoScope[0],
                [...self::LINK_PROVIDERS, ...$videoScope[1]],
            ),
        ];
    }

    /** @param list<mixed> $values */
    private static function marks(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }

    /**
     * @param array{0: string, 1: list<string>} $scope
     * @return list<array{id: string, title: string, provider: string, problem: string, detail: string}>
     */
    private function videoProblems(array $scope): array
    {
        [$where, $params] = $scope;
        $stuckBefore = Db::datetime(new \DateTimeImmutable('-' . self::STUCK_HOURS . ' hours'));
        $rows = $this->db()->all(
            "SELECT v.id, v.title, v.provider, v.external_id, v.status, v.updated_at, v.transcript_status, v.transcript_error, v.provider_data
             FROM {{videos}} v LEFT JOIN {{series}} s ON s.id = v.series_id
             WHERE v.deleted_at IS NULL AND $where
               AND (v.status = 'FAILED' OR (v.status = 'PROCESSING' AND v.updated_at < ?) OR v.transcript_status = 'FAILED' OR v.provider = 'local' OR v.provider NOT IN (" . self::marks($known = array_keys($this->app->services()->providers('video'))) . '))
             ORDER BY v.title LIMIT ' . (self::LIMIT * 5),
            [...$params, $stuckBefore, ...$known],
        );
        $out = [];
        foreach ($rows as $v) {
            $problem = null;
            $detail = '';
            if (!in_array($v['provider'], $known, true)) {
                [$problem, $detail] = ['service', 'Its service, “' . $v['provider'] . '”, isn’t installed any more.'];
            } elseif ($v['provider'] === 'local' && $v['status'] === 'READY' && LocalVideoProvider::filePath(VideoRef::fromRow($v)) === null) {
                [$problem, $detail] = ['file', 'Its file isn’t on this host’s disk.'];
            } elseif ($v['status'] === 'FAILED') {
                [$problem, $detail] = ['failed', 'The service reports the video failed.'];
            } elseif ($v['status'] === 'PROCESSING' && (string) $v['updated_at'] < $stuckBefore) {
                [$problem, $detail] = ['stuck', 'Processing since ' . substr((string) $v['updated_at'], 0, 16) . ' UTC.'];
            } elseif ($v['transcript_status'] === 'FAILED') {
                [$problem, $detail] = ['transcript', (string) ($v['transcript_error'] ?? 'The transcription failed.')];
            }
            if ($problem !== null) {
                $out[] = ['id' => (string) $v['id'], 'title' => (string) $v['title'], 'provider' => (string) $v['provider'], 'problem' => $problem, 'detail' => $detail];
            }
            if (count($out) >= self::LIMIT) {
                break;
            }
        }
        return $out;
    }

    /**
     * Files on local storage whose object is gone. Other backends are asked
     * only by their own screens: a remote check per file is too slow here.
     *
     * @param array{0: string, 1: list<string>} $scope
     * @return list<array{id: string, title: string, path: string}>
     */
    private function missingFiles(array $scope): array
    {
        [$where, $params] = $scope;
        $provider = $this->app->services()->get('files', 'local');
        if (!$provider instanceof FilesProvider) {
            return [];
        }
        $out = [];
        foreach ($this->db()->all(
            "SELECT f.id, f.title, f.storage_path FROM {{file_assets}} f LEFT JOIN {{series}} s ON s.id = f.series_id
             WHERE f.deleted_at IS NULL AND f.backend = 'local' AND f.storage_path <> '' AND $where ORDER BY f.title LIMIT 5000",
            $params,
        ) as $f) {
            if (!$provider->exists((string) $f['storage_path'])) {
                $out[] = ['id' => (string) $f['id'], 'title' => (string) $f['title'], 'path' => (string) $f['storage_path']];
                if (count($out) >= self::LIMIT) {
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * Video files on this host that no video row names (trashed ones count
     * as named: restoring them needs the file).
     *
     * @return list<array{name: string, size: int, modified: int, public: bool, deletable: bool}>
     */
    private function orphans(): array
    {
        $used = array_flip(array_map('strval', $this->db()->column("SELECT external_id FROM {{videos}} WHERE provider = 'local'")));
        $out = [];
        foreach ([false => LocalVideoProvider::privatePath(''), true => LocalVideoProvider::publicPath('')] as $public => $dir) {
            foreach (glob(rtrim($dir, '/') . '/*') ?: [] as $path) {
                $name = basename($path);
                if (!is_file($path) || !LocalVideoProvider::isName($name) || isset($used[$name])) {
                    continue;
                }
                $modified = (int) filemtime($path);
                $out[] = ['name' => $name, 'size' => (int) filesize($path), 'modified' => $modified, 'public' => (bool) $public, 'deletable' => $modified < time() - self::ORPHAN_GRACE];
            }
        }
        usort($out, fn ($a, $b) => $b['size'] <=> $a['size']);
        return $out;
    }

    /** @param array<string, string> $p */
    public function deleteOrphan(Request $req, array $p): Response
    {
        $name = $p['name'];
        foreach ($this->orphans() as $o) {
            if ($o['name'] === $name) {
                if (!$o['deletable']) {
                    throw ApiError::invalid('That file changed within the last hour; it may be an upload finishing. Try again later.');
                }
                foreach ([LocalVideoProvider::privatePath($name), LocalVideoProvider::publicPath($name)] as $path) {
                    if (is_file($path) && !@unlink($path)) {
                        throw new ApiError('The file couldn’t be deleted.', 500, 'unwritable');
                    }
                }
                $this->catalog->audit('media.orphan.delete', 'video', $name, sprintf('%.1f MB', $o['size'] / 1048576));
                return Response::json(['ok' => true]);
            }
        }
        throw ApiError::notFound('No unused video file has that name.');
    }

    /**
     * Pasted links, checked a batch at a time (HEAD through the untrusted-URL
     * fetcher): the page asks again with the cursor until there is none.
     */
    public function links(Request $req): Response
    {
        $scope = $this->catalog->listScope('manage_videos', 'COALESCE(v.category_id, s.category_id)', 'v.series_id');
        if ($scope === null) {
            return Response::json(['results' => [], 'next' => null]);
        }
        $after = (string) ($req->input()['after'] ?? '');
        [$where, $params] = $scope;
        $rows = $this->db()->all(
            'SELECT v.id, v.title, v.provider, v.provider_data FROM {{videos}} v LEFT JOIN {{series}} s ON s.id = v.series_id
             WHERE v.deleted_at IS NULL AND v.provider IN (' . self::marks(self::LINK_PROVIDERS) . ") AND v.id > ? AND $where ORDER BY v.id LIMIT " . self::LINK_BATCH,
            [...self::LINK_PROVIDERS, $after, ...$params],
        );
        $deadline = microtime(true) + 20;
        $results = [];
        $last = null;
        foreach ($rows as $v) {
            if (microtime(true) > $deadline) {
                break;
            }
            $last = (string) $v['id'];
            $url = (string) (VideoRef::fromRow($v)->data['url'] ?? '');
            $results[] = ['id' => $last, 'title' => (string) $v['title']] + self::probe($url);
        }
        $next = $last !== null && (count($rows) === self::LINK_BATCH || count($results) < count($rows)) ? $last : null;
        return Response::json(['results' => $results, 'next' => $next]);
    }

    /** @return array{ok: bool, detail: string} */
    public static function probe(string $url): array
    {
        if ($url === '') {
            return ['ok' => false, 'detail' => 'No address is saved for it.'];
        }
        try {
            $r = Http::fetchUntrusted('HEAD', $url);
        } catch (HttpException $e) {
            return ['ok' => false, 'detail' => $e->getMessage()];
        }
        if (!$r->ok()) {
            return ['ok' => false, 'detail' => 'Answered ' . $r->status . '.'];
        }
        $type = strtolower(trim(explode(';', (string) ($r->header('content-type') ?? ''))[0]));
        if ($type !== '' && !str_starts_with($type, 'video/') && !in_array($type, ['application/octet-stream', 'application/binary', 'application/x-mpegurl', 'application/vnd.apple.mpegurl'], true)) {
            return ['ok' => false, 'detail' => 'Answers with ' . $type . ', not a video.'];
        }
        return ['ok' => true, 'detail' => $type !== '' ? $type : 'answers'];
    }
}
