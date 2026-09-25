<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Core\Http;
use App\Services\TestContext;
use App\Services\TestResult;

/**
 * A Dropbox shared link, made direct (dl=1), checked to be a video that
 * answers Range requests, and played in this site's own <video>. Dropbox
 * doesn't transcode: the file must already be H.264/AAC MP4.
 */
final class DropboxProvider extends BaseVideoProvider
{
    public static function id(): string
    {
        return 'dropbox';
    }

    public static function label(): string
    {
        return 'Dropbox shared link';
    }

    public static function limits(): string
    {
        return 'The link itself is the credential: anyone who has it can watch, members-only or not. Dropbox pauses links that pass its daily bandwidth (20 GB a day on Basic, 200 GB on paid plans). The file must already be an H.264/AAC MP4.';
    }

    public function capabilities(): VideoCapabilities
    {
        return new VideoCapabilities(link: true, mp4: true, progressEvents: true);
    }

    public function matchesLink(string $url): bool
    {
        return Links::dropboxDirect($url) !== null;
    }

    public function resolveLink(string $url): LinkedVideo
    {
        $direct = Links::dropboxDirect($url) ?? throw new VideoProviderException('That isn’t a Dropbox shared link.');
        $r = Http::fetchUntrusted('HEAD', $direct);
        $type = strtolower(trim(explode(';', (string) ($r->header('content-type') ?? ''))[0]));
        if (!$r->ok()) {
            throw new VideoProviderException($r->status === 429 || $r->status === 509
                ? 'Dropbox has paused that link for passing its bandwidth limit. Try again tomorrow.'
                : 'Dropbox didn’t serve that link (' . $r->status . '). Check that it is shared with anyone who has the link.');
        }
        if (!str_starts_with($type, 'video/') && $type !== 'application/octet-stream' && $type !== 'application/binary') {
            throw new VideoProviderException('That Dropbox file is ' . $type . ', not a video.');
        }
        $name = urldecode(basename((string) parse_url($direct, PHP_URL_PATH)));
        return new LinkedVideo(substr(hash('sha256', $direct), 0, 40), preg_replace('/\.[a-z0-9]{2,5}$/i', '', $name) ?: null, null, null, trim($url), ['url' => $direct]);
    }

    public function player(VideoRef $video, PlayerOptions $options): PlayerSpec
    {
        return PlayerSpec::native([['src' => (string) ($video->data['url'] ?? ''), 'type' => 'video/mp4']], isset($video->data['poster']) ? (string) $video->data['poster'] : null);
    }

    public function thumbnailUrl(VideoRef $video, ?string $file): ?string
    {
        return isset($video->data['poster']) ? (string) $video->data['poster'] : null;
    }

    public function mp4(VideoRef $video, int $maxHeight): Mp4Result
    {
        return Mp4Result::ok((string) ($video->data['url'] ?? ''));
    }

    public function test(TestContext $context): TestResult
    {
        return TestResult::ok('Link-only: nothing to set up. Paste a shared link to a video.');
    }
}
