<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins;

use MarineTeam\Plugins\Schedules\Duplicates;
use MarineTeam\Plugins\Schedules\Logic;
use MarineTeam\Plugins\Schedules\Visibility;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/plugins/schedules/src/Names.php';
require_once dirname(__DIR__, 3) . '/plugins/schedules/src/Logic.php';
require_once dirname(__DIR__, 3) . '/plugins/schedules/src/Visibility.php';
require_once dirname(__DIR__, 3) . '/plugins/schedules/src/Duplicates.php';

/**
 * The calendar's own logic, its visibility rule and its duplicate finder:
 * lib/schedules/logic.test.ts, visibility.test.ts and duplicates.test.ts.
 */
final class SchedulesLogicTest extends TestCase
{
    private const TODAY = '2026-06-10';

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function event(string $id, string $date, array $extra = []): array
    {
        return $extra + [
            'id' => $id,
            'scheduleId' => 'breakbread',
            'date' => $date,
            'endDate' => null,
            'allDay' => true,
            'startTime' => null,
            'status' => Logic::CONFIRMED,
            'people' => [['id' => 'p-dave', 'name' => 'Dave'], ['id' => 'p-sue', 'name' => 'Sue']],
        ];
    }

    /** What a page works with: cancelled dates are dropped by filterEvents first. */
    private function live(): array
    {
        return Logic::filterEvents($this->events());
    }

    /** @return list<array<string, mixed>> */
    private function events(): array
    {
        return [
            $this->event('past', '2026-06-01'),
            $this->event('today', self::TODAY),
            $this->event('soon', '2026-06-14', ['scheduleId' => 'welcome', 'people' => [['id' => 'p-bob', 'name' => 'Bob']]]),
            $this->event('later', '2026-07-05'),
            $this->event('far', '2026-12-25'),
            $this->event('off', '2026-06-21', ['status' => Logic::CANCELLED]),
        ];
    }

    // -- involvesPerson / filterEvents ------------------------------------

    public function test_involves_matches_on_the_person_id_not_the_name(): void
    {
        self::assertTrue(Logic::involvesPerson($this->event('a', self::TODAY), 'p-dave'));
        self::assertFalse(Logic::involvesPerson($this->event('a', self::TODAY), 'Dave'));
    }

    public function test_filter_returns_everything_when_no_filter_is_given(): void
    {
        self::assertCount(5, Logic::filterEvents($this->events()), 'the cancelled one apart');
    }

    public function test_filter_by_person_by_schedule_and_both(): void
    {
        self::assertSame(['soon'], array_column(Logic::filterEvents($this->events(), ['personId' => 'p-bob']), 'id'));
        self::assertSame(['soon'], array_column(Logic::filterEvents($this->events(), ['scheduleIds' => ['welcome']]), 'id'));
        self::assertSame([], array_column(Logic::filterEvents($this->events(), ['personId' => 'p-dave', 'scheduleIds' => ['welcome']]), 'id'));
    }

    public function test_filter_treats_an_empty_schedule_list_as_all_and_can_include_cancelled(): void
    {
        self::assertCount(5, Logic::filterEvents($this->events(), ['scheduleIds' => []]));
        self::assertCount(6, Logic::filterEvents($this->events(), ['includeCancelled' => true]));
    }

    // -- sortEvents -------------------------------------------------------

    public function test_sort_is_by_date_then_time_with_all_day_first(): void
    {
        $events = [
            $this->event('evening', self::TODAY, ['allDay' => false, 'startTime' => '19:30']),
            $this->event('morning', self::TODAY, ['allDay' => false, 'startTime' => '09:00']),
            $this->event('allday', self::TODAY),
            $this->event('yesterday', '2026-06-09'),
        ];
        self::assertSame(['yesterday', 'allday', 'morning', 'evening'], array_column(Logic::sortEvents($events), 'id'));
    }

    public function test_sort_is_stable_and_deterministic_for_identical_dates(): void
    {
        $events = [$this->event('b', self::TODAY), $this->event('a', self::TODAY), $this->event('c', self::TODAY)];
        self::assertSame(array_column(Logic::sortEvents($events), 'id'), array_column(Logic::sortEvents(Logic::sortEvents($events)), 'id'));
    }

    // -- eventsOnDay / coversDay ------------------------------------------

