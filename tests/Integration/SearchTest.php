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
use App\Modules\Library\Search;
use App\Modules\Library\Viewer;

/**
 * Search against a real database: ranking, the fuzzy fallback ("chruch"
 * finds "Church"), full-text word order, and that a visitor never finds
 * members-only content by searching for it.
 */
final class SearchTest extends DatabaseTestCase
{
    private const PREFIX = 'se_';
    private static ?Db $db = null;
    private static ?App $app = null;

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

        $series = function (string $title, array $extra = []) use ($db): string {
            $id = Id::new();
            $db->insert('series', $extra + ['id' => $id, 'title' => $title, 'slug' => Id::new(), 'published' => true, 'tags' => []]);
            return $id;
        };
        $open = $series('The Church in Acts', ['description' => 'How the early believers met.']);
        $series('Sermons on Grace', ['description' => 'A church-wide study.']);
        $series('Church Members Meeting', ['member_only' => true]);
        $db->insert('videos', ['id' => Id::new(), 'title' => 'Church history, part one', 'slug' => 'history', 'published' => true, 'scripture_refs' => [], 'series_id' => $open]);
        $db->insert('videos', ['id' => Id::new(), 'title' => 'Budget for church members', 'slug' => 'budget', 'published' => true, 'member_only' => true, 'scripture_refs' => []]);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::dropPrefix(self::$db, self::PREFIX);
        }
    }

    /** @return array<string, mixed> */
    private function search(string $q, ?Viewer $viewer = null): array
    {
        if (self::$db === null || self::$app === null) {
            self::markTestSkipped('MT_TEST_DB_NAME is not set.');
        }
        Cache::forgetMemo();
        $access = new ContentAccess(self::$app, $viewer ?? Viewer::guest());
        return (new Search(self::$app, new Browse(self::$app, $access)))->run($q);
    }

    /** @param list<array<string, mixed>> $rows @return list<string> */
    private static function titles(array $rows): array
    {
        return array_map(fn ($r) => (string) $r['title'], $rows);
    }

    public function testTitleMatchesOutrankDescriptionMatches(): void
    {
        $r = $this->search('church');
        self::assertFalse($r['fuzzy']);
        self::assertSame(['The Church in Acts', 'Sermons on Grace'], self::titles($r['series']));
        self::assertSame(['Church history, part one'], self::titles($r['videos']));
    }

    public function testChruchFindsChurch(): void
    {
        $r = $this->search('chruch');
        self::assertTrue($r['fuzzy']);
        self::assertContains('The Church in Acts', self::titles($r['series']));
        self::assertContains('Church history, part one', self::titles($r['videos']));
    }

    public function testWordsInAnotherOrderStillMatch(): void
    {
        self::assertContains('The Church in Acts', self::titles($this->search('acts church')['series']));
    }

    public function testVisitorsNeverFindMembersOnlyContent(): void
    {
        foreach (['church', 'chruch', 'members', 'budget'] as $q) {
            $r = $this->search($q);
            self::assertNotContains('Church Members Meeting', self::titles($r['series']), $q);
            self::assertNotContains('Budget for church members', self::titles($r['videos']), $q);
        }
        $member = new Viewer(['id' => Id::new(), 'email' => 'm@x.test', 'role' => 'MEMBER']);
        self::assertContains('Church Members Meeting', self::titles($this->search('members', $member)['series']));
        self::assertContains('Budget for church members', self::titles($this->search('budget', $member)['videos']));
    }
}
