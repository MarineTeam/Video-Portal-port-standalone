<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Core\Http;
use App\Core\HttpResponse;
use App\Services\TestContext;
use App\Services\TestResult;

/**
 * bunny.net Stream: what src/lib/bunny.ts does for Stream. A placeholder by
 * POST /library/{id}/videos; the browser uploads by TUS straight to Bunny with
 * a one-hour pre-signature (no API key ever reaches it) and then asks the
 * site to sync the video's status. Embeds and thumbnails are signed per
 * request when token authentication is on; MP4 fallback per video, at the
 * highest rendition at or under the configured height; captions in Bunny.
 */
final class BunnyStreamProvider extends BaseVideoProvider
{
    public static function id(): string
    {
        return 'bunny';
    }

    public static function label(): string
    {
        return 'bunny.net Stream';
    }

    public static function requiresOutboundHttps(): bool
    {
        return true;
    }

    public static function limits(): string
    {
        return 'A paid service (cheap). Downloads and casting need “MP4 fallback” turned on for the library, which only applies to videos uploaded after it was switched on.';
    }

    public static function cspSources(): array
    {
        return ['frame' => ['https://iframe.mediadelivery.net'], 'connect' => ['https://video.bunnycdn.com']];
    }

    public static function configSchema(): array
    {
        return [
            ['key' => 'libraryId', 'label' => 'Stream library ID', 'type' => 'text', 'required' => true],
            ['key' => 'apiKey', 'label' => 'Library API key', 'type' => 'text', 'secret' => true, 'required' => true],
            ['key' => 'cdnHostname', 'label' => 'CDN hostname (vz-….b-cdn.net)', 'type' => 'text', 'required' => true],
            ['key' => 'embedTokenKey', 'label' => 'Embed token authentication key (optional)', 'type' => 'text', 'secret' => true, 'help' => 'Set when “Embed view token authentication” is on for the library.'],
            ['key' => 'cdnTokenKey', 'label' => 'CDN token authentication key (optional)', 'type' => 'text', 'secret' => true, 'help' => 'The pull zone’s URL token key, when token authentication is on for it. Thumbnails and downloads are signed with it.'],
            ['key' => 'downloadHeight', 'label' => 'Largest download', 'type' => 'select', 'options' => ['720' => '720p (default)', '1080' => '1080p', '480' => '480p', '360' => '360p', '240' => '240p'], 'default' => '720'],
        ];
    }

    private function lib(): string
    {
        return $this->str('libraryId');
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['AccessKey' => $this->str('apiKey'), 'Accept' => 'application/json', 'Content-Type' => 'application/json'];
    }

    private function api(string $method, string $path, ?array $body = null): HttpResponse
    {
        return Http::request($method, Bunny::API . '/library/' . rawurlencode($this->lib()) . $path, $this->headers(), $body === null ? null : (string) json_encode($body));
    }

    public function capabilities(): VideoCapabilities
    {
        return new VideoCapabilities(upload: true, transcodes: true, thumbnails: true, duration: true, captions: true, mp4: true, enforcesPrivacy: $this->str('embedTokenKey') !== '');
    }

    public function createUpload(string $title, UploadHints $hints): UploadTicket
    {
        $r = $this->api('POST', '/videos', ['title' => mb_substr($title, 0, 255)]);
        $guid = $r->json()['guid'] ?? null;
        if (!$r->ok() || !is_string($guid)) {
            throw new VideoProviderException('Bunny wouldn’t create the video (' . (string) ($r->json()['Message'] ?? $r->status) . ').');
        }
        $expires = time() + 3600;
        return new UploadTicket('tus', $guid, [
            'endpoint' => Bunny::API . '/tusupload',
            'headers' => [
                'AuthorizationSignature' => Bunny::tusSignature($this->lib(), $this->str('apiKey'), $expires, $guid),
                'AuthorizationExpire' => (string) $expires,
                'VideoId' => $guid,
                'LibraryId' => $this->lib(),
            ],
            'metadata' => ['filetype' => $hints->mimeType, 'title' => $title],
        ], ['libraryId' => $this->lib()]);
    }

    public function get(VideoRef $video): VideoInfo
    {
        $r = $this->api('GET', '/videos/' . rawurlencode($video->id));
        $d = $r->json();
        if (!$r->ok() || !is_array($d)) {
            throw new VideoProviderException($r->status === 404 ? 'Bunny has no such video.' : 'Bunny didn’t answer (' . $r->status . ').');
        }
        return new VideoInfo(
            Bunny::status((int) ($d['status'] ?? 0)),
            isset($d['length']) ? (int) $d['length'] : null,
            isset($d['thumbnailFileName']) && $d['thumbnailFileName'] !== '' ? (string) $d['thumbnailFileName'] : null,
            isset($d['hasMP4Fallback']) ? (bool) $d['hasMP4Fallback'] : null,
            isset($d['availableResolutions']) ? (string) $d['availableResolutions'] : null,
        );
    }

