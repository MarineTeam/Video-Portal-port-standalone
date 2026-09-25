<?php

declare(strict_types=1);

namespace App\Core;

/**
 * cuid-shaped ids: 'c' followed by 24 lowercase base-36 characters, the same
 * shape as the 25-character ids imported from the original deployment, so an
 * imported row and a new one are indistinguishable and a string column is
 * never asked to hold anything else.
 *
 * Time comes first (so ids made later sort later, which keeps InnoDB's
 * clustered index append-mostly), then a per-process counter, then randomness.
 */
final class Id
{
    private static int $counter = -1;

    public static function new(): string
    {
        if (self::$counter < 0) {
            self::$counter = random_int(0, 36 ** 4 - 1);
        }
        self::$counter = (self::$counter + 1) % (36 ** 4);

        $time = str_pad(base_convert((string) (int) floor(microtime(true) * 1000), 10, 36), 8, '0', STR_PAD_LEFT);
        $count = str_pad(base_convert((string) self::$counter, 10, 36), 4, '0', STR_PAD_LEFT);
        return 'c' . substr($time, -8) . $count . self::random(12);
    }

    /** A random lowercase base-36 string, from the CSPRNG. */
    public static function random(int $length): string
    {
        $alphabet = '0123456789abcdefghijklmnopqrstuvwxyz';
        $out = '';
        while (strlen($out) < $length) {
            foreach (str_split(random_bytes($length)) as $byte) {
                $n = ord($byte);
                if ($n < 252) { // 252 = 7 * 36: reject the tail so every character is equally likely
                    $out .= $alphabet[$n % 36];
                }
            }
        }
        return substr($out, 0, $length);
    }

    /** Whether a value is shaped like an id this app issues or imports. */
    public static function isValid(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-z0-9]{8,32}$/', $value) === 1;
    }

    /** A url-safe random token: $bytes of entropy, base64url, no padding. */
    public static function token(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
