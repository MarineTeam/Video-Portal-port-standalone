<?php

declare(strict_types=1);

namespace Tests\Unit\Tools;

use App\Modules\Tools\Import\Mapping;
use App\Modules\Tools\Import\Row;
use PHPUnit\Framework\TestCase;

/**
 * The names and shapes an exported row takes on the way in.
 *
 * The last two tests here are the ones that matter over time: they read the
 * migration and fail if a rename listed in Mapping no longer names a real
 * column, or if a model has no table. A schema change made a year from now
 * breaks the build rather than the import.
 */
final class ImportMappingTest extends TestCase
{
    /** @return array<string, array<string, string>> table => column => type */
    private static function schema(): array
    {
        static $schema = null;
        if ($schema !== null) {
            return $schema;
        }
        $sql = (string) file_get_contents(__DIR__ . '/../../../app/Migrations/0001_init.sql');
        $schema = [];
        preg_match_all('/CREATE TABLE IF NOT EXISTS \{\{(\w+)\}\} \((.*?)\n\) ENGINE/s', $sql, $tables, PREG_SET_ORDER);
        foreach ($tables as [, $table, $body]) {
            foreach (explode("\n", $body) as $line) {
                if (preg_match('/^\s*`?(\w+)`?\s+(\w+)/', $line, $m) === 1
                    && !in_array(strtoupper($m[1]), ['PRIMARY', 'UNIQUE', 'KEY', 'CONSTRAINT', 'INDEX'], true)) {
                    $schema[$table][$m[1]] = strtolower($m[2]);
                }
            }
        }
        return $schema;
    }

    public function test_1_camel_case_becomes_snake_case(): void
    {
        self::assertSame('display_name', Mapping::snake('displayName'));
        self::assertSame('id', Mapping::snake('id'));
        self::assertSame('external_thumbnail_url', Mapping::snake('externalThumbnailUrl'));
        self::assertSame('has_mp4_fallback', Mapping::snake('hasMp4Fallback'));
    }

    public function test_2_the_renames_win_over_the_rule(): void
    {
        self::assertSame('provider', Mapping::column('Video', 'source'));
        self::assertSame('external_id', Mapping::column('Video', 'bunnyVideoId'));
        self::assertSame('storage_path', Mapping::column('FileAsset', 'bunnyPath'));
        // The same field name on another model is not renamed.
        self::assertSame('source', Mapping::column('CalendarEvent', 'source'));
    }

    public function test_3_a_bunny_video_keeps_its_guid_as_the_id_at_its_provider(): void
    {
        $made = Row::convert('Video', [
            'id' => 'v1',
            'title' => 'Sunday',
            'source' => 'BUNNY',
            'bunnyVideoId' => 'guid-1',
            'externalId' => null,
        ], self::schema()['videos']);
        self::assertSame('bunny', $made['row']['provider']);
        self::assertSame('guid-1', $made['row']['external_id']);
        self::assertSame('{}', $made['row']['provider_data']);
    }

    public function test_4_a_youtube_video_keeps_the_youtube_id(): void
    {
        $made = Row::convert('Video', [
            'id' => 'v2',
            'source' => 'YOUTUBE',
            'bunnyVideoId' => null,
            'externalId' => 'dQw4w9WgXcQ',
        ], self::schema()['videos']);
        self::assertSame('youtube', $made['row']['provider']);
        self::assertSame('dQw4w9WgXcQ', $made['row']['external_id']);
    }

    public function test_5_a_bunny_video_that_was_imported_keeps_where_it_came_from(): void
    {
        $made = Row::convert('Video', [
            'id' => 'v3',
            'source' => 'BUNNY',
            'bunnyVideoId' => 'guid-3',
            'externalId' => 'yt-3',
        ], self::schema()['videos']);
        self::assertSame('guid-3', $made['row']['external_id']);
        self::assertSame(['importedFrom' => 'yt-3'], json_decode((string) $made['row']['provider_data'], true));
    }

    public function test_6_a_file_with_a_bunny_path_is_a_bunny_file_and_one_without_is_not(): void
    {
        $columns = self::schema()['file_assets'];
        $inBunny = Row::convert('FileAsset', ['id' => 'f1', 'bunnyPath' => 'books/one.pdf'], $columns);
        self::assertSame('bunny', $inBunny['row']['backend']);
        self::assertSame('books/one.pdf', $inBunny['row']['storage_path']);

        $linked = Row::convert('FileAsset', ['id' => 'f2', 'bunnyPath' => null, 'url' => 'https://example.org/a.pdf'], $columns);
        self::assertSame('local', $linked['row']['backend']);
        self::assertSame('', $linked['row']['storage_path']);
    }

