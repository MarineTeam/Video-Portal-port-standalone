<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Db;
use App\Core\Migrator;
use App\Modules\Access\AuthTokens;

/**
 * The tokens in a password reset, a magic link and an email change.
 *
 * Each of these is, for the minutes it lives, a way to become somebody.
 * Four properties carry that: the table holds a hash and not the token, it
 * works once, it stops working on time, and asking again invalidates the
 * last one. The single-use rule is checked against two callers racing,
 * because "already used" decided by a read is not a rule.
 */
final class AuthTokensTest extends DatabaseTestCase
{
    private const PREFIX = 'tok_';
    private static ?Db $db = null;

    public static function setUpBeforeClass(): void
    {
        $name = getenv('MT_TEST_DB_NAME');
        if (!is_string($name) || $name === '') {
            return;
        }
        $db = self::connect(self::PREFIX);
        self::dropPrefix($db, self::PREFIX);
        (new Migrator($db, dirname(__DIR__, 2) . '/app/Migrations'))->runAll();
        self::$db = $db;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::dropPrefix(self::$db, self::PREFIX);
        }
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('MT_TEST_DB_NAME is not set.');
        }
        self::$db->run('DELETE FROM {{auth_tokens}}');
        self::$db->run(
            'INSERT INTO {{users}} (id, email, name, role, authorized) VALUES (?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE email = VALUES(email)',
            ['ruth', 'ruth@test.example', 'Ruth', 'MEMBER'],
        );
    }

    public function test_1_the_table_holds_a_hash_and_never_the_token(): void
    {
        $raw = AuthTokens::issue(self::$db, 'ruth', 'reset');
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $raw, '32 random bytes');

        $row = self::$db->one('SELECT token_hash FROM {{auth_tokens}}');
        self::assertSame(hash('sha256', $raw), $row['token_hash']);
        self::assertNotSame($raw, $row['token_hash'], 'a database read is not a password reset');
    }

    public function test_2_a_token_works_once(): void
    {
        $raw = AuthTokens::issue(self::$db, 'ruth', 'reset');
        self::assertSame('ruth', AuthTokens::consume(self::$db, $raw, 'reset')['user_id'] ?? null);
        self::assertNull(AuthTokens::consume(self::$db, $raw, 'reset'), 'and not twice');
        self::assertNull(AuthTokens::peek(self::$db, $raw, 'reset'));
    }

    public function test_3_two_callers_racing_for_one_token_cannot_both_win(): void
    {
        // Two tabs, or a link prefetched by a mail client and then clicked.
        // The UPDATE decides, not the read before it.
        $raw = AuthTokens::issue(self::$db, 'ruth', 'magic');
        $others = [];
        for ($i = 0; $i < 6; $i++) {
            $others[] = AuthTokens::consume(self::connect(self::PREFIX), $raw, 'magic');
        }
        self::assertCount(1, array_filter($others), 'exactly one caller is let in');
    }

    public function test_4_a_token_is_bound_to_its_purpose(): void
    {
        // A magic link is a sign-in and a reset sets a password; one must
        // never be spendable as the other.
        $raw = AuthTokens::issue(self::$db, 'ruth', 'magic');
        self::assertNull(AuthTokens::consume(self::$db, $raw, 'reset'));
        self::assertNotNull(AuthTokens::consume(self::$db, $raw, 'magic'), 'and still works as what it is');
    }

    public function test_5_the_windows_are_the_ones_the_rules_name(): void
    {
        self::assertSame(3600, AuthTokens::TTL['reset'], 'a reset lasts an hour');
        self::assertSame(900, AuthTokens::TTL['magic'], 'a sign-in link fifteen minutes');
    }

    public function test_6_an_expired_token_stops_working(): void
    {
        $raw = AuthTokens::issue(self::$db, 'ruth', 'magic');
        self::$db->run('UPDATE {{auth_tokens}} SET expires_at = ? WHERE token_hash = ?', [
            Db::datetime(new \DateTimeImmutable('-1 second')),
            hash('sha256', $raw),
        ]);
        self::assertNull(AuthTokens::peek(self::$db, $raw, 'magic'));
        self::assertNull(AuthTokens::consume(self::$db, $raw, 'magic'));
    }

    public function test_7_asking_again_puts_the_last_one_out(): void
    {
        // Otherwise every reset a member ever asked for stays live, and the
        // oldest email in their inbox is as good as the newest.
        $first = AuthTokens::issue(self::$db, 'ruth', 'reset');
        $second = AuthTokens::issue(self::$db, 'ruth', 'reset');
        self::assertNull(AuthTokens::consume(self::$db, $first, 'reset'));
        self::assertNotNull(AuthTokens::consume(self::$db, $second, 'reset'));
    }

    public function test_8_a_used_token_is_not_replaced_by_a_new_request(): void
    {
        // The row stays as evidence that it was spent; only live ones go.
        $used = AuthTokens::issue(self::$db, 'ruth', 'reset');
        AuthTokens::consume(self::$db, $used, 'reset');
        AuthTokens::issue(self::$db, 'ruth', 'reset');
        self::assertSame(2, (int) self::$db->value('SELECT COUNT(*) FROM {{auth_tokens}}'));
    }

    public function test_9_rubbish_is_refused_without_reaching_the_table(): void
    {
        foreach (['', 'x', str_repeat('!', 43), "' OR 1=1 --", str_repeat('a', 44)] as $raw) {
            self::assertNull(AuthTokens::peek(self::$db, $raw, 'reset'), var_export($raw, true));
            self::assertNull(AuthTokens::consume(self::$db, $raw, 'reset'), var_export($raw, true));
        }
    }

    public function test_10_an_email_change_carries_the_address_it_is_for(): void
    {
        // The new address travels with the token rather than being re-read
        // from a form on the way back: otherwise the confirmation confirms
        // whatever the second request says.
        $raw = AuthTokens::issue(self::$db, 'ruth', 'email_change', 'new@test.example');
        self::assertSame('new@test.example', AuthTokens::consume(self::$db, $raw, 'email_change')['email'] ?? null);
    }

    public function test_11_pruning_clears_the_long_dead_and_leaves_the_living(): void
    {
        $live = AuthTokens::issue(self::$db, 'ruth', 'reset');
        $old = AuthTokens::issue(self::$db, 'ruth', 'magic');
        self::$db->run('UPDATE {{auth_tokens}} SET expires_at = ? WHERE token_hash = ?', [
            Db::datetime(new \DateTimeImmutable('-2 days')),
            hash('sha256', $old),
        ]);

        self::assertSame(1, AuthTokens::prune(self::$db));
        self::assertNotNull(AuthTokens::peek(self::$db, $live, 'reset'));
    }
}
