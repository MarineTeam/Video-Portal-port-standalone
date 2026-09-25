<?php

declare(strict_types=1);

namespace Tests\Unit\Library;

use App\Modules\Library\CategoryTree;
use App\Modules\Library\Visibility;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** lib/content.test.ts, plus the publish-window rules the port keeps beside them. */
final class ContentTest extends TestCase
{
    #[TestDox('canAccess allows anyone to a non-member-only item')]
    public function test_allows_anyone_to_a_non_member_only_item(): void
    {
        $this->assertTrue(Visibility::canAccess(['member_only' => 0], null));
        $this->assertTrue(Visibility::canAccess(['memberOnly' => false], ['id' => 'u1']));
    }

    #[TestDox('canAccess requires login for a member-only item')]
    public function test_requires_login_for_a_member_only_item(): void
    {
        $this->assertFalse(Visibility::canAccess(['member_only' => 1], null));
        $this->assertTrue(Visibility::canAccess(['member_only' => 1], ['id' => 'u1']));
    }

    #[TestDox('categoryChainIds maps the tree to a flat id list, root-most last')]
    public function test_category_chain_root_most_last(): void
    {
        $tree = new CategoryTree(['child' => 'mid', 'mid' => 'root', 'root' => null, 'other' => null]);
        $this->assertSame(['child', 'mid', 'root'], $tree->chain('child'));
    }

    #[TestDox('categoryChainIds returns just the category itself when it has no parent')]
    public function test_category_chain_single(): void
    {
        $this->assertSame(['root'], (new CategoryTree(['root' => null]))->chain('root'));
    }

    public function test_category_chain_survives_a_cycle(): void
    {
        $this->assertSame(['a', 'b'], (new CategoryTree(['a' => 'b', 'b' => 'a']))->chain('a'));
    }

    // getSequentialLockedVideoIds ----------------------------------------------------

    /** @return list<array{id: string, position: int}> */
    private static function videos(): array
    {
        return [['id' => 'v1', 'position' => 0], ['id' => 'v2', 'position' => 1], ['id' => 'v3', 'position' => 2], ['id' => 'v4', 'position' => 3]];
    }

    #[TestDox('getSequentialLockedVideoIds locks nothing for an anonymous viewer, without querying progress')]
    public function test_locks_nothing_for_an_anonymous_viewer(): void
    {
        $asked = false;
        $this->assertSame([], Visibility::sequentialLockedVideoIds(false, true, self::videos(), function () use (&$asked) {
            $asked = true;
            return [];
        }));
        $this->assertFalse($asked);
    }

    #[TestDox("getSequentialLockedVideoIds locks nothing when the series doesn't require sequential viewing")]
    public function test_locks_nothing_when_not_required(): void
    {
        $asked = false;
        $this->assertSame([], Visibility::sequentialLockedVideoIds(true, false, self::videos(), function () use (&$asked) {
            $asked = true;
            return [];
        }));
        $this->assertFalse($asked);
    }

    #[TestDox('getSequentialLockedVideoIds locks every video after the first one not yet completed')]
    public function test_locks_after_first_incomplete(): void
    {
        $this->assertSame(['v4'], Visibility::sequentialLockedVideoIds(true, true, self::videos(), fn () => ['v1', 'v2']));
    }

    #[TestDox('getSequentialLockedVideoIds locks everything past the first video when nothing is completed')]
    public function test_locks_everything_past_first(): void
    {
        $this->assertSame(['v2', 'v3', 'v4'], Visibility::sequentialLockedVideoIds(true, true, self::videos(), fn () => []));
    }

    #[TestDox('getSequentialLockedVideoIds sorts by position before deriving locks, regardless of input order')]
    public function test_sorts_by_position(): void
    {
        $shuffled = [['id' => 'v3', 'position' => 2], ['id' => 'v1', 'position' => 0], ['id' => 'v4', 'position' => 3], ['id' => 'v2', 'position' => 1]];
        $this->assertSame(['v3', 'v4'], Visibility::sequentialLockedVideoIds(true, true, $shuffled, fn () => ['v1']));
    }

    // The publish window -------------------------------------------------------------

    public function test_live_needs_published_untrashed_and_inside_its_window(): void
    {
        $now = new \DateTimeImmutable('2026-09-25 12:00:00', new \DateTimeZone('UTC'));
        $base = ['published' => 1, 'deleted_at' => null, 'publish_at' => null, 'unpublish_at' => null, 'hidden' => 0];
        $this->assertTrue(Visibility::isVisible($base, $now));
        $this->assertFalse(Visibility::isLive(['published' => 0] + $base, $now));
        $this->assertFalse(Visibility::isLive(['deleted_at' => '2026-09-01 00:00:00'] + $base, $now));
        $this->assertFalse(Visibility::isLive(['publish_at' => '2026-09-25 12:00:01'] + $base, $now), 'scheduled');
        $this->assertTrue(Visibility::isLive(['publish_at' => '2026-09-25 12:00:00'] + $base, $now), 'due exactly now');
        $this->assertFalse(Visibility::isLive(['unpublish_at' => '2026-09-25 12:00:00'] + $base, $now), 'expired exactly now');
        $this->assertTrue(Visibility::isLive(['hidden' => 1] + $base, $now));
        $this->assertFalse(Visibility::isVisible(['hidden' => 1] + $base, $now), 'hidden is not there for readers');
    }

    public function test_a_premiere_is_a_published_video_with_a_future_time(): void
    {
        $now = new \DateTimeImmutable('2026-09-25 12:00:00', new \DateTimeZone('UTC'));
        $video = ['published' => 1, 'deleted_at' => null, 'hidden' => 0, 'is_premiere' => 1, 'publish_at' => '2026-09-26 10:00:00'];
        $this->assertTrue(Visibility::isPremiere($video, $now));
        $this->assertFalse(Visibility::isPremiere(['is_premiere' => 0] + $video, $now), 'an ordinary scheduled video stays hidden');
        $this->assertFalse(Visibility::isPremiere(['publish_at' => '2026-09-25 11:00:00'] + $video, $now), 'already out: it is simply live');
        $this->assertFalse(Visibility::isPremiere(['hidden' => 1] + $video, $now));
    }
}
