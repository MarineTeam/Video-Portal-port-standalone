<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins;

use MarineTeam\Plugins\Events\Recurrence;
use MarineTeam\Plugins\Events\Series;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/plugins/events/src/Recurrence.php';
require_once dirname(__DIR__, 3) . '/plugins/events/src/Series.php';

/** Repeating events, case for case as the original's lib/event-series.test.ts. */
final class EventSeriesTest extends TestCase
{
    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function series(array $extra = []): array
    {
        return $extra + [
            'rule' => 'FREQ=WEEKLY;BYDAY=TU',
            'time_zone' => 'Europe/London',
            'start_date' => '2026-03-03',
            'start_time' => '19:30',
            'duration_minutes' => 90,
            'all_day' => false,
            'title' => 'Prayer meeting',
            'opens_days_before' => 14,
            'closes_days_before' => 2,
        ];
    }

    // -- planOccurrences --------------------------------------------------

    public function test_plan_keeps_the_wall_clock_the_same_on_both_sides_of_a_clock_change(): void
    {
        $plan = Series::planOccurrences($this->series(), ['2026-03-24', '2026-04-07']);
        self::assertSame('2026-03-24T19:30:00+00:00', $plan[0]['startsAt']->format('c'), 'GMT');
        self::assertSame('2026-04-07T18:30:00+00:00', $plan[1]['startsAt']->format('c'), 'BST, and still half past seven to whoever comes');
    }

    public function test_plan_ends_each_occurrence_its_own_length_later(): void
    {
        $plan = Series::planOccurrences($this->series(), ['2026-03-24']);
        self::assertSame('2026-03-24T21:00:00+00:00', $plan[0]['endsAt']->format('c'));
    }

    public function test_plan_leaves_the_finish_open_when_no_length_is_given(): void
    {
        self::assertNull(Series::planOccurrences($this->series(['duration_minutes' => null]), ['2026-03-24'])[0]['endsAt']);
    }

    public function test_plan_starts_an_all_day_occurrence_at_local_midnight_whatever_the_stored_time_says(): void
    {
        $plan = Series::planOccurrences($this->series(['all_day' => true, 'duration_minutes' => null]), ['2026-07-04']);
        self::assertSame('2026-07-03T23:00:00+00:00', $plan[0]['startsAt']->format('c'), 'midnight in London in July');
    }

    public function test_plan_moves_the_sign_up_window_with_each_date_rather_than_copying_one_pair_of_instants(): void
    {
        $plan = Series::planOccurrences($this->series(), ['2026-03-24', '2026-12-22']);
        self::assertSame('2026-03-10', $plan[0]['opensAt']->format('Y-m-d'));
        self::assertSame('2026-12-08', $plan[1]['opensAt']->format('Y-m-d'), 'December\'s sign-up does not close in September');
        self::assertSame('2026-12-20', $plan[1]['closesAt']->format('Y-m-d'));
    }

    public function test_plan_closes_at_the_end_of_the_events_own_day_when_told_zero_days_before(): void
    {
        $plan = Series::planOccurrences($this->series(['closes_days_before' => 0]), ['2026-03-24']);
        self::assertSame('2026-03-24T23:59:59+00:00', $plan[0]['closesAt']->format('c'));
    }

    public function test_plan_leaves_the_window_open_at_both_ends_when_neither_is_set(): void
    {
        $plan = Series::planOccurrences($this->series(['opens_days_before' => null, 'closes_days_before' => null]), ['2026-03-24']);
        self::assertNull($plan[0]['opensAt']);
        self::assertNull($plan[0]['closesAt']);
    }

    // -- datesToCreate ----------------------------------------------------

    public function test_dates_to_create_skips_what_already_exists(): void
    {
        self::assertSame(['2026-03-17'], Series::datesToCreate(['2026-03-03', '2026-03-10', '2026-03-17'], ['2026-03-03', '2026-03-10'], []));
    }

    public function test_dates_to_create_does_not_put_back_a_date_somebody_took_out(): void
    {
        self::assertSame(['2026-03-17'], Series::datesToCreate(['2026-03-10', '2026-03-17'], [], ['2026-03-10']));
    }

    public function test_dates_to_create_is_a_no_op_when_everything_is_already_there(): void
    {
        self::assertSame([], Series::datesToCreate(['2026-03-10'], ['2026-03-10'], []));
        self::assertSame([], Series::datesToCreate([], [], []));
    }

    // -- stopSeriesPlan ---------------------------------------------------

    /** @return list<array{id: string, date: string, registrations: int}> */
    private function dates(): array
    {
        return [
            ['id' => 'past-empty', 'date' => '2026-02-24', 'registrations' => 0],
            ['id' => 'past-booked', 'date' => '2026-03-03', 'registrations' => 2],
            ['id' => 'today', 'date' => '2026-03-10', 'registrations' => 0],
            ['id' => 'future-booked', 'date' => '2026-03-17', 'registrations' => 1],
            ['id' => 'future-empty', 'date' => '2026-03-24', 'registrations' => 0],
        ];
    }

    public function test_stop_never_deletes_an_occurrence_somebody_signed_up_for(): void
    {
        $plan = Series::stopSeriesPlan($this->dates(), '2026-03-10');
        self::assertNotContains('future-booked', $plan['delete']);
        self::assertContains('future-booked', $plan['keep']);
    }

    public function test_stop_counts_today_as_still_to_come(): void
    {
        self::assertContains('today', Series::stopSeriesPlan($this->dates(), '2026-03-10')['delete']);
    }

