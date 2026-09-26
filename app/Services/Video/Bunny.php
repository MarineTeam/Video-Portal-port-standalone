<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Core\HttpResponse;

/**
 * Bunny's rules, pure (lib/bunny.ts): which MP4 renditions a video has and
 * which one to hand out, and how Bunny's URLs are signed.
 */
final class Bunny
{
    /** Heights Bunny writes an MP4 fallback at (play_<h>p.mp4). */
    public const MP4_HEIGHTS = [1080, 720, 480, 360, 240];
    public const DEFAULT_DOWNLOAD_HEIGHT = 720;
    public const EMBED_HOST = 'https://iframe.mediadelivery.net';
    public const API = 'https://video.bunnycdn.com';

    /**
     * "240p,360p,480p" → [480, 360, 240]: highest first, whatever order Bunny
     * wrote them in, only heights we know an MP4 for, each once.
     *
     * @return list<int>
     */
    public static function parseResolutions(?string $raw): array
    {
        $out = [];
        foreach (explode(',', (string) $raw) as $part) {
            if (preg_match('/^\s*(\d{3,4})p\s*$/i', $part, $m) && in_array((int) $m[1], self::MP4_HEIGHTS, true)) {
                $out[(int) $m[1]] = true;
            }
        }
        $heights = array_keys($out);
        rsort($heights);
        return $heights;
    }

    /**
     * The highest available height at or under the cap, or null.
     *
     * @param list<int> $available
     */
    public static function selectMp4Height(array $available, int $cap = self::DEFAULT_DOWNLOAD_HEIGHT): ?int
    {
        $best = null;
        foreach ($available as $h) {
            if ($h <= $cap && ($best === null || $h > $best)) {
                $best = $h;
            }
        }
        return $best;
    }

    /** The configured cap, or 720 when unset or not a height Bunny writes. */
    public static function downloadHeight(mixed $configured): int
    {
        $h = is_numeric($configured) ? (int) $configured : 0;
        return in_array($h, self::MP4_HEIGHTS, true) ? $h : self::DEFAULT_DOWNLOAD_HEIGHT;
    }

    /** https://<cdn>/<video>/play_<h>p.mp4 — the height is required, never assumed. */
    public static function mp4Url(string $cdnHostname, string $videoId, int $height): string
    {
        $host = self::host($cdnHostname);
        if ($host === '') {
            throw new VideoProviderException('The Bunny Stream CDN hostname isn’t set, so there’s no address to download from.');
        }
        if (!in_array($height, self::MP4_HEIGHTS, true)) {
            throw new \InvalidArgumentException("No MP4 rendition at {$height}p.");
        }
        return 'https://' . $host . '/' . rawurlencode($videoId) . '/play_' . $height . 'p.mp4';
    }

    /**
     * https://<cdn>/<video>/playlist.m3u8 — the adaptive stream, which is
     * what a television plays. Empty when no CDN hostname is configured,
     * because there is then no address at all rather than a broken one.
     */
    public static function hlsUrl(string $cdnHostname, string $videoId): string
    {
        $host = self::host($cdnHostname);
        return $host === '' || $videoId === '' ? '' : 'https://' . $host . '/' . rawurlencode($videoId) . '/playlist.m3u8';
    }

    public static function host(string $hostname): string
    {
        $host = strtolower(trim((string) preg_replace('#^https?://#i', '', trim($hostname)), '/'));
        return preg_match('/^[a-z0-9.-]+$/', $host) ? $host : '';
    }

    /**
     * What a probe of an MP4 URL means: 403/401 is a token or pull-zone
     * setting, not a missing file; 404/410 is missing; 200/206 is there;
     * anything else — a 5xx, a dead network — is an error, never "missing".
     *
     * @param callable(string): ?HttpResponse $fetch null for a network failure
     * @return 'ok'|'forbidden'|'missing'|'error'
     */
    public static function probeMp4(string $url, callable $fetch): string
    {
        if ($url === '') {
            return 'error';
        }
        try {
            $r = $fetch($url);
        } catch (\Throwable) {
            return 'error';
        }
        if ($r === null) {
            return 'error';
        }
        return match (true) {
            in_array($r->status, [200, 206], true) => 'ok',
            in_array($r->status, [401, 403], true) => 'forbidden',
            in_array($r->status, [404, 410], true) => 'missing',
            default => 'error',
        };
    }

    /** The Stream embed's token: sha256_hex(key . videoId . expires). */
    public static function embedToken(string $key, string $videoId, int $expires): string
    {
        return hash('sha256', $key . $videoId . $expires);
    }

    /**
     * A pull-zone URL signed with Bunny CDN token authentication:
     * base64url(sha256(key . path . expires)).
     */
    public static function signCdnUrl(string $url, ?string $key, int $expires): string
    {
        if ($key === null || $key === '') {
            return $url;
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        $token = rtrim(strtr(base64_encode(hash('sha256', $key . $path . $expires, true)), '+/', '-_'), '=');
        return $url . (str_contains($url, '?') ? '&' : '?') . 'token=' . $token . '&expires=' . $expires;
    }

    /** The TUS pre-signature: sha256(libraryId . apiKey . expires . videoId). */
    public static function tusSignature(string $libraryId, string $apiKey, int $expires, string $videoId): string
    {
        return hash('sha256', $libraryId . $apiKey . $expires . $videoId);
    }

    /**
     * An expiry rounded up to the next boundary, so the same page rendered a
     * minute later carries the same signed URLs (and caches).
     */
    public static function expiry(int $ttl, int $now): int
    {
        $step = max(60, (int) ($ttl / 4));
        return (int) (ceil(($now + $ttl) / $step) * $step);
    }

    /** Bunny's status codes → ours. */
    public static function status(int $code): string
    {
        return match ($code) {
            4, 7, 8 => 'READY',
            5, 6 => 'FAILED',
            default => 'PROCESSING',
        };
    }
}
