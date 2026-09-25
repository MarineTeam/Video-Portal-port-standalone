<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Services\BaseProvider;

/**
 * Honest defaults: a provider states what it can do and inherits a clear
 * refusal for the rest, never a silent success.
 */
abstract class BaseVideoProvider extends BaseProvider implements VideoProvider
{
    public static function slot(): string
    {
        return 'video';
    }

    public function createUpload(string $title, UploadHints $hints): UploadTicket
    {
        throw new VideoProviderException(static::label() . ' doesn’t take uploads from this site; paste a link instead.');
    }

    public function completeUpload(VideoRef $video): VideoInfo
    {
        return $this->get($video);
    }

    public function matchesLink(string $url): bool
    {
        return false;
    }

    public function resolveLink(string $url): LinkedVideo
    {
        throw new VideoProviderException(static::label() . ' doesn’t recognise that link.');
    }

    public function get(VideoRef $video): VideoInfo
    {
        return new VideoInfo('READY');
    }

    public function owns(VideoRef $video): bool
    {
        return false;
    }

    public function delete(VideoRef $video): void
    {
    }

    public function thumbnailUrl(VideoRef $video, ?string $file): ?string
    {
        return null;
    }

    public function setThumbnail(VideoRef $video, string $imageUrl): void
    {
        throw new VideoProviderException(static::label() . ' keeps its own thumbnail; set one there.');
    }

    public function mp4(VideoRef $video, int $maxHeight): Mp4Result
    {
        return Mp4Result::reason('not_supported');
    }

    public function captions(VideoRef $video): ?CaptionOps
    {
        return null;
    }
}
