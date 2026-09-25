<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Core\Http;
use App\Services\TestContext;
use App\Services\TestResult;

/**
 * The Internet Archive: archive.org/details/<identifier>. The metadata API
 * names the files; the H.264 derivative (or the MPEG4 original) plays
 * natively from archive.org/download with Range and CORS; the thumbnail is
 * services/img. Everything there is public.
 */
final class ArchiveProvider extends BaseVideoProvider
{
    public static function id(): string
    {
        return 'archive';
    }

    public static function label(): string
    {
        return 'Internet Archive';
    }

    public static function requiresOutboundHttps(): bool
    {
        return true;
    }

    public static function limits(): string
    {
        return 'Everything on the Archive is public, so "members only" can only hide the page here, never the file. Playback speed varies.';
    }

    public function capabilities(): VideoCapabilities
    {
        return new VideoCapabilities(link: true, thumbnails: true, duration: true, mp4: true, progressEvents: true);
    }

    public function matchesLink(string $url): bool
    {
        return Links::archiveIdentifier($url) !== null;
    }

    /**
     * The file to play: the H.264 derivative, else an MPEG4, largest first.
     *
     * @param list<array<string, mixed>> $files
     * @return array<string, mixed>|null
     */
    public static function pickFile(array $files): ?array
    {
        $rank = fn (array $f) => match (strtolower((string) ($f['format'] ?? ''))) {
            'h.264 hd' => 4, 'h.264' => 3, 'mpeg4' => 2, '512kb mpeg4' => 1, default => 0,
        };
        $candidates = array_values(array_filter($files, fn ($f) => $rank($f) > 0 && str_ends_with(strtolower((string) ($f['name'] ?? '')), '.mp4')));
        usort($candidates, fn ($a, $b) => [$rank($b), (int) ($b['size'] ?? 0)] <=> [$rank($a), (int) ($a['size'] ?? 0)]);
        return $candidates[0] ?? null;
    }

    public function resolveLink(string $url): LinkedVideo
    {
        $id = Links::archiveIdentifier($url) ?? throw new VideoProviderException('That isn’t an archive.org item link.');
        $r = Http::request('GET', 'https://archive.org/metadata/' . rawurlencode($id));
        $d = $r->json();
        if (!$r->ok() || !is_array($d) || !isset($d['files'])) {
            throw new VideoProviderException('The Archive has no item called ' . $id . '.');
        }
        $file = self::pickFile(array_values((array) $d['files']));
        if ($file === null) {
            throw new VideoProviderException('That item has no MP4 file to play (the Archive may still be making one).');
        }
        return new LinkedVideo(
            $id,
            (string) ($d['metadata']['title'] ?? $id),
            isset($file['length']) ? (int) round((float) $file['length']) : null,
            'https://archive.org/services/img/' . rawurlencode($id),
            'https://archive.org/details/' . rawurlencode($id),
            ['file' => (string) $file['name']],
            isset($d['metadata']['description']) && is_string($d['metadata']['description']) ? strip_tags($d['metadata']['description']) : null,
        );
    }

    private function fileUrl(VideoRef $video): string
    {
        return 'https://archive.org/download/' . rawurlencode($video->id) . '/' . implode('/', array_map('rawurlencode', explode('/', (string) ($video->data['file'] ?? ''))));
    }

    public function player(VideoRef $video, PlayerOptions $options): PlayerSpec
    {
        return PlayerSpec::native([['src' => $this->fileUrl($video), 'type' => 'video/mp4']], $this->thumbnailUrl($video, null));
    }

    public function thumbnailUrl(VideoRef $video, ?string $file): string
    {
        return 'https://archive.org/services/img/' . rawurlencode($video->id);
    }

    public function mp4(VideoRef $video, int $maxHeight): Mp4Result
    {
        return Mp4Result::ok($this->fileUrl($video));
    }

    public function test(TestContext $context): TestResult
    {
        return TestResult::ok('Nothing to set up: paste any archive.org item link.');
    }
}
