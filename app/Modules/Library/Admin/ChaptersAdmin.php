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
use App\Core\Validator;
use App\Support\Timestamp;

/**
 * A video's chapters: named moments the video page lists under the player
 * (the Chapters plugin), each seeking the player and carrying its own
 * share link. Listed in time order.
 */
final class ChaptersAdmin
{
    public const MAX = 200;

    public function __construct(private readonly App $app, private readonly Catalog $catalog)
    {
    }

    public static function register(Router $r, App $app): void
    {
        $self = new self($app, new Catalog($app));
        $can = Middleware::can($app, 'manage_videos', anywhere: true);
        $r->get('/api/admin/videos/[id]/chapters', [$self, 'list'], [$can]);
        $r->post('/api/admin/videos/[id]/chapters', [$self, 'create'], [$can]);
        $r->add('PATCH', '/api/admin/videos/chapters/[id]', [$self, 'update'], [$can]);
        $r->add('DELETE', '/api/admin/videos/chapters/[id]', [$self, 'delete'], [$can]);
    }

    private function db(): Db
    {
        return $this->app->db();
    }

    /** @return list<array<string, mixed>> */
    public static function forVideo(Db $db, string $videoId): array
    {
        return Json::rows('chapters', $db->all('SELECT * FROM {{chapters}} WHERE video_id = ? ORDER BY timestamp_seconds, position', [$videoId]));
    }

    /** @return array<string, mixed> the video, once the caller may manage it */
    private function video(string $id): array
    {
        $row = $this->catalog->find('video', $id);
        $this->catalog->require('manage_videos', $this->catalog->scopeOf('video', $row));
        return $row;
    }

    /** @return array<string, mixed> the chapter, once the caller may manage its video */
    private function chapter(string $id): array
    {
        $row = Id::isValid($id) ? $this->db()->one('SELECT * FROM {{chapters}} WHERE id = ?', [$id]) : null;
        if ($row === null) {
            throw ApiError::notFound();
        }
        $this->video((string) $row['video_id']);
        return $row;
    }

    /**
     * Seconds from timestampSeconds, or from "timestamp" as 1:02:03, 12:03 or 95.
     *
     * @param array<string, mixed> $input
     */
    private static function seconds(array $input, bool $required): ?int
    {
        if (array_key_exists('timestampSeconds', $input)) {
            $s = $input['timestampSeconds'];
            if (!is_int($s) && !(is_string($s) && ctype_digit($s))) {
                throw ApiError::invalid('timestampSeconds must be a whole number of seconds.');
            }
            $seconds = (int) $s;
        } elseif (array_key_exists('timestamp', $input)) {
            $seconds = Timestamp::parse(is_scalar($input['timestamp']) ? (string) $input['timestamp'] : null);
            if ($seconds === null) {
                throw ApiError::invalid('Give the time as minutes:seconds, e.g. 12:03.');
            }
        } elseif ($required) {
            throw ApiError::invalid('Say when the chapter starts.');
        } else {
            return null;
        }
        if ($seconds < 0 || $seconds > 48 * 3600) {
            throw ApiError::invalid('That time is outside any video.');
        }
        return $seconds;
    }

    /** @param array<string, string> $p */
    public function list(Request $req, array $p): Response
    {
        $video = $this->video($p['id']);
        return Response::json(self::forVideo($this->db(), (string) $video['id']));
    }

    /** @param array<string, string> $p */
    public function create(Request $req, array $p): Response
    {
        $video = $this->video($p['id']);
        $input = $req->input();
        $data = Validator::check($input, ['title' => ['string', 'required', 'max' => 255]]);
        $seconds = (int) self::seconds($input, true);
        if ((int) $this->db()->value('SELECT COUNT(*) FROM {{chapters}} WHERE video_id = ?', [$video['id']]) >= self::MAX) {
            throw ApiError::invalid('A video can have at most ' . self::MAX . ' chapters.');
        }
        $id = $this->db()->insert('chapters', [
            'video_id' => $video['id'],
            'title' => trim((string) $data['title']),
            'timestamp_seconds' => $seconds,
            'position' => (int) $this->db()->value('SELECT COALESCE(MAX(position), -1) + 1 FROM {{chapters}} WHERE video_id = ?', [$video['id']]),
        ]);
        $this->catalog->audit('chapter.create', 'video', (string) $video['id'], Timestamp::format($seconds) . ' ' . $data['title']);
        return Response::json(Json::row('chapters', (array) $this->db()->one('SELECT * FROM {{chapters}} WHERE id = ?', [$id])), 201);
    }

    /** @param array<string, string> $p */
    public function update(Request $req, array $p): Response
    {
        $chapter = $this->chapter($p['id']);
        $input = $req->input();
        $data = Validator::check($input, ['title' => ['string', 'required', 'max' => 255]], partial: true);
        $row = [];
        if (isset($data['title'])) {
            $row['title'] = trim((string) $data['title']);
        }
        $seconds = self::seconds($input, false);
        if ($seconds !== null) {
            $row['timestamp_seconds'] = $seconds;
        }
        if ($row !== []) {
            $this->db()->update('chapters', $row, ['id' => $chapter['id']]);
        }
        $this->catalog->audit('chapter.update', 'video', (string) $chapter['video_id'], (string) ($row['title'] ?? $chapter['title']));
        return Response::json(Json::row('chapters', (array) $this->db()->one('SELECT * FROM {{chapters}} WHERE id = ?', [$chapter['id']])));
    }

    /** @param array<string, string> $p */
    public function delete(Request $req, array $p): Response
    {
        $chapter = $this->chapter($p['id']);
        $this->db()->delete('chapters', ['id' => $chapter['id']]);
        $this->catalog->audit('chapter.delete', 'video', (string) $chapter['video_id'], (string) $chapter['title']);
        return Response::json(['ok' => true]);
    }
}
