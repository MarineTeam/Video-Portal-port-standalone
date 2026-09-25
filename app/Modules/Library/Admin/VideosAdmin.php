<?php

declare(strict_types=1);

namespace App\Modules\Library\Admin;

use App\Core\ApiError;
use App\Core\App;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Id;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Url;
use App\Core\Validator;
use App\Modules\Files\Images;
use App\Modules\Library\Presenter;
use App\Modules\Library\Videos;
use App\Modules\Library\Visibility;
use App\Modules\Uploads\Uploads;
use App\Services\Video\BunnyStreamProvider;
use App\Services\Video\UploadHints;
use App\Services\Video\VideoProviderException;
use App\Services\Video\VideoRef;

/**
 * /admin/videos and its API: the list (scoped, filtered, bulk), adding a
 * video by upload to the default provider or by a pasted link, syncing its
 * status, its thumbnail and captions, and "import from the Bunny library".
 */
final class VideosAdmin
{
    public const PAGE = 50;

    public function __construct(private readonly App $app, private readonly Catalog $catalog, private readonly Videos $videos)
    {
    }

    public static function register(Router $r, App $app): void
    {
        $self = new self($app, new Catalog($app), new Videos($app));
        $can = Middleware::can($app, 'manage_videos', anywhere: true);
        $r->get('/admin/videos', [$self, 'page'], [$can]);
        $r->get('/admin/videos/[id]', [$self, 'editPage'], [$can]);
        $r->get('/api/admin/videos', [$self, 'list'], [$can]);
        $r->post('/api/admin/videos', [$self, 'create'], [$can]);
        $r->post('/api/admin/videos/bulk', [$self, 'bulk'], [$can]);
        $r->get('/api/admin/videos/bunny-library', [$self, 'bunnyLibrary'], [$can]);
        $r->post('/api/admin/videos/import', [$self, 'import'], [$can]);
        $r->add('PATCH', '/api/admin/videos/[id]', [$self, 'update'], [$can]);
        $r->add('DELETE', '/api/admin/videos/[id]', [$self, 'trash'], [$can]);
        $r->post('/api/admin/videos/[id]/sync-status', [$self, 'syncStatus'], [$can]);
        $r->post('/api/admin/videos/[id]/thumbnail', [$self, 'thumbnail'], [$can]);
        $r->get('/api/admin/videos/[id]/captions', [$self, 'captionsList'], [$can]);
        $r->post('/api/admin/videos/[id]/captions', [$self, 'captionsAdd'], [$can]);
        $r->add('DELETE', '/api/admin/videos/[id]/captions', [$self, 'captionsDelete'], [$can]);
    }

    private function db(): Db
    {
        return $this->app->db();
    }

