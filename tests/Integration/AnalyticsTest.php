<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Db;
use App\Core\Id;

/**
 * /admin/analytics through a real server.
 *
 * What is being proved: the window is honoured and nothing outside it is
 * counted; the top lists are ordered by what happened in that window; a
 * video no player reported on has no watch-through rate rather than a
 * misleading 0%; a hymn is named by the book's own contents; the export
 * carries the same figures and quotes a title a spreadsheet would run; and
 * nobody without the capability sees any of it.
 */
final class AnalyticsTest extends ServerTestCase
{
    /** @var array<string, string> */
    private static array $ids = [];

    protected static function prefix(): string
    {
        return 'an_';
    }

    private static function ago(int $days): string
    {
        return gmdate('Y-m-d H:i:s', time() - $days * 86400);
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::install();
        $db = self::connect(self::prefix());
        self::$ids['ruth'] = self::member('ruth@test.example', 'ruth');

        foreach (['advent' => 'Advent', 'lent' => 'Lent'] as $slug => $title) {
            self::$ids[$slug] = Id::new();
            $db->insert('series', ['id' => self::$ids[$slug], 'title' => $title, 'slug' => $slug, 'published' => 1, 'tags' => '[]']);
        }
        foreach (['popular' => 'The popular one', 'quiet' => 'The quiet one'] as $slug => $title) {
            self::$ids[$slug] = Id::new();
            $db->insert('videos', [
                'id' => self::$ids[$slug], 'title' => $title, 'slug' => $slug, 'provider' => 'direct',
                'series_id' => self::$ids['advent'], 'published' => 1, 'status' => 'READY', 'scripture_refs' => '[]',
            ]);
        }

        // Inside the week: six for the popular video, two for the quiet one,
        // and one for Lent so the order is not the insertion order.
        self::views($db, self::$ids['advent'], self::$ids['popular'], 6, 2);
        self::views($db, self::$ids['advent'], self::$ids['quiet'], 2, 3);
        self::views($db, self::$ids['lent'], null, 1, 1);
        // Outside it: a landslide that must not reach the seven-day window.
        self::views($db, self::$ids['lent'], null, 40, 60);

        // Two of three players finished the popular one; nobody's player
        // said anything about the quiet one.
        foreach ([[1, 2], [1, 5], [0, 6]] as $i => [$done, $days]) {
            $db->insert('watch_progresses', [
                'id' => Id::new(), 'user_id' => self::member("w$i@test.example", "w$i"),
                'video_id' => self::$ids['popular'], 'position_seconds' => 10, 'completed' => $done,
                'updated_at' => self::ago($days),
            ]);
        }

        // A scanned book with a contents entry, and a hymn of its own.
        self::$ids['book'] = Id::new();
        $db->insert('file_assets', [
            'id' => self::$ids['book'], 'title' => 'The church hymnal', 'backend' => 'local',
            'storage_path' => 'files/hymnal.pdf', 'published' => 1, 'mime_type' => 'application/pdf',
        ]);
        $db->insert('book_hymns', ['id' => Id::new(), 'file_id' => self::$ids['book'], 'title' => 'O Come, O Come Emmanuel', 'number' => 1, 'page' => 11, 'depth' => 0, 'position' => 0]);
        self::$ids['single'] = Id::new();
        $db->insert('file_assets', [
            'id' => self::$ids['single'], 'title' => 'Be Thou My Vision', 'backend' => 'local',
            'storage_path' => 'files/vision.pdf', 'published' => 1, 'page_number' => 4,
        ]);
        foreach ([[self::$ids['book'], 1, 'book', 3, 2], [self::$ids['single'], null, 'hymn', 1, 2], [self::$ids['book'], 1, 'present', 0, 40]] as [$file, $number, $source, $inside, $days]) {
            for ($i = 0; $i < $inside; $i++) {
                $db->insert('hymn_lookups', ['id' => Id::new(), 'file_id' => $file, 'number' => $number, 'source' => $source, 'created_at' => self::ago($days)]);
            }
        }
        // One long ago, which the week must not count.
        $db->insert('hymn_lookups', ['id' => Id::new(), 'file_id' => self::$ids['book'], 'number' => 1, 'source' => 'present', 'created_at' => self::ago(40)]);
        self::flushCache();
    }

    private static function views(Db $db, ?string $seriesId, ?string $videoId, int $many, int $daysAgo): void
    {
        for ($i = 0; $i < $many; $i++) {
            $db->insert('view_events', [
                'id' => Id::new(), 'series_id' => $seriesId, 'video_id' => $videoId,
                'ip_hash' => 'hash' . $i, 'created_at' => self::ago($daysAgo),
            ]);
        }
    }

    public function test_1_the_week_counts_the_week_and_nothing_older(): void
    {
        $page = self::http('GET', '/admin/analytics?days=7');
        self::assertSame(200, $page['status']);
        // 6 + 2 + 1 inside the week; the 40 from six weeks ago are not here.
        self::assertStringContainsString('>9<', str_replace([' ', "\n"], '', $page['body']), 'nine openings in the week');
        self::assertStringNotContainsString('>49<', str_replace([' ', "\n"], '', $page['body']));
    }

