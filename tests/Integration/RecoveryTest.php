<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * The lockout path: /auth/recover.
 *
 * A church whose email service was never set up, whose one administrator has
 * forgotten their password, on a host with no shell. The way back in is to
 * create storage/enable-local-login with a file manager, read the code the
 * app writes beside it, and set a new password. That is a route which hands
 * over an administrator account, so what it refuses matters more than what it
 * allows: it does not exist until the file does, it wants the code and an
 * administrator's address together, it is rate limited, and it ends every
 * session that account had.
 *
 * It is also the one place in the port with no counterpart in the original,
 * and it had no test.
 */
final class RecoveryTest extends ServerTestCase
{
    protected static function prefix(): string
    {
        return 'rec_';
    }

    private const NEW_PASSWORD = 'a locked out pastor';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::install();
    }

    protected function setUp(): void
    {
        parent::setUp();
        @unlink(self::$storage . '/enable-local-login');
        @unlink(self::$storage . '/recovery.key');
        self::connect(self::prefix())->run('DELETE FROM {{rate_limits}}');
    }

    private static function breakGlass(): string
    {
        file_put_contents(self::$storage . '/enable-local-login', '');
        // The code is written the first time the page is asked for, so that a
        // site that never needs this never has a password-shaped file lying
        // in its storage folder.
        self::http('GET', '/auth/recover', null, 'nobody');
        return trim((string) file_get_contents(self::$storage . '/recovery.key'));
    }

    public function test_1_the_page_does_not_exist_until_the_file_does(): void
    {
        $get = self::http('GET', '/auth/recover', null, 'nobody');
        self::assertSame(404, $get['status'], 'nothing to find without the file');
        self::assertFileDoesNotExist(self::$storage . '/recovery.key', 'and no code written on the way');

        // With a token good for this session, taken from the sign-in page, so
        // what answers is the route's own refusal and not the CSRF check's.
        $post = self::http('POST', '/auth/recover', ['_csrf' => self::csrf('/auth/login', 'nobody'), 'email' => 'admin@test.example', 'password' => self::NEW_PASSWORD, 'code' => 'anything'], 'nobody');
        self::assertSame(404, $post['status'], 'and the form behind it is shut too, not merely hidden');
    }

    public function test_2_the_code_is_written_beside_the_file_and_is_unguessable(): void
    {
        $code = self::breakGlass();
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $code, '16 random bytes');

        // Asking again does not move it; an administrator reads it once from
        // their file manager and types it in.
        self::http('GET', '/auth/recover', null, 'nobody');
        self::assertSame($code, trim((string) file_get_contents(self::$storage . '/recovery.key')));
    }

    public function test_3_the_code_alone_is_not_enough(): void
    {
        $code = self::breakGlass();
        $db = self::connect(self::prefix());
        $member = 'member@test.example';
        $db->insert('users', ['id' => \App\Core\Id::new(), 'email' => $member, 'role' => 'MEMBER', 'authorized' => 1]);

        foreach ([
            'an address that is nobody' => 'stranger@test.example',
            'an address that is not an administrator' => $member,
        ] as $why => $email) {
            $r = self::http('POST', '/auth/recover', ['_csrf' => self::csrf('/auth/recover', 'nobody'), 'email' => $email, 'password' => self::NEW_PASSWORD, 'code' => $code], 'nobody');
            self::assertSame(400, $r['status'], $why);
            self::assertStringNotContainsString($code, $r['body'], 'and the page never echoes the code back');
        }

        $row = $db->one('SELECT password_hash FROM {{users}} WHERE email = ?', [$member]);
        self::assertNull($row['password_hash'] ?? null, 'the member is still without a password');
    }

    public function test_4_the_address_alone_is_not_enough(): void
    {
        self::breakGlass();
        $r = self::http('POST', '/auth/recover', ['_csrf' => self::csrf('/auth/recover', 'nobody'), 'email' => 'admin@test.example', 'password' => self::NEW_PASSWORD, 'code' => str_repeat('0', 32)], 'nobody');
        self::assertSame(400, $r['status']);

        // The wrong code and the wrong address are refused in the same words,
        // so the page cannot be asked who the administrators are.
        $code = trim((string) file_get_contents(self::$storage . '/recovery.key'));
        $other = self::http('POST', '/auth/recover', ['_csrf' => self::csrf('/auth/recover', 'nobody'), 'email' => 'nobody@test.example', 'password' => self::NEW_PASSWORD, 'code' => $code], 'nobody');
        self::assertSame(400, $other['status']);
        // The token and the content-security nonce are new on every response.
        $strip = fn (string $html): string => (string) preg_replace(
            ['/name="_csrf" value="[^"]*"/', '/nonce="[^"]*"/'],
            '',
            $html,
        );
        self::assertSame($strip($r['body']), $strip($other['body']), 'byte for byte, what is new each time aside');

        // Signing in normally still works while this page is open: the way
        // back in is an extra door, not a replacement for the front one.
        $login = self::http('POST', '/auth/login', ['_csrf' => self::csrf('/auth/login', 'still-works'), 'email' => 'admin@test.example', 'password' => 'correct horse battery'], 'still-works');
        self::assertSame(303, $login['status'], (string) $login['status']);
        self::assertSame(200, self::http('GET', '/admin', null, 'still-works')['status']);
    }

    public function test_5_a_password_that_would_be_refused_anywhere_else_is_refused_here(): void
    {
        $code = self::breakGlass();
        $r = self::http('POST', '/auth/recover', ['_csrf' => self::csrf('/auth/recover', 'nobody'), 'email' => 'admin@test.example', 'password' => 'password1234', 'code' => $code], 'nobody');
        self::assertSame(400, $r['status'], 'a password off the common list');

        // A refusal for the password is not a wrong guess, so it does not
        // spend the code: the administrator types a better one and carries on.
        self::assertSame($code, trim((string) file_get_contents(self::$storage . '/recovery.key')));
    }

    public function test_6_guessing_is_rate_limited(): void
    {
        self::breakGlass();
        $token = self::csrf('/auth/recover', 'nobody');
        $statuses = [];
        for ($i = 0; $i < 9; $i++) {
            $statuses[] = self::http('POST', '/auth/recover', ['_csrf' => self::csrf('/auth/recover', 'nobody'), 'email' => 'admin@test.example', 'password' => self::NEW_PASSWORD, 'code' => sprintf('%032d', $i)], 'nobody')['status'];
        }
        self::assertContains(429, $statuses, 'guessing is stopped before nine tries');
        self::assertSame(400, $statuses[0], 'while the first few are simply wrong');
    }

    public function test_7_it_sets_the_password_and_ends_every_session_that_account_had(): void
    {
        // The administrator is signed in somewhere else — on the machine they
        // still have a session on, or someone else's.
        self::signIn('admin@test.example', 'correct horse battery', 'elsewhere');
        self::assertSame(200, self::http('GET', '/admin', null, 'elsewhere')['status']);

        $code = self::breakGlass();
        $r = self::http('POST', '/auth/recover', ['_csrf' => self::csrf('/auth/recover', 'nobody'), 'email' => 'admin@test.example', 'password' => self::NEW_PASSWORD, 'code' => $code], 'nobody');
        self::assertSame(200, $r['status'], $r['status'] . ' ' . substr($r['body'], 0, 200));

        // Whoever held that session is out, because a password reset that
        // leaves the old sessions standing has not taken the account back.
        $after = self::http('GET', '/admin', null, 'elsewhere');
        self::assertNotSame(200, $after['status'], 'the old session no longer opens the admin');

        // The code is spent, so a second run needs a new one.
        self::assertFileDoesNotExist(self::$storage . '/recovery.key');

        // And the new password works.
        self::signIn('admin@test.example', self::NEW_PASSWORD, 'admin');
        self::assertSame(200, self::http('GET', '/admin', null, 'admin')['status']);

        // It is written down: this is an administrator changing hands.
        $log = self::connect(self::prefix())->one("SELECT * FROM {{audit_logs}} WHERE action = 'auth.recover' ORDER BY created_at DESC");
        self::assertNotNull($log, 'the recovery is in the audit log');
        self::assertSame('admin@test.example', $log['actor_email'] ?? null);
    }

    public function test_8_the_way_back_in_closes_when_the_file_goes(): void
    {
        self::breakGlass();
        self::assertSame(200, self::http('GET', '/auth/recover', null, 'nobody')['status']);

        // Taken while the page is still open, so the refusal below is the
        // route's and not the CSRF check's.
        $token = self::csrf('/auth/recover', 'nobody');
        unlink(self::$storage . '/enable-local-login');
        self::assertSame(404, self::http('GET', '/auth/recover', null, 'nobody')['status'], 'shut again, with no restart');

        // Even holding the code, which is still on disk from before.
        $code = trim((string) file_get_contents(self::$storage . '/recovery.key'));
        self::assertSame(404, self::http('POST', '/auth/recover', ['_csrf' => $token, 'email' => 'admin@test.example', 'password' => self::NEW_PASSWORD, 'code' => $code], 'nobody')['status']);
    }
}
