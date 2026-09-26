<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Reader;
use PHPUnit\Framework\TestCase;

/** lib/reader.test.ts */
final class ReaderTest extends TestCase
{
    public function testItRecognizesTheCorrectMimeTypes(): void
    {
        $this->assertSame('PDF', Reader::format('application/pdf', ''));
        $this->assertSame('EPUB', Reader::format('application/epub+zip', ''));
    }

    public function testItFallsBackToTheExtensionWhenTheMimeTypeIsMissingOrWrong(): void
    {
        $this->assertSame('PDF', Reader::format(null, '/files/hymnal.pdf'));
        $this->assertSame('EPUB', Reader::format('application/octet-stream', '/files/book.epub'));
    }

    public function testItIgnoresAQueryStringOrFragmentOnThePath(): void
    {
        $this->assertSame('PDF', Reader::format(null, '/files/hymnal.pdf?v=2'));
        $this->assertSame('EPUB', Reader::format(null, '/files/book.epub#page'));
    }

    public function testItReturnsNullForAnythingItCannotOpen(): void
    {
        $this->assertNull(Reader::format('audio/mpeg', '/files/sermon.mp3'));
        $this->assertNull(Reader::format(null, '/files/notes'));
    }

    public function testItTrustsACorrectMimeTypeOverAMisleadingExtension(): void
    {
        $this->assertSame('PDF', Reader::format('application/pdf', '/files/hymnal.epub'));
    }

    public function testClampPercentHoldsTheValueInsideZeroToOneHundred(): void
    {
        $this->assertSame(50, Reader::clampPercent(49.6));
        $this->assertSame(0, Reader::clampPercent(-10));
        $this->assertSame(100, Reader::clampPercent(1000));
    }

    public function testClampPercentTreatsNonFiniteInputAsZero(): void
    {
        // Rather than writing NaN to the database.
        $this->assertSame(0, Reader::clampPercent(NAN));
        $this->assertSame(0, Reader::clampPercent(INF));
        $this->assertSame(0, Reader::clampPercent('nonsense'));
        $this->assertSame(0, Reader::clampPercent(null));
    }

    public function testSpeechChunksSplitOnSentenceEndingsKeepingThePunctuation(): void
    {
        $this->assertSame(['One.', 'Two!', 'Three?'], Reader::speechChunks('One. Two! Three?'));
    }

    public function testSpeechChunksCollapseWhitespace(): void
    {
        // Extracted PDF text is full of it.
        $this->assertSame(['Amazing grace, how sweet the sound.'], Reader::speechChunks("Amazing grace,\n  how sweet\nthe sound."));
    }

    public function testSpeechChunksAreNothingForEmptyOrWhitespaceOnlyText(): void
    {
        $this->assertSame([], Reader::speechChunks(''));
        $this->assertSame([], Reader::speechChunks("  \n\t "));
    }

