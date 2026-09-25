<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins;

use MarineTeam\Plugins\Prayer\Prayer;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/plugins/prayer/src/Prayer.php';

/** The prayer wall's rules, case for case as the original's lib/prayer.test.ts. */
final class PrayerTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function request(array $overrides = []): array
    {
        return $overrides + [
            'id' => 'p1',
            'user_id' => 'ruth',
            'name' => 'Ruth',
            'body' => 'For my mother, who is in hospital.',
            'anonymous' => 0,
            'visibility' => Prayer::MEMBERS,
            'status' => Prayer::APPROVED,
            'answered_note' => null,
            'answered_at' => null,
            'created_at' => '2026-03-01 10:00:00',
        ];
    }

    // -- canSee -----------------------------------------------------------

    public function test_can_see_shows_nothing_to_anybody_until_it_has_been_let_through(): void
    {
        $waiting = $this->request(['status' => Prayer::PENDING]);
        self::assertFalse(Prayer::canSee($waiting, 'boaz', false));
        self::assertFalse(Prayer::canSee($waiting, null, false));
    }

    public function test_can_see_still_shows_a_waiting_request_to_whoever_wrote_it(): void
    {
        self::assertTrue(Prayer::canSee($this->request(['status' => Prayer::PENDING]), 'ruth', false));
    }

    public function test_can_see_shows_the_queue_to_a_moderator_which_is_the_job(): void
    {
        foreach (Prayer::STATUSES as $status) {
            self::assertTrue(Prayer::canSee($this->request(['status' => $status]), 'mod', true), $status);
        }
    }

    public function test_can_see_keeps_a_taken_down_request_away_from_everybody_else(): void
    {
        $hidden = $this->request(['status' => Prayer::HIDDEN]);
        self::assertFalse(Prayer::canSee($hidden, 'ruth', false), 'its writer included');
        self::assertFalse(Prayer::canSee($hidden, 'boaz', false));
        self::assertFalse(Prayer::canSee($hidden, null, false));
    }

    public function test_can_see_honours_the_three_audiences(): void
    {
        $everyone = $this->request(['visibility' => Prayer::EVERYONE]);
        self::assertTrue(Prayer::canSee($everyone, null, false));
        $members = $this->request(['visibility' => Prayer::MEMBERS]);
        self::assertTrue(Prayer::canSee($members, 'boaz', false));
        self::assertFalse(Prayer::canSee($members, null, false));
        $leaders = $this->request(['visibility' => Prayer::LEADERS]);
        self::assertFalse(Prayer::canSee($leaders, 'boaz', false));
        self::assertFalse(Prayer::canSee($leaders, null, false));
        self::assertTrue(Prayer::canSee($leaders, 'mod', true));
    }

    public function test_can_see_shows_an_answered_request_wherever_an_approved_one_would_show(): void
    {
        self::assertTrue(Prayer::canSee($this->request(['status' => Prayer::ANSWERED, 'visibility' => Prayer::EVERYONE]), null, false));
        self::assertFalse(Prayer::canSee($this->request(['status' => Prayer::ANSWERED, 'visibility' => Prayer::MEMBERS]), null, false));
    }

    public function test_can_see_does_not_hand_a_visitor_somebody_elses_request_because_both_have_no_account(): void
    {
        $byAVisitor = $this->request(['user_id' => null, 'status' => Prayer::PENDING]);
        self::assertFalse(Prayer::canSee($byAVisitor, null, false));
    }

    // -- bylineFor --------------------------------------------------------

    public function test_byline_gives_the_name_when_there_is_one_to_give(): void
    {
        self::assertSame('Ruth', Prayer::bylineFor($this->request()));
    }

    public function test_byline_never_gives_a_name_for_an_anonymous_request(): void
    {
        self::assertNull(Prayer::bylineFor($this->request(['anonymous' => 1])));
    }

    public function test_byline_does_not_leak_a_blank_as_a_name(): void
    {
        self::assertNull(Prayer::bylineFor($this->request(['name' => '   '])));
        self::assertNull(Prayer::bylineFor($this->request(['name' => null])));
        self::assertNull(Prayer::bylineFor($this->request(['name' => 'ruth@example.org'])), 'nor an address typed into a name box');
    }

    // -- presentPrayer ----------------------------------------------------

    public function test_present_carries_no_account_id_out_for_anyone(): void
    {
        foreach ([[null, false], ['ruth', false], ['mod', true]] as [$who, $moderator]) {
            $shape = Prayer::present($this->request(), $who, $moderator);
            self::assertArrayNotHasKey('userId', $shape);
            self::assertStringNotContainsString('ruth', json_encode(array_diff_key($shape, ['author' => 1])) ?: '');
        }
    }

    public function test_present_withholds_the_name_even_from_the_moderators_own_list(): void
    {
        self::assertNull(Prayer::present($this->request(['anonymous' => 1]), 'mod', true)['author']);
    }

    public function test_present_says_whose_it_is_to_act_on(): void
    {
        self::assertTrue(Prayer::present($this->request(), 'ruth', false)['mine']);
        self::assertFalse(Prayer::present($this->request(), 'boaz', false)['mine']);
        self::assertTrue(Prayer::present($this->request(), 'boaz', true)['canDelete']);
    }

    // -- visibleTo --------------------------------------------------------

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return [
            $this->request(['id' => 'open', 'visibility' => Prayer::EVERYONE]),
            $this->request(['id' => 'members']),
            $this->request(['id' => 'leaders', 'visibility' => Prayer::LEADERS]),
            $this->request(['id' => 'waiting', 'status' => Prayer::PENDING, 'user_id' => 'boaz']),
        ];
    }

    public function test_visible_drops_what_a_member_may_not_see_without_them_knowing_it_was_there(): void
    {
        self::assertSame(['open', 'members'], array_column(Prayer::visibleTo($this->rows(), 'boaz2', false), 'id'));
        self::assertSame(['open'], array_column(Prayer::visibleTo($this->rows(), null, false), 'id'));
        self::assertSame(['open', 'members', 'waiting'], array_column(Prayer::visibleTo($this->rows(), 'boaz', false), 'id'), 'and their own, waiting');
    }

    public function test_visible_counts_prayers_and_knows_whether_this_reader_is_one_of_them(): void
    {
        $seen = Prayer::visibleTo($this->rows(), 'boaz2', false, ['open' => 7], ['open']);
        self::assertSame(7, $seen[0]['prayers']);
        self::assertTrue($seen[0]['prayed']);
        self::assertSame(0, $seen[1]['prayers']);
        self::assertFalse($seen[1]['prayed']);
    }

    public function test_visible_gives_a_moderator_the_lot(): void
    {
        self::assertCount(4, Prayer::visibleTo($this->rows(), 'mod', true));
    }

    // -- canPrayFor -------------------------------------------------------

    public function test_can_pray_needs_an_account_because_pressing_it_twice_must_not_be_two(): void
    {
        self::assertFalse(Prayer::canPrayFor($this->request(['visibility' => Prayer::EVERYONE]), null, false));
        self::assertTrue(Prayer::canPrayFor($this->request(['visibility' => Prayer::EVERYONE]), 'boaz', false));
    }

    public function test_can_pray_will_not_count_a_prayer_for_something_nobody_has_been_shown(): void
    {
        self::assertFalse(Prayer::canPrayFor($this->request(['status' => Prayer::PENDING]), 'ruth', false), 'their own, still waiting');
        self::assertFalse(Prayer::canPrayFor($this->request(['status' => Prayer::PENDING]), 'mod', true), 'nor a moderator reading the queue');
        self::assertFalse(Prayer::canPrayFor($this->request(['status' => Prayer::HIDDEN]), 'mod', true));
        self::assertTrue(Prayer::canPrayFor($this->request(['status' => Prayer::ANSWERED]), 'boaz', false));
    }

    // -- canDelete --------------------------------------------------------

    public function test_can_delete_is_the_writers_and_the_moderators(): void
    {
        self::assertTrue(Prayer::canDelete($this->request(), 'ruth', false));
        self::assertTrue(Prayer::canDelete($this->request(), 'boaz', true));
        self::assertFalse(Prayer::canDelete($this->request(), 'boaz', false));
        self::assertFalse(Prayer::canDelete($this->request(['user_id' => null]), null, false), 'a visitor is not everybody');
    }
}
