<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Db;
use App\Core\Id;
use App\Core\Migrator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Url;

/**
 * What a session is, against a real database.
 *
 * The rules being pinned are the ones a reader of the security requirements
 * would want proof of: the cookie is never what the table holds, an id is
 * replaced on sign-in rather than reused, an old session stops working on
 * its own, and "sign out everywhere" means everywhere.
 */
final class SessionTest extends DatabaseTestCase
{
    private const PREFIX = 'sess_';
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
        self::$db->run('DELETE FROM {{sessions}}');
        // A session points at a real member: the table says so with a
        // foreign key, and a test that invented ids would not be testing
        // the same table the site uses.
        foreach (['user-1', 'user-2'] as $id) {
            self::$db->run(
                'INSERT INTO {{users}} (id, email, name, role, authorized) VALUES (?, ?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE email = VALUES(email)',
                [$id, "$id@test.example", $id, 'MEMBER'],
            );
        }
        Url::configure('https://church.example');
    }

    /** @param array<string, string> $cookies */
    private static function request(array $cookies = [], bool $https = true): Request
    {
        return new Request('GET', '/', cookies: $cookies, https: $https, host: 'church.example', id: 'test');
    }

    /** Signs somebody in and hands back the cookie their browser would keep. */
    private function signIn(string $userId): string
    {
        $session = new Session(self::$db, self::request());
        $session->start();
        $session->login($userId);
        $response = new Response();
        $session->commit($response);
        return (string) $session->id();
    }

    /** Every Set-Cookie the response would send, as one string to look in. */
    private static function cookieHeader(Response $response): string
    {
        return implode("\n", $response->cookies);
    }

    public function test_1_the_table_holds_a_hash_and_never_the_cookie(): void
    {
        $raw = $this->signIn('user-1');
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $raw, '32 random bytes, base64url');

        $rows = self::$db->all('SELECT id_hash, user_id FROM {{sessions}}');
        self::assertCount(1, $rows);
        self::assertSame(hash('sha256', $raw), $rows[0]['id_hash']);
        // The point: a database read does not yield a usable session.
        self::assertNotSame($raw, $rows[0]['id_hash']);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM {{sessions}} WHERE id_hash = ?', [$raw]));
    }

    public function test_2_the_cookie_it_sets_carries_the_flags_the_rules_ask_for(): void
    {
        $session = new Session(self::$db, self::request());
        $session->start();
        $session->login('user-1');
        $response = new Response();
        $session->commit($response);

        $cookie = self::cookieHeader($response);
        self::assertStringContainsString('HttpOnly', $cookie);
        self::assertStringContainsString('SameSite=Lax', $cookie);
        self::assertStringContainsString('Secure', $cookie);
        self::assertStringContainsString('__Host-mt_session=', $cookie, 'at a domain root over HTTPS');
    }

    public function test_3_the_host_prefix_is_dropped_where_it_would_be_a_lie(): void
    {
        // __Host- means path / and Secure. A site in a subfolder, or one on
        // plain HTTP, cannot honestly claim it.
        self::assertSame('__Host-mt_session', Session::cookieName(true));
        self::assertSame('mt_session', Session::cookieName(false));
        Url::configure('https://church.example/church');
        self::assertSame('mt_session', Session::cookieName(true));
    }

    public function test_4_signing_in_replaces_the_id_rather_than_keeping_it(): void
    {
        // A session id a visitor arrived with must not survive the sign-in:
        // otherwise anybody who planted it is now signed in as them.
        $before = new Session(self::$db, self::request());
        $before->start();
        $before->ensure();
        $before->set('returnTo', '/watch/one');
        $before->commit(new Response());
        $first = (string) $before->id();

        $session = new Session(self::$db, self::request([Session::cookieName(true) => $first]));
        $session->start();
        $session->login('user-1');
        $session->commit(new Response());

        self::assertNotSame($first, $session->id());
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM {{sessions}} WHERE id_hash = ?', [hash('sha256', $first)]), 'and the old row is gone');
        self::assertSame('/watch/one', $session->get('returnTo'), 'while what the visitor was doing survives');
    }

    public function test_5_a_new_session_gets_a_new_csrf_token(): void
    {
        $session = new Session(self::$db, self::request());
        $session->start();
        $session->ensure();
        $session->set('_csrf', 'a-token-from-before');
        $session->login('user-1');
        self::assertNull($session->get('_csrf'), 'a token minted before the sign-in is not the token after it');
    }

    public function test_6_a_cookie_pointing_at_nothing_signs_nobody_in(): void
    {
        foreach ([Id::token(32), 'not-a-session-id', str_repeat('x', 43)] as $raw) {
            $session = new Session(self::$db, self::request([Session::cookieName(true) => $raw]));
            $session->start();
            self::assertNull($session->userId(), $raw);
            self::assertNull($session->id());
        }
    }

    public function test_7_a_session_dies_of_old_age_and_of_being_left_alone(): void
    {
        foreach ([
            'absolute' => ['created' => Session::ABSOLUTE + 60, 'seen' => 0],
            'idle' => ['created' => 0, 'seen' => Session::IDLE + 60],
        ] as $why => $ago) {
            $raw = $this->signIn('user-1');
            self::$db->update('sessions', [
                'created_at' => Db::datetime(new \DateTimeImmutable("-{$ago['created']} seconds")),
                'last_seen_at' => Db::datetime(new \DateTimeImmutable("-{$ago['seen']} seconds")),
            ], ['id_hash' => hash('sha256', $raw)]);

            $session = new Session(self::$db, self::request([Session::cookieName(true) => $raw]));
            $session->start();
            self::assertNull($session->userId(), $why);
            // And it is cleared out rather than left to be tried again.
            self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM {{sessions}} WHERE id_hash = ?', [hash('sha256', $raw)]), $why);
        }
    }

    public function test_8_a_session_inside_both_windows_still_works(): void
    {
        $raw = $this->signIn('user-1');
        self::$db->update('sessions', [
            'created_at' => Db::datetime(new \DateTimeImmutable('-29 days')),
            'last_seen_at' => Db::datetime(new \DateTimeImmutable('-6 days')),
        ], ['id_hash' => hash('sha256', $raw)]);

        $session = new Session(self::$db, self::request([Session::cookieName(true) => $raw]));
        $session->start();
        self::assertSame('user-1', $session->userId());
    }

    public function test_9_signing_out_everywhere_is_everywhere(): void
    {
        $phone = $this->signIn('user-1');
        $laptop = $this->signIn('user-1');
        $somebodyElse = $this->signIn('user-2');
        self::assertSame(3, (int) self::$db->value('SELECT COUNT(*) FROM {{sessions}}'));

        $session = new Session(self::$db, self::request([Session::cookieName(true) => $laptop]));
        $session->start();
        $session->destroyAllFor('user-1');

        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM {{sessions}} WHERE user_id = ?', ['user-1']));
        self::assertNull($session->userId(), 'including the one asking');
        foreach ([$phone, $laptop] as $gone) {
            $after = new Session(self::$db, self::request([Session::cookieName(true) => $gone]));
            $after->start();
            self::assertNull($after->userId());
        }
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM {{sessions}} WHERE user_id = ?', ['user-2']), 'and nobody else is touched');
    }

    public function test_10_signing_out_forgets_the_cookie_as_well_as_the_row(): void
    {
        $raw = $this->signIn('user-1');
        $session = new Session(self::$db, self::request([Session::cookieName(true) => $raw]));
        $session->start();
        $session->logout();
        $response = new Response();
        $session->commit($response);

        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM {{sessions}}'));
        $cookie = self::cookieHeader($response);
        self::assertStringContainsString('__Host-mt_session=', $cookie);
        self::assertMatchesRegularExpression('/Max-Age=0|Expires=Thu, 01 Jan 1970/i', $cookie, 'the browser is told to drop it');
    }

    public function test_11_two_sessions_are_never_the_same_session(): void
    {
        $ids = [];
        for ($i = 0; $i < 25; $i++) {
            $ids[] = $this->signIn('user-1');
        }
        self::assertCount(25, array_unique($ids));
    }
}
