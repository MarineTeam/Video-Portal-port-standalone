<?php

declare(strict_types=1);

namespace App\Modules\Library\Admin;

use App\Core\ApiError;
use App\Core\App;
use App\Core\Log;
use App\Services\Video\VideoProvider;
use App\Services\Video\VideoRef;

/** A video's asset at its provider. */
final class VideoAssets
{
    /**
     * Removes the provider's copy when the provider holds one (an uploaded
     * video); a linked video has nothing of ours to remove. A failure stops
     * the purge so the row can be tried again.
     *
     * @param array<string, mixed> $video
     */
    public static function delete(App $app, array $video): void
    {
        $provider = $app->services()->get('video', (string) ($video['provider'] ?? ''));
        if ($provider === null) {
            if (in_array($video['provider'] ?? '', ['youtube', 'vimeo', 'direct', 'archive', 'dropbox', 'gdrive', 'onedrive'], true)) {
                return; // A link: the video lives somewhere else and stays there.
            }
            throw new ApiError('The service this video is stored with isn’t set up any more, so it can’t be removed there. Set it up again under Services, or leave the video in the trash.', 409, 'provider_missing');
        }
        if (!$provider instanceof VideoProvider || !$provider->owns(VideoRef::fromRow($video))) {
            return;
        }
        try {
            $provider->delete(VideoRef::fromRow($video));
        } catch (\Throwable $e) {
            Log::warning('Video delete failed: ' . $e->getMessage());
            throw new ApiError('The video couldn’t be removed from ' . $provider->label() . ': ' . $e->getMessage(), 502, 'provider_error');
        }
    }
}
