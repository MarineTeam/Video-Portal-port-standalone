<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Id;

/**
 * Picking a video up where it was left, in both kinds of player: this site's
 * own <video> and somebody else's iframe. The position the browser reports is
 * the position the next page hands back, the video's own player is told about
 * it in whatever way that player takes, and a video already finished starts
 * again from the top rather than at its last second.
 */
final class ResumeTest extends ServerTestCase
{
    /** @var array<string, string> */
    private static array $ids = [];

    protected static function prefix(): string
    {
        return 'resume_';
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
                'external_id' => $provider === 'youtube' ? 'dQw4w9WgXcQ' : $slug, 'status' => 'READY',
                'published' => true, 'duration_seconds' => 1800, 'scripture_refs' => [], 'provider_data' => $data,
            ]);
            return $id;
        };
        self::$ids['native'] = $video('our-own-player', 'direct', ['url' => 'https://cdn.example.org/one.mp4', 'type' => 'video/mp4']);
        self::$ids['iframe'] = $video('somebody-elses-player', 'youtube', []);
        self::flushCache();
        self::member('lydia@test.example', 'lydia');
    }

    /** The data-player JSON the page hands the browser. */
    private static function spec(string $slug, string $who = 'lydia'): array
    {
        $page = self::http('GET', "/videos/$slug", null, $who)['body'];
        self::assertMatchesRegularExpression('/data-player="[^"]+"/', $page, "no player on /videos/$slug");
        preg_match('/data-player="([^"]+)"/', $page, $m);
        return (array) json_decode(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), true);
    }

    public function test_1_a_fresh_video_starts_at_the_beginning_in_either_player(): void
    {
        self::assertSame(0, self::spec('our-own-player')['start']);
        self::assertSame(0, self::spec('somebody-elses-player')['start']);
    }

    public function test_2_the_position_the_browser_reports_is_the_one_the_page_hands_back(): void
    {
        foreach (['native' => 'our-own-player', 'iframe' => 'somebody-elses-player'] as $key => $slug) {
            $sent = self::api('POST', '/api/watch-progress', ['videoId' => self::$ids[$key], 'positionSeconds' => 425], 'lydia');
            self::assertSame(200, $sent['status'], (string) $sent['body']);
            self::assertSame(425, self::spec($slug)['start'], $slug);
        }
        // And each player is told in the way it understands: our own element
        // seeks itself from the spec, YouTube's embed is asked in its URL.
        self::assertSame('native', self::spec('our-own-player')['kind']);
        $iframe = self::spec('somebody-elses-player');
        self::assertSame('iframe', $iframe['kind']);
        self::assertStringContainsString('start=425', (string) $iframe['src']);
        // Both report position for real rather than guessing from the clock.
        self::assertTrue(self::spec('our-own-player')['progressEvents']);
        self::assertTrue($iframe['progressEvents']);
    }

    public function test_3_a_position_in_the_first_few_seconds_is_not_worth_resuming(): void
    {
        self::api('POST', '/api/watch-progress', ['videoId' => self::$ids['native'], 'positionSeconds' => 4], 'lydia');
        self::assertSame(0, self::spec('our-own-player')['start']);
    }

    public function test_4_a_video_already_finished_starts_again_from_the_top(): void
    {
        self::api('POST', '/api/watch-progress', ['videoId' => self::$ids['native'], 'positionSeconds' => 1790], 'lydia');
        self::assertSame(1790, self::spec('our-own-player')['start']);
        self::api('POST', '/api/watch-progress/mark-watched', ['videoId' => self::$ids['native'], 'completed' => true], 'lydia');
        self::assertSame(0, self::spec('our-own-player')['start'], 'a watched video does not resume at its last second');
    }

    public function test_5_a_shared_timestamp_beats_the_saved_position(): void
    {
        self::api('POST', '/api/watch-progress', ['videoId' => self::$ids['iframe'], 'positionSeconds' => 425], 'lydia');
        $page = self::http('GET', '/videos/somebody-elses-player?t=2m10s', null, 'lydia')['body'];
        preg_match('/data-player="([^"]+)"/', $page, $m);
        self::assertSame(130, ((array) json_decode(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), true))['start']);
    }

    public function test_6_the_heartbeat_is_only_switched_on_for_someone_it_can_be_saved_for(): void
    {
        self::assertStringContainsString('data-progress', self::http('GET', '/videos/our-own-player', null, 'lydia')['body']);
        self::assertStringNotContainsString('data-progress', self::http('GET', '/videos/our-own-player', null, 'guest')['body']);
        self::assertSame(401, self::api('POST', '/api/watch-progress', ['videoId' => self::$ids['native'], 'positionSeconds' => 5], 'guest')['status']);
    }
}