    public function test_events_on_a_day_scope_to_that_day_and_to_a_person(): void
    {
        self::assertSame(['today'], array_column(Logic::eventsOnDay($this->events(), self::TODAY), 'id'));
        self::assertSame([], Logic::eventsOnDay($this->events(), self::TODAY, 'p-bob'));
        self::assertSame([], Logic::eventsOnDay($this->events(), '2026-06-11'));
    }

    public function test_events_on_a_day_include_a_multi_day_event_that_spans_it(): void
    {
        $camp = $this->event('camp', '2026-06-08', ['endDate' => '2026-06-12']);
        self::assertSame(['camp'], array_column(Logic::eventsOnDay([$camp], self::TODAY), 'id'));
    }

    public function test_covers_day_matches_the_start_and_any_day_inside_a_span(): void
    {
        $camp = $this->event('camp', '2026-06-08', ['endDate' => '2026-06-12']);
        self::assertTrue(Logic::coversDay($camp, '2026-06-08'));
        self::assertTrue(Logic::coversDay($camp, '2026-06-12'));
        self::assertFalse(Logic::coversDay($camp, '2026-06-13'));
    }

    // -- upcomingEvents / pastEvents --------------------------------------

    public function test_upcoming_excludes_today_by_default_and_can_include_it(): void
    {
        self::assertSame(['soon', 'later'], array_column(Logic::upcomingEvents($this->live(), self::TODAY), 'id'));
        self::assertSame(['today', 'soon', 'later'], array_column(Logic::upcomingEvents($this->live(), self::TODAY, ['includeToday' => true]), 'id'));
    }

    public function test_upcoming_never_includes_past_events_and_respects_the_horizon_and_limit(): void
    {
        self::assertNotContains('past', array_column(Logic::upcomingEvents($this->live(), self::TODAY), 'id'));
        self::assertSame(['soon'], array_column(Logic::upcomingEvents($this->live(), self::TODAY, ['horizonDays' => 7]), 'id'));
        self::assertSame(['soon'], array_column(Logic::upcomingEvents($this->live(), self::TODAY, ['limit' => 1]), 'id'));
        self::assertSame(['soon', 'later', 'far'], array_column(Logic::upcomingEvents($this->live(), self::TODAY, ['horizonDays' => 365, 'limit' => 3]), 'id'));
    }

    public function test_upcoming_filters_by_person_and_is_empty_when_they_have_nothing(): void
    {
        self::assertSame(['later'], array_column(Logic::upcomingEvents($this->live(), self::TODAY, ['personId' => 'p-dave']), 'id'));
        self::assertSame([], Logic::upcomingEvents($this->live(), self::TODAY, ['personId' => 'p-nobody']));
    }

    public function test_upcoming_keeps_an_in_progress_multi_day_event_when_today_is_included(): void
    {
        $camp = $this->event('camp', '2026-06-08', ['endDate' => '2026-06-12']);
        self::assertSame(['camp'], array_column(Logic::upcomingEvents([$camp], self::TODAY, ['includeToday' => true]), 'id'));
        self::assertSame([], Logic::upcomingEvents([$camp], self::TODAY));
    }

    public function test_past_returns_most_recent_first_does_not_treat_today_as_past_and_filters_by_person(): void
    {
        $events = [...$this->events(), $this->event('older', '2026-05-01')];
        self::assertSame(['past', 'older'], array_column(Logic::pastEvents($events, self::TODAY), 'id'));
        self::assertNotContains('today', array_column(Logic::pastEvents($events, self::TODAY), 'id'));
        self::assertSame(['past', 'older'], array_column(Logic::pastEvents($events, self::TODAY, 'p-dave'), 'id'));
    }

    // -- nextEventForPerson / groupByDay / daysWithEvents -----------------

    public function test_next_event_for_a_person_includes_today_and_is_null_when_there_is_nothing(): void
    {
        self::assertSame('today', Logic::nextEventForPerson($this->live(), 'p-dave', self::TODAY)['id']);
        self::assertNull(Logic::nextEventForPerson($this->live(), 'p-nobody', self::TODAY));
    }

    public function test_group_by_day_buckets_events_into_ordered_days(): void
    {
        $groups = Logic::groupByDay($this->events());
        self::assertSame(['2026-06-01', self::TODAY, '2026-06-14', '2026-06-21', '2026-07-05', '2026-12-25'], array_column($groups, 'day'));
        self::assertSame([], Logic::groupByDay([]));
    }

