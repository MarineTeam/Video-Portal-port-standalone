<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * A broadcast through a real server.
 *
 * What is being proved: the count on the screen is the count that goes out;
 * the three consent rules hold where it matters, not only in the pure
 * module; the list is frozen before anything is sent, so a send resumed
 * after a closed laptop finishes rather than starting again; and one bad
 * address does not stop the rest.
 */
final class BroadcastsTest extends ServerTestCase
{
    /** @var array<string, string> */
    private static array $ids = [];

    protected static function prefix(): string
    {
        return 'bc_';
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::install();
        $db = self::connect(self::prefix());
        // Ruth can be reached every way; Sam turned announcements off; Tom
        // has a number but never said yes to being texted.
        self::$ids['ruth'] = self::member('ruth@test.example', 'ruth');
        self::$ids['sam'] = self::member('sam@test.example', 'sam');
        self::$ids['tom'] = self::member('tom@test.example', 'tom');
        $db->update('users', ['name' => 'Ruth', 'phone' => '+447700900123', 'sms_opt_in' => 1], ['id' => self::$ids['ruth']]);
        $db->update('users', ['name' => 'Sam', 'broadcast_emails' => 0], ['id' => self::$ids['sam']]);
        $db->update('users', ['name' => 'Tom', 'phone' => '+447700900999', 'sms_opt_in' => 0], ['id' => self::$ids['tom']]);
        self::flushCache();
    }

    public function test_1_it_says_who_it_will_reach_before_it_goes(): void
    {
        $seen = self::api('POST', '/api/admin/broadcasts/preview', [
            'audience' => 'EVERYONE',
            'channels' => ['EMAIL'],
            'body' => 'No service tomorrow, the road is closed.',
        ]);
        self::assertSame(200, $seen['status'], (string) $seen['body']);
        // The administrator, Ruth and Tom get the email; Sam turned
        // announcements off, which is a different switch from the sermon one.
        self::assertSame(3, $seen['json']['reached']);
        self::assertSame(1, $seen['json']['missed']);
        self::assertSame([['reason' => 'announcementsOff', 'count' => 1]], $seen['json']['skips']);

        $withText = self::api('POST', '/api/admin/broadcasts/preview', [
            'audience' => 'EVERYONE',
            'channels' => ['EMAIL', 'SMS'],
            'body' => 'No service tomorrow.',
        ]);
        // Still three reached: a member with no text who gets the email is
        // not missing out, and Sam is still the only one getting nothing.
        self::assertSame(3, $withText['json']['reached']);
        self::assertSame(1, $withText['json']['missed']);
        self::assertSame(1, $withText['json']['sms']['messages'], 'and it costs what the composer said');
    }

