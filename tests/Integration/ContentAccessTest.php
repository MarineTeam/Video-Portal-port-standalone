<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\App;
use App\Core\Cache;
use App\Core\Db;
use App\Core\Id;
use App\Core\Migrator;
use App\Core\Paths;
use App\Modules\Library\ContentAccess;
use App\Modules\Library\Viewer;

/**
 * canViewSeries / canViewVideo / canViewFile and the listing filters, against
 * a real database: members-only inherited down the tree, restricted items,
 * share-link grants, hidden and scheduled content, and a manager's preview.
 */
final class ContentAccessTest extends DatabaseTestCase
{
    private const PREFIX = 'ca_';
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

        $id = fn (string $name) => self::$ids[$name] = Id::new();
        $past = '2026-01-01 00:00:00';
        $future = '2099-01-01 00:00:00';
        $db->insert('categories', ['id' => $id('open'), 'name' => 'Open', 'slug' => 'open', 'tags' => []]);
        $db->insert('categories', ['id' => $id('members'), 'name' => 'Members', 'slug' => 'members', 'member_only' => true, 'tags' => []]);
        $db->insert('categories', ['id' => $id('inner'), 'name' => 'Inner', 'slug' => 'inner', 'parent_id' => self::$ids['members'], 'tags' => []]);
        $series = function (string $name, array $extra = []) use ($db, $id): void {
            $db->insert('series', $extra + ['id' => $id($name), 'title' => ucfirst($name), 'slug' => $name, 'published' => true, 'tags' => []]);
        };
        $series('public', ['category_id' => self::$ids['open']]);
        $series('flagged', ['category_id' => self::$ids['open'], 'member_only' => true]);
        $series('deep', ['category_id' => self::$ids['inner']]);
        $series('restricted', ['category_id' => self::$ids['open']]);
        $series('hiddenone', ['category_id' => self::$ids['open'], 'hidden' => true]);
        $series('scheduled', ['category_id' => self::$ids['open'], 'publish_at' => $future]);
        $series('expired', ['category_id' => self::$ids['open'], 'unpublish_at' => $past]);
        $series('draft', ['category_id' => self::$ids['open'], 'published' => false]);
        $series('trashed', ['category_id' => self::$ids['open'], 'deleted_at' => $past]);

        $video = function (string $name, ?string $series, array $extra = []) use ($db, $id): void {
            $db->insert('videos', $extra + ['id' => $id($name), 'title' => ucfirst($name), 'slug' => $name, 'published' => true, 'scripture_refs' => [], 'series_id' => $series === null ? null : self::$ids[$series]]);
        };
        $video('v-public', 'public');
        $video('v-in-restricted', 'restricted');
        $video('v-own-grant', 'restricted');
        $video('v-flagged', 'public', ['member_only' => true]);
        $video('v-deep', 'deep');
        $video('v-premiere', 'public', ['is_premiere' => true, 'publish_at' => $future]);
        $video('v-scheduled', 'public', ['publish_at' => $future]);
        $video('v-standalone', null, ['category_id' => self::$ids['members']]);

