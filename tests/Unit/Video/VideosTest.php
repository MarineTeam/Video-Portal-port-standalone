<?php

declare(strict_types=1);

namespace Tests\Unit\Video;

use App\Modules\Library\Admin\VideosAdmin;
use App\Modules\Library\Videos;
use PHPUnit\Framework\TestCase;

final class VideosTest extends TestCase
{
    public function testSrtBecomesVtt(): void
    {
        $vtt = VideosAdmin::srtToVtt("\xEF\xBB\xBF1\r\n00:00:01,000 --> 00:00:02,500\r\nHello, there\r\n");
        self::assertSame("WEBVTT\n\n1\n00:00:01.000 --> 00:00:02.500\nHello, there\n", $vtt);
    }

    public function testVttPassesThrough(): void
    {
        self::assertSame("WEBVTT\n\n00:01.000 --> 00:02.000\nHi\n", VideosAdmin::srtToVtt("WEBVTT\n\n00:01.000 --> 00:02.000\nHi\n"));
    }

    public function testScriptureBook(): void
    {
        self::assertSame('John', Videos::scriptureBook('John 3:16'));
        self::assertSame('1 John', Videos::scriptureBook('1 John 4:8'));
        self::assertSame('Psalm', Videos::scriptureBook('psalm 23'));
        self::assertSame('Song of Songs', Videos::scriptureBook('Song of Songs 2:1'));
        self::assertSame('Romans', Videos::scriptureBook('Romans'));
        self::assertNull(Videos::scriptureBook('3:16'));
    }
}
