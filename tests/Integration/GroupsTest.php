<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Id;

/**
 * Small groups through a real server: the address given only to people
 * actually in the group, a leader who is not staff answering requests on
 * the group's own page, a waiting list that moves when a place appears,
 * the conversation that only people in the group read or write, a roll
 * that reaches a member as their own row and nothing else, and leader
 * notes that never travel to a member's page.
 */
final class GroupsTest extends ServerTestCase
{
    /** @var array<string, string> */
    private static array $ids = [];

    protected static function prefix(): string
    {
        return 'gr_';
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::install();
        foreach (['naomi', 'ruth', 'boaz', 'orpah'] as $who) {
            self::$ids[$who] = self::member("$who@test.example", $who);
        }
        $db = self::connect(self::prefix());
        foreach (['naomi' => 'Naomi', 'ruth' => 'Ruth', 'boaz' => 'Boaz', 'orpah' => 'Orpah'] as $who => $name) {
            $db->update('users', ['name' => $name], ['id' => self::$ids[$who]]);
        }
        self::flushCache();
    }

    public function test_1_a_group_is_listed_and_its_address_is_not(): void
    {
        $created = self::api('POST', '/api/admin/groups', [
            'name' => 'Tuesday group',
            'description' => 'A home group for anybody.',
            'meetsWhen' => 'Tuesdays, 7.30pm',
            'area' => 'North side, near the station',
            'address' => '14 Acacia Avenue',
            'published' => true,
            'capacity' => 3,
            'leaderEmail' => 'naomi@test.example',
        ]);
        self::assertSame(201, $created['status'], (string) $created['body']);
        self::$ids['group'] = $created['json']['id'];

        // A visitor and a signed-in stranger get the district, never the address.
        foreach (['guest', 'boaz'] as $who) {
            $page = self::http('GET', '/groups/tuesday-group', null, $who);
            self::assertSame(200, $page['status']);
            self::assertStringContainsString('North side, near the station', $page['body']);
            self::assertStringNotContainsString('Acacia', $page['body'], "not to $who");
            self::assertStringNotContainsString('Acacia', (string) json_encode(self::http('GET', '/api/groups', null, $who)['json']));
        }
        // And the leader's own page has it.
        self::assertStringContainsString('14 Acacia Avenue', self::http('GET', '/groups/tuesday-group', null, 'naomi')['body']);
        self::assertSame(403, self::api('POST', '/api/admin/groups', ['name' => 'Not mine'], 'ruth')['status']);
    }

    public function test_2_asking_is_a_request_the_leader_answers(): void
    {
        $ask = fn (string $who, string $note = '') => self::api('POST', '/api/groups/tuesday-group/join', ['note' => $note], $who);
        self::assertSame(201, $ask('ruth', 'A friend asked me along')['status']);
        self::assertSame(409, $ask('ruth')['status'], 'asking twice is the same ask');

        // Still not in: an ask is not a place, and the address is still not hers.
        $asked = self::http('GET', '/groups/tuesday-group', null, 'ruth');
        self::assertStringNotContainsString('Acacia', $asked['body']);
        self::assertStringContainsString('asked to join', $asked['body']);
        self::assertSame(404, self::http('GET', '/api/groups/tuesday-group/messages', null, 'ruth')['status'], 'nor the conversation');

        // A leader is not staff: Naomi answers on the group's own page.
        $requests = self::api('GET', '/api/groups/tuesday-group/requests', null, 'naomi');
        self::assertSame(200, $requests['status']);
        self::assertSame(['Ruth'], array_column((array) $requests['json'], 'name'));
        self::assertSame(404, self::api('GET', '/api/groups/tuesday-group/requests', null, 'boaz')['status'], 'and nobody else sees who has asked');
        $memberId = (string) $requests['json'][0]['id'];
        self::assertSame(['status' => 'ACTIVE'], self::api('PATCH', "/api/groups/tuesday-group/requests/$memberId", ['status' => 'ACTIVE'], 'naomi')['json']);

        // Only a yes is a notification.
        $told = fn (string $who) => array_column((array) self::http('GET', '/api/inbox', null, $who)['json']['notifications'], 'title');
        self::assertContains('You are in', $told('ruth'));
        self::assertStringContainsString('14 Acacia Avenue', self::http('GET', '/groups/tuesday-group', null, 'ruth')['body'], 'and now the address is hers');
    }

