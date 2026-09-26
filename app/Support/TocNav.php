<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Moving about a book's contents (lib/toc-nav.ts).
 *
 * The reader's next and previous buttons move by *entry*, not by page: on a
 * hymnal that is the next hymn, which is what somebody standing at a piano
 * means by next. The rules that look fussy here are the ones that make it
 * feel like a track skip on a music player rather than a page turn.
 */
final class TocNav
{
    /**
     * Which entry the page being read falls inside.
     *
     * The one it falls inside, not the next one — a reader on page 44 of a
     * hymn that starts on 42 is reading that hymn.
     *
     * @param list<array<string, mixed>> $entries
     */
    public static function currentTocIndex(array $entries, ?int $page): ?int
    {
        if ($page === null) {
            return null;
        }
        $best = null;
        foreach ($entries as $index => $entry) {
            $at = self::pageOf($entry);
            if ($at === null || $at > $page) {
                continue;
            }
            // Two entries starting in the same place: the later one, which is
            // the hymn rather than the section heading above it.
            if ($best === null || $at >= self::pageOf($entries[$best])) {
                $best = $index;
            }
        }
        return $best;
    }

    /**
     * The first entry after here.
     *
     * Every entry sharing this position is stepped past, so pressing next
     * always moves — two hymns printed on one page are not a trap.
     *
     * @param list<array<string, mixed>> $entries
     */
    public static function nextTocIndex(array $entries, ?int $page): ?int
    {
        $ordered = self::ordered($entries);
        foreach ($ordered as [$index, $at]) {
            if ($page === null || $at > $page) {
                return $index;
            }
        }
        // Stops at the last entry rather than wrapping.
        return $ordered === [] ? null : $ordered[count($ordered) - 1][0];
    }

    /**
     * Back to the start of the entry being read, the way a track skip does —
     * and to the one before it when already at the start.
     *
     * @param list<array<string, mixed>> $entries
     */
    public static function previousTocIndex(array $entries, ?int $page): ?int
    {
        $ordered = self::ordered($entries);
        if ($ordered === []) {
            return null;
        }
        if ($page === null) {
            return $ordered[0][0];
        }
        $here = self::currentTocIndex($entries, $page);
        if ($here !== null && self::pageOf($entries[$here]) < $page) {
            // Part-way through: go back to where this one started.
            return $here;
        }
        $previous = null;
        foreach ($ordered as [$index, $at]) {
            if ($at < $page) {
                $previous = $index;
            }
        }
        return $previous ?? $ordered[0][0];
    }

    /**
     * The number a hymnal's own bookmarks put in front of the title.
     *
     * In front of it, not inside it: "Psalm 23" is not hymn 23, and a title
     * beginning "119. " is hymn 119 rather than hymn 1.
     */
    public static function hymnNumberOf(string $title): ?int
    {
        if (preg_match('/^\s*(\d{1,4})\s*[.):\-\x{2013}\x{2014}]?\s+\S/u', $title, $m) !== 1) {
            return null;
        }
        $number = (int) $m[1];
        // No hymnal prints a hymn zero.
        return $number >= 1 ? $number : null;
    }

    /**
     * The entry printed under a number.
     *
     * @param list<array<string, mixed>> $entries
     */
    public static function findHymnIndex(array $entries, int $number): ?int
    {
        foreach ($entries as $index => $entry) {
            $entryNumber = $entry['number'] ?? null;
            $found = is_int($entryNumber) || (is_string($entryNumber) && $entryNumber !== '')
                ? (int) $entryNumber
                : self::hymnNumberOf((string) ($entry['title'] ?? ''));
            if ($found === $number) {
                return $index;
            }
        }
        return null;
    }

    /**
     * The entries that can be placed, in page order, as [index, page] pairs.
     * One that cannot be placed is skipped rather than treated as page zero.
     *
     * @param list<array<string, mixed>> $entries
     * @return list<array{0: int, 1: int}>
     */
    private static function ordered(array $entries): array
    {
        $out = [];
        foreach ($entries as $index => $entry) {
            $at = self::pageOf($entry);
            if ($at !== null) {
                $out[] = [$index, $at];
            }
        }
        usort($out, fn (array $a, array $b) => [$a[1], $a[0]] <=> [$b[1], $b[0]]);
        return $out;
    }

    /** @param array<string, mixed> $entry */
    private static function pageOf(array $entry): ?int
    {
        $page = $entry['page'] ?? null;
        return is_int($page) || (is_string($page) && preg_match('/^\d+$/', $page) === 1) ? (int) $page : null;
    }
}
