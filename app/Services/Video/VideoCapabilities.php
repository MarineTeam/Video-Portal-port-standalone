<?php

declare(strict_types=1);

namespace App\Services\Video;

/**
 * What a provider can honestly do; the admin screens show these rather than
 * promising the same of every provider.
 */
final class VideoCapabilities
{
    public function __construct(
        public readonly bool $upload = false,
        public readonly bool $link = false,
        public readonly bool $transcodes = false,
        public readonly bool $thumbnails = false,
        public readonly bool $duration = false,
        public readonly bool $captions = false,
        public readonly bool $mp4 = false,
        public readonly bool $enforcesPrivacy = false,
        public readonly bool $progressEvents = false,
    ) {
    }

    /** @return array<string, bool> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
