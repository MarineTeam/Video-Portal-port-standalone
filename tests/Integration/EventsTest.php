<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Id;

/**
 * Events through a real server: publishing, signing up without an account,
 * places counted with guests, the last place going to one person, a
 * waiting list that moves when somebody drops out and stops at the first
 * party that doesn't fit, a members-only event that is invisible rather
 * than refused, the three calendar feeds, and a repeat that fills the
 * diary and can be stopped without deleting anybody's place.
 */
final class EventsTest extends ServerTestCase
{
    /** @var array<string, string> */
    private static array $ids = [];

    protected static function prefix(): string
    {
        return 'ev_';
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::install();
        self::$ids['ruth'] = self::member('ruth@test.example', 'ruth');
        self::$ids['boaz'] = self::member('boaz@test.example', 'boaz');
        self::flushCache();
    }

    private static function stamp(string $offset): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify($offset)->format('Y-m-d\TH:i:s\Z');
    }

    /** @param array<string, mixed> $extra */
    private static function makeEvent(array $extra = []): array
    {
        $r = self::api('POST', '/api/admin/events', $extra + [
            'title' => 'Men\'s breakfast',
            'startsAt' => self::stamp('+7 days'),
            'endsAt' => self::stamp('+7 days +2 hours'),
            'published' => true,
            'registration' => true,
            'capacity' => 4,
            'maxGuests' => 3,
        ]);
        self::assertSame(201, $r['status'], (string) $r['body']);
        return (array) $r['json'];
    }

    public function test_1_publishing_and_what_a_visitor_sees(): void
    {
        $event = self::makeEvent(['published' => false]);
        self::$ids['breakfast'] = $event['id'];
        self::$ids['breakfastSlug'] = $event['slug'];
        self::assertSame('men-s-breakfast', $event['slug']);
        self::assertSame(404, self::http('GET', '/events/' . $event['slug'], null, 'guest')['status'], 'a draft is nobody else\'s business');
        self::assertSame(200, self::api('PATCH', '/api/admin/events/' . $event['id'], ['published' => true])['status']);
        $page = self::http('GET', '/events/' . $event['slug'], null, 'guest');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Men&#039;s breakfast', $page['body']);
        self::assertStringContainsString('data-event-form', $page['body'], 'no account needed to sign up');
        self::assertStringContainsString('Men&#039;s breakfast', self::http('GET', '/events', null, 'guest')['body']);

        // Members only is invisible, not refused.
        $closed = self::makeEvent(['title' => 'Elders away day', 'memberOnly' => true, 'registration' => false]);
        self::assertSame(404, self::http('GET', '/events/' . $closed['slug'], null, 'guest')['status']);
        self::assertStringNotContainsString('Elders away day', self::http('GET', '/events', null, 'guest')['body']);
        self::assertSame(200, self::http('GET', '/events/' . $closed['slug'], null, 'ruth')['status']);
        self::assertSame(404, self::http('GET', '/events/' . $closed['slug'] . '/event.ics', null, 'ruth')['status'], 'a calendar app has nobody to check');
        self::$ids['awaySlug'] = $closed['slug'];
    }

    public function test_2_the_last_place_goes_to_one_person(): void
    {
        $slug = self::$ids['breakfastSlug'];
        $sign = fn (string $name, int $guests, string $who = 'guest') => self::api('POST', "/api/events/$slug/register", [
            'name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)) . '@test.example', 'guests' => $guests,
        ], $who);
        self::assertSame('GOING', $sign('Amos Smith', 0)['json']['status'], 'one place of four');
        self::assertSame(400, $sign('Too Many', 9)['status'], 'more guests than allowed');
        self::assertSame('GOING', $sign('Family Jones', 2)['json']['status'], 'a guest counts as a place: four of four');
        $full = self::http('GET', "/events/$slug", null, 'guest');
        self::assertStringContainsString('0 places left', $full['body']);
        self::assertSame('WAITLIST', $sign('Late Comer', 1)['json']['status']);
        self::assertSame('WAITLIST', $sign('Ruth Moab', 0, 'ruth')['json']['status']);
        self::assertSame(409, $sign('Amos Smith', 0)['status'], 'twice is not two places');

        $state = self::http('GET', "/events/$slug", null, 'ruth');
        self::assertStringContainsString('waiting list', $state['body']);
    }

    public function test_3_the_waiting_list_moves_when_somebody_drops_out(): void
    {
        $slug = self::$ids['breakfastSlug'];
        $id = self::$ids['breakfast'];
        $list = fn () => array_map(fn (array $r) => $r['name'] . ':' . $r['status'], (array) self::api('GET', "/api/admin/events/$id/registrations")['json']);
        self::assertSame(['Amos Smith:GOING', 'Family Jones:GOING', 'Late Comer:WAITLIST', 'Ruth Moab:WAITLIST'], $list());

        // Amos drops out, freeing one place. Late Comer is bringing
        // somebody, so two are wanted and nobody moves — and Ruth, who
        // would fit, is not seated past the party in front of her.
        self::assertSame(200, self::api('DELETE', "/api/events/$slug/register", ['email' => 'amos.smith@test.example'], 'guest')['status']);
        self::assertSame(['Amos Smith:CANCELLED', 'Family Jones:GOING', 'Late Comer:WAITLIST', 'Ruth Moab:WAITLIST'], $list());

        // Raising the capacity moves the list too, and now both fit.
        self::assertSame(200, self::api('PATCH', "/api/admin/events/$id", ['capacity' => 6])['status']);
        self::assertSame(['Amos Smith:CANCELLED', 'Family Jones:GOING', 'Late Comer:GOING', 'Ruth Moab:GOING'], $list());

        $csv = self::api('GET', "/api/admin/events/$id/registrations?format=csv");
        self::assertStringContainsString('Name,Email,Phone,Guests,Status,Member,Note,Signed up', $csv['body']);
        self::assertStringContainsString('"Ruth Moab","ruth.moab@test.example","","0","GOING","yes"', $csv['body'], 'with the column the sign-up form didn\'t ask for');
        self::assertStringContainsString('"Family Jones"', $csv['body']);
        self::assertStringNotContainsString('userId', (string) json_encode(self::api('GET', "/api/admin/events/$id/registrations")['json']));

        self::assertStringContainsString('Men&#039;s breakfast', self::http('GET', '/profile/events', null, 'ruth')['body']);
        $screen = self::http('GET', "/admin/events/$id");
        self::assertStringContainsString('Ruth Moab', $screen['body'], 'the list for the door, on screen');
        self::assertStringContainsString('moved up', $screen['body']);
        self::assertSame(200, self::api('DELETE', "/api/events/$slug/register", null, 'ruth')['status']);
        self::assertSame(403, self::api('GET', "/api/admin/events/$id/registrations", null, 'ruth')['status']);
    }

    public function test_4_the_calendar_feeds(): void
    {
        $feed = self::http('GET', '/events/calendar.ics', null, 'guest');
        self::assertSame(200, $feed['status']);
        self::assertStringContainsString('BEGIN:VCALENDAR', $feed['body']);
        self::assertStringContainsString('SUMMARY:Men\'s breakfast', $feed['body']);
        self::assertStringNotContainsString('Elders away day', $feed['body'], 'a members-only event is absent: nobody is there to check');
        $one = self::http('GET', '/events/' . self::$ids['breakfastSlug'] . '/event.ics', null, 'guest');
        self::assertStringContainsString('BEGIN:VEVENT', $one['body']);
        self::assertSame(1, substr_count($one['body'], 'BEGIN:VEVENT'));

        // The member's own diary: nobody has one until they ask.
        self::assertSame(404, self::http('GET', '/api/calendar/nothing-like-a-token/marine-team.ics', null, 'guest')['status']);
        $made = self::api('POST', '/api/profile/calendar', null, 'boaz');
        self::assertSame(200, $made['status']);
        $url = (string) $made['json']['url'];
        self::assertMatchesRegularExpression('#/api/calendar/[A-Za-z0-9_-]{16,}/marine-team\.ics$#', $url);
        $path = (string) parse_url($url, PHP_URL_PATH);
        self::api('POST', '/api/events/' . self::$ids['breakfastSlug'] . '/register', ['name' => 'Boaz', 'email' => 'boaz@test.example'], 'boaz');
        $diary = self::http('GET', $path, null, 'guest');
        self::assertSame(200, $diary['status'], 'the token is the whole of the authentication');
        self::assertStringContainsString('Men\'s breakfast', $diary['body']);
        // Replacing it stops every calendar following the old link.
        $replaced = (string) self::api('POST', '/api/profile/calendar', null, 'boaz')['json']['url'];
        self::assertNotSame($url, $replaced);
        self::assertSame(404, self::http('GET', $path, null, 'guest')['status']);
        self::assertSame(200, self::api('DELETE', '/api/profile/calendar', null, 'boaz')['status']);
        self::assertSame(404, self::http('GET', (string) parse_url($replaced, PHP_URL_PATH), null, 'guest')['status']);
        self::assertStringNotContainsString('calendarToken', (string) self::http('GET', '/api/profile/export', null, 'boaz')['body']);
    }

    public function test_4b_the_last_place_goes_to_one_person(): void
    {
        $event = self::makeEvent(['title' => 'One place left', 'capacity' => 1, 'maxGuests' => 0]);
        // Four people press the button in the same second, through four
        // connections at once, and the hall is not overbooked.
        preg_match('/name="csrf-token" content="([^"]+)"/', self::http('GET', '/', null, 'guest')['body'], $m);
        $multi = curl_multi_init();
        $handles = [];
        foreach (range(1, 4) as $n) {
            $ch = curl_init(self::$base . '/api/events/' . $event['slug'] . '/register');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_COOKIEFILE => self::jar('guest'),
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . ($m[1] ?? '')],
                CURLOPT_POSTFIELDS => (string) json_encode(['name' => "Person $n", 'email' => "person$n@test.example"]),
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[] = $ch;
        }
        do {
            curl_multi_exec($multi, $running);
            curl_multi_select($multi, 0.2);
        } while ($running > 0);
        $answers = [];
        foreach ($handles as $ch) {
            $answers[] = (array) json_decode((string) curl_multi_getcontent($ch), true);
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }
        curl_multi_close($multi);
        $statuses = array_count_values(array_map(fn (array $a) => (string) ($a['status'] ?? $a['error'] ?? '?'), $answers));
        self::assertSame(1, $statuses['GOING'] ?? 0, 'one yes: ' . json_encode($statuses));
        self::assertSame(3, $statuses['WAITLIST'] ?? 0, 'and three places on the list: ' . json_encode($statuses));
        self::assertSame(1, (int) self::connect(self::prefix())->value(
            'SELECT COUNT(*) FROM {{event_registrations}} WHERE event_id = ? AND status = ?',
            [$event['id'], 'GOING'],
        ));
    }

    public function test_5_something_that_repeats(): void
    {
        $created = self::api('POST', '/api/admin/events/series', [
            'title' => 'Prayer meeting',
            'shape' => 'WEEKLY',
            'days' => ['TU'],
            'startDate' => (new \DateTimeImmutable('next tuesday', new \DateTimeZone('UTC')))->format('Y-m-d'),
            'startTime' => '19:30',
            'durationMinutes' => 90,
            'published' => true,
            'registration' => true,
            'capacity' => 20,
            'opensDaysBefore' => 14,
            'closesDaysBefore' => 0,
        ]);
        self::assertSame(201, $created['status'], (string) $created['body']);
        self::assertSame('FREQ=WEEKLY;BYDAY=TU', $created['json']['rule']);
        self::assertSame('Every week on Tuesday at 19:30', $created['json']['describe']);
        self::assertGreaterThan(20, $created['json']['created'], 'six months of Tuesdays');
        $seriesId = $created['json']['id'];

        $db = self::connect(self::prefix());
        $dates = array_map('strval', $db->column('SELECT slug FROM {{events}} WHERE series_id = ? ORDER BY starts_at', [$seriesId]));
        self::assertMatchesRegularExpression('/^prayer-meeting-\d{4}-\d{2}-\d{2}$/', $dates[0], 'the URL says which week it is for');
        // Generating again is idempotent.
        self::assertSame(200, self::api('PATCH', "/api/admin/events/series/$seriesId", ['title' => 'Prayer meeting'])['status']);
        self::assertSame(count($dates), (int) $db->value('SELECT COUNT(*) FROM {{events}} WHERE series_id = ?', [$seriesId]));

        // Removing one date sticks.
        $second = (string) $db->value('SELECT id FROM {{events}} WHERE series_id = ? ORDER BY starts_at LIMIT 1 OFFSET 1', [$seriesId]);
        self::assertSame(200, self::api('DELETE', "/api/admin/events/$second")['status']);
        self::api('PATCH', "/api/admin/events/series/$seriesId", ['location' => 'The hall']);
        self::assertSame(count($dates) - 1, (int) $db->value('SELECT COUNT(*) FROM {{events}} WHERE series_id = ?', [$seriesId]));

        // Stopping it never deletes somebody's place.
        $booked = (string) $db->value('SELECT slug FROM {{events}} WHERE series_id = ? ORDER BY starts_at LIMIT 1', [$seriesId]);
        self::assertSame(201, self::api('POST', "/api/events/$booked/register", ['name' => 'Ruth', 'email' => 'ruth@test.example'], 'ruth')['status']);
        $stopped = self::api('DELETE', "/api/admin/events/series/$seriesId");
        self::assertSame(200, $stopped['status']);
        self::assertSame(1, $stopped['json']['kept']);
        self::assertSame(0, (int) $db->value('SELECT COUNT(*) FROM {{event_series}} WHERE id = ?', [$seriesId]));
        $survivor = $db->one('SELECT * FROM {{events}} WHERE slug = ?', [$booked]);
        self::assertNotNull($survivor, 'a sign-up is a promise to a person');
        self::assertNull($survivor['series_id'], 'detached, and left exactly where it was');
    }
}
