<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use App\Modules\Admin\AdminNav;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** lib/admin-nav.test.ts */
final class AdminNavTest extends TestCase
{
    /** @return list<string> */
    private static function hrefs(array $groups): array
    {
        return array_merge(...array_map(fn ($g) => array_column($g['links'], 'href'), $groups ?: [['links' => []]]));
    }

    #[TestDox('adminGroupsFor: gives an admin every section')]
    public function testAdmin(): void
    {
        $all = [];
        foreach (AdminNav::groups() as $g) {
            foreach ($g['links'] as $l) {
                $all[$l['href']] = true;
            }
        }
        self::assertEqualsCanonicalizing(array_keys($all), array_values(array_unique(self::hrefs(AdminNav::groupsFor(true, fn () => false)))));
    }

    #[TestDox('adminGroupsFor: gives a staff member with no grants only what every staff member gets')]
    public function testNoGrants(): void
    {
        self::assertSame([], AdminNav::groupsFor(false, fn () => false));
    }

    #[TestDox('adminGroupsFor: keeps the overview and categories admin-only, since /admin bounces everyone else')]
    public function testAdminOnly(): void
    {
        $hrefs = self::hrefs(AdminNav::groupsFor(false, fn () => true));
        self::assertNotContains('/admin', $hrefs);
        self::assertNotContains('/admin/categories', $hrefs);
    }

    #[TestDox('adminGroupsFor: drops a group whose every link is hidden, so no heading stands over nothing')]
    public function testDropsEmpty(): void
    {
        $labels = array_column(AdminNav::groupsFor(false, fn ($c) => $c === 'moderate_prayer'), 'label');
        self::assertSame(['Church life'], $labels);
    }

    #[TestDox('adminGroupsFor: reveals sections one capability at a time')]
    public function testOneAtATime(): void
    {
        self::assertSame(['/admin/analytics'], self::hrefs(AdminNav::groupsFor(false, fn ($c) => $c === 'view_analytics')));
        $files = self::hrefs(AdminNav::groupsFor(false, fn ($c) => $c === 'manage_files'));
        self::assertContains('/admin/files', $files);
        self::assertContains('/admin/trash', $files);
        self::assertNotContains('/admin/videos', $files);
    }

    #[TestDox('adminGroupsFor: lists no href twice')]
    public function testNoDuplicates(): void
    {
        $pairs = [];
        foreach (AdminNav::groupsFor(true, fn () => true) as $g) {
            foreach ($g['links'] as $l) {
                $pairs[] = $l['href'] . '|' . $l['label'];
            }
        }
        self::assertSame($pairs, array_values(array_unique($pairs)));
    }

    #[TestDox('currentAdminLabel: names the open section')]
    public function testLabel(): void
    {
        self::assertSame('Videos', AdminNav::currentLabel('/admin/videos'));
    }

    #[TestDox('currentAdminLabel: stays on the section while inside it')]
    public function testInside(): void
    {
        self::assertSame('Series', AdminNav::currentLabel('/admin/series/cabc123'));
    }

    #[TestDox('currentAdminLabel: prefers the longest match, so a sub-section doesn\'t resolve to the overview')]
    public function testLongest(): void
    {
        self::assertSame('Plugins', AdminNav::currentLabel('/admin/plugins'));
        self::assertSame('Dashboard', AdminNav::currentLabel('/admin'));
    }

    #[TestDox('currentAdminLabel: falls back rather than showing nothing for a page not in the nav')]
    public function testFallback(): void
    {
        self::assertSame('Admin', AdminNav::currentLabel('/elsewhere'));
    }
}
