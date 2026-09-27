<?php

declare(strict_types=1);

namespace Tests\Unit\Access;

use App\Modules\Access\Passwords;
use PHPUnit\Framework\TestCase;

/**
 * The rules on a local password, and how one is stored.
 *
 * Local accounts exist because a church must be able to get in when the
 * identity provider is down, so these are the credentials most likely to be
 * the only ones. The length floor is doing most of the work; the common list
 * is short on purpose, and includes the ones a church picks.
 */
final class PasswordsTest extends TestCase
{
    public function test_1_it_hashes_with_argon2id_where_the_host_has_it(): void
    {
        if (!defined('PASSWORD_ARGON2ID')) {
            self::markTestSkipped('This PHP has no Argon2id; bcrypt at cost 12 is the fallback.');
        }
        self::assertSame(PASSWORD_ARGON2ID, Passwords::algorithm());
        self::assertStringStartsWith('$argon2id$', Passwords::hash('a good long password'));
    }

    public function test_2_a_hash_verifies_and_a_wrong_password_does_not(): void
    {
        $hash = Passwords::hash('correct horse battery staple');
        self::assertTrue(Passwords::verify('correct horse battery staple', $hash));
        self::assertFalse(Passwords::verify('Correct horse battery staple', $hash));
        self::assertFalse(Passwords::verify('', $hash));
    }

    public function test_3_verifying_against_nothing_is_false_rather_than_an_error(): void
    {
        // A member who has only ever signed in through a provider has no
        // hash at all; asking is normal and the answer is no.
        self::assertFalse(Passwords::verify('anything', null));
        self::assertFalse(Passwords::verify('anything', ''));
    }

    public function test_4_the_same_password_hashes_differently_every_time(): void
    {
        self::assertNotSame(Passwords::hash('a good long password'), Passwords::hash('a good long password'));
    }

    public function test_5_twelve_characters_is_the_floor(): void
    {
        self::assertNotNull(Passwords::problem('short'));
        self::assertNotNull(Passwords::problem(str_repeat('a', Passwords::MIN - 1)));
        self::assertNull(Passwords::problem('four words strung together'));
        self::assertSame(12, Passwords::MIN);
    }

    public function test_6_there_is_no_low_maximum(): void
    {
        // A passphrase manager generates long ones; refusing them teaches
        // people to pick worse passwords.
        self::assertNull(Passwords::problem(str_repeat('abcdefgh', 24)));
        self::assertGreaterThanOrEqual(200, Passwords::MAX);
    }

    public function test_7_the_commonest_are_refused_whatever_their_case(): void
    {
        foreach (['password1234', 'PASSWORD1234', 'Qwertyuiop12', 'jesuslovesme', 'churchchurch'] as $common) {
            self::assertNotNull(Passwords::problem($common), $common);
            self::assertStringContainsString('commonly used', (string) Passwords::problem($common), $common);
        }
    }

    public function test_8_a_long_password_of_almost_no_characters_is_refused(): void
    {
        // "aaaaaaaaaaaa" clears twelve characters and is not a password.
        self::assertNotNull(Passwords::problem('aaaaaaaaaaaaaaa'));
        self::assertNotNull(Passwords::problem('ababababababab'));
        self::assertNull(Passwords::problem('abcdefghijklm'));
    }

    public function test_9_the_members_own_address_is_refused_inside_it(): void
    {
        self::assertNotNull(Passwords::problem('ruthsmith-is-here', 'ruthsmith@example.org'));
        self::assertNotNull(Passwords::problem('XXRUTHSMITHXXXX', 'ruthsmith@example.org'));
        self::assertNull(Passwords::problem('a quite different phrase', 'ruthsmith@example.org'));
    }

    public function test_10_a_hash_made_by_the_current_rules_does_not_need_rehashing(): void
    {
        self::assertFalse(Passwords::needsRehash(Passwords::hash('a good long password')));
        // One from an older, weaker setting does.
        self::assertTrue(Passwords::needsRehash(password_hash('a good long password', PASSWORD_BCRYPT, ['cost' => 4])));
    }
}
