<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Core\Http;
use App\Services\TestContext;
use App\Services\TestResult;

/**
 * A Google Drive file shared with anyone who has the link. Two modes:
 *
 *   preview  Google's own player in an iframe (drive.google.com/…/preview):
 *            needs nothing, but gives no MP4, no progress, no start time;
 *   api      with an API key restricted to this site's referrers: metadata
 *            from files/<id>, playback in this site's <video> straight from
 *            files/<id>?alt=media&key=…, which answers Range and CORS.
 *
 * Never uc?export=download: above about 100 MB it puts a virus-scan page in
 * front of the file.
 *
 * Uploads are optional, with an OAuth client and the owner's one-time
 * consent to the drive.file scope (only files this app made): PHP opens a
 * resumable session the browser PUTs to, then shares the file with anyone
 * who has the link.
 */
final class GoogleDriveProvider extends BaseVideoProvider
{
    public const API = 'https://www.googleapis.com/drive/v3/files/';

    public static function id(): string
    {
        return 'gdrive';
    }

    public static function label(): string
    {
        return 'Google Drive shared link';
    }

    public static function requiresOutboundHttps(): bool
    {
        return true;
    }

    public static function limits(): string
    {
        return 'Drive is not a video host: a much-watched file passes its download quota and is refused for a day. The link is the credential — anyone with it can watch.';
    }

    public static function cspSources(): array
    {
        return ['frame' => ['https://drive.google.com'], 'connect' => ['https://www.googleapis.com']];
    }

    public static function configSchema(): array
    {
        return [
            ['key' => 'apiKey', 'label' => 'API key (optional; restrict it to this site’s address, Drive API enabled)', 'type' => 'text', 'help' => 'Without a key, videos play in Google’s own preview player.'],
            ['key' => 'clientId', 'label' => 'OAuth client ID (for uploads)', 'type' => 'text'],
            ['key' => 'clientSecret', 'label' => 'OAuth client secret', 'type' => 'text', 'secret' => true],
            ['key' => 'refreshToken', 'label' => 'Refresh token from the owner’s consent (drive.file scope)', 'type' => 'text', 'secret' => true],
            ['key' => 'folderId', 'label' => 'Folder ID to upload into (optional)', 'type' => 'text', 'help' => 'Must be a folder this app created; otherwise uploads go to the top of My Drive.'],
        ];
    }

    private function canUpload(): bool
    {
        return $this->str('clientId') !== '' && $this->str('clientSecret') !== '' && $this->str('refreshToken') !== '';
    }

    private function apiMode(): bool
    {
        return $this->str('apiKey') !== '';
    }

