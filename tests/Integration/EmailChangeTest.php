<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Db;
use App\Core\Id;

/**
 * Changing the address an account signs in with.
 *
 * The address is the account, so this is a takeover in one step if it is
 * wrong. Three things carry it: nothing changes until the new address
 * answers, the old address is told while it can still act, and the address
 * that is applied is the one off the token rather than one a later request
 * names.
 */
final class EmailChangeTest extends ServerTestCase
{
    protected static function prefix(): string
    {
        return 'chg_';
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::install();
        $db = self::connect(self::prefix());
        // An email slot that counts as set up, with no provider behind it:
        // every send is recorded in email_log rather than delivered, which
        // is what lets the test read the two messages.
        $db->insert('services', [
            'id' => Id::new(), 'slot' => 'email', 'provider' => 'log', 'active' => 1,
            'config' => '{}',
        ]);
        self::flushCache();
        self::member('ruth@test.example', 'ruth');
        self::member('sam@test.example', 'sam');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $db = self::connect(self::prefix());
        $db->run('DELETE FROM {{email_log}}');
        $db->run('DELETE FROM {{auth_tokens}}');
        $db->run('DELETE FROM {{rate_limits}}');
        $db->update('users', ['pending_email' => null], ['email' => 'ruth@test.example']);
    }

    /**
     * Put the address back and sign in again: confirming ends every session
     * on purpose, so the tests after one have to start over.
     */
    private static function restore(string $from): void
    {
        self::connect(self::prefix())->update('users', ['email' => 'ruth@test.example'], ['email' => $from]);
        self::flushCache();
        self::signIn('ruth@test.example', 'member password 123', 'ruth');
    }

    /** @return list<array<string, mixed>> */
    private static function sent(): array
    {
        return self::connect(self::prefix())->all('SELECT * FROM {{email_log}} ORDER BY created_at, id');
    }

    private static function linkIn(string $body): string
    {
        preg_match('#/auth/email/([A-Za-z0-9_-]{43})#', $body, $m);
        return $m[1] ?? '';
    }

    /**
     * The link the new address received.
     *
     * It cannot be read back out of the email log, because a message
     * carrying a live token is logged without its body — which test 2
     * asserts. So the test mints the same token against the same row, which
     * is what the inbox is holding.
     */
    private static function linkFor(string $email): string
    {
        $db = self::connect(self::prefix());
        $userId = (string) $db->value('SELECT id FROM {{users}} WHERE pending_email = ?', [$email]);
        self::assertNotSame('', $userId, "nobody is waiting to move to $email");
        return \App\Modules\Access\AuthTokens::issue($db, $userId, 'email_change', $email);
    }

    /** A local account proves it is still the member sitting there. */
    private static function ask(string $email, string $who = 'ruth'): array
    {
        return self::api('POST', '/api/profile/email', ['email' => $email, 'password' => 'member password 123'], $who);
    }

    public function test_1_asking_changes_nothing_and_writes_to_both_addresses(): void
    {
        $answer = self::ask('ruth-new@test.example');
        self::assertSame(200, $answer['status'], (string) $answer['body']);

        $db = self::connect(self::prefix());
        self::assertSame('ruth@test.example', $db->value('SELECT email FROM {{users}} WHERE id = ?', [self::$ids['ruth'] ?? '']) ?? 'ruth@test.example');
        self::assertSame('ruth-new@test.example', $db->value('SELECT pending_email FROM {{users}} WHERE email = ?', ['ruth@test.example']));

        $sent = self::sent();
        self::assertCount(2, $sent, 'the new address and the old one');
        $to = array_column($sent, 'to_address');
        self::assertContains('ruth-new@test.example', $to);
        self::assertContains('ruth@test.example', $to, 'the old address hears about it while it can still act');
    }