    public function test_7_booleans_become_one_and_zero(): void
    {
        $made = Row::convert('Series', ['id' => 's1', 'title' => 'A', 'published' => true, 'hidden' => false], self::schema()['series']);
        self::assertSame(1, $made['row']['published']);
        self::assertSame(0, $made['row']['hidden']);
    }

    public function test_8_an_array_becomes_json_and_rows_in_the_second_table(): void
    {
        $made = Row::convert('Series', ['id' => 's2', 'tags' => ['Grace', 'grace', ' Hope ', '']], self::schema()['series']);
        self::assertSame(['Grace', 'grace', ' Hope ', ''], json_decode((string) $made['row']['tags'], true), 'the column keeps the array as it was');
        self::assertSame(['grace', 'hope'], $made['fanout']['series_tags'], 'the index is lower-cased and deduplicated');
    }

    public function test_9_scripture_references_keep_their_case(): void
    {
        $made = Row::convert('Video', ['id' => 'v4', 'scriptureRefs' => ['John 3:16', 'Romans 8']], self::schema()['videos']);
        self::assertSame(['John 3:16', 'Romans 8'], $made['fanout']['video_scripture_books']);
    }

    public function test_10_a_timestamp_is_converted_only_for_a_timestamp_column(): void
    {
        $columns = ['created_at' => 'datetime', 'title' => 'varchar'];
        $made = Row::convert('Speaker', [
            'createdAt' => '2026-03-01 09:30:00.123+00',
            'title' => '2026-03-01T09:30:00.000Z',
        ], $columns);
        self::assertSame('2026-03-01 09:30:00.123', $made['row']['created_at']);
        self::assertSame('2026-03-01T09:30:00.000Z', $made['row']['title'], 'a title that reads like a date is a title');
    }

    public function test_11_a_time_zone_is_brought_back_to_utc(): void
    {
        self::assertSame('2026-03-01 04:00:00.000', Row::timestamp('2026-03-01 09:30:00+05:30'));
        self::assertSame('2026-03-01 09:30:00.000', Row::timestamp('2026-03-01 09:30:00'), 'no zone means the UTC the old site stored');
        self::assertSame('not a date', Row::timestamp('not a date'));
    }

    public function test_12_a_column_this_schema_has_not_is_reported_rather_than_swallowed(): void
    {
        $made = Row::convert('Speaker', ['id' => 's1', 'name' => 'A', 'favouriteBiscuit' => 'rich tea'], ['id' => 'varchar', 'name' => 'varchar']);
        self::assertSame(['favouriteBiscuit'], $made['dropped']);
        self::assertArrayNotHasKey('favourite_biscuit', $made['row']);
    }

    public function test_13_every_model_in_the_export_has_a_table_that_exists(): void
    {
        $schema = self::schema();
        foreach (Mapping::TABLES as $model => $table) {
            self::assertArrayHasKey($table, $schema, "$model maps to $table, which the migration does not create");
        }
        self::assertCount(95, Mapping::TABLES, 'the data model has 95 models');
    }

    public function test_14_every_rename_names_a_column_that_exists(): void
    {
        $schema = self::schema();
        foreach (Mapping::COLUMNS as $model => $renames) {
            $table = (string) Mapping::tableFor($model);
            foreach ($renames as $from => $to) {
                self::assertArrayHasKey($to, $schema[$table], "$model.$from is mapped to $table.$to, which does not exist");
            }
        }
        foreach (Mapping::FANOUT as $model => $fields) {
            foreach ($fields as [$table, $parent, $value]) {
                self::assertArrayHasKey($parent, $schema[$table] ?? [], "$model fans out into $table.$parent, which does not exist");
                self::assertArrayHasKey($value, $schema[$table] ?? [], "$model fans out into $table.$value, which does not exist");
            }
        }
    }

    public function test_15_the_two_models_that_are_never_imported_are_credentials(): void
    {
        self::assertSame(['PushSubscription', 'TvDevice'], array_keys(Mapping::SKIPPED));
        foreach (Mapping::SKIPPED as $model => $why) {
            self::assertArrayHasKey($model, Mapping::TABLES);
            self::assertNotSame('', $why, 'the screen has a sentence to show for each');
        }
    }
}
