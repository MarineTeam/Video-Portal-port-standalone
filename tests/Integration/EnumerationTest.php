<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Id;

/**
 * Whether the site will tell a stranger who is a member.
 *
 * A church's membership list is not public information, and half the
 * endpoints here take an address: the reset form, the sign-in link, the
 * login itself, registration. Each must answer a member and a stranger the
 * same way, and take about as long doing it — a page that is quick for one
 * and slow for the other is the same answer, said differently.
 */
final class EnumerationTest extends ServerTestCase
{
    private const KNOWN = 'ruth@test.example';
    private const UNKNOWN = 'nobody-at-all@test.example';

    protected static function prefix(): string
    {
        return 'enum_';
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::install();
        $db = self::connect(self::prefix());
        $db->insert('services', [
            'id' => Id::new(), 'slot' => 'email', 'provider' => 'log', 'active' => 1, 'config' => '{}',
        ]);
        self::flushCache();
        self::member(self::KNOWN, 'ruth');
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::connect(self::prefix())->run('DELETE FROM {{rate_limits}}');
    }

    /** @return array{status: int, body: string} */
    private static function post(string $path, array $body): array
    {
        $answer = self::http('POST', $path, ['_csrf' => self::csrf($path, 'guest')] + $body, 'guest');
        return ['status' => $answer['status'], 'body' => (string) $answer['body']];
    }

    /**
     * A page with the things that differ on every response taken out: the
     * CSP nonce and the CSRF token. What is left is what the page said.
     */
    private static function sameness(string $body, string $address): string
    {
        return (string) preg_replace(
            ['/nonce="[^"]*"/', '/name="_csrf" value="[^"]*"/', '/content="[A-Za-z0-9_-]{20,}"/'],
            ['nonce="N"', 'csrf="T"', 'content="T"'],
            str_replace($address, 'ADDRESS', $body),
        );
    }

    public function test_1_the_reset_form_says_the_same_thing_either_way(): void
    {
        $known = self::post('/auth/reset', ['email' => self::KNOWN]);
        $unknown = self::post('/auth/reset', ['email' => self::UNKNOWN]);

        self::assertSame($known['status'], $unknown['status']);
        // The only difference allowed is the address echoed back, which the
        // caller already knew.
        self::assertSame(
            self::sameness($known['body'], self::KNOWN),
            self::sameness($unknown['body'], self::UNKNOWN),
        );
    }

    public function test_2_the_sign_in_link_says_the_same_thing_either_way(): void
    {
        $known = self::post('/auth/magic', ['email' => self::KNOWN]);
        $unknown = self::post('/auth/magic', ['email' => self::UNKNOWN]);

        self::assertSame($known['status'], $unknown['status']);
        self::assertSame(
            self::sameness($known['body'], self::KNOWN),
            self::sameness($unknown['body'], self::UNKNOWN),
        );
    }

    public function test_3_only_the_member_actually_gets_a_message(): void
    {
        // Saying the same thing is not the same as doing the same thing.
        $db = self::connect(self::prefix());
        $db->run('DELETE FROM {{email_log}}');
        self::post('/auth/reset', ['email' => self::UNKNOWN]);
        self::assertSame(0, (int) $db->value('SELECT COUNT(*) FROM {{email_log}}'));

        self::post('/auth/reset', ['email' => self::KNOWN]);
        self::assertSame(1, (int) $db->value('SELECT COUNT(*) FROM {{email_log}} WHERE to_address = ?', [self::KNOWN]));
    }

    public function test_4_a_wrong_password_and_an_unknown_address_read_alike(): void
    {
        $wrongPassword = self::post('/auth/login', ['email' => self::KNOWN, 'password' => 'not the password']);
        $noSuchMember = self::post('/auth/login', ['email' => self::UNKNOWN, 'password' => 'not the password']);

        self::assertSame($wrongPassword['status'], $noSuchMember['status']);
        self::assertSame(
            self::sameness($wrongPassword['body'], self::KNOWN),
            self::sameness($noSuchMember['body'], self::UNKNOWN),
        );
    }

    public function test_5_neither_answer_is_quick_enough_to_tell_them_apart(): void
    {
        // A member's request hashes a password or issues a token; a
        // stranger's must not simply return. The handler sleeps for a random
        // 150-400ms instead, so the two overlap.
        $time = static function (callable $call): float {
            $at = microtime(true);
            $call();
            return microtime(true) - $at;
        };
        $db = self::connect(self::prefix());

        $known = [];
        $unknown = [];
        for ($i = 0; $i < 3; $i++) {
            $db->run('DELETE FROM {{rate_limits}}');
            $known[] = $time(fn () => self::post('/auth/reset', ['email' => self::KNOWN]));
            $db->run('DELETE FROM {{rate_limits}}');
            $unknown[] = $time(fn () => self::post('/auth/reset', ['email' => self::UNKNOWN]));
        }
        // Not a timing proof — a test cannot be one — but the obvious
        // giveaway is a stranger's answer coming back instantly.
        self::assertGreaterThan(0.1, min($unknown), 'an unknown address is not answered instantly');
        self::assertLessThan(
            max(min($known), min($unknown)) * 8,
            max(max($known), max($unknown)),
            'and the two are the same order of magnitude',
        );
    }

    public function test_6_registration_does_not_say_who_is_already_a_member(): void
    {
        $existing = self::post('/auth/register', ['email' => self::KNOWN, 'name' => 'Ruth', 'password' => 'a long enough password']);
        $fresh = self::post('/auth/register', ['email' => 'brand-new@test.example', 'name' => 'New', 'password' => 'a long enough password']);

        self::assertSame($existing['status'], $fresh['status']);
        self::assertSame(
            self::sameness($existing['body'], self::KNOWN),
            self::sameness($fresh['body'], 'brand-new@test.example'),
        );
    }
}
