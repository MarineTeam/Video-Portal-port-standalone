<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins;

use MarineTeam\Plugins\Events\Events;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/plugins/events/src/Recurrence.php';
require_once dirname(__DIR__, 3) . '/plugins/events/src/Events.php';

/** Sign-up, case for case as the original's lib/events.test.ts. */
final class EventsTest extends TestCase
{
    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function event(array $extra = []): array
    {
        return $extra + [
            'registration' => true,
            'capacity' => 10,
            'waitlist' => true,
            'starts_at' => '2026-03-03 19:30:00',
            'ends_at' => '2026-03-03 21:00:00',
            'all_day' => false,
            'opens_at' => null,
            'closes_at' => null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function registrations(): array
    {
        return [
            ['id' => 'a', 'status' => Events::GOING, 'guests' => 0],
            ['id' => 'b', 'status' => Events::GOING, 'guests' => 2],
            ['id' => 'c', 'status' => Events::WAITLIST, 'guests' => 1],
            ['id' => 'd', 'status' => Events::CANCELLED, 'guests' => 5],
        ];
    }

    private function now(string $when): \DateTimeImmutable
    {
        return new \DateTimeImmutable($when, new \DateTimeZone('UTC'));
    }

    // -- seatsTaken -------------------------------------------------------

    public function test_seats_count_a_guest_as_a_place_because_a_guest_sits_somewhere(): void
    {
        self::assertSame(4, Events::seatsTaken($this->registrations()));
    }

    public function test_seats_ignore_the_waiting_list_and_the_cancelled(): void
    {
        self::assertSame(0, Events::seatsTaken(array_slice($this->registrations(), 2)));
    }

    public function test_seats_are_zero_for_nobody(): void
    {
        self::assertSame(0, Events::seatsTaken([]));
    }

    // -- placesLeft -------------------------------------------------------

    public function test_places_left_never_goes_negative_even_when_the_capacity_is_lowered_below_what_is_booked(): void
    {
        self::assertSame(0, Events::placesLeft(2, 9));
        self::assertSame(6, Events::placesLeft(10, 4));
    }

    public function test_places_left_is_null_for_an_event_with_no_limit(): void
    {
        self::assertNull(Events::placesLeft(null, 40));
    }

    // -- registrationState ------------------------------------------------

    public function test_state_offers_sign_up_when_there_is_room_and_the_window_is_open(): void
    {
        self::assertSame(Events::OPEN, Events::registrationState($this->event(), 4, $this->now('2026-03-01 09:00')));
    }

    public function test_state_says_nothing_about_sign_up_for_an_event_that_does_not_take_it(): void
    {
        self::assertSame(Events::NONE, Events::registrationState($this->event(['registration' => false]), 0, $this->now('2026-03-01 09:00')));
    }

    public function test_state_leads_with_the_event_being_over_not_with_sign_up_having_closed(): void
    {
        $shut = $this->event(['closes_at' => '2026-03-01 09:00:00']);
        self::assertSame(Events::OVER, Events::registrationState($shut, 0, $this->now('2026-03-04 09:00')));
        self::assertSame(Events::CLOSED, Events::registrationState($shut, 0, $this->now('2026-03-02 09:00')));
    }

    public function test_state_counts_an_event_as_over_only_once_it_has_finished_not_once_it_has_started(): void
    {
        self::assertSame(Events::OPEN, Events::registrationState($this->event(), 0, $this->now('2026-03-03 20:00')), 'it is still going');
        self::assertSame(Events::OVER, Events::registrationState($this->event(), 0, $this->now('2026-03-03 21:30')));
        // With no stated finish, the end of its own day.
        $open = $this->event(['ends_at' => null]);
        self::assertSame(Events::OPEN, Events::registrationState($open, 0, $this->now('2026-03-03 22:00')));
        self::assertSame(Events::OVER, Events::registrationState($open, 0, $this->now('2026-03-04 00:30')));
    }

    public function test_state_distinguishes_not_open_yet_from_closed(): void
    {
        $window = $this->event(['opens_at' => '2026-02-20 00:00:00', 'closes_at' => '2026-03-01 00:00:00']);
        self::assertSame(Events::NOT_OPEN, Events::registrationState($window, 0, $this->now('2026-02-01 09:00')));
        self::assertSame(Events::OPEN, Events::registrationState($window, 0, $this->now('2026-02-25 09:00')));
        self::assertSame(Events::CLOSED, Events::registrationState($window, 0, $this->now('2026-03-02 09:00')));
    }

    public function test_state_offers_the_waiting_list_when_full_and_refuses_outright_when_there_is_not_one(): void
    {
        self::assertSame(Events::LIST_OPEN, Events::registrationState($this->event(), 10, $this->now('2026-03-01 09:00')));
        self::assertSame(Events::FULL, Events::registrationState($this->event(['waitlist' => false]), 10, $this->now('2026-03-01 09:00')));
    }

    public function test_state_stays_open_for_an_event_with_no_capacity_however_many_have_signed_up(): void
    {
        self::assertSame(Events::OPEN, Events::registrationState($this->event(['capacity' => null]), 400, $this->now('2026-03-01 09:00')));
    }

    // -- registrationMessage ----------------------------------------------

    public function test_message_counts_places_down_and_gets_the_singular_right(): void
    {
        self::assertSame('6 places left', Events::registrationMessage(Events::OPEN, 6));
        self::assertSame('1 place left', Events::registrationMessage(Events::OPEN, 1));
        self::assertSame('0 places left', Events::registrationMessage(Events::LIST_OPEN, 0));
    }

    public function test_message_says_nothing_about_a_number_an_unlimited_event_does_not_have(): void
    {
        self::assertSame('', Events::registrationMessage(Events::OPEN, null));
        self::assertSame('', Events::registrationMessage(Events::OVER, 3));
    }

    // -- promotable -------------------------------------------------------

    /** @return list<array<string, mixed>> */
    private function waiting(): array
    {
        return [
            ['id' => 'family', 'guests' => 3],
            ['id' => 'couple', 'guests' => 1],
            ['id' => 'alone', 'guests' => 0],
        ];
    }

    public function test_promotable_moves_whoever_fits_in_the_order_they_joined(): void
    {
        self::assertSame(['family', 'couple'], Events::promotable($this->waiting(), 10, 4));
    }

    public function test_promotable_stops_at_the_first_party_that_does_not_fit_rather_than_skipping_over_them(): void
    {
        // Two places free and a family of four at the front: nobody moves.
        self::assertSame([], Events::promotable($this->waiting(), 10, 8));
    }

    public function test_promotable_moves_the_whole_queue_when_there_is_no_limit_at_all(): void
    {
        self::assertSame(['family', 'couple', 'alone'], Events::promotable($this->waiting(), null, 100));
    }

    public function test_promotable_moves_nobody_when_nothing_freed_up(): void
    {
        self::assertSame([], Events::promotable($this->waiting(), 10, 10));
        self::assertSame([], Events::promotable([], 10, 0));
    }

    // -- eventWhen --------------------------------------------------------

    public function test_when_gives_a_day_and_a_time(): void
    {
        self::assertSame('Tuesday 3 March 2026, 19:30–21:00', Events::eventWhen($this->event()));
        self::assertSame('Tuesday 3 March 2026, 19:30', Events::eventWhen($this->event(['ends_at' => null])));
    }

    public function test_when_gives_one_day_for_an_all_day_event_rather_than_a_midnight_time(): void
    {
        self::assertSame('Saturday 4 July 2026', Events::eventWhen(['starts_at' => '2026-07-04 00:00:00', 'ends_at' => null, 'all_day' => true]));
    }

    public function test_when_runs_two_dates_together_for_something_spanning_days(): void
    {
        self::assertSame(
            'Friday 3 July 2026 – Sunday 5 July 2026',
            Events::eventWhen(['starts_at' => '2026-07-03 00:00:00', 'ends_at' => '2026-07-05 00:00:00', 'all_day' => true]),
        );
        self::assertSame(
            'Friday 3 July 2026, 19:00 – Sunday 5 July 2026, 16:00',
            Events::eventWhen(['starts_at' => '2026-07-03 19:00:00', 'ends_at' => '2026-07-05 16:00:00', 'all_day' => false]),
        );
    }

    public function test_when_reads_in_the_zone_it_is_given(): void
    {
        self::assertSame('Tuesday 3 March 2026, 14:30–16:00', Events::eventWhen($this->event(), 'America/New_York'));
    }
}
