<?php

declare(strict_types=1);

namespace App\Modules\Library;

use App\Core\ApiError;
use App\Core\App;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Id;
use App\Services\Video\LinkedVideo;
use App\Services\Video\LocalVideoProvider;
use App\Services\Video\UploadHints;
use App\Services\Video\UploadTicket;
use App\Services\Video\VideoInfo;
use App\Services\Video\VideoProvider;
use App\Services\Video\VideoProviderException;
use App\Services\Video\VideoRef;
use App\Support\Slug;

/**
 * Videos and their providers: adding one by upload or by link, settling a
 * row from what its provider says, and the per-provider chores a save
 * implies (the host's disk moving a file between private and public).
 */
final class Videos
{
    /** Link providers, narrowest claim first; the direct link is asked last. */
    public const LINK_ORDER = ['youtube', 'vimeo', 'dropbox', 'gdrive', 'onedrive', 'archive', 'direct'];

    public function __construct(private readonly App $app)
    {
    }

    private function db(): Db
    {
        return $this->app->db();
    }

    public function provider(string $id): VideoProvider
    {
        $p = $this->app->services()->get('video', $id);
        if (!$p instanceof VideoProvider) {
            throw new ApiError('This video’s service (' . $id . ') isn’t available any more.', 409, 'provider_missing');
        }
        return $p;
    }

    /** @param array<string, mixed> $row */
    public function providerFor(array $row): VideoProvider
    {
        return $this->provider((string) $row['provider']);
    }

    /** The provider new uploads go to, or null when the Video slot is unset. */
    public function uploadProvider(): ?VideoProvider
    {
        $p = $this->app->services()->active('video');
        return $p instanceof VideoProvider && $p->capabilities()->upload ? $p : null;
    }

    /** @return array{0: VideoProvider, 1: LinkedVideo} */
    public function resolveLink(string $url): array
    {
        foreach (self::LINK_ORDER as $id) {
            $provider = $this->app->services()->get('video', $id);
            if ($provider instanceof VideoProvider && $provider->capabilities()->link && $provider->matchesLink($url)) {
                try {
                    return [$provider, $provider->resolveLink($url)];
                } catch (HttpException $e) {
                    throw ApiError::invalid('That address couldn’t be reached: ' . $e->getMessage());
                } catch (VideoProviderException $e) {
                    throw ApiError::invalid($e->getMessage());
                }
            }
        }
        throw ApiError::invalid('No video service recognises that link. Paste a YouTube, Vimeo, Dropbox, Google Drive, OneDrive or archive.org link, or an https:// address of a video file.');
    }

    public function uniqueSlug(string $title, ?string $except = null): string
    {
        return Slug::unique($title, fn (string $s) => $this->db()->value('SELECT 1 FROM {{videos}} WHERE slug = ?' . ($except !== null ? ' AND id <> ?' : ''), $except !== null ? [$s, $except] : [$s]) !== null, 'video');
    }

    /**
     * @param array<string, mixed> $row the fields beyond what the link gives (series, category, publish state)
     * @return array<string, mixed> the new row
     */
    public function createFromLink(string $url, array $row): array
    {
        [$provider, $linked] = $this->resolveLink($url);
        $existing = $this->db()->one('SELECT id, title, deleted_at FROM {{videos}} WHERE provider = ? AND external_id = ?', [$provider::id(), $linked->id]);
        if ($existing !== null) {
            throw ApiError::conflict('That video is already in the library' . ($existing['deleted_at'] !== null ? ' (in the trash)' : '') . ' as “' . $existing['title'] . '”.');
        }
        $title = trim((string) ($row['title'] ?? '')) !== '' ? (string) $row['title'] : ($linked->title !== null && $linked->title !== '' ? $linked->title : 'Untitled video');
        $data = $linked->data;
        if ($linked->thumbnailUrl !== null && $provider::id() !== 'youtube') {
            $data['thumbnail'] = $linked->thumbnailUrl;
        }
        $id = Id::new();
        $this->db()->insert('videos', $row + [
            'id' => $id,
            'title' => mb_substr($title, 0, 255),
            'slug' => $this->uniqueSlug($title),
            'description' => $linked->description,
            'provider' => $provider::id(),
            'external_id' => $linked->id,
            'external_url' => $linked->externalUrl,
            'external_thumbnail_url' => $linked->thumbnailUrl,
            'imported_title' => $linked->title,
            'imported_description' => $linked->description,
            'provider_data' => $data,
            'duration_seconds' => $linked->durationSeconds,
            'status' => 'READY',
            'scripture_refs' => [],
        ]);
        return (array) $this->db()->one('SELECT * FROM {{videos}} WHERE id = ?', [$id]);
    }

