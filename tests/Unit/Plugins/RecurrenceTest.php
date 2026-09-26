<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins;

use MarineTeam\Plugins\Events\Recurrence;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/plugins/events/src/Recurrence.php';

/** Repeat rules, case for case as the original's lib/recurrence.test.ts. */
final class RecurrenceTest extends TestCase
{
    // -- parseRule --------------------------------------------------------

    public function test_parse_reads_the_parts_a_church_diary_uses(): void
    {
        $parts = Recurrence::parseRule('FREQ=WEEKLY;INTERVAL=2;BYDAY=TU,TH;COUNT=8');
        self::assertSame('WEEKLY', $parts['freq']);
        self::assertSame(2, $parts['interval']);
        self::assertSame([['pos' => null, 'day' => 'TU'], ['pos' => null, 'day' => 'TH']], $parts['byday']);
        self::assertSame(8, $parts['count']);
    }

    public function test_parse_accepts_the_rrule_prefix_an_ics_line_carries(): void
    {
        self::assertSame('DAILY', Recurrence::parseRule('RRULE:FREQ=DAILY')['freq']);
    }

    public function test_parse_reads_a_numbered_weekday(): void
    {
        self::assertSame([['pos' => -1, 'day' => 'SA']], Recurrence::parseRule('FREQ=MONTHLY;BYDAY=-1SA')['byday']);
        self::assertSame([['pos' => 1, 'day' => 'SU']], Recurrence::parseRule('FREQ=MONTHLY;BYDAY=1SU')['byday']);
    }

    public function test_parse_drops_the_time_of_day_from_until_which_is_a_day_here(): void
    {
        self::assertSame('2026-12-24', Recurrence::parseRule('FREQ=WEEKLY;UNTIL=20261224T235959Z')['until']);
    }

    public function test_parse_refuses_a_part_it_cannot_compute_rather_than_ignoring_it(): void
    {
        foreach (['FREQ=MONTHLY;BYSETPOS=-1;BYDAY=MO', 'FREQ=WEEKLY;BYWEEKNO=3', 'FREQ=DAILY;BYHOUR=9', 'FREQ=WEEKLY;WKST=SU'] as $rule) {
            $this->refuses($rule);
        }
    }

    public function test_parse_refuses_the_combinations_that_do_not_mean_what_they_look_like(): void
    {
        foreach ([
            'FREQ=WEEKLY;BYDAY=1MO',
            'FREQ=WEEKLY;BYMONTHDAY=15',
            'FREQ=MONTHLY;BYDAY=1MO;BYMONTHDAY=15',
            'FREQ=MONTHLY;BYMONTH=3',
            'FREQ=WEEKLY;COUNT=5;UNTIL=20261224',
        ] as $rule) {
            $this->refuses($rule);
        }
    }

    public function test_parse_refuses_rubbish(): void
    {
        foreach (['', 'every tuesday', 'FREQ=HOURLY', 'FREQ=WEEKLY;INTERVAL=0', 'FREQ=WEEKLY;BYDAY=FUNDAY', 'FREQ=MONTHLY;BYMONTHDAY=40', 'FREQ=WEEKLY;UNTIL=nonsense'] as $rule) {
            $this->refuses($rule);
        }
    }

