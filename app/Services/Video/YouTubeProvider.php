<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Core\Http;
use App\Core\Url;
use App\Services\TestContext;
use App\Services\TestResult;

/**
 * YouTube. Link mode needs nothing: watch, youtu.be, shorts, live and embed
 * links play in youtube-nocookie.com with rel=0; title and duration come
 * from the Data API when a key is set, from oEmbed otherwise. Upload mode is
 * optional and needs an OAuth client with the channel owner's one-time
 * consent: PHP opens a resumable session and the browser PUTs the file to it.
 */
final class YouTubeProvider extends BaseVideoProvider
{
    public const API = 'https://www.googleapis.com/youtube/v3';
    public const TEST_VIDEO = 'jNQXAC9IVRw';

    public static function id(): string
    {
        return 'youtube';
    }

    public static function label(): string
    {
        return 'YouTube';
    }

    public static function requiresOutboundHttps(): bool
    {
        return true;
    }

    public static function limits(): string
    {
        return 'No MP4 for downloads or casting, and "unlisted" is a secret, not a lock: anyone with the link can watch. The default API quota allows about six uploads a day, and an app Google hasn’t verified has its uploads set to private.';
    }

    public static function cspSources(): array
    {
        return ['frame' => ['https://www.youtube-nocookie.com'], 'connect' => ['https://www.googleapis.com']];
    }

    public static function configSchema(): array
    {
        return [
            ['key' => 'apiKey', 'label' => 'Data API key (optional)', 'type' => 'text', 'secret' => true, 'help' => 'For titles and durations of pasted links; oEmbed is used without it.'],
            ['key' => 'clientId', 'label' => 'OAuth client ID (for uploads)', 'type' => 'text'],
            ['key' => 'clientSecret', 'label' => 'OAuth client secret', 'type' => 'text', 'secret' => true],
            ['key' => 'refreshToken', 'label' => 'Refresh token from the channel owner’s consent', 'type' => 'text', 'secret' => true],
        ];
    }

    private function canUpload(): bool
    {
        return $this->str('clientId') !== '' && $this->str('clientSecret') !== '' && $this->str('refreshToken') !== '';
    }

    public function capabilities(): VideoCapabilities
    {
        return new VideoCapabilities(upload: $this->canUpload(), link: true, transcodes: true, thumbnails: true, duration: true);
    }

    public function matchesLink(string $url): bool
    {
        return Links::youtubeId($url) !== null;
    }

    public function resolveLink(string $url): LinkedVideo
    {
        $id = Links::youtubeId($url) ?? throw new VideoProviderException('That isn’t a YouTube video link.');
        $page = 'https://www.youtube.com/watch?v=' . $id;
        if ($this->str('apiKey') !== '') {
            $r = Http::request('GET', self::API . '/videos?' . http_build_query(['part' => 'snippet,contentDetails', 'id' => $id, 'key' => $this->str('apiKey')]));
            $item = $r->json()['items'][0] ?? null;
            if (!$r->ok() || !is_array($item)) {
                throw new VideoProviderException($r->ok() ? 'YouTube has no public video with that id.' : 'YouTube refused the request: ' . self::apiError($r->json()));
            }
            return new LinkedVideo(
                $id,
                (string) ($item['snippet']['title'] ?? ''),
                Links::isoDuration((string) ($item['contentDetails']['duration'] ?? '')),
                'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg',
                $page,
                [],
                isset($item['snippet']['description']) ? (string) $item['snippet']['description'] : null,
            );
        }
        $r = Http::request('GET', 'https://www.youtube.com/oembed?' . http_build_query(['url' => $page, 'format' => 'json']));
        $data = $r->json();
        if (!$r->ok() || !is_array($data)) {
            throw new VideoProviderException('YouTube says that video isn’t public or doesn’t exist.');
        }
        return new LinkedVideo($id, (string) ($data['title'] ?? ''), null, 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg', $page);
    }

    public function player(VideoRef $video, PlayerOptions $options): PlayerSpec
    {
        return PlayerSpec::iframe('https://www.youtube-nocookie.com/embed/' . rawurlencode($video->id) . '?rel=0' . ($options->startSeconds > 0 ? '&start=' . $options->startSeconds : '') . ($options->autoplay ? '&autoplay=1' : ''));
    }

    public function thumbnailUrl(VideoRef $video, ?string $file): ?string
    {
        return $video->id === '' ? null : 'https://i.ytimg.com/vi/' . rawurlencode($video->id) . '/hqdefault.jpg';
    }

    public function owns(VideoRef $video): bool
    {
        // Something this site uploaded; a pasted link stays on its owner's channel.
        return ($video->data['uploaded'] ?? false) === true;
    }

    private function accessToken(): string
    {
        $r = Http::request('POST', 'https://oauth2.googleapis.com/token', ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query([
            'client_id' => $this->str('clientId'),
            'client_secret' => $this->str('clientSecret'),
            'refresh_token' => $this->str('refreshToken'),
            'grant_type' => 'refresh_token',
        ]));
        $token = $r->json()['access_token'] ?? null;
        if (!$r->ok() || !is_string($token)) {
            throw new VideoProviderException('Google refused the saved consent (' . (string) ($r->json()['error'] ?? $r->status) . '). The channel owner needs to give it again.');
        }
        return $token;
    }

    public function createUpload(string $title, UploadHints $hints): UploadTicket
    {
        if (!$this->canUpload()) {
            parent::createUpload($title, $hints);
        }
        $r = Http::request('POST', 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status', [
            'Authorization' => 'Bearer ' . $this->accessToken(),
            'Content-Type' => 'application/json; charset=UTF-8',
            'X-Upload-Content-Length' => (string) $hints->size,
            'X-Upload-Content-Type' => $hints->mimeType,
            // A session opened with the site's origin accepts the browser's PUT.
            'Origin' => Url::origin(),
        ], (string) json_encode(['snippet' => ['title' => mb_substr($title, 0, 100)], 'status' => ['privacyStatus' => 'unlisted']]));
        $session = $r->header('location');
        if (!$r->ok() || $session === null) {
            throw new VideoProviderException('YouTube wouldn’t open an upload: ' . self::apiError($r->json()));
        }
        return new UploadTicket('resumable', '', ['url' => $session, 'method' => 'PUT'], ['uploaded' => true]);
    }

