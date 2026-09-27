<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * /admin/media-check, and the Bunny audit it offers.
 *
 * The audit is the question "is what we are paying Bunny for the same as what
 * we can reach", and the answer that matters most is the one it gives when it
 * cannot ask: saying so, rather than reporting every file as missing.
 */
final class MediaCheckTest extends ServerTestCase
{
    protected static function prefix(): string
    {
        return 'mcheck_';
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (self::$base === '') {
            return;
        }
        self::install();
        self::member('naomi@test.example', 'naomi');
    }

    public function test_1_an_administrator_is_offered_the_audit(): void
    {
        $page = self::http('GET', '/admin/media-check');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('data-bunny-audit-start', $page['body']);
        // It is offered, not run: a request per folder is not a page load.
        self::assertStringContainsString('deletes nothing', $page['body']);
    }

    public function test_2_with_bunny_not_set_up_it_says_so_rather_than_calling_everything_missing(): void
    {
        $answer = self::api('GET', '/api/admin/bunny-audit');

        self::assertSame(200, $answer['status']);
        foreach (['files', 'videos'] as $half) {
            self::assertFalse($answer['json'][$half]['checked'], $half);
            self::assertNotSame('', (string) $answer['json'][$half]['why'], $half);
            self::assertSame([], $answer['json'][$half]['orphans'], $half);
            self::assertSame([], $answer['json'][$half]['missing'], $half);
        }
    }

    public function test_3_it_is_not_a_members_question(): void
    {
        self::assertContains(self::http('GET', '/admin/media-check', null, 'naomi')['status'], [403, 404]);
        self::assertContains(self::api('GET', '/api/admin/bunny-audit', null, 'naomi')['status'], [403, 404]);
    }

    public function test_4_running_it_is_written_down(): void
    {
        self::api('GET', '/api/admin/bunny-audit');
        $db = self::connect(self::prefix());

        self::assertSame('bunny.audit', (string) $db->value("SELECT action FROM {{audit_logs}} WHERE action = 'bunny.audit' ORDER BY created_at DESC LIMIT 1"));
    }
}