    public function test_3_a_full_group_takes_names_in_order(): void
    {
        // Two in (Naomi, Ruth) of three places. Boaz asks and fits; Orpah waits.
        self::assertSame(201, self::api('POST', '/api/groups/tuesday-group/join', null, 'boaz')['status']);
        $waiting = self::api('POST', '/api/groups/tuesday-group/join', null, 'orpah');
        self::assertSame(201, $waiting['status']);
        self::assertSame('WAITLIST', $waiting['json']['status'], 'the third place is spoken for by an unanswered ask');
        self::assertStringContainsString('number 1 on the list', self::http('GET', '/groups/tuesday-group', null, 'orpah')['body']);

        // The leader turns Boaz down: the place frees, and Orpah's name goes
        // in front of the leader — who still decides.
        $requests = (array) self::api('GET', '/api/groups/tuesday-group/requests', null, 'naomi')['json'];
        $boaz = array_values(array_filter($requests, fn (array $r) => $r['name'] === 'Boaz'))[0];
        self::assertSame(200, self::api('PATCH', '/api/groups/tuesday-group/requests/' . $boaz['id'], ['status' => 'DECLINED'], 'naomi')['status']);
        $after = (array) self::api('GET', '/api/groups/tuesday-group/requests', null, 'naomi')['json'];
        self::assertSame([['Orpah', 'REQUESTED']], array_map(fn (array $r) => [$r['name'], $r['status']], $after));
        self::assertStringNotContainsString('Acacia', self::http('GET', '/groups/tuesday-group', null, 'orpah')['body'], 'a place is not an answer');

        $told = array_column((array) self::http('GET', '/api/inbox', null, 'boaz')['json']['notifications'], 'title');
        self::assertNotContains('You are in', $told, 'a no is a conversation, not a notification');
    }

    public function test_4_the_conversation_is_for_people_in_the_group(): void
    {
        $say = fn (string $who, string $body) => self::api('POST', '/api/groups/tuesday-group/messages', ['body' => $body], $who);
        self::assertSame(201, $say('ruth', "Bringing a cake on Tuesday\n\n\n\n\nand the plates")['status']);
        self::assertSame(404, $say('orpah', 'Can I come?')['status'], 'an unanswered ask is not in the thread');
        self::assertSame(401, $say('guest', 'hello')['status'], 'and a visitor is asked to sign in');

        $thread = self::http('GET', '/api/groups/tuesday-group/messages', null, 'naomi')['json'];
        self::assertSame(["Bringing a cake on Tuesday\n\nand the plates"], array_column($thread['messages'], 'body'));
        self::assertStringNotContainsString(self::$ids['ruth'], (string) json_encode($thread), 'no account ids travel out');
        self::assertContains('New in Tuesday group', array_column((array) self::http('GET', '/api/inbox', null, 'naomi')['json']['notifications'], 'title'));
        self::assertContains('Bringing a cake on Tuesday', array_column((array) self::http('GET', '/api/inbox', null, 'naomi')['json']['notifications'], 'body'), 'the first line only');

        // A site manager outside the group gets nothing from it.
        self::assertSame(404, self::http('GET', '/api/groups/tuesday-group/messages')['status']);

        // The leader takes it down; it is hidden from everybody, her included.
        $id = (string) $thread['messages'][0]['id'];
        self::assertSame(404, self::api('DELETE', "/api/groups/tuesday-group/messages/$id", null, 'boaz')['status'], 'somebody outside the group is told nothing, not even that it exists');
        self::assertSame(200, self::api('DELETE', "/api/groups/tuesday-group/messages/$id", null, 'naomi')['status']);
        self::assertSame([], self::http('GET', '/api/groups/tuesday-group/messages', null, 'naomi')['json']['messages']);

        // Mute keeps somebody in the group and stops the notifications.
        self::assertSame(['muted' => true], self::api('PATCH', '/api/groups/tuesday-group/messages', ['muted' => true], 'naomi')['json']);
        $before = count((array) self::http('GET', '/api/inbox', null, 'naomi')['json']['notifications']);
        self::assertSame(201, $say('ruth', 'Second thoughts about the cake')['status']);
        self::assertSame($before, count((array) self::http('GET', '/api/inbox', null, 'naomi')['json']['notifications']));
        self::assertSame(1, count(self::http('GET', '/api/groups/tuesday-group/messages', null, 'naomi')['json']['messages']), 'still in the group');
    }