    public function test_days_with_events_include_every_day_of_a_multi_day_event(): void
    {
        $camp = $this->event('camp', '2026-06-08', ['endDate' => '2026-06-10']);
        self::assertSame(['2026-06-08', '2026-06-09', '2026-06-10'], Logic::daysWithEvents([$camp]));
    }

    // -- people -----------------------------------------------------------

    public function test_people_in_events_dedupes_by_id_and_sorts_by_name(): void
    {
        self::assertSame(['Bob', 'Dave', 'Sue'], array_column(Logic::peopleInEvents($this->events()), 'name'));
    }

    public function test_describe_participants_phrases_the_list_naturally_and_keeps_the_order(): void
    {
        self::assertSame('Dave and Sue', Logic::describeParticipants($this->event('a', self::TODAY)));
        $three = $this->event('a', self::TODAY, ['people' => [['id' => '1', 'name' => 'Sue'], ['id' => '2', 'name' => 'Dave'], ['id' => '3', 'name' => 'Bob', 'role' => 'Bread']]]);
        self::assertSame('Sue, Dave and Bob (Bread)', Logic::describeParticipants($three));
        self::assertSame('', Logic::describeParticipants($this->event('a', self::TODAY, ['people' => []])));
    }

    public function test_event_ordering_does_not_disturb_participant_order(): void
    {
        $events = [$this->event('b', '2026-06-20', ['people' => [['id' => '1', 'name' => 'Sue'], ['id' => '2', 'name' => 'Dave']]]), $this->event('a', '2026-06-15')];
        self::assertSame(['Sue', 'Dave'], array_column(Logic::sortEvents($events)[1]['people'], 'name'));
    }

    // -- schedules and the chosen name ------------------------------------

    public function test_visible_schedules_hide_disabled_ones_and_sort_by_display_order(): void
    {
        $schedules = [
            ['id' => 'c', 'name' => 'Sound', 'enabled' => true, 'displayOrder' => 2, 'deletedAt' => null],
            ['id' => 'a', 'name' => 'Breakbread', 'enabled' => true, 'displayOrder' => 1, 'deletedAt' => null],
            ['id' => 'b', 'name' => 'Old', 'enabled' => false, 'displayOrder' => 0, 'deletedAt' => null],
            ['id' => 'd', 'name' => 'Gone', 'enabled' => true, 'displayOrder' => 0, 'deletedAt' => '2026-01-01'],
        ];
        self::assertSame(['a', 'c'], array_column(Logic::visibleSchedules($schedules), 'id'));
    }

    public function test_resolve_selected_person_matches_on_id_first_then_the_name(): void
    {
        $people = [['id' => 'p1', 'name' => 'Dave'], ['id' => 'p2', 'name' => 'Sue']];
        self::assertSame('p2', Logic::resolveSelectedPerson($people, 'p2', 'Dave')['id'], 'the id wins');
        self::assertSame('p1', Logic::resolveSelectedPerson($people, 'gone', 'dave')['id'], 'and the name is the fallback, whatever its case');
        self::assertNull(Logic::resolveSelectedPerson($people, 'gone', 'Nobody'));
        self::assertNull(Logic::resolveSelectedPerson($people, null, null));
    }

    // -- visibility -------------------------------------------------------

    public function test_names_are_for_anybody_signed_in_and_nobody_else(): void
    {
        self::assertTrue(Visibility::canSeeNames(true));
        self::assertFalse(Visibility::canSeeNames(false));
        self::assertNotSame('', Visibility::NAMES_WITHHELD, 'and it tells them what to do about it');
    }

    public function test_visible_event_keeps_everything_for_a_member_and_drops_the_people_for_a_stranger(): void
    {
        $event = $this->event('a', self::TODAY, ['notes' => 'Bring bread', 'location' => 'The hall']);
        self::assertSame($event, Visibility::visibleEvent($event, true));
        $stripped = Visibility::visibleEvent($event, false);
        self::assertArrayNotHasKey('people', $stripped);
        self::assertSame('Bring bread', $stripped['notes'], 'the structure stays');
        self::assertStringNotContainsString('Dave', (string) json_encode($stripped), 'no name is left behind anywhere');
        self::assertStringNotContainsString('p-dave', (string) json_encode($stripped));
        self::assertArrayHasKey('people', $event, 'and what it was given is not changed');
    }

