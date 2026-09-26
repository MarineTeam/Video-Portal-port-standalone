<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Id;
use App\Modules\Tools\Import\Export;

/**
 * The import from the Next.js site, through a real server: a zip is built
 * here exactly as tools/export-from-nextjs/export.mjs writes one, uploaded
 * in chunks the way the browser uploads it, and read in a step at a time.
 *
 * What is being proved: the counts match the export's own manifest; names
 * and shapes are converted (camelCase columns, Postgres booleans, arrays,
 * the renamed video columns); a category's parent survives being written
 * before its parent exists; the two models that are credentials are left
 * behind with a reason; a row nothing can accept is reported rather than
 * ending the run; and importing the same export twice adds nothing.
 */
final class ImportTest extends ServerTestCase
{
    private const EXPORTED_AT = '2026-03-01T09:30:00.000Z';

    protected static function prefix(): string
    {
        return 'imp_';
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::install();
    }

    /**
     * The export, as the Node script writes it: one JSON object per line
     * per model, and a manifest of the counts.
     *
     * @param array<string, list<array<string, mixed>>> $tables
     * @param list<string> $emptyModels models the old site had but never used
     */
    private static function makeExport(array $tables, array $emptyModels = []): string
    {
        $path = tempnam(sys_get_temp_dir(), 'export') . '.zip';
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true);
        $manifest = ['format' => Export::FORMAT, 'exportedAt' => self::EXPORTED_AT, 'tables' => []];
        foreach ($tables as $model => $rows) {
            $lines = '';
            foreach ($rows as $row) {
                $lines .= json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
            }
            $zip->addFromString("$model.ndjson", $lines);
            $manifest['tables'][$model] = count($rows);
        }
        foreach ($emptyModels as $model) {
            $zip->addFromString("$model.ndjson", '');
            $manifest['tables'][$model] = 0;
        }
        $zip->addFromString('manifest.json', (string) json_encode($manifest));
        $zip->close();
        return $path;
    }

    /** The CSRF token the pages carry, for the one call that sends raw bytes. */
    private static function token(): string
    {
        preg_match('/name="csrf-token" content="([^"]+)"/', self::http('GET', '/')['body'], $m);
        return $m[1] ?? '';
    }

    /** The upload, in two chunks, so the resumable path is the one under test. */
    private static function upload(string $path): string
    {
        $bytes = (string) file_get_contents($path);
        $created = self::api('POST', '/api/uploads', ['purpose' => 'import', 'fileName' => 'export.zip', 'size' => strlen($bytes)]);
        self::assertSame(201, $created['status'], (string) json_encode($created['json']));
        $id = (string) $created['json']['id'];
        $offset = 0;
        $chunk = (int) ceil(strlen($bytes) / 2);
        while ($offset < strlen($bytes)) {
            $answer = self::http('PUT', "/api/uploads/$id/chunk?offset=$offset", substr($bytes, $offset, $chunk), 'admin', [
                'Content-Type' => 'application/octet-stream',
                'X-CSRF-Token' => self::token(),
            ]);
            self::assertSame(200, $answer['status'], (string) json_encode($answer['json']));
            $offset = (int) $answer['json']['received'];
        }
        return $id;
    }

    /** Upload, start, and step until it says it is done. */
    private static function runImport(string $zipPath): array
    {
        $upload = self::upload($zipPath);
        $started = self::api('POST', '/api/admin/tools/import', ['upload' => $upload]);
        self::assertSame(201, $started['status'], (string) json_encode($started['json']));
        $state = $started['json'];
        for ($step = 0; $step < 200 && $state['phase'] !== 'done'; $step++) {
            $answer = self::api('POST', '/api/admin/tools/import/' . $state['id'] . '/step');
            self::assertSame(200, $answer['status'], (string) json_encode($answer['json']));
            $state = $answer['json'];
        }
        self::assertSame('done', $state['phase'], 'the import finished within a sane number of steps');
        return $state;
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

    public function test_1_a_whole_export_arrives_with_the_counts_the_manifest_gave(): void
    {
        $parentId = Id::new();
        $childId = Id::new();
        $seriesId = Id::new();
        $videoId = Id::new();
        $zip = self::makeExport([
            // The child comes first on purpose: its parent does not exist yet
            // when it is written, which is what the relink phase is for.
            'Category' => [
                ['id' => $childId, 'name' => 'Sunday mornings', 'slug' => 'sunday-mornings', 'parentId' => $parentId, 'published' => true, 'tags' => [], 'createdAt' => '2026-01-02 10:00:00+00'],
                ['id' => $parentId, 'name' => 'Services', 'slug' => 'services', 'parentId' => null, 'published' => true, 'tags' => ['worship'], 'createdAt' => '2026-01-01 10:00:00+00'],
            ],
            'Series' => [
                ['id' => $seriesId, 'title' => 'Advent', 'slug' => 'advent', 'categoryId' => $childId, 'published' => true, 'memberOnly' => false, 'tags' => ['Advent', 'advent', 'hope'], 'viewCount' => 12],
            ],
            'Video' => [
                ['id' => $videoId, 'title' => 'The first Sunday', 'slug' => 'first-sunday', 'source' => 'BUNNY', 'bunnyVideoId' => 'guid-1', 'externalId' => null, 'seriesId' => $seriesId, 'published' => true, 'scriptureRefs' => ['Luke 1', 'Isaiah 9'], 'durationSeconds' => 2400, 'createdAt' => '2026-02-01T09:00:00.000Z'],
                ['id' => Id::new(), 'title' => 'A YouTube one', 'slug' => 'youtube-one', 'source' => 'YOUTUBE', 'bunnyVideoId' => null, 'externalId' => 'dQw4w9WgXcQ', 'seriesId' => $seriesId, 'published' => false, 'scriptureRefs' => []],
            ],
            'PushSubscription' => [['id' => Id::new(), 'endpoint' => 'https://fcm.googleapis.com/x', 'userId' => 'nobody']],
        ], ['Speaker']);

        $state = self::runImport($zip);
        $report = self::byModel($state);

        self::assertSame(2, $report['Category']['written']);
        self::assertSame(1, $report['Series']['written']);
        self::assertSame(2, $report['Video']['written']);
        foreach (['Category', 'Series', 'Video', 'Speaker'] as $model) {
            self::assertTrue($report[$model]['ok'], "$model matches the manifest");
        }

        $db = self::connect(self::prefix());
        self::assertSame('Sunday mornings', $db->value('SELECT name FROM {{categories}} WHERE id = ?', [$childId]));
        self::assertSame($parentId, $db->value('SELECT parent_id FROM {{categories}} WHERE id = ?', [$childId]), 'the child found its parent after everything was in');
        self::assertSame(12, (int) $db->value('SELECT view_count FROM {{series}} WHERE id = ?', [$seriesId]));
        self::assertSame(1, (int) $db->value('SELECT published FROM {{series}} WHERE id = ?', [$seriesId]), 'a Postgres boolean is a MySQL 1');

        self::assertSame('bunny', $db->value('SELECT provider FROM {{videos}} WHERE id = ?', [$videoId]));
        self::assertSame('guid-1', $db->value('SELECT external_id FROM {{videos}} WHERE id = ?', [$videoId]));
        self::assertSame('youtube', $db->value('SELECT provider FROM {{videos}} WHERE slug = ?', ['youtube-one']));
        self::assertSame('dQw4w9WgXcQ', $db->value('SELECT external_id FROM {{videos}} WHERE slug = ?', ['youtube-one']));
        self::assertSame('2026-02-01 09:00:00.000', $db->value('SELECT created_at FROM {{videos}} WHERE id = ?', [$videoId]));

        self::assertSame(['advent', 'hope'], $db->column('SELECT tag FROM {{series_tags}} WHERE series_id = ? ORDER BY tag', [$seriesId]));
        self::assertSame(['Isaiah 9', 'Luke 1'], $db->column('SELECT book FROM {{video_scripture_books}} WHERE video_id = ? ORDER BY book', [$videoId]));
    }

    public function test_2_a_credential_that_only_works_on_the_old_site_is_left_behind_with_a_reason(): void
    {
        $state = self::runImport(self::makeExport([
            'TvDevice' => [['id' => Id::new(), 'name' => 'Foyer screen', 'tokenHash' => 'abc']],
        ]));
        $why = [];
        foreach ($state['skipped'] as $one) {
            $why[$one['model']] = $one['why'];
        }
        self::assertArrayHasKey('TvDevice', $why);
        self::assertStringContainsString('Pair the screens again', $why['TvDevice']);
        self::assertSame(0, (int) self::connect(self::prefix())->value('SELECT COUNT(*) FROM {{tv_devices}}'));
        // Nothing arrived, and the screen still calls that table settled:
        // it was never going to arrive.
        self::assertTrue(self::byModel($state)['TvDevice']['ok']);
    }

    public function test_3_running_the_same_export_twice_adds_nothing(): void
    {
        $zip = self::makeExport([
            'Speaker' => [['id' => Id::new(), 'name' => 'A visiting preacher', 'slug' => 'visiting']],
        ]);
        $first = self::byModel(self::runImport($zip));
        self::assertSame(1, $first['Speaker']['written']);

        $again = self::byModel(self::runImport($zip));
        self::assertSame(0, $again['Speaker']['written']);
        self::assertSame(1, $again['Speaker']['present'], 'the row is recognised as already here');
        self::assertTrue($again['Speaker']['ok'], 'which still counts as the whole table having arrived');
        self::assertSame(1, (int) self::connect(self::prefix())->value('SELECT COUNT(*) FROM {{speakers}} WHERE slug = ?', ['visiting']));
    }

    public function test_4_a_row_nothing_can_accept_is_reported_and_the_rest_still_arrive(): void
    {
        $good = Id::new();
        $state = self::runImport(self::makeExport([
            'Speaker' => [
                // A series that does not exist anywhere: the foreign key
                // refuses it, and the run carries on.
                ['id' => Id::new(), 'name' => 'Too long a name for the column' . str_repeat('!', 300), 'slug' => 'too-long'],
                ['id' => $good, 'name' => 'A fine one', 'slug' => 'fine'],
            ],
        ]));
        $report = self::byModel($state);
        self::assertSame(1, $report['Speaker']['written']);
        self::assertSame(1, $report['Speaker']['refused']);
        self::assertFalse($report['Speaker']['ok'], 'a table short of its manifest count is marked');
        self::assertNotSame([], $state['errors']);
        self::assertSame('Speaker', $state['errors'][0]['model']);
        self::assertSame($good, self::connect(self::prefix())->value('SELECT id FROM {{speakers}} WHERE slug = ?', ['fine']));
    }

    public function test_5_a_column_this_schema_has_not_is_named_rather_than_swallowed(): void
    {
        $state = self::runImport(self::makeExport([
            'Speaker' => [['id' => Id::new(), 'name' => 'With extras', 'slug' => 'extras', 'favouriteBiscuit' => 'rich tea']],
        ]));
        $dropped = [];
        foreach ($state['dropped'] as $one) {
            $dropped[$one['model']] = $one['fields'];
        }
        self::assertSame(['favouriteBiscuit'], $dropped['Speaker'] ?? []);
    }

    public function test_6_an_export_from_something_else_is_refused_by_name(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'export') . '.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', (string) json_encode(['format' => 'someone-elses/9', 'tables' => []]));
        $zip->close();

        $answer = self::api('POST', '/api/admin/tools/import', ['upload' => self::upload($path)]);
        self::assertSame(400, $answer['status']);
        self::assertStringContainsString('someone-elses/9', (string) $answer['body']);
    }

    public function test_7_a_member_may_not_start_one(): void
    {
        self::member('member@test.example', 'member');
        self::assertSame(403, self::api('POST', '/api/admin/tools/import', ['upload' => 'x'], 'member')['status']);
    }
}
