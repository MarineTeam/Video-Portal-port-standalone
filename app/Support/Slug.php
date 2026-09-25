<?php

declare(strict_types=1);

namespace App\Support;

final class Slug
{
    public const MAX = 80;

    /** A title as a URL segment: lower case, ASCII, dashes, no dash at either end. */
    public static function slugify(string $title): string
    {
        $text = self::fold($title);
        $text = strtolower($text);
        $text = (string) preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim($text, '-');
        if (strlen($text) > self::MAX) {
            $text = rtrim(substr($text, 0, self::MAX), '-');
        }
        return $text;
    }

    /** Strips accents but keeps the letter: "José" → "Jose". */
    public static function fold(string $text): string
    {
        if (class_exists(\Transliterator::class)) {
            $t = \Transliterator::create('Any-Latin; Latin-ASCII');
            if ($t !== null) {
                $out = $t->transliterate($text);
                if (is_string($out)) {
                    return $out;
                }
            }
        }
        $normalized = class_exists(\Normalizer::class) ? (string) \Normalizer::normalize($text, \Normalizer::FORM_D) : $text;
        $stripped = (string) preg_replace('/\p{Mn}+/u', '', $normalized);
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $stripped);
        return $ascii === false ? $stripped : $ascii;
    }

    /**
     * The slug itself when free, otherwise counted up — "easter-2", then
     * "easter-3" — so next year's reads like next year's.
     *
     * @param callable(string): bool $taken
     */
    public static function unique(string $title, callable $taken, string $fallback = 'item'): string
    {
        $base = self::slugify($title);
        if ($base === '') {
            $base = $fallback;
        }
        if (!$taken($base)) {
            return $base;
        }
        for ($n = 2; $n < 1000; $n++) {
            $suffix = '-' . $n;
            $candidate = rtrim(substr($base, 0, self::MAX - strlen($suffix)), '-') . $suffix;
            if (!$taken($candidate)) {
                return $candidate;
            }
        }
        return $base . '-' . substr(bin2hex(random_bytes(4)), 0, 6);
    }
}
