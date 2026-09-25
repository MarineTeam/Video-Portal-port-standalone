<?php

declare(strict_types=1);

namespace Tests\Unit\Library;

use App\Modules\Library\ViewKey;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** lib/view-key.test.ts */
final class ViewKeyTest extends TestCase
{
    #[TestDox('is stable for the same address under the same secret')]
    public function testStable(): void
    {
        self::assertSame(ViewKey::viewKey('203.0.113.9', 'k'), ViewKey::viewKey('203.0.113.9', 'k'));
    }

    #[TestDox('differs by address and by secret')]
    public function testDiffers(): void
    {
        self::assertNotSame(ViewKey::viewKey('203.0.113.9', 'k'), ViewKey::viewKey('203.0.113.10', 'k'));
        self::assertNotSame(ViewKey::viewKey('203.0.113.9', 'k'), ViewKey::viewKey('203.0.113.9', 'j'));
    }

    #[TestDox('is not the address, and is short enough to index')]
    public function testNotAddress(): void
    {
        $key = (string) ViewKey::viewKey('203.0.113.9', 'k');
        self::assertStringNotContainsString('203', $key);
        self::assertLessThanOrEqual(64, strlen($key));
    }

    #[TestDox('is null with nothing to key on')]
    public function testNull(): void
    {
        self::assertNull(ViewKey::viewKey('', 'k'));
        self::assertNull(ViewKey::viewKey(null, 'k'));
    }
}
