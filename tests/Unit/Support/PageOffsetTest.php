<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\PageOffset;
use PHPUnit\Framework\TestCase;

/** lib/page-offset.test.ts */
final class PageOffsetTest extends TestCase
{
    public function testThePrintedPageIsThePdfPageWhenABookHasNoFrontMatter(): void
    {
        $this->assertSame(1, PageOffset::printedPage(1, 0));
        $this->assertSame(42, PageOffset::printedPage(42, 0));
    }

    public function testItSubtractsTheFrontMatter(): void
    {
        // A ten-page contents puts printed 1 on PDF 11.
        $this->assertSame(1, PageOffset::printedPage(11, 10));
        $this->assertSame(32, PageOffset::printedPage(42, 10));
    }

    public function testInsideTheFrontMatterThereIsNoPrintedPage(): void
    {
        $this->assertNull(PageOffset::printedPage(10, 10), 'rather than a zero');
        $this->assertNull(PageOffset::printedPage(3, 10), 'or a negative page');
    }

    public function testItHandlesANegativeOffset(): void
    {
        // A scan that starts partway into a book: PDF page 1 is printed 6.
        $this->assertSame(6, PageOffset::printedPage(1, -5));
    }

    public function testThePdfPageIsThePrintedPageWhenABookHasNoFrontMatter(): void
    {
        $this->assertSame(42, PageOffset::pdfPageOf(42, 0));
    }

    public function testItAddsTheFrontMatterBackOn(): void
    {
        $this->assertSame(11, PageOffset::pdfPageOf(1, 10));
    }

    public function testItLeavesAnOutOfRangeValueAloneForTheReaderToClamp(): void
    {
        $this->assertSame(0, PageOffset::pdfPageOf(-10, 10));
        $this->assertSame(10_010, PageOffset::pdfPageOf(10_000, 10));
    }

    public function testARoundTripThroughThePageBoxStaysPut(): void
    {
        foreach ([[1, 0], [42, 10], [7, -5]] as [$printed, $offset]) {
            $this->assertSame($printed, PageOffset::printedPage(PageOffset::pdfPageOf($printed, $offset), $offset));
        }
    }
}
