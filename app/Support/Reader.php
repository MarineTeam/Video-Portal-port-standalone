<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The reader's own small decisions (lib/reader.ts).
 *
 * Both engines — pdf.js for a PDF, epub.js for an EPUB — sit behind one
 * handle in the browser, so nothing above them needs to know a page number
 * from a CFI. What is here is the part that is the same either way, and the
 * part the server needs when it hands the bytes over.
 */
final class Reader
{
    public const PDF = 'PDF';
    public const EPUB = 'EPUB';

    /** A book this size is fetched whole; a bigger scan keeps streaming. */
    public const WHOLE_BOOK_BYTES = 40 * 1024 * 1024;

    /** One utterance, so read-aloud can be stopped part-way through a page. */
    public const MAX_SPEECH_CHUNK = 240;

    /** Which engine opens this, or null for something neither can. */
    public static function format(?string $mime, string $path = ''): ?string
    {
        $type = strtolower(trim((string) $mime));
        // A correct mime type is trusted over a misleading extension.
        if ($type === 'application/pdf') {
            return self::PDF;
        }
        if ($type === 'application/epub+zip' || $type === 'application/epub') {
            return self::EPUB;
        }
        $clean = (string) preg_replace('/[?#].*$/', '', $path);
        return match (strtolower((string) pathinfo($clean, PATHINFO_EXTENSION))) {
            'pdf' => self::PDF,
            'epub' => self::EPUB,
            default => null,
        };
    }

    /** A percentage the database can hold: a whole number, 0 to 100. */
    public static function clampPercent(mixed $value): int
    {
        $number = is_numeric($value) ? (float) $value : 0.0;
        if (!is_finite($number)) {
            // Never NaN, which would land in the column as garbage.
            $number = 0.0;
        }
        return (int) max(0, min(100, round($number)));
    }

    /**
     * Text broken into what read-aloud says in one breath.
     *
     * @return list<string>
     */
    public static function speechChunks(string $text, int $max = self::MAX_SPEECH_CHUNK): array
    {
        // Extracted PDF text is full of line breaks mid-sentence.
        $clean = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($clean === '') {
            return [];
        }
        $sentences = preg_split('/(?<=[.!?;:\x{2026}])\s+/u', $clean) ?: [];
        $out = [];
        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if ($sentence === '') {
                continue;
            }
            while (mb_strlen($sentence) > $max) {
                // A runaway sentence is broken on a space rather than
                // emitted as one huge utterance nobody can interrupt.
                $cut = mb_strrpos(mb_substr($sentence, 0, $max), ' ');
                $at = $cut === false || $cut < $max / 2 ? $max : $cut;
                $out[] = trim(mb_substr($sentence, 0, $at));
                $sentence = trim(mb_substr($sentence, $at));
            }
            if ($sentence !== '') {
                $out[] = $sentence;
            }
        }
        return $out;
    }

    /**
     * Every occurrence of a query, overlaps included.
     *
     * @return list<int> byte-independent character offsets
     */
    public static function findMatches(string $haystack, string $needle): array
    {
        $query = trim($needle);
        if ($query === '' || $haystack === '') {
            return [];
        }
        $out = [];
        $lowerHay = mb_strtolower($haystack);
        $lowerNeedle = mb_strtolower($query);
        $from = 0;
        while (($at = mb_strpos($lowerHay, $lowerNeedle, $from)) !== false) {
            $out[] = $at;
            // One character on, so "aa" in "aaa" is found twice.
            $from = $at + 1;
        }
        return $out;
    }

    /** A line of context, ellipsized only at the ends actually trimmed. */
    public static function excerptAround(string $text, int $at, int $length, int $window = 60): string
    {
        $from = max(0, $at - $window);
        $to = min(mb_strlen($text), $at + $length + $window);
        return ($from > 0 ? '…' : '') . trim(mb_substr($text, $from, $to - $from)) . ($to < mb_strlen($text) ? '…' : '');
    }

    /**
     * The filename a download is offered under.
     *
     * The extension comes from the stored path, never from the title, and
     * the title cannot smuggle a newline or a quote into the header.
     */
    public static function contentDispositionFilename(string $title, string $path): string
    {
        $extension = strtolower((string) pathinfo((string) preg_replace('/[?#].*$/', '', $path), PATHINFO_EXTENSION));
        $extension = preg_match('/^[a-z0-9]{1,8}$/', $extension) === 1 ? $extension : '';
        $name = trim((string) preg_replace('/[\r\n"\\\\]+/', '', $title));
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if ($name === '') {
            $name = 'file';
        }
        if ($extension !== '' && strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) !== $extension) {
            $name .= '.' . $extension;
        }
        // Per character, not per byte, so one accented letter becomes one
        // underscore rather than two.
        $ascii = (string) preg_replace('/[^\x20-\x7E]/u', '_', $name);
        $ascii = trim((string) preg_replace('/[";\\\\]+/', '', $ascii));
        if ($ascii === '') {
            $ascii = 'file' . ($extension !== '' ? '.' . $extension : '');
        }
        return 'filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name);
    }

    /** Whether to fetch the whole book in one cacheable request. */
    public static function shouldFetchWholeBook(mixed $bytes): bool
    {
        // An unrecorded size is treated as too big rather than guessed at:
        // the first page of a 900MB scan should still open quickly.
        return is_int($bytes) && $bytes > 0 && $bytes <= self::WHOLE_BOOK_BYTES;
    }

    /** Whether the tag a client holds is one of ours. */
    public static function etagMatches(?string $given, ?string $ours): bool
    {
        if ($given === null || $ours === null || trim($given) === '' || trim($ours) === '') {
            return false;
        }
        $strip = static fn (string $tag) => trim(preg_replace('/^W\//i', '', trim($tag)) ?? '', '"');
        $mine = $strip($ours);
        foreach (explode(',', $given) as $one) {
            $one = trim($one);
            if ($one === '*' || $strip($one) === $mine) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a replacement file can stand in for the one people are reading.
     *
     * A re-scan of the same kind of book keeps every reading position
     * meaningful; swapping a PDF for an EPUB makes nonsense of all of them,
     * because only the engine that wrote a location can read it.
     */
    public static function isCompatibleReplacement(?string $wasMime, string $wasPath, ?string $nowMime, string $nowPath, bool $hasPositions = true): bool
    {
        if (!$hasPositions) {
            // Nobody is anywhere in it, so nothing can be lost.
            return true;
        }
        return self::format($wasMime, $wasPath) === self::format($nowMime, $nowPath);
    }
}
