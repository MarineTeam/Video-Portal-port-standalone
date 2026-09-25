<?php

declare(strict_types=1);

namespace App\Modules\Library;

use App\Support\Scrypt;

/**
 * A share link's optional passphrase (share-password.ts). New passwords
 * use password_hash(); links imported from the Next.js deployment carry
 * Node's "scrypt$salt$key", verified by the vendored scrypt and rehashed on
 * the first successful unlock. Passwords are NFC-normalized first, so the
 * same word typed on two keyboards is the same password. The hash never
 * leaves the server. Ten wrong guesses inside fifteen minutes lock the link
 * for fifteen minutes, counted on the row.
 */
final class SharePassword
{
    public const LOCKOUT_THRESHOLD = 10;
    public const WINDOW_SECONDS = 15 * 60;

    private static function normalize(string $password): string
    {
        if (class_exists(\Normalizer::class)) {
            $n = \Normalizer::normalize($password, \Normalizer::FORM_C);
            if (is_string($n)) {
                return $n;
            }
        }
        return $password;
    }

    public static function hash(string $password): string
    {
        return password_hash(self::normalize($password), PASSWORD_DEFAULT);
    }

    /** Never throws: a malformed stored value is simply not a match. */
    public static function verify(string $password, ?string $stored): bool
    {
        if ($stored === null || $stored === '') {
            return false;
        }
        $password = self::normalize($password);
        if (str_starts_with($stored, 'scrypt$')) {
            $parts = explode('$', $stored);
            if (count($parts) !== 3 || $parts[1] === '' || $parts[2] === '') {
                return false;
            }
            [, $salt, $key] = $parts;
            $expected = ctype_xdigit($key) && strlen($key) % 2 === 0 ? hex2bin($key) : base64_decode(strtr($key, '-_', '+/'), true);
            if (!is_string($expected) || strlen($expected) < 16 || strlen($expected) > 128) {
                return false;
            }
            try {
                // Node was handed the salt as the string it stores; accept a decoded hex salt too.
                foreach (array_unique([$salt, ctype_xdigit($salt) && strlen($salt) % 2 === 0 ? (string) hex2bin($salt) : $salt]) as $s) {
                    if (hash_equals($expected, Scrypt::hash($password, $s, 16384, 8, 1, strlen($expected)))) {
                        return true;
                    }
                }
            } catch (\Throwable) {
                return false;
            }
            return false;
        }
        return password_verify($password, $stored);
    }

    public static function needsRehash(string $stored): bool
    {
        return str_starts_with($stored, 'scrypt$') || password_needs_rehash($stored, PASSWORD_DEFAULT);
    }

    public static function isWithinUnlockWindow(?\DateTimeImmutable $lastFailedAt, \DateTimeImmutable $now): bool
    {
        return $lastFailedAt !== null && $now->getTimestamp() - $lastFailedAt->getTimestamp() < self::WINDOW_SECONDS;
    }

    public static function isUnlockLockedOut(int $failedAttempts, ?\DateTimeImmutable $lastFailedAt, \DateTimeImmutable $now): bool
    {
        return $failedAttempts >= self::LOCKOUT_THRESHOLD && self::isWithinUnlockWindow($lastFailedAt, $now);
    }
}