    public function test_2_the_quarter_counts_what_the_week_left_out(): void
    {
        $report = self::api('GET', '/api/admin/analytics/export?days=90&format=json')['json'];
        self::assertSame(90, $report['days']);
        self::assertSame(49, $report['views']);
        self::assertSame('Lent', $report['series'][0]['title'], 'the landslide outside the week leads the quarter');
    }

    public function test_3_the_top_lists_are_ordered_by_the_window(): void
    {
        $report = self::api('GET', '/api/admin/analytics/export?days=7&format=json')['json'];
        self::assertSame(['Advent', 'Lent'], array_column($report['series'], 'title'));
        self::assertSame([8, 1], array_column($report['series'], 'views'), 'a series takes its videos’ views too');
        self::assertSame(['The popular one', 'The quiet one'], array_column($report['videos'], 'title'));
    }

    public function test_4_a_video_no_player_reported_on_has_no_rate_rather_than_a_zero(): void
    {
        $videos = [];
        foreach (self::api('GET', '/api/admin/analytics/export?days=7&format=json')['json']['videos'] as $row) {
            $videos[$row['title']] = $row['watchedThrough'];
        }
        self::assertSame(67, $videos['The popular one'], 'two of the three players that reported reached the end');
        self::assertNull($videos['The quiet one'], '“nobody finished it” and “nobody told us” are different answers');
    }

    public function test_5_a_hymn_is_named_by_the_book_rather_than_by_its_number(): void
    {
        $hymns = self::api('GET', '/api/admin/analytics/export?days=7&format=json')['json']['hymns'];
        self::assertSame('O Come, O Come Emmanuel', $hymns[0]['title'], 'the contents entry, not “The church hymnal”');
        self::assertSame(3, $hymns[0]['lookups'], 'and not the one from six weeks ago');
        self::assertSame('Be Thou My Vision', $hymns[1]['title'], 'a hymn that is its own file is named by the file');
        self::assertNull($hymns[1]['number']);
    }

    public function test_6_the_csv_carries_the_same_figures_and_defuses_a_formula(): void
    {
        $db = self::connect(self::prefix());
        $db->update('series', ['title' => '=cmd|calc'], ['id' => self::$ids['lent']]);
        $csv = self::http('GET', '/api/admin/analytics/export?days=7');
        self::assertSame(200, $csv['status']);
        self::assertStringContainsString('text/csv', (string) $csv['headers']['content-type']);
        self::assertStringContainsString('attachment; filename="analytics-7-days-', (string) $csv['headers']['content-disposition']);
        self::assertStringContainsString('The popular one', $csv['body']);
        self::assertStringContainsString('67%', $csv['body']);
        self::assertStringContainsString("'=cmd|calc", $csv['body'], 'a title a spreadsheet would run is quoted');
        $db->update('series', ['title' => 'Lent'], ['id' => self::$ids['lent']]);
    }

    public function test_7_a_bad_window_is_a_month_rather_than_an_error(): void
    {
        self::assertSame(30, self::api('GET', '/api/admin/analytics/export?days=365&format=json')['json']['days']);
        self::assertSame(30, self::api('GET', '/api/admin/analytics/export?days=7;DROP&format=json')['json']['days']);
    }

    public function test_8_it_needs_the_capability(): void
    {
        self::assertContains(self::http('GET', '/admin/analytics', null, 'ruth')['status'], [302, 303, 403]);
        self::assertSame(403, self::api('GET', '/api/admin/analytics/export', null, 'ruth')['status']);
        self::assertContains(self::http('GET', '/admin/analytics', null, 'guest')['status'], [302, 303, 401, 403]);
    }

    public function test_9_opening_a_hymn_counts_it_and_the_builder_asking_does_not(): void
    {
        $db = self::connect(self::prefix());
        $before = (int) $db->value('SELECT COUNT(*) FROM {{hymn_lookups}}');

        // The running-order builder, typing: a question, not an opening.
        self::assertSame(200, self::api('POST', '/api/hymns/lookup', ['number' => 1, 'fileId' => self::$ids['book']], 'ruth')['status']);
        self::assertSame($before, (int) $db->value('SELECT COUNT(*) FROM {{hymn_lookups}}'), 'no source, no count');

        // A page saying where it was opened from.
        self::assertSame(200, self::api('POST', '/api/hymns/lookup', ['number' => 1, 'fileId' => self::$ids['book'], 'source' => 'present'], 'ruth')['status']);
        self::assertSame($before + 1, (int) $db->value('SELECT COUNT(*) FROM {{hymn_lookups}}'));
        self::assertSame('present', $db->value('SELECT source FROM {{hymn_lookups}} ORDER BY created_at DESC, id DESC LIMIT 1'));

        // A source nobody defined is not a source.
        self::api('POST', '/api/hymns/lookup', ['number' => 1, 'fileId' => self::$ids['book'], 'source' => 'invented'], 'ruth');
        self::assertSame($before + 1, (int) $db->value('SELECT COUNT(*) FROM {{hymn_lookups}}'));
    }
}