    public function test_5_the_roll_reaches_a_member_as_their_own_row(): void
    {
        $today = gmdate('Y-m-d');
        $roll = [
            'date' => $today,
            'topic' => 'Romans 8',
            'visitorCount' => 2,
            'leaderNotes' => 'Ring Ruth about the rota',
            'attendance' => [
                ['userId' => self::$ids['naomi'], 'status' => 'PRESENT'],
                ['userId' => self::$ids['ruth'], 'status' => 'APOLOGIES', 'note' => 'Away with work'],
                ['userId' => self::$ids['boaz'], 'status' => 'PRESENT'],
            ],
        ];
        self::assertSame(403, self::api('POST', '/api/groups/tuesday-group/meetings', $roll, 'ruth')['status'], 'a member doesn\'t write the roll');
        $written = self::api('POST', '/api/groups/tuesday-group/meetings', $roll, 'naomi');
        self::assertSame(201, $written['status'], (string) $written['body']);
        $meetings = (array) $written['json']['meetings'];
        self::assertSame(3, $meetings[0]['summary']['inTheRoom'], 'one present, plus two visitors — apologies are neither present nor absent');
        self::assertSame(1, $meetings[0]['summary']['apologies']);
        self::assertCount(2, $meetings[0]['attendance'], 'Boaz was turned down, so he is not markable');

        // A member sees their own evening and nothing else.
        $mine = (array) self::api('GET', '/api/groups/tuesday-group/meetings', null, 'ruth')['json'];
        self::assertCount(1, $mine[0]['attendance']);
        self::assertSame('APOLOGIES', $mine[0]['attendance'][0]['status']);
        self::assertArrayNotHasKey('summary', $mine[0], 'never a count, which is a size');
        self::assertArrayNotHasKey('leaderNotes', $mine[0]);
        self::assertStringNotContainsString('Ring Ruth about the rota', self::http('GET', '/groups/tuesday-group', null, 'ruth')['body']);
        self::assertStringContainsString('Ring Ruth about the rota', self::http('GET', '/groups/tuesday-group', null, 'naomi')['body']);

        // A roll can't be written for an evening that hasn't happened.
        $future = ['date' => gmdate('Y-m-d', time() + 7 * 86400), 'attendance' => []];
        self::assertSame(400, self::api('POST', '/api/groups/tuesday-group/meetings', $future, 'naomi')['status']);
        // One meeting per group per day: writing it again is the same roll.
        self::assertSame(201, self::api('POST', '/api/groups/tuesday-group/meetings', $roll + ['topic' => 'Romans 8 again'], 'naomi')['status']);
        self::assertSame(1, (int) self::connect(self::prefix())->value('SELECT COUNT(*) FROM {{small_group_meetings}}'));
    }

    public function test_6_a_guides_leader_notes_never_reach_a_member(): void
    {
        $guide = self::api('POST', '/api/admin/guides', ['title' => 'Romans 8', 'published' => true]);
        self::assertSame(201, $guide['status']);
        $id = (string) $guide['json']['id'];
        foreach ([
            ['kind' => 'SCRIPTURE', 'body' => 'Read it together', 'reference' => 'Romans 8:1-11'],
            ['kind' => 'QUESTION', 'body' => 'What does "no condemnation" mean here?'],
            ['kind' => 'LEADER_NOTE', 'body' => 'Do not let this become a debate about predestination.'],
        ] as $item) {
            self::assertSame(201, self::api('POST', "/api/admin/guides/$id/items", $item)['status']);
        }
        $page = self::http('GET', '/guides/romans-8', null, 'ruth');
        self::assertStringContainsString('no condemnation', $page['body']);
        self::assertStringNotContainsString('predestination', $page['body'], 'a member is never given the field');
        self::assertStringNotContainsString('predestination', self::http('GET', '/guides/romans-8', null, 'guest')['body']);
        self::assertStringContainsString('predestination', self::http('GET', '/guides/romans-8', null, 'naomi')['body'], 'whoever leads any group may read them');
        self::assertStringContainsString('1 question', self::http('GET', '/guides', null, 'guest')['body']);
    }

    public function test_6b_whoever_keeps_the_list_can_put_somebody_in_a_group(): void
    {
        $id = self::$ids['group'];
        // A site manager outside the group gets nothing from the conversation;
        // putting themselves in works, and leaves a row saying so.
        self::assertSame(404, self::http('GET', '/api/groups/tuesday-group/messages')['status']);
        $added = self::api('POST', "/api/admin/groups/$id/members", ['email' => 'admin@test.example', 'role' => 'MEMBER']);
        self::assertSame(201, $added['status'], (string) $added['body']);
        self::assertSame(200, self::http('GET', '/api/groups/tuesday-group/messages')['status']);
        self::assertContains('admin@test.example', array_column((array) $added['json']['members'], 'email'));
        $screen = self::http('GET', "/admin/groups/$id");
        self::assertStringContainsString('admin@test.example', $screen['body']);
        $mine = array_values(array_filter((array) $added['json']['members'], fn (array $m) => $m['email'] === 'admin@test.example'))[0];
        self::assertSame(200, self::api('DELETE', "/api/admin/groups/$id/members/" . $mine['id'])['status']);
        self::assertSame(404, self::http('GET', '/api/groups/tuesday-group/messages')['status'], 'and out again');
    }

    public function test_7_a_name_on_a_group_page_is_never_an_address(): void
    {
        $db = self::connect(self::prefix());
        $db->update('users', ['name' => 'naomi@test.example'], ['id' => self::$ids['naomi']]);
        self::flushCache();
        $page = self::http('GET', '/groups/tuesday-group', null, 'ruth');
        self::assertStringNotContainsString('naomi@test.example', $page['body']);
        self::assertStringContainsString('A member', $page['body']);
        $db->update('users', ['name' => 'Naomi'], ['id' => self::$ids['naomi']]);
    }
}