    public function capabilities(): VideoCapabilities
    {
        $api = $this->apiMode();
        return new VideoCapabilities(upload: $this->canUpload(), link: true, thumbnails: $api, duration: $api, mp4: $api, progressEvents: $api);
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
            throw new VideoProviderException('Google refused the saved consent (' . (string) ($r->json()['error'] ?? $r->status) . '). The Drive owner needs to give it again.');
        }
        return $token;
    }

    public function createUpload(string $title, UploadHints $hints): UploadTicket
    {
        if (!$this->canUpload()) {
            parent::createUpload($title, $hints);
        }
        $meta = ['name' => $hints->fileName !== '' ? $hints->fileName : $title . '.mp4', 'mimeType' => $hints->mimeType];
        if ($this->str('folderId') !== '') {
            $meta['parents'] = [$this->str('folderId')];
        }
        $r = Http::request('POST', 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&fields=id', [
            'Authorization' => 'Bearer ' . $this->accessToken(),
            'Content-Type' => 'application/json; charset=UTF-8',
            'X-Upload-Content-Length' => (string) $hints->size,
            'X-Upload-Content-Type' => $hints->mimeType,
            // A session opened with the site's origin accepts the browser's PUT.
            'Origin' => \App\Core\Url::origin(),
        ], (string) json_encode($meta, JSON_UNESCAPED_SLASHES));
        $session = $r->header('location');
        if (!$r->ok() || $session === null) {
            throw new VideoProviderException('Drive wouldn’t open an upload: ' . (string) ($r->json()['error']['message'] ?? $r->status));
        }
        return new UploadTicket('resumable', '', ['url' => $session, 'method' => 'PUT'], ['uploaded' => true]);
    }

    /**
     * The browser reports the id Drive gave the file. With the drive.file
     * scope the token only sees files this app made, so reading it proves
     * the upload is ours; then anyone with the link may view it.
     */
    public function completeUpload(VideoRef $video): VideoInfo
    {
        if (($video->data['uploaded'] ?? false) !== true || !preg_match('/^[A-Za-z0-9_-]{10,100}$/', $video->id)) {
            throw new VideoProviderException('Drive didn’t say which file the upload became.');
        }
        $token = $this->accessToken();
        $r = Http::request('GET', self::API . rawurlencode($video->id) . '?fields=id,mimeType,videoMediaMetadata,thumbnailLink', ['Authorization' => 'Bearer ' . $token]);
        $d = $r->json();
        if (!$r->ok() || !is_array($d)) {
            throw new VideoProviderException('Drive doesn’t know that upload.');
        }
        $share = Http::request('POST', self::API . rawurlencode($video->id) . '/permissions', ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'], '{"role":"reader","type":"anyone"}');
        if (!$share->ok()) {
            throw new VideoProviderException('Drive wouldn’t share the upload with anyone who has the link (' . (string) ($share->json()['error']['message'] ?? $share->status) . '). A Workspace domain may forbid it.');
        }
        $ms = $d['videoMediaMetadata']['durationMillis'] ?? null;
        return new VideoInfo('READY', is_numeric($ms) ? (int) round((float) $ms / 1000) : null, data: [
            'mode' => $this->apiMode() ? 'api' : 'preview',
            'mimeType' => (string) ($d['mimeType'] ?? 'video/mp4'),
            'page' => 'https://drive.google.com/file/d/' . $video->id . '/view',
        ]);
    }

    public function owns(VideoRef $video): bool
    {
        return ($video->data['uploaded'] ?? false) === true;
    }

    public function delete(VideoRef $video): void
    {
        if (!$this->owns($video) || !$this->canUpload() || str_starts_with($video->id, 'pending-')) {
            return;
        }
        $r = Http::request('DELETE', self::API . rawurlencode($video->id), ['Authorization' => 'Bearer ' . $this->accessToken()]);
        if (!$r->ok() && $r->status !== 404) {
            throw new VideoProviderException('Drive refused the delete (' . (string) ($r->json()['error']['message'] ?? $r->status) . ').');
        }
    }

    public function matchesLink(string $url): bool
    {
        return Links::driveId($url) !== null;
    }

    public function resolveLink(string $url): LinkedVideo
    {
        $id = Links::driveId($url) ?? throw new VideoProviderException('That isn’t a Google Drive file link.');
        $page = 'https://drive.google.com/file/d/' . $id . '/view';
        if (!$this->apiMode()) {
            return new LinkedVideo($id, null, null, null, $page, ['mode' => 'preview']);
        }
        $r = Http::request('GET', self::API . rawurlencode($id) . '?' . http_build_query(['fields' => 'name,size,mimeType,videoMediaMetadata,thumbnailLink', 'key' => $this->str('apiKey')]));
        $d = $r->json();
        if (!$r->ok() || !is_array($d)) {
            throw new VideoProviderException($r->status === 404
                ? 'Drive can’t see that file. Share it with “anyone with the link”.'
                : 'Drive refused the request (' . (string) ($d['error']['message'] ?? $r->status) . ').');
        }
        if (!str_starts_with((string) ($d['mimeType'] ?? ''), 'video/')) {
            throw new VideoProviderException('That Drive file is ' . (string) ($d['mimeType'] ?? 'not a video') . '.');
        }
        $ms = $d['videoMediaMetadata']['durationMillis'] ?? null;
        return new LinkedVideo($id, preg_replace('/\.[a-z0-9]{2,5}$/i', '', (string) ($d['name'] ?? '')) ?: null, is_numeric($ms) ? (int) round((float) $ms / 1000) : null, isset($d['thumbnailLink']) ? (string) $d['thumbnailLink'] : null, $page, ['mode' => 'api', 'mimeType' => (string) $d['mimeType']]);
    }

    public function player(VideoRef $video, PlayerOptions $options): PlayerSpec
    {
        if (!$this->apiMode() || ($video->data['mode'] ?? 'preview') === 'preview') {
            return PlayerSpec::iframe('https://drive.google.com/file/d/' . rawurlencode($video->id) . '/preview');
        }
        return PlayerSpec::native([['src' => self::API . rawurlencode($video->id) . '?alt=media&key=' . rawurlencode($this->str('apiKey')), 'type' => (string) ($video->data['mimeType'] ?? 'video/mp4')]]);
    }

    public function mp4(VideoRef $video, int $maxHeight): Mp4Result
    {
        if (!$this->apiMode()) {
            return Mp4Result::reason('not_supported');
        }
        return Mp4Result::ok(self::API . rawurlencode($video->id) . '?alt=media&key=' . rawurlencode($this->str('apiKey')));
    }

    public function test(TestContext $context): TestResult
    {
        if ($this->canUpload()) {
            try {
                $r = Http::request('GET', 'https://www.googleapis.com/drive/v3/about?fields=user', ['Authorization' => 'Bearer ' . $this->accessToken()]);
            } catch (VideoProviderException $e) {
                return TestResult::fail($e->getMessage());
            }
            if (!$r->ok()) {
                return TestResult::fail('The consent works but Drive refused it (' . (string) ($r->json()['error']['message'] ?? $r->status) . '). Enable the Drive API for the OAuth client’s project.');
            }
            if (!$this->apiMode()) {
                return TestResult::ok('Uploads go to ' . (string) ($r->json()['user']['emailAddress'] ?? 'the owner’s') . ' Drive; videos play in Google’s own player.');
            }
        }
        if (!$this->apiMode()) {
            return TestResult::ok('Preview mode needs nothing: videos play in Google’s own player.');
        }
        // Any request with the key tells us whether the key and the Drive API are on.
        $r = Http::request('GET', self::API . 'test?' . http_build_query(['key' => $this->str('apiKey')]), ['Referer' => \App\Core\Url::baseUrl() . '/']);
        $reason = (string) ($r->json()['error']['errors'][0]['reason'] ?? '');
        if ($r->status === 404 || $reason === 'notFound') {
            return TestResult::ok('The key works with the Drive API.');
        }
        return TestResult::fail('Drive refused the key: ' . (string) ($r->json()['error']['message'] ?? $r->status) . '. Enable the Drive API for its project and allow this site’s address as a referrer.');
    }
}
