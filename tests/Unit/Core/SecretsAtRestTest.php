<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Crypto;
use PHPUnit\Framework\TestCase;

/**
 * Every provider secret the site stores goes through this.
 *
 * The threat it exists for is shared hosting: the database is often on a
 * machine shared with strangers, and a backup is a file somebody emails
 * themselves. A row read out of either must not yield a working key, which
 * is why the key lives in storage/config.php and never in the database.
 */
final class SecretsAtRestTest extends TestCase
{
    protected function setUp(): void
    {
        Crypto::configure(Crypto::generateAppKey());
    }

    public function test_1_a_secret_comes_back_exactly(): void
    {
        foreach (['', 'k', 'SG.abc-123_DEF', str_repeat('x', 10000), "line\nbreak\ttab", '🔑 unicode'] as $secret) {
            self::assertSame($secret, Crypto::decrypt(Crypto::encrypt($secret)), var_export($secret, true));
        }
    }

    public function test_2_the_stored_form_does_not_contain_the_secret(): void
    {
        $stored = Crypto::encrypt('super-secret-api-key');
        self::assertStringNotContainsString('super-secret-api-key', $stored);
        self::assertStringNotContainsString('super-secret-api-key', (string) base64_decode(substr($stored, 3), true));
    }

    public function test_3_the_same_secret_stores_differently_every_time(): void
    {
        // A fresh nonce per value: otherwise two providers sharing a key
        // would be visibly sharing it, and a changed value would be visible
        // as a change even to somebody who cannot read either.
        $first = Crypto::encrypt('same');
        $second = Crypto::encrypt('same');
        self::assertNotSame($first, $second);
        self::assertSame('same', Crypto::decrypt($first));
        self::assertSame('same', Crypto::decrypt($second));
    }

    public function test_4_a_secret_stored_under_another_key_does_not_open(): void
    {
        // The case that matters: a database restored without config.php.
        $stored = Crypto::encrypt('a twilio token');
        Crypto::configure(Crypto::generateAppKey());
        self::assertNull(Crypto::decrypt($stored), 'the rows are useless without the key file');
    }

    public function test_5_a_tampered_value_is_refused_rather_than_half_read(): void
    {
        $stored = Crypto::encrypt('a twilio token');
        $raw = (string) base64_decode(substr($stored, 3), true);

        // Flip a bit in the ciphertext, then in the tag, then in the nonce.
        foreach ([28, 12, 0] as $at) {
            $broken = $raw;
            $broken[$at] = chr(ord($broken[$at]) ^ 0x01);
            self::assertNull(Crypto::decrypt('v1:' . base64_encode($broken)), "byte $at");
        }
    }

    public function test_6_rubbish_is_refused_rather_than_thrown(): void
    {
        // These reach decrypt() from a database anybody may have edited by
        // hand, so it answers null rather than dying.
        foreach (['', 'plaintext', 'v1:', 'v1:!!!not base64!!!', 'v1:' . base64_encode('short'), 'v2:' . base64_encode(random_bytes(40))] as $stored) {
            self::assertNull(Crypto::decrypt($stored), var_export($stored, true));
        }
    }

    public function test_7_a_signature_is_bound_to_its_purpose_and_expires(): void
    {
        $token = Crypto::sign('service-switch', ['slot' => 'email'], 900);
        self::assertSame('email', Crypto::verify('service-switch', $token)['slot'] ?? null);
        // A token minted for one thing must not be spendable on another.
        self::assertNull(Crypto::verify('password-reset', $token));
        self::assertNull(Crypto::verify('service-switch', $token . 'x'));
        self::assertNull(Crypto::verify('service-switch', Crypto::sign('service-switch', ['slot' => 'email'], -1)));
    }

    public function test_8_an_hmac_is_stable_and_purpose_bound(): void
    {
        // The view throttle keys off this: the same address must give the
        // same key all day, and must not collide with another purpose's.
        self::assertSame(Crypto::hmac('views', '203.0.113.9'), Crypto::hmac('views', '203.0.113.9'));
        self::assertNotSame(Crypto::hmac('views', '203.0.113.9'), Crypto::hmac('downloads', '203.0.113.9'));
        self::assertNotSame(Crypto::hmac('views', '203.0.113.9'), Crypto::hmac('views', '203.0.113.10'));
    }
}