    /**
     * A placeholder row and what the browser must do next.
     *
     * @param array<string, mixed> $row
     * @return array{0: array<string, mixed>, 1: UploadTicket}
     */
    public function createUpload(array $row, UploadHints $hints): array
    {
        $provider = $this->uploadProvider() ?? throw ApiError::invalid('No video service that takes uploads is set up. Choose one under Admin → Services, or add the video by link.');
        $title = trim((string) ($row['title'] ?? '')) !== '' ? (string) $row['title'] : \App\Support\Filename::titleFromFilename($hints->fileName);
        try {
            $ticket = $provider->createUpload($title, $hints);
        } catch (HttpException $e) {
            throw new ApiError($provider::label() . ' couldn’t be reached: ' . $e->getMessage(), 502, 'provider_error');
        } catch (VideoProviderException $e) {
            throw new ApiError($e->getMessage(), 502, 'provider_error');
        }
        $id = Id::new();
        $this->db()->insert('videos', $row + [
            'id' => $id,
            'title' => mb_substr($title !== '' ? $title : 'Untitled video', 0, 255),
            'slug' => $this->uniqueSlug($title !== '' ? $title : 'video'),
            'provider' => $provider::id(),
            // Unique per provider: a placeholder with no id yet keeps its own row id there.
            'external_id' => $ticket->id !== '' ? $ticket->id : 'pending-' . $id,
            'bunny_library_id' => $provider::id() === 'bunny' ? ($ticket->data['libraryId'] ?? null) : null,
            'provider_data' => $ticket->data,
            'status' => 'PROCESSING',
            'scripture_refs' => [],
        ]);
        return [(array) $this->db()->one('SELECT * FROM {{videos}} WHERE id = ?', [$id]), $ticket];
    }

    /**
     * Brings a row up to date with its provider: after an upload finishes
     * (with what the browser reports), by the manual Sync button, and by
     * the job that polls anything still processing.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $report upload id, the provider's id for the video, part ETags
     * @return array<string, mixed>
     */
    public function sync(array $row, array $report = []): array
    {
        $provider = $this->providerFor($row);
        $ref = VideoRef::fromRow($row);
        $data = $ref->data;
        $externalId = $ref->id;
        try {
            if ($report !== []) {
                if (isset($report['externalId']) && str_starts_with($externalId, 'pending-')) {
                    $externalId = (string) $report['externalId'];
                }
                if (isset($report['parts'])) {
                    $data['parts'] = $report['parts'];
                }
                if (isset($report['upload'])) {
                    $upload = \App\Modules\Uploads\Uploads::take($this->app, (string) $report['upload'], 'video');
                    $data['localPath'] = $upload['path'];
                }
                $info = $provider->completeUpload(new VideoRef($externalId, $data));
                unset($data['parts'], $data['localPath'], $data['uploadId']);
                if (isset($report['upload'])) {
                    \App\Modules\Uploads\Uploads::discard($this->app, (string) $report['upload']);
                }
            } else {
                $info = $provider->get($ref);
            }
        } catch (HttpException $e) {
            throw new ApiError($provider::label() . ' couldn’t be reached: ' . $e->getMessage(), 502, 'provider_error');
        } catch (VideoProviderException $e) {
            throw new ApiError($e->getMessage(), 502, 'provider_error');
        }
        $this->applyInfo((string) $row['id'], $info, $data, $externalId);
        $fresh = (array) $this->db()->one('SELECT * FROM {{videos}} WHERE id = ?', [$row['id']]);
        return $this->afterSave($fresh);
    }

