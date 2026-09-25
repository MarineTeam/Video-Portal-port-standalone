<?php

declare(strict_types=1);

namespace App\Support;

/**
 * scrypt (RFC 7914) in plain PHP, which has no native standard scrypt. It is
 * here for one job: verifying share-link passwords imported from the Next.js
 * deployment, which Node hashed with its defaults (N=16384, r=8, p=1). A
 * link is rehashed with password_hash() on its first successful unlock, so
 * this runs at most once per imported password.
 */
final class Scrypt
{
    public static function hash(string $password, string $salt, int $n = 16384, int $r = 8, int $p = 1, int $length = 64): string
    {
        if ($n < 2 || ($n & ($n - 1)) !== 0 || $r < 1 || $p < 1 || $length < 1) {
            throw new \InvalidArgumentException('Bad scrypt parameters.');
        }
        $blockSize = 128 * $r;
        $b = hash_pbkdf2('sha256', $password, $salt, 1, $p * $blockSize, true);
        $out = '';
        for ($i = 0; $i < $p; $i++) {
            $out .= self::roMix(substr($b, $i * $blockSize, $blockSize), $n, $r);
        }
        return hash_pbkdf2('sha256', $password, $out, 1, $length, true);
    }

    /** RFC 7914 §5: the sequential memory-hard mix, on 32-bit little-endian words. */
    private static function roMix(string $block, int $n, int $r): string
    {
        $words = 32 * $r;
        $x = array_values(unpack('V*', $block) ?: []);
        // V is N blocks of 128·r bytes: 16 MB at Node's defaults as packed
        // strings, where arrays of PHP integers would need eight times that.
        $v = [];
        for ($i = 0; $i < $n; $i++) {
            $v[$i] = pack('V*', ...$x);
            $x = self::blockMix($x, $r);
        }
        for ($i = 0; $i < $n; $i++) {
            $j = $x[$words - 16] & ($n - 1);
            $vj = array_values(unpack('V*', $v[$j]) ?: []);
            for ($k = 0; $k < $words; $k++) {
                $x[$k] ^= $vj[$k];
            }
            $x = self::blockMix($x, $r);
        }
        return pack('V*', ...$x);
    }

    /**
     * @param list<int> $b 32·r words
     * @return list<int>
     */
    private static function blockMix(array $b, int $r): array
    {
        $x = array_slice($b, (2 * $r - 1) * 16, 16);
        $even = [];
        $odd = [];
        for ($i = 0; $i < 2 * $r; $i++) {
            for ($k = 0; $k < 16; $k++) {
                $x[$k] ^= $b[$i * 16 + $k];
            }
            $x = self::salsa($x);
            if ($i % 2 === 0) {
                array_push($even, ...$x);
            } else {
                array_push($odd, ...$x);
            }
        }
        return array_merge($even, $odd);
    }

