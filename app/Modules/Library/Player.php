<?php

declare(strict_types=1);

namespace App\Modules\Library;

use App\Core\App;
use App\Services\Video\PlayerOptions;
use App\Services\Video\VideoProvider;
use App\Services\Video\VideoProviderException;
use App\Services\Video\VideoRef;

/**
 * What the video page's player gets: the provider's own spec, plus what the
 * site keeps for a native player (captions sidecars, the poster an admin
 * chose). The caller has already decided the viewer may watch.
 */
final class Player
{
    /**
     * @param array<string, mixed> $video
     * @return array<string, mixed>|null null when the provider is gone or can't say
     */
    public static function spec(App $app, array $video, int $startSeconds = 0, bool $autoplay = false): ?array
    {
        $provider = $app->services()->get('video', (string) $video['provider']);
        if (!$provider instanceof VideoProvider) {
            return null;
        }
        $ref = VideoRef::fromRow($video);
        try {
            $spec = $provider->player($ref, new PlayerOptions(max(0, $startSeconds), $autoplay, (bool) $video['member_only']));
        } catch (VideoProviderException $e) {
            \App\Core\Log::warning('Player for video ' . $video['id'] . ': ' . $e->getMessage());
            return null;
        }
        if ($spec->kind === 'native') {
            $tracks = [];
            foreach ((array) ($ref->data['tracks'] ?? []) as $t) {
                if (is_array($t) && isset($t['src'], $t['srclang'], $t['label'])) {
                    $tracks[] = ['src' => (string) $t['src'], 'srclang' => (string) $t['srclang'], 'label' => (string) $t['label']];
                }
            }
            $spec = $spec->withTracks($tracks);
            if ($spec->poster === null) {
                $poster = (string) ($video['external_thumbnail_url'] ?? '');
                $spec = $spec->withPoster($poster !== '' ? $poster : null);
            }
        }
        return ['videoId' => (string) $video['id'], 'start' => max(0, $startSeconds), 'duration' => $video['duration_seconds'] !== null ? (int) $video['duration_seconds'] : null] + $spec->toArray();
    }
}
