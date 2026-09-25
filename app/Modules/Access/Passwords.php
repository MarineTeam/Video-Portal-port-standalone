<?php

declare(strict_types=1);

namespace App\Modules\Access;

/**
 * Argon2id where the host has it, bcrypt at cost 12 otherwise; rehashed on
 * sign-in when a better algorithm becomes available. At least twelve
 * characters, not one of the commonest, no maximum under 200.
 */
final class Passwords
{
    public const MIN = 12;
    public const MAX = 1024;

    private const COMMON = [
        '123456789012', '1234567890123', 'password1234', 'passwordpassword', 'qwertyuiop12', 'qwertyuiopas',
        '111111111111', '000000000000', 'iloveyou1234', 'letmein12345', 'welcome12345', 'administrator',
        'abcdefghijkl', 'abc123abc123', 'changeme1234', 'password123!', 'Password1234', 'jesuslovesme',
        'godisgood123', 'blessedbeyond', 'praisethelord', 'hallelujah12', 'churchchurch', '1q2w3e4r5t6y',
    ];

    public static function algorithm(): string|int
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    /** @return array<string, int> */
    private static function options(): array
    {
        return self::algorithm() === PASSWORD_BCRYPT ? ['cost' => 12] : [];
    }

    public static function hash(string $password): string
    {
        return password_hash($password, self::algorithm(), self::options());
    }

    public static function verify(string $password, ?string $hash): bool
    {
        if ($hash === null || $hash === '') {
            // Spend the time anyway, so a missing account answers as slowly as a real one.
            password_verify($password, self::hash('timing-equaliser'));
            return false;
        }
        return password_verify($password, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::algorithm(), self::options());
    }

    /** A sentence explaining what is wrong with a new password, or null. */
    public static function problem(string $password, ?string $email = null): ?string
    {
        $length = mb_strlen($password);
        if ($length < self::MIN) {
            return 'Use at least ' . self::MIN . ' characters. A few ordinary words strung together is easy to remember and hard to guess.';
        }
        if ($length > self::MAX) {
            return 'That password is too long.';
        }
        $lower = mb_strtolower($password);
        foreach (self::COMMON as $common) {
            if ($lower === mb_strtolower($common)) {
                return 'That password is one of the most commonly used. Choose another.';
            }
        }
        if (count(array_unique(mb_str_split($password))) < 4) {
            return 'That password repeats too few characters. Choose another.';
        }
        if ($email !== null && str_contains($lower, mb_strtolower((string) strstr($email, '@', true)))) {
            return 'Don’t include your email address in your password.';
        }
        return null;
    }
}
