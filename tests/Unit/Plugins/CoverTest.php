<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins;

use MarineTeam\Plugins\ServicePlans\Cover;
use MarineTeam\Plugins\ServicePlans\Rota;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/plugins/service-plans/src/Rota.php';
require_once dirname(__DIR__, 3) . '/plugins/service-plans/src/Cover.php';

/** Covering a slot, case for case as the original's lib/cover.test.ts. */
final class CoverTest extends TestCase
{
    private const TODAY = '2026-03-08';

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function assignment(array $extra = []): array
    {
        return $extra + ['id' => 'a1', 'user_id' => 'ruth', 'status' => Rota::ACCEPTED, 'cover_wanted' => 0, 'position' => 'Sound desk'];
    }

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function plan(array $extra = []): array
    {
        return $extra + ['id' => 'p1', 'title' => 'Sunday morning', 'service_date' => '2026-03-15 00:00:00', 'published' => 1];
    }

    // -- canAskForCover ---------------------------------------------------

    public function test_ask_lets_whoever_is_on_ask(): void
    {
        self::assertTrue(Cover::canAskForCover($this->assignment(), 'ruth', $this->plan(), self::TODAY));
    }

    public function test_ask_lets_somebody_who_has_not_answered_yet_ask(): void
    {
        self::assertTrue(Cover::canAskForCover($this->assignment(['status' => Rota::INVITED]), 'ruth', $this->plan(), self::TODAY));
    }

    public function test_ask_refuses_somebody_elses_slot(): void
    {
        self::assertFalse(Cover::canAskForCover($this->assignment(), 'boaz', $this->plan(), self::TODAY));
        self::assertFalse(Cover::canAskForCover($this->assignment(), null, $this->plan(), self::TODAY));
    }

    public function test_ask_has_nothing_to_cover_once_they_have_said_no(): void
    {
        self::assertFalse(Cover::canAskForCover($this->assignment(['status' => Rota::DECLINED]), 'ruth', $this->plan(), self::TODAY));
    }

    public function test_ask_refuses_a_second_ask_on_the_same_slot(): void
    {
        self::assertFalse(Cover::canAskForCover($this->assignment(['cover_wanted' => 1]), 'ruth', $this->plan(), self::TODAY));
    }

    public function test_ask_refuses_a_service_that_has_been_and_gone(): void
    {
        self::assertFalse(Cover::canAskForCover($this->assignment(), 'ruth', $this->plan(['service_date' => '2026-03-01 00:00:00']), self::TODAY));
    }

    public function test_ask_counts_the_whole_of_the_services_own_day_as_not_yet_past(): void
    {
        self::assertTrue(Cover::canAskForCover($this->assignment(), 'ruth', $this->plan(['service_date' => self::TODAY . ' 00:00:00']), self::TODAY));
    }

    public function test_ask_treats_an_undated_plan_as_still_to_come(): void
    {
        self::assertTrue(Cover::canAskForCover($this->assignment(), 'ruth', $this->plan(['service_date' => null]), self::TODAY));
    }

    public function test_ask_refuses_a_plan_nobody_can_see_yet(): void
    {
        self::assertFalse(Cover::canAskForCover($this->assignment(), 'ruth', $this->plan(['published' => 0]), self::TODAY));
    }

    // -- coverState -------------------------------------------------------

    private function wanted(): array
    {
        return $this->assignment(['cover_wanted' => 1]);
    }

    public function test_state_is_open_to_somebody_else_on_the_team(): void
    {
        self::assertSame(Cover::OPEN, Cover::coverState($this->wanted(), 'boaz', $this->plan(), [], [], self::TODAY));
    }

    public function test_state_is_not_open_when_nobody_asked(): void
    {
        self::assertSame(Cover::NOT_ASKED, Cover::coverState($this->assignment(), 'boaz', $this->plan(), [], [], self::TODAY));
    }

    public function test_state_says_nothing_to_do_about_your_own_slot(): void
    {
        self::assertSame(Cover::YOURS, Cover::coverState($this->wanted(), 'ruth', $this->plan(), [], [], self::TODAY));
    }

    public function test_state_refuses_somebody_already_on_that_service(): void
    {
        $theirs = [['id' => 'a2', 'status' => Rota::ACCEPTED]];
        self::assertSame(Cover::ALREADY_ON, Cover::coverState($this->wanted(), 'boaz', $this->plan(), $theirs, [], self::TODAY));
        $declined = [['id' => 'a2', 'status' => Rota::DECLINED]];
        self::assertSame(Cover::OPEN, Cover::coverState($this->wanted(), 'boaz', $this->plan(), $declined, [], self::TODAY), 'a no is not being on');
    }

    public function test_state_warns_but_does_not_refuse_somebody_who_said_they_were_away(): void
    {
        $away = [['start_date' => '2026-03-14 00:00:00', 'end_date' => '2026-03-16 00:00:00']];
        self::assertSame(Cover::AWAY, Cover::coverState($this->wanted(), 'boaz', $this->plan(), [], $away, self::TODAY));
        self::assertFalse(Cover::refuses(Cover::AWAY));
        self::assertNotSame('', Cover::takeMessage(Cover::AWAY));
    }

    public function test_state_refuses_a_service_that_has_already_happened(): void
    {
        self::assertSame(Cover::PAST, Cover::coverState($this->wanted(), 'boaz', $this->plan(['service_date' => '2026-03-01 00:00:00']), [], [], self::TODAY));
    }

    public function test_state_puts_being_already_on_ahead_of_being_away(): void
    {
        $theirs = [['id' => 'a2', 'status' => Rota::ACCEPTED]];
        $away = [['start_date' => '2026-03-14 00:00:00', 'end_date' => '2026-03-16 00:00:00']];
        self::assertSame(Cover::ALREADY_ON, Cover::coverState($this->wanted(), 'boaz', $this->plan(), $theirs, $away, self::TODAY));
    }

    // -- takeMessage / askerName ------------------------------------------

    public function test_take_message_says_something_for_every_state_and_nothing_when_it_is_simply_open(): void
    {
        foreach ([Cover::NOT_ASKED, Cover::YOURS, Cover::ALREADY_ON, Cover::AWAY, Cover::PAST] as $state) {
            self::assertNotSame('', Cover::takeMessage($state), $state);
        }
        self::assertSame('', Cover::takeMessage(Cover::OPEN));
    }

    public function test_asker_name_prefers_the_name_they_chose(): void
    {
        self::assertSame('Ruthie', Cover::askerName(['display_name' => 'Ruthie', 'name' => 'Ruth Moab']));
    }

    public function test_asker_name_never_falls_back_to_an_email_address(): void
    {
        self::assertSame('', Cover::askerName(['display_name' => null, 'name' => 'ruth@test.example']));
    }
}
