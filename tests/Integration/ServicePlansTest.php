<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Id;

/**
 * Service plans through a real server: a running order whose rows resolve
 * against the library as it stands now, the presenter that only opens on
 * words somebody has typed, the rota's asks, answers and cover, blockouts,
 * and "what we sang" in the shape a licence return asks for.
 */
final class ServicePlansTest extends ServerTestCase
{
    /** @var array<string, string> */
    private static array $ids = [];

    protected static function prefix(): string
    {
        return 'sp_';
    }

    private static function day(string $offset): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify($offset)->format('Y-m-d');
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::install();
        $db = self::connect(self::prefix());
        self::$ids['ruth'] = self::member('ruth@test.example', 'ruth');
        self::$ids['boaz'] = self::member('boaz@test.example', 'boaz');
        $db->update('users', ['name' => 'Ruth'], ['id' => self::$ids['ruth']]);
        $db->update('users', ['name' => 'Boaz'], ['id' => self::$ids['boaz']]);

        // A hymn-per-file series with one hymn, and a whole-book hymnal.
        self::$ids['series'] = Id::new();
        $db->insert('series', ['id' => self::$ids['series'], 'title' => 'Praise!', 'slug' => 'praise', 'published' => 1, 'tags' => [], 'hymn_per_file' => 1]);
        self::$ids['hymn'] = Id::new();
        $db->insert('file_assets', [
            'id' => self::$ids['hymn'], 'title' => 'Be Thou My Vision', 'storage_path' => 'praise/473.pdf', 'mime_type' => 'application/pdf',
            'series_id' => self::$ids['series'], 'page_number' => 473, 'lyrics_text' => "Be thou my vision\n\nO Lord of my heart",
            'ccli_number' => '30639', 'song_author' => 'Eleanor Hull', 'song_copyright' => 'Public domain', 'published' => 1,
        ]);
        self::$ids['silent'] = Id::new();
        $db->insert('file_assets', [
            'id' => self::$ids['silent'], 'title' => 'A hymn nobody typed', 'storage_path' => 'praise/500.pdf', 'mime_type' => 'application/pdf',
            'series_id' => self::$ids['series'], 'page_number' => 500, 'ccli_number' => '1', 'published' => 1,
        ]);
        self::$ids['book'] = Id::new();
        $db->insert('file_assets', [
            'id' => self::$ids['book'], 'title' => 'The Church Hymnal', 'storage_path' => 'books/hymnal.pdf', 'mime_type' => 'application/pdf', 'published' => 1,
        ]);
        $db->insert('book_hymns', ['id' => Id::new(), 'file_id' => self::$ids['book'], 'title' => 'Amazing Grace', 'number' => 12, 'page' => 30, 'position' => 1]);
        $db->insert('book_hymn_details', ['id' => Id::new(), 'file_id' => self::$ids['book'], 'number' => 12, 'lyrics_text' => "Amazing grace\n\nHow sweet the sound", 'ccli_number' => '22025', 'author' => 'John Newton', 'copyright' => 'Public domain']);
        $db->insert('book_hymns', ['id' => Id::new(), 'file_id' => self::$ids['book'], 'title' => 'A scan nobody typed', 'number' => 99, 'page' => 120, 'position' => 2]);
        self::flushCache();
    }

    public function test_1_a_plan_is_an_order_of_rows_that_resolve_now(): void
    {
        $created = self::api('POST', '/api/admin/services', ['title' => 'Sunday morning', 'serviceDate' => self::day('+7 days')]);
        self::assertSame(201, $created['status'], (string) $created['body']);
        self::$ids['plan'] = $created['json']['id'];

        $saved = self::api('PATCH', '/api/admin/services/' . self::$ids['plan'], ['items' => [
            ['fileId' => self::$ids['hymn'], 'note' => 'verses 1 and 4 only'],
            ['fileId' => self::$ids['book'], 'hymnNumber' => 12],
            ['fileId' => self::$ids['book'], 'hymnNumber' => 99],
            ['fileId' => self::$ids['silent']],
        ]]);
        self::assertSame(200, $saved['status'], (string) $saved['body']);
        $items = (array) $saved['json']['items'];
        self::assertSame(['/hymns/' . self::$ids['hymn'], '/books/' . self::$ids['book'] . '?hymn=12', '/books/' . self::$ids['book'] . '?hymn=99', '/hymns/' . self::$ids['silent']], array_column($items, 'href'));
        self::assertSame([473, 12, 99, 500], array_column($items, 'number'), 'the number written for the service, else the hymn\'s own');
        self::assertSame(
            ['/present/' . self::$ids['hymn'] . '?plan=' . self::$ids['plan'], '/present/' . self::$ids['book'] . '?hymn=12&plan=' . self::$ids['plan'], null, null],
            array_column($items, 'presentHref'),
            'only what somebody has typed the words of can go on a screen',
        );
    }

    public function test_2_a_draft_is_nobody_elses_business_and_a_plan_outlives_its_library(): void
    {
        $id = self::$ids['plan'];
        self::assertSame(404, self::http('GET', "/services/$id", null, 'guest')['status']);
        self::assertStringContainsString('No running orders', self::http('GET', '/services', null, 'guest')['body']);
        self::assertSame(200, self::api('PATCH', "/api/admin/services/$id", ['published' => true])['status']);
        $page = self::http('GET', "/services/$id", null, 'guest');
        self::assertStringContainsString('Be Thou My Vision', $page['body']);
        self::assertStringContainsString('verses 1 and 4 only', $page['body']);

        // A hymn unpublished since the plan was made is listed and not linked.
        $db = self::connect(self::prefix());
        $db->update('file_assets', ['published' => 0], ['id' => self::$ids['hymn']]);
        self::flushCache();
        $after = self::http('GET', "/services/$id", null, 'guest');
        self::assertStringContainsString('Be Thou My Vision', $after['body']);
        self::assertStringNotContainsString('/hymns/' . self::$ids['hymn'], $after['body']);
        self::assertStringContainsString('Not available', $after['body']);
        $db->update('file_assets', ['published' => 1], ['id' => self::$ids['hymn']]);
        self::flushCache();
    }

    public function test_3_the_hymn_and_book_pages_and_the_presenter(): void
    {
        $hymn = self::http('GET', '/hymns/' . self::$ids['hymn'], null, 'guest');
        self::assertSame(200, $hymn['status']);
        self::assertStringContainsString('Be thou my vision', $hymn['body']);
        self::assertStringContainsString('CCLI 30639', $hymn['body']);

        $book = self::http('GET', '/books/' . self::$ids['book'] . '?hymn=12', null, 'guest');
        self::assertStringContainsString('Amazing Grace', $book['body']);
        self::assertStringContainsString('How sweet the sound', $book['body'], 'the typed-out words');
        self::assertStringContainsString('page 30', $book['body']);
        self::assertStringContainsString('There is no number 13', self::http('GET', '/books/' . self::$ids['book'] . '?hymn=13', null, 'guest')['body']);

        $present = self::http('GET', '/present/' . self::$ids['book'] . '?hymn=12&plan=' . self::$ids['plan'], null, 'guest');
        self::assertSame(200, $present['status']);
        self::assertStringContainsString('Amazing grace', $present['body']);
        self::assertStringContainsString('Public domain', $present['body'], 'the copyright line stays up with the words');
        self::assertStringContainsString('Sunday morning', $present['body'], 'and it knows which service it belongs to');
        self::assertSame(404, self::http('GET', '/present/' . self::$ids['book'] . '?hymn=99', null, 'guest')['status'], 'nothing to put on a screen');
        self::assertSame(404, self::http('GET', '/present/' . self::$ids['silent'], null, 'guest')['status']);
    }

    public function test_4_a_members_only_hymn_asks_a_visitor_to_sign_in(): void
    {
        $db = self::connect(self::prefix());
        $db->update('file_assets', ['member_only' => 1], ['id' => self::$ids['hymn']]);
        self::flushCache();
        self::assertSame(401, self::http('GET', '/hymns/' . self::$ids['hymn'], null, 'guest')['status']);
        self::assertSame(200, self::http('GET', '/hymns/' . self::$ids['hymn'], null, 'ruth')['status']);
        $page = self::http('GET', '/services/' . self::$ids['plan'], null, 'guest');
        self::assertStringNotContainsString('/hymns/' . self::$ids['hymn'], $page['body'], 'and the row is not a link for them');
        $db->update('file_assets', ['member_only' => 0], ['id' => self::$ids['hymn']]);
        self::flushCache();
    }

    public function test_5_the_rota_asks_answers_and_cover(): void
    {
        $team = self::api('POST', '/api/admin/teams', ['name' => 'Tech team', 'position' => 1]);
        self::assertSame(201, $team['status']);
        self::$ids['team'] = $team['json']['id'];
        foreach (['ruth@test.example', 'boaz@test.example'] as $email) {
            self::assertSame(201, self::api('PATCH', '/api/admin/teams/' . self::$ids['team'], ['name' => 'Tech team', 'addEmail' => $email, 'addPosition' => 'Sound desk'])['status']);
        }
        self::assertSame(400, self::api('PATCH', '/api/admin/teams/' . self::$ids['team'], ['name' => 'Tech team', 'addEmail' => 'nobody@test.example'])['status']);

        $plan = self::$ids['plan'];
        self::assertSame(200, self::api('PATCH', "/api/admin/services/$plan", ['assignments' => [
            ['userId' => self::$ids['ruth'], 'teamId' => self::$ids['team'], 'position' => 'Sound desk'],
        ]])['status']);
        self::assertContains('You are on the rota', array_column((array) self::http('GET', '/api/inbox', null, 'ruth')['json']['notifications'], 'title'));

        $mine = self::http('GET', '/profile/rota', null, 'ruth');
        self::assertStringContainsString('Sound desk', $mine['body']);
        self::assertStringContainsString('Sunday morning', $mine['body']);
        $rota = (array) self::api('GET', "/api/admin/services/$plan/rota")['json'];
        $assignment = (string) $rota[0]['id'];
        self::assertSame('INVITED', $rota[0]['status']);

        // The answer is the member's own; nobody else's.
        self::assertSame(403, self::api('POST', '/api/rota', ['assignmentId' => $assignment, 'status' => 'ACCEPTED'], 'boaz')['status']);
        self::assertSame(200, self::api('POST', '/api/rota', ['assignmentId' => $assignment, 'status' => 'ACCEPTED'], 'ruth')['status']);
        self::assertSame('ACCEPTED', ((array) self::api('GET', "/api/admin/services/$plan/rota")['json'])[0]['status']);

        // Asking for cover, and somebody on the same team taking the slot.
        self::assertSame(400, self::api('POST', '/api/rota', ['assignmentId' => $assignment, 'takeCover' => true], 'boaz')['status'], 'nobody asked');
        self::assertSame(200, self::api('POST', '/api/rota', ['assignmentId' => $assignment, 'coverWanted' => true, 'note' => 'Away that weekend'], 'ruth')['status']);
        self::assertStringContainsString('Away that weekend', self::http('GET', '/profile/rota', null, 'boaz')['body'], 'it shows on his teams');
        self::assertSame(200, self::api('POST', '/api/rota', ['assignmentId' => $assignment, 'takeCover' => true], 'boaz')['status']);
        $after = ((array) self::api('GET', "/api/admin/services/$plan/rota")['json'])[0];
        self::assertSame(self::$ids['boaz'], $after['userId']);
        self::assertFalse($after['coverWanted']);
        self::assertSame('Ruth', $after['coveredFor'], 'the rota still shows what happened');
        self::assertContains('Somebody has taken your slot', array_column((array) self::http('GET', '/api/inbox', null, 'ruth')['json']['notifications'], 'title'));

        // Names are for members: the shape is public, the names are not.
        $page = self::http('GET', "/services/$plan", null, 'guest');
        self::assertStringContainsString('Sound desk', $page['body']);
        self::assertStringNotContainsString('Boaz', $page['body']);
        self::assertStringContainsString('Boaz', self::http('GET', "/services/$plan", null, 'ruth')['body']);
    }

    public function test_6_being_away_and_the_diary_feed(): void
    {
        self::assertSame(201, self::api('POST', '/api/rota', ['startDate' => self::day('+6 days'), 'endDate' => self::day('+8 days'), 'reason' => 'Away'], 'boaz')['status']);
        self::assertSame(400, self::api('POST', '/api/rota', ['startDate' => self::day('+8 days'), 'endDate' => self::day('+6 days')], 'boaz')['status']);
        $page = self::http('GET', '/profile/rota', null, 'boaz');
        self::assertStringContainsString('You said you were away', $page['body'], 'beside the service he is on');

        // What he is serving at, in his own calendar.
        $url = (string) self::api('POST', '/api/profile/calendar', null, 'boaz')['json']['url'];
        $feed = self::http('GET', (string) parse_url($url, PHP_URL_PATH), null, 'guest');
        self::assertStringContainsString('SUMMARY:Sound desk — Sunday morning', $feed['body']);
        self::assertStringContainsString('DTSTART;VALUE=DATE:' . str_replace('-', '', self::day('+7 days')), $feed['body']);

        // A declined date is written as cancelled rather than left out.
        $assignment = ((array) self::api('GET', '/api/admin/services/' . self::$ids['plan'] . '/rota')['json'])[0]['id'];
        self::assertSame(200, self::api('POST', '/api/rota', ['assignmentId' => $assignment, 'status' => 'DECLINED', 'note' => 'Sorry'], 'boaz')['status']);
        self::assertStringContainsString('STATUS:CANCELLED', self::http('GET', (string) parse_url($url, PHP_URL_PATH), null, 'guest')['body']);

        $blockouts = self::connect(self::prefix())->all('SELECT id FROM {{service_blockouts}}');
        self::assertSame(200, self::api('DELETE', '/api/rota', ['id' => (string) $blockouts[0]['id']], 'boaz')['status']);
        self::assertSame(404, self::api('DELETE', '/api/rota', ['id' => (string) $blockouts[0]['id']], 'boaz')['status']);
    }

    public function test_7_what_we_sang(): void
    {
        // A second service with the same hymn in it.
        $second = self::api('POST', '/api/admin/services', ['title' => 'Evening', 'serviceDate' => self::day('+14 days'), 'published' => true])['json']['id'];
        self::assertSame(200, self::api('PATCH', "/api/admin/services/$second", ['items' => [['fileId' => self::$ids['book'], 'hymnNumber' => 12]]])['status']);

        $window = 'from=' . self::day('-1 day') . '&to=' . self::day('+30 days');
        $report = self::api('GET', "/api/admin/services/report?$window");
        self::assertSame(200, $report['status']);
        $songs = (array) $report['json']['songs'];
        self::assertSame(['Amazing Grace', 'A hymn nobody typed', 'A scan nobody typed', 'Be Thou My Vision'], array_column($songs, 'title'), 'most sung first, then by title');
        self::assertSame(2, $songs[0]['services'], 'and how many services it was sung in');
        self::assertSame('22025', $songs[0]['ccli']);
        self::assertSame('John Newton', $songs[0]['author']);
        self::assertSame([self::day('+7 days'), self::day('+14 days')], $songs[0]['dates']);

        $csv = self::api('GET', "/api/admin/services/report?$window&format=csv");
        self::assertStringContainsString('Title,Number,CCLI,Author,Copyright,Services,Dates', $csv['body']);
        self::assertStringContainsString('"Amazing Grace","12","22025","John Newton","Public domain","2"', $csv['body']);
        self::assertStringContainsString('What we sang', self::http('GET', "/admin/services/report?$window")['body']);
        self::assertSame(403, self::api('GET', '/api/admin/services/report', null, 'ruth')['status']);
    }
}
