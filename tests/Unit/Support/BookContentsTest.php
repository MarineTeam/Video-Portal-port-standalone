<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\BookContents;
use App\Support\TocNav;
use PHPUnit\Framework\TestCase;

/** lib/book-contents.test.ts and lib/toc-nav.test.ts */
final class BookContentsTest extends TestCase
{
    // -- parseContentsText --------------------------------------------------

    public function testItReadsAContentsPageAsItIsPrinted(): void
    {
        $read = BookContents::parse("1 Amazing Grace .......... 12\n2 Be Thou My Vision ..... 14");
        $this->assertSame([], $read['problems']);
        $this->assertSame(['1 Amazing Grace', '2 Be Thou My Vision'], array_column($read['entries'], 'title'));
        $this->assertSame([12, 14], array_column($read['entries'], 'page'));
    }

    public function testItLeavesTheHymnNumberOnTheLabelWhereHymnNumberOfFindsIt(): void
    {
        $entry = BookContents::parse('119 O Worship the King  40')['entries'][0];
        $this->assertSame('119 O Worship the King', $entry['title']);
        $this->assertSame(119, $entry['number']);
    }

    public function testATabOrAPipeIsASeparatorSoASpreadsheetPasteWorks(): void
    {
        $tabbed = BookContents::parse("1 Amazing Grace\t12")['entries'][0];
        $this->assertSame(['1 Amazing Grace', 12], [$tabbed['title'], $tabbed['page']]);
        $piped = BookContents::parse('1 Amazing Grace | 12')['entries'][0];
        $this->assertSame(['1 Amazing Grace', 12], [$piped['title'], $piped['page']]);
    }

    public function testItKeepsATitleThatEndsInANumberApartFromThePage(): void
    {
        $entry = BookContents::parse('Psalm 23  40')['entries'][0];
        $this->assertSame('Psalm 23', $entry['title']);
        $this->assertSame(40, $entry['page']);
        $this->assertNull($entry['number'], 'and Psalm 23 is not hymn 23');
    }

    public function testItConvertsThePrintedPageAPersonTypesIntoThePdfPageStored(): void
    {
        $entry = BookContents::parse('1 Amazing Grace  1', ['offset' => 10])['entries'][0];
        $this->assertSame(11, $entry['page']);
    }

    public function testPdfNIsThePdfPageItself(): void
    {
        // Front matter has no printed number to type.
        $entry = BookContents::parse('Preface  pdf:4', ['offset' => 10])['entries'][0];
        $this->assertSame(4, $entry['page']);
    }

    public function testBlankLinesAreSkippedWithoutBeingCounted(): void
    {
        $read = BookContents::parse("1 One  1\n\n\n2 Two  2\n");
        $this->assertCount(2, $read['entries']);
        $this->assertSame([0, 1], array_column($read['entries'], 'position'));
        $this->assertSame([], $read['problems']);
    }

    public function testALineWithNoPageIsReportedRatherThanTheHymnDropped(): void
    {
        $read = BookContents::parse("1 Amazing Grace  12\n2 Be Thou My Vision");
        $this->assertCount(1, $read['entries']);
        $this->assertCount(1, $read['problems']);
        $this->assertStringContainsString('No page', $read['problems'][0]['reason']);
    }

    public function testAProblemPointsAtTheLineTheTypistSees(): void
    {
        $read = BookContents::parse("1 One  1\n\n\nno page here\n");
        $this->assertSame(4, $read['problems'][0]['line'], 'counting the blanks');
    }

    public function testItRefusesAPageInFrontOfTheBooksOwnPageOne(): void
    {
        // Printed 1 with a ten-page offset is PDF 11, which is fine.
        $this->assertSame(11, BookContents::parse('Preface  1', ['offset' => 10])['entries'][0]['page']);

        $bad = BookContents::parse('Preface  -4', ['offset' => 10]);
        $this->assertSame([], $bad['entries']);
        $this->assertCount(1, $bad['problems']);

        $front = BookContents::parse('Preface  pdf:0');
        $this->assertSame([], $front['entries']);
        $this->assertStringContainsString('first page', $front['problems'][0]['reason']);
    }

    public function testItRefusesAPageWithNothingToCallIt(): void
    {
        $read = BookContents::parse("|  12\n");
        $this->assertSame([], $read['entries']);
        $this->assertCount(1, $read['problems']);
    }

    public function testIndentationIsNestingSoAHeadingKeepsTheHymnsUnderIt(): void
    {
        $read = BookContents::parse("Advent  10\n  1 O Come  11\n  2 Hark  12\nLent  40");
        $this->assertSame([0, 1, 1, 0], array_column($read['entries'], 'depth'));
    }

    // -- formatContentsText -------------------------------------------------

    public function testItRoundTripsWhatWasParsedOffsetAndNestingAndAll(): void
    {
        $text = "Advent\t10\n  1 O Come\t11\n  2 Hark\t12";
        $read = BookContents::parse($text, ['offset' => 9]);
        $this->assertSame($text, BookContents::format($read['entries'], 9));
    }

    public function testItWritesFrontMatterAsAPdfPage(): void
    {
        $read = BookContents::parse("Preface\tpdf:4", ['offset' => 10]);
        $this->assertSame("Preface\tpdf:4", BookContents::format($read['entries'], 10));
    }

