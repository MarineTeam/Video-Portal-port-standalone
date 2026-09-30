<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * The migration, both halves of it, meeting in the middle.
 *
 * ImportTest builds its zip by hand and says in a comment that it is shaped
 * "exactly as tools/export-from-nextjs/export.mjs writes one". Nothing
 * checked that. The exporter is the one program here that runs against
 * somebody else's database, on a laptop, once, on the day a church leaves
 * the old platform — and if what it writes is not quite what the importer
 * reads, the suite stays green and the migration fails on the day, with the
 * old site already being turned off.
 *
 * So this runs the real exporter, unchanged, against a stand-in for `pg`
 * that answers its four queries from a fixture, and feeds the zip it really
 * writes to the real importer over HTTP. What is checked at the far end is
 * the content: the awkward name, the two lines, the characters outside the
 * basic plane, the timestamp to the millisecond, and the two credential
 * tables that must not have travelled at all.
 */
final class ExportRoundTripTest extends ServerTestCase
{
    protected static function prefix(): string
    {
        return 'xrt_';
    }

    private static string $zip = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::install();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$zip !== '' && is_file(self::$zip)) {
            @unlink(self::$zip);
        }
        parent::tearDownAfterClass();
    }

    /** What the old deployment's database holds, as `pg` would hand it over. */
    private const OLD_SITE = [
        'User' => [[
            'id' => 'u1',
            'email' => 'pastor@old.example',
            'name' => "Pastor O'Brien \\ \"quoted\"",
            'role' => 'ADMIN',
            'authorized' => true,
            'createdAt' => '2024-02-29 13:45:01',
        ]],
        'Category' => [[
            'id' => 'c1',
            'name' => 'Sunday mornings',
            'slug' => 'sunday-mornings',
            'parentId' => null,
            'createdAt' => '2024-01-01 00:00:00',
        ]],
        'Series' => [[
            'id' => 's1',
            'title' => 'Advent ✝ 待降節',
            'slug' => 'advent',
            'categoryId' => 'c1',
            'viewCount' => 12,
            'published' => true,
            'createdAt' => '2024-01-02 10:00:00',
            'tags' => ['advent', 'hope'],
        ]],
        'Video' => [[
            'id' => 'v1',
            'title' => "Hope\nin two lines",
            'slug' => 'hope',
            'seriesId' => 's1',
            'provider' => 'bunny',
            'externalId' => 'guid-1',
            'createdAt' => '2026-02-01 09:00:00.000',
            'scriptureRefs' => ['Isaiah 9', 'Luke 1'],
            'durationSeconds' => 2400,
        ]],
        // Neither of these may reach the new site: a screen's pairing token
        // and a push endpoint are only meaningful where they were issued.
        'TvDevice' => [[
            'id' => 'tv1',
            'code' => 'ABC123',
            'token' => 'a-secret-that-must-not-travel',
        ]],
        'PushSubscription' => [[
            'id' => 'p1',
            'endpoint' => 'https://push.example/x',
        ]],
    ];

    /** Runs tools/export-from-nextjs/export.mjs for real, and returns its zip. */
    private static function runExporter(): string
    {
        $node = trim((string) (getenv('MT_NODE') ?: 'node'));
        $root = dirname(__DIR__, 2);
        $dir = self::$storage . '/export';
        mkdir($dir, 0775, true);
        $rows = $dir . '/rows.json';
        file_put_contents($rows, (string) json_encode(self::OLD_SITE));
        $zip = $dir . '/export.zip';

        $process = proc_open(
            [
                $node,
                '--no-warnings',
                '--experimental-loader',
                $root . '/tests/js/fixtures/pg-loader.mjs',
                $root . '/tools/export-from-nextjs/export.mjs',
                'postgres://stand-in/none',
                '--out',
                $zip,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            array_merge(getenv(), ['MT_FAKE_PG' => $rows]),
        );
        if (!is_resource($process)) {
            self::markTestSkipped('node could not be started.');
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        self::assertSame(0, $status, "the exporter failed:\n$out\n$err");
        self::assertFileExists($zip, "the exporter wrote no zip:\n$out\n$err");
        return $zip;
    }

    /** @return array<string, array{expected: int, written: int, present: int, refused: int, ok: bool}> */
    private static function byModel(array $state): array
    {
        $out = [];
        foreach ($state['report'] as $row) {
            $out[$row['model']] = $row;
        }
        return $out;
    }

    private static function token(): string
    {
        preg_match('/name="csrf-token" content="([^"]+)"/', self::http('GET', '/')['body'], $m);
        return $m[1] ?? '';
    }

    private static function runImport(string $zipPath): array
    {
        $bytes = (string) file_get_contents($zipPath);
        $created = self::api('POST', '/api/uploads', ['purpose' => 'import', 'fileName' => 'export.zip', 'size' => strlen($bytes)]);
        self::assertSame(201, $created['status'], (string) json_encode($created['json']));
        $id = (string) $created['json']['id'];
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $answer = self::http('PUT', "/api/uploads/$id/chunk?offset=$offset", substr($bytes, $offset, 1 << 20), 'admin', [
                'Content-Type' => 'application/octet-stream',
                'X-CSRF-Token' => self::token(),
            ]);
            self::assertSame(200, $answer['status'], (string) json_encode($answer['json']));
            $offset = (int) $answer['json']['received'];
        }
        $started = self::api('POST', '/api/admin/tools/import', ['upload' => $id]);
        self::assertSame(201, $started['status'], (string) json_encode($started['json']));
        $state = $started['json'];
        for ($step = 0; $step < 500 && $state['phase'] !== 'done'; $step++) {
            $answer = self::api('POST', '/api/admin/tools/import/' . $state['id'] . '/step');
            self::assertSame(200, $answer['status'], (string) json_encode($answer['json']));
            $state = $answer['json'];
        }
        self::assertSame('done', $state['phase']);
        return $state;
    }

    public function testWhatTheExporterWritesIsWhatTheImporterReads(): void
    {
        self::$zip = self::runExporter();

        // Before anything is imported: the zip itself says what it is, and
        // what it does not contain.
        $archive = new \ZipArchive();
        self::assertTrue($archive->open(self::$zip) === true, 'the zip it wrote opens');
        $names = [];
        for ($i = 0; $i < $archive->numFiles; $i++) {
            $names[] = (string) $archive->getNameIndex($i);
        }
        $manifest = json_decode((string) $archive->getFromName('manifest.json'), true);
        $archive->close();

        self::assertSame(
            \App\Modules\Tools\Import\Export::FORMAT,
            $manifest['format'] ?? null,
            'the exporter and the importer name the same format',
        );
        self::assertContains('User.ndjson', $names);
        self::assertNotContains('TvDevice.ndjson', $names, 'a pairing token never leaves the old site');
        self::assertNotContains('PushSubscription.ndjson', $names, 'nor a push endpoint');
        self::assertArrayNotHasKey('TvDevice', $manifest['tables'], 'and neither is counted as exported');
        self::assertStringContainsString('credential', (string) ($manifest['notes']['TvDevice'] ?? ''), 'the manifest says why');

        $state = self::runImport(self::$zip);
        $report = self::byModel($state);
        $db0 = self::connect(self::prefix());

        foreach (['User', 'Category', 'Series', 'Video'] as $model) {
            self::assertSame(1, $report[$model]['written'] ?? null, "$model arrived: " . json_encode($state['errors'] ?? []));
            self::assertTrue($report[$model]['ok'] ?? false, "$model matches the manifest");
        }

        // This old deployment predates the tags column, as a real one may.
        // The rows still arrive, and the screen says what was left empty
        // rather than the import silently keeping a church's library out.
        $filled = [];
        foreach ($state['filled'] ?? [] as $one) {
            $filled[$one['model']] = $one['columns'];
        }
        self::assertContains('tags', $filled['Category'] ?? [], 'the missing column is named');
        self::assertSame([], json_decode((string) $db0->value('SELECT tags FROM {{categories}} WHERE id = ?', ['c1']), true), 'and left empty');

        // The content, not the count. Each of these is something a hand-made
        // fixture would have got right by construction and a real pipeline
        // can get wrong: quoting, a newline inside a value, characters
        // outside the basic plane, a Postgres boolean, a timestamp's
        // milliseconds, and the arrays that become their own tables.
        $db = $db0;
        self::assertSame("Pastor O'Brien \\ \"quoted\"", $db->value('SELECT name FROM {{users}} WHERE id = ?', ['u1']));
        self::assertSame('Advent ✝ 待降節', $db->value('SELECT title FROM {{series}} WHERE id = ?', ['s1']));
        self::assertSame("Hope\nin two lines", $db->value('SELECT title FROM {{videos}} WHERE id = ?', ['v1']));
        self::assertSame(12, (int) $db->value('SELECT view_count FROM {{series}} WHERE id = ?', ['s1']));
        self::assertSame(1, (int) $db->value('SELECT published FROM {{series}} WHERE id = ?', ['s1']), 'a Postgres boolean is a MySQL 1');
        self::assertSame('2026-02-01 09:00:00.000', $db->value('SELECT created_at FROM {{videos}} WHERE id = ?', ['v1']));
        self::assertSame(['advent', 'hope'], $db->column('SELECT tag FROM {{series_tags}} WHERE series_id = ? ORDER BY tag', ['s1']));
        self::assertSame(['Isaiah 9', 'Luke 1'], $db->column('SELECT book FROM {{video_scripture_books}} WHERE video_id = ? ORDER BY book', ['v1']));

        // And the two that must not have travelled really did not.
        self::assertSame(0, (int) $db->value('SELECT COUNT(*) FROM {{tv_devices}}'));
        self::assertSame(0, (int) $db->value('SELECT COUNT(*) FROM {{push_subscriptions}}'));
    }
}
