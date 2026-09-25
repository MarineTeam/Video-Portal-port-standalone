<?php

declare(strict_types=1);

namespace App\Modules\Library;

use App\Core\App;
use App\Core\Id;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Modules\Files\UploadTypes;
use App\Services\Files\FilesProvider;
use App\Services\Files\RangeStreamer;
use App\Services\Video\LocalVideoProvider;
use App\Services\Video\VideoRef;

/**
 * The public library's routes. So far: a file's bytes, and a video kept on
 * the host's own disk that not everybody may watch — each streamed after the
 * same access decision its page makes, on every request (anyone-may-watch
 * local videos are moved under public/media/videos and never come here).
 */
final class Routes
{
    public static function register(Router $r, App $app): void
    {
        $r->get('/api/videos/local/[name]', fn (Request $req, array $p) => self::local($app, $p['name'], $req));
        $r->get('/api/files/[id]/content', fn (Request $req, array $p) => self::fileContent($app, $p['id'], $req));
    }

    /**
     * The only URL the site hands out for an uploaded file: readers,
     * downloads, audio players and podcast enclosures alike. ?download=1
     * asks for an attachment.
     */
    private static function fileContent(App $app, string $id, Request $req): Response
    {
        $file = Id::isValid($id) ? $app->db()->one('SELECT * FROM {{file_assets}} WHERE id = ? AND deleted_at IS NULL', [$id]) : null;
        if ($file === null) {
            return Response::error('Not found', 404, 'not_found');
        }
        $series = $file['series_id'] !== null ? $app->db()->one('SELECT * FROM {{series}} WHERE id = ?', [$file['series_id']]) : null;
        $decision = ContentAccess::for($app)->file($file, $series);
        if ($decision === ContentAccess::LOGIN) {
            return Response::error('Sign in to open this file.', 401, 'unauthorized');
        }
        if ($decision !== ContentAccess::OK) {
            return Response::error('Not found', 404, 'not_found');
        }
        $provider = $app->services()->get('files', (string) $file['backend']);
        if (!$provider instanceof FilesProvider) {
            return Response::error('This file’s storage isn’t available.', 503, 'provider_missing');
        }
        $object = (string) $file['storage_path'];
        $policy = UploadTypes::servePolicy($object, $req->query('download') === '1');
        $ext = UploadTypes::extensionOf($object);
        $name = trim((string) preg_replace(['~[\x00-\x1f"\\\\/:*?<>|]+~u', '~\s+~u'], ' ', (string) $file['title']));
        $name = ($name !== '' ? mb_substr($name, 0, 150) : 'file') . ($ext !== '' ? ".$ext" : '');
        $ascii = (string) preg_replace('/[^\x20-\x7e]/u', '_', $name);
        return $provider->serve($object, $req, [
            'Content-Type' => $policy['type'],
            'Content-Disposition' => $policy['disposition'] . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name),
            // Access is decided per request, so no shared cache may keep it.
            'Cache-Control' => 'private, no-cache',
        ] + $policy['headers']);
    }

    private static function local(App $app, string $name, Request $req): Response
    {
        if (!LocalVideoProvider::isName($name)) {
            return Response::error('Not found', 404);
        }
        $video = $app->db()->one("SELECT * FROM {{videos}} WHERE provider = 'local' AND external_id = ? AND deleted_at IS NULL", [$name]);
        if ($video === null) {
            return Response::error('Not found', 404);
        }
        $series = $video['series_id'] !== null ? $app->db()->one('SELECT * FROM {{series}} WHERE id = ?', [$video['series_id']]) : null;
        $decision = ContentAccess::for($app)->video($video, $series);
        if ($decision !== ContentAccess::OK) {
            return Response::error($decision === ContentAccess::LOGIN ? 'Sign in to watch this video.' : 'Not found', $decision === ContentAccess::LOGIN ? 401 : 404);
        }
        $data = VideoRef::fromRow($video)->data;
        return RangeStreamer::serve(LocalVideoProvider::privatePath($name), $req, [
            'Content-Type' => (string) ($data['type'] ?? 'video/mp4'),
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