    public function test_2_neither_message_leaves_a_live_link_in_the_log(): void
    {
        self::ask('ruth-new@test.example');
        $sent = self::sent();
        self::assertCount(2, $sent);
        $bodies = [];
        foreach ($sent as $message) {
            $bodies[(string) $message['to_address']] = (string) ($message['text_body'] ?? '');
            self::assertSame('', self::linkIn((string) $message['text_body']), (string) $message['to_address']);
        }
        // The message carrying the one-time link is logged without its body,
        // so neither /admin/logs nor a backup holds a working one.
        self::assertSame('', $bodies['ruth-new@test.example']);
        // The notice to the old address carries no token, so it is kept
        // whole — and it is the one somebody may need to read again.
        self::assertStringContainsString('ruth-new@test.example', $bodies['ruth@test.example']);
        self::assertStringContainsString('Nothing has changed yet', $bodies['ruth@test.example']);
        // The notice to the old address is not a link, and says so plainly.
        $subjects = array_column($sent, 'subject');
        self::assertContains('Confirm your new email address', $subjects);
        self::assertContains('Your email address is being changed', $subjects);
    }

    public function test_3_the_change_applies_only_when_the_new_address_answers(): void
    {
        self::ask('ruth-new@test.example');
        $token = self::linkFor('ruth-new@test.example');

        $answer = self::http('GET', "/auth/email/$token", null, 'guest');
        self::assertSame(200, $answer['status'], (string) $answer['body']);

        $db = self::connect(self::prefix());
        $row = $db->one('SELECT id, email, pending_email, email_verified_at FROM {{users}} WHERE email = ?', ['ruth-new@test.example']);
        self::assertNotNull($row, 'the account now signs in as the new address');
        self::assertNull($row['pending_email']);
        self::assertNotNull($row['email_verified_at'], 'and it has just proved itself by answering');

        self::assertSame(0, (int) $db->value('SELECT COUNT(*) FROM {{sessions}} WHERE user_id = ?', [$row['id'] ?? '']), 'and every session ends, because the address is how somebody signs in');
        self::restore('ruth-new@test.example');
    }

    public function test_4_the_link_works_once(): void
    {
        self::ask('ruth-again@test.example');
        $token = self::linkFor('ruth-again@test.example');
        self::assertSame(200, self::http('GET', "/auth/email/$token", null, 'guest')['status']);
        self::assertSame(410, self::http('GET', "/auth/email/$token", null, 'guest')['status']);

        self::restore('ruth-again@test.example');
    }

    public function test_5_cancelling_kills_the_link(): void
    {
        self::ask('ruth-cancel@test.example');
        $token = self::linkFor('ruth-cancel@test.example');
        self::assertSame(200, self::api('DELETE', '/api/profile/email', null, 'ruth')['status']);
        self::assertNull(self::connect(self::prefix())->value('SELECT pending_email FROM {{users}} WHERE email = ?', ['ruth@test.example']));
        self::assertSame(410, self::http('GET', "/auth/email/$token", null, 'guest')['status'], 'the link somebody was sent stops working');
    }

    public function test_6_asking_again_puts_the_first_link_out(): void
    {
        self::ask('ruth-first@test.example');
        $first = self::linkFor('ruth-first@test.example');
        self::ask('ruth-second@test.example');

        self::assertSame(410, self::http('GET', "/auth/email/$first", null, 'guest')['status']);
        self::assertSame('ruth@test.example', self::connect(self::prefix())->value('SELECT email FROM {{users}} WHERE email = ?', ['ruth@test.example']));
    }

    public function test_7_an_address_somebody_else_holds_is_not_said_out_loud(): void
    {
        // Answering "that is taken" would make this a way of asking who is a
        // member. It answers the same and the link never arrives.
        $answer = self::ask('sam@test.example');
        self::assertSame(200, $answer['status']);
        self::assertSame([], self::sent(), 'and nothing is sent to either address');
        self::assertNull(self::connect(self::prefix())->value('SELECT pending_email FROM {{users}} WHERE email = ?', ['ruth@test.example']));
    }

    public function test_8_it_is_the_members_own_address_or_nothing(): void
    {
        self::assertSame(401, self::api('POST', '/api/profile/email', ['email' => 'x@test.example'], 'guest')['status']);
        self::assertSame(400, self::ask('not-an-address')['status']);
        self::assertSame(400, self::ask('ruth@test.example')['status'], 'the one they already have');
        self::assertSame(
            403,
            self::api('POST', '/api/profile/email', ['email' => 'ruth-x@test.example', 'password' => 'not the password'], 'ruth')['status'],
            'and somebody at a borrowed laptop cannot move the account',
        );
    }
}
