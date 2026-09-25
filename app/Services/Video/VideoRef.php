<?php

declare(strict_types=1);

namespace App\Services\Video;

/**
 * A video as a provider sees it: its id there (external_id) and whatever the
 * provider keeps beside it (provider_data — an object key, a normalised
 * link, a library id). The interface in the brief passes a bare id; a
 * provider like S3 or the host's disk needs more than one string, so every
 * method takes this instead (recorded under Deviations).
 */
final class VideoRef
{
    /** @param array<string, mixed> $data */
    public function __construct(public readonly string $id, public readonly array $data = [])
    {
    }

    /** @param array<string, mixed> $row a videos row */
    public static function fromRow(array $row): self
    {
        $data = $row['provider_data'] ?? null;
        if (is_string($data)) {
            $data = json_decode($data, true);
        }
        $data = is_array($data) ? $data : [];
        if (($row['bunny_library_id'] ?? null) !== null) {
            $data['libraryId'] ??= (string) $row['bunny_library_id'];
        }
        return new self((string) ($row['external_id'] ?? ''), $data);
    }
}
