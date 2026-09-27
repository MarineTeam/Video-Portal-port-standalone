<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Id;

/**
 * Cast to a television, beside the Download button and under the same gate:
 * a receiver plays the very same MP4, so a video with no file of ours can't
 * be cast either, and Bunny's player — which carries a cast button inside its
 * own frame — is left to do it rather than shown a second one.
 */
final class CastTest extends ServerTestCase
{
    /** @var array<string, string> */
    private static array $ids = [];

    protected static function prefix(): string
    {
        return 'cast_';
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (self::$base === '') {
            return;
        }
        self::install();
        $db = self::connect(self::prefix());
        $video = static function (string $slug, string $provider, array $data) use ($db): string {
            $id = Id::new();
            $db->insert('videos', [
                'id' => $id, 'title' => ucfirst($slug), 'slug' => $slug, 'provider' => $provider,
                'external_id' => $slug, 'status' => 'READY', 'published' => true,
                'scripture_refs' => [], 'provider_data' => $data,
            ]);
            return $id;
        };
        self::$ids['direct'] = $video('a-file-of-ours', 'direct', ['url' => 'https://cdn.example.org/one.mp4']);
        self::$ids['bunny'] = $video('on-bunny', 'bunny', []);
        self::$ids['youtube'] = $video('on-youtube', 'youtube', []);
        $db->insert('services', ['id' => Id::new(), 'slot' => 'video', 'provider' => 'bunny', 'active' => 0, 'config' => ['libraryId' => '123', 'apiKey' => 'k', 'pullZone' => 'vz-abc']]);
        self::flushCache();
        self::member('naomi@test.example', 'naomi');
    }

    public function test_1_a_video_with_a_file_of_ours_can_be_sent_to_a_television(): void
    {
        $page = self::http('GET', '/videos/a-file-of-ours', null, 'naomi')['body'];

        self::assertStringContainsString('data-download-video', $page);
        self::assertStringContainsString('data-cast-video="' . self::$ids['direct'] . '"', $page);
        self::assertStringContainsString('js/cast.js', $page);
        // Hidden until the browser finds a receiver; a dead button is worse than none.
        self::assertMatchesRegularExpression('/data-cast-video="[^"]+"\s+hidden/', $page);
    }

    public function test_2_bunnys_own_player_keeps_its_cast_button_and_the_page_adds_none(): void
    {
        $page = self::http('GET', '/videos/on-bunny', null, 'naomi')['body'];

        self::assertStringContainsString('chromecast=true', $page, 'Bunny is asked for its own cast button');
        self::assertStringNotContainsString('data-cast-video', $page);
    }

    public function test_3_a_video_that_lives_on_youtube_offers_neither_download_nor_cast(): void
    {
        $page = self::http('GET', '/videos/on-youtube', null, 'naomi')['body'];

        self::assertStringNotContainsString('data-cast-video', $page);
        self::assertStringNotContainsString('data-download-video', $page);
        // And the endpoint both share says why, in as many words.
        $refusal = self::http('GET', '/api/downloads/' . self::$ids['youtube'], null, 'naomi');
        self::assertSame(403, $refusal['status']);
        self::assertSame('not_supported', $refusal['json']['code'] ?? null);
    }

    public function test_4_a_guest_is_offered_nothing_at_all(): void
    {
        $page = self::http('GET', '/videos/a-file-of-ours', null, 'guest')['body'];

        self::assertStringNotContainsString('data-cast-video', $page);
        self::assertSame(401, self::http('GET', '/api/downloads/' . self::$ids['direct'], null, 'guest')['status']);
    }
}