    public function testARunawaySentenceIsBrokenOnASpace(): void
    {
        $long = str_repeat('word ', 200);
        $chunks = Reader::speechChunks($long);
        $this->assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(Reader::MAX_SPEECH_CHUNK, mb_strlen($chunk));
            $this->assertStringEndsNotWith('wor', $chunk, 'broken on a space, not mid-word');
        }
    }

    public function testASentenceWithNoTerminalPunctuationIsKept(): void
    {
        $this->assertSame(['The last line of a hymn'], Reader::speechChunks('The last line of a hymn'));
    }

    public function testFindMatchesFindsEveryCaseInsensitiveOccurrence(): void
    {
        $this->assertSame([0, 15], Reader::findMatches('Grace, amazing grace', 'grace'));
    }

    public function testFindMatchesFindsOverlappingMatches(): void
    {
        $this->assertSame([0, 1, 2], Reader::findMatches('aaaa', 'aa'));
    }

    public function testFindMatchesReturnsNothingForAnEmptyQueryOrNoHit(): void
    {
        $this->assertSame([], Reader::findMatches('Amazing grace', '  '));
        $this->assertSame([], Reader::findMatches('Amazing grace', 'zzz'));
        $this->assertSame([], Reader::findMatches('', 'a'));
    }

    public function testExcerptEllipsizesOnlyTheEndsItActuallyTrimmed(): void
    {
        $text = str_repeat('a', 200);
        $middle = Reader::excerptAround($text, 100, 5);
        $this->assertStringStartsWith('…', $middle);
        $this->assertStringEndsWith('…', $middle);
        $start = Reader::excerptAround($text, 0, 5);
        $this->assertStringStartsNotWith('…', $start);
    }

    public function testExcerptAddsNoEllipsisWhenTheWholeStringAlreadyFits(): void
    {
        $this->assertSame('Amazing grace', Reader::excerptAround('Amazing grace', 0, 7));
    }

    public function testTheFilenameTakesItsExtensionFromThePathNotTheTitle(): void
    {
        $this->assertStringContainsString('filename="Hymns Ancient and Modern.pdf"', Reader::contentDispositionFilename('Hymns Ancient and Modern', '/files/abc.pdf'));
    }

    public function testItDoesNotDoubleUpAnExtensionTheTitleAlreadyHas(): void
    {
        $this->assertStringContainsString('filename="hymnal.pdf"', Reader::contentDispositionFilename('hymnal.pdf', '/files/abc.pdf'));
    }

    public function testItStripsWhatWouldEndTheQuotedStringEarly(): void
    {
        $header = Reader::contentDispositionFilename("Hymns\r\nX-Injected: yes \"quoted\" \\slash", '/files/abc.pdf');
        $this->assertStringNotContainsString("\n", $header);
        $this->assertStringNotContainsString("\r", $header);
        $this->assertSame(2, substr_count($header, '"'), 'exactly the pair around the ASCII name');
        $this->assertStringNotContainsString('\\', $header);
    }

    public function testItKeepsNonAsciiInTheStarFormWhileFallingBackToAscii(): void
    {
        $header = Reader::contentDispositionFilename('Cántico nuevo', '/files/abc.pdf');
        $this->assertStringContainsString('filename="C_ntico nuevo.pdf"', $header);
        $this->assertStringContainsString("filename*=UTF-8''C%C3%A1ntico%20nuevo.pdf", $header);
    }

    public function testItFallsBackToAUsableNameWhenTheTitleIsBlank(): void
    {
        $this->assertStringContainsString('filename="file.pdf"', Reader::contentDispositionFilename('   ', '/files/abc.pdf'));
    }

    public function testItIgnoresAJunkExtensionRatherThanAppendingIt(): void
    {
        $header = Reader::contentDispositionFilename('Hymnal', '/files/abc.this-is-not-an-extension');
        $this->assertStringContainsString('filename="Hymnal"', $header);
    }

    public function testItFetchesAnOrdinaryBookInOneCacheableRequest(): void
    {
        $this->assertTrue(Reader::shouldFetchWholeBook(3 * 1024 * 1024));
    }

    public function testItLeavesAVeryLargeScanStreaming(): void
    {
        // So its first page still opens quickly.
        $this->assertFalse(Reader::shouldFetchWholeBook(900 * 1024 * 1024));
    }

    public function testItTreatsAnUnrecordedSizeAsTooBig(): void
    {
        $this->assertFalse(Reader::shouldFetchWholeBook(null));
        $this->assertFalse(Reader::shouldFetchWholeBook(0));
    }

    public function testEtagMatchesTheTagTheClientAlreadyHolds(): void
    {
        $this->assertTrue(Reader::etagMatches('"abc"', '"abc"'));
        $this->assertFalse(Reader::etagMatches('"abc"', '"def"'));
    }

    public function testEtagComparesWeakly(): void
    {
        // A validator marked weak still counts as the same bytes.
        $this->assertTrue(Reader::etagMatches('W/"abc"', '"abc"'));
        $this->assertTrue(Reader::etagMatches('"abc"', 'W/"abc"'));
    }

    public function testEtagAcceptsAnyTagInAListAndTheWildcard(): void
    {
        $this->assertTrue(Reader::etagMatches('"one", "abc", "two"', '"abc"'));
        $this->assertTrue(Reader::etagMatches('*', '"abc"'));
    }

    public function testEtagIsFalseWhenEitherSideHasNothingToCompare(): void
    {
        $this->assertFalse(Reader::etagMatches(null, '"abc"'));
        $this->assertFalse(Reader::etagMatches('"abc"', null));
        $this->assertFalse(Reader::etagMatches('', ''));
    }

    public function testAReScanOfTheSameKindOfBookIsAllowed(): void
    {
        $this->assertTrue(Reader::isCompatibleReplacement('application/pdf', 'a.pdf', 'application/pdf', 'b.pdf'));
    }

    public function testSwappingOneReadersFormatForTheOthersIsRefused(): void
    {
        $this->assertFalse(Reader::isCompatibleReplacement('application/pdf', 'a.pdf', 'application/epub+zip', 'b.epub'));
    }

    public function testTurningABookIntoSomethingNoReaderOpensIsRefusedAndTheReverse(): void
    {
        $this->assertFalse(Reader::isCompatibleReplacement('application/pdf', 'a.pdf', 'audio/mpeg', 'b.mp3'));
        $this->assertFalse(Reader::isCompatibleReplacement('audio/mpeg', 'a.mp3', 'application/pdf', 'b.pdf'));
    }

    public function testFilesWithNoReaderPositionsAreInterchangeable(): void
    {
        $this->assertTrue(Reader::isCompatibleReplacement('application/pdf', 'a.pdf', 'audio/mpeg', 'b.mp3', false));
    }
}
