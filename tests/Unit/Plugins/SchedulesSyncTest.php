<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins;

use MarineTeam\Plugins\Schedules\Parse;
use MarineTeam\Plugins\Schedules\Sync;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/plugins/schedules/src/Names.php';
require_once dirname(__DIR__, 3) . '/plugins/schedules/src/Dates.php';
require_once dirname(__DIR__, 3) . '/plugins/schedules/src/Parse.php';
require_once dirname(__DIR__, 3) . '/plugins/schedules/src/Logic.php';
require_once dirname(__DIR__, 3) . '/plugins/schedules/src/Sync.php';

/**
 * When an import is due, and what it makes of a source row
 * (lib/schedules/sync.ts).
 */
final class SchedulesSyncTest extends TestCase
{
    public function testASourceNeverSyncedIsDue(): void
    {
        $this->assertTrue(Sync::isDue(['last_synced_at' => null, 'sync_interval_minutes' => 60]));
        $this->assertTrue(Sync::isDue(['last_synced_at' => '', 'sync_interval_minutes' => 60]));
    }

    public function testASourceWithinItsIntervalIsNot(): void
    {
        $this->assertFalse(Sync::isDue(['last_synced_at' => '2026-03-02 05:00:00', 'sync_interval_minutes' => 1440], '2026-03-02 09:00:00'));
    }

    public function testASourcePastItsIntervalIs(): void
    {
        $this->assertTrue(Sync::isDue(['last_synced_at' => '2026-03-01 05:00:00', 'sync_interval_minutes' => 1440], '2026-03-02 09:00:00'));
    }

    public function testAnIntervalOfNothingIsStillAMinute(): void
    {
        // A misconfigured zero would otherwise mean "every run, forever".
        $this->assertFalse(Sync::isDue(['last_synced_at' => '2026-03-02 09:00:30', 'sync_interval_minutes' => 0], '2026-03-02 09:00:45'));
    }

    public function testTheParserConfigIsReadBackWithTheFormatFilledIn(): void
    {
        $config = Sync::parserConfig(['parser_config' => '{"dateColumn":"B"}', 'format' => Parse::NAME_COLUMNS]);
        $this->assertSame('B', $config['dateColumn']);
        $this->assertSame(Parse::NAME_COLUMNS, $config['format']);
    }

    public function testAConfigThatNamesItsOwnFormatKeepsIt(): void
    {
        $config = Sync::parserConfig(['parser_config' => '{"format":"DATE_NAMES"}', 'format' => Parse::NAME_COLUMNS]);
        $this->assertSame(Parse::DATE_NAMES, $config['format']);
    }

    public function testNonsenseInTheConfigColumnIsNoConfigAtAll(): void
    {
        $this->assertSame([], Sync::parserConfig(['parser_config' => 'not json', 'format' => null]));
    }
}