    public function owns(VideoRef $video): bool
    {
        return $video->id !== '';
    }

    public function delete(VideoRef $video): void
    {
        $r = $this->api('DELETE', '/videos/' . rawurlencode($video->id));
        if (!$r->ok() && $r->status !== 404) {
            throw new VideoProviderException('Bunny refused the delete (' . $r->status . ').');
        }
    }

    public function player(VideoRef $video, PlayerOptions $options): PlayerSpec
    {
        return PlayerSpec::iframe($this->embedUrl($video->id, $options->startSeconds, $options->autoplay), 'playerjs');
    }

    /** The iframe, signed per request when embed token authentication is on. */
    public function embedUrl(string $videoId, int $start = 0, bool $autoplay = false): string
    {
        $library = (string) ($this->lib());
        $query = ['autoplay' => $autoplay ? 'true' : 'false', 'preload' => 'true', 'responsive' => 'true'];
        if ($this->str('embedTokenKey') !== '') {
            $expires = Bunny::expiry(3600, time());
            $query = ['token' => Bunny::embedToken($this->str('embedTokenKey'), $videoId, $expires), 'expires' => (string) $expires] + $query;
        }
        if ($start > 0) {
            $query['t'] = (string) $start;
        }
        return Bunny::EMBED_HOST . '/embed/' . rawurlencode($library) . '/' . rawurlencode($videoId) . '?' . http_build_query($query);
    }

    private function cdnKey(): ?string
    {
        $key = $this->str('cdnTokenKey');
        return $key !== '' ? $key : null;
    }

    /**
     * The adaptive stream a television can play by itself, rather than the
     * embed a set-top box has no browser for. Signed for a day where token
     * authentication is on, which outlives the hour a platform caches a
     * catalogue for.
     */
    public function hlsUrl(VideoRef $video): ?string
    {
        $url = Bunny::hlsUrl($this->str('cdnHostname'), $video->id);
        return $url === '' ? null : Bunny::signCdnUrl($url, $this->cdnKey(), Bunny::expiry(86400, time()));
    }

    public function thumbnailUrl(VideoRef $video, ?string $file): ?string
    {
        $host = Bunny::host($this->str('cdnHostname'));
        if ($host === '' || $video->id === '') {
            return null;
        }
        $url = 'https://' . $host . '/' . rawurlencode($video->id) . '/' . rawurlencode($file !== null && $file !== '' ? $file : 'thumbnail.jpg');
        return Bunny::signCdnUrl($url, $this->cdnKey(), Bunny::expiry(86400, time()));
    }

    public function setThumbnail(VideoRef $video, string $imageUrl): void
    {
        $r = Http::request('POST', Bunny::API . '/library/' . rawurlencode($this->lib()) . '/videos/' . rawurlencode($video->id) . '/thumbnail?' . http_build_query(['thumbnailUrl' => $imageUrl]), $this->headers());
        if (!$r->ok()) {
            throw new VideoProviderException('Bunny wouldn’t take that thumbnail (' . $r->status . ').');
        }
    }

    /**
     * A signed MP4 for downloads and casting, or the reason there isn't one.
     * $video->data may carry the cached hasMp4Fallback / mp4Resolutions so a
     * member's tap is a database read, not an API call.
     */
    public function mp4(VideoRef $video, int $maxHeight): Mp4Result
    {
        $has = $video->data['hasMp4Fallback'] ?? null;
        $resolutions = $video->data['mp4Resolutions'] ?? null;
        if ($has === null) {
            try {
                $info = $this->get($video);
            } catch (VideoProviderException) {
                return Mp4Result::reason('provider_error');
            }
            $has = $info->hasMp4Fallback;
            $resolutions = $info->mp4Resolutions;
        }
        if ($has !== true) {
            return Mp4Result::reason('mp4_unavailable');
        }
        // The library's own "largest download" setting caps whatever the caller asks for.
        $height = Bunny::selectMp4Height(Bunny::parseResolutions(is_string($resolutions) ? $resolutions : null), min($maxHeight, Bunny::downloadHeight($this->cfg('downloadHeight'))));
        if ($height === null) {
            return Mp4Result::reason('resolution_unavailable');
        }
        try {
            $url = Bunny::signCdnUrl(Bunny::mp4Url($this->str('cdnHostname'), $video->id, $height), $this->cdnKey(), time() + 1800);
        } catch (VideoProviderException) {
            return Mp4Result::reason('provider_error');
        }
        $probe = Bunny::probeMp4($url, fn (string $u) => Http::request('GET', $u, ['Range' => 'bytes=0-0'], null, ['timeout' => 8, 'maxBytes' => 1024]));
        return match ($probe) {
            'forbidden' => Mp4Result::reason('mp4_forbidden'),
            'missing' => Mp4Result::reason('mp4_missing'),
            // A probe that failed to answer doesn't block a file Bunny's API confirmed.
            default => Mp4Result::ok($url, $height),
        };
    }

