<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Id;

/**
 * The Playlists plugin through a real server: a playlist is its owner's
 * until made shareable, a shared one shows each reader only the videos
 * they may watch, and adding, reordering and removing are the owner's.
 */
final class PlaylistsTest extends ServerTestCase
{
    /** @var array<string, string> */
    private static array $ids = [];

    protected static function prefix(): string
    {
        return 'pl_';
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::install();
        $db = self::connect(self::prefix());
        foreach (['one' => [], 'two' => [], 'members' => ['member_only' => 1], 'draft' => ['published' => 0]] as $slug => $extra) {
            $id = Id::new();
            $db->insert('videos', $extra + ['id' => $id, 'title' => "Video $slug", 'slug' => $slug, 'published' => 1, 'status' => 'READY', 'provider' => 'direct', 'external_id' => $slug, 'scripture_refs' => [], 'provider_data' => ['url' => "https://cdn.example.org/$slug.mp4"]]);
            self::$ids[$slug] = $id;
        }
        self::flushCache();
        self::member('ruth@test.example', 'ruth');
        self::member('boaz@test.example', 'boaz');
    }

    public function test_a_playlist_from_start_to_sharing(): void
    {
        $created = self::api('POST', '/api/playlists', ['title' => 'Advent', 'videoId' => self::$ids['one']], 'ruth');
        self::assertSame(201, $created['status']);
        $id = $created['json']['id'];
        self::assertArrayNotHasKey('userId', $created['json']);
        foreach (['two', 'members'] as $slug) {
            self::assertSame(201, self::api('POST', "/api/playlists/$id/items", ['videoId' => self::$ids[$slug]], 'ruth')['status']);
        }
        self::assertSame(404, self::api('POST', "/api/playlists/$id/items", ['videoId' => self::$ids['draft']], 'ruth')['status'], 'a draft can\'t be added');
        $order = fn (string $who) => array_column((array) self::http('GET', "/api/playlists/$id", null, $who)['json']['items'], 'title');
        self::assertSame(['Video one', 'Video two', 'Video members'], $order('ruth'));
        self::api('PATCH', "/api/playlists/$id/items", ['videoId' => self::$ids['two'], 'move' => 'up'], 'ruth');
        self::assertSame(['Video two', 'Video one', 'Video members'], $order('ruth'));
        self::assertSame(400, self::api('PATCH', "/api/playlists/$id/items", ['order' => [self::$ids['one']]], 'ruth')['status'], 'a partial order');

        // Private: nobody else sees it at all.
        self::assertSame(404, self::http('GET', "/api/playlists/$id", null, 'boaz')['status']);
        self::assertSame(404, self::http('GET', "/playlists/$id", null, 'guest')['status']);
        self::assertSame(404, self::api('POST', "/api/playlists/$id/items", ['videoId' => self::$ids['one']], 'boaz')['status']);

        // Shareable: anyone may read it, each seeing only what they may watch.
        self::assertTrue(self::api('PATCH', "/api/playlists/$id", ['public' => true], 'ruth')['json']['public']);
        self::assertSame(['Video two', 'Video one'], $order('guest'));
        self::assertSame(['Video two', 'Video one', 'Video members'], $order('boaz'));
        $page = self::http('GET', "/playlists/$id", null, 'guest');
        self::assertSame(200, $page['status']);
        self::assertStringNotContainsString('data-method="DELETE"', $page['body'], 'read-only for others');
        self::assertSame(404, self::api('PATCH', "/api/playlists/$id", ['title' => 'Mine now'], 'boaz')['status']);

        self::assertSame(200, self::api('DELETE', "/api/playlists/$id/items?videoId=" . self::$ids['one'], null, 'ruth')['status']);
        self::assertSame(['Video two', 'Video members'], $order('ruth'));
        $menu = self::http('GET', '/api/playlists/for-video?videoId=' . self::$ids['two'], null, 'ruth')['json'];
        self::assertSame([['id' => $id, 'title' => 'Advent', 'contains' => true]], $menu);
        self::assertSame(200, self::api('DELETE', "/api/playlists/$id", null, 'ruth')['status']);
        self::assertSame(404, self::http('GET', "/playlists/$id", null, 'ruth')['status']);
    }

    public function test_members_only(): void
    {
        self::assertSame(401, self::api('POST', '/api/playlists', ['title' => 'x'], 'guest')['status']);
        self::assertSame(400, self::api('POST', '/api/playlists', ['title' => ''], 'ruth')['status']);
        self::assertSame(303, self::http('GET', '/playlists', null, 'guest')['status']);
    }
}
