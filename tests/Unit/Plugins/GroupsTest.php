<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins;

use MarineTeam\Plugins\Groups\Groups;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/plugins/groups/src/Groups.php';

/** Small groups, case for case as the original's lib/groups.test.ts. */
final class GroupsTest extends TestCase
{
    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function group(array $extra = []): array
    {
        return $extra + [
            'id' => 'g1', 'slug' => 'tuesday', 'name' => 'Tuesday group', 'description' => 'A home group.',
            'meets_when' => 'Tuesdays, 7.30pm', 'area' => 'North side, near the station', 'address' => '14 Acacia Avenue',
            'published' => 1, 'open_to_join' => 1, 'capacity' => 3, 'waitlist' => 1,
        ];
    }

    /** @return array<string, mixed> */
    private function member(string $userId, string $status, string $role = Groups::MEMBER, string $askedAt = '2026-01-01 10:00:00'): array
    {
        return ['id' => 'm-' . $userId, 'user_id' => $userId, 'name' => ucfirst($userId), 'status' => $status, 'role' => $role, 'muted' => 0, 'created_at' => $askedAt];
    }

    /** @return list<array<string, mixed>> */
    private function members(): array
    {
        return [
            $this->member('naomi', Groups::ACTIVE, Groups::LEADER),
            $this->member('ruth', Groups::ACTIVE),
            $this->member('boaz', Groups::REQUESTED),
            $this->member('orpah', Groups::WAITLIST, Groups::MEMBER, '2026-02-01 10:00:00'),
            $this->member('elimelech', Groups::DECLINED),
        ];
    }

    // -- standingIn -------------------------------------------------------

    public function test_standing_tells_the_four_ways_somebody_can_stand_to_a_group(): void
    {
        $members = $this->members();
        self::assertSame(Groups::ACTIVE, Groups::standingIn($members, 'ruth'));
        self::assertSame(Groups::REQUESTED, Groups::standingIn($members, 'boaz'));
        self::assertSame(Groups::WAITLIST, Groups::standingIn($members, 'orpah'));
        self::assertSame(Groups::NONE, Groups::standingIn($members, 'stranger'));
        self::assertSame(Groups::NONE, Groups::standingIn($members, null));
    }

    public function test_standing_remembers_somebody_who_was_turned_down(): void
    {
        self::assertSame(Groups::DECLINED, Groups::standingIn($this->members(), 'elimelech'));
    }

    // -- canSeeAddress ----------------------------------------------------

    public function test_address_goes_to_people_who_are_actually_in_the_group(): void
    {
        self::assertTrue(Groups::canSeeAddress($this->members(), 'ruth'));
        self::assertTrue(Groups::canSeeAddress($this->members(), 'naomi'));
    }

    public function test_address_is_withheld_from_a_visitor_and_from_a_stranger_with_an_account(): void
    {
        self::assertFalse(Groups::canSeeAddress($this->members(), null));
        self::assertFalse(Groups::canSeeAddress($this->members(), 'stranger'));
    }

    public function test_address_is_withheld_from_somebody_who_has_only_asked_to_join(): void
    {
        self::assertFalse(Groups::canSeeAddress($this->members(), 'boaz'));
    }

    public function test_address_is_withheld_from_somebody_who_was_turned_down(): void
    {
        self::assertFalse(Groups::canSeeAddress($this->members(), 'elimelech'));
    }

    // -- presentGroup -----------------------------------------------------

    public function test_present_leaves_the_address_out_entirely_rather_than_sending_null(): void
    {
        $shape = Groups::presentGroup($this->group(), $this->members(), 'boaz');
        self::assertArrayNotHasKey('address', $shape);
        self::assertStringNotContainsString('Acacia', (string) json_encode($shape));
    }

    public function test_present_still_gives_the_district_which_is_what_somebody_is_choosing_between(): void
    {
        self::assertSame('North side, near the station', Groups::presentGroup($this->group(), $this->members(), null)['area']);
    }

    public function test_present_gives_the_address_to_a_member_a_leader_and_whoever_keeps_the_list(): void
    {
        self::assertSame('14 Acacia Avenue', Groups::presentGroup($this->group(), $this->members(), 'ruth')['address']);
        self::assertSame('14 Acacia Avenue', Groups::presentGroup($this->group(), $this->members(), 'naomi')['address']);
        self::assertSame('14 Acacia Avenue', Groups::presentGroup($this->group(), $this->members(), 'staff', keepsTheList: true)['address']);
    }

    public function test_present_names_the_leaders_because_somebody_has_to_be_asked(): void
    {
        self::assertSame(['Naomi'], Groups::presentGroup($this->group(), $this->members(), null)['leaders']);
        self::assertTrue(Groups::presentGroup($this->group(), [$this->member('ruth', Groups::ACTIVE)], null)['needsLeader']);
    }

    public function test_present_counts_only_people_actually_in_it(): void
    {
        self::assertSame(2, Groups::presentGroup($this->group(), $this->members(), null)['memberCount']);
    }

    // -- activeMembers ----------------------------------------------------

    public function test_active_members_leave_out_requests_and_refusals(): void
    {
        self::assertSame(['naomi', 'ruth'], array_column(Groups::activeMembers($this->members()), 'user_id'));
    }

    // -- joinState --------------------------------------------------------

    public function test_join_offers_to_a_signed_in_stranger(): void
    {
        self::assertSame(Groups::JOIN_OPEN, Groups::joinState($this->group(['capacity' => 10]), $this->members(), 'stranger'));
    }

