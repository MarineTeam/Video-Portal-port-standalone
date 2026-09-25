<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Id;

/**
 * The prayer wall through a real server: nothing on the wall until
 * somebody lets it through, anonymous meaning anonymous on every screen
 * including the moderator's, the three audiences, "I prayed for this" as a
 * number, and the request's words never reaching the audit log.
 */
final class PrayerTest extends ServerTestCase
{
    /** @var array<string, string> */
    private static array $ids = [];

    protected static function prefix(): string
    {
        return 'pr_';
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::install();
        self::$ids['ruth'] = self::member('ruth@test.example', 'ruth');
        self::$ids['boaz'] = self::member('boaz@test.example', 'boaz');
        $mod = self::member('mod@test.example', 'mod');
        $db = self::connect(self::prefix());
        $group = Id::new();
        $db->insert('permission_groups', ['id' => $group, 'name' => 'Prayer team', 'capabilities' => ['moderate_prayer']]);
        $db->insert('group_assignments', ['id' => Id::new(), 'user_id' => $mod, 'group_id' => $group]);
        $db->update('users', ['name' => 'Ruth'], ['id' => self::$ids['ruth']]);
        self::flushCache();
    }

    public function test_1_nothing_appears_until_somebody_reads_it(): void
    {
        $asked = self::api('POST', '/api/prayer', ['body' => 'For my mother, who is in hospital.'], 'ruth');
        self::assertSame(201, $asked['status']);
        self::assertSame('PENDING', $asked['json']['status']);
        self::assertArrayNotHasKey('userId', $asked['json']);
        self::$ids['mother'] = $asked['json']['id'];

        self::assertSame([], self::http('GET', '/api/prayer', null, 'guest')['json']);
        self::assertSame([], self::http('GET', '/api/prayer', null, 'boaz')['json'], 'nor to another member');
        self::assertSame([self::$ids['mother']], array_column((array) self::http('GET', '/api/prayer', null, 'ruth')['json'], 'id'), 'but she sees her own, waiting');
        self::assertStringContainsString('<meta name="robots" content="noindex">', self::http('GET', '/prayer', null, 'guest')['body']);
        self::assertSame(403, self::api('GET', '/api/admin/prayer', null, 'boaz')['status']);
        self::assertSame([self::$ids['mother']], array_column((array) self::api('GET', '/api/admin/prayer', null, 'mod')['json'], 'id'));
    }

    public function test_2_the_honeypot_and_the_rate_limit(): void
    {
        $before = (int) self::connect(self::prefix())->value('SELECT COUNT(*) FROM {{prayer_requests}}');
        self::assertSame(201, self::api('POST', '/api/prayer', ['body' => 'Cheap pills', 'website' => 'http://spam.example'], 'guest')['status']);
        self::assertSame($before, (int) self::connect(self::prefix())->value('SELECT COUNT(*) FROM {{prayer_requests}}'), 'a person never sees that field');

        $statuses = [];
        for ($i = 0; $i < 6; $i++) {
            $statuses[] = self::api('POST', '/api/prayer', ['body' => "Request $i"], 'boaz')['status'];
        }
        self::assertSame([201, 201, 201, 201, 201, 429], $statuses, 'five an hour from one account');
        self::assertSame(201, self::api('POST', '/api/prayer', ['body' => 'From somebody else on the same network'], 'ruth')['status'], 'the address\'s own limit is higher: a church shares a network');
    }

    public function test_3_letting_one_through_and_the_three_audiences(): void
    {
        $id = self::$ids['mother'];
        self::assertSame(403, self::api('PATCH', "/api/admin/prayer/$id", ['status' => 'APPROVED'], 'boaz')['status']);
        self::assertSame('APPROVED', self::api('PATCH', "/api/admin/prayer/$id", ['status' => 'APPROVED'], 'mod')['json']['status']);
        $seen = fn (string $who) => array_column((array) self::http('GET', '/api/prayer', null, $who)['json'], 'id');
        self::assertContains($id, $seen('boaz'), 'members see it');
        self::assertNotContains($id, $seen('guest'), 'a visitor does not: it was asked for members');
        self::assertSame('EVERYONE', self::api('PATCH', "/api/admin/prayer/$id", ['visibility' => 'EVERYONE'], 'mod')['json']['visibility']);
        self::assertContains($id, $seen('guest'));

        $quiet = self::api('POST', '/api/prayer', ['body' => 'Something difficult at home.', 'visibility' => 'LEADERS'], 'ruth')['json']['id'];
        self::api('PATCH', "/api/admin/prayer/$quiet", ['status' => 'APPROVED'], 'mod');
        self::assertNotContains($quiet, $seen('boaz'), 'not a wall at all');
        self::assertContains($quiet, $seen('ruth'), 'she wrote it');
        self::assertContains($quiet, array_column((array) self::api('GET', '/api/admin/prayer', null, 'mod')['json'], 'id'));
    }

