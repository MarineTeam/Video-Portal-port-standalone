<?php

declare(strict_types=1);

namespace App\Modules\Library;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Services\Files\RangeStreamer;
use App\Services\Video\LocalVideoProvider;
use App\Services\Video\VideoRef;

/**
 * The public library's routes. So far: a video kept on the host's own disk
 * that not everybody may watch, streamed through PHP after the same access
 * decision its page makes (anyone-may-watch videos are moved under
 * public/media/videos and never come here).
 */
final class Routes
{
    public static function register(Router $r, App $app): void
    {
        $r->get('/api/videos/local/[name]', fn (Request $req, array $p) => self::local($app, $p['name'], $req));
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
