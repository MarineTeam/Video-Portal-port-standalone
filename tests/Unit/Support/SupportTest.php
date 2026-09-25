<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Filename;
use App\Support\Reorder;
use App\Support\Slug;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** lib/slug.test.ts, lib/reorder.test.ts, lib/filename.test.ts */
final class SupportTest extends TestCase
{
    #[TestDox('slugify: makes a title into a URL')]
    public function testSlugify(): void
    {
        self::assertSame('the-cost-of-discipleship', Slug::slugify('The Cost of Discipleship!'));
    }

    #[TestDox('slugify: keeps the letter when stripping its accent')]
    public function testAccent(): void
    {
        self::assertSame('jose-senor', Slug::slugify('José Señor'));
    }

    #[TestDox('slugify: never ends in a dash, including after the length cap')]
    public function testNoTrailingDash(): void
    {
        self::assertSame('hello', Slug::slugify('Hello -- '));
        $long = Slug::slugify(str_repeat('a', 79) . ' b');
        self::assertStringEndsNotWith('-', $long);
        self::assertLessThanOrEqual(Slug::MAX, strlen($long));
    }

    #[TestDox('slugify: gives nothing back for a title with nothing in it')]
    public function testEmpty(): void
    {
        self::assertSame('', Slug::slugify('!!! ??'));
    }

    #[TestDox('uniqueSlug: leaves a free slug alone')]
    public function testUniqueFree(): void
    {
        self::assertSame('easter', Slug::unique('Easter', fn () => false));
    }

    #[TestDox('uniqueSlug: counts up rather than randomising, so next year\'s reads like next year\'s')]
    public function testUniqueCounts(): void
    {
        $taken = ['easter', 'easter-2'];
        self::assertSame('easter-3', Slug::unique('Easter', fn ($s) => in_array($s, $taken, true)));
    }

    #[TestDox('uniqueSlug: falls back rather than returning an empty slug')]
    public function testUniqueFallback(): void
    {
        self::assertSame('item', Slug::unique('???', fn () => false));
    }

    #[TestDox('reorderArray: moves an item later in the list, shifting items between')]
    public function testLater(): void
    {
        self::assertSame(['b', 'c', 'a', 'd'], Reorder::move(['a', 'b', 'c', 'd'], 0, 2));
    }

    #[TestDox('reorderArray: moves an item earlier in the list, shifting items between')]
    public function testEarlier(): void
    {
        self::assertSame(['d', 'a', 'b', 'c'], Reorder::move(['a', 'b', 'c', 'd'], 3, 0));
    }

    #[TestDox('reorderArray: returns the same array when the target index is unchanged')]
    public function testSame(): void
    {
        self::assertSame(['a', 'b'], Reorder::move(['a', 'b'], 1, 1));
    }

    #[TestDox('reorderArray: clamps a target index past the end of the list')]
    public function testClampEnd(): void
    {
        self::assertSame(['b', 'c', 'a'], Reorder::move(['a', 'b', 'c'], 0, 99));
    }

    #[TestDox('reorderArray: clamps a negative target index to the start of the list')]
    public function testClampStart(): void
    {
        self::assertSame(['c', 'a', 'b'], Reorder::move(['a', 'b', 'c'], 2, -5));
    }

    #[TestDox('reorderArray: does not mutate the input array')]
    public function testNoMutate(): void
    {
        $input = ['a', 'b', 'c'];
        Reorder::move($input, 0, 2);
        self::assertSame(['a', 'b', 'c'], $input);
    }

    #[TestDox('titleFromFilename: drops the extension')]
    public function testDropsExt(): void
    {
        self::assertSame('Hymn 214', Filename::titleFromFilename('Hymn 214.pdf'));
    }

    #[TestDox('titleFromFilename: drops only the last extension')]
    public function testLastExt(): void
    {
        self::assertSame('notes.tar', Filename::titleFromFilename('notes.tar.gz'));
    }

    #[TestDox('titleFromFilename: keeps a name that has no extension')]
    public function testNoExt(): void
    {
        self::assertSame('README', Filename::titleFromFilename('README'));
    }

    #[TestDox('titleFromFilename: treats a leading dot as part of the name, not a separator')]
    public function testLeadingDot(): void
    {
        self::assertSame('.hidden', Filename::titleFromFilename('.hidden'));
    }

    #[TestDox('titleFromFilename: strips a directory path if one comes through')]
    public function testPath(): void
    {
        self::assertSame('sermon', Filename::titleFromFilename('C:\\Users\\me\\sermon.mp3'));
        self::assertSame('sermon', Filename::titleFromFilename('/home/me/sermon.mp3'));
    }

    #[TestDox('titleFromFilename: leaves separators and capitalisation alone')]
    public function testLeavesAlone(): void
    {
        self::assertSame('Easter_Sunday-FULL', Filename::titleFromFilename('Easter_Sunday-FULL.mp4'));
    }

    #[TestDox('titleFromFilename: handles empty and whitespace input without producing junk')]
    public function testEmptyName(): void
    {
        self::assertSame('', Filename::titleFromFilename(''));
        self::assertSame('', Filename::titleFromFilename('   '));
    }
}