    public function captions(VideoRef $video): CaptionOps
    {
        $lib = $this->lib();
        $headers = $this->headers();
        $id = $video->id;
        return new class ($lib, $id, $headers) implements CaptionOps {
            /** @param array<string, string> $headers */
            public function __construct(private readonly string $lib, private readonly string $id, private readonly array $headers)
            {
            }

            private function base(): string
            {
                return Bunny::API . '/library/' . rawurlencode($this->lib) . '/videos/' . rawurlencode($this->id);
            }

            public function list(): array
            {
                $out = [];
                foreach ((array) (Http::request('GET', $this->base(), $this->headers)->json()['captions'] ?? []) as $c) {
                    $out[] = ['srclang' => (string) ($c['srclang'] ?? ''), 'label' => (string) ($c['label'] ?? '')];
                }
                return $out;
            }

            public function add(string $srclang, string $label, string $vtt): void
            {
                $r = Http::request('POST', $this->base() . '/captions/' . rawurlencode($srclang), $this->headers, (string) json_encode(['srclang' => $srclang, 'label' => $label, 'captionsFile' => base64_encode($vtt)]));
                if (!$r->ok()) {
                    throw new VideoProviderException('Bunny wouldn’t take the captions (' . $r->status . ').');
                }
            }

            public function delete(string $srclang): void
            {
                $r = Http::request('DELETE', $this->base() . '/captions/' . rawurlencode($srclang), $this->headers);
                if (!$r->ok() && $r->status !== 404) {
                    throw new VideoProviderException('Bunny wouldn’t remove the captions (' . $r->status . ').');
                }
            }
        };
    }

    /**
     * The library's videos, for "import from the Bunny library".
     *
     * @return list<array{guid: string, title: string, length: ?int, status: string}>
     */
    public function listLibrary(int $page = 1): array
    {
        $r = $this->api('GET', '/videos?' . http_build_query(['page' => $page, 'itemsPerPage' => 100, 'orderBy' => 'date']));
        if (!$r->ok()) {
            throw new VideoProviderException('Bunny didn’t list the library (' . $r->status . ').');
        }
        $out = [];
        foreach ((array) ($r->json()['items'] ?? []) as $item) {
            if (isset($item['guid'])) {
                $out[] = ['guid' => (string) $item['guid'], 'title' => (string) ($item['title'] ?? ''), 'length' => isset($item['length']) ? (int) $item['length'] : null, 'status' => Bunny::status((int) ($item['status'] ?? 0))];
            }
        }
        return $out;
    }

    public function test(TestContext $context): TestResult
    {
        if ($this->lib() === '' || $this->str('apiKey') === '') {
            return TestResult::fail('Fill in the library ID and its API key (Stream → your library → API).');
        }
        try {
            $r = $this->api('GET', '/videos?page=1&itemsPerPage=1');
        } catch (\App\Core\HttpException $e) {
            return TestResult::fail('Bunny couldn’t be reached: ' . $e->getMessage());
        }
        if (!$r->ok()) {
            return TestResult::fail($r->status === 401 ? 'Bunny refused the API key for that library.' : 'Bunny didn’t answer for that library (' . $r->status . ').');
        }
        $host = Bunny::host($this->str('cdnHostname'));
        if ($host === '') {
            return TestResult::fail('Fill in the CDN hostname (Stream → your library → API → CDN hostname).');
        }
        try {
            Http::request('HEAD', 'https://' . $host . '/', [], null, ['timeout' => 8]);
        } catch (\App\Core\HttpException $e) {
            return TestResult::fail('The CDN hostname ' . $host . ' doesn’t answer: ' . $e->getMessage());
        }
        $first = $r->json()['items'][0]['guid'] ?? null;
        if ($this->cdnKey() !== null && is_string($first)) {
            $unsigned = 'https://' . $host . '/' . rawurlencode($first) . '/thumbnail.jpg';
            $range = ['Range' => 'bytes=0-0'];
            $signed = Http::request('GET', Bunny::signCdnUrl($unsigned, $this->cdnKey(), time() + 300), $range, null, ['timeout' => 8, 'maxBytes' => 5_000_000]);
            $plain = Http::request('GET', $unsigned, $range, null, ['timeout' => 8, 'maxBytes' => 5_000_000]);
            if (!$signed->ok()) {
                return TestResult::fail('A thumbnail signed with the CDN token key was refused (' . $signed->status . '): the key doesn’t match the pull zone’s.');
            }
            if ($plain->ok()) {
                return TestResult::fail('An unsigned thumbnail still loads, so token authentication isn’t on for the pull zone. Turn it on there, or clear the CDN token key here.');
            }
        }
        return TestResult::ok('Bunny answered for library ' . $this->lib() . ' and the CDN hostname works.');
    }
}
