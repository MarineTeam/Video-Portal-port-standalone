<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins;

use MarineTeam\Plugins\Groups\Attendance;
use MarineTeam\Plugins\Groups\Groups;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/plugins/groups/src/Groups.php';
require_once dirname(__DIR__, 3) . '/plugins/groups/src/Attendance.php';

/** The roll, case for case as the original's lib/attendance.test.ts. */
final class AttendanceTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function members(): array
    {
        return [
            ['id' => 'm1', 'user_id' => 'naomi', 'status' => Groups::ACTIVE, 'role' => Groups::LEADER, 'created_at' => '2026-01-01 10:00:00'],
            ['id' => 'm2', 'user_id' => 'ruth', 'status' => Groups::ACTIVE, 'role' => Groups::MEMBER, 'created_at' => '2026-01-01 10:00:00'],
            ['id' => 'm3', 'user_id' => 'boaz', 'status' => Groups::ACTIVE, 'role' => Groups::MEMBER, 'created_at' => '2026-01-01 10:00:00'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function roll(): array
    {
        return [
            ['user_id' => 'naomi', 'status' => Attendance::PRESENT, 'note' => null],
            ['user_id' => 'ruth', 'status' => Attendance::APOLOGIES, 'note' => 'Away with work'],
            ['user_id' => 'boaz', 'status' => Attendance::ABSENT, 'note' => null],
        ];
    }

    // -- canKeepRoll ------------------------------------------------------

    public function test_keeping_the_roll_is_the_groups_leaders_and_whoever_keeps_the_group_list(): void
    {
        self::assertTrue(Attendance::canKeepRoll($this->members(), 'naomi'));
        self::assertTrue(Attendance::canKeepRoll($this->members(), 'staff', keepsTheList: true));
    }

    public function test_keeping_the_roll_is_not_an_ordinary_member(): void
    {
        self::assertFalse(Attendance::canKeepRoll($this->members(), 'ruth'));
        self::assertFalse(Attendance::canKeepRoll($this->members(), null));
    }

    // -- visibleAttendance ------------------------------------------------

    public function test_visible_gives_a_leader_the_roll(): void
    {
        self::assertCount(3, Attendance::visibleAttendance($this->roll(), $this->members(), 'naomi'));
    }

    public function test_visible_gives_a_member_their_own_row_and_nothing_else(): void
    {
        $seen = Attendance::visibleAttendance($this->roll(), $this->members(), 'ruth');
        self::assertCount(1, $seen);
        self::assertSame('ruth', $seen[0]['user_id']);
    }

    public function test_visible_gives_somebody_outside_the_group_nothing_at_all(): void
    {
        self::assertSame([], Attendance::visibleAttendance($this->roll(), $this->members(), 'stranger'));
        self::assertSame([], Attendance::visibleAttendance($this->roll(), $this->members(), null));
    }

    public function test_visible_never_lets_a_member_infer_the_size_of_the_room(): void
    {
        // A list of at most one row, rather than a flag or a count: there is
        // nothing there to total by accident.
        foreach (['ruth', 'boaz'] as $who) {
            self::assertLessThanOrEqual(1, count(Attendance::visibleAttendance($this->roll(), $this->members(), $who)));
        }
    }

    // -- summariseRoll ----------------------------------------------------

    public function test_summary_counts_each_answer_and_everybody_who_was_in_the_room(): void
    {
        self::assertSame(
            ['present' => 1, 'apologies' => 1, 'absent' => 1, 'visitors' => 2, 'inTheRoom' => 3],
            Attendance::summariseRoll($this->roll(), 2),
        );
    }

    public function test_summary_keeps_apologies_out_of_both_present_and_absent(): void
    {
        $summary = Attendance::summariseRoll($this->roll());
        self::assertSame(1, $summary['apologies']);
        self::assertSame(1, $summary['present']);
        self::assertSame(1, $summary['absent']);
    }

    public function test_summary_refuses_a_negative_visitor_count_rather_than_subtracting_from_the_room(): void
    {
        self::assertSame(1, Attendance::summariseRoll($this->roll(), -5)['inTheRoom']);
    }

    // -- attendanceRate ---------------------------------------------------

    /** @return list<array{id: string, date: string, cancelled: bool}> */
    private function meetings(): array
    {
        return [
            ['id' => 'd4', 'date' => '2026-03-24', 'cancelled' => false],
            ['id' => 'd3', 'date' => '2026-03-17', 'cancelled' => true],
            ['id' => 'd2', 'date' => '2026-03-10', 'cancelled' => false],
            ['id' => 'd1', 'date' => '2026-03-03', 'cancelled' => false],
        ];
    }

    public function test_rate_counts_the_last_few_meetings_most_recent_first(): void
    {
        $by = ['d4' => ['ruth' => Attendance::PRESENT], 'd2' => ['ruth' => Attendance::PRESENT], 'd1' => ['ruth' => Attendance::ABSENT]];
        self::assertEqualsWithDelta(2 / 3, Attendance::attendanceRate($this->meetings(), $by, 'ruth'), 0.0001);
    }

    public function test_rate_ignores_a_week_the_group_did_not_meet_at_both_ends(): void
    {
        $by = ['d4' => ['ruth' => Attendance::PRESENT], 'd3' => ['ruth' => Attendance::ABSENT], 'd2' => ['ruth' => Attendance::PRESENT], 'd1' => ['ruth' => Attendance::PRESENT]];
        self::assertSame(1.0, Attendance::attendanceRate($this->meetings(), $by, 'ruth'), 'the cancelled week counts against nobody');
    }

    public function test_rate_counts_a_member_with_no_row_at_all_as_missed(): void
    {
        self::assertSame(0.0, Attendance::attendanceRate($this->meetings(), [], 'ruth'));
    }

    public function test_rate_does_not_count_apologies_as_coming(): void
    {
        $by = ['d4' => ['ruth' => Attendance::APOLOGIES], 'd2' => ['ruth' => Attendance::APOLOGIES], 'd1' => ['ruth' => Attendance::PRESENT]];
        self::assertEqualsWithDelta(1 / 3, Attendance::attendanceRate($this->meetings(), $by, 'ruth'), 0.0001);
    }

    public function test_rate_honours_the_window(): void
    {
        $by = ['d4' => ['ruth' => Attendance::PRESENT], 'd2' => ['ruth' => Attendance::ABSENT], 'd1' => ['ruth' => Attendance::ABSENT]];
        self::assertSame(1.0, Attendance::attendanceRate($this->meetings(), $by, 'ruth', 1));
    }

    // -- quietlyMissing ---------------------------------------------------

    /** @return list<array{id: string, date: string, cancelled: bool}> */
    private function threeHeld(): array
    {
        return [
            ['id' => 'c', 'date' => '2026-03-24', 'cancelled' => false],
            ['id' => 'b', 'date' => '2026-03-17', 'cancelled' => false],
            ['id' => 'a', 'date' => '2026-03-10', 'cancelled' => false],
        ];
    }

    public function test_quietly_missing_names_somebody_who_has_missed_the_last_three_without_a_word(): void
    {
        $by = ['c' => ['naomi' => Attendance::PRESENT], 'b' => ['naomi' => Attendance::PRESENT], 'a' => ['naomi' => Attendance::PRESENT]];
        self::assertSame(['ruth'], Attendance::quietlyMissing($this->threeHeld(), $by, ['naomi', 'ruth']));
    }

    public function test_quietly_missing_takes_somebody_off_the_list_the_moment_they_send_apologies(): void
    {
        $by = ['c' => ['ruth' => Attendance::APOLOGIES]];
        self::assertSame([], Attendance::quietlyMissing($this->threeHeld(), $by, ['ruth']));
    }

    public function test_quietly_missing_keeps_somebody_whose_apologies_were_weeks_ago_and_has_said_nothing_since(): void
    {
        $by = ['a' => ['ruth' => Attendance::APOLOGIES]];
        self::assertSame(['ruth'], Attendance::quietlyMissing($this->threeHeld(), $by, ['ruth']));
    }

    public function test_quietly_missing_says_nothing_at_all_until_there_are_enough_meetings_to_judge(): void
    {
        self::assertSame([], Attendance::quietlyMissing(array_slice($this->threeHeld(), 0, 2), [], ['ruth']));
    }

    public function test_quietly_missing_skips_cancelled_meetings_when_counting_the_run(): void
    {
        $meetings = [
            ['id' => 'd', 'date' => '2026-03-31', 'cancelled' => true],
            ...$this->threeHeld(),
        ];
        $by = ['c' => ['ruth' => Attendance::PRESENT]];
        self::assertSame([], Attendance::quietlyMissing($meetings, $by, ['ruth']), 'she came to the last one that happened');
    }

    // -- canRecordFor -----------------------------------------------------

    public function test_can_record_allows_the_night_itself_and_anything_before_it(): void
    {
        self::assertTrue(Attendance::canRecordFor('2026-03-24', '2026-03-24'));
        self::assertTrue(Attendance::canRecordFor('2026-03-17', '2026-03-24'));
    }

    public function test_can_record_refuses_a_meeting_that_has_not_happened(): void
    {
        self::assertFalse(Attendance::canRecordFor('2026-03-31', '2026-03-24'));
    }
}