    /** @return array<string, mixed> the video, once the caller may manage it */
    private function video(string $id): array
    {
        $row = $this->catalog->find('video', $id);
        $this->catalog->require('manage_videos', $this->catalog->scopeOf('video', $row));
        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function present(array $row): array
    {
        $out = Presenter::video($row);
        $data = VideoRef::fromRow($row)->data;
        $out['providerLabel'] = ($class = $this->app->services()->providerClass('video', (string) $row['provider'])) !== null ? $class::label() : (string) $row['provider'];
        $out['seriesTitle'] = $row['series_title'] ?? null;
        $out['speakerName'] = $row['speaker_name'] ?? null;
        $out['tracks'] = array_values((array) ($data['tracks'] ?? []));
        return $out;
    }

    /** @return array{rows: list<array<string, mixed>>, total: int} */
    private function query(Request $req): array
    {
        $scope = $this->catalog->listScope('manage_videos', 'COALESCE(v.category_id, s.category_id)', 'v.series_id');
        if ($scope === null) {
            return ['rows' => [], 'total' => 0];
        }
        [$where, $params] = $scope;
        $where = "v.deleted_at IS NULL AND $where";
        $q = trim((string) ($req->query('q') ?? ''));
        if ($q !== '') {
            $where .= ' AND v.title LIKE ?';
            $params[] = '%' . Db::likeEscape($q) . '%';
        }
        $series = $req->query('seriesId');
        if ($series === 'none') {
            $where .= ' AND v.series_id IS NULL';
        } elseif (is_string($series) && Id::isValid($series)) {
            $where .= ' AND v.series_id = ?';
            $params[] = $series;
        }
        $status = $req->query('status');
        if (in_array($status, ['PROCESSING', 'READY', 'FAILED'], true)) {
            $where .= ' AND v.status = ?';
            $params[] = $status;
        }
        $page = max(1, (int) ($req->query('page') ?? 1));
        $from = '{{videos}} v LEFT JOIN {{series}} s ON s.id = v.series_id LEFT JOIN {{speakers}} sp ON sp.id = v.speaker_id';
        $total = (int) $this->db()->value("SELECT COUNT(*) FROM $from WHERE $where", $params);
        $rows = $this->db()->all(
            "SELECT v.*, s.title AS series_title, sp.name AS speaker_name FROM $from WHERE $where
             ORDER BY v.series_id IS NULL, s.title, v.position, v.created_at DESC LIMIT " . self::PAGE . ' OFFSET ' . (($page - 1) * self::PAGE),
            $params,
        );
        return ['rows' => $rows, 'total' => $total];
    }

    public function page(Request $req): Response
    {
        $result = $this->query($req);
        $upload = $this->videos->uploadProvider();
        return $this->app->page('admin/videos', [
            'title' => 'Videos',
            'videos' => array_map([$this, 'present'], $result['rows']),
            'total' => $result['total'],
            'page' => max(1, (int) ($req->query('page') ?? 1)),
            'perPage' => self::PAGE,
            'q' => (string) ($req->query('q') ?? ''),
            'seriesId' => (string) ($req->query('seriesId') ?? ''),
            'series' => $this->db()->all('SELECT id, title FROM {{series}} WHERE deleted_at IS NULL ORDER BY title'),
            'speakers' => $this->db()->all('SELECT id, name FROM {{speakers}} ORDER BY name'),
            'uploadTo' => $upload === null ? null : $upload::label(),
            'status' => (string) ($req->query('status') ?? ''),
            'canPublish' => $this->catalog->can('publish_content', ['categoryId' => null, 'seriesId' => null]),
            'bunny' => $this->app->services()->savedConfig('video', 'bunny') !== [],
        ], 200, 'layouts/admin');
    }

    /** @param array<string, string> $p */
    public function editPage(Request $req, array $p): Response
    {
        $video = $this->catalog->find('video', $p['id']);
        $scope = $this->catalog->scopeOf('video', $video);
        if (!$this->catalog->can('manage_videos', $scope)) {
            return \App\Core\ErrorPage::render(403);
        }
        return $this->app->page('admin/video-edit', [
            'title' => 'Edit video',
            'video' => $this->present($video),
            'series' => $this->db()->all('SELECT id, title FROM {{series}} WHERE deleted_at IS NULL ORDER BY title'),
            'categories' => (new CategoriesAdmin($this->app, $this->catalog))->tree(),
            'speakers' => $this->db()->all('SELECT id, name FROM {{speakers}} ORDER BY name'),
            'canPublish' => $this->catalog->can('publish_content', $scope),
            'viewers' => (new ViewersAdmin($this->app, $this->catalog))->listFor('videos', (string) $video['id']),
            'groups' => $this->db()->all('SELECT id, name FROM {{permission_groups}} ORDER BY name'),
            'thumbnail' => \App\Modules\Library\VideoSource::thumbnailUrl($video),
            'chapters' => ChaptersAdmin::forVideo($this->db(), (string) $video['id']),
            'hasCaptionOps' => $this->videos->providerFor($video)->capabilities()->captions,
            'player' => $video['status'] === 'READY' ? \App\Modules\Library\Player::spec($this->app, $video) : null,
        ], 200, 'layouts/admin');
    }

    public function list(Request $req): Response
    {
        $result = $this->query($req);
        return Response::json(['videos' => array_map([$this, 'present'], $result['rows']), 'total' => $result['total']]);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> columns for a new row */
    private function placement(array $input): array
    {
        $data = Validator::check($input, [
            'title' => ['string', 'max' => 255],
            'seriesId' => ['id', 'nullable'],
            'categoryId' => ['id', 'nullable'],
            'published' => ['bool'],
            'memberOnly' => ['bool'],
        ]);
        $seriesId = $data['seriesId'] ?? null;
        $categoryId = $data['categoryId'] ?? null;
        if ($seriesId !== null && $this->db()->value('SELECT 1 FROM {{series}} WHERE id = ? AND deleted_at IS NULL', [$seriesId]) === null) {
            throw ApiError::invalid('That series doesn’t exist.');
        }
        $scope = ['categoryId' => $categoryId ?? ($seriesId !== null ? $this->db()->value('SELECT category_id FROM {{series}} WHERE id = ?', [$seriesId]) : null), 'seriesId' => $seriesId];
        $this->catalog->require('manage_videos', $scope);
        if (($data['published'] ?? false) === true) {
            $this->catalog->requirePublishIfTouched(['published' => true], $scope);
        }
        $row = ['series_id' => $seriesId, 'category_id' => $seriesId === null ? $categoryId : null, 'published' => $data['published'] ?? false, 'member_only' => $data['memberOnly'] ?? false];
        if (isset($data['title'])) {
            $row['title'] = $data['title'];
        }
        $row['position'] = $this->catalog->nextPosition('video', $row);
        return $row;
    }

    public function create(Request $req): Response
    {
        $input = $req->input();
        $mode = $input['mode'] ?? 'link';
        $row = $this->placement($input);
        if ($mode === 'link') {
            $url = trim((string) ($input['url'] ?? ''));
            if ($url === '' || mb_strlen($url) > 2000) {
                throw ApiError::invalid('Paste the video’s link.');
            }
            $video = $this->videos->afterSave($this->videos->createFromLink($url, $row));
            $this->catalog->audit('video.create', 'video', (string) $video['id'], 'link: ' . $video['provider']);
            return Response::json(['id' => $video['id'], 'video' => $this->present($video)], 201);
        }
        if ($mode !== 'upload') {
            throw ApiError::invalid('mode must be link or upload.');
        }
        $file = Validator::check($input, ['fileName' => ['string', 'required', 'max' => 255], 'size' => ['int', 'required', 'min' => 1], 'mimeType' => ['string', 'max' => 100]]);
        $mime = (string) ($file['mimeType'] ?? 'video/mp4');
        [$video, $ticket] = $this->videos->createUpload($row, new UploadHints((string) $file['fileName'], (int) $file['size'], str_starts_with($mime, 'video/') ? $mime : 'video/mp4', (bool) $row['member_only']));
        $this->catalog->audit('video.create', 'video', (string) $video['id'], 'upload: ' . $video['provider']);
        return Response::json(['video' => $this->present($video), 'upload' => ['kind' => $ticket->kind] + $ticket->client], 201);
    }

    /** @param array<string, string> $p */
    public function syncStatus(Request $req, array $p): Response
    {
        $video = $this->video($p['id']);
        $input = $req->input();
        $report = [];
        if (isset($input['upload']) && is_string($input['upload'])) {
            $report['upload'] = $input['upload'];
        }
        if (isset($input['externalId']) && is_string($input['externalId']) && preg_match('/^[A-Za-z0-9_-]{1,128}$/', $input['externalId'])) {
            $report['externalId'] = $input['externalId'];
        }
        if (isset($input['parts']) && is_array($input['parts'])) {
            $report['parts'] = array_values(array_map(fn ($part) => ['number' => (int) ($part['number'] ?? 0), 'etag' => mb_substr((string) ($part['etag'] ?? ''), 0, 200)], array_filter($input['parts'], 'is_array')));
        }
        if (($input['completed'] ?? false) === true && $report === []) {
            $report['completed'] = true;
        }
        $fresh = $this->videos->sync($video, $report);
        $this->catalog->audit('video.sync', 'video', (string) $video['id'], (string) $fresh['status']);
        return Response::json($this->present($fresh));
    }

    /** @param array<string, string> $p */
    public function update(Request $req, array $p): Response
    {
        $video = $this->video($p['id']);
        $scope = $this->catalog->scopeOf('video', $video);
        $input = $req->input();
        if (isset($input['move'])) {
            $to = $input['move'];
            if (!in_array($to, ['up', 'down'], true) && !is_int($to)) {
                throw ApiError::invalid('move must be up, down or a position.');
            }
            $this->catalog->move('video', $video, $to);
            return Response::json($this->present($this->catalog->find('video', $p['id'])));
        }
        $data = Fields::read(Fields::VIDEO, $input, true);
        $this->catalog->requirePublishIfTouched($data, $scope);
        $row = Fields::columns(Fields::VIDEO, $data);
        if (array_key_exists('seriesId', $data) || array_key_exists('categoryId', $data)) {
            $seriesId = array_key_exists('seriesId', $data) ? $data['seriesId'] : $video['series_id'];
            if ($seriesId !== null && $this->db()->value('SELECT 1 FROM {{series}} WHERE id = ? AND deleted_at IS NULL', [$seriesId]) === null) {
                throw ApiError::invalid('That series doesn’t exist.');
            }
            $target = ['series_id' => $seriesId, 'category_id' => $seriesId === null ? ($data['categoryId'] ?? $video['category_id']) : null];
            $this->catalog->require('manage_videos', $this->catalog->scopeOf('video', $target));
            $row = $target + $row;
            $row['position'] = $this->catalog->nextPosition('video', $target);
        }
        if (($data['speakerId'] ?? null) !== null && $this->db()->value('SELECT 1 FROM {{speakers}} WHERE id = ?', [$data['speakerId']]) === null) {
            throw ApiError::invalid('That speaker doesn’t exist.');
        }
        if (isset($data['slug']) && $data['slug'] !== $video['slug']) {
            $row['slug'] = $this->videos->uniqueSlug((string) $data['slug'], (string) $video['id']);
            $this->catalog->recordSlugChange('video', (string) $video['id'], (string) $video['slug'], (string) $row['slug']);
        }
        if (array_key_exists('scriptureRefs', $data)) {
            $row['scripture_refs'] = array_values(array_filter(array_map('trim', (array) $data['scriptureRefs']), fn ($s) => $s !== ''));
        }
        if ($row !== []) {
            $this->db()->update('videos', $row, ['id' => $video['id']]);
        }
        if (array_key_exists('scripture_refs', $row)) {
            $this->videos->syncScriptureBooks((string) $video['id'], $row['scripture_refs']);
        }
        $fresh = $this->videos->afterSave($this->catalog->find('video', $p['id']));
        $this->catalog->audit('video.update', 'video', (string) $video['id'], implode(', ', array_keys($data)));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if (!Visibility::isLive($video, $now) && Visibility::isLive($fresh, $now)) {
            $this->app->hooks->do('video.published', $fresh, $this->app);
        }
        $this->app->hooks->do('video.saved', $fresh, $this->app);
        return Response::json($this->present($fresh));
    }

    /** @param array<string, string> $p */
    public function trash(Request $req, array $p): Response
    {
        $video = $this->video($p['id']);
        $this->db()->update('videos', ['deleted_at' => Db::now()], ['id' => $video['id']]);
        $this->videos->afterSave($this->catalog->find('video', $p['id'], withTrashed: true));
        $this->catalog->audit('video.trash', 'video', (string) $video['id'], (string) $video['title']);
        $this->app->hooks->do('video.trashed', $video, $this->app);
        return Response::json(['ok' => true]);
    }

    public function bulk(Request $req): Response
    {
        $data = Validator::check($req->input(), [
            'ids' => ['array', 'required', 'max' => 500, 'of' => 'id'],
            'action' => ['enum', 'required', 'enum' => ['publish', 'unpublish', 'delete', 'move', 'schedule', 'expire']],
            'seriesId' => ['id', 'nullable'],
            'publishAt' => ['datetime', 'nullable'],
            'unpublishAt' => ['datetime', 'nullable'],
        ]);
        $done = 0;
        foreach (array_unique($data['ids']) as $id) {
            $video = $this->video((string) $id);
            $scope = $this->catalog->scopeOf('video', $video);
            $update = match ($data['action']) {
                'publish' => ['published' => true],
                'unpublish' => ['published' => false],
                'delete' => ['deleted_at' => Db::now()],
                'schedule' => ['published' => true, 'publish_at' => $data['publishAt'] ?? null],
                'expire' => ['unpublish_at' => $data['unpublishAt'] ?? null],
                default => ['series_id' => $data['seriesId'] ?? null], // move
            };
            if (in_array($data['action'], ['publish', 'unpublish', 'schedule', 'expire'], true)) {
                $this->catalog->requirePublishIfTouched(['published' => true], $scope);
            }
            if ($data['action'] === 'move') {
                $this->catalog->require('manage_videos', $this->catalog->scopeOf('video', ['series_id' => $data['seriesId'] ?? null, 'category_id' => $video['category_id']]));
                $update['position'] = $this->catalog->nextPosition('video', ['series_id' => $data['seriesId'] ?? null, 'category_id' => $video['category_id']]);
            }
            $this->db()->update('videos', $update, ['id' => $video['id']]);
            $fresh = $this->videos->afterSave($this->catalog->find('video', (string) $id, withTrashed: true));
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            if (!Visibility::isLive($video, $now) && Visibility::isLive($fresh, $now)) {
                $this->app->hooks->do('video.published', $fresh, $this->app);
            }
            $done++;
        }
        $this->catalog->audit('video.bulk', 'video', 'bulk', $data['action'] . ' × ' . $done);
        return Response::json(['updated' => $done]);
    }

    // Thumbnails -----------------------------------------------------------------------

    /**
     * A thumbnail uploaded here (or captured from the video in the browser):
     * Bunny is told to take it; for the providers without thumbnails of their
     * own it becomes the poster.
     *
     * @param array<string, string> $p
     */
    public function thumbnail(Request $req, array $p): Response
    {
        $video = $this->video($p['id']);
        $input = $req->input();
        $uploadId = (string) ($input['upload'] ?? '');
        $upload = Uploads::take($this->app, $uploadId, 'image');
        try {
            $name = Images::store($upload['path'], $upload['fileName'], $this->app->paths->storage('media/thumbnails'), maxSide: 1280);
        } finally {
            Uploads::discard($this->app, $uploadId);
        }
        $url = Url::to('/media/thumbnails/' . $name);
        $ref = VideoRef::fromRow($video);
        $update = [];
        if (in_array($video['provider'], ['youtube', 'vimeo'], true)) {
            throw ApiError::invalid('This video’s thumbnail comes from ' . $this->videos->providerFor($video)::label() . '; change it there.');
        }
        if ($video['provider'] === 'bunny') {
            try {
                $this->videos->providerFor($video)->setThumbnail($ref, Url::absolute('/media/thumbnails/' . $name));
                $info = $this->videos->providerFor($video)->get($ref);
                $update['thumbnail_file_name'] = $info->thumbnailFileName;
            } catch (VideoProviderException | HttpException $e) {
                throw new ApiError('Bunny couldn’t take the thumbnail: ' . $e->getMessage() . ' (Bunny fetches it from this site, so the site must be reachable from the internet.)', 502, 'provider_error');
            }
        } else {
            $update['external_thumbnail_url'] = $url;
            $update['provider_data'] = ['poster' => $url] + $ref->data;
        }
        $duration = $input['durationSeconds'] ?? null;
        if (is_numeric($duration) && (int) $duration > 0 && $video['duration_seconds'] === null) {
            $update['duration_seconds'] = (int) $duration;
        }
        $this->db()->update('videos', $update, ['id' => $video['id']]);
        $this->catalog->audit('video.thumbnail', 'video', (string) $video['id']);
        return Response::json($this->present($this->catalog->find('video', $p['id'])));
    }

    // Captions --------------------------------------------------------------------------

    /** SubRip to WebVTT: a header, and full stops for the millisecond commas. */
    public static function srtToVtt(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", ltrim($text, "\xEF\xBB\xBF"));
        if (str_starts_with($text, 'WEBVTT')) {
            return $text;
        }
        $text = (string) preg_replace('/(\d{2}:\d{2}:\d{2}),(\d{3})/', '$1.$2', $text);
        return "WEBVTT\n\n" . trim($text) . "\n";
    }

    /** @param array<string, mixed> $video @return list<array{srclang: string, label: string, src?: string}> */
    private function tracks(array $video): array
    {
        $ops = $this->videos->providerFor($video)->captions(VideoRef::fromRow($video));
        if ($ops !== null) {
            try {
                return $ops->list();
            } catch (VideoProviderException | HttpException $e) {
                throw new ApiError('The captions couldn’t be read: ' . $e->getMessage(), 502, 'provider_error');
            }
        }
        return array_values((array) (VideoRef::fromRow($video)->data['tracks'] ?? []));
    }

    /** @param array<string, string> $p */
    public function captionsList(Request $req, array $p): Response
    {
        return Response::json($this->tracks($this->video($p['id'])));
    }

    /** @param array<string, string> $p */
    public function captionsAdd(Request $req, array $p): Response
    {
        $video = $this->video($p['id']);
        $data = Validator::check($req->input(), [
            'srclang' => ['string', 'required', 'max' => 12, 'pattern' => '/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?$/'],
            'label' => ['string', 'required', 'max' => 60],
            'upload' => ['string', 'required', 'max' => 40],
        ]);
        $upload = Uploads::take($this->app, (string) $data['upload'], 'caption');
        try {
            if ($upload['size'] > 1_048_576) {
                throw ApiError::invalid('Captions files are limited to 1 MB.');
            }
            $vtt = self::srtToVtt((string) file_get_contents($upload['path']));
        } finally {
            Uploads::discard($this->app, (string) $data['upload']);
        }
        $ref = VideoRef::fromRow($video);
        $ops = $this->videos->providerFor($video)->captions($ref);
        if ($ops !== null) {
            try {
                $ops->add((string) $data['srclang'], (string) $data['label'], $vtt);
            } catch (VideoProviderException | HttpException $e) {
                throw new ApiError($e->getMessage(), 502, 'provider_error');
            }
        } else {
            // A sidecar file for the site's own player.
            $dir = $this->app->paths->storage('media/captions');
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('storage/media is not writable.');
            }
            $name = bin2hex(random_bytes(12)) . '.vtt';
            file_put_contents("$dir/$name", $vtt);
            $tracks = array_values(array_filter((array) ($ref->data['tracks'] ?? []), fn ($t) => ($t['srclang'] ?? null) !== $data['srclang']));
            $tracks[] = ['srclang' => (string) $data['srclang'], 'label' => (string) $data['label'], 'src' => Url::to('/media/captions/' . $name)];
            $this->db()->update('videos', ['provider_data' => ['tracks' => $tracks] + $ref->data], ['id' => $video['id']]);
        }
        $this->catalog->audit('video.captions.add', 'video', (string) $video['id'], (string) $data['srclang']);
        return Response::json($this->tracks($this->catalog->find('video', $p['id'])), 201);
    }

    /** @param array<string, string> $p */
    public function captionsDelete(Request $req, array $p): Response
    {
        $video = $this->video($p['id']);
        $srclang = (string) ($req->input()['srclang'] ?? $req->query('srclang') ?? '');
        $ref = VideoRef::fromRow($video);
        $ops = $this->videos->providerFor($video)->captions($ref);
        if ($ops !== null) {
            try {
                $ops->delete($srclang);
            } catch (VideoProviderException | HttpException $e) {
                throw new ApiError($e->getMessage(), 502, 'provider_error');
            }
        } else {
            $tracks = array_values(array_filter((array) ($ref->data['tracks'] ?? []), fn ($t) => ($t['srclang'] ?? null) !== $srclang));
            $this->db()->update('videos', ['provider_data' => ['tracks' => $tracks] + $ref->data], ['id' => $video['id']]);
        }
        $this->catalog->audit('video.captions.delete', 'video', (string) $video['id'], $srclang);
        return Response::json($this->tracks($this->catalog->find('video', $p['id'])));
    }

    // Import from the Bunny library ------------------------------------------------------

    private function bunnyProvider(): BunnyStreamProvider
    {
        $p = $this->app->services()->get('video', 'bunny');
        if (!$p instanceof BunnyStreamProvider || $this->app->services()->savedConfig('video', 'bunny') === []) {
            throw ApiError::invalid('bunny.net Stream isn’t set up under Services.');
        }
        return $p;
    }

    public function bunnyLibrary(Request $req): Response
    {
        $this->catalog->require('manage_videos', ['categoryId' => null, 'seriesId' => null]);
        try {
            $items = $this->bunnyProvider()->listLibrary(max(1, (int) ($req->query('page') ?? 1)));
        } catch (VideoProviderException | HttpException $e) {
            throw new ApiError($e->getMessage(), 502, 'provider_error');
        }
        $known = array_flip(array_map('strval', $this->db()->column("SELECT external_id FROM {{videos}} WHERE provider = 'bunny'")));
        return Response::json(array_values(array_filter($items, fn ($i) => !isset($known[$i['guid']]))));
    }

    public function import(Request $req): Response
    {
        $data = Validator::check($req->input(), ['guids' => ['array', 'required', 'max' => 100, 'of' => 'string', 'each' => ['max' => 64]], 'seriesId' => ['id', 'nullable']]);
        $row = $this->placement(['seriesId' => $data['seriesId'] ?? null]);
        $provider = $this->bunnyProvider();
        $made = 0;
        foreach ($data['guids'] as $guid) {
            if ($this->db()->value("SELECT 1 FROM {{videos}} WHERE provider = 'bunny' AND external_id = ?", [$guid]) !== null) {
                continue;
            }
            try {
                $info = $provider->get(new VideoRef((string) $guid));
                $title = (string) (\App\Core\Http::request('GET', 'https://video.bunnycdn.com/library/' . rawurlencode((string) $this->app->services()->savedConfig('video', 'bunny')['libraryId']) . '/videos/' . rawurlencode((string) $guid), ['AccessKey' => (string) $this->app->services()->savedConfig('video', 'bunny')['apiKey']])->json()['title'] ?? $guid);
            } catch (VideoProviderException | HttpException $e) {
                continue;
            }
            $id = Id::new();
            $this->db()->insert('videos', $row + [
                'id' => $id,
                'title' => mb_substr($title, 0, 255),
                'slug' => $this->videos->uniqueSlug($title),
                'provider' => 'bunny',
                'external_id' => (string) $guid,
                'bunny_library_id' => (string) $this->app->services()->savedConfig('video', 'bunny')['libraryId'],
                'provider_data' => [],
                'status' => $info->status,
                'duration_seconds' => $info->durationSeconds,
                'thumbnail_file_name' => $info->thumbnailFileName,
                'has_mp4_fallback' => $info->hasMp4Fallback,
                'mp4_resolutions' => $info->mp4Resolutions,
                'scripture_refs' => [],
            ]);
            $row['position']++;
            $made++;
        }
        $this->catalog->audit('video.import', 'video', 'bunny', (string) $made);
        return Response::json(['imported' => $made]);
    }
}
