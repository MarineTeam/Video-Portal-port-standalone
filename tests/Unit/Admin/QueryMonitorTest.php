<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use App\Modules\Admin\QueryMonitor;
use PHPUnit\Framework\TestCase;

final class QueryMonitorTest extends TestCase
{
    public function test_the_bar_counts_and_times_the_queries(): void
    {
        $bar = QueryMonitor::bar([['sql' => 'SELECT 1', 'ms' => 1.25], ['sql' => "SELECT\n  2", 'ms' => 0.75]], 12.34, 4 * 1048576);
        $this->assertStringContainsString('2 queries · 2.0 ms in the database · 12.3 ms in all · 4.0 MB peak memory', $bar);
        $this->assertStringContainsString('<code>SELECT 2</code>', $bar, 'whitespace folded');
    }

    public function test_sql_is_escaped_and_long_statements_are_cut(): void
    {
        $bar = QueryMonitor::bar([['sql' => "SELECT '</code><script>alert(1)</script>'", 'ms' => 0.1], ['sql' => 'SELECT ' . str_repeat('x', 400), 'ms' => 0.1]], 1, 1);
        $this->assertStringNotContainsString('<script>', $bar);
        $this->assertStringContainsString('&lt;script&gt;', $bar);
        $this->assertStringContainsString(str_repeat('x', 293) . '…', $bar);
    }
}
