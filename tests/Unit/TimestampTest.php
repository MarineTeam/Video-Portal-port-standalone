<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Timestamp;
use PHPUnit\Framework\TestCase;

final class TimestampTest extends TestCase
{
    public function testParse(): void
    {
        foreach (['90' => 90, '90s' => 90, '1:30' => 90, '01:02:03' => 3723, '1h2m3s' => 3723, '2m' => 120, '0' => 0] as $in => $out) {
            self::assertSame($out, Timestamp::parse((string) $in), (string) $in);
        }
        foreach (['', 'abc', '1:75', '-5', '1:2', 'h'] as $bad) {
            self::assertNull(Timestamp::parse($bad), $bad);
        }
    }

    public function testFormat(): void
    {
        self::assertSame('2:05', Timestamp::format(125));
        self::assertSame('1:02:03', Timestamp::format(3723));
        self::assertSame('0:00', Timestamp::format(-4));
    }
}
