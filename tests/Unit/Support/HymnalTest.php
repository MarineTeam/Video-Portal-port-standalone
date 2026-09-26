<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Hymnal;
use App\Support\Verses;
use PHPUnit\Framework\TestCase;

/** lib/hymnal.test.ts and lib/verses.test.ts */
final class HymnalTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function book(): array
    {
        return [
            ['id' => 'c', 'number' => 12, 'title' => 'Be Thou My Vision', 'lyrics' => 'Be thou my vision'],
            ['id' => 'a', 'number' => 1, 'title' => 'Amazing Grace', 'lyrics' => 'Amazing grace'],
            ['id' => 'b', 'number' => null, 'title' => 'Doxology', 'lyrics' => 'Praise God'],
        ];
    }

    // -- hymnReadingOrder ---------------------------------------------------

    public function testItPutsTheBookInPrintedPageOrder(): void
    {
        $this->assertSame(['a', 'c', 'b'], array_column(Hymnal::readingOrder($this->book()), 'id'));
    }

    public function testHymnsWithNoPrintedNumberStayAtTheEndInTheOrderTheyCameIn(): void
    {
        $hymns = [
            ['id' => 'x', 'number' => null, 'title' => 'First unnumbered'],
            ['id' => 'y', 'number' => 3, 'title' => 'Numbered'],
            ['id' => 'z', 'number' => null, 'title' => 'Second unnumbered'],
        ];
        $this->assertSame(['y', 'x', 'z'], array_column(Hymnal::readingOrder($hymns), 'id'));
    }

    public function testTwoHymnsPrintedOnTheSamePageKeepTheirGivenOrder(): void
    {
        $hymns = [['id' => 'second', 'number' => 5], ['id' => 'first', 'number' => 5]];
        $this->assertSame(['second', 'first'], array_column(Hymnal::readingOrder($hymns), 'id'));
    }

    public function testItDoesNotDisturbTheArrayItWasGiven(): void
    {
        $hymns = $this->book();
        Hymnal::readingOrder($hymns);
        $this->assertSame(['c', 'a', 'b'], array_column($hymns, 'id'));
    }

    public function testAHymnNumberedOnlyInItsTitleIsStillPlaced(): void
    {
        $hymns = [['id' => 'b', 'title' => '12 Be Thou My Vision'], ['id' => 'a', 'title' => '1 Amazing Grace']];
        $this->assertSame(['a', 'b'], array_column(Hymnal::readingOrder($hymns), 'id'));
    }

    // -- fingerprintHymns ---------------------------------------------------

    public function testTheFingerprintIsTheSameForTheSameBook(): void
    {
        $this->assertSame(Hymnal::fingerprint($this->book()), Hymnal::fingerprint($this->book()));
    }

    public function testItChangesWhenAHymnsWordsAreCorrected(): void
    {
        $corrected = $this->book();
        $corrected[0]['lyrics'] = 'Be Thou my vision, O Lord of my heart';
        $this->assertNotSame(Hymnal::fingerprint($this->book()), Hymnal::fingerprint($corrected));
    }

    public function testItChangesWhenAHymnIsAddedRenumberedRetitledOrReordered(): void
    {
        $was = Hymnal::fingerprint($this->book());
        $added = [...$this->book(), ['id' => 'd', 'number' => 40, 'title' => 'New', 'lyrics' => 'x']];
        $this->assertNotSame($was, Hymnal::fingerprint($added));

        $renumbered = $this->book();
        $renumbered[0]['number'] = 13;
        $this->assertNotSame($was, Hymnal::fingerprint($renumbered));

        $retitled = $this->book();
        $retitled[0]['title'] = 'Be Thou My Vision (new)';
        $this->assertNotSame($was, Hymnal::fingerprint($retitled));

        // Reordering two unnumbered hymns is a change to what is sung second.
        $reordered = [['title' => 'B'], ['title' => 'A']];
        $this->assertNotSame(Hymnal::fingerprint([['title' => 'A'], ['title' => 'B']]), Hymnal::fingerprint($reordered));
    }

    public function testItCountsTheHymnsInTheToken(): void
    {
        // So a hash collision still cannot read as unchanged.
        $this->assertStringStartsWith('3-', Hymnal::fingerprint($this->book()));
        $this->assertStringStartsWith('0-', Hymnal::fingerprint([]));
    }

    public function testItIsTheSameFunctionItHasAlwaysBeen(): void
    {
        // Pinned: a change here silently invalidates every saved copy.
        $this->assertSame(
            '2-' . substr(hash('sha256', "1\x1fAmazing Grace\x1fAmazing grace\x1e2\x1fBe Thou\x1fBe thou"), 0, 32),
            Hymnal::fingerprint([
                ['number' => 1, 'title' => 'Amazing Grace', 'lyrics' => 'Amazing grace'],
                ['number' => 2, 'title' => 'Be Thou', 'lyrics' => 'Be thou'],
            ]),
        );
    }

    // -- fileHref -----------------------------------------------------------

    public function testItSendsAHymnInAHymnPerFileBookToItsLyricsPage(): void
    {
        $this->assertSame('/hymns/f1', Hymnal::href(['id' => 'f1', 'hymnPerFile' => true, 'mimeType' => 'audio/mpeg']));
    }

    public function testItSendsABookToItsContents(): void
    {
        $this->assertSame('/books/f2', Hymnal::href(['id' => 'f2', 'mimeType' => 'application/pdf', 'path' => 'a.pdf']));
    }

    public function testItHasNowhereToSendAFileThatIsNeither(): void
    {
        $this->assertNull(Hymnal::href(['id' => 'f3', 'mimeType' => 'audio/mpeg', 'path' => 'a.mp3']));
        $this->assertNull(Hymnal::href(['mimeType' => 'application/pdf']));
    }

    // -- splitVerses --------------------------------------------------------

    public function testItSplitsOnTheBlankLineBetweenVerses(): void
    {
        $verses = Verses::split("Amazing grace\nhow sweet the sound\n\nTwas grace that taught\nmy heart to fear");
        $this->assertCount(2, $verses);
        $this->assertSame(['Amazing grace', 'how sweet the sound'], $verses[0]['lines']);
    }

    public function testItNumbersTheVersesAndLetsAChorusKeepItsName(): void
    {
        $verses = Verses::split("Verse one\n\nChorus\nPraise him\n\nVerse two");
        $this->assertSame(['1', 'Chorus', '2'], array_column($verses, 'label'));
        $this->assertSame([1, null, 2], array_column($verses, 'number'));
        $this->assertSame(['Praise him'], $verses[1]['lines'], 'and the heading is not sung');
    }

    public function testItRecognisesTheOtherNamesAHymnalPrintsAndNothingElse(): void
    {
        $verses = Verses::split("Refrain\nx\n\nEstribillo\ny\n\nBridge\nz\n\nSomething Else\nw");
        $this->assertSame(['Refrain', 'Estribillo', 'Bridge', '1'], array_column($verses, 'label'));
        $this->assertSame(['Something Else', 'w'], $verses[3]['lines'], 'an unknown heading is part of the verse');
    }

    public function testItSurvivesWhatPastingFromADocumentActuallyLooksLike(): void
    {
        $verses = Verses::split("\r\n  Amazing grace  \r\n\r\n\r\n\u{00a0}Twas grace\r\n\r\n");
        $this->assertSame([['Amazing grace'], ['Twas grace']], array_column($verses, 'lines'));
    }

    public function testAHymnWithNoLyricsHasNothingToShow(): void
    {
        $this->assertSame([], Verses::split(''));
        $this->assertSame([], Verses::split("\n\n   \n"));
    }
}
