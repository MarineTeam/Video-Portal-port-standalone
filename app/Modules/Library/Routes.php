<?php

declare(strict_types=1);

namespace App\Modules\Library;

use App\Core\ApiError;
use App\Core\App;
use App\Core\Id;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Validator;
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
        Pages::register($r, $app);
        Listings::register($r, $app);
        Feeds::register($r, $app);
        Sharing::register($r, $app);
        Downloads::register($r, $app);
        VideoFeeds::register($r, $app);
        $r->get('/api/videos/local/[name]', fn (Request $req, array $p) => self::local($app, $p['name'], $req));
        $r->get('/api/files/[id]/content', fn (Request $req, array $p) => self::fileContent($app, $p['id'], $req));
        $r->post('/api/view-events', fn (Request $req) => self::viewEvent($app, $req));
        $r->post('/api/watch-progress', fn (Request $req) => self::progress($app, $req, false));
        $r->post('/api/watch-progress/mark-watched', fn (Request $req) => self::progress($app, $req, true));
        $app->hooks->on('jobs.register', function (\App\Modules\Jobs\Scheduler $s) use ($app): void {
            $s->register('sync-video-status', 900, fn (float $deadline) => StatusSync::run($app, $deadline));
            $s->register('local-videos', 300, fn (float $deadline) => 'moved ' . (new Videos($app))->reconcileLocal());
        });
        // Something above a video changed who may see it.
        $app->hooks->on('library.changed', function (string $action, string $type) use ($app): void {
            if (in_array($type, ['category', 'series'], true) || str_starts_with($action, 'viewers.') || $action === 'video.restore') {
                (new Videos($app))->reconcileLocal();
            }
        });
    }

    public const VIEWS_COOKIE = 'mt_views';

    /**
     * The view beacon a series or video page fires once it has loaded. Two
     * throttles, one per browser per item per thirty minutes: a cookie
     * (free, so a repeat view costs no query at all) and, because a script
     * sends no cookie, an HMAC of the caller's address checked against the
     * recent events. A view that passes both is a ViewEvent (for trending and
     * analytics) and one more on the item's view count.
     */
    private static function viewEvent(App $app, Request $req): Response
    {
        $data = Validator::check($req->input(), ['seriesId' => ['id', 'nullable'], 'videoId' => ['id', 'nullable']]);
        $videoId = $data['videoId'] ?? null;
        $seriesId = $videoId === null ? ($data['seriesId'] ?? null) : null;
        $item = $videoId ?? $seriesId;
        if ($item === null) {
            throw ApiError::invalid('Say which series or video was viewed.');
        }
        $now = time();
        $seen = [];
        foreach (explode('.', (string) $req->cookie(self::VIEWS_COOKIE)) as $entry) {
            if (preg_match('/^([a-z0-9]{8,32})-(\d{1,10})$/', $entry, $m) && (int) $m[2] > $now - ViewKey::THROTTLE_SECONDS) {
                $seen[$m[1]] = (int) $m[2];
            }
        }
        if (isset($seen[$item])) {
            return Response::json(['ok' => true, 'counted' => false]);
        }
        $db = $app->db();
        $access = ContentAccess::for($app);
        if ($videoId !== null) {
            $row = $db->one('SELECT * FROM {{videos}} WHERE id = ? AND deleted_at IS NULL', [$videoId]);
            $series = $row !== null && $row['series_id'] !== null ? $db->one('SELECT * FROM {{series}} WHERE id = ?', [$row['series_id']]) : null;
            $ok = $row !== null && $access->video($row, $series) === ContentAccess::OK;
        } else {
            $row = $db->one('SELECT * FROM {{series}} WHERE id = ? AND deleted_at IS NULL', [$seriesId]);
            $ok = $row !== null && $access->series($row) === ContentAccess::OK;
        }
        if (!$ok) {
            throw ApiError::notFound();
        }
        $key = ViewKey::viewKey($req->ip, (string) ($app->config['app_key'] ?? ''));
        $column = $videoId !== null ? 'video_id' : 'series_id';
        $recent = $key !== null && $db->value(
            "SELECT 1 FROM {{view_events}} WHERE ip_hash = ? AND $column = ? AND created_at > ? LIMIT 1",
            [$key, $item, \App\Core\Db::datetime(new \DateTimeImmutable('-' . ViewKey::THROTTLE_SECONDS . ' seconds'))],
        ) !== null;
        if (!$recent) {
            $db->insert('view_events', ['id' => Id::new(), $column => $item, 'user_id' => $access->viewer()->id(), 'ip_hash' => $key]);
            $db->run('UPDATE {{' . ($videoId !== null ? 'videos' : 'series') . '}} SET view_count = view_count + 1 WHERE id = ?', [$item]);
        }
        $seen[$item] = $now;
        arsort($seen);
        $value = implode('.', array_map(fn ($id, $at) => "$id-$at", array_keys(array_slice($seen, 0, 40, true)), array_slice($seen, 0, 40, true)));
        return Response::json(['ok' => true, 'counted' => !$recent])
            ->cookie(self::VIEWS_COOKIE, $value, ['maxAge' => ViewKey::THROTTLE_SECONDS, 'secure' => $req->https]);
    }

    /**
     * The heartbeat (position, and completed once near the end) and the
     * "Mark as watched" toggle. Only the toggle ever clears a completion:
     * a stray heartbeat must not undo one.
     */
    private static function progress(App $app, Request $req, bool $toggle): Response
    {
        $user = $app->currentUser()->user();
        if ($user === null) {
            throw ApiError::unauthorized();
        }
        $data = Validator::check($req->input(), [
            'videoId' => ['id', 'required'],
            'positionSeconds' => ['int', 'min' => 0, 'max' => 1_000_000],
            'completed' => ['bool'],
        ]);
        $video = $app->db()->one('SELECT * FROM {{videos}} WHERE id = ? AND deleted_at IS NULL', [$data['videoId']]);
        $series = $video !== null && $video['series_id'] !== null ? $app->db()->one('SELECT * FROM {{series}} WHERE id = ?', [$video['series_id']]) : null;
        if ($video === null || ContentAccess::for($app)->video($video, $series) !== ContentAccess::OK) {
            throw ApiError::notFound();
        }
        $completed = (bool) ($data['completed'] ?? false);
        $position = (int) ($data['positionSeconds'] ?? 0);
        if ($toggle) {
            $app->db()->run(
                'INSERT INTO {{watch_progresses}} (id, user_id, video_id, position_seconds, completed) VALUES (?, ?, ?, 0, ?)
                 ON DUPLICATE KEY UPDATE completed = VALUES(completed)',
                [Id::new(), $user['id'], $video['id'], $completed ? 1 : 0],
            );
        } else {
            $app->db()->run(
                'INSERT INTO {{watch_progresses}} (id, user_id, video_id, position_seconds, completed) VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE position_seconds = VALUES(position_seconds), completed = GREATEST(completed, VALUES(completed))',
                [Id::new(), $user['id'], $video['id'], $position, $completed ? 1 : 0],
            );
        }
        $app->hooks->do('video.progress', ['videoId' => (string) $video['id'], 'userId' => (string) $user['id'], 'positionSeconds' => $position, 'completed' => $completed, 'toggle' => $toggle], $app);
        return Response::json(['ok' => true]);
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
        ], $app->paths->storage);
    }
}
