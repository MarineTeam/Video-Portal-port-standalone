<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\App;
use App\Core\Cache;
use App\Core\Db;
use App\Core\Http;
use App\Core\HttpResponse;
use App\Core\Id;
use App\Core\Migrator;
use App\Core\Paths;
use App\Modules\Library\Admin\BunnyAudit;

/**
 * What is at Bunny against what this site thinks is at Bunny, with Bunny
 * recorded rather than real.
 *
 * The two answers are not the same question and the test keeps them apart:
 * an orphan costs money, a missing object breaks a download on a Sunday.
 */
final class BunnyAuditTest extends DatabaseTestCase
{
    private const PREFIX = 'audit2_';
    private static ?Db $db = null;
    private static ?App $app = null;
    private static string $storage = '';

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
        self::$storage = sys_get_temp_dir() . '/mt-audit2-' . bin2hex(random_bytes(4));
        mkdir(self::$storage . '/tmp', 0777, true);
        $root = dirname(__DIR__, 2);
        self::$app = new App(new Paths($root, self::$storage, "$root/plugins", "$root/themes"));
        self::$app->config = ['database' => self::dbConfig(self::PREFIX)];
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::dropPrefix(self::$db, self::PREFIX);
            \App\Modules\Plugins\PackageInstaller::removeTree(self::$storage);
        }
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('MT_TEST_DB_NAME is not set.');
        }
        Cache::forgetMemo();
        Cache::forget('settings');
        self::$db->run('DELETE FROM {{file_assets}}');
        self::$db->run('DELETE FROM {{videos}}');
        self::$db->run('DELETE FROM {{services}}');
        Http::fakeResolver(fn () => ['93.184.216.34']);
    }

    protected function tearDown(): void
    {
        Http::fake(null);
        Http::fakeResolver(null);
    }

    private function configureBunny(bool $files = true, bool $video = true): void
    {
        if ($files) {
            self::$db->insert('services', [
                'id' => Id::new(), 'slot' => 'files', 'provider' => 'bunny', 'active' => 0,
                'config' => (string) json_encode(['zone' => 'church', 'api_key' => 'k', 'region' => '', 'pull_zone_host' => 'c.b-cdn.net']),
            ]);
        }
        if ($video) {
            self::$db->insert('services', [
                'id' => Id::new(), 'slot' => 'video', 'provider' => 'bunny', 'active' => 0,
                'config' => (string) json_encode(['libraryId' => '1', 'apiKey' => 'k', 'cdnHostname' => 'v.b-cdn.net']),
            ]);
        }
        Cache::forgetMemo();
        Cache::forget('settings');
    }

    /**
     * @param array<string, list<array{string, bool, int}>> $zone folder => [name, isDirectory, size]
     * @param list<array{string, string}> $library guid, title
     */
    private function bunny(array $zone, array $library = []): void
    {
        Http::fake(function (string $method, string $url) use ($zone, $library): HttpResponse {
            if (str_contains($url, 'video.bunnycdn.com')) {
                $page = (int) (self::queryOf($url)['page'] ?? 1);
                $items = $page === 1 ? array_map(fn (array $v) => ['guid' => $v[0], 'title' => $v[1], 'status' => 4], $library) : [];
                return new HttpResponse(200, [], (string) json_encode(['items' => $items]));
            }
            // Bunny Storage lists one folder per request; the path after the
            // zone name is the folder being asked about.
            $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
            $folder = trim((string) preg_replace('#^church/?#', '', $path), '/');
            $entries = $zone[$folder] ?? null;
            if ($entries === null) {
                return new HttpResponse(404, [], '[]');
            }
            return new HttpResponse(200, [], (string) json_encode(array_map(
                fn (array $e) => ['ObjectName' => $e[0], 'IsDirectory' => $e[1], 'Length' => $e[2]],
                $entries,
            )));
        });
    }

    /** @return array<string, string> */
    private static function queryOf(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        return array_map('strval', $query);
    }

    private function file(string $path): string
    {
        $id = Id::new();
        self::$db->insert('file_assets', [
            'id' => $id, 'title' => "File $path", 'backend' => 'bunny', 'storage_path' => $path, 'published' => 1,
        ]);
        return $id;
    }

    private function video(string $guid): string
    {
        $id = Id::new();
        self::$db->insert('videos', [
            'id' => $id, 'title' => "Video $guid", 'slug' => $guid, 'provider' => 'bunny',
            'external_id' => $guid, 'status' => 'READY', 'scripture_refs' => '[]', 'provider_data' => '{}',
        ]);
        return $id;
    }

    public function test_1_an_object_no_row_points_at_is_an_orphan(): void
    {
        $this->configureBunny();
        $this->file('books/kept.pdf');
        $this->bunny([
            '' => [['books', true, 0]],
            'books' => [['kept.pdf', false, 100], ['forgotten.pdf', false, 9000]],
        ]);

        $files = (new BunnyAudit(self::$app))->run()['files'];

        self::assertTrue($files['checked']);
        self::assertSame(2, $files['atBunny']);
        self::assertSame([['path' => 'books/forgotten.pdf', 'bytes' => 9000]], $files['orphans']);
        self::assertSame([], $files['missing']);
    }

    public function test_2_a_row_pointing_at_nothing_is_a_download_that_will_fail(): void
    {
        $this->configureBunny();
        $id = $this->file('books/gone.pdf');
        $this->bunny(['' => []]);

        $files = (new BunnyAudit(self::$app))->run()['files'];

        self::assertSame([], $files['orphans']);
        self::assertSame([['id' => $id, 'title' => 'File books/gone.pdf', 'path' => 'books/gone.pdf']], $files['missing']);
    }

    public function test_3_the_biggest_orphan_is_named_first(): void
    {
        $this->configureBunny();
        $this->bunny(['' => [['small.pdf', false, 10], ['huge.pdf', false, 5_000_000], ['middling.pdf', false, 900]]]);

        self::assertSame(
            ['huge.pdf', 'middling.pdf', 'small.pdf'],
            array_column((new BunnyAudit(self::$app))->run()['files']['orphans'], 'path'),
            'the order somebody clearing space wants',
        );
    }

    public function test_4_the_walk_goes_into_folders(): void
    {
        $this->configureBunny();
        $this->bunny([
            '' => [['books', true, 0], ['loose.pdf', false, 1]],
            'books' => [['2026', true, 0]],
            'books/2026' => [['deep.pdf', false, 2]],
        ]);

        // Biggest first, as always; what this proves is that the walk went
        // two folders down rather than stopping at the root.
        self::assertSame(
            ['books/2026/deep.pdf', 'loose.pdf'],
            array_column((new BunnyAudit(self::$app))->run()['files']['orphans'], 'path'),
        );
    }

    public function test_5_a_video_in_the_library_that_nobody_added_here_is_an_orphan(): void
    {
        $this->configureBunny();
        $this->video('guid-kept');
        $missingId = $this->video('guid-gone');
        $this->bunny(['' => []], [['guid-kept', 'A sermon'], ['guid-stray', 'Somebody’s test upload']]);

        $videos = (new BunnyAudit(self::$app))->run()['videos'];

        self::assertTrue($videos['checked']);
        self::assertSame([['guid' => 'guid-stray', 'title' => 'Somebody’s test upload']], $videos['orphans']);
        self::assertSame([['id' => $missingId, 'title' => 'Video guid-gone', 'guid' => 'guid-gone']], $videos['missing']);
    }

    public function test_6_a_slot_that_is_not_on_bunny_says_so_rather_than_reporting_everything_missing(): void
    {
        $this->file('books/one.pdf');
        $this->video('guid-1');
        $this->bunny(['' => []]);

        $audit = (new BunnyAudit(self::$app))->run();

        foreach (['files', 'videos'] as $half) {
            self::assertFalse($audit[$half]['checked'], $half);
            self::assertStringContainsString('not set up', (string) $audit[$half]['why']);
            self::assertSame([], $audit[$half]['missing'], 'and claims nothing about rows it could not check');
        }
    }

    public function test_7_a_refusal_from_bunny_is_reported_rather_than_read_as_an_empty_zone(): void
    {
        $this->configureBunny();
        $this->file('books/one.pdf');
        Http::fake(fn () => new HttpResponse(401, [], 'no'));

        $audit = (new BunnyAudit(self::$app))->run();

        self::assertFalse($audit['files']['checked']);
        self::assertSame([], $audit['files']['missing'], 'a key that was refused does not mean every file is gone');
    }

    public function test_8_a_deleted_row_is_not_reported_as_missing(): void
    {
        $this->configureBunny();
        $id = $this->video('guid-trashed');
        self::$db->update('videos', ['deleted_at' => \App\Core\Db::now()], ['id' => $id]);
        $this->bunny(['' => []], []);

        self::assertSame([], (new BunnyAudit(self::$app))->run()['videos']['missing']);
    }
}