    /** @param array<string, mixed> $data */
    public function applyInfo(string $id, VideoInfo $info, array $data, ?string $externalId = null): void
    {
        $update = ['status' => $info->status, 'provider_data' => $info->data + $data];
        if ($externalId !== null) {
            $update['external_id'] = $externalId;
        }
        if ($info->durationSeconds !== null) {
            $update['duration_seconds'] = $info->durationSeconds;
        }
        if ($info->thumbnailFileName !== null) {
            $update['thumbnail_file_name'] = $info->thumbnailFileName;
        }
        if ($info->hasMp4Fallback !== null) {
            $update['has_mp4_fallback'] = $info->hasMp4Fallback;
            $update['mp4_resolutions'] = $info->mp4Resolutions;
        }
        $this->db()->update('videos', $update, ['id' => $id]);
    }

    /**
     * What a save implies at the provider. Only the host's disk has chores:
     * a video anyone may watch is moved where the web server serves it, any
     * other back into storage.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function afterSave(array $row): array
    {
        if (($row['provider'] ?? null) !== 'local') {
            return $row;
        }
        $provider = $this->provider('local');
        if (!$provider instanceof LocalVideoProvider) {
            return $row;
        }
        $access = new ContentAccess($this->app, Viewer::guest());
        $series = $row['series_id'] !== null ? $this->db()->one('SELECT * FROM {{series}} WHERE id = ?', [$row['series_id']]) : null;
        $public = $row['deleted_at'] === null && $access->video($row, $series) === ContentAccess::OK && Visibility::isVisible($row, $access->now());
        try {
            $data = $provider->publish(VideoRef::fromRow($row), $public);
        } catch (VideoProviderException $e) {
            \App\Core\Log::warning('Local video publish: ' . $e->getMessage());
            return $row;
        }
        $this->db()->update('videos', ['provider_data' => $data], ['id' => $row['id']]);
        $row['provider_data'] = json_encode($data);
        return $row;
    }

    /**
     * Re-decides every host-disk video's place (public/media or storage):
     * after a change above it (a category or series going members-only, a
     * viewer restriction) and on a schedule, since publish and take-down
     * times pass without anybody saving.
     *
     * @return int how many moved
     */
    public function reconcileLocal(): int
    {
        $moved = 0;
        foreach ($this->db()->all("SELECT * FROM {{videos}} WHERE provider = 'local'") as $row) {
            $before = (VideoRef::fromRow($row)->data['public'] ?? false) === true;
            $after = (VideoRef::fromRow($this->afterSave($row))->data['public'] ?? false) === true;
            $moved += $before !== $after ? 1 : 0;
        }
        return $moved;
    }

    /**
     * The book a reference starts with: "1 John 3:16" → "1 John",
     * "Psalm 23" → "Psalm", "Song of Songs 2:1" → "Song of Songs".
     */
    public static function scriptureBook(string $reference): ?string
    {
        if (!preg_match('/^\s*((?:[1-3]|I{1,3})\s*)?([\p{L}][\p{L}\'’.]*(?:\s+(?:of\s+)?[\p{L}][\p{L}\'’.]*)*?)\s*(?=\d|$)/u', $reference, $m)) {
            return null;
        }
        $book = trim(preg_replace('/\s+/u', ' ', trim($m[1] . ' ' . $m[2])) ?? '');
        $book = rtrim($book, '.');
        if ($book === '') {
            return null;
        }
        $words = array_map(
            fn (string $w) => $w === 'of' ? $w : mb_strtoupper(mb_substr($w, 0, 1)) . mb_substr($w, 1),
            explode(' ', mb_strtolower($book)),
        );
        return implode(' ', $words);
    }

    /** @param list<string> $refs */
    public function syncScriptureBooks(string $videoId, array $refs): void
    {
        $this->db()->run('DELETE FROM {{video_scripture_books}} WHERE video_id = ?', [$videoId]);
        $books = [];
        foreach ($refs as $ref) {
            $book = self::scriptureBook($ref);
            if ($book !== null) {
                $books[$book] = true;
            }
        }
        foreach (array_keys($books) as $book) {
            $this->db()->run('INSERT IGNORE INTO {{video_scripture_books}} (video_id, book) VALUES (?, ?)', [$videoId, $book]);
        }
    }
}
