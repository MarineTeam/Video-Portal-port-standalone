<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * The reader's server half, through a real server.
 *
 * The browser does the reading — pdf.js and epub.js run there, and there is
 * no PDF library here — so what is tested here is what the browser sends
 * back and what the reader is handed: a contents list that a re-run cannot
 * wipe, a search that answers with hymns rather than page numbers, marks
 * that are private to whoever made them, and a saved copy that the same
 * access rules stand in front of.
 */
final class BookReaderTest extends ServerTestCase
{
    /** @var array<string, string> */
    private static array $ids = [];

    protected static function prefix(): string
    {
        return 'br_';
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::install();
        self::$ids['ruth'] = self::member('ruth@test.example', 'ruth');
        self::$ids['sam'] = self::member('sam@test.example', 'sam');
        $db = self::connect(self::prefix());

        self::$ids['series'] = \App\Core\Id::new();
        $db->insert('series', ['id' => self::$ids['series'], 'title' => 'Hymnals', 'slug' => 'hymnals', 'published' => 1, 'tags' => '[]']);
        self::$ids['book'] = \App\Core\Id::new();
        $db->insert('file_assets', [
            'id' => self::$ids['book'], 'title' => 'Hymns Ancient and Modern', 'backend' => 'local',
            'storage_path' => 'files/hymnal.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 2181,
            'published' => 1, 'page_offset' => 10, 'series_id' => self::$ids['series'],
        ]);

        // A members-only hymnal, to prove the gate is the file's own.
        self::$ids['closed'] = \App\Core\Id::new();
        $db->insert('file_assets', [
            'id' => self::$ids['closed'], 'title' => 'The members’ book', 'backend' => 'local',
            'storage_path' => 'files/closed.pdf', 'mime_type' => 'application/pdf',
            'published' => 1, 'member_only' => 1,
        ]);
        self::flushCache();
    }

