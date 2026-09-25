<?php

declare(strict_types=1);

namespace App\Modules\Library;

use App\Core\ApiError;
use App\Core\App;
use App\Core\Db;
use App\Core\Http;
use App\Core\HttpException;
use App\Core\Id;
use App\Core\Json;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Validator;
use App\Modules\Audit\Audit;
use App\Services\Video\Links;

/**
 * Admin → Video feeds: a YouTube channel or playlist, or a Vimeo account or
 * showcase, brought in nightly as ordinary youtube and vimeo videos.
 *
 * The sync keeps what the source said (imported_title, imported_description)
 * beside the live fields and only overwrites a field whose live value still
 * equals it, one field at a time — so an edit made here survives every later
 * sync, and an unknown import (null) means leave it alone. An unchanged feed
 * costs one request (its fingerprint matches) and removing a feed keeps the
 * videos it brought in.
 */
final class VideoFeeds
{
    public const KINDS = ['YOUTUBE_CHANNEL', 'YOUTUBE_PLAYLIST', 'VIMEO_USER', 'VIMEO_SHOWCASE'];
    private const YOUTUBE = 'https://www.googleapis.com/youtube/v3';
    private const VIMEO = 'https://api.vimeo.com';

    public function __construct(private readonly App $app)
    {
    }

    public static function register(Router $r, App $app): void
    {
        $self = new self($app);
        $admin = Middleware::admin($app);
        $r->get('/admin/video-feeds', [$self, 'page'], [$admin]);
        $r->get('/api/admin/video-feeds', [$self, 'list'], [$admin]);
        $r->post('/api/admin/video-feeds', [$self, 'create'], [$admin]);
        $r->add('PATCH', '/api/admin/video-feeds/[id]', [$self, 'update'], [$admin]);
        $r->add('DELETE', '/api/admin/video-feeds/[id]', [$self, 'delete'], [$admin]);
        $r->post('/api/admin/video-feeds/[id]/sync', [$self, 'syncNow'], [$admin]);
        $app->hooks->on('jobs.register', function (\App\Modules\Jobs\Scheduler $s) use ($app): void {
            $s->register('sync-video-feeds', 86400, fn (float $deadline) => (new self($app))->syncAll($deadline), 25.0, '07:15');
        });
    }

    // The rules (lib/video-feed-sync.ts) ---------------------------------------------------

    /**
     * Whether a sync may replace a live field: only when nobody has changed
     * it since the last import. No record of the import means don't touch;
     * a null live value counts as empty.
     */
    public static function mayOverwrite(?string $live, ?string $imported): bool
    {
        if ($imported === null) {
            return false;
        }
        return (string) $live === $imported;
    }

    /**
     * The update a sync makes to one video: each field only where it may,
     * and the record of what the source says always.
     *
     * @param array<string, mixed> $row title, description, imported_title, imported_description
     * @param array{title: string, description: ?string} $incoming
     * @return array<string, mixed>
     */
    public static function mergeImported(array $row, array $incoming): array
    {
        $out = [];
        if (self::mayOverwrite($row['title'] ?? null, $row['imported_title'] ?? null)) {
            $out['title'] = mb_substr($incoming['title'], 0, 255);
        }
        if (self::mayOverwrite($row['description'] ?? null, $row['imported_description'] ?? null)) {
            $out['description'] = $incoming['description'];
        }
        $out['imported_title'] = mb_substr($incoming['title'], 0, 255);
        $out['imported_description'] = $incoming['description'] ?? '';
        return $out;
    }

    public static function parseIsoDuration(?string $iso): ?int
    {
        return $iso === null || $iso === '' ? null : Links::isoDuration($iso);
    }

