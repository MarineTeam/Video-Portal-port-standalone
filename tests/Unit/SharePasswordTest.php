<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Library\SharePassword;
use PHPUnit\Framework\TestCase;

/** lib/share-password.test.ts */
final class SharePasswordTest extends TestCase
{
    /** Made by Node's crypto.scryptSync (N=16384, r=8, p=1, 64 bytes), as the original stored it. */
    private const NODE_HASH = 'scrypt$a1b2c3d4e5f60718293a4b5c6d7e8f90$a42ef6427c4000c7a6b8841c3bb88ba6ca7cd76749466041ad860b14d6d4d4dd470b9cce87d922b473aa28c1e4bc5aac5a3dfcd92f5503eb330a9f7a280a03e9';

    public function test_accepts_the_right_password(): void
    {
        self::assertTrue(SharePassword::verify('open sesame', SharePassword::hash('open sesame')));
    }

    public function test_rejects_the_wrong_password(): void
    {
        self::assertFalse(SharePassword::verify('open sesamE', SharePassword::hash('open sesame')));
    }

    public function test_salts_each_hash_so_the_same_password_stores_differently_every_time(): void
    {
        self::assertNotSame(SharePassword::hash('open sesame'), SharePassword::hash('open sesame'));
    }

    public function test_treats_equivalent_unicode_spellings_as_the_same_password(): void
    {
        if (!class_exists(\Normalizer::class)) {
            self::markTestSkipped('The intl extension is not loaded.');
        }
        // "café" precomposed, and as e + combining acute.
        self::assertTrue(SharePassword::verify("cafe\u{0301}!!", SharePassword::hash("caf\u{00e9}!!")));
    }

    public function test_returns_false_rather_than_throwing_on_a_malformed_stored_value(): void
    {
        foreach (['', 'nonsense', 'scrypt$', 'scrypt$salt', 'scrypt$salt$zz', 'scrypt$$abcd', '$2y$10$short'] as $stored) {
            self::assertFalse(SharePassword::verify('open sesame', $stored), $stored);
        }
        self::assertFalse(SharePassword::verify('open sesame', null));
    }

    public function test_verifies_a_hash_imported_from_node_and_asks_for_a_rehash(): void
    {
        self::assertTrue(SharePassword::verify('correct horse', self::NODE_HASH));
        self::assertFalse(SharePassword::verify('correct horsE', self::NODE_HASH));
        self::assertTrue(SharePassword::needsRehash(self::NODE_HASH));
        self::assertFalse(SharePassword::needsRehash(SharePassword::hash('x')));
    }

    public function test_unlock_window(): void
    {
        $now = new \DateTimeImmutable('2026-09-25 12:00:00');
        self::assertFalse(SharePassword::isWithinUnlockWindow(null, $now), 'no failure at all');
        self::assertTrue(SharePassword::isWithinUnlockWindow($now->modify('-5 minutes'), $now), 'inside the window');
        self::assertFalse(SharePassword::isWithinUnlockWindow($now->modify('-16 minutes'), $now), 'aged out');
    }

    public function test_unlock_lockout(): void
    {
        $now = new \DateTimeImmutable('2026-09-25 12:00:00');
        $recent = $now->modify('-1 minute');
        self::assertFalse(SharePassword::isUnlockLockedOut(9, $recent, $now), 'below the threshold');
        self::assertTrue(SharePassword::isUnlockLockedOut(10, $recent, $now), 'at the threshold');
        self::assertFalse(SharePassword::isUnlockLockedOut(10, $now->modify('-20 minutes'), $now), 'forgiven after the window');
        self::assertFalse(SharePassword::isUnlockLockedOut(10, null, $now), 'a count with no time');
    }
}
