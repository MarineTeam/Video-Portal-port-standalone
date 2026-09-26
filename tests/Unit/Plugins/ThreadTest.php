<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins;

use MarineTeam\Plugins\Groups\Groups;
use MarineTeam\Plugins\Groups\Thread;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/plugins/groups/src/Groups.php';
require_once dirname(__DIR__, 3) . '/plugins/groups/src/Thread.php';

/** The group's conversation, case for case as the original's lib/group-messages.test.ts. */
final class ThreadTest extends TestCase
{
    /** @return array<string, mixed> */
    private function member(string $userId, string $status, string $role = Groups::MEMBER, bool $muted = false): array
    {
        return ['id' => 'm-' . $userId, 'user_id' => $userId, 'status' => $status, 'role' => $role, 'muted' => $muted ? 1 : 0, 'created_at' => '2026-01-01 10:00:00'];
    }

    /** @return list<array<string, mixed>> */
    private function members(): array
    {
        return [
            $this->member('naomi', Groups::ACTIVE, Groups::LEADER),
            $this->member('ruth', Groups::ACTIVE),
            $this->member('mahlon', Groups::ACTIVE, Groups::MEMBER, muted: true),
            $this->member('boaz', Groups::REQUESTED),
            $this->member('orpah', Groups::WAITLIST),
            $this->member('elimelech', Groups::DECLINED),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function messages(): array
    {
        return [
            ['id' => 'a', 'user_id' => 'ruth', 'author_name' => 'Ruth', 'body' => 'Bringing a cake on Tuesday', 'hidden' => 0, 'created_at' => '2026-03-01 10:00:00'],
            ['id' => 'b', 'user_id' => 'mahlon', 'author_name' => 'Mahlon', 'body' => 'Something unkind', 'hidden' => 1, 'created_at' => '2026-03-01 11:00:00'],
            ['id' => 'c', 'user_id' => 'naomi', 'author_name' => 'Naomi', 'body' => 'Lovely, thank you', 'hidden' => 0, 'created_at' => '2026-03-01 12:00:00'],
        ];
    }

    // -- inTheThread ------------------------------------------------------

    public function test_in_the_thread_is_people_actually_in_the_group(): void
    {
        self::assertTrue(Thread::inTheThread($this->members(), 'ruth'));
        self::assertTrue(Thread::inTheThread($this->members(), 'naomi'));
    }

    public function test_in_the_thread_is_not_people_whose_ask_is_unanswered_or_who_were_turned_down(): void
    {
        foreach (['boaz', 'orpah', 'elimelech', 'stranger', null] as $who) {
            self::assertFalse(Thread::inTheThread($this->members(), $who), (string) $who);
        }
    }

    // -- canModerate ------------------------------------------------------

    public function test_can_moderate_is_this_groups_leaders(): void
    {
        self::assertTrue(Thread::canModerate($this->members(), 'naomi'));
    }

    public function test_can_moderate_is_not_a_member(): void
    {
        self::assertFalse(Thread::canModerate($this->members(), 'ruth'));
    }

    public function test_can_moderate_is_not_a_site_manager_who_is_not_in_the_group(): void
    {
        // The one place this departs from the address rule: an address is an
        // operational fact; a conversation isn't.
        self::assertFalse(Thread::canModerate($this->members(), 'staff', keepsTheList: true));
    }

    public function test_can_moderate_is_a_site_manager_who_is_in_the_group_because_they_lead_by_capability(): void
    {
        $members = [...$this->members(), $this->member('staff', Groups::ACTIVE)];
        self::assertTrue(Thread::canModerate($members, 'staff', keepsTheList: true));
    }

    // -- threadState ------------------------------------------------------

    public function test_thread_state_names_why_somebody_cannot_see_it(): void
    {
        self::assertSame(Thread::OPEN, Thread::threadState($this->members(), 'ruth'));
        self::assertSame(Thread::SIGN_IN, Thread::threadState($this->members(), null));
        self::assertSame(Thread::ASKED, Thread::threadState($this->members(), 'boaz'));
        self::assertSame(Thread::WAITING, Thread::threadState($this->members(), 'orpah'));
        self::assertSame(Thread::OUTSIDE, Thread::threadState($this->members(), 'elimelech'));
        self::assertSame(Thread::OUTSIDE, Thread::threadState($this->members(), 'stranger'));
    }

    public function test_thread_state_has_a_sentence_for_every_state_but_open(): void
    {
        $strings = require dirname(__DIR__, 3) . '/plugins/groups/lang/en.php';
        foreach ([Thread::SIGN_IN, Thread::ASKED, Thread::WAITING, Thread::OUTSIDE] as $state) {
            self::assertArrayHasKey('groups.thread.' . strtolower($state), $strings, $state);
        }
    }

    // -- visibleThread ----------------------------------------------------

    public function test_visible_gives_members_the_messages_without_the_hidden_one(): void
    {
        self::assertSame(['a', 'c'], array_column(Thread::visibleThread($this->messages(), $this->members(), 'ruth'), 'id'));
    }

    public function test_visible_gives_somebody_outside_the_group_nothing_at_all(): void
    {
        foreach (['boaz', 'orpah', 'stranger', null] as $who) {
            self::assertSame([], Thread::visibleThread($this->messages(), $this->members(), $who));
        }
    }

    public function test_visible_keeps_a_hidden_message_hidden_from_the_leader_who_hid_it_too(): void
    {
        self::assertSame(['a', 'c'], array_column(Thread::visibleThread($this->messages(), $this->members(), 'naomi'), 'id'));
    }

    public function test_visible_lets_an_author_remove_their_own_and_nobody_elses(): void
    {
        $seen = Thread::visibleThread($this->messages(), $this->members(), 'ruth');
        self::assertTrue($seen[0]['canRemove'], 'hers');
        self::assertFalse($seen[1]['canRemove'], 'the leader\'s');
    }

    public function test_visible_lets_a_leader_remove_anything(): void
    {
        foreach (Thread::visibleThread($this->messages(), $this->members(), 'naomi') as $message) {
            self::assertTrue($message['canRemove']);
        }
    }

    public function test_visible_carries_no_user_ids_out(): void
    {
        $json = (string) json_encode(Thread::visibleThread($this->messages(), $this->members(), 'naomi'));
        self::assertStringNotContainsString('ruth', $json);
        self::assertStringNotContainsString('user_id', $json);
    }

    // -- canRemoveMessage -------------------------------------------------

    public function test_can_remove_is_the_author_or_a_leader_of_the_group(): void
    {
        $message = $this->messages()[0];
        self::assertTrue(Thread::canRemoveMessage($message, $this->members(), 'ruth'));
        self::assertTrue(Thread::canRemoveMessage($message, $this->members(), 'naomi'));
    }

    public function test_can_remove_is_not_another_member(): void
    {
        self::assertFalse(Thread::canRemoveMessage($this->messages()[0], $this->members(), 'mahlon'));
    }

    public function test_can_remove_is_not_the_author_once_they_have_left_the_group(): void
    {
        $members = array_values(array_filter($this->members(), fn (array $m) => $m['user_id'] !== 'ruth'));
        self::assertFalse(Thread::canRemoveMessage($this->messages()[0], $members, 'ruth'));
    }

    public function test_can_remove_is_not_a_site_manager_outside_the_group(): void
    {
        self::assertFalse(Thread::canRemoveMessage($this->messages()[0], $this->members(), 'staff', keepsTheList: true));
    }

    // -- cleanGroupMessage ------------------------------------------------

    public function test_clean_collapses_the_runs_that_turn_one_message_into_a_screenful(): void
    {
        self::assertSame("One\n\nTwo", Thread::cleanGroupMessage("One\n\n\n\n\n\nTwo"));
        self::assertSame('Yessss!!!!', Thread::cleanGroupMessage('Yesssssssssss!!!!!!!!!!'), 'four of anything is plenty');
    }

    public function test_clean_refuses_an_empty_one(): void
    {
        self::assertNull(Thread::cleanGroupMessage('   '));
        self::assertNull(Thread::cleanGroupMessage("\n\n\n"));
    }

    public function test_clean_takes_a_paragraph_which_the_stream_chat_would_not(): void
    {
        $paragraph = str_repeat('A sentence about the study. ', 30);
        self::assertSame(trim($paragraph), Thread::cleanGroupMessage($paragraph));
        self::assertSame("Line one\nLine two", Thread::cleanGroupMessage("Line one\nLine two"), 'and keeps its lines');
    }

    public function test_clean_stops_at_the_limit_and_says_what_it_is(): void
    {
        self::assertNull(Thread::cleanGroupMessage(str_repeat('ab', Thread::MAX_LENGTH)));
        self::assertSame(4000, Thread::MAX_LENGTH);
    }

    // -- notifiable -------------------------------------------------------

    public function test_notifiable_is_active_members_other_than_the_author_minus_the_muted(): void
    {
        self::assertSame(['naomi'], Thread::notifiable($this->members(), 'ruth'));
    }

    public function test_notifiable_never_tells_somebody_about_their_own_message(): void
    {
        self::assertNotContains('naomi', Thread::notifiable($this->members(), 'naomi'));
    }

    public function test_notifiable_does_not_reach_people_who_are_not_in_the_group_yet(): void
    {
        $told = Thread::notifiable($this->members(), 'ruth');
        foreach (['boaz', 'orpah', 'elimelech'] as $who) {
            self::assertNotContains($who, $told);
        }
    }

    // -- latest -----------------------------------------------------------

    public function test_latest_returns_the_newest_page_in_reading_order(): void
    {
        $rows = $this->messages();
        self::assertSame(['b', 'c'], array_column(Thread::latest($rows, 2), 'id'));
    }

    public function test_latest_copes_with_fewer_messages_than_a_page(): void
    {
        self::assertCount(3, Thread::latest($this->messages(), 50));
        self::assertSame([], Thread::latest([], 50));
    }

    public function test_latest_does_not_mutate_what_it_was_given(): void
    {
        $rows = array_reverse($this->messages());
        $before = array_column($rows, 'id');
        Thread::latest($rows, 2);
        self::assertSame($before, array_column($rows, 'id'));
    }

    public function test_latest_breaks_a_tie_on_id_so_the_order_is_stable(): void
    {
        $same = '2026-03-01 10:00:00';
        $rows = [
            ['id' => 'z', 'user_id' => 'ruth', 'author_name' => 'Ruth', 'body' => 'one', 'hidden' => 0, 'created_at' => $same],
            ['id' => 'a', 'user_id' => 'ruth', 'author_name' => 'Ruth', 'body' => 'two', 'hidden' => 0, 'created_at' => $same],
        ];
        self::assertSame(['a', 'z'], array_column(Thread::latest($rows), 'id'));
    }

    // -- the first line ---------------------------------------------------

    public function test_a_notification_carries_the_first_line_only(): void
    {
        self::assertSame('Bringing a cake', Thread::firstLine("Bringing a cake\nand the plates and the cups and everything else"));
        self::assertStringEndsWith('…', Thread::firstLine(str_repeat('word ', 60)));
    }
}