    public function test_stop_keeps_the_past_even_when_nobody_came(): void
    {
        $plan = Series::stopSeriesPlan($this->dates(), '2026-03-10');
        self::assertSame(['past-empty', 'past-booked', 'future-booked'], $plan['keep']);
        self::assertSame(['today', 'future-empty'], $plan['delete']);
    }

    // -- seriesProblems ---------------------------------------------------

    public function test_problems_passes_a_series_that_is_fit_to_save(): void
    {
        self::assertSame([], Series::seriesProblems($this->series()));
        self::assertSame([], Series::seriesProblems($this->series(['all_day' => true, 'start_time' => null])));
    }

    public function test_problems_catches_a_rule_it_cannot_expand(): void
    {
        self::assertNotSame([], Series::seriesProblems($this->series(['rule' => 'every tuesday'])));
    }

    public function test_problems_catches_a_time_zone_that_does_not_exist(): void
    {
        self::assertStringContainsString('Mars/Olympus', implode(' ', Series::seriesProblems($this->series(['time_zone' => 'Mars/Olympus']))));
    }

    public function test_problems_wants_a_time_unless_it_is_all_day(): void
    {
        self::assertStringContainsString('time of day', implode(' ', Series::seriesProblems($this->series(['start_time' => null]))));
    }

    public function test_problems_catches_a_sign_up_window_that_shuts_before_it_opens(): void
    {
        self::assertStringContainsString('shut before', implode(' ', Series::seriesProblems($this->series(['opens_days_before' => 2, 'closes_days_before' => 14]))));
    }

    public function test_problems_reports_everything_wrong_at_once_not_just_the_first(): void
    {
        $problems = Series::seriesProblems(['rule' => 'nonsense', 'time_zone' => 'Mars/Olympus', 'start_date' => 'soon', 'start_time' => null, 'all_day' => false, 'title' => ' ']);
        self::assertCount(5, $problems);
    }

    // -- describeSeries ---------------------------------------------------

    public function test_describe_says_it_the_way_somebody_would(): void
    {
        self::assertSame('Every week on Tuesday at 19:30', Series::describeSeries($this->series()));
        self::assertSame('Every month on last Saturday', Series::describeSeries($this->series(['rule' => 'FREQ=MONTHLY;BYDAY=-1SA', 'all_day' => true])));
    }

    public function test_describe_says_something_rather_than_throwing_on_a_rule_that_cannot_be_read(): void
    {
        self::assertNotSame('', Series::describeSeries($this->series(['rule' => 'FREQ=NEVER'])));
    }

    // -- horizonEnd -------------------------------------------------------

    public function test_horizon_looks_half_a_year_ahead(): void
    {
        self::assertSame('2026-09-03', Series::horizonEnd(new \DateTimeImmutable('2026-03-03 10:00:00', new \DateTimeZone('UTC'))));
    }

    // -- ruleFromChoices --------------------------------------------------

    public function test_rule_falls_back_to_the_day_the_first_date_lands_on(): void
    {
        self::assertSame('FREQ=WEEKLY;BYDAY=TU', Series::ruleFromChoices(['shape' => 'WEEKLY'], '2026-03-03'));
    }

    public function test_rule_orders_the_days_it_was_given_whatever_order_they_were_clicked_in(): void
    {
        self::assertSame('FREQ=WEEKLY;BYDAY=TU,TH,SU', Series::ruleFromChoices(['shape' => 'WEEKLY', 'days' => ['SU', 'TH', 'TU', 'TH']], '2026-03-03'));
    }

    public function test_rule_reads_the_monthly_shapes_off_the_first_date(): void
    {
        self::assertSame('FREQ=MONTHLY;BYMONTHDAY=3', Series::ruleFromChoices(['shape' => 'MONTHLY_DATE'], '2026-03-03'));
        self::assertSame('FREQ=MONTHLY;BYDAY=1TU', Series::ruleFromChoices(['shape' => 'MONTHLY_NTH'], '2026-03-03'));
        self::assertSame('FREQ=MONTHLY;BYDAY=-1SA', Series::ruleFromChoices(['shape' => 'MONTHLY_LAST'], '2026-03-28'));
    }

    public function test_rule_carries_the_interval_and_the_ending(): void
    {
        self::assertSame('FREQ=WEEKLY;INTERVAL=2;BYDAY=TU;COUNT=8', Series::ruleFromChoices(['shape' => 'WEEKLY', 'interval' => 2, 'count' => 8], '2026-03-03'));
        self::assertSame('FREQ=DAILY;UNTIL=20261224', Series::ruleFromChoices(['shape' => 'DAILY', 'until' => '2026-12-24'], '2026-03-03'));
    }

    public function test_rule_only_ever_builds_rules_the_parser_accepts(): void
    {
        foreach (Series::SHAPES as $shape) {
            $rule = Series::ruleFromChoices(['shape' => $shape, 'days' => ['WE'], 'interval' => 3], '2026-03-31');
            self::assertSame($rule, Recurrence::formatRule(Recurrence::parseRule($rule)), $shape);
        }
    }

    // -- monthPositionOf --------------------------------------------------

    public function test_month_position_knows_which_weekday_of_the_month_a_date_is(): void
    {
        self::assertSame(['day' => 'TU', 'week' => 1, 'fromEnd' => -5], Recurrence::monthPositionOf('2026-03-03'));
        self::assertSame(['day' => 'SA', 'week' => 4, 'fromEnd' => -1], Recurrence::monthPositionOf('2026-03-28'));
    }
}
