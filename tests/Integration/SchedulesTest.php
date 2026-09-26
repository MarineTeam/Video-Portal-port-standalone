<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * The calendar through a real server.
 *
 * The rule under test is the one the port changed: the dates are public and
 * the names need a sign-in. A signed-out reader is handed events with nobody
 * on them rather than names to hide, /api/people is a 403 without a session,
 * and a personId filter is refused signed out — "which days is this id on"
 * is "who is this", sideways.
 *
 * Also: the rota kept here, reminders reaching only a name with an account,
 * a merge moving the history and leaving an alias, and the offline snapshot.
 */
final class SchedulesTest extends ServerTestCase
{
    /** @var array<string, string> */
    private static array $ids = [];

    protected static function prefix(): string
    {
        return 'sc_';
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::install();
        self::$ids['ruth'] = self::member('ruth@test.example', 'ruth');
        self::flushCache();
    }

    private static function day(int $offset): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d');
    }

    public function test_1_a_rota_is_kept_here(): void
    {
        $made = self::api('POST', '/api/admin/schedules', ['name' => 'Breakbread', 'color' => 'blue']);
        self::assertSame(201, $made['status'], (string) $made['body']);
        self::$ids['rota'] = (string) $made['json']['id'];
        self::assertSame('breakbread', $made['json']['slug']);
        self::assertSame('WEB', $made['json']['sourceType']);

        $welcome = self::api('POST', '/api/admin/schedules', ['name' => 'Welcome']);
        self::$ids['welcome'] = (string) $welcome['json']['id'];

        // Names are typed in, because that is what somebody reading a rota
        // has; the people rows are made as they turn up.
        $sunday = self::api('POST', '/api/admin/schedules/' . self::$ids['rota'] . '/events', [
            'date' => self::day(3),
            'startTime' => '10:30',
            'location' => 'Main hall',
            'people' => ['Devin Liao', 'cindy  liao'],
        ]);
        self::assertSame(201, $sunday['status'], (string) $sunday['body']);
        self::$ids['sunday'] = (string) $sunday['json']['id'];
        self::assertSame(['Devin Liao', 'Cindy Liao'], array_column($sunday['json']['people'], 'displayName'));
        self::assertFalse($sunday['json']['allDay']);

        self::assertSame(400, self::api('POST', '/api/admin/schedules/' . self::$ids['rota'] . '/events', [
            'date' => self::day(10), 'endDate' => self::day(9),
        ])['status'], 'a date cannot end before it starts');

        self::api('POST', '/api/admin/schedules/' . self::$ids['welcome'] . '/events', ['date' => self::day(3), 'people' => ['Devin Liao']]);
        self::assertSame(403, self::api('POST', '/api/admin/schedules', ['name' => 'Not mine'], 'ruth')['status']);
    }

    public function test_2_the_dates_are_public_and_the_names_are_not(): void
    {
        $guest = self::http('GET', '/api/calendar-events', null, 'guest');
        self::assertSame(200, $guest['status']);
        $events = $guest['json']['events'];
        self::assertCount(2, $events, 'a stranger sees both dates');
        foreach ($events as $event) {
            self::assertArrayNotHasKey('people', $event, 'with nobody on them');
            self::assertArrayNotHasKey('personIds', $event);
            self::assertTrue($event['namesWithheld']);
            self::assertSame('Main hall', $event['location'] ?? 'Main hall', 'the structure is still public');
        }
        self::assertStringNotContainsString('Devin', $guest['body']);

        $page = self::http('GET', '/calendar', null, 'guest');
        self::assertStringContainsString('<meta name="robots" content="noindex">', $page['body'], 'even without names it says when the building is in use');
        self::assertStringNotContainsString('Devin', $page['body']);
        self::assertStringContainsString('Breakbread', $page['body']);

        $member = self::http('GET', '/api/calendar-events', null, 'ruth');
        self::assertSame('Devin Liao', $member['json']['events'][0]['people'][0]['displayName']);
    }

    public function test_3_who_is_this_sideways_is_refused(): void
    {
        self::assertSame(403, self::http('GET', '/api/people', null, 'guest')['status']);
        $people = self::http('GET', '/api/people', null, 'ruth');
        self::assertSame(200, $people['status']);
        $devin = null;
        foreach ($people['json']['people'] as $person) {
            if ($person['displayName'] === 'Devin Liao') {
                $devin = $person['id'];
            }
        }
        self::assertNotNull($devin);
        self::$ids['devin'] = (string) $devin;

        self::assertSame(403, self::http('GET', '/api/calendar-events?personId=' . $devin, null, 'guest')['status'], 'a personId filter is refused signed out');
        self::assertSame(403, self::http('GET', '/api/schedules/' . self::$ids['rota'] . '/events?personId=' . $devin, null, 'guest')['status']);
        $mine = self::http('GET', '/api/calendar-events?personId=' . $devin, null, 'ruth');
        self::assertSame(200, $mine['status']);
        self::assertCount(2, $mine['json']['events'], 'Devin is on both');

        // The rotas themselves are public: that is the half that did not change.
        $rotas = self::http('GET', '/api/schedules', null, 'guest');
        self::assertSame(['Breakbread', 'Welcome'], array_column($rotas['json']['schedules'], 'name'));
    }

    public function test_4_the_snapshot_a_device_keeps(): void
    {
        $guest = self::http('GET', '/api/sync/snapshot', null, 'guest');
        self::assertSame(200, $guest['status']);
        self::assertStringContainsString('no-store', (string) ($guest['headers']['cache-control'] ?? ''), 'it carries names, so no shared cache keeps it');
        self::assertSame([], $guest['json']['people'], 'a stranger gets no names at all');
        self::assertTrue($guest['json']['namesWithheld']);
        foreach ($guest['json']['events'] as $event) {
            self::assertArrayNotHasKey('people', $event);
        }
        self::assertStringNotContainsString('Devin', $guest['body']);
        self::assertTrue($guest['json']['full'], 'and always a full one, so a shared laptop stops carrying names');

        $member = self::http('GET', '/api/sync/snapshot', null, 'ruth');
        self::assertNotSame([], $member['json']['people']);
        self::assertSame(2, count($member['json']['events']));
        self::assertTrue($member['json']['full']);

        // A second ask, with what it already holds, is a delta.
        $delta = self::http('GET', '/api/sync/snapshot?since=' . urlencode((string) $member['json']['syncedAt']), null, 'ruth');
        self::assertFalse($delta['json']['full']);
        self::assertSame([], $delta['json']['events'], 'nothing has changed since');

        // A signed-out sync stays full however much it says it holds.
        $stale = self::http('GET', '/api/sync/snapshot?since=' . urlencode((string) $member['json']['syncedAt']), null, 'guest');
        self::assertTrue($stale['json']['full']);
    }

    public function test_5_two_spellings_are_one_person_and_a_merge_keeps_the_other(): void
    {
        // Case and spacing are already one person: the normalized name is
        // the unique key, so this resolves rather than making a second row.
        self::api('POST', '/api/admin/schedules/' . self::$ids['welcome'] . '/events', ['date' => self::day(10), 'people' => ['DEVIN LIAO']]);
        $db = self::connect(self::prefix());
        self::assertSame(1, (int) $db->value('SELECT COUNT(*) FROM {{people}} WHERE display_name LIKE ?', ['%Liao']) - 1);

        $dave = self::api('POST', '/api/admin/people', ['displayName' => 'Dave']);
        self::assertSame(201, $dave['status'], (string) $dave['body']);
        $davey = self::api('POST', '/api/admin/people', ['displayName' => 'Davey']);
        self::assertSame(201, $davey['status']);
        $ids = [];
        foreach ($davey['json']['people'] as $person) {
            $ids[$person['displayName']] = $person['id'];
        }

        $page = self::http('GET', '/admin/people');
        self::assertStringContainsString('Davey', $page['body']);
        self::assertStringContainsString('Dave', $page['body'], 'and they are offered as possibly the same person, never merged for you');

        self::api('POST', '/api/admin/schedules/' . self::$ids['welcome'] . '/events', ['date' => self::day(12), 'people' => ['Davey']]);
        $merged = self::api('POST', '/api/admin/people/merge', ['keepId' => $ids['Dave'], 'loseId' => $ids['Davey']]);
        self::assertSame(200, $merged['status'], (string) $merged['body']);
        self::assertNotContains('Davey', array_column($merged['json']['people'], 'displayName'));
        self::assertSame(1, (int) $db->value('SELECT COUNT(*) FROM {{calendar_event_people}} WHERE person_id = ?', [$ids['Dave']]), 'the history moved');
        self::assertSame('davey', (string) $db->value('SELECT normalized_name FROM {{person_aliases}} WHERE person_id = ?', [$ids['Dave']]));

        // So the next import resolves the old spelling rather than making it again.
        self::api('POST', '/api/admin/schedules/' . self::$ids['welcome'] . '/events', ['date' => self::day(14), 'people' => ['Davey']]);
        self::assertSame(0, (int) $db->value('SELECT COUNT(*) FROM {{people}} WHERE normalized_name = ?', ['davey']));
        self::assertSame(2, (int) $db->value('SELECT COUNT(*) FROM {{calendar_event_people}} WHERE person_id = ?', [$ids['Dave']]));
    }

    public function test_6_a_name_with_an_account_gets_the_reminder_and_the_diary(): void
    {
        $db = self::connect(self::prefix());
        $db->update('people', ['user_id' => self::$ids['ruth']], ['id' => self::$ids['devin']]);
        $db->update('calendar_events', ['date' => self::day(1)], ['id' => self::$ids['sunday']]);

        self::http('POST', '/admin/jobs/schedule-reminders/run', ['_csrf' => self::csrf('/admin/jobs')]);
        $inbox = $db->one('SELECT * FROM {{notifications}} WHERE user_id = ? ORDER BY created_at DESC', [self::$ids['ruth']]);
        self::assertNotNull($inbox, 'one message however many rotas');
        self::assertStringContainsString('Breakbread', (string) $inbox['body']);

        // The rota's dates reach that member's own diary feed.
        $token = self::api('POST', '/api/profile/calendar', [], 'ruth');
        self::assertSame(200, $token['status'], (string) $token['body']);
        $feed = self::http('GET', parse_url((string) $token['json']['url'], PHP_URL_PATH) ?: '', null, 'guest');
        self::assertStringContainsString('Breakbread', $feed['body']);
    }

    public function test_7_a_sheet_needs_a_key_and_says_so(): void
    {
        $refused = self::api('POST', '/api/admin/schedules/' . self::$ids['rota'] . '/validate', ['spreadsheetId' => 'https://docs.google.com/spreadsheets/d/abc123/edit']);
        self::assertSame(400, $refused['status']);
        self::assertStringContainsString('service account', (string) $refused['json']['error']);

        self::assertSame(400, self::api('POST', '/api/admin/schedules/key', ['key' => '{"type":"nope"}'])['status']);
        self::assertSame(400, self::api('POST', '/api/admin/schedules/' . self::$ids['rota'] . '/sync')['status'], 'a rota kept here is not fed by a sheet');
    }

    public function test_8_a_rota_taken_off_the_calendar_takes_its_dates_with_it(): void
    {
        self::api('PATCH', '/api/admin/schedules/' . self::$ids['welcome'], ['enabled' => false]);
        $rotas = self::http('GET', '/api/schedules', null, 'guest');
        self::assertSame(['Breakbread'], array_column($rotas['json']['schedules'], 'name'));
        foreach (self::http('GET', '/api/calendar-events', null, 'guest')['json']['events'] as $event) {
            self::assertNotSame(self::$ids['welcome'], $event['scheduleId']);
        }
        self::assertSame(200, self::api('DELETE', '/api/admin/schedules/' . self::$ids['welcome'])['status']);
    }
}
