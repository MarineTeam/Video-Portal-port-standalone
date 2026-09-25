<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * The Announcements plugin through a real server: only the newest active
 * banner for the reader's audience shows, inside its window; the admin API
 * is for those who manage plugins; a write shows at once (the cache is
 * forgotten).
 */
final class AnnouncementsTest extends ServerTestCase
{
    protected static function prefix(): string
    {
        return 'an_';
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::install();
        self::member('ruth@test.example', 'ruth');
    }

    protected function setUp(): void
    {
        foreach ((array) self::api('GET', '/api/admin/announcements')['json'] as $row) {
            self::api('DELETE', '/api/admin/announcements/' . $row['id']);
        }
    }

    private static function banner(string $who): ?string
    {
        $body = self::http('GET', '/', null, $who)['body'];
        return preg_match('/data-announcement="[^"]+">\s*<p>([^<]*)<\/p>/', $body, $m) ? html_entity_decode($m[1]) : null;
    }

    public function test_the_newest_active_one_for_the_audience_shows(): void
    {
        self::assertSame(201, self::api('POST', '/api/admin/announcements', ['message' => 'Older news', 'audience' => 'ALL'])['status']);
        usleep(20_000);
        self::api('POST', '/api/admin/announcements', ['message' => 'Members: potluck Sunday', 'audience' => 'MEMBERS']);
        self::assertSame('Older news', self::banner('guest'));
        self::assertSame('Members: potluck Sunday', self::banner('ruth'));

        $id = self::api('POST', '/api/admin/announcements', ['message' => 'Guests <welcome>', 'audience' => 'GUESTS'])['json']['id'];
        self::assertSame('Guests <welcome>', self::banner('guest'), 'escaped on the way out');
        self::api('PATCH', '/api/admin/announcements/' . $id, ['active' => false]);
        self::assertSame('Older news', self::banner('guest'));
    }

    public function test_the_window_holds_it_back_and_takes_it_down(): void
    {
        self::api('POST', '/api/admin/announcements', ['message' => 'Not yet', 'publishAt' => gmdate('c', time() + 3600)]);
        self::api('POST', '/api/admin/announcements', ['message' => 'Too late', 'expiresAt' => gmdate('c', time() - 60)]);
        self::assertNull(self::banner('guest'));
        self::api('POST', '/api/admin/announcements', ['message' => 'Now', 'publishAt' => gmdate('c', time() - 60), 'expiresAt' => gmdate('c', time() + 3600)]);
        self::assertSame('Now', self::banner('guest'));
        self::assertSame(400, self::api('POST', '/api/admin/announcements', ['message' => 'Backwards', 'publishAt' => gmdate('c', time() + 7200), 'expiresAt' => gmdate('c', time() + 3600)])['status']);
    }

    public function test_only_those_who_manage_plugins_may_write(): void
    {
        self::assertSame(403, self::api('POST', '/api/admin/announcements', ['message' => 'Hi'], 'ruth')['status']);
        self::assertSame(401, self::api('POST', '/api/admin/announcements', ['message' => 'Hi'], 'guest')['status']);
        self::assertSame(400, self::api('POST', '/api/admin/announcements', ['message' => ''])['status']);
        self::assertSame(400, self::api('POST', '/api/admin/announcements', ['message' => 'x', 'audience' => 'STAFF'])['status']);
        self::assertSame(200, self::http('GET', '/admin/announcements')['status']);
    }
}
