<?php

declare(strict_types=1);

namespace App\Services\Video;

/**
 * What fills the frame: somebody else's player in an iframe, or this site's
 * own <video> with sources (and tracks, and a poster). progressEvents says
 * whether the page can hear real playback position: a native element can,
 * and so can an iframe whose $protocol the player speaks over postMessage
 * (youtube, vimeo, or playerjs for Bunny); elsewhere the heartbeat
 * approximates from elapsed time.
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
        public readonly ?string $protocol = null,
    ) {
    }

    /** @param 'youtube'|'vimeo'|'playerjs'|null $protocol */
    public static function iframe(string $src, ?string $protocol = null): self
    {
        return new self('iframe', $src, progressEvents: $protocol !== null, protocol: $protocol);
    }

    /** @param list<array{src: string, srclang: string, label: string}> $tracks */
    public function withTracks(array $tracks): self
    {
        return new self($this->kind, $this->src, $this->sources, $tracks, $this->poster, $this->progressEvents, $this->hls, $this->protocol);
    }

    public function withPoster(?string $poster): self
    {
        return new self($this->kind, $this->src, $this->sources, $this->tracks, $poster, $this->progressEvents, $this->hls, $this->protocol);
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