    public function test_2_a_number_on_an_event_form_is_not_permission_to_text(): void
    {
        $db = self::connect(self::prefix());
        $eventId = \App\Core\Id::new();
        $db->insert('events', [
            'id' => $eventId, 'title' => 'Men’s breakfast', 'slug' => 'mens-breakfast',
            'starts_at' => \App\Core\Db::datetime(new \DateTimeImmutable('+10 days')),
            'published' => 1, 'registration' => 1,
        ]);
        self::$ids['event'] = $eventId;
        foreach ([
            ['name' => 'Visitor', 'email' => 'visitor@test.example', 'phone' => '+447700900321', 'user_id' => null],
            ['name' => 'Ruth', 'email' => 'ruth@test.example', 'phone' => '+447700900123', 'user_id' => self::$ids['ruth']],
        ] as $row) {
            $db->insert('event_registrations', $row + ['id' => \App\Core\Id::new(), 'event_id' => $eventId, 'status' => 'GOING']);
        }

        $seen = self::api('POST', '/api/admin/broadcasts/preview', [
            'audience' => 'EVENT',
            'audienceId' => $eventId,
            'channels' => ['EMAIL', 'SMS', 'PUSH'],
            'body' => 'Breakfast is still on.',
        ]);
        self::assertSame(2, $seen['json']['reached'], 'both, at the address they typed');
        $reasons = array_column($seen['json']['skips'], 'reason');
        self::assertSame([], $reasons, 'each of them is reachable some way');

        // The visitor's number came from a public form, so nothing is texted
        // to it: only Ruth's own opted-in number is.
        $made = self::api('POST', '/api/admin/broadcasts', [
            'subject' => 'Breakfast', 'body' => 'Still on.',
            'channels' => ['SMS'], 'audience' => 'EVENT', 'audienceId' => $eventId,
        ]);
        self::assertSame(201, $made['status'], (string) $made['body']);
        $tried = self::api('POST', '/api/admin/broadcasts/' . $made['json']['id'] . '/send');
        self::assertSame(200, $tried['status'], (string) $tried['body']);
        self::assertSame(1, $tried['json']['progress']['total'], 'one row: Ruth, on the number she typed in herself');
        self::assertSame(1, $tried['json']['progress']['skipped'], 'and no provider to send it with, which is not her fault');
        self::assertSame(0, $tried['json']['progress']['failed']);
        self::assertSame(
            '+447700900123',
            (string) self::connect(self::prefix())->value('SELECT address FROM {{broadcast_recipients}} WHERE broadcast_id = ?', [$made['json']['id']]),
            'and never the number the visitor typed on a public form',
        );
        self::api('DELETE', '/api/admin/broadcasts/' . $made['json']['id']);

        $justEmail = self::api('POST', '/api/admin/broadcasts/preview', [
            'audience' => 'EVENT', 'audienceId' => $eventId, 'channels' => ['SMS'], 'body' => 'x',
        ]);
        self::assertSame(1, $justEmail['json']['reached'], 'only Ruth, who typed her number in herself');
        self::assertSame('notOptedIn', $justEmail['json']['skips'][0]['reason']);
    }

    public function test_3_the_list_is_frozen_before_anything_is_sent(): void
    {
        $made = self::api('POST', '/api/admin/broadcasts', [
            'subject' => 'No service tomorrow',
            'body' => 'The road is closed.',
            'channels' => ['EMAIL'],
            'audience' => 'EVERYONE',
        ]);
        self::assertSame(201, $made['status'], (string) $made['body']);
        self::$ids['broadcast'] = (string) $made['json']['id'];
        self::assertSame('DRAFT', $made['json']['status']);

        $sent = self::api('POST', '/api/admin/broadcasts/' . self::$ids['broadcast'] . '/send');
        self::assertSame(200, $sent['status'], (string) $sent['body']);
        self::assertSame(3, $sent['json']['progress']['total'], 'one row per person per channel, Sam excluded');
        self::assertTrue($sent['json']['progress']['finished']);
        self::assertSame('SENT', $sent['json']['status']);
        // This install has no email provider, so every row is skipped —
        // nothing is wrong with the addresses, and none of them is a failure.
        self::assertSame(3, $sent['json']['progress']['skipped']);
        self::assertSame(0, $sent['json']['progress']['failed']);

        $db = self::connect(self::prefix());
        $addresses = $db->column('SELECT address FROM {{broadcast_recipients}} WHERE broadcast_id = ? ORDER BY address', [self::$ids['broadcast']]);
        self::assertContains('ruth@test.example', $addresses);
        self::assertNotContains('sam@test.example', $addresses);
        self::assertSame(3, (int) $db->value('SELECT COUNT(*) FROM {{email_log}} WHERE subject = ?', ['No service tomorrow']));
    }

    public function test_4_sending_it_again_does_not_send_it_twice(): void
    {
        self::assertSame(409, self::api('POST', '/api/admin/broadcasts/' . self::$ids['broadcast'] . '/send')['status']);
        $db = self::connect(self::prefix());
        self::assertSame(3, (int) $db->value('SELECT COUNT(*) FROM {{email_log}} WHERE subject = ?', ['No service tomorrow']));
        // Nor is a message on its way editable: half the recipients would
        // have the old one.
        self::assertSame(409, self::api('PATCH', '/api/admin/broadcasts/' . self::$ids['broadcast'], ['subject' => 'Changed'])['status']);
    }

