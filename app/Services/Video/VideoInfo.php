<?php

declare(strict_types=1);

namespace App\Services\Video;

/** A provider's current word on a video, for the status sync. */
final class VideoInfo
{
    /**
     * @param 'PROCESSING'|'READY'|'FAILED' $status
     * @param array<string, mixed> $data provider_data to merge into the row
     */
    public function __construct(
        public readonly string $status,
        public readonly ?int $durationSeconds = null,
        public readonly ?string $thumbnailFileName = null,
        public readonly ?bool $hasMp4Fallback = null,
        public readonly ?string $mp4Resolutions = null,
        public readonly array $data = [],
    ) {
    }
}
