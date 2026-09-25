<?php

declare(strict_types=1);

namespace App\Services\Video;

/** A pasted URL, resolved by the provider that recognised it. */
final class LinkedVideo
{
    /** @param array<string, mixed> $data kept on the row as provider_data */
    public function __construct(
        public readonly string $id,
        public readonly ?string $title = null,
        public readonly ?int $durationSeconds = null,
        public readonly ?string $thumbnailUrl = null,
        public readonly ?string $externalUrl = null,
        public readonly array $data = [],
        public readonly ?string $description = null,
    ) {
    }
}
