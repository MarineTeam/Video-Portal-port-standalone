<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Id;

/**
 * The Live streaming plugin through a real server: a stream scheduled and
 * published, the "Live now" banner, and the chat beside it — open half an
 * hour early and closed an hour after, one's own messages, a moderator's
 * reach, mutes, slow mode, and a poll that never carries a taken-down
 * message however far behind it is.
 */
final class LiveTest extends ServerTestCase
{
    /** @var array<string, string> */
    private static array $ids = [];

    protected static function prefix(): string
    {
        return 'lv_';
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
        $db->insert('permission_groups', ['id' => $group, 'name' => 'Chat moderators', 'capabilities' => ['moderate_comments']]);
        $db->insert('group_assignments', ['id' => Id::new(), 'user_id' => $mod, 'group_id' => $group]);
        self::flushCache();
    }

    private static function stamp(string $offset): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify($offset)->format('Y-m-d\TH:i:s\Z');
    }

    public function test_1_nothing_scheduled(): void
    {
        $page = self::http('GET', '/live', null, 'guest');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Nothing is scheduled', $page['body']);
        self::assertStringNotContainsString('data-live-chat', $page['body']);
    }

    public function test_2_a_stream_is_scheduled_published_and_announced(): void
    {
        $created = self::api('POST', '/api/admin/live', [
            'title' => 'Carol service',
            'embedUrl' => 'https://www.youtube-nocookie.com/embed/abc123',
            'description' => 'Sung by candlelight.',
            'startAt' => self::stamp('-10 minutes'),
            'endAt' => self::stamp('+80 minutes'),
        ]);
        self::assertSame(201, $created['status']);
        self::$ids['stream'] = $created['json']['id'];
        self::assertFalse($created['json']['published']);
        self::assertStringContainsString('Nothing is scheduled', self::http('GET', '/live', null, 'guest')['body'], 'a draft is nobody else\'s business');
        self::assertSame(400, self::api('POST', '/api/admin/live', ['title' => 'Insecure', 'embedUrl' => 'http://example.org/embed', 'startAt' => self::stamp('+1 day')])['status']);
        self::assertSame(403, self::api('POST', '/api/admin/live', ['title' => 'Not mine', 'embedUrl' => 'https://example.org/e', 'startAt' => self::stamp('+1 day')], 'ruth')['status']);

        self::assertSame(200, self::api('PATCH', '/api/admin/live/' . self::$ids['stream'], ['published' => true])['status']);
        $page = self::http('GET', '/live', null, 'guest');
        self::assertStringContainsString('https://www.youtube-nocookie.com/embed/abc123', $page['body']);
        self::assertStringContainsString('Sung by candlelight.', $page['body']);
        self::assertStringNotContainsString('data-live-chat', $page['body'], 'the chat is off until somebody turns it on');
        // A banner everywhere else, and a nav entry, while it is running.
        self::assertStringContainsString('Live now', self::http('GET', '/', null, 'guest')['body']);
        self::assertStringNotContainsString('live-banner', $page['body'], 'not on /live itself');
        self::assertStringContainsString('<loc>http://127.0.0.1', self::http('GET', '/sitemap.xml', null, 'guest')['body']);
        self::assertStringContainsString('/live</loc>', self::http('GET', '/sitemap.xml', null, 'guest')['body']);

        $told = fn (string $who) => array_column((array) self::http('GET', '/api/inbox', null, $who)['json']['notifications'], 'title');
        self::assertContains('Going live', $told('ruth'));
        self::assertContains('Going live', $told('boaz'));
    }

    public function test_3_the_chat(): void
    {
        $id = self::$ids['stream'];
        self::assertSame(400, self::api('POST', "/api/live/$id/chat", ['body' => 'Hello'], 'ruth')['status'], 'the chat is off');
        self::assertSame(200, self::api('PATCH', "/api/admin/live/$id", ['chatEnabled' => true])['status']);
        self::assertStringContainsString('data-live-chat', self::http('GET', '/live', null, 'guest')['body']);

        self::assertSame(401, self::api('POST', "/api/live/$id/chat", ['body' => 'Hello'], 'guest')['status']);
        $first = self::api('POST', "/api/live/$id/chat", ['body' => "AMEN!!!!!!!!!!!!\n\n  and again"], 'ruth');
        self::assertSame(201, $first['status']);
        self::assertSame('AMEN!!! and again', $first['json']['body'], 'the shouting a length limit doesn\'t stop');
        self::assertArrayNotHasKey('userId', $first['json']);
        self::assertSame(400, self::api('POST', "/api/live/$id/chat", ['body' => '   '], 'ruth')['status'], 'nothing at all');

        $boaz = self::api('POST', "/api/live/$id/chat", ['body' => 'Rude words'], 'boaz')['json'];
        $poll = self::http('GET', "/api/live/$id/chat", null, 'guest')['json'];
        self::assertSame(['AMEN!!! and again', 'Rude words'], array_column($poll['messages'], 'body'));
        self::assertSame('OPEN', $poll['state']);
        self::assertFalse(array_column($poll['messages'], 'canDelete', 'body')['Rude words'], 'a guest takes nothing down');
        self::assertSame([], self::http('GET', "/api/live/$id/chat?since=" . $boaz['id'], null, 'guest')['json']['messages'], 'a poll asks only for what is new');

        // One's own, and nobody else's.
        self::assertSame(403, self::api('DELETE', "/api/live/$id/chat/" . $boaz['id'], null, 'ruth')['status']);
        self::assertSame(200, self::api('DELETE', "/api/live/$id/chat/" . $first['json']['id'], null, 'ruth')['status']);
        $behind = self::http('GET', "/api/live/$id/chat", null, 'guest')['json'];
        self::assertSame(['Rude words'], array_column($behind['messages'], 'body'));
        self::assertContains($first['json']['id'], $behind['removed'], 'a tab a few seconds behind is told to drop it');

        // A moderator mutes, which hides what they have already written.
        self::assertSame(403, self::api('POST', "/api/live/$id/chat/mute", ['messageId' => $boaz['id']], 'ruth')['status']);
        self::assertSame(['muted' => true], self::api('POST', "/api/live/$id/chat/mute", ['messageId' => $boaz['id']], 'mod')['json']);
        $after = self::http('GET', "/api/live/$id/chat", null, 'guest')['json'];
        self::assertSame([], $after['messages']);
        self::assertContains($boaz['id'], $after['removed']);
        self::assertSame(403, self::api('POST', "/api/live/$id/chat", ['body' => 'Again'], 'boaz')['status'], 'muted for the evening');
        self::assertTrue(self::http('GET', "/api/live/$id/chat", null, 'boaz')['json']['muted']);
        self::assertSame(['muted' => false], self::api('POST', "/api/live/$id/chat/mute", ['messageId' => $boaz['id'], 'muted' => false], 'mod')['json']);
        self::assertSame(201, self::api('POST', "/api/live/$id/chat", ['body' => 'Sorry'], 'boaz')['status']);

        // Slow mode counts from that person's own last message.
        self::assertSame(200, self::api('PATCH', "/api/admin/live/$id", ['chatSlowMode' => 60])['status']);
        $refused = self::api('POST', "/api/live/$id/chat", ['body' => 'And another thing'], 'boaz');
        self::assertSame(429, $refused['status']);
        self::assertSame(201, self::api('POST', "/api/live/$id/chat", ['body' => 'Hello again'], 'mod')['status'], 'somebody who has not just written waits for nobody else');
    }

    public function test_4_the_chat_closes_but_what_was_said_stays_readable(): void
    {
        $id = self::$ids['stream'];
        self::assertSame(200, self::api('PATCH', "/api/admin/live/$id", ['startAt' => self::stamp('-1 year'), 'endAt' => self::stamp('-1 year +90 minutes')])['status']);
        $poll = self::http('GET', "/api/live/$id/chat", null, 'guest')['json'];
        self::assertSame('CLOSED', $poll['state']);
        self::assertContains('Sorry', array_column($poll['messages'], 'body'), 'the messages stay, the box goes');
        self::assertSame(400, self::api('POST', "/api/live/$id/chat", ['body' => 'Anybody there?'], 'ruth')['status']);
        self::assertStringNotContainsString('Live now', self::http('GET', '/', null, 'guest')['body'], 'last year\'s carol service is not live now');
        self::assertStringContainsString('Nothing is scheduled', self::http('GET', '/live', null, 'guest')['body']);
        self::assertSame(200, self::api('DELETE', "/api/admin/live/$id")['status']);
        self::assertSame(404, self::http('GET', "/api/live/$id/chat", null, 'guest')['status']);
    }

    public function test_5_nothing_on_now_counts_down_to_the_next_one(): void
    {
        foreach (['Harvest' => '+3 days', 'Christmas' => '+90 days'] as $title => $offset) {
            self::assertSame(201, self::api('POST', '/api/admin/live', [
                'title' => $title,
                'embedUrl' => 'https://www.youtube-nocookie.com/embed/' . strtolower($title),
                'startAt' => self::stamp($offset),
                'published' => true,
                'chatEnabled' => true,
            ])['status']);
        }
        $page = self::http('GET', '/live', null, 'guest');
        self::assertStringNotContainsString('Nothing is scheduled', $page['body']);
        self::assertStringContainsString('Harvest', $page['body'], 'the next one is what is counted down to');
        self::assertStringNotContainsString('<iframe', $page['body'], 'no player until it starts');
        self::assertStringNotContainsString('data-live-chat', $page['body'], 'nor a chat three days early');
        self::assertStringContainsString('Christmas', $page['body'], 'and the rest are listed under Coming up');
        self::assertStringNotContainsString('Live now', self::http('GET', '/', null, 'guest')['body']);
    }
}
