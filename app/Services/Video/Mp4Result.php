<?php

declare(strict_types=1);

namespace App\Services\Video;

/**
 * A file to download or cast, or the specific reason there isn't one — so a
 * misconfigured library reads differently from an unencoded rendition.
 */
final class Mp4Result
{
    public const REASONS = [
        'mp4_unavailable' => 'This video has no downloadable file yet: MP4 fallback isn’t turned on for it at the provider (it only applies to videos uploaded after it was switched on).',
        'resolution_unavailable' => 'None of this video’s downloadable files is at or under the size this site allows.',
        'mp4_forbidden' => 'The provider refused the download link — usually a token-authentication or pull-zone setting rather than a missing file.',
        'mp4_missing' => 'The provider says the downloadable file doesn’t exist.',
        'provider_error' => 'The provider couldn’t be reached to find the file. Try again in a minute.',
        'not_supported' => 'This video’s provider doesn’t offer a file to download.',
        'plan' => 'Your plan with this provider doesn’t give file links.',
    ];

    private function __construct(
        public readonly bool $ok,
        public readonly ?string $url,
        public readonly ?int $height,
        public readonly ?string $reason,
    ) {
    }

    public static function ok(string $url, ?int $height = null): self
    {
        return new self(true, $url, $height, null);
    }

    public static function reason(string $reason): self
    {
        return new self(false, null, null, isset(self::REASONS[$reason]) ? $reason : 'provider_error');
    }

    public function message(): ?string
    {
        return $this->reason === null ? null : self::REASONS[$this->reason];
    }
}