    /**
     * Salsa20/8 core.
     *
     * @param list<int> $in 16 words
     * @return list<int>
     */
    private static function salsa(array $in): array
    {
        [$x0, $x1, $x2, $x3, $x4, $x5, $x6, $x7, $x8, $x9, $x10, $x11, $x12, $x13, $x14, $x15] = $in;
        for ($i = 0; $i < 8; $i += 2) {
            $t = ($x0 + $x12) & 0xffffffff; $x4 ^= (($t << 7) | ($t >> 25)) & 0xffffffff;
            $t = ($x4 + $x0) & 0xffffffff; $x8 ^= (($t << 9) | ($t >> 23)) & 0xffffffff;
            $t = ($x8 + $x4) & 0xffffffff; $x12 ^= (($t << 13) | ($t >> 19)) & 0xffffffff;
            $t = ($x12 + $x8) & 0xffffffff; $x0 ^= (($t << 18) | ($t >> 14)) & 0xffffffff;
            $t = ($x5 + $x1) & 0xffffffff; $x9 ^= (($t << 7) | ($t >> 25)) & 0xffffffff;
            $t = ($x9 + $x5) & 0xffffffff; $x13 ^= (($t << 9) | ($t >> 23)) & 0xffffffff;
            $t = ($x13 + $x9) & 0xffffffff; $x1 ^= (($t << 13) | ($t >> 19)) & 0xffffffff;
            $t = ($x1 + $x13) & 0xffffffff; $x5 ^= (($t << 18) | ($t >> 14)) & 0xffffffff;
            $t = ($x10 + $x6) & 0xffffffff; $x14 ^= (($t << 7) | ($t >> 25)) & 0xffffffff;
            $t = ($x14 + $x10) & 0xffffffff; $x2 ^= (($t << 9) | ($t >> 23)) & 0xffffffff;
            $t = ($x2 + $x14) & 0xffffffff; $x6 ^= (($t << 13) | ($t >> 19)) & 0xffffffff;
            $t = ($x6 + $x2) & 0xffffffff; $x10 ^= (($t << 18) | ($t >> 14)) & 0xffffffff;
            $t = ($x15 + $x11) & 0xffffffff; $x3 ^= (($t << 7) | ($t >> 25)) & 0xffffffff;
            $t = ($x3 + $x15) & 0xffffffff; $x7 ^= (($t << 9) | ($t >> 23)) & 0xffffffff;
            $t = ($x7 + $x3) & 0xffffffff; $x11 ^= (($t << 13) | ($t >> 19)) & 0xffffffff;
            $t = ($x11 + $x7) & 0xffffffff; $x15 ^= (($t << 18) | ($t >> 14)) & 0xffffffff;
            $t = ($x0 + $x3) & 0xffffffff; $x1 ^= (($t << 7) | ($t >> 25)) & 0xffffffff;
            $t = ($x1 + $x0) & 0xffffffff; $x2 ^= (($t << 9) | ($t >> 23)) & 0xffffffff;
            $t = ($x2 + $x1) & 0xffffffff; $x3 ^= (($t << 13) | ($t >> 19)) & 0xffffffff;
            $t = ($x3 + $x2) & 0xffffffff; $x0 ^= (($t << 18) | ($t >> 14)) & 0xffffffff;
            $t = ($x5 + $x4) & 0xffffffff; $x6 ^= (($t << 7) | ($t >> 25)) & 0xffffffff;
            $t = ($x6 + $x5) & 0xffffffff; $x7 ^= (($t << 9) | ($t >> 23)) & 0xffffffff;
            $t = ($x7 + $x6) & 0xffffffff; $x4 ^= (($t << 13) | ($t >> 19)) & 0xffffffff;
            $t = ($x4 + $x7) & 0xffffffff; $x5 ^= (($t << 18) | ($t >> 14)) & 0xffffffff;
            $t = ($x10 + $x9) & 0xffffffff; $x11 ^= (($t << 7) | ($t >> 25)) & 0xffffffff;
            $t = ($x11 + $x10) & 0xffffffff; $x8 ^= (($t << 9) | ($t >> 23)) & 0xffffffff;
            $t = ($x8 + $x11) & 0xffffffff; $x9 ^= (($t << 13) | ($t >> 19)) & 0xffffffff;
            $t = ($x9 + $x8) & 0xffffffff; $x10 ^= (($t << 18) | ($t >> 14)) & 0xffffffff;
            $t = ($x15 + $x14) & 0xffffffff; $x12 ^= (($t << 7) | ($t >> 25)) & 0xffffffff;
            $t = ($x12 + $x15) & 0xffffffff; $x13 ^= (($t << 9) | ($t >> 23)) & 0xffffffff;
            $t = ($x13 + $x12) & 0xffffffff; $x14 ^= (($t << 13) | ($t >> 19)) & 0xffffffff;
            $t = ($x14 + $x13) & 0xffffffff; $x15 ^= (($t << 18) | ($t >> 14)) & 0xffffffff;
        }
        return [
            ($x0 + $in[0]) & 0xffffffff, ($x1 + $in[1]) & 0xffffffff, ($x2 + $in[2]) & 0xffffffff, ($x3 + $in[3]) & 0xffffffff,
            ($x4 + $in[4]) & 0xffffffff, ($x5 + $in[5]) & 0xffffffff, ($x6 + $in[6]) & 0xffffffff, ($x7 + $in[7]) & 0xffffffff,
            ($x8 + $in[8]) & 0xffffffff, ($x9 + $in[9]) & 0xffffffff, ($x10 + $in[10]) & 0xffffffff, ($x11 + $in[11]) & 0xffffffff,
            ($x12 + $in[12]) & 0xffffffff, ($x13 + $in[13]) & 0xffffffff, ($x14 + $in[14]) & 0xffffffff, ($x15 + $in[15]) & 0xffffffff,
        ];
    }
}