    public function test_1_contents_come_from_the_browser_and_are_read_back_as_printed(): void
    {
        $saved = self::http('PUT', '/api/admin/files/' . self::$ids['book'] . '/contents', (string) json_encode([
            'entries' => [
                ['title' => 'Advent', 'page' => 11, 'depth' => 0],
                ['title' => '1 O Come, O Come Emmanuel', 'page' => 11, 'depth' => 1],
                ['title' => '2 Hark the Herald', 'page' => 14, 'depth' => 1],
            ],
        ]), 'admin', ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-CSRF-Token' => self::token()]);
        self::assertSame(200, $saved['status'], (string) $saved['body']);
        self::assertSame(3, $saved['json']['saved']);

        // Stored as PDF pages, shown as the book prints them.
        $read = self::http('GET', '/api/admin/files/' . self::$ids['book'] . '/contents');
        self::assertSame([11, 11, 14], array_column($read['json']['entries'], 'page'));
        self::assertSame([1, 1, 4], array_column($read['json']['entries'], 'printedPage'));
        self::assertSame([null, 1, 2], array_column($read['json']['entries'], 'number'), 'the number is parsed off the label');
        // And the box a typist edits round-trips.
        self::assertStringContainsString("  1 O Come, O Come Emmanuel\t1", (string) $read['json']['text']);
    }

    private static function token(): string
    {
        preg_match('/name="csrf-token" content="([^"]+)"/', self::http('GET', '/')['body'], $m);
        return $m[1] ?? '';
    }

    public function test_2_an_empty_list_cannot_wipe_a_typed_one(): void
    {
        // The outline pass runs on every cover generation; a PDF with no
        // bookmarks must not empty a contents list somebody typed.
        $refused = self::http('PUT', '/api/admin/files/' . self::$ids['book'] . '/contents', (string) json_encode(['entries' => []]), 'admin', [
            'Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-CSRF-Token' => self::token(),
        ]);
        self::assertSame(400, $refused['status']);
        self::assertSame(3, (int) self::connect(self::prefix())->value('SELECT COUNT(*) FROM {{book_hymns}} WHERE file_id = ?', [self::$ids['book']]));
    }

    public function test_3_the_typed_box_is_parsed_the_way_the_typist_wrote_it(): void
    {
        $saved = self::http('PUT', '/api/admin/files/' . self::$ids['book'] . '/contents', (string) json_encode([
            'pageOffset' => 10,
            'text' => "Advent\t1\n  1 O Come, O Come Emmanuel\t1\n  2 Hark the Herald\t4\nnonsense line\n  3 Once in Royal\t6",
        ]), 'admin', ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-CSRF-Token' => self::token()]);
        self::assertSame(200, $saved['status'], (string) $saved['body']);
        self::assertSame(4, $saved['json']['saved']);
        self::assertCount(1, $saved['json']['problems'], 'and the line it could not read is reported');
        self::assertSame(4, $saved['json']['problems'][0]['line']);
        self::assertSame([11, 11, 14, 16], array_column($saved['json']['entries'], 'page'));
        self::assertSame([0, 1, 1, 1], array_column($saved['json']['entries'], 'depth'), 'indentation is nesting');
    }

    public function test_4_the_reader_is_handed_what_it_needs_to_open_the_book(): void
    {
        $book = self::http('GET', '/api/books/' . self::$ids['book'], null, 'ruth');
        self::assertSame(200, $book['status']);
        self::assertSame('PDF', $book['json']['format']);
        self::assertSame(10, $book['json']['pageOffset']);
        self::assertTrue($book['json']['fetchWhole'], 'a small book comes in one cacheable request');
        self::assertFalse($book['json']['searchable'], 'nobody has read its text yet');
        self::assertCount(4, $book['json']['contents']);
        self::assertNull($book['json']['progress']);

        self::assertContains(self::http('GET', '/api/books/' . self::$ids['closed'], null, 'guest')['status'], [401, 403, 404], 'and a members-only book is not opened for a stranger');
    }

    public function test_5_a_page_of_text_at_a_time_and_only_a_finished_run_says_searchable(): void
    {
        $put = fn (array $body) => self::http('PUT', '/api/admin/files/' . self::$ids['book'] . '/pages', (string) json_encode($body), 'admin', [
            'Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-CSRF-Token' => self::token(),
        ]);
        $first = $put(['pages' => [
            ['page' => 11, 'text' => 'O come, O come, Emmanuel, and ransom captive Israel', 'source' => 'TEXT'],
            ['page' => 12, 'text' => 'That mourns in lonely exile here', 'source' => 'OCR'],
        ]]);
        self::assertSame(200, $first['status'], (string) $first['body']);
        self::assertSame(2, $first['json']['saved']);
        self::assertFalse(self::http('GET', '/api/books/' . self::$ids['book'], null, 'ruth')['json']['searchable'], 'half a book is not searchable');

        $put(['pages' => [['page' => 14, 'text' => 'Hark the herald angels sing, glory to the newborn King', 'source' => 'TEXT']], 'finished' => true]);
        self::assertTrue(self::http('GET', '/api/books/' . self::$ids['book'], null, 'ruth')['json']['searchable']);

        // Re-reading a page replaces it rather than making a second row.
        $again = $put(['pages' => [['page' => 11, 'text' => 'O come, O come, Emmanuel — corrected', 'source' => 'OCR']]]);
        self::assertSame(200, $again['status']);
        self::assertSame(3, $again['json']['pages']);
    }

    public function test_6_searching_answers_with_hymns_rather_than_page_numbers(): void
    {
        $found = self::http('GET', '/api/files/' . self::$ids['book'] . '/search?q=herald', null, 'ruth');
        self::assertTrue($found['json']['indexed']);
        self::assertSame(4, $found['json']['hits'][0]['printedPage'], 'the page as the book prints it');
        self::assertSame('2 Hark the Herald', $found['json']['hits'][0]['inside'], 'and the hymn it falls inside');
        self::assertStringContainsString('herald', mb_strtolower((string) $found['json']['hits'][0]['excerpt']));

        // Across the shelf, by title and by number.
        $shelf = self::http('GET', '/api/hymnals/search?q=Hark', null, 'ruth');
        self::assertSame(200, $shelf['status'], (string) $shelf['body']);
        self::assertSame('2 Hark the Herald', $shelf['json']['hymns'][0]['title']);
        self::assertSame(4, $shelf['json']['hymns'][0]['printedPage']);
        self::assertStringContainsString('/read/' . self::$ids['book'] . '?page=14', (string) $shelf['json']['hymns'][0]['href']);

        $byNumber = self::api('POST', '/api/hymns/lookup', ['number' => 1], 'ruth');
        self::assertSame(200, $byNumber['status'], (string) $byNumber['body']);
        self::assertSame('1 O Come, O Come Emmanuel', $byNumber['json']['hymns'][0]['title']);

        // The words inside a scan, for a search the contents cannot answer.
        $inside = self::http('GET', '/api/hymnals/search?q=lonely+exile', null, 'ruth');
        self::assertNotSame([], $inside['json']['inTheText']);
        self::assertSame('1 O Come, O Come Emmanuel', $inside['json']['inTheText'][0]['title'], 'the hymn the page falls inside, not a page number');
    }

    public function test_7_marks_are_private_to_whoever_made_them(): void
    {
        $made = self::api('POST', '/api/reading/marks', [
            'fileId' => self::$ids['book'],
            'kind' => 'HIGHLIGHT', 'location' => '11', 'excerpt' => 'and ransom captive Israel', 'note' => 'for Advent',
        ], 'ruth');
        self::assertSame(201, $made['status'], (string) $made['body']);
        self::$ids['mark'] = (string) $made['json']['id'];
        self::assertSame('HIGHLIGHT', $made['json']['marks'][0]['kind']);

        // A highlight with nothing selected is honestly a bookmark: in an
        // EPUB the selection lives in a frame the reader cannot read.
        $bookmark = self::api('POST', '/api/reading/marks', ['fileId' => self::$ids['book'], 'kind' => 'HIGHLIGHT', 'location' => 'epubcfi(/6/4!/2)'], 'ruth');
        self::assertSame('BOOKMARK', $bookmark['json']['marks'][1]['kind']);

        self::assertSame([], self::http('GET', '/api/reading/marks?fileId=' . self::$ids['book'], null, 'sam')['json']['marks']);
        self::assertSame(404, self::api('DELETE', '/api/reading/marks/' . self::$ids['mark'], null, 'sam')['status']);
        self::assertSame(200, self::api('DELETE', '/api/reading/marks/' . self::$ids['mark'], null, 'ruth')['status']);
    }

    public function test_8_a_place_is_kept_and_reopened_at(): void
    {
        self::assertSame(200, self::api('POST', '/api/reading/progress', ['fileId' => self::$ids['book'], 'location' => '14', 'percent' => 250], 'ruth')['status']);
        $book = self::http('GET', '/api/books/' . self::$ids['book'], null, 'ruth');
        self::assertSame('14', $book['json']['progress']['location']);
        self::assertSame(100, $book['json']['progress']['percent'], 'clamped rather than stored as it came');

        // Saved once per member, however many times it is sent.
        self::api('POST', '/api/reading/progress', ['fileId' => self::$ids['book'], 'location' => '16', 'percent' => 60], 'ruth');
        self::assertSame(1, (int) self::connect(self::prefix())->value('SELECT COUNT(*) FROM {{reading_progresses}} WHERE user_id = ?', [self::$ids['ruth']]));
        self::assertSame([], self::http('GET', '/api/books/' . self::$ids['book'], null, 'sam')['json']['progress'] ?? [], 'and it is this member’s place, not the book’s');
    }

    public function test_9_a_hymnal_kept_on_the_device_is_the_same_book_it_shows(): void
    {
        $db = self::connect(self::prefix());
        $series = \App\Core\Id::new();
        $db->insert('series', ['id' => $series, 'title' => 'Sunday hymns', 'slug' => 'sunday-hymns', 'published' => 1, 'hymn_per_file' => 1, 'tags' => '[]']);
        foreach ([[2, 'Be Thou My Vision', 'Be thou my vision'], [1, 'Amazing Grace', 'Amazing grace, how sweet the sound']] as [$number, $title, $words]) {
            $db->insert('file_assets', [
                'id' => \App\Core\Id::new(), 'title' => $title, 'backend' => 'local', 'storage_path' => 'files/x.mp3',
                'mime_type' => 'audio/mpeg', 'published' => 1, 'series_id' => $series,
                'page_number' => $number, 'lyrics_text' => $words,
            ]);
        }
        self::flushCache();

        $saved = self::http('GET', "/api/offline/hymnal/$series", null, 'guest');
        self::assertSame(200, $saved['status'], (string) $saved['body']);
        self::assertSame(['Amazing Grace', 'Be Thou My Vision'], array_column($saved['json']['hymns'], 'title'), 'in printed order');
        self::assertSame('Amazing grace, how sweet the sound', $saved['json']['hymns'][0]['lyrics']);

        // The probe answers "is what I saved still what you would send"
        // without sending it again.
        $probe = self::http('GET', "/api/offline/hymnal/$series?probe=1", null, 'guest');
        self::assertSame($saved['json']['fingerprint'], $probe['json']['fingerprint']);
        self::assertArrayNotHasKey('hymns', array_flip(array_keys((array) $probe['json'])) === [] ? [] : (array) $probe['json']['hymns'] ?? []);
        self::assertStringNotContainsString('Amazing grace, how sweet', (string) $probe['body']);

        // Correcting the words changes the token, so a device knows to re-fetch.
        $db->run('UPDATE {{file_assets}} SET lyrics_text = ? WHERE series_id = ? AND page_number = 1', ['Amazing grace! how sweet the sound', $series]);
        self::flushCache();
        self::assertNotSame($saved['json']['fingerprint'], self::http('GET', "/api/offline/hymnal/$series?probe=1", null, 'guest')['json']['fingerprint']);
    }
}
