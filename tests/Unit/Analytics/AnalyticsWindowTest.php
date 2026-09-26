<?php

declare(strict_types=1);

namespace Tests\Unit\Analytics;

use App\Modules\Analytics\Analytics;
use PHPUnit\Framework\TestCase;

/** The one decision on this page that needs no database: which window was asked for. */
final class AnalyticsWindowTest extends TestCase
{
    public function test_1_only_the_three_windows_are_honoured(): void
    {
        self::assertSame(7, Analytics::days(7));
        self::assertSame(30, Analytics::days(30));
        self::assertSame(90, Analytics::days(90));
    }

    public function test_2_anything_else_reads_as_a_month(): void
    {
        foreach ([null, '', 'all', 0, -7, 365, '7; DROP TABLE', 1.5] as $asked) {
            self::assertSame(30, Analytics::days($asked), var_export($asked, true));
        }
    }

    public function test_3_a_window_given_as_text_is_still_a_window(): void
    {
        self::assertSame(7, Analytics::days('7'), 'it arrives from a query string');
    }
}
