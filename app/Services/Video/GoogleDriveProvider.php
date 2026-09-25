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
        return ['frame' => ['https://drive.google.com']];
    }

    public static function configSchema(): array
    {
        return [['key' => 'apiKey', 'label' => 'API key (optional; restrict it to this site’s address, Drive API enabled)', 'type' => 'text', 'help' => 'Without a key, videos play in Google’s own preview player.']];
    }

    private function apiMode(): bool
    {
        return $this->str('apiKey') !== '';
    }

    public function capabilities(): VideoCapabilities
    {
        $api = $this->apiMode();
        return new VideoCapabilities(link: true, thumbnails: $api, duration: $api, mp4: $api, progressEvents: $api);
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
