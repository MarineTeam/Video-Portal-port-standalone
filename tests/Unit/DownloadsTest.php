<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Library\Downloads;
use PHPUnit\Framework\TestCase;

/** lib/downloads.test.ts */
final class DownloadsTest extends TestCase
{
    public function test_allows_by_default_when_nothing_anywhere_has_an_opinion(): void
    {
        self::assertTrue(Downloads::resolveDownloadEnabled(null, null, [null, null]));
        self::assertTrue(Downloads::resolveDownloadEnabled(null, null, []));
    }

    public function test_lets_the_video_override_its_series_and_category(): void
    {
        self::assertTrue(Downloads::resolveDownloadEnabled(true, false, [false]));
        self::assertFalse(Downloads::resolveDownloadEnabled(false, true, [true]));
    }

    public function test_falls_to_the_series_when_the_video_is_inheriting(): void
    {
        self::assertFalse(Downloads::resolveDownloadEnabled(null, false, [true]));
    }

    public function test_uses_the_nearest_category_with_an_opinion_not_the_root(): void
    {
        self::assertTrue(Downloads::resolveDownloadEnabled(null, null, [true, false]));
    }

    public function test_skips_inheriting_categories_to_reach_an_ancestor_that_decided(): void
    {
        self::assertFalse(Downloads::resolveDownloadEnabled(null, null, [null, null, false]));
    }

    public function test_treats_an_explicit_false_as_a_block_not_as_absent(): void
    {
        self::assertFalse(Downloads::resolveDownloadEnabled(false, null, []));
    }

    public function test_platforms(): void
    {
        self::assertTrue(Downloads::isPlatformAllowed('BOTH', true));
        self::assertTrue(Downloads::isPlatformAllowed('BOTH', false));
        self::assertTrue(Downloads::isPlatformAllowed('PWA', true));
        self::assertFalse(Downloads::isPlatformAllowed('PWA', false));
        self::assertTrue(Downloads::isPlatformAllowed('WEB', false));
        self::assertFalse(Downloads::isPlatformAllowed('WEB', true));
    }

    public function test_allows_any_member_when_the_audience_is_everyone(): void
    {
        self::assertTrue(Downloads::isAudienceAllowed('ALL_MEMBERS', false, 'u1', [], [], []));
        self::assertFalse(Downloads::isAudienceAllowed('ALL_MEMBERS', false, null, [], [], []));
    }

    public function test_refuses_a_member_outside_the_lists_when_the_audience_is_specific(): void
    {
        self::assertFalse(Downloads::isAudienceAllowed('SPECIFIC', false, 'u1', [], ['u2'], ['g1']));
    }

    public function test_allows_a_named_individual(): void
    {
        self::assertTrue(Downloads::isAudienceAllowed('SPECIFIC', false, 'u1', [], ['u1'], []));
    }

    public function test_allows_a_member_of_a_listed_group(): void
    {
        self::assertTrue(Downloads::isAudienceAllowed('SPECIFIC', false, 'u1', ['g1', 'g2'], [], ['g2']));
    }

    public function test_refuses_a_member_whose_groups_arent_listed(): void
    {
        self::assertFalse(Downloads::isAudienceAllowed('SPECIFIC', false, 'u1', ['g3'], [], ['g1']));
    }

    public function test_always_allows_an_admin_whatever_the_lists_say(): void
    {
        self::assertTrue(Downloads::isAudienceAllowed('SPECIFIC', true, 'admin', [], [], []));
    }
}
