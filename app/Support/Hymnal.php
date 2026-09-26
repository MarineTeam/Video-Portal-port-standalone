<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A hymnal's own order and its fingerprint (lib/hymnal.ts, lib/fingerprint.ts).
 *
 * A hymn-per-file book has no document to open: its "pages" are file rows
 * with lyrics on them. A book with a document has a contents list instead.
 * Both are read the same way by somebody standing at a piano — by the number
 * printed in the book — which is what reading order is for.
 */
final class Hymnal
{
    /**
     * The book in printed order.
     *
     * A hymn with no number printed on it goes at the end rather than at the
     * front, where a missing number would otherwise sort it: a hymnal's
     * appendix is not its first page. The array it is given is left alone.
     *
     * @param list<array<string, mixed>> $hymns
     * @return list<array<string, mixed>>
     */
    public static function readingOrder(array $hymns): array
    {
        $keyed = [];
        foreach ($hymns as $index => $hymn) {
            $number = self::numberOf($hymn);
            $keyed[] = [
                // Unnumbered last, and otherwise by the printed number; the
                // original index keeps two hymns on one page in their order.
                $number === null ? 1 : 0,
                $number ?? 0,
                $index,
                $hymn,
            ];
        }
        usort($keyed, fn (array $a, array $b) => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);
        return array_map(fn (array $row) => $row[3], $keyed);
    }

    /**
     * A token for "is the book I saved still the book".
     *
     * The count is in it, so a hash collision cannot read as unchanged, and
     * the words are in it, so correcting a typo in verse three is a change —
     * which is the point: a device holding the old words would sing them.
     *
     * @param list<array<string, mixed>> $hymns
     */
    public static function fingerprint(array $hymns): string
    {
        $rows = [];
        foreach (self::readingOrder($hymns) as $hymn) {
            $rows[] = implode("\x1f", [
                (string) (self::numberOf($hymn) ?? ''),
                trim((string) ($hymn['title'] ?? '')),
                trim((string) ($hymn['lyrics'] ?? $hymn['lyrics_text'] ?? '')),
            ]);
        }
        return count($rows) . '-' . substr(hash('sha256', implode("\x1e", $rows)), 0, 32);
    }

    /**
     * Where a file belongs: a hymn in a hymn-per-file book goes to its
     * lyrics page, a book with a document to its contents, and anything else
     * nowhere at all.
     *
     * @param array<string, mixed> $file
     */
    public static function href(array $file): ?string
    {
        $id = (string) ($file['id'] ?? '');
        if ($id === '') {
            return null;
        }
        if ((bool) ($file['hymnPerFile'] ?? $file['hymn_per_file'] ?? false)) {
            return '/hymns/' . $id;
        }
        return Reader::format($file['mimeType'] ?? $file['mime_type'] ?? null, (string) ($file['path'] ?? '')) === null
            ? null
            : '/books/' . $id;
    }

    /** @param array<string, mixed> $hymn */
    private static function numberOf(array $hymn): ?int
    {
        foreach (['number', 'hymnNumber', 'hymn_number'] as $key) {
            $value = $hymn[$key] ?? null;
            if (is_int($value) && $value >= 1) {
                return $value;
            }
            if (is_string($value) && preg_match('/^\d{1,5}$/', trim($value)) === 1 && (int) $value >= 1) {
                return (int) $value;
            }
        }
        return TocNav::hymnNumberOf((string) ($hymn['title'] ?? ''));
    }
}
