<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Core\Http;
use App\Services\TestContext;
use App\Services\TestResult;

/**
 * Vimeo. Links resolve by oEmbed (or the API with a token) and play on
 * player.vimeo.com with dnt=1. With a token that has the upload scope,
 * uploads go by tus straight from the browser to Vimeo; privacy follows the
 * video's members-only flag; captions are text tracks; MP4 comes from files
 * on plans that expose them.
 */
final class VimeoProvider extends BaseVideoProvider
{
    public const API = 'https://api.vimeo.com';

    public static function id(): string
    {
        return 'vimeo';
    }

    public static function label(): string
    {
        return 'Vimeo';
    }

    public static function requiresOutboundHttps(): bool
    {
        return true;
    }

    public static function limits(): string
    {
        return 'Keeps a members-only video from strangers only partly: a domain-restricted embed on paid plans. File links for downloads and casting need a paid plan; the free tier uploads 500 MB a week.';
    }

    public static function cspSources(): array
    {
        return ['frame' => ['https://player.vimeo.com'], 'connect' => ['https://*.cloud.vimeo.com', 'https://*.tus.vimeo.com']];
    }

    public static function configSchema(): array
    {
        return [['key' => 'token', 'label' => 'Personal access token (for uploads and private details)', 'type' => 'text', 'secret' => true, 'help' => 'Scopes: public, private, upload, edit, delete, video_files.']];
    }

    private function headers(): array
    {
        return ['Authorization' => 'bearer ' . $this->str('token'), 'Accept' => 'application/vnd.vimeo.*+json;version=3.4', 'Content-Type' => 'application/json'];
    }

    public function capabilities(): VideoCapabilities
    {
        $token = $this->str('token') !== '';
        return new VideoCapabilities(upload: $token, link: true, transcodes: true, thumbnails: true, duration: true, captions: $token, mp4: $token);
    }

    public function matchesLink(string $url): bool
    {
        return Links::vimeo($url) !== null;
    }

    public function resolveLink(string $url): LinkedVideo
    {
        $v = Links::vimeo($url) ?? throw new VideoProviderException('That isn’t a Vimeo video link.');
        $page = 'https://vimeo.com/' . $v['id'] . ($v['hash'] !== null ? '/' . $v['hash'] : '');
        if ($this->str('token') !== '') {
            $r = Http::request('GET', self::API . '/videos/' . $v['id'] . ($v['hash'] !== null ? ':' . $v['hash'] : '') . '?fields=name,description,duration,pictures.base_link,link', $this->headers());
            $d = $r->json();
            if ($r->ok() && is_array($d)) {
                return new LinkedVideo($v['id'], (string) ($d['name'] ?? ''), isset($d['duration']) ? (int) $d['duration'] : null, isset($d['pictures']['base_link']) ? (string) $d['pictures']['base_link'] . '_640' : null, (string) ($d['link'] ?? $page), ['hash' => $v['hash']], isset($d['description']) ? (string) $d['description'] : null);
            }
        }
        $r = Http::request('GET', 'https://vimeo.com/api/oembed.json?' . http_build_query(['url' => $page]));
        $d = $r->json();
        if (!$r->ok() || !is_array($d)) {
            throw new VideoProviderException('Vimeo says that video isn’t available to embed.');
        }
        return new LinkedVideo($v['id'], (string) ($d['title'] ?? ''), isset($d['duration']) ? (int) $d['duration'] : null, isset($d['thumbnail_url']) ? (string) $d['thumbnail_url'] : null, $page, ['hash' => $v['hash']], isset($d['description']) ? (string) $d['description'] : null);
    }

    public function player(VideoRef $video, PlayerOptions $options): PlayerSpec
    {
        $hash = $video->data['hash'] ?? null;
        return PlayerSpec::iframe('https://player.vimeo.com/video/' . rawurlencode($video->id) . '?dnt=1'
            . (is_string($hash) && $hash !== '' ? '&h=' . rawurlencode($hash) : '')
            . ($options->autoplay ? '&autoplay=1' : '')
            . ($options->startSeconds > 0 ? '#t=' . $options->startSeconds . 's' : ''));
    }

    public function thumbnailUrl(VideoRef $video, ?string $file): ?string
    {
        $thumb = $video->data['thumbnail'] ?? null;
        return is_string($thumb) ? $thumb : null;
    }

    public function owns(VideoRef $video): bool
    {
        return ($video->data['uploaded'] ?? false) === true;
    }

    public function createUpload(string $title, UploadHints $hints): UploadTicket
    {
        if ($this->str('token') === '') {
            parent::createUpload($title, $hints);
        }
        $r = Http::request('POST', self::API . '/me/videos', $this->headers(), (string) json_encode([
            'upload' => ['approach' => 'tus', 'size' => $hints->size],
            'name' => mb_substr($title, 0, 128),
            'privacy' => ['view' => $hints->memberOnly ? 'disable' : 'unlisted', 'embed' => 'public'],
        ]));
        $d = $r->json();
        $link = $d['upload']['upload_link'] ?? null;
        $uri = (string) ($d['uri'] ?? '');
        if (!$r->ok() || !is_string($link) || !preg_match('#/videos/(\d+)#', $uri, $m)) {
            throw new VideoProviderException('Vimeo wouldn’t open an upload: ' . (string) ($d['error'] ?? $d['developer_message'] ?? $r->status));
        }
        return new UploadTicket('tus', $m[1], ['uploadUrl' => $link, 'headers' => ['Tus-Resumable' => '1.0.0']], ['uploaded' => true, 'hash' => null]);
    }