    private function refuses(string $rule): void
    {
        try {
            Recurrence::parseRule($rule);
            self::fail("Should have refused: $rule");
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
    }

    // -- formatRule -------------------------------------------------------

    public function test_format_round_trips_every_rule_this_app_writes(): void
    {
        foreach ([
            'FREQ=DAILY',
            'FREQ=WEEKLY;BYDAY=TU,TH',
            'FREQ=WEEKLY;INTERVAL=2;BYDAY=WE;COUNT=10',
            'FREQ=MONTHLY;BYMONTHDAY=15',
            'FREQ=MONTHLY;BYDAY=-1SA',
            'FREQ=MONTHLY;INTERVAL=3;BYDAY=2SU;UNTIL=20271231',
            'FREQ=YEARLY;BYMONTH=12;BYMONTHDAY=25',
        ] as $rule) {
            self::assertSame($rule, Recurrence::formatRule(Recurrence::parseRule($rule)), $rule);
        }
    }

    // -- occurrencesBetween -----------------------------------------------

    /** @return list<string> */
    private function between(string $rule, string $start, string $from, string $to): array
    {
        return Recurrence::occurrencesBetween($rule, $start, $from, $to);
    }

    public function test_occurrences_always_count_the_start_even_when_the_rule_would_not_have_picked_it(): void
    {
        // A Monday start on a Tuesdays rule: the first date is still one.
        self::assertSame(['2026-03-02', '2026-03-03', '2026-03-10'], $this->between('FREQ=WEEKLY;BYDAY=TU', '2026-03-02', '2026-03-01', '2026-03-12'));
    }

    public function test_occurrences_keep_the_rest_of_the_starting_week(): void
    {
        self::assertSame(['2026-03-03', '2026-03-05', '2026-03-10'], $this->between('FREQ=WEEKLY;BYDAY=TU,TH', '2026-03-03', '2026-03-01', '2026-03-10'));
    }

    public function test_occurrences_count_intervals_in_whole_weeks_from_the_starting_week(): void
    {
        self::assertSame(['2026-03-03', '2026-03-17', '2026-03-31'], $this->between('FREQ=WEEKLY;INTERVAL=2;BYDAY=TU', '2026-03-03', '2026-03-01', '2026-04-05'));
    }

    public function test_occurrences_do_the_first_sunday_of_the_month(): void
    {
        self::assertSame(['2026-03-01', '2026-04-05', '2026-05-03'], $this->between('FREQ=MONTHLY;BYDAY=1SU', '2026-03-01', '2026-03-01', '2026-05-31'));
    }

    public function test_occurrences_do_the_last_saturday_whether_the_month_has_four_or_five(): void
    {
        self::assertSame(['2026-01-31', '2026-02-28', '2026-03-28'], $this->between('FREQ=MONTHLY;BYDAY=-1SA', '2026-01-31', '2026-01-01', '2026-03-31'));
    }

    public function test_occurrences_skip_a_month_too_short_for_the_day_rather_than_sliding_to_the_28th(): void
    {
        self::assertSame(['2026-01-31', '2026-03-31', '2026-05-31'], $this->between('FREQ=MONTHLY;BYMONTHDAY=31', '2026-01-31', '2026-01-01', '2026-06-30'));
    }

    public function test_occurrences_do_the_last_day_of_every_month(): void
    {
        self::assertSame(['2026-01-31', '2026-02-28', '2026-03-31'], $this->between('FREQ=MONTHLY;BYMONTHDAY=-1', '2026-01-31', '2026-01-01', '2026-03-31'));
    }

    public function test_occurrences_do_a_yearly_date_including_one_that_only_exists_in_leap_years(): void
    {
        self::assertSame(['2025-12-25', '2026-12-25'], $this->between('FREQ=YEARLY', '2025-12-25', '2025-01-01', '2026-12-31'));
        self::assertSame(['2024-02-29', '2028-02-29'], $this->between('FREQ=YEARLY;BYMONTH=2;BYMONTHDAY=29', '2024-02-29', '2024-01-01', '2028-12-31'));
    }

    public function test_occurrences_stop_at_count_counting_from_the_start_and_not_from_the_window(): void
    {
        self::assertSame(['2026-03-17', '2026-03-24'], $this->between('FREQ=WEEKLY;BYDAY=TU;COUNT=4', '2026-03-03', '2026-03-15', '2026-12-31'));
    }

    public function test_occurrences_stop_at_until_inclusive(): void
    {
        self::assertSame(['2026-03-03', '2026-03-10', '2026-03-17'], $this->between('FREQ=WEEKLY;BYDAY=TU;UNTIL=20260317', '2026-03-03', '2026-01-01', '2026-12-31'));
    }

    public function test_occurrences_answer_only_the_window_asked_for(): void
    {
        self::assertSame(['2026-06-02', '2026-06-09'], $this->between('FREQ=WEEKLY;BYDAY=TU', '2026-03-03', '2026-06-01', '2026-06-14'));
    }

    public function test_occurrences_give_nothing_for_a_window_that_ends_before_it_starts(): void
    {
        self::assertSame([], $this->between('FREQ=DAILY', '2026-03-03', '2026-06-14', '2026-06-01'));
    }

    public function test_occurrences_cap_a_rule_that_would_otherwise_answer_with_a_decade_of_days(): void
    {
        $days = $this->between('FREQ=DAILY', '2020-01-01', '2020-01-01', '2030-01-01');
        self::assertCount(Recurrence::MAX_RESULTS, $days);
        self::assertSame('2020-01-01', $days[0]);
    }

    public function test_occurrences_give_up_on_a_rule_that_can_never_land_again(): void
    {
        // The 30th of February: the start is an occurrence, and nothing else is.
        self::assertSame(['2026-02-28'], $this->between('FREQ=YEARLY;BYMONTH=2;BYMONTHDAY=30', '2026-02-28', '2026-01-01', '2036-01-01'));
    }

    // -- describeRule -----------------------------------------------------

    public function test_describe_says_what_a_rule_means_in_words_somebody_can_check(): void
    {
        self::assertSame('Every month on last Saturday', Recurrence::describeRule('FREQ=MONTHLY;BYDAY=-1SA'));
        self::assertSame('Every week on Tuesday and Thursday', Recurrence::describeRule('FREQ=WEEKLY;BYDAY=TU,TH'));
        self::assertSame('Every other week on Wednesday, 10 times', Recurrence::describeRule('FREQ=WEEKLY;INTERVAL=2;BYDAY=WE;COUNT=10'));
        self::assertSame('Every year on the 25th in December', Recurrence::describeRule('FREQ=YEARLY;BYMONTH=12;BYMONTHDAY=25'));
        self::assertSame('every tuesday', Recurrence::describeRule('every tuesday'), 'says something rather than throwing on a rule that can\'t be read');
    }

    public function test_describe_gets_the_ordinal_suffixes_right(): void
    {
        self::assertSame(['1st', '2nd', '3rd', '4th', '11th', '12th', '13th', '21st', '22nd', '23rd'], array_map([Recurrence::class, 'ordinal'], [1, 2, 3, 4, 11, 12, 13, 21, 22, 23]));
    }

    // -- zonedInstant -----------------------------------------------------

    public function test_zoned_instant_keeps_the_wall_clock_across_a_daylight_saving_change(): void
    {
        self::assertSame('2026-01-06T19:30:00+00:00', Recurrence::zonedInstant('2026-01-06', '19:30', 'Europe/London')->format('c'));
        self::assertSame('2026-07-07T18:30:00+00:00', Recurrence::zonedInstant('2026-07-07', '19:30', 'Europe/London')->format('c'));
    }

    public function test_zoned_instant_works_west_of_greenwich_too(): void
    {
        self::assertSame('2026-01-07T00:30:00+00:00', Recurrence::zonedInstant('2026-01-06', '19:30', 'America/New_York')->format('c'));
    }

    public function test_zoned_instant_moves_an_hour_that_never_happens_forward_not_backward(): void
    {
        // 01:30 does not exist in London on 29 March 2026; people arrive at 02:30.
        self::assertSame('2026-03-29T01:30:00+00:00', Recurrence::zonedInstant('2026-03-29', '01:30', 'Europe/London')->format('c'));
    }

    public function test_zoned_instant_takes_the_first_of_an_hour_that_happens_twice(): void
    {
        // 01:30 happens twice on 25 October 2026: 00:30Z (BST) and 01:30Z (GMT).
        self::assertSame('2026-10-25T00:30:00+00:00', Recurrence::zonedInstant('2026-10-25', '01:30', 'Europe/London')->format('c'));
    }

    public function test_zoned_instant_handles_a_zone_whose_offset_is_not_a_whole_hour(): void
    {
        self::assertSame('2026-01-06T13:45:00+00:00', Recurrence::zonedInstant('2026-01-06', '19:30', 'Asia/Kathmandu')->format('c'));
    }

    public function test_zoned_instant_treats_a_zone_it_does_not_know_as_utc_rather_than_throwing(): void
    {
        self::assertSame('2026-01-06T19:30:00+00:00', Recurrence::zonedInstant('2026-01-06', '19:30', 'Europe/Londn')->format('c'));
    }

    public function test_zoned_instant_refuses_a_time_of_day_it_cannot_read(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Recurrence::zonedInstant('2026-01-06', 'half seven', 'Europe/London');
    }

    // -- dayInZone / isKnownTimeZone --------------------------------------

    public function test_day_in_zone_gives_the_local_day_which_need_not_be_the_utc_one(): void
    {
        $instant = new \DateTimeImmutable('2026-01-07T00:30:00Z');
        self::assertSame('2026-01-06', Recurrence::dayInZone($instant, 'America/New_York'));
        self::assertSame('2026-01-07', Recurrence::dayInZone($instant, 'Europe/London'));
    }

    public function test_knows_a_real_zone_from_a_typo(): void
    {
        self::assertTrue(Recurrence::isKnownTimeZone('Europe/London'));
        self::assertFalse(Recurrence::isKnownTimeZone('Europe/Londn'));
    }
}