    /** The browser reports the id YouTube gave it; it is only kept once YouTube confirms it is ours. */
    public function completeUpload(VideoRef $video): VideoInfo
    {
        $r = Http::request('GET', self::API . '/videos?' . http_build_query(['part' => 'status,contentDetails', 'id' => $video->id]), ['Authorization' => 'Bearer ' . $this->accessToken()]);
        $item = $r->json()['items'][0] ?? null;
        if (!$r->ok() || !is_array($item) || !preg_match('/^[A-Za-z0-9_-]{11}$/', $video->id)) {
            throw new VideoProviderException('YouTube doesn’t know that upload.');
        }
        return new VideoInfo(($item['status']['uploadStatus'] ?? '') === 'processed' ? 'READY' : 'PROCESSING', Links::isoDuration((string) ($item['contentDetails']['duration'] ?? '')));
    }

    public function get(VideoRef $video): VideoInfo
    {
        if ($this->str('apiKey') === '') {
            return new VideoInfo('READY');
        }
        $r = Http::request('GET', self::API . '/videos?' . http_build_query(['part' => 'status,contentDetails', 'id' => $video->id, 'key' => $this->str('apiKey')]));
        $item = $r->json()['items'][0] ?? null;
        if (!is_array($item)) {
            return new VideoInfo('FAILED');
        }
        $status = (string) ($item['status']['uploadStatus'] ?? 'processed');
        return new VideoInfo(in_array($status, ['uploaded'], true) ? 'PROCESSING' : ($status === 'processed' ? 'READY' : 'FAILED'), Links::isoDuration((string) ($item['contentDetails']['duration'] ?? '')));
    }

    public function delete(VideoRef $video): void
    {
        if (!$this->owns($video) || !$this->canUpload()) {
            return;
        }
        $r = Http::request('DELETE', self::API . '/videos?' . http_build_query(['id' => $video->id]), ['Authorization' => 'Bearer ' . $this->accessToken()]);
        if (!$r->ok() && $r->status !== 404) {
            throw new VideoProviderException('YouTube refused the delete: ' . self::apiError($r->json()));
        }
    }

    /** @param array<string, mixed>|null $json */
    private static function apiError(?array $json): string
    {
        return (string) ($json['error']['message'] ?? $json['error']['errors'][0]['reason'] ?? 'no reason given');
    }

    public function test(TestContext $context): TestResult
    {
        if ($this->str('apiKey') !== '') {
            $r = Http::request('GET', self::API . '/videos?' . http_build_query(['part' => 'id', 'id' => self::TEST_VIDEO, 'key' => $this->str('apiKey')]));
            if (!$r->ok()) {
                return TestResult::fail('The API key was refused: ' . self::apiError($r->json()) . ' Check that the YouTube Data API is enabled for its project.');
            }
        }
        if ($this->canUpload()) {
            try {
                $token = $this->accessToken();
            } catch (VideoProviderException $e) {
                return TestResult::fail($e->getMessage());
            }
            $r = Http::request('GET', self::API . '/channels?part=id&mine=true', ['Authorization' => 'Bearer ' . $token]);
            if (!$r->ok() || ($r->json()['items'] ?? []) === []) {
                return TestResult::fail('The consent works but has no channel to upload to.');
            }
        }
        return TestResult::ok($this->str('apiKey') === '' && !$this->canUpload()
            ? 'Links work without settings; titles come from oEmbed. Nothing to test.'
            : 'YouTube answered with these settings.');
    }
}
