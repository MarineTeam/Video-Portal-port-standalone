<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Library\Search;
use PHPUnit\Framework\TestCase;

final class SearchTest extends TestCase
{
    public function testExactAndPrefixTitleOutrankDescriptionHits(): void
    {
        $exact = Search::score('grace', 'Grace');
        $prefix = Search::score('grace', 'Grace upon grace');
        $word = Search::score('grace', 'Romans: Grace upon grace');
        $inside = Search::score('race', 'Grace');
        $tag = Search::score('romans', 'Sunday talks', 'romans, epistles');
        $description = Search::score('grace', 'Sunday talks', '', 'All about grace');
        $transcript = Search::score('grace', 'Sunday talks', '', '', '… grace …');
        self::assertGreaterThan($prefix, $exact);
        self::assertGreaterThan($word, $prefix);
        self::assertGreaterThan($inside, $word);
        self::assertGreaterThan($tag, $inside);
        self::assertGreaterThan($description, $tag);
        self::assertGreaterThan($transcript, $description);
        self::assertGreaterThan(0, $transcript);
        self::assertSame(0, Search::score('grace', 'Sunday talks'));
    }

    public function testCaseInsensitive(): void
    {
        self::assertSame(100, Search::score('CHURCH', 'church'));
    }

    public function testFuzzyFindsTypos(): void
    {
        self::assertGreaterThanOrEqual(Search::FUZZY_THRESHOLD, Search::similarity('chruch', 'Church'));
        self::assertGreaterThanOrEqual(Search::FUZZY_THRESHOLD, Search::similarity('chruch', 'Grace Church Sunday'));
        self::assertGreaterThanOrEqual(Search::FUZZY_THRESHOLD, Search::similarity('romasn', 'Romans: Grace Upon Grace'));
        self::assertLessThan(Search::FUZZY_THRESHOLD, Search::similarity('baptism', 'Church'));
        self::assertSame(0.0, Search::similarity('', 'Church'));
    }

    public function testQueryIsCapped(): void
    {
        self::assertSame(Search::MAX_QUERY, mb_strlen(Search::clean(str_repeat('é', 300))));
        self::assertSame('a b', Search::clean("  a \n  b "));
    }
}
