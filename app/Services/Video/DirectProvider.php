<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Core\Http;
use App\Services\TestContext;
use App\Services\TestResult;

/**
 * Any HTTPS address that answers with a video file or an HLS playlist,
 * played in this site's own <video> (hls.js where the browser has no native
 * HLS). Asked last, after every provider with a narrower claim. The link is
 * fetched only through fetchUntrusted: it is somebody's pasted address.
 */
final class DirectProvider extends BaseVideoProvider
{
    public static function id(): string
    {
        return 'direct';
    }

    public static function label(): string
    {
        return 'Direct link';
    }

    public static function limits(): string
    {
        return 'Anybody with the address can watch it; the site can’t make a members-only video private. It plays as well as whatever hosts the file.';
    }

    public function capabilities(): VideoCapabilities
    {
        return new VideoCapabilities(link: true, mp4: true, progressEvents: true);
    }

    public function matchesLink(string $url): bool
    {
        return Http::isPublicHttpUrl(trim($url)) && str_starts_with(strtolower(trim($url)), 'https://');
    }

    public function resolveLink(string $url): LinkedVideo
    {
        $url = trim($url);
        if (!$this->matchesLink($url)) {
            throw new VideoProviderException('A direct link must be an https:// address on the public internet.');
        }
        $r = Http::fetchUntrusted('HEAD', $url);
        $type = strtolower(trim(explode(';', (string) ($r->header('content-type') ?? ''))[0]));
        $isHls = in_array($type, ['application/vnd.apple.mpegurl', 'application/x-mpegurl', 'audio/mpegurl'], true) || str_ends_with(strtolower((string) parse_url($url, PHP_URL_PATH)), '.m3u8');
        if (!$r->ok() || (!str_starts_with($type, 'video/') && !$isHls)) {
            throw new VideoProviderException($r->ok()
                ? 'That address answers with ' . ($type === '' ? 'something' : $type) . ', not a video file or an HLS playlist.'
                : 'That address didn’t answer (' . $r->status . ').');
        }
        $name = urldecode(basename((string) parse_url($url, PHP_URL_PATH)));
        return new LinkedVideo(hash('sha256', $url), $name !== '' ? preg_replace('/\.[a-z0-9]{2,5}$/i', '', $name) : null, null, null, $url, ['url' => $url, 'type' => $isHls ? 'application/vnd.apple.mpegurl' : $type, 'ranges' => strtolower((string) ($r->header('accept-ranges') ?? '')) === 'bytes']);
    }

    public function player(VideoRef $video, PlayerOptions $options): PlayerSpec
    {
        $url = (string) ($video->data['url'] ?? '');
        $type = (string) ($video->data['type'] ?? 'video/mp4');
        return PlayerSpec::native([['src' => $url, 'type' => $type]], isset($video->data['poster']) ? (string) $video->data['poster'] : null, $type === 'application/vnd.apple.mpegurl');
    }

    public function thumbnailUrl(VideoRef $video, ?string $file): ?string
    {
        return isset($video->data['poster']) ? (string) $video->data['poster'] : null;
    }

    public function mp4(VideoRef $video, int $maxHeight): Mp4Result
    {
        $type = (string) ($video->data['type'] ?? '');
        return $type === 'video/mp4' ? Mp4Result::ok((string) $video->data['url']) : Mp4Result::reason('not_supported');
    }

    public function test(TestContext $context): TestResult
    {
        return TestResult::ok('Nothing to set up: any https:// video address works.');
    }
}
