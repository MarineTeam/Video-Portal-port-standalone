<?php

declare(strict_types=1);

namespace MarineTeam\Plugins\Schedules;

/**
 * Names off a spreadsheet (the original's lib/names.ts). A rota is typed
 * by people, so the same person arrives as "DAVE", "dave" and "Dave  " in
 * the same sheet; the normalized form is what makes those one person, and
 * the display form is what a page shows.
 */
final class Names
{
    /** The separators a cell of names is split on. */
    public const SEPARATORS = [',', '/', '&', ';', '+', ' and ', "\n"];
    /** Longer than any name anybody types. */
    public const MAX = 120;

    /** The matching key: lower case, whitespace collapsed, accents folded. */
    public static function normalizeName(string $name): string
    {
        $text = \App\Support\Slug::fold(trim($name));
        // Typographic apostrophes and dashes are the same characters as
        // their plain forms, as far as who somebody is goes.
        $text = str_replace(['’', '‘', '‛', '´', '`'], "'", $text);
        $text = (string) preg_replace('/[\x{2010}-\x{2015}]/u', '-', $text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        $text = mb_strtolower(trim($text));
        return (string) preg_replace('/[^a-z0-9 \'\-]/u', '', $text);
    }

    /**
     * The spelling to show: a deliberate mixed-case spelling is kept, and
     * the spreadsheet artefacts (ALL CAPS, all lower) are title-cased.
     */
    public static function toDisplayName(string $name): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $name));
        if ($text === '') {
            return '';
        }
        $letters = (string) preg_replace('/[^\p{L}]/u', '', $text);
        $mixed = $letters !== '' && $letters !== mb_strtoupper($letters) && $letters !== mb_strtolower($letters);
        if ($mixed) {
            // Somebody wrote "McDonald" or "de Vries" on purpose.
            return $text;
        }
        return (string) preg_replace_callback(
            '/(^|[\s\'\-])(\p{L})/u',
            fn (array $m) => $m[1] . mb_strtoupper($m[2]),
            mb_strtolower($text),
        );
    }

    /**
     * One cell of names as a list, deduplicated on the normalized form.
     *
     * @param list<string>|null $separators
     * @return list<string>
     */
    public static function splitNames(string $cell, ?array $separators = null): array
    {
        $parts = [$cell];
        foreach ($separators ?? self::SEPARATORS as $separator) {
            if ($separator === '') {
                continue;
            }
            $next = [];
            foreach ($parts as $part) {
                array_push($next, ...explode($separator, $part));
            }
            $parts = $next;
        }
        $out = [];
        $seen = [];
        foreach ($parts as $part) {
            $display = self::toDisplayName($part);
            $key = self::normalizeName($part);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $display;
        }
        return $out;
    }

    /**
     * Whether a cell is a name at all: "×", "yes", "Notes" and a date are
     * not people, and neither is something the length of a sentence.
     */
    public static function isPlausibleName(string $value): bool
    {
        $text = trim($value);
        if ($text === '' || mb_strlen($text) > self::MAX) {
            return false;
        }
        if (preg_match('/\d/u', $text)) {
            return false;
        }
        $key = self::normalizeName($text);
        return $key !== '' && str_word_count($key, 0, "'-") <= 6;
    }
}
