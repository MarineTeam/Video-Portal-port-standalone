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
use App\Modules\Tools\Import\FilePull;
use App\Services\Files\LocalDiskProvider;

/**
 * Pulling files out of the old site's Bunny Storage, against a real
 * database and a recorded Bunny.
 *
 * What is being proved: a file is only repointed once its bytes are here; a
 * file Bunny will not give back is left pointing at Bunny and named; the
 * copy is given a name this site's store will accept, whatever it was
 * called over there; and the offer is not made at all to a site that keeps
 * its files in Bunny anyway.
 */
final class FilePullTest extends DatabaseTestCase
{
    private const PREFIX = 'pull_';
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
        self::$storage = sys_get_temp_dir() . '/mt-pull-' . bin2hex(random_bytes(4));
        mkdir(self::$storage . '/uploads', 0777, true);
        mkdir(self::$storage . '/tmp', 0777, true);
        $root = dirname(__DIR__, 2);
        self::$app = new App(new Paths($root, self::$storage, "$root/plugins", "$root/themes"));
        self::$app->config = ['database' => self::dbConfig(self::PREFIX)];
        LocalDiskProvider::configureRoot(self::$storage . '/uploads');
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
        self::$db->run('DELETE FROM {{services}}');
        // Bunny configured so there is something to read the old files with,
        // and local disk left active, which is the case this exists for.
        self::$db->insert('services', [
            'id' => Id::new(),
            'slot' => 'files',
            'provider' => 'bunny',
            'config' => (string) json_encode(['zone' => 'old-church', 'api_key' => 'k', 'region' => '', 'pull_zone_host' => 'old.b-cdn.net']),
            'active' => 0,
        ]);
        Http::fakeResolver(fn (string $host) => ['93.184.216.34']);
    }

    protected function tearDown(): void
    {
        Http::fake(null);
        Http::fakeResolver(null);
    }

    /** A row still pointing at the old storage. */
    private function file(string $path, ?string $title = null): string
    {
        $id = Id::new();
        self::$db->insert('file_assets', [
            'id' => $id, 'title' => $title ?? $path, 'backend' => 'bunny',
            'storage_path' => $path, 'size_bytes' => 9,
        ]);
        return $id;
    }

    /** @param array<string, string|null> $bytesByPath null for a file Bunny refuses */
    private function bunny(array $bytesByPath): void
    {
        Http::fake(function (string $method, string $url, array $headers, ?string $body, array $options) use ($bytesByPath): HttpResponse {
            foreach ($bytesByPath as $path => $bytes) {
                if (str_contains($url, rawurlencode($path)) || str_contains($url, str_replace('%2F', '/', rawurlencode($path)))) {
                    if ($bytes === null) {
                        return new HttpResponse(404, [], 'Not found');
                    }
                    if (isset($options['sink']) && is_callable($options['sink'])) {
                        ($options['sink'])($bytes);
                    }
                    return new HttpResponse(200, [], '');
                }
            }
            return new HttpResponse(404, [], 'Not found');
        });
    }

    public function test_1_a_file_is_copied_here_and_the_row_follows_the_bytes(): void
    {
        $id = $this->file('books/hymnal.pdf');
        $this->bunny(['books/hymnal.pdf' => 'PDF-BYTES']);

        $result = (new FilePull(self::$app))->step();

        self::assertSame(1, $result['moved']);
        self::assertSame(9, $result['bytes']);
        self::assertSame(0, $result['left']);
        self::assertSame([], $result['failures']);

        $row = self::$db->one('SELECT backend, storage_path FROM {{file_assets}} WHERE id = ?', [$id]);
        self::assertSame('local', $row['backend']);
        self::assertSame(FilePull::objectName($id, 'books/hymnal.pdf'), $row['storage_path']);
        self::assertSame('PDF-BYTES', file_get_contents(self::$storage . '/uploads/' . $row['storage_path']));
    }

    public function test_2_a_name_bunny_accepted_is_renamed_to_one_this_store_will_take(): void
    {
        $id = $this->file('scans/Hymnal Scan (2019).PDF');
        $this->bunny(['scans/Hymnal Scan (2019).PDF' => 'SCANBYTES']);

        self::assertSame(1, (new FilePull(self::$app))->step()['moved']);

        $path = (string) self::$db->value('SELECT storage_path FROM {{file_assets}} WHERE id = ?', [$id]);
        self::assertMatchesRegularExpression('#^files/[a-z0-9]+\.pdf$#', $path, 'no spaces, no capitals, no brackets');
        self::assertFileExists(self::$storage . '/uploads/' . $path);
    }

    public function test_3_a_file_bunny_will_not_give_back_is_left_exactly_as_it_was(): void
    {
        $gone = $this->file('books/missing.pdf', 'The missing one');
        $fine = $this->file('books/here.pdf');
        $this->bunny(['books/missing.pdf' => null, 'books/here.pdf' => 'HEREBYTES']);

        $result = (new FilePull(self::$app))->step();

        self::assertSame(1, $result['moved']);
        self::assertCount(1, $result['failures']);
        self::assertSame($gone, $result['failures'][0]['id']);
        self::assertSame('The missing one', $result['failures'][0]['title']);

        $row = self::$db->one('SELECT backend, storage_path FROM {{file_assets}} WHERE id = ?', [$gone]);
        self::assertSame('bunny', $row['backend'], 'still pointing at Bunny, where it still works');
        self::assertSame('books/missing.pdf', $row['storage_path']);
        self::assertSame('local', self::$db->value('SELECT backend FROM {{file_assets}} WHERE id = ?', [$fine]));
    }

    public function test_4_an_empty_answer_is_a_failure_not_an_empty_file(): void
    {
        $id = $this->file('books/truncated.pdf');
        $this->bunny(['books/truncated.pdf' => '']);

        $result = (new FilePull(self::$app))->step();

        self::assertSame(0, $result['moved']);
        self::assertCount(1, $result['failures']);
        self::assertSame('bunny', self::$db->value('SELECT backend FROM {{file_assets}} WHERE id = ?', [$id]));
    }

    public function test_5_nothing_is_offered_when_there_is_nothing_to_move(): void
    {
        $pull = new FilePull(self::$app);
        self::assertFalse($pull->needed(), 'no rows point at Bunny');
        self::assertSame(['files' => 0, 'bytes' => 0], $pull->pending());

        $this->file('books/one.pdf');
        self::assertTrue((new FilePull(self::$app))->needed());
    }

    public function test_6_a_site_that_keeps_its_files_in_bunny_is_told_there_is_nothing_to_do(): void
    {
        $this->file('books/one.pdf');
        self::$db->update('services', ['active' => 1], ['slot' => 'files']);
        Cache::forgetMemo();
        Cache::forget('settings');

        self::assertFalse((new FilePull(self::$app))->needed());
        $this->expectExceptionMessageMatches('/already keeps its files in Bunny/');
        (new FilePull(self::$app))->step();
    }
}
