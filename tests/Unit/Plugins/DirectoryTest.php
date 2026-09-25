<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins;

use MarineTeam\Plugins\Profiles\Directory;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/plugins/profiles/src/Directory.php';

/** The member directory's rules, case for case as the original's lib/directory.test.ts. */
final class DirectoryTest extends TestCase
{
    private static function user(array $over = []): array
    {
        return $over + [
            'id' => 'u1', 'email' => 'ruth@example.org', 'name' => 'Ruth M', 'display_name' => null, 'phone' => '+44 7700 900123',
            'authorized' => 1, 'directory_listed' => 1, 'directory_show_email' => 0, 'directory_show_phone' => 0, 'directory_note' => null,
            'role' => 'ADMIN', 'password_hash' => 'secret', 'picture' => 'https://example.org/p.png',
        ];
    }

    public function test_listed_needs_them_to_have_asked(): void
    {
        self::assertTrue(Directory::listed(self::user()));
        self::assertFalse(Directory::listed(self::user(['directory_listed' => 0])));
    }

    public function test_listed_drops_somebody_whose_access_was_withdrawn(): void
    {
        self::assertFalse(Directory::listed(self::user(['authorized' => 0])));
    }

    public function test_listed_drops_somebody_with_no_name_to_show(): void
    {
        self::assertFalse(Directory::listed(self::user(['name' => null, 'display_name' => '  '])));
    }

    public function test_directory_name_prefers_the_name_they_chose(): void
    {
        self::assertSame('Ruth the Moabite', Directory::directoryName(self::user(['display_name' => ' Ruth the Moabite '])));
        self::assertSame('Ruth M', Directory::directoryName(self::user()));
    }

    public function test_directory_name_never_falls_back_to_an_email_address(): void
    {
        self::assertNull(Directory::directoryName(self::user(['name' => 'ruth@example.org', 'display_name' => null])));
        self::assertNull(Directory::directoryName(self::user(['name' => null])));
    }

    public function test_present_member_publishes_a_name_and_nothing_else_by_default(): void
    {
        self::assertSame(['name' => 'Ruth M'], Directory::presentMember(self::user()));
    }

    public function test_present_member_treats_each_contact_detail_as_its_own_yes(): void
    {
        self::assertSame(['name' => 'Ruth M', 'email' => 'ruth@example.org'], Directory::presentMember(self::user(['directory_show_email' => 1])));
        self::assertSame(['name' => 'Ruth M', 'phone' => '+44 7700 900123'], Directory::presentMember(self::user(['directory_show_phone' => 1])));
    }

    public function test_present_member_leaves_the_field_off_rather_than_sending_an_empty_one(): void
    {
        $m = Directory::presentMember(self::user(['directory_show_phone' => 1, 'phone' => '', 'directory_note' => '  ']));
        self::assertSame(['name' => 'Ruth M'], $m);
    }

    public function test_present_member_carries_no_other_account_field_out_with_it(): void
    {
        $m = Directory::presentMember(self::user(['directory_show_email' => 1, 'directory_show_phone' => 1, 'directory_note' => 'Welcome team']));
        self::assertSame(['name', 'note', 'email', 'phone'], array_keys($m));
    }

    public function test_visible_directory_shows_only_those_who_asked_and_still_have_access_by_name(): void
    {
        $list = Directory::visibleDirectory([
            self::user(['name' => 'Naomi']),
            self::user(['name' => 'Orpah', 'directory_listed' => 0]),
            self::user(['name' => 'Boaz', 'authorized' => 0]),
            self::user(['name' => null, 'display_name' => null]),
        ]);
        self::assertSame([['name' => 'Naomi']], $list);
    }

    public function test_visible_directory_sorts_without_caring_about_case(): void
    {
        $names = array_column(Directory::visibleDirectory([self::user(['name' => 'naomi']), self::user(['name' => 'Boaz']), self::user(['name' => 'ruth'])]), 'name');
        self::assertSame(['Boaz', 'naomi', 'ruth'], $names);
    }

    public function test_search_matches_a_name_and_a_note(): void
    {
        $members = [['name' => 'Naomi'], ['name' => 'Boaz', 'note' => 'Harvest team']];
        self::assertSame([['name' => 'Naomi']], Directory::searchDirectory($members, 'nao'));
        self::assertSame([['name' => 'Boaz', 'note' => 'Harvest team']], Directory::searchDirectory($members, 'HARVEST'));
    }

    public function test_search_never_matches_a_contact_detail_even_a_published_one(): void
    {
        $members = [['name' => 'Boaz', 'email' => 'boaz@example.org', 'phone' => '+44 7700 900999']];
        self::assertSame([], Directory::searchDirectory($members, 'boaz@'));
        self::assertSame([], Directory::searchDirectory($members, '900999'));
    }

    public function test_search_returns_everybody_for_an_empty_query(): void
    {
        $members = [['name' => 'Naomi'], ['name' => 'Boaz']];
        self::assertSame($members, Directory::searchDirectory($members, '  '));
    }

    public function test_directory_standing_says_plainly_where_somebody_stands(): void
    {
        self::assertSame(['directory.standingOut', []], Directory::directoryStanding(self::user(['directory_listed' => 0])));
        self::assertSame(['directory.standingNoAccess', []], Directory::directoryStanding(self::user(['authorized' => 0])));
        self::assertSame(['directory.standingNoName', []], Directory::directoryStanding(self::user(['name' => null])));
        self::assertSame(['directory.standingName', ['name' => 'Ruth M']], Directory::directoryStanding(self::user()));
        self::assertSame(['directory.standingEmailPhone', ['name' => 'Ruth M']], Directory::directoryStanding(self::user(['directory_show_email' => 1, 'directory_show_phone' => 1])));
    }
}
