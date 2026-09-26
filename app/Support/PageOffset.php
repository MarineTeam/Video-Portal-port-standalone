<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Printed page against PDF page (lib/page-offset.ts).
 *
 * A hymnal's page 1 is rarely the PDF's page 1: there is a cover, a title
 * page, a preface and a contents page in front of it. The offset is how many
 * PDF pages come before printed page 1, and everything a reader *shows* is
 * the printed number, because that is the number somebody calls out.
 *
 * Pages are stored as PDF pages and the printed number is derived at the
 * edge, so a re-scan with a different front matter changes one field rather
 * than every row.
 */
final class PageOffset
{
    /**
     * What is printed on this PDF page, or null when it is front matter —
     * never a zero or a negative page, which no book prints.
     */
    public static function printedPage(int $pdfPage, int $offset): ?int
    {
        $printed = $pdfPage - $offset;
        return $printed >= 1 ? $printed : null;
    }

    /**
     * Which PDF page carries this printed one.
     *
     * An out-of-range answer is left alone rather than clamped: the reader
     * knows how many pages the document has and this does not.
     */
    public static function pdfPageOf(int $printedPage, int $offset): int
    {
        return $printedPage + $offset;
    }
}
