<?php

declare(strict_types=1);

namespace App\Services\Video;

/**
 * What fills the frame: somebody else's player in an iframe, or this site's
 * own <video> with sources (and tracks, and a poster). progressEvents says
 * whether the page can hear real playback position (a native element can;
 * most iframes can't, and the heartbeat approximates).
 */
final class PlayerSpec
{
    /**
     * @param 'iframe'|'native' $kind
     * @param list<array{src: string, type: string}> $sources
     * @param list<array{src: string, srclang: string, label: string}> $tracks
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $src = '',
        public readonly array $sources = [],
        public readonly array $tracks = [],
        public readonly ?string $poster = null,
        public readonly bool $progressEvents = false,
        public readonly bool $hls = false,
    ) {
    }

    public static function iframe(string $src): self
    {
        return new self('iframe', $src);
    }

    /** @param list<array{src: string, type: string}> $sources */
    public static function native(array $sources, ?string $poster = null, bool $hls = false): self
    {
        return new self('native', '', $sources, [], $poster, true, $hls);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
