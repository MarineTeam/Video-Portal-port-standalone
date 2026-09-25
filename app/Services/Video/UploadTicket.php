<?php

declare(strict_types=1);

namespace App\Services\Video;

/**
 * What the browser's one uploader must do next — and in none of these does a
 * long-lived credential reach the browser:
 *
 *   tus        endpoint, headers, metadata (Bunny, Vimeo)
 *   put        one presigned PUT (S3-compatible, small files)
 *   multipart  presigned part URLs, then a completion call (S3, large files)
 *   resumable  a session URL the server opened with its own token (YouTube,
 *              Google Drive, OneDrive)
 *   dropbox    a four-hour access token for Dropbox's upload-session calls
 *   chunked    slices through this site (the host's own disk)
 *
 * $id and $data are what the videos row keeps (external_id, provider_data).
 */
final class UploadTicket
{
    public const KINDS = ['tus', 'put', 'multipart', 'resumable', 'dropbox', 'chunked'];

    /**
     * @param 'tus'|'put'|'multipart'|'resumable'|'dropbox'|'chunked' $kind
     * @param array<string, mixed> $client handed to the browser as-is
     * @param array<string, mixed> $data kept on the row
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $id,
        public readonly array $client,
        public readonly array $data = [],
    ) {
    }
}