        $db->insert('users', ['id' => $id('ruth'), 'email' => 'ruth@x.test', 'authorized' => true]);
        $db->insert('users', ['id' => $id('boaz'), 'email' => 'boaz@x.test', 'authorized' => true]);
        $db->insert('users', ['id' => $id('naomi'), 'email' => 'naomi@x.test', 'authorized' => true]);
        $db->insert('permission_groups', ['id' => $id('elders'), 'name' => 'Elders', 'capabilities' => []]);
        $db->insert('group_assignments', ['id' => Id::new(), 'user_id' => self::$ids['naomi'], 'group_id' => self::$ids['elders']]);
        $db->insert('series_viewers', ['id' => Id::new(), 'series_id' => self::$ids['restricted'], 'user_id' => self::$ids['boaz']]);
        $db->insert('series_viewer_groups', ['id' => Id::new(), 'series_id' => self::$ids['restricted'], 'group_id' => self::$ids['elders']]);
        $db->insert('video_viewers', ['id' => Id::new(), 'video_id' => self::$ids['v-own-grant'], 'user_id' => self::$ids['ruth']]);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::dropPrefix(self::$db, self::PREFIX);
        }
    }

    private function access(Viewer $viewer): ContentAccess
    {
        if (self::$db === null || self::$app === null) {
            self::markTestSkipped('MT_TEST_DB_NAME is not set.');
        }
        Cache::forgetMemo();
        return new ContentAccess(self::$app, $viewer, new \DateTimeImmutable('2026-09-25 12:00:00', new \DateTimeZone('UTC')));
    }

    /** @return array<string, mixed> */
    private function row(string $table, string $name): array
    {
        return (array) self::$db?->one("SELECT * FROM {{{$table}}} WHERE id = ?", [self::$ids[$name]]);
    }

    private function member(string $name, array $groups = []): Viewer
    {
        return new Viewer($this->row('users', $name) + ['role' => 'MEMBER'], false, $groups);
    }

    public function testSeriesDecisions(): void
    {
        $guest = $this->access(Viewer::guest());
        $this->assertSame('ok', $guest->series($this->row('series', 'public')));
        $this->assertSame('login', $guest->series($this->row('series', 'flagged')));
        $this->assertSame('login', $guest->series($this->row('series', 'deep')), 'members-only inherited from a grandparent category');
        $this->assertSame('login', $guest->series($this->row('series', 'restricted')));
        foreach (['hiddenone', 'scheduled', 'expired', 'draft', 'trashed'] as $gone) {
            $this->assertSame('missing', $guest->series($this->row('series', $gone)), $gone);
        }
        $ruth = $this->access($this->member('ruth'));
        $this->assertSame('ok', $ruth->series($this->row('series', 'deep')));
        $this->assertSame('denied', $ruth->series($this->row('series', 'restricted')), 'members-only no longer decides a restricted item');
        $this->assertSame('ok', $this->access($this->member('boaz'))->series($this->row('series', 'restricted')), 'a named person');
        $this->assertSame('ok', $this->access($this->member('naomi', [self::$ids['elders']]))->series($this->row('series', 'restricted')), 'a listed role');
        $admin = $this->access(new Viewer(['id' => 'x', 'email' => 'a@x.test', 'role' => 'ADMIN'], true));
        $this->assertSame('ok', $admin->series($this->row('series', 'restricted')));
        $this->assertSame('ok', $admin->series($this->row('series', 'draft')), 'a manager previews');
        $shared = $this->access(new Viewer(null, false, [], ['series' => [self::$ids['restricted']], 'videos' => []]));
        $this->assertSame('ok', $shared->series($this->row('series', 'restricted')), 'a grant opens it with no account at all');
        $this->assertSame('missing', $shared->series($this->row('series', 'draft')), 'but never what isn’t published');
    }

    public function testVideoDecisions(): void
    {
        $guest = $this->access(Viewer::guest());
        $video = fn (string $n) => $this->row('videos', $n);
        $series = fn (string $n) => $this->row('series', $n);
        $this->assertSame('ok', $guest->video($video('v-public'), $series('public')));
        $this->assertSame('login', $guest->video($video('v-flagged'), $series('public')));
        $this->assertSame('login', $guest->video($video('v-deep'), $series('deep')));
        $this->assertSame('login', $guest->video($video('v-standalone')), 'a standalone video in a members-only category');
        $this->assertSame('ok', $guest->video($video('v-premiere'), $series('public')), 'a premiere page shows its countdown');
        $this->assertSame('missing', $guest->video($video('v-scheduled'), $series('public')));
        $ruth = $this->access($this->member('ruth'));
        $this->assertSame('denied', $ruth->video($video('v-in-restricted'), $series('restricted')), 'inherits its series’ restriction');
        $this->assertSame('ok', $ruth->video($video('v-own-grant'), $series('restricted')), 'its own grants decide, not its series’');
        $this->assertSame('denied', $this->access($this->member('boaz'))->video($video('v-own-grant'), $series('restricted')));
    }

    /** @return list<string> */
    private function listed(ContentAccess $access, string $kind): array
    {
        if ($kind === 'series') {
            [$where, $params] = $access->seriesListSql('s');
            $rows = self::$db?->all("SELECT s.slug FROM {{series}} s WHERE $where ORDER BY s.slug", $params) ?? [];
        } else {
            [$where, $params] = $access->videoListSql('v', 's');
            $rows = self::$db?->all("SELECT v.slug FROM {{videos}} v LEFT JOIN {{series}} s ON s.id = v.series_id WHERE $where ORDER BY v.slug", $params) ?? [];
        }
        return array_column($rows, 'slug');
    }

    public function testListingsShowOnlyWhatTheReaderMaySee(): void
    {
        $this->assertSame(['public'], $this->listed($this->access(Viewer::guest()), 'series'));
        $this->assertSame(['deep', 'flagged', 'public'], $this->listed($this->access($this->member('ruth')), 'series'));
        $this->assertSame(['deep', 'flagged', 'public', 'restricted'], $this->listed($this->access($this->member('boaz')), 'series'));
        $this->assertSame(['v-public'], $this->listed($this->access(Viewer::guest()), 'videos'));
        $this->assertSame(['v-deep', 'v-flagged', 'v-own-grant', 'v-public', 'v-standalone'], $this->listed($this->access($this->member('ruth')), 'videos'));
        $this->assertSame(['v-deep', 'v-flagged', 'v-in-restricted', 'v-public', 'v-standalone'], $this->listed($this->access($this->member('boaz')), 'videos'));
        $this->assertSame(['public', 'restricted'], $this->listed($this->access(new Viewer(null, false, [], ['series' => [self::$ids['restricted']], 'videos' => []])), 'series'));
    }
}
