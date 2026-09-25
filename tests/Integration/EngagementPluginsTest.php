<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Id;

/**
 * Ratings, likes, view counts and social share through a real server: the
 * APIs' shapes and validation, members-only writes over guest-readable
 * summaries, nothing for content the reader can't open, and each panel
 * leaving the page when its plugin is off for the category.
 */
final class EngagementPluginsTest extends ServerTestCase
{
    /** @var array<string, string> */
    private static array $ids = [];

    protected static function prefix(): string
    {
        return 'en_';
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::install();
        $db = self::connect(self::prefix());
        $new = function (string $table, array $row) use ($db): string {
            $id = Id::new();
            $db->insert($table, ['id' => $id] + $row);
            return $id;
        };
        self::$ids['quiet'] = $new('categories', ['name' => 'Quiet', 'slug' => 'quiet', 'published' => 1, 'tags' => []]);
        self::$ids['romans'] = $new('series', ['title' => 'Romans', 'slug' => 'romans', 'published' => 1, 'tags' => [], 'view_count' => 1234]);
        self::$ids['hush'] = $new('series', ['title' => 'Hush', 'slug' => 'hush', 'published' => 1, 'tags' => [], 'category_id' => self::$ids['quiet']]);
        self::$ids['draft'] = $new('series', ['title' => 'Draft', 'slug' => 'draft', 'published' => 0, 'tags' => []]);
        self::$ids['video'] = $new('videos', ['title' => 'Romans 1', 'slug' => 'romans-1', 'published' => 1, 'status' => 'READY', 'provider' => 'direct', 'external_id' => 'x1', 'scripture_refs' => [], 'provider_data' => ['url' => 'https://cdn.example.org/r1.mp4'], 'series_id' => self::$ids['romans']]);
        // Every one of the four switched off under Quiet.
        foreach (['ratings', 'likes-dislikes', 'view-counts', 'social-share'] as $slug) {
            $new('plugin_category_overrides', ['plugin_id' => (string) $db->value('SELECT id FROM {{plugins}} WHERE slug = ?', [$slug]), 'category_id' => self::$ids['quiet'], 'enabled' => 0]);
        }
        self::flushCache();
        self::member('ruth@test.example', 'ruth');
        self::member('boaz@test.example', 'boaz');
    }

    public function test_ratings_average_what_members_give(): void
    {
        $series = ['seriesId' => self::$ids['romans']];
        self::assertEquals(['average' => null, 'count' => 0, 'mine' => null], self::http('GET', '/api/ratings?seriesId=' . self::$ids['romans'], null, 'guest')['json']);
        self::assertEquals(['average' => 5, 'count' => 1, 'mine' => 5], self::api('POST', '/api/ratings', $series + ['value' => 5], 'ruth')['json']);
        self::assertEquals(['average' => 3.5, 'count' => 2, 'mine' => 2], self::api('POST', '/api/ratings', $series + ['value' => 2], 'boaz')['json']);
        // Changing one's mind replaces the rating rather than adding another.
        self::assertEquals(['average' => 4.5, 'count' => 2, 'mine' => 4], self::api('POST', '/api/ratings', $series + ['value' => 4], 'boaz')['json']);
        self::assertEquals(['average' => 5, 'count' => 1, 'mine' => null], self::api('POST', '/api/ratings', $series + ['value' => null], 'boaz')['json']);
        foreach ([0, 6, 3.5, '4'] as $bad) {
            self::assertSame(400, self::api('POST', '/api/ratings', $series + ['value' => $bad], 'ruth')['status'], (string) json_encode($bad));
        }
        self::assertSame(401, self::api('POST', '/api/ratings', $series + ['value' => 3], 'guest')['status']);
        self::assertSame(404, self::http('GET', '/api/ratings?seriesId=' . self::$ids['draft'], null, 'guest')['status']);
        self::assertSame(403, self::api('POST', '/api/ratings', ['seriesId' => self::$ids['hush'], 'value' => 3], 'ruth')['status']);
    }

    public function test_one_reaction_per_member_either_way(): void
    {
        $video = ['videoId' => self::$ids['video']];
        self::assertSame(['likes' => 1, 'dislikes' => 0, 'mine' => 'LIKE'], self::api('POST', '/api/reactions', $video + ['type' => 'LIKE'], 'ruth')['json']);
        self::assertSame(['likes' => 1, 'dislikes' => 1, 'mine' => 'DISLIKE'], self::api('POST', '/api/reactions', $video + ['type' => 'DISLIKE'], 'boaz')['json']);
        self::assertSame(['likes' => 2, 'dislikes' => 0, 'mine' => 'LIKE'], self::api('POST', '/api/reactions', $video + ['type' => 'LIKE'], 'boaz')['json']);
        self::assertSame(['likes' => 1, 'dislikes' => 0, 'mine' => null], self::api('POST', '/api/reactions', $video + ['type' => null], 'boaz')['json']);
        self::assertSame(['likes' => 1, 'dislikes' => 0, 'mine' => null], self::http('GET', '/api/reactions?videoId=' . self::$ids['video'], null, 'guest')['json']);
        self::assertSame(400, self::api('POST', '/api/reactions', $video + ['type' => 'LOVE'], 'ruth')['status']);
        self::assertSame(401, self::api('POST', '/api/reactions', $video + ['type' => 'LIKE'], 'guest')['status']);
    }

    public function test_the_panels_show_on_the_page_and_leave_where_switched_off(): void
    {
        $page = self::http('GET', '/series/romans', null, 'ruth')['body'];
        self::assertStringContainsString('1,234 views', $page);
        self::assertStringContainsString('data-rating=', $page);
        self::assertStringContainsString('data-reactions=', $page);
        self::assertStringContainsString('https://twitter.com/intent/tweet?url=', $page);
        self::assertStringNotContainsString('data-share-at', $page, 'Share at is for videos');
        self::assertStringContainsString('data-share-at', self::http('GET', '/videos/romans-1', null, 'ruth')['body']);

        $quiet = self::http('GET', '/series/hush', null, 'ruth')['body'];
        foreach (['data-view-count', 'data-rating=', 'data-reactions=', 'twitter.com'] as $gone) {
            self::assertStringNotContainsString($gone, $quiet, $gone);
        }
    }

    public function test_ratings_and_reactions_are_in_the_data_export(): void
    {
        self::api('POST', '/api/ratings', ['videoId' => self::$ids['video'], 'value' => 3], 'ruth');
        $doc = self::http('GET', '/api/profile/export', null, 'ruth')['json'];
        self::assertContains(self::$ids['video'], array_column($doc['ratings'], 'videoId'));
        self::assertContains('LIKE', array_column($doc['reactions'], 'type'));
    }
}
