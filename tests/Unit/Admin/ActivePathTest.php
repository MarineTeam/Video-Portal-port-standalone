<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use App\Modules\Site\Shell;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** lib/active-path.test.ts */
final class ActivePathTest extends TestCase
{
    #[TestDox('isActivePath: matches a section and everything under it')]
    public function testSection(): void
    {
        self::assertTrue(Shell::isActivePath('/events', '/events'));
        self::assertTrue(Shell::isActivePath('/events', '/events/carols-2026'));
    }

    #[TestDox('isActivePath: stops at a segment boundary')]
    public function testBoundary(): void
    {
        self::assertFalse(Shell::isActivePath('/events', '/eventsx'));
        self::assertFalse(Shell::isActivePath('/live', '/livestream'));
    }

    #[TestDox('isActivePath: only lights Home up on Home')]
    public function testHome(): void
    {
        self::assertTrue(Shell::isActivePath('/', '/'));
        self::assertFalse(Shell::isActivePath('/', '/events'));
    }

    #[TestDox('isActivePath: keeps an overview from staying lit on the pages under it')]
    public function testOverview(): void
    {
        self::assertTrue(Shell::isActivePath('/profile', '/profile', exact: true));
        self::assertFalse(Shell::isActivePath('/profile', '/profile/inbox', exact: true));
    }

    #[TestDox('THEME_INIT_SCRIPT is the same text in PHP and in the browser module')]
    public function testThemeScriptInSync(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 3) . '/public/assets/js/device-settings.js');
        preg_match('/THEME_INIT_SCRIPT =\s*((?:\s*\'[^\']*\'\s*\+?)+);/', $js, $m);
        $joined = implode('', array_map(fn ($part) => trim($part, "' \n+"), preg_split("/'\s*\+\s*'/", $m[1] ?? '') ?: []));
        self::assertSame(Shell::THEME_INIT_SCRIPT, $joined);
    }
}
