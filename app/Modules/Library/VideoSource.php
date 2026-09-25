<?php

declare(strict_types=1);

namespace App\Modules\Library;

/**
 * Which player fills the frame, from a video row (lib/video-source.ts). The
 * port stores the provider's id in videos.provider ('bunny', 'youtube',
 * 'vimeo', and the providers the port adds) and the provider's own id in
 * external_id; the original's three sources keep their names on the wire:
 * source BUNNY | YOUTUBE | VIMEO, bunnyVideoId for Bunny's, externalId for
 * the others.
 */
final class VideoSource
{
    public const SOURCES = ['bunny' => 'BUNNY', 'youtube' => 'YOUTUBE', 'vimeo' => 'VIMEO'];
    public const NAMES = ['BUNNY' => 'Bunny Stream', 'YOUTUBE' => 'YouTube', 'VIMEO' => 'Vimeo'];

    /** @param array<string, mixed> $video */
    public static function source(array $video): string
    {
        $provider = (string) ($video['provider'] ?? 'bunny');
        return self::SOURCES[$provider] ?? strtoupper(str_replace('-', '_', $provider));
    }

    /** @param array<string, mixed> $video */
    public static function isBunnyVideo(array $video): bool
    {
        return self::source($video) === 'BUNNY' && (string) ($video['external_id'] ?? '') !== '';
    }

    /**
     * The iframe for a video on YouTube or Vimeo, or Bunny's embed when
     * $bunnyEmbed is given (Bunny's needs the library and, with token auth,
     * a signature — the provider builds it). A start time goes in each
     * player's own way; zero is left off rather than sent as 0. No id, no
     * frame: an empty string rather than a broken one.
     *
     * @param array<string, mixed> $video
     * @param (callable(string $videoId, int $start): string)|null $bunnyEmbed
     */
    public static function embedUrl(array $video, int $startSeconds = 0, ?callable $bunnyEmbed = null): string
    {
        $id = (string) ($video['external_id'] ?? '');
        if ($id === '') {
            return '';
        }
        $start = max(0, $startSeconds);
        return match (self::source($video)) {
            'YOUTUBE' => 'https://www.youtube-nocookie.com/embed/' . rawurlencode($id) . '?rel=0' . ($start > 0 ? '&start=' . $start : ''),
            'VIMEO' => 'https://player.vimeo.com/video/' . rawurlencode($id) . '?dnt=1' . ($start > 0 ? '#t=' . $start . 's' : ''),
            'BUNNY' => $bunnyEmbed !== null ? $bunnyEmbed($id, $start) : '',
            default => '',
        };
    }

    /**
     * The thumbnail the source gave us; for a video stored in Bunny, Bunny's
     * own (through $bunnyThumb, never a URL on this site). An empty string
     * rather than a broken image.
     *
     * @param array<string, mixed> $video
     * @param (callable(string $videoId, ?string $file): string)|null $bunnyThumb
     */
    public static function thumbnailUrl(array $video, ?callable $bunnyThumb = null): string
    {
        $given = (string) ($video['external_thumbnail_url'] ?? '');
        if ($given !== '') {
            return $given;
        }
        if (self::isBunnyVideo($video) && $bunnyThumb !== null) {
            return $bunnyThumb((string) $video['external_id'], isset($video['thumbnail_file_name']) ? (string) $video['thumbnail_file_name'] : null);
        }
        return '';
    }

    /**
     * The page a person would land on at the source, for "Watch on YouTube".
     * A video that lives here has nowhere else to send anyone.
     *
     * @param array<string, mixed> $video
     */
    public static function watchAtSourceUrl(array $video): ?string
    {
        $id = (string) ($video['external_id'] ?? '');
        $given = (string) ($video['external_url'] ?? '');
        return match (self::source($video)) {
            'YOUTUBE' => $given !== '' ? $given : ($id !== '' ? 'https://www.youtube.com/watch?v=' . rawurlencode($id) : null),
            'VIMEO' => $given !== '' ? $given : ($id !== '' ? 'https://vimeo.com/' . rawurlencode($id) : null),
            'BUNNY' => null,
            default => $given !== '' ? $given : null,
        };
    }

    public static function sourceName(string $source): string
    {
        return self::NAMES[$source] ?? ucwords(strtolower(str_replace('_', ' ', $source)));
    }
}
