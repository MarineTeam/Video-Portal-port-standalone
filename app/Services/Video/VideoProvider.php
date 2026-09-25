<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Services\ServiceProvider;

/**
 * Where videos live. The Video slot names where new uploads go; every
 * provider with saved settings keeps playing the videos it holds, each row
 * recording its own (videos.provider), and a switch re-hosts nothing.
 */
interface VideoProvider extends ServiceProvider
{
    public function capabilities(): VideoCapabilities;

    /** A placeholder at the provider, plus what the browser must do next. */
    public function createUpload(string $title, UploadHints $hints): UploadTicket;

    /** Called once the browser says it finished, to settle the row. */
    public function completeUpload(VideoRef $video): VideoInfo;

    public function matchesLink(string $url): bool;

    public function resolveLink(string $url): LinkedVideo;

    public function get(VideoRef $video): VideoInfo;

    /** Whether the provider holds a copy of ours to delete (an upload, not a link). */
    public function owns(VideoRef $video): bool;

    public function delete(VideoRef $video): void;

    public function player(VideoRef $video, PlayerOptions $options): PlayerSpec;

    public function thumbnailUrl(VideoRef $video, ?string $file): ?string;

    public function setThumbnail(VideoRef $video, string $imageUrl): void;

    public function mp4(VideoRef $video, int $maxHeight): Mp4Result;

    public function captions(VideoRef $video): ?CaptionOps;
}
