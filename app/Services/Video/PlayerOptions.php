<?php

declare(strict_types=1);

namespace App\Services\Video;

final class PlayerOptions
{
    public function __construct(
        public readonly int $startSeconds = 0,
        public readonly bool $autoplay = false,
        public readonly bool $memberOnly = false,
    ) {
    }
}
