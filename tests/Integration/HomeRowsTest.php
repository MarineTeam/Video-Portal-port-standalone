<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\App;
use App\Core\Cache;
use App\Core\Db;
use App\Core\Id;
use App\Core\Migrator;
use App\Core\Paths;
use App\Modules\Library\Browse;
use App\Modules\Library\ContentAccess;
use App\Modules\Library\HomeRows;
use App\Modules\Library\Viewer;
use App\Modules\Plugins\PluginStates;

/**
 * The homepage rows against a real database: the built-in fallback, the
 * configured order, curated category and tag rows, trending by this week's
 * views, "Because you watched", and that a row's plugin being off or a
 * guest's view keep members-only series out.
 */
final class HomeRowsTest extends DatabaseTestCase
{
    private const PREFIX = 'hr_';
    private static ?Db $db = null;
    private static ?App $app = null;
    /** @var array<string, string> */
    private static array $ids = [];

    public static function setUpBeforeClass(): void
    {
        $name = getenv('MT_TEST_DB_NAME');
        if (!is_string($name) || $name === '') {
            return;
        }
        $db = self::connect(self::PREFIX);
        self::dropPrefix($db, self::PREFIX);
        (new Migrator($db, dirname(__DIR__, 2) . '/app/Migrations'))->runAll();
        self::$db = $db;
        $root = dirname(__DIR__, 2);
        self::$app = new App(new Paths($root, sys_get_temp_dir(), "$root/plugins", "$root/themes"));
        self::$app->config = ['database' => self::dbConfig(self::PREFIX)];

        $cat = Id::new();
        $db->insert('categories', ['id' => $cat, 'name' => 'Sermons', 'slug' => 'sermons', 'published' => true, 'tags' => []]);
        $sub = Id::new();
        $db->insert('categories', ['id' => $sub, 'name' => 'Advent', 'slug' => 'advent', 'parent_id' => $cat, 'published' => true, 'tags' => []]);
        $series = function (string $key, array $extra = [], array $tags = []) use ($db): string {
            $id = Id::new();
            $db->insert('series', $extra + ['id' => $id, 'title' => $key, 'slug' => strtolower($key), 'published' => true, 'tags' => $tags]);
            foreach ($tags as $tag) {
                $db->run('INSERT INTO {{series_tags}} (series_id, tag) VALUES (?, ?)', [$id, $tag]);
            }
            return self::$ids[$key] = $id;
        };
        $series('Romans', ['category_id' => $cat], ['grace', 'paul']);
        $series('Galatians', ['category_id' => $cat], ['grace', 'paul']);
        $series('Hope', ['category_id' => $sub], ['advent']);
        $series('Psalms', [], ['grace']);
        $series('Budget', ['member_only' => true, 'category_id' => $cat], ['grace']);
        $video = Id::new();
        $db->insert('videos', ['id' => $video, 'title' => 'Romans 1', 'slug' => 'romans-1', 'published' => true, 'scripture_refs' => [], 'series_id' => self::$ids['Romans']]);
        self::$ids['video'] = $video;
        $user = Id::new();
        $db->insert('users', ['id' => $user, 'email' => 'ruth@example.org', 'authorized' => 1]);
        self::$ids['user'] = $user;
        $db->insert('watch_progresses', ['id' => Id::new(), 'user_id' => $user, 'video_id' => $video, 'position_seconds' => 30]);
        // Psalms is viewed three times this week, Romans once (through its video), Galatians only long ago.
        foreach ([['series_id' => self::$ids['Psalms']], ['series_id' => self::$ids['Psalms']], ['series_id' => self::$ids['Psalms']], ['video_id' => $video]] as $e) {
            $db->insert('view_events', $e + ['id' => Id::new()]);
        }
        $db->insert('view_events', ['id' => Id::new(), 'series_id' => self::$ids['Galatians'], 'created_at' => gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::dropPrefix(self::$db, self::PREFIX);
        }
    }

    private function db(): Db
    {
        if (self::$db === null || self::$app === null) {
            self::markTestSkipped('MT_TEST_DB_NAME is not set.');
        }
        return self::$db;
    }

    /** @return array{continue: mixed, rows: list<array<string, mixed>>} */
    private function sections(bool $member = false): array
    {
        $this->db();
        Cache::forgetMemo();
        PluginStates::forget();
        $viewer = $member ? new Viewer((array) self::$db->one('SELECT * FROM {{users}} WHERE id = ?', [self::$ids['user']])) : Viewer::guest();
        $access = new ContentAccess(self::$app, $viewer);
        return HomeRows::sections(self::$app, new Browse(self::$app, $access));
    }

    /** @return array<string, list<string>> row type => series titles */
    private static function shape(array $sections): array
    {
        $out = [];
        foreach ($sections['rows'] as $row) {
            $out[$row['type'] . ($row['type'] === 'CATEGORY' || $row['type'] === 'TAG' ? ':' . $row['href'] : '')] = array_map(fn ($s) => $s['title'], $row['series']);
        }
        return $out;
    }

    public function test_with_nothing_configured_the_built_in_rows_show_in_their_order(): void
    {
        $this->db()->run('DELETE FROM {{home_rows}}');
        $guest = $this->sections();
        self::assertNull($guest['continue']);
        self::assertSame(['TRENDING', 'RECENTLY_ADDED'], array_keys(self::shape($guest)));
        self::assertSame(['Psalms', 'Romans'], self::shape($guest)['TRENDING'], 'most viewed this week first; old views and members-only left out');
        self::assertNotContains('Budget', self::shape($guest)['RECENTLY_ADDED']);

        $member = $this->sections(member: true);
        self::assertSame(['Romans 1'], array_map(fn ($v) => $v['title'], $member['continue']['videos']));
        // Because you watched Romans: Galatians shares both tags, then the rest.
        self::assertSame('Galatians', self::shape($member)['RECOMMENDATIONS'][0]);
        self::assertNotContains('Romans', self::shape($member)['RECOMMENDATIONS']);
    }

    public function test_configured_order_titles_and_curated_rows(): void
    {
        $db = $this->db();
        $db->run('DELETE FROM {{home_rows}}');
        HomeRows::ensureSeeded($db);
        HomeRows::ensureSeeded($db);
        self::assertSame(4, (int) $db->value('SELECT COUNT(*) FROM {{home_rows}}'), 'seeding twice adds nothing');
        $db->run("UPDATE {{home_rows}} SET enabled = 0 WHERE type = 'TRENDING'");
        $db->run("UPDATE {{home_rows}} SET title = 'Fresh', position = -1 WHERE type = 'RECENTLY_ADDED'");
        $category = (string) $db->value("SELECT id FROM {{categories}} WHERE slug = 'sermons'");
        $db->insert('home_rows', ['id' => Id::new(), 'type' => 'CATEGORY', 'category_id' => $category, 'position' => 10]);
        $db->insert('home_rows', ['id' => Id::new(), 'type' => 'TAG', 'tag' => 'advent', 'position' => 11]);
        $db->insert('home_rows', ['id' => Id::new(), 'type' => 'TAG', 'tag' => 'nothing-here', 'position' => 12]);

        $guest = $this->sections();
        self::assertSame(['RECENTLY_ADDED', 'CATEGORY:/categories/sermons', 'TAG:/tags/advent'], array_keys(self::shape($guest)));
        self::assertSame('Fresh', $guest['rows'][0]['title']);
        // The category row reaches into its subcategories, and not into members-only series for a guest.
        self::assertEqualsCanonicalizing(['Romans', 'Galatians', 'Hope'], self::shape($guest)['CATEGORY:/categories/sermons']);
        self::assertSame(['Hope'], self::shape($guest)['TAG:/tags/advent']);
    }

    public function test_a_rows_plugin_being_off_hides_it(): void
    {
        $db = $this->db();
        $db->run('DELETE FROM {{home_rows}}');
        $db->insert('plugins', ['id' => Id::new(), 'slug' => 'recommendations', 'name' => 'Recommendations', 'enabled' => 0]);
        $db->insert('plugins', ['id' => Id::new(), 'slug' => 'view-counts', 'name' => 'View counts', 'enabled' => 0]);
        Cache::forget('plugin-rows');
        try {
            self::assertSame(['RECENTLY_ADDED'], array_keys(self::shape($this->sections(member: true))));
        } finally {
            $db->run("DELETE FROM {{plugins}} WHERE slug IN ('recommendations', 'view-counts')");
            Cache::forget('plugin-rows');
        }
    }
}