    public function test_5_it_survives_being_interrupted(): void
    {
        $made = self::api('POST', '/api/admin/broadcasts', [
            'subject' => 'Carols', 'body' => 'Sunday at six.', 'channels' => ['EMAIL'], 'audience' => 'EVERYONE',
        ]);
        $id = (string) $made['json']['id'];
        $db = self::connect(self::prefix());

        // Freeze the list and send one, the way a first batch would.
        self::api('POST', "/api/admin/broadcasts/$id/send");
        $db->run('UPDATE {{broadcast_recipients}} SET status = ?, sent_at = NULL WHERE broadcast_id = ?', ['PENDING', $id]);
        $db->update('broadcasts', ['status' => 'SENDING', 'sent_at' => null], ['id' => $id]);
        $db->run(
            'UPDATE {{broadcast_recipients}} SET status = ?, sent_at = ? WHERE broadcast_id = ? ORDER BY id LIMIT 1',
            ['SENT', \App\Core\Db::now(), $id],
        );
        $before = (int) $db->value('SELECT COUNT(*) FROM {{email_log}} WHERE subject = ?', ['Carols']);

        $resumed = self::api('POST', "/api/admin/broadcasts/$id/send");
        self::assertSame(200, $resumed['status']);
        self::assertTrue($resumed['json']['progress']['finished']);
        self::assertSame(1, $resumed['json']['progress']['sent'], 'the one already marked stays marked');
        self::assertSame(2, $resumed['json']['progress']['skipped'], 'and only the rest were worked through');
        self::assertSame(
            $before + 2,
            (int) $db->value('SELECT COUNT(*) FROM {{email_log}} WHERE subject = ?', ['Carols']),
            'the rest went later — not everybody twice',
        );
    }

    public function test_6_one_bad_address_does_not_stop_the_rest(): void
    {
        $db = self::connect(self::prefix());
        $made = self::api('POST', '/api/admin/broadcasts', [
            'subject' => 'Working bee', 'body' => 'Saturday morning.', 'channels' => ['EMAIL'], 'audience' => 'EVERYONE',
        ]);
        $id = (string) $made['json']['id'];
        self::api('POST', "/api/admin/broadcasts/$id/send");
        // The email provider here is "none", which records rather than
        // sends; make one row look like a provider refusal instead.
        $db->run(
            'UPDATE {{broadcast_recipients}} SET status = ?, error = ? WHERE broadcast_id = ? ORDER BY id LIMIT 1',
            ['FAILED', 'The address does not exist.', $id],
        );
        $seen = self::http('GET', "/api/admin/broadcasts/$id");
        self::assertSame(1, $seen['json']['progress']['failed']);
        self::assertSame(2, $seen['json']['progress']['skipped'], 'the rest were still worked through');
        self::assertSame(0, $seen['json']['progress']['pending'], 'and one bad address did not stop them');
        self::assertSame('The address does not exist.', $seen['json']['failures'][0]['error']);
        self::assertStringContainsString('The address does not exist.', self::http('GET', "/admin/broadcasts/$id")['body']);
    }

    public function test_7_it_can_be_stopped_part_way(): void
    {
        $made = self::api('POST', '/api/admin/broadcasts', [
            'subject' => 'Cancelled', 'body' => 'Never mind.', 'channels' => ['EMAIL'], 'audience' => 'EVERYONE',
        ]);
        $id = (string) $made['json']['id'];
        $db = self::connect(self::prefix());
        self::api('POST', "/api/admin/broadcasts/$id/send");
        $db->run('UPDATE {{broadcast_recipients}} SET status = ? WHERE broadcast_id = ?', ['PENDING', $id]);
        $db->update('broadcasts', ['status' => 'SENDING'], ['id' => $id]);

        $stopped = self::api('POST', "/api/admin/broadcasts/$id/cancel");
        self::assertSame('CANCELLED', $stopped['json']['status']);
        self::assertSame(0, $stopped['json']['progress']['pending']);
        self::assertSame(3, $stopped['json']['progress']['skipped'], 'the rest were not sent, and say so');
    }

    public function test_8_only_somebody_who_may_send_one_can(): void
    {
        self::assertSame(403, self::api('POST', '/api/admin/broadcasts', ['subject' => 'x', 'body' => 'y'], 'ruth')['status']);
        self::assertSame(403, self::http('GET', '/admin/broadcasts', null, 'ruth')['status']);
        self::assertContains(self::http('GET', '/api/admin/broadcasts', null, 'guest')['status'], [401, 403]);
    }
}
