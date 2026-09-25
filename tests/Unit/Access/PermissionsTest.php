<?php

declare(strict_types=1);

namespace Tests\Unit\Access;

use App\Modules\Access\Permissions;
use App\Modules\Library\CategoryTree;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** lib/permissions.test.ts */
final class PermissionsTest extends TestCase
{
    private static function a(array $caps, ?string $cat = null, ?string $series = null): array
    {
        return ['capabilities' => $caps, 'categoryId' => $cat, 'seriesId' => $series];
    }

    #[TestDox('hasCapability: always passes for an admin, without querying assignments')]
    public function testAdmin(): void
    {
        self::assertTrue(Permissions::decide('ADMIN', [], 'manage_users'));
    }

    #[TestDox('hasCapability: fails when the user has no matching assignment')]
    public function testNoMatch(): void
    {
        self::assertFalse(Permissions::decide('MEMBER', [self::a(['manage_files'])], 'manage_videos'));
    }

    #[TestDox('hasCapability: passes on a site-wide assignment (no category or series), even with no scope given')]
    public function testSiteWide(): void
    {
        self::assertTrue(Permissions::decide('MEMBER', [self::a(['manage_videos'])], 'manage_videos'));
    }

    #[TestDox('hasCapability: fails a scoped-only assignment when no scope is given')]
    public function testScopedNoScope(): void
    {
        self::assertFalse(Permissions::decide('MEMBER', [self::a(['manage_videos'], 'cat1')], 'manage_videos'));
    }

    #[TestDox('hasCapability: passes when the assignment\'s seriesId exactly matches the requested scope')]
    public function testSeries(): void
    {
        self::assertTrue(Permissions::decide('MEMBER', [self::a(['manage_videos'], null, 's1')], 'manage_videos', 's1'));
        self::assertFalse(Permissions::decide('MEMBER', [self::a(['manage_videos'], null, 's1')], 'manage_videos', 's2'));
    }

    #[TestDox('hasCapability: passes when the assignment\'s category is an ancestor of the scoped category')]
    public function testAncestor(): void
    {
        self::assertTrue(Permissions::decide('MEMBER', [self::a(['manage_videos'], 'root')], 'manage_videos', null, ['child', 'mid', 'root']));
    }

    #[TestDox('hasCapability: fails when the assignment\'s category is outside the scoped category\'s chain')]
    public function testOutside(): void
    {
        self::assertFalse(Permissions::decide('MEMBER', [self::a(['manage_videos'], 'other')], 'manage_videos', null, ['child', 'root']));
    }

    #[TestDox('descendantCategoryIds: returns an empty list for no roots, without querying')]
    public function testNoRoots(): void
    {
        self::assertSame([], (new CategoryTree(['a' => null]))->descendants([]));
    }

    #[TestDox('descendantCategoryIds: returns just the root when it has no children')]
    public function testLeaf(): void
    {
        self::assertSame(['a'], (new CategoryTree(['a' => null, 'b' => null]))->descendants(['a']));
    }

    #[TestDox('descendantCategoryIds: includes every descendant regardless of row order')]
    public function testDescendants(): void
    {
        $tree = new CategoryTree(['d' => 'c', 'c' => 'b', 'b' => 'a', 'a' => null, 'x' => null]);
        $got = $tree->descendants(['a']);
        sort($got);
        self::assertSame(['a', 'b', 'c', 'd'], $got);
    }

    #[TestDox('descendantCategoryIds: doesn\'t pull in a sibling subtree outside the given roots')]
    public function testSibling(): void
    {
        $tree = new CategoryTree(['a' => null, 'a1' => 'a', 'b' => null, 'b1' => 'b']);
        $got = $tree->descendants(['a']);
        sort($got);
        self::assertSame(['a', 'a1'], $got);
    }

    #[TestDox('categoryChainIds: maps the tree to a flat id list, root-most last')]
    public function testChain(): void
    {
        self::assertSame(['c', 'b', 'a'], (new CategoryTree(['a' => null, 'b' => 'a', 'c' => 'b']))->chain('c'));
    }

    #[TestDox('categoryChainIds: returns just the category itself when it has no parent')]
    public function testChainRoot(): void
    {
        self::assertSame(['a'], (new CategoryTree(['a' => null]))->chain('a'));
    }

    #[TestDox('categoryChainIds: stops at the depth cap rather than looping on a cycle')]
    public function testChainCycle(): void
    {
        self::assertSame(['a', 'b'], (new CategoryTree(['a' => 'b', 'b' => 'a']))->chain('a'));
    }
}