    public function test_4_anonymous_means_anonymous(): void
    {
        $id = self::api('POST', '/api/prayer', ['body' => 'Please pray for my marriage.', 'anonymous' => true, 'visibility' => 'EVERYONE'], 'ruth')['json']['id'];
        self::api('PATCH', "/api/admin/prayer/$id", ['status' => 'APPROVED'], 'mod');
        $one = fn (array $rows) => array_values(array_filter($rows, fn ($r) => $r['id'] === $id))[0];
        foreach (['guest', 'boaz', 'ruth'] as $who) {
            self::assertNull($one((array) self::http('GET', '/api/prayer', null, $who)['json'])['author'], "no name for $who");
        }
        self::assertNull($one((array) self::api('GET', '/api/admin/prayer', null, 'mod')['json'])['author'], 'nor on the moderator\'s own screen');
        $card = function (string $path, string $who) use ($id): string {
            preg_match('/data-prayer="' . $id . '"(.*?)<\/li>/s', self::http('GET', $path, null, $who)['body'], $m);
            return $m[1] ?? (str_contains(self::http('GET', $path, null, $who)['body'], 'my marriage') ? 'the card was not found but the words were' : '');
        };
        self::assertStringContainsString('my marriage', $card('/prayer', 'guest'));
        self::assertStringNotContainsString('Ruth', $card('/prayer', 'guest'));
        self::assertStringContainsString('Anonymous', $card('/prayer', 'guest'));
        $queueCard = array_values(array_filter(explode('<div class="card">', self::http('GET', '/admin/prayer', null, 'mod')['body']), fn (string $part) => str_contains($part, 'my marriage')));
        self::assertCount(1, $queueCard);
        self::assertStringContainsString('anonymous', $queueCard[0]);
        self::assertStringNotContainsString('Ruth', $queueCard[0], 'nor beside it on the moderator\'s own screen');
        // The row still knows whose it is, so she can take it down.
        self::assertSame(self::$ids['ruth'], (string) self::connect(self::prefix())->value('SELECT user_id FROM {{prayer_requests}} WHERE id = ?', [$id]));
        self::assertSame(200, self::api('DELETE', "/api/prayer/$id", null, 'ruth')['status']);
    }

    public function test_5_i_prayed_for_this_is_a_number(): void
    {
        $id = self::$ids['mother'];
        self::assertSame(401, self::api('POST', "/api/prayer/$id/pray", null, 'guest')['status']);
        self::assertSame(['prayers' => 1, 'prayed' => true], self::api('POST', "/api/prayer/$id/pray", null, 'boaz')['json']);
        self::assertSame(['prayers' => 1, 'prayed' => true], self::api('POST', "/api/prayer/$id/pray", null, 'boaz')['json'], 'twice is not two');
        self::assertSame(['prayers' => 2, 'prayed' => true], self::api('POST', "/api/prayer/$id/pray", null, 'mod')['json']);
        $row = array_values(array_filter((array) self::http('GET', '/api/prayer', null, 'ruth')['json'], fn ($r) => $r['id'] === $id))[0];
        self::assertSame(2, $row['prayers']);
        self::assertFalse($row['prayed'], 'she has not pressed it');
        // Nothing nobody has been shown can be prayed for.
        $waiting = self::api('POST', '/api/prayer', ['body' => 'Still waiting'], 'ruth')['json']['id'];
        self::assertSame(404, self::api('POST', "/api/prayer/$waiting/pray", null, 'boaz')['status']);
        self::assertSame(404, self::api('POST', "/api/prayer/$waiting/pray", null, 'mod')['status'], 'nor by the moderator reading the queue');
    }

    public function test_6_answered_stays_up_and_taking_one_down(): void
    {
        $id = self::$ids['mother'];
        $answered = self::api('PATCH', "/api/admin/prayer/$id", ['status' => 'ANSWERED', 'answeredNote' => 'She is home, and well.'], 'mod')['json'];
        self::assertSame('ANSWERED', $answered['status']);
        self::assertNotNull($answered['answeredAt']);
        $page = self::http('GET', '/prayer', null, 'guest');
        self::assertStringContainsString('She is home, and well.', $page['body']);

        self::assertSame(404, self::api('DELETE', "/api/prayer/$id", null, 'boaz')['status'], 'not his to take down');
        self::assertSame(200, self::api('DELETE', "/api/prayer/$id", null, 'mod')['status']);
        self::assertStringNotContainsString('She is home, and well.', self::http('GET', '/prayer', null, 'guest')['body']);

        // The request's words never reach the audit log.
        $db = self::connect(self::prefix());
        $log = (string) json_encode($db->all('SELECT * FROM {{audit_logs}}'));
        self::assertStringContainsString('prayer.', $log, 'the decisions are logged');
        foreach (['mother, who is in hospital', 'She is home, and well', 'my marriage'] as $words) {
            self::assertStringNotContainsString($words, $log);
        }
    }
}