    public function testAnEntryIndexedFromBookmarksIsReadableWithNoDepthOfItsOwn(): void
    {
        $this->assertSame("1 Amazing Grace\t12", BookContents::format([['title' => '1 Amazing Grace', 'page' => 12]], 0));
    }

    // -- toc-nav ------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    private function book(): array
    {
        return [
            ['title' => 'Advent', 'page' => 10],
            ['title' => '1 O Come', 'page' => 10],
            ['title' => '2 Hark', 'page' => 14],
            ['title' => 'Lent', 'page' => 40],
            ['title' => '3 When I Survey', 'page' => 40],
        ];
    }

    public function testCurrentFindsTheEntryAPageFallsInsideNotTheNextOne(): void
    {
        $this->assertSame(2, TocNav::currentTocIndex($this->book(), 15));
        $this->assertSame(2, TocNav::currentTocIndex($this->book(), 14));
    }

    public function testCurrentPrefersTheLaterOfTwoEntriesStartingInTheSamePlace(): void
    {
        $this->assertSame(1, TocNav::currentTocIndex($this->book(), 10), 'the hymn, not the heading above it');
        $this->assertSame(4, TocNav::currentTocIndex($this->book(), 41));
    }

    public function testCurrentHasNoAnswerBeforeTheFirstEntry(): void
    {
        $this->assertNull(TocNav::currentTocIndex($this->book(), 3));
    }

    public function testCurrentSkipsEntriesItCannotPlace(): void
    {
        $entries = [['title' => 'Unplaceable', 'page' => null], ['title' => '1 One', 'page' => 10]];
        $this->assertSame(1, TocNav::currentTocIndex($entries, 12), 'rather than putting it at the front');
    }

    public function testCurrentHasNoAnswerWhenTheReaderItselfCannotBePlaced(): void
    {
        $this->assertNull(TocNav::currentTocIndex($this->book(), null));
    }

    public function testNextMovesToTheFirstEntryAfterHere(): void
    {
        $this->assertSame(2, TocNav::nextTocIndex($this->book(), 10));
    }

    public function testNextStepsPastEveryEntrySharingThisPositionSoItAlwaysMoves(): void
    {
        $entries = [['title' => 'A', 'page' => 10], ['title' => 'B', 'page' => 10], ['title' => 'C', 'page' => 12]];
        $this->assertSame(2, TocNav::nextTocIndex($entries, 10));
    }

    public function testNextStopsAtTheLastEntry(): void
    {
        $this->assertSame(4, TocNav::nextTocIndex($this->book(), 400));
    }

    public function testNextOrdersByPositionNotByTheOrderEntriesAreListedIn(): void
    {
        $entries = [['title' => 'Late', 'page' => 40], ['title' => 'Early', 'page' => 10]];
        $this->assertSame(0, TocNav::nextTocIndex($entries, 20));
    }

    public function testPreviousGoesBackToTheStartOfTheEntryBeingRead(): void
    {
        // The way a track skip does.
        $this->assertSame(2, TocNav::previousTocIndex($this->book(), 15));
    }

    public function testPreviousPrefersTheLaterOfTwoEntriesStartingInTheSamePlace(): void
    {
        $this->assertSame(1, TocNav::previousTocIndex($this->book(), 12));
    }

    public function testPreviousStopsAtTheFirstEntry(): void
    {
        $this->assertSame(0, TocNav::previousTocIndex($this->book(), 1));
        $this->assertSame(0, TocNav::previousTocIndex($this->book(), 10));
    }

    public function testPreviousSkipsEntriesItCannotPlace(): void
    {
        $entries = [['title' => 'Unplaceable', 'page' => null], ['title' => '1 One', 'page' => 10], ['title' => '2 Two', 'page' => 20]];
        $this->assertSame(1, TocNav::previousTocIndex($entries, 20));
    }

    public function testHymnNumberOfReadsTheNumberAHymnalPutsInFront(): void
    {
        $this->assertSame(119, TocNav::hymnNumberOf('119. O Worship the King'));
        $this->assertSame(1, TocNav::hymnNumberOf('1 Amazing Grace'));
        $this->assertSame(23, TocNav::hymnNumberOf('23 — The Lord is my shepherd'));
    }

    public function testHymnNumberOfIgnoresANumberThatIsPartOfTheTitle(): void
    {
        $this->assertNull(TocNav::hymnNumberOf('Psalm 23'));
        $this->assertNull(TocNav::hymnNumberOf('The 23rd Psalm'));
    }

    public function testHymnNumberOfTakesTheWholeNumberNotTheFirstDigit(): void
    {
        $this->assertSame(119, TocNav::hymnNumberOf('119 O Worship the King'));
    }

    public function testHymnNumberOfRefusesAZeroWhichNoHymnalPrints(): void
    {
        $this->assertNull(TocNav::hymnNumberOf('0 Nothing'));
    }

    public function testFindHymnIndexFindsTheEntryPrintedUnderThatNumber(): void
    {
        $this->assertSame(2, TocNav::findHymnIndex($this->book(), 2));
        $this->assertSame(1, TocNav::findHymnIndex([['title' => 'Advent', 'page' => 1], ['title' => 'x', 'number' => 7, 'page' => 2]], 7));
    }

    public function testFindHymnIndexHasNoAnswerForANumberTheBookDoesNotList(): void
    {
        $this->assertNull(TocNav::findHymnIndex($this->book(), 900));
    }
}
