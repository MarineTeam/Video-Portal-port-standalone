<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A contents page somebody typed, read into rows (lib/book-contents.ts).
 *
 * A PDF with no bookmarks indexes to nothing, and plenty of scanned hymnals
 * have none — so the same rows can be typed instead, from the contents page
 * of the book itself. What is typed is what is printed: the hymn number, its
 * title, and the page as the book numbers it. What is stored is the PDF page,
 * because that is what opens the document.
 *
 * Indentation is nesting, so a heading keeps the hymns under it.
 */
final class BookContents
{
    /** A line is split on a tab, a pipe, or a run of two or more spaces. */
    public const SEPARATORS = "/\t|\\||\x20{2,}/u";

    /** Two spaces of indent is one level, as a typist would write it. */
    public const INDENT = 2;

    public const MAX_LINES = 5000;

    /**
     * @param array{offset?: int} $options
     * @return array{entries: list<array{title: string, number: ?int, page: int, depth: int, position: int}>, problems: list<array{line: int, reason: string}>}
     */
    public static function parse(string $text, array $options = []): array
    {
        $offset = (int) ($options['offset'] ?? 0);
        $entries = [];
        $problems = [];
        $position = 0;
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        foreach (array_slice($lines, 0, self::MAX_LINES) as $index => $raw) {
            // Counting blanks, so the number points at the line the typist
            // is looking at rather than at the nth entry.
            $number = $index + 1;
            if (trim($raw) === '') {
                continue;
            }
            $depth = self::depthOf($raw);
            $parts = self::split(trim($raw));
            if (count($parts) < 2) {
                // A hymn with no page is reported rather than dropped: the
                // typist can see which line they left half-finished.
                $problems[] = ['line' => $number, 'reason' => 'No page on this line.'];
                continue;
            }
            $page = array_pop($parts);
            $label = trim(implode(' ', $parts));
            // Punctuation left over from a separator is not a title.
            if (preg_match('/\p{L}|\p{N}/u', $label) !== 1) {
                $problems[] = ['line' => $number, 'reason' => 'Nothing to call this page.'];
                continue;
            }
            $stored = self::pageOf($page, $offset);
            if ($stored === null) {
                $problems[] = ['line' => $number, 'reason' => 'That page is not a page: ' . mb_substr($page, 0, 20) . '.'];
                continue;
            }
            if ($stored < 1) {
                // A printed page in front of the book's own page 1 is a
                // mistake in the offset, not something to store a guess for.
                $problems[] = ['line' => $number, 'reason' => 'That is in front of the book’s first page.'];
                continue;
            }
            $entries[] = [
                // The hymn number stays on the label, where hymnNumberOf
                // finds it — a title is not split from its number here.
                'title' => $label,
                'number' => TocNav::hymnNumberOf($label),
                'page' => $stored,
                'depth' => $depth,
                'position' => $position++,
            ];
        }
        return ['entries' => $entries, 'problems' => $problems];
    }

    /**
     * What the box shows for rows already stored.
     *
     * @param list<array<string, mixed>> $entries
     */
    public static function format(array $entries, int $offset = 0): string
    {
        $lines = [];
        foreach ($entries as $entry) {
            $page = (int) $entry['page'];
            $printed = PageOffset::printedPage($page, $offset);
            $lines[] = str_repeat(' ', self::INDENT * max(0, (int) ($entry['depth'] ?? 0)))
                . (string) $entry['title'] . "\t"
                // Front matter has no printed page to write, so it is
                // written the way it is typed back in.
                . ($printed === null ? 'pdf:' . $page : (string) $printed);
        }
        return implode("\n", $lines);
    }

    /**
     * A line into its label and its page.
     *
     * A tab or a pipe is what a spreadsheet paste has, and is taken first
     * because it is unambiguous. Failing that the line is read as a contents
     * page is printed — dot leaders and all — and the trailing number is the
     * page, which keeps a title that ends in a number apart from it.
     *
     * @return list<string>
     */
    private static function split(string $line): array
    {
        $explicit = array_values(array_filter(array_map('trim', (array) preg_split(self::SEPARATORS, $line)), fn (string $p) => $p !== ''));
        if (count($explicit) >= 2) {
            return $explicit;
        }
        // "Amazing Grace .......... 42", as the book prints it.
        $line = trim((string) preg_replace('/[.\x{2026}\x{00B7}]{2,}/u', ' ', $line));
        if (preg_match('/^(.*?)\s+(pdf:\s*\d{1,6}|\d{1,6})$/iu', $line, $m) === 1 && trim($m[1]) !== '') {
            return [trim($m[1]), trim($m[2])];
        }
        return $explicit;
    }

    /** `pdf:N` is the PDF page itself, for front matter with no printed number. */
    private static function pageOf(string $given, int $offset): ?int
    {
        $text = trim($given);
        if (preg_match('/^pdf:\s*(\d{1,6})$/i', $text, $m) === 1) {
            return (int) $m[1];
        }
        if (preg_match('/^\d{1,6}$/', $text) !== 1) {
            return null;
        }
        return PageOffset::pdfPageOf((int) $text, $offset);
    }

    private static function depthOf(string $raw): int
    {
        $spaces = strlen($raw) - strlen(ltrim($raw, ' '));
        $tabs = strlen($raw) - strlen(ltrim($raw, "\t"));
        return $tabs > 0 ? $tabs : intdiv($spaces, self::INDENT);
    }
}