    public function get(VideoRef $video): VideoInfo
    {
        if ($this->str('token') === '') {
            return new VideoInfo('READY');
        }
        $r = Http::request('GET', self::API . '/videos/' . rawurlencode($video->id) . '?fields=transcode.status,duration,pictures.base_link', $this->headers());
        $d = $r->json();
        if (!$r->ok() || !is_array($d)) {
            return new VideoInfo('FAILED');
        }
        $status = (string) ($d['transcode']['status'] ?? 'complete');
        return new VideoInfo(
            $status === 'complete' ? 'READY' : ($status === 'error' ? 'FAILED' : 'PROCESSING'),
            isset($d['duration']) ? (int) $d['duration'] : null,
            null, null, null,
            isset($d['pictures']['base_link']) ? ['thumbnail' => (string) $d['pictures']['base_link'] . '_640'] : [],
        );
    }

    public function delete(VideoRef $video): void
    {
        if (!$this->owns($video) || $this->str('token') === '') {
            return;
        }
        $r = Http::request('DELETE', self::API . '/videos/' . rawurlencode($video->id), $this->headers());
        if (!$r->ok() && $r->status !== 404) {
            throw new VideoProviderException('Vimeo refused the delete (' . $r->status . ').');
        }
    }

    public function mp4(VideoRef $video, int $maxHeight): Mp4Result
    {
        if ($this->str('token') === '') {
            return Mp4Result::reason('not_supported');
        }
        $r = Http::request('GET', self::API . '/videos/' . rawurlencode($video->id) . '?fields=files', $this->headers());
        if (!$r->ok()) {
            return Mp4Result::reason($r->status === 403 ? 'mp4_forbidden' : ($r->status === 404 ? 'mp4_missing' : 'provider_error'));
        }
        $best = null;
        foreach ((array) ($r->json()['files'] ?? []) as $file) {
            $height = (int) ($file['height'] ?? 0);
            if (($file['type'] ?? '') === 'video/mp4' && isset($file['link']) && $height > 0 && $height <= $maxHeight && ($best === null || $height > $best['height'])) {
                $best = ['height' => $height, 'link' => (string) $file['link']];
            }
        }
        if ($best === null) {
            return Mp4Result::reason(($r->json()['files'] ?? []) === [] ? 'plan' : 'resolution_unavailable');
        }
        return Mp4Result::ok($best['link'], $best['height']);
    }

    public function captions(VideoRef $video): ?CaptionOps
    {
        if ($this->str('token') === '') {
            return null;
        }
        $headers = $this->headers();
        $base = self::API . '/videos/' . rawurlencode($video->id) . '/texttracks';
        return new class ($base, $headers) implements CaptionOps {
            /** @param array<string, string> $headers */
            public function __construct(private readonly string $base, private readonly array $headers)
            {
            }

            public function list(): array
            {
                $out = [];
                foreach ((array) (Http::request('GET', $this->base, $this->headers)->json()['data'] ?? []) as $t) {
                    $out[] = ['srclang' => (string) ($t['language'] ?? ''), 'label' => (string) ($t['name'] ?? '')];
                }
                return $out;
            }

            public function add(string $srclang, string $label, string $vtt): void
            {
                $r = Http::request('POST', $this->base, $this->headers, (string) json_encode(['type' => 'captions', 'language' => $srclang, 'name' => $label]));
                $link = $r->json()['link'] ?? null;
                if (!$r->ok() || !is_string($link)) {
                    throw new VideoProviderException('Vimeo wouldn’t take the captions.');
                }
                $put = Http::request('PUT', $link, ['Content-Type' => 'text/vtt'], $vtt);
                if (!$put->ok()) {
                    throw new VideoProviderException('Vimeo wouldn’t take the captions file.');
                }
            }

            public function delete(string $srclang): void
            {
                foreach ((array) (Http::request('GET', $this->base, $this->headers)->json()['data'] ?? []) as $t) {
                    if (($t['language'] ?? null) === $srclang && isset($t['uri'])) {
                        Http::request('DELETE', 'https://api.vimeo.com' . $t['uri'], $this->headers);
                    }
                }
            }
        };
    }

    public function test(TestContext $context): TestResult
    {
        if ($this->str('token') === '') {
            return TestResult::ok('Links work without settings. Nothing to test.');
        }
        $r = Http::request('GET', self::API . '/me?fields=name,upload_quota', $this->headers());
        if (!$r->ok()) {
            return TestResult::fail('Vimeo refused the token (' . $r->status . '). Make a new one with the upload scope.');
        }
        $scopes = (string) ($r->header('x-accepted-oauth-scopes') ?? $r->header('x-oauth-scopes') ?? '');
        $free = $r->json()['upload_quota']['space']['free'] ?? null;
        return TestResult::ok('Signed in to Vimeo as ' . (string) ($r->json()['name'] ?? 'the account')
            . (is_numeric($free) ? ', with ' . round((float) $free / 1048576) . ' MB of upload quota left' : '')
            . ($scopes !== '' && !str_contains($scopes, 'upload') ? '. This token has no upload scope, so uploads won’t work.' : '.'));
    }
}