    public function test_join_asks_a_visitor_to_sign_in_rather_than_pretending_they_can_join(): void
    {
        self::assertSame(Groups::JOIN_SIGN_IN, Groups::joinState($this->group(), $this->members(), null));
    }

    public function test_join_says_so_when_the_answer_is_already_yes_or_already_asked(): void
    {
        self::assertSame(Groups::JOIN_IN, Groups::joinState($this->group(), $this->members(), 'ruth'));
        self::assertSame(Groups::JOIN_ASKED, Groups::joinState($this->group(), $this->members(), 'boaz'));
        self::assertSame(Groups::JOIN_WAITING, Groups::joinState($this->group(), $this->members(), 'orpah'));
        self::assertSame(Groups::JOIN_DECLINED, Groups::joinState($this->group(), $this->members(), 'elimelech'));
    }

    public function test_join_says_full_rather_than_hiding_a_full_group(): void
    {
        // Two in, one asked: the third place is spoken for.
        self::assertSame(Groups::JOIN_WAITING, Groups::joinState($this->group(), $this->members(), 'stranger'));
        self::assertSame(Groups::JOIN_FULL, Groups::joinState($this->group(['waitlist' => 0]), $this->members(), 'stranger'));
    }

    public function test_join_treats_a_place_already_offered_to_somebody_as_taken(): void
    {
        self::assertSame(0, Groups::placesLeft($this->group(), $this->members()));
        self::assertSame(1, Groups::placesLeft($this->group(['capacity' => 4]), $this->members()));
        self::assertNull(Groups::placesLeft($this->group(['capacity' => null]), $this->members()));
    }

    public function test_join_respects_a_group_that_has_closed_its_doors_even_with_room(): void
    {
        self::assertSame(Groups::JOIN_CLOSED, Groups::joinState($this->group(['capacity' => 10, 'open_to_join' => 0]), $this->members(), 'stranger'));
    }

    public function test_join_has_a_sentence_for_every_state(): void
    {
        $strings = require dirname(__DIR__, 3) . '/plugins/groups/lang/en.php';
        foreach ([Groups::JOIN_SIGN_IN, Groups::JOIN_OPEN, Groups::JOIN_ASKED, Groups::JOIN_WAITING, Groups::JOIN_IN, Groups::JOIN_DECLINED, Groups::JOIN_FULL, Groups::JOIN_CLOSED] as $state) {
            self::assertArrayHasKey('groups.join.' . strtolower($state), $strings, $state);
        }
    }

    // -- canLead ----------------------------------------------------------

    public function test_can_lead_is_the_groups_own_leader_and_whoever_keeps_the_list(): void
    {
        self::assertTrue(Groups::canLead($this->members(), 'naomi'));
        self::assertFalse(Groups::canLead($this->members(), 'ruth'));
        self::assertFalse(Groups::canLead($this->members(), null));
        self::assertTrue(Groups::canLead($this->members(), 'staff', keepsTheList: true));
    }

    // -- the waiting list -------------------------------------------------

    public function test_waiting_counts_an_unanswered_request_as_holding_a_place(): void
    {
        // Naomi and Ruth are in, Boaz has asked: nobody moves up.
        self::assertSame([], Groups::promotable($this->group(), $this->members()));
    }

    public function test_waiting_orders_by_when_they_asked_not_by_when_the_rows_come_back(): void
    {
        $members = [
            $this->member('naomi', Groups::ACTIVE, Groups::LEADER),
            $this->member('late', Groups::WAITLIST, Groups::MEMBER, '2026-03-01 10:00:00'),
            $this->member('early', Groups::WAITLIST, Groups::MEMBER, '2026-01-05 10:00:00'),
        ];
        self::assertSame(['m-early', 'm-late'], Groups::promotable($this->group(), $members));
        self::assertSame(1, Groups::waitingPosition($members, 'early'));
        self::assertSame(2, Groups::waitingPosition($members, 'late'));
        self::assertNull(Groups::waitingPosition($members, 'naomi'), 'and nothing for somebody who isn\'t waiting');
    }

    public function test_waiting_offers_exactly_as_many_places_as_there_are(): void
    {
        $members = [
            $this->member('naomi', Groups::ACTIVE, Groups::LEADER),
            $this->member('a', Groups::WAITLIST, Groups::MEMBER, '2026-01-02 10:00:00'),
            $this->member('b', Groups::WAITLIST, Groups::MEMBER, '2026-01-03 10:00:00'),
            $this->member('c', Groups::WAITLIST, Groups::MEMBER, '2026-01-04 10:00:00'),
        ];
        self::assertSame(['m-a', 'm-b'], Groups::promotable($this->group(), $members), 'three places, one taken');
    }

    public function test_waiting_takes_everybody_when_the_group_has_no_stated_size(): void
    {
        $members = [$this->member('a', Groups::WAITLIST, Groups::MEMBER, '2026-01-02 10:00:00'), $this->member('b', Groups::WAITLIST, Groups::MEMBER, '2026-01-03 10:00:00')];
        self::assertSame(['m-a', 'm-b'], Groups::promotable($this->group(['capacity' => null]), $members));
    }

    public function test_waiting_ignores_anybody_who_is_not_waiting(): void
    {
        self::assertSame([], Groups::promotable($this->group(['capacity' => 10]), [$this->member('boaz', Groups::REQUESTED), $this->member('elimelech', Groups::DECLINED)]));
    }

    public function test_waiting_still_keeps_the_address_from_somebody_who_is_only_waiting(): void
    {
        self::assertArrayNotHasKey('address', Groups::presentGroup($this->group(), $this->members(), 'orpah'));
    }
}