    public function test_visible_people_is_the_list_for_a_member_and_nothing_for_a_stranger(): void
    {
        $people = [['id' => 'p1', 'name' => 'Dave']];
        self::assertSame($people, Visibility::visiblePeople($people, true));
        self::assertSame([], Visibility::visiblePeople($people, false));
        // A member is handed a copy: changing it can't reach the caller's list.
        $copy = Visibility::visiblePeople($people, true);
        $copy[0]['name'] = 'Somebody else';
        self::assertSame('Dave', $people[0]['name']);
    }

    public function test_visible_snapshot_carries_no_people_no_names_and_no_person_ids_for_a_stranger(): void
    {
        $snapshot = [
            'since' => '2026-06-01T00:00:00.000Z',
            'full' => true,
            'schedules' => [['id' => 's1', 'name' => 'Breakbread']],
            'events' => [$this->event('a', self::TODAY)],
            'people' => [['id' => 'p-dave', 'name' => 'Dave']],
        ];
        self::assertSame($snapshot, Visibility::visibleSnapshot($snapshot, true));
        $stripped = Visibility::visibleSnapshot($snapshot, false);
        self::assertSame([], $stripped['people']);
        self::assertStringNotContainsString('Dave', (string) json_encode($stripped));
        self::assertStringNotContainsString('p-dave', (string) json_encode($stripped));
        self::assertSame($snapshot['schedules'], $stripped['schedules'], 'everything that isn\'t a person is kept');
    }

    public function test_visible_events_applies_the_rule_to_every_event(): void
    {
        $stripped = Visibility::visibleEvents($this->events(), false);
        self::assertStringNotContainsString('Dave', (string) json_encode($stripped));
        self::assertCount(count($this->events()), $stripped);
    }

    // -- duplicates -------------------------------------------------------

    public function test_duplicates_pair_a_name_with_a_longer_one_that_starts_the_same_way(): void
    {
        $people = [['id' => '1', 'displayName' => 'Dave'], ['id' => '2', 'displayName' => 'Davey']];
        self::assertSame([['1', '2']], array_map(fn (array $p) => [$p['a']['id'], $p['b']['id']], Duplicates::possibleDuplicates($people)));
    }

    public function test_duplicates_ignore_casing_and_spacing_which_are_what_made_the_duplicate(): void
    {
        $people = [['id' => '1', 'displayName' => 'Anne Marie'], ['id' => '2', 'displayName' => 'ANNE  MARIE']];
        // Those are already one person by the normalized key, so there is
        // nothing to suggest.
        self::assertSame([], Duplicates::possibleDuplicates($people));
    }

    public function test_duplicates_do_not_pair_two_different_short_names(): void
    {
        $people = [['id' => '1', 'displayName' => 'Bob'], ['id' => '2', 'displayName' => 'Sue']];
        self::assertSame([], Duplicates::possibleDuplicates($people));
    }

    public function test_duplicates_will_not_pair_on_a_prefix_shorter_than_three_characters(): void
    {
        $people = [['id' => '1', 'displayName' => 'Jo'], ['id' => '2', 'displayName' => 'John']];
        self::assertSame([], Duplicates::possibleDuplicates($people));
    }

    public function test_duplicates_will_not_pair_names_that_diverge_by_more_than_a_few_characters(): void
    {
        $people = [['id' => '1', 'displayName' => 'Dave'], ['id' => '2', 'displayName' => 'Davenport Smith']];
        self::assertSame([], Duplicates::possibleDuplicates($people));
    }

    public function test_duplicates_stop_at_the_limit_rather_than_listing_every_pair_in_a_large_church(): void
    {
        $people = [];
        foreach (range(1, 40) as $n) {
            $people[] = ['id' => "s$n", 'displayName' => "Name$n"];
            $people[] = ['id' => "l$n", 'displayName' => "Name{$n}ly"];
        }
        self::assertCount(5, Duplicates::possibleDuplicates($people, 5));
    }

    public function test_duplicates_say_nothing_about_a_list_with_no_near_duplicates(): void
    {
        self::assertSame([], Duplicates::possibleDuplicates([['id' => '1', 'displayName' => 'Dave'], ['id' => '2', 'displayName' => 'Susan']]));
    }
}
