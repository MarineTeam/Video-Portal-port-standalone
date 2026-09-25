<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Id;

/**
 * The Subscriptions plugin through a real server: following toggles, a
 * follow can be muted, and a video going live reaches the unmuted followers
 * of its series and of every category above it — nobody else.
 */
final class SubscriptionsTest extends ServerTestCase
{
    /** @var array<string, string> */
    private static array $ids = [];

    protected static function prefix(): string
    {
        return 'sb_';
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
        self::$ids['sermons'] = $new('categories', ['name' => 'Sermons', 'slug' => 'sermons', 'published' => 1, 'tags' => []]);
        self::$ids['advent'] = $new('categories', ['name' => 'Advent', 'slug' => 'advent', 'published' => 1, 'tags' => [], 'parent_id' => self::$ids['sermons']]);
        self::$ids['hope'] = $new('series', ['title' => 'Hope', 'slug' => 'hope', 'published' => 1, 'tags' => [], 'category_id' => self::$ids['advent']]);
        self::$ids['other'] = $new('series', ['title' => 'Other', 'slug' => 'other', 'published' => 1, 'tags' => []]);
        self::$ids['video'] = $new('videos', ['title' => 'Hope week one', 'slug' => 'hope-1', 'published' => 0, 'status' => 'READY', 'provider' => 'direct', 'external_id' => 'h1', 'scripture_refs' => [], 'provider_data' => ['url' => 'https://cdn.example.org/h1.mp4'], 'series_id' => self::$ids['hope']]);
        self::flushCache();
        foreach (['ruth', 'boaz', 'orpah', 'naomi'] as $who) {
            self::member("$who@test.example", $who);
        }
    }

    public function test_following_toggles_and_can_be_muted(): void
    {
        self::assertSame(['following' => true], self::api('POST', '/api/subscriptions', ['seriesId' => self::$ids['other']], 'naomi')['json']);
        self::assertSame(['following' => false], self::api('POST', '/api/subscriptions', ['seriesId' => self::$ids['other']], 'naomi')['json']);
        self::assertSame(404, self::api('PATCH', '/api/subscriptions', ['seriesId' => self::$ids['other'], 'muted' => true], 'naomi')['status'], 'not following it');
        self::assertSame(401, self::api('POST', '/api/subscriptions', ['seriesId' => self::$ids['other']], 'guest')['status']);
        self::assertSame(400, self::api('POST', '/api/subscriptions', ['videoId' => self::$ids['video']], 'naomi')['status'], 'videos are not followed');
    }

    public function test_a_new_video_reaches_unmuted_followers_of_its_series_and_the_categories_above(): void
    {
        self::assertSame(['following' => true], self::api('POST', '/api/subscriptions', ['categoryId' => self::$ids['sermons']], 'ruth')['json']);
        self::assertSame(['following' => true], self::api('POST', '/api/subscriptions', ['seriesId' => self::$ids['hope']], 'boaz')['json']);
        self::api('POST', '/api/subscriptions', ['seriesId' => self::$ids['hope']], 'orpah');
        self::assertSame(['muted' => true], self::api('PATCH', '/api/subscriptions', ['seriesId' => self::$ids['hope'], 'muted' => true], 'orpah')['json']);
        self::api('POST', '/api/subscriptions', ['seriesId' => self::$ids['other']], 'naomi');
        self::assertStringContainsString('Unmute', self::http('GET', '/subscriptions', null, 'orpah')['body']);

        self::assertSame(200, self::api('PATCH', '/api/admin/videos/' . self::$ids['video'], ['published' => true])['status']);
        $told = fn (string $who) => in_array('New in Hope', array_column((array) self::http('GET', '/api/inbox', null, $who)['json']['notifications'], 'title'), true);
        self::assertTrue($told('ruth'), 'follows a category above it');
        self::assertTrue($told('boaz'), 'follows the series');
        self::assertFalse($told('orpah'), 'muted');
        self::assertFalse($told('naomi'), 'follows something else');
    }
}
