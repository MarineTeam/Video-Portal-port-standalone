<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Id;

/**
 * The Favorites and Watch later plugins through a real server: the toggle
 * answers which way it went, only members may use it, something the member
 * can't open is "not found", a category that switches the plugin off
 * refuses it and loses the button, and the pages and the data export list
 * what was saved.
 */
final class MemberListsTest extends ServerTestCase
{
    /** @var array<string, string> */
    private static array $ids = [];

    protected static function prefix(): string
    {
        return 'ml_';
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
        self::$ids['open'] = $new('categories', ['name' => 'Sermons', 'slug' => 'sermons', 'published' => 1, 'tags' => []]);
        self::$ids['kids'] = $new('categories', ['name' => 'Kids', 'slug' => 'kids', 'published' => 1, 'tags' => []]);
        self::$ids['romans'] = $new('series', ['title' => 'Romans', 'slug' => 'romans', 'published' => 1, 'tags' => [], 'category_id' => self::$ids['open']]);
        self::$ids['draft'] = $new('series', ['title' => 'Draft series', 'slug' => 'draft', 'published' => 0, 'tags' => []]);
        self::$ids['songs'] = $new('series', ['title' => 'Kids songs', 'slug' => 'kids-songs', 'published' => 1, 'tags' => [], 'category_id' => self::$ids['kids']]);
        self::$ids['video'] = $new('videos', ['title' => 'Romans 1', 'slug' => 'romans-1', 'published' => 1, 'status' => 'READY', 'provider' => 'direct', 'external_id' => 'x1', 'scripture_refs' => [], 'provider_data' => ['url' => 'https://cdn.example.org/r1.mp4'], 'series_id' => self::$ids['romans']]);
        // Favorites off under Kids only.
        $plugin = (string) $db->value("SELECT id FROM {{plugins}} WHERE slug = 'favorites'");
        $new('plugin_category_overrides', ['plugin_id' => $plugin, 'category_id' => self::$ids['kids'], 'enabled' => 0]);
        self::flushCache();
        self::member('ruth@test.example', 'ruth');
    }

    public function test_a_favorite_toggles_and_says_which_way(): void
    {
        $body = ['seriesId' => self::$ids['romans']];
        self::assertSame(['favorited' => true], self::api('POST', '/api/favorites', $body, 'ruth')['json']);
        self::assertSame(['favorited' => false], self::api('POST', '/api/favorites', $body, 'ruth')['json']);
        self::assertSame(['favorited' => true], self::api('POST', '/api/favorites', $body, 'ruth')['json']);
        self::assertSame(['favorited' => true], self::api('POST', '/api/favorites', ['videoId' => self::$ids['video']], 'ruth')['json']);

        $page = self::http('GET', '/favorites', null, 'ruth');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Romans 1', $page['body']);
        self::assertStringContainsString('>Romans<', $page['body']);
        // The button shows its state on the page.
        self::assertMatchesRegularExpression('/data-api="\/api\/favorites"[^>]*\n?[^>]*aria-pressed="true"/', self::http('GET', '/series/romans', null, 'ruth')['body']);
    }

    public function test_only_members_and_only_what_they_can_open(): void
    {
        self::assertSame(401, self::api('POST', '/api/favorites', ['seriesId' => self::$ids['romans']], 'guest')['status']);
        self::assertSame(303, self::http('GET', '/favorites', null, 'guest')['status']);
        self::assertSame(404, self::api('POST', '/api/favorites', ['seriesId' => self::$ids['draft']], 'ruth')['status']);
        self::assertSame(404, self::api('POST', '/api/favorites', ['seriesId' => Id::new()], 'ruth')['status']);
        self::assertSame(400, self::api('POST', '/api/favorites', [], 'ruth')['status']);
        self::assertStringNotContainsString('/api/favorites', self::http('GET', '/series/romans', null, 'guest')['body']);
    }

    public function test_a_category_that_switches_it_off_refuses_it_and_loses_the_button(): void
    {
        $r = self::api('POST', '/api/favorites', ['seriesId' => self::$ids['songs']], 'ruth');
        self::assertSame(403, $r['status']);
        self::assertSame('plugin_disabled', $r['json']['code']);
        $page = self::http('GET', '/series/kids-songs', null, 'ruth')['body'];
        self::assertStringNotContainsString('/api/favorites', $page);
        // Watch later isn't switched off there.
        self::assertStringContainsString('/api/watch-later', $page);
    }

    public function test_watch_later_keeps_categories_series_and_videos(): void
    {
        self::assertSame(['saved' => true], self::api('POST', '/api/watch-later', ['categoryId' => self::$ids['open']], 'ruth')['json']);
        self::assertSame(['saved' => true], self::api('POST', '/api/watch-later', ['videoId' => self::$ids['video']], 'ruth')['json']);
        $page = self::http('GET', '/watch-later', null, 'ruth')['body'];
        self::assertStringContainsString('Sermons', $page);
        self::assertStringContainsString('Romans 1', $page);
        self::assertSame(['saved' => false], self::api('POST', '/api/watch-later', ['videoId' => self::$ids['video']], 'ruth')['json']);
        self::assertStringNotContainsString('Romans 1', self::http('GET', '/watch-later', null, 'ruth')['body']);
    }

    public function test_the_lists_are_in_the_members_data_export_and_nobody_elses(): void
    {
        self::api('POST', '/api/favorites', ['videoId' => self::$ids['video']], 'ruth');
        $doc = self::http('GET', '/api/profile/export', null, 'ruth')['json'];
        // The core's export already holds every member table, plugin or not.
        self::assertContains(self::$ids['romans'], array_column($doc['favoriteSeries'], 'seriesId'));
        self::assertArrayHasKey('watchLaterCategories', $doc);
        $mine = self::http('GET', '/api/profile/export', null, 'admin')['json'];
        self::assertSame([], $mine['favoriteSeries']);
    }
}
