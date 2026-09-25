<?php

declare(strict_types=1);

namespace App\Services\Video;

final class UploadHints
{
    public function __construct(
        public readonly string $fileName,
        public readonly int $size,
        public readonly string $mimeType = 'video/mp4',
        public readonly bool $memberOnly = false,
    ) {
    }
}