    /**
     * The widest of the thumbnails offered (a card is bigger than a favicon).
     *
     * @param list<array{url?: mixed, width?: mixed}> $thumbnails
     */
    public static function bestThumbnail(array $thumbnails): ?string
    {
        $best = null;
        $bestWidth = -1;
        foreach ($thumbnails as $t) {
            if (!is_string($t['url'] ?? null) || $t['url'] === '') {
                continue;
            }
            $w = is_numeric($t['width'] ?? null) ? (int) $t['width'] : 0;
            if ($w > $bestWidth) {
                $best = $t['url'];
                $bestWidth = $w;
            }
        }
        return $best;
    }

    /** @param list<array<string, mixed>> $items */
    public static function fingerprintFeed(array $items): string
    {
        $parts = array_map(fn ($i) => [$i['externalId'] ?? null, $i['title'] ?? null, $i['description'] ?? null, $i['durationSeconds'] ?? null, $i['thumbnail'] ?? null], $items);
        return hash('sha256', (string) json_encode($parts));
    }

    /** @return 'youtube'|'vimeo' */
    public static function sourceOf(string $kind): string
    {
        return str_starts_with($kind, 'YOUTUBE') ? 'youtube' : 'vimeo';
    }

    // Fetching ---------------------------------------------------------------------------------

    /** What the API needs, or the sentence saying which setting is missing. */
    public function credential(string $kind): ?string
    {
        $source = self::sourceOf($kind);
        $config = $this->app->services()->savedConfig('video', $source);
        $key = (string) ($config[$source === 'youtube' ? 'apiKey' : 'token'] ?? '');
        return $key !== '' ? $key : null;
    }

    public static function missingCredential(string $kind): string
    {
        return self::sourceOf($kind) === 'youtube'
            ? 'Add a YouTube Data API key under Services → Video → YouTube first.'
            : 'Add a Vimeo access token under Services → Video → Vimeo first.';
    }

