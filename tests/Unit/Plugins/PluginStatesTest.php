<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins;

use App\Modules\Plugins\PluginStates;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** lib/plugins.test.ts */
final class PluginStatesTest extends TestCase
{
    private const ROWS = ['comments' => ['id' => 'p1', 'enabled' => true], 'ratings' => ['id' => 'p2', 'enabled' => false]];

    #[TestDox('getPluginStates: uses the site-wide value with no category context')]
    public function testSiteWide(): void
    {
        self::assertSame(['comments' => true, 'ratings' => false], PluginStates::resolve(self::ROWS, [], [], ['comments', 'ratings']));
    }

    #[TestDox('getPluginStates: fails open for a plugin with no seeded row')]
    public function testFailOpen(): void
    {
        self::assertTrue(PluginStates::resolve([], [], [], ['favorites'])['favorites']);
    }

    #[TestDox('getPluginStates: applies a direct override on the given category')]
    public function testDirect(): void
    {
        self::assertFalse(PluginStates::resolve(self::ROWS, ['p1' => ['kids' => false]], ['kids', 'root'], ['comments'])['comments']);
    }

    #[TestDox('getPluginStates: walks up to an ancestor\'s override when the category has none of its own')]
    public function testAncestor(): void
    {
        self::assertTrue(PluginStates::resolve(self::ROWS, ['p2' => ['root' => true]], ['kids', 'root'], ['ratings'])['ratings']);
    }

    #[TestDox('getPluginStates: prefers the nearest override over a more distant ancestor\'s')]
    public function testNearest(): void
    {
        self::assertFalse(PluginStates::resolve(self::ROWS, ['p1' => ['kids' => false, 'root' => true]], ['kids', 'root'], ['comments'])['comments']);
    }

    #[TestDox('getPluginStates: falls back to the site-wide value when no override matches the chain')]
    public function testFallback(): void
    {
        self::assertTrue(PluginStates::resolve(self::ROWS, ['p1' => ['elsewhere' => false]], ['kids', 'root'], ['comments'])['comments']);
    }
}
