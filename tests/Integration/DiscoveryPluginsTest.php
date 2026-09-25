<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Id;

/**
 * Related content, Up next, Watch history and Profiles through a real
 * server: what each adds to a page, that a guest never meets members-only
 * content through them, and the directory's consent rules end to end.
 */
final class DiscoveryPluginsTest extends ServerTestCase
{
    /** @var array<string, string> */
    private static array $ids = [];

    protected static function prefix(): string
    {
        return 'dp_';
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
        $video = fn (string $slug, array $extra = []) => self::$ids[$slug] = $new('videos', $extra + ['title' => ucwords(str_replace('-', ' ', $slug)), 'slug' => $slug, 'published' => 1, 'status' => 'READY', 'provider' => 'direct', 'external_id' => $slug, 'scripture_refs' => [], 'provider_data' => ['url' => "https://cdn.example.org/$slug.mp4"]]);
        $cat = $new('categories', ['name' => 'Talks', 'slug' => 'talks', 'published' => 1, 'tags' => []]);
        $romans = $new('series', ['title' => 'Romans', 'slug' => 'romans', 'published' => 1, 'tags' => []]);
        $video('romans-one', ['series_id' => $romans, 'position' => 0]);
        $video('romans-two', ['series_id' => $romans, 'position' => 1]);
        $video('romans-three', ['series_id' => $romans, 'position' => 2]);
        $video('standalone-a', ['category_id' => $cat]);
        $video('standalone-b', ['category_id' => $cat]);
        $video('members-talk', ['category_id' => $cat, 'member_only' => 1]);
        $ruth = self::member('ruth@test.example', 'ruth');
        self::member('boaz@test.example', 'boaz');
        $db->update('users', ['display_name' => 'Ruth the Moabite', 'directory_listed' => 1, 'directory_show_email' => 1, 'phone' => '+44 7700 900123', 'directory_note' => 'Welcome team'], ['id' => $ruth]);
        $db->insert('watch_progresses', ['id' => Id::new(), 'user_id' => $ruth, 'video_id' => self::$ids['romans-two'], 'position_seconds' => 40]);
        self::flushCache();
    }

    public function test_a_video_in_a_series_shows_up_next_and_the_rest_of_the_series(): void
    {
        $page = self::http('GET', '/videos/romans-one', null, 'guest')['body'];
        self::assertStringContainsString('data-up-next="/videos/romans-two"', $page);
        self::assertStringContainsString('More from this series', $page);
        self::assertStringContainsString('Romans Three', $page);
        // The last one has nothing after it.
        self::assertStringNotContainsString('data-up-next=', self::http('GET', '/videos/romans-three', null, 'guest')['body']);
    }

    public function test_a_video_standing_alone_suggests_others_but_not_members_only_ones_to_a_guest(): void
    {
        $guest = self::http('GET', '/videos/standalone-a', null, 'guest')['body'];
        self::assertStringContainsString('You might also like', $guest);
        self::assertStringContainsString('Standalone B', $guest);
        self::assertStringNotContainsString('Members Talk', $guest);
        self::assertStringContainsString('Members Talk', self::http('GET', '/videos/standalone-a', null, 'ruth')['body']);
    }

    public function test_recently_played_lists_what_the_member_started(): void
    {
        $page = self::http('GET', '/recently-played', null, 'ruth');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Romans Two', $page['body']);
        self::assertStringNotContainsString('Romans Two', self::http('GET', '/recently-played', null, 'boaz')['body']);
        self::assertSame(303, self::http('GET', '/recently-played', null, 'guest')['status']);
    }

    public function test_the_directory_publishes_only_what_each_person_chose(): void
    {
        $r = self::http('GET', '/directory', null, 'boaz');
        self::assertSame(200, $r['status']);
        self::assertStringContainsString('Ruth the Moabite', $r['body']);
        self::assertStringContainsString('ruth@test.example', $r['body']);
        self::assertStringNotContainsString('7700', $r['body'], 'the phone was not ticked');
        self::assertStringNotContainsString('boaz@test.example</a>', $r['body'], 'Boaz never asked to be listed');
        self::assertStringContainsString('You aren’t in the directory.', $r['body']);
        self::assertStringNotContainsString('Ruth the Moabite', self::http('GET', '/directory?q=ruth%40test', null, 'boaz')['body'], 'no finding people by address');
        self::assertStringContainsString('Ruth the Moabite', self::http('GET', '/directory?q=welcome', null, 'boaz')['body']);
        self::assertSame(303, self::http('GET', '/directory', null, 'guest')['status']);
        // Access withdrawn: gone at once.
        self::connect(self::prefix())->run('UPDATE {{users}} SET authorized = 0 WHERE email = ?', ['ruth@test.example']);
        self::assertStringNotContainsString('Ruth the Moabite', self::http('GET', '/directory', null, 'boaz')['body']);
        self::connect(self::prefix())->run('UPDATE {{users}} SET authorized = 1 WHERE email = ?', ['ruth@test.example']);
    }

    public function test_the_directory_is_never_indexed(): void
    {
        $ch = curl_init(self::$base . '/directory');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_NOBODY => false, CURLOPT_COOKIEFILE => self::jar('boaz')]);
        $raw = (string) curl_exec($ch);
        curl_close($ch);
        self::assertMatchesRegularExpression('/^X-Robots-Tag: noindex/mi', $raw);
    }

    public function test_a_display_name_shows_only_while_profiles_is_on(): void
    {
        self::assertStringContainsString('Ruth the Moabite', self::http('GET', '/profile', null, 'ruth')['body']);
        $db = self::connect(self::prefix());
        $db->run("UPDATE {{plugins}} SET enabled = 0 WHERE slug = 'profiles'");
        self::flushCache();
        try {
            $page = self::http('GET', '/profile', null, 'ruth')['body'];
            self::assertStringNotContainsString('Ruth the Moabite', $page);
            self::assertSame(404, self::http('GET', '/directory', null, 'ruth')['status']);
        } finally {
            $db->run("UPDATE {{plugins}} SET enabled = 1 WHERE slug = 'profiles'");
            self::flushCache();
        }
    }
}