    /**
     * The newest videos in a feed.
     *
     * @return list<array{externalId: string, title: string, description: ?string, durationSeconds: ?int, thumbnail: ?string, url: string, publishedAt: ?string}>
     */
    public static function fetchFeed(string $kind, string $externalId, int $lookBack, string $credential): array
    {
        $lookBack = max(1, min(50, $lookBack));
        return self::sourceOf($kind) === 'youtube'
            ? self::fetchYouTube($kind, $externalId, $lookBack, $credential)
            : self::fetchVimeo($kind, $externalId, $lookBack, $credential);
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private static function json(string $url, array $headers = []): array
    {
        $r = Http::request('GET', $url, $headers + ['Accept' => 'application/json']);
        $d = $r->json();
        if (!$r->ok() || !is_array($d)) {
            $message = is_array($d) ? (string) ($d['error']['message'] ?? $d['error'] ?? $d['developer_message'] ?? '') : '';
            throw new HttpException(trim('The feed didn’t answer (' . $r->status . '). ' . $message));
        }
        return $d;
    }

    /** @return list<array{externalId: string, title: string, description: ?string, durationSeconds: ?int, thumbnail: ?string, url: string, publishedAt: ?string}> */
    private static function fetchYouTube(string $kind, string $externalId, int $lookBack, string $key): array
    {
        $playlist = $externalId;
        if ($kind === 'YOUTUBE_CHANNEL') {
            $d = self::json(self::YOUTUBE . '/channels?' . http_build_query(['part' => 'contentDetails', 'id' => $externalId, 'key' => $key]));
            $playlist = (string) ($d['items'][0]['contentDetails']['relatedPlaylists']['uploads'] ?? '');
            if ($playlist === '') {
                throw new HttpException('YouTube has no channel with that id.');
            }
        }
        $d = self::json(self::YOUTUBE . '/playlistItems?' . http_build_query(['part' => 'contentDetails', 'playlistId' => $playlist, 'maxResults' => $lookBack, 'key' => $key]));
        $ids = array_values(array_filter(array_map(fn ($i) => (string) ($i['contentDetails']['videoId'] ?? ''), (array) ($d['items'] ?? []))));
        if ($ids === []) {
            return [];
        }
        $d = self::json(self::YOUTUBE . '/videos?' . http_build_query(['part' => 'snippet,contentDetails,status', 'id' => implode(',', $ids), 'key' => $key]));
        $out = [];
        foreach ((array) ($d['items'] ?? []) as $v) {
            // A private or deleted upload still lists in the playlist; it can't be embedded.
            if (($v['status']['privacyStatus'] ?? 'public') === 'private' || !isset($v['id'])) {
                continue;
            }
            $thumbs = [];
            foreach ((array) ($v['snippet']['thumbnails'] ?? []) as $t) {
                $thumbs[] = is_array($t) ? $t : [];
            }
            $out[] = [
                'externalId' => (string) $v['id'],
                'title' => (string) ($v['snippet']['title'] ?? 'Untitled video'),
                'description' => isset($v['snippet']['description']) ? (string) $v['snippet']['description'] : null,
                'durationSeconds' => self::parseIsoDuration($v['contentDetails']['duration'] ?? null),
                'thumbnail' => self::bestThumbnail($thumbs),
                'url' => 'https://www.youtube.com/watch?v=' . $v['id'],
                'publishedAt' => isset($v['snippet']['publishedAt']) ? (string) $v['snippet']['publishedAt'] : null,
            ];
        }
        return $out;
    }

    /** @return list<array{externalId: string, title: string, description: ?string, durationSeconds: ?int, thumbnail: ?string, url: string, publishedAt: ?string}> */
    private static function fetchVimeo(string $kind, string $externalId, int $lookBack, string $token): array
    {
        $path = $kind === 'VIMEO_USER'
            ? '/users/' . rawurlencode($externalId) . '/videos'
            : '/me/albums/' . rawurlencode($externalId) . '/videos';
        $d = self::json(self::VIMEO . $path . '?' . http_build_query(['per_page' => $lookBack, 'sort' => 'date', 'direction' => 'desc', 'fields' => 'uri,name,description,duration,link,created_time,pictures.sizes']), ['Authorization' => 'bearer ' . $token]);
        $out = [];
        foreach ((array) ($d['data'] ?? []) as $v) {
            if (!preg_match('#/videos/(\d+)#', (string) ($v['uri'] ?? ''), $m)) {
                continue;
            }
            $out[] = [
                'externalId' => $m[1],
                'title' => (string) ($v['name'] ?? 'Untitled video'),
                'description' => isset($v['description']) ? (string) $v['description'] : null,
                'durationSeconds' => isset($v['duration']) ? (int) $v['duration'] : null,
                'thumbnail' => self::bestThumbnail(array_map(fn ($s) => ['url' => $s['link'] ?? null, 'width' => $s['width'] ?? null], (array) ($v['pictures']['sizes'] ?? []))),
                'url' => (string) ($v['link'] ?? 'https://vimeo.com/' . $m[1]),
                'publishedAt' => isset($v['created_time']) ? (string) $v['created_time'] : null,
            ];
        }
        return $out;
    }

    // Syncing ----------------------------------------------------------------------------------

    /**
     * One feed. Returns a short status and records it on the feed row.
     *
     * @param array<string, mixed> $feed
     */
    public function sync(array $feed, bool $force = false): string
    {
        $db = $this->app->db();
        $credential = $this->credential((string) $feed['kind']);
        if ($credential === null) {
            $db->update('video_feeds', ['last_synced_at' => Db::now(), 'last_sync_status' => 'error', 'last_error' => self::missingCredential((string) $feed['kind'])], ['id' => $feed['id']]);
            return 'error';
        }
        try {
            $items = self::fetchFeed((string) $feed['kind'], (string) $feed['external_id'], (int) $feed['look_back'], $credential);
        } catch (HttpException $e) {
            $db->update('video_feeds', ['last_synced_at' => Db::now(), 'last_sync_status' => 'error', 'last_error' => mb_substr($e->getMessage(), 0, 2000)], ['id' => $feed['id']]);
            return 'error';
        }
        $fingerprint = self::fingerprintFeed($items);
        if (!$force && $fingerprint === $feed['fingerprint']) {
            $db->update('video_feeds', ['last_synced_at' => Db::now(), 'last_sync_status' => 'unchanged', 'last_error' => null], ['id' => $feed['id']]);
            return 'unchanged';
        }
        $provider = self::sourceOf((string) $feed['kind']);
        $videos = new Videos($this->app);
        $catalog = new Admin\Catalog($this->app);
        $added = $updated = 0;
        foreach ($items as $item) {
            $row = $db->one('SELECT * FROM {{videos}} WHERE provider = ? AND external_id = ?', [$provider, $item['externalId']]);
            if ($row !== null) {
                $update = self::mergeImported($row, $item) + ['duration_seconds' => $item['durationSeconds'] ?? $row['duration_seconds']];
                if ($item['thumbnail'] !== null && $provider === 'vimeo') {
                    $update['external_thumbnail_url'] = $item['thumbnail'];
                }
                $db->update('videos', $update, ['id' => $row['id']]);
                $updated++;
                continue;
            }
            $placement = ['series_id' => $feed['series_id'], 'category_id' => $feed['series_id'] === null ? $feed['category_id'] : null];
            $db->insert('videos', $placement + [
                'id' => Id::new(),
                'title' => mb_substr($item['title'], 0, 255),
                'slug' => $videos->uniqueSlug($item['title']),
                'description' => $item['description'],
                'provider' => $provider,
                'external_id' => $item['externalId'],
                'external_url' => $item['url'],
                'external_thumbnail_url' => $provider === 'youtube' ? 'https://i.ytimg.com/vi/' . rawurlencode($item['externalId']) . '/hqdefault.jpg' : $item['thumbnail'],
                'imported_title' => mb_substr($item['title'], 0, 255),
                'imported_description' => $item['description'] ?? '',
                'provider_data' => [],
                'duration_seconds' => $item['durationSeconds'],
                'status' => 'READY',
                'published' => (bool) $feed['auto_publish'],
                'feed_id' => $feed['id'],
                'position' => $catalog->nextPosition('video', $placement),
                'scripture_refs' => [],
            ]);
            $added++;
        }
        $status = "added $added, checked $updated";
        $db->update('video_feeds', ['last_synced_at' => Db::now(), 'last_sync_status' => 'ok', 'last_error' => null, 'fingerprint' => $fingerprint], ['id' => $feed['id']]);
        return $status;
    }

    public function syncAll(float $deadline): string
    {
        $done = [];
        foreach ($this->app->db()->all('SELECT * FROM {{video_feeds}} WHERE enabled = 1 ORDER BY last_synced_at IS NOT NULL, last_synced_at') as $feed) {
            if (microtime(true) > $deadline) {
                break;
            }
            $done[] = $this->sync($feed);
        }
        return count($done) . ' feeds';
    }

    // Routes ------------------------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    private function find(string $id): ?array
    {
        return Id::isValid($id) ? $this->app->db()->one('SELECT * FROM {{video_feeds}} WHERE id = ?', [$id]) : null;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function present(array $row): array
    {
        $out = Json::row('video_feeds', $row, ['fingerprint']);
        $out['videoCount'] = (int) $this->app->db()->value('SELECT COUNT(*) FROM {{videos}} WHERE feed_id = ? AND deleted_at IS NULL', [$row['id']]);
        $out['missing'] = $this->credential((string) $row['kind']) === null ? self::missingCredential((string) $row['kind']) : null;
        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return array_map([$this, 'present'], $this->app->db()->all('SELECT * FROM {{video_feeds}} ORDER BY name'));
    }

    public function list(Request $req): Response
    {
        return Response::json($this->rows());
    }

    public function page(Request $req): Response
    {
        $db = $this->app->db();
        return $this->app->page('admin/video-feeds', [
            'title' => 'Video feeds',
            'feeds' => $this->rows(),
            'series' => $db->all('SELECT id, title FROM {{series}} WHERE deleted_at IS NULL ORDER BY title'),
            'categories' => (new Admin\CategoriesAdmin($this->app, new Admin\Catalog($this->app)))->tree(),
            'youtubeReady' => $this->credential('YOUTUBE_CHANNEL') !== null,
            'vimeoReady' => $this->credential('VIMEO_USER') !== null,
        ], 200, 'layouts/admin');
    }

    /** @return array<string, array<int|string, mixed>> */
    private static function rules(bool $partial): array
    {
        return [
            'kind' => ['enum', $partial ? 'nullable' : 'required', 'enum' => self::KINDS],
            'externalId' => ['string', $partial ? 'nullable' : 'required', 'max' => 191, 'pattern' => '/^[A-Za-z0-9_-]+$/'],
            'name' => ['string', $partial ? 'nullable' : 'required', 'max' => 255],
            'seriesId' => ['id', 'nullable'],
            'categoryId' => ['id', 'nullable'],
            'autoPublish' => ['bool'],
            'lookBack' => ['int', 'min' => 1, 'max' => 50],
            'enabled' => ['bool'],
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function columns(array $data): array
    {
        $map = ['kind' => 'kind', 'externalId' => 'external_id', 'name' => 'name', 'seriesId' => 'series_id', 'categoryId' => 'category_id', 'autoPublish' => 'auto_publish', 'lookBack' => 'look_back', 'enabled' => 'enabled'];
        $out = [];
        foreach ($data as $k => $v) {
            if (isset($map[$k])) {
                $out[$map[$k]] = $v;
            }
        }
        if (($out['series_id'] ?? null) !== null) {
            $out['category_id'] = null;
        }
        return $out;
    }

    public function create(Request $req): Response
    {
        $data = Validator::check($req->input(), self::rules(false));
        $db = $this->app->db();
        if ($db->value('SELECT 1 FROM {{video_feeds}} WHERE kind = ? AND external_id = ?', [$data['kind'], $data['externalId']]) !== null) {
            throw ApiError::conflict('That feed is already here.');
        }
        $id = Id::new();
        $db->insert('video_feeds', ['id' => $id] + $this->columns($data));
        Audit::log($db, (string) $this->app->currentUser()->email(), 'video_feed.create', 'VideoFeed', $id, $data['kind'] . ' ' . $data['externalId']);
        return Response::json($this->present((array) $this->find($id)), 201);
    }

    /** @param array<string, string> $p */
    public function update(Request $req, array $p): Response
    {
        $feed = $this->find($p['id']) ?? throw ApiError::notFound();
        $data = Validator::check($req->input(), self::rules(true), true);
        $columns = array_filter($this->columns($data), fn ($v, $k) => $v !== null || in_array($k, ['series_id', 'category_id'], true), ARRAY_FILTER_USE_BOTH);
        if ($columns !== []) {
            $this->app->db()->update('video_feeds', $columns + (isset($columns['external_id']) || isset($columns['kind']) ? ['fingerprint' => null] : []), ['id' => $feed['id']]);
        }
        return Response::json($this->present((array) $this->find($p['id'])));
    }

    /**
     * Removing a feed keeps the videos it brought in.
     *
     * @param array<string, string> $p
     */
    public function delete(Request $req, array $p): Response
    {
        $feed = $this->find($p['id']) ?? throw ApiError::notFound();
        $this->app->db()->delete('video_feeds', ['id' => $feed['id']]);
        Audit::log($this->app->db(), (string) $this->app->currentUser()->email(), 'video_feed.delete', 'VideoFeed', (string) $feed['id'], (string) $feed['name']);
        return Response::json(['ok' => true]);
    }

    /** @param array<string, string> $p */
    public function syncNow(Request $req, array $p): Response
    {
        $feed = $this->find($p['id']) ?? throw ApiError::notFound();
        $status = $this->sync($feed, force: true);
        $fresh = $this->present((array) $this->find($p['id']));
        if ($status === 'error') {
            throw new ApiError((string) ($fresh['lastError'] ?? 'The feed couldn’t be synced.'), 502, 'feed_error');
        }
        return Response::json($fresh + ['result' => $status]);
    }
}
