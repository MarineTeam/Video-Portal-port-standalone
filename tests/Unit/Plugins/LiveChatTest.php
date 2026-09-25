<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins;

use MarineTeam\Plugins\Live\Chat;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/plugins/live-streaming/src/Chat.php';

/** The live chat's rules, case for case as the original's lib/live-chat.test.ts. */
final class LiveChatTest extends TestCase
{
    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
    }

    // -- chatState --------------------------------------------------------

    public function test_state_is_open_during_the_stream(): void
    {
        self::assertSame(Chat::OPEN, Chat::state(true, $this->at('2026-03-01 10:00'), $this->at('2026-03-01 11:30'), $this->at('2026-03-01 10:45')));
    }

    public function test_state_opens_half_an_hour_early_so_people_arriving_can_say_hello(): void
    {
        $start = $this->at('2026-03-01 10:00');
        self::assertSame(Chat::EARLY, Chat::state(true, $start, null, $this->at('2026-03-01 09:29')));
        self::assertSame(Chat::OPEN, Chat::state(true, $start, null, $this->at('2026-03-01 09:31')));
    }

    public function test_state_stays_open_an_hour_after_so_the_conversation_is_not_cut_off(): void
    {
        self::assertSame(Chat::OPEN, Chat::state(true, $this->at('2026-03-01 10:00'), $this->at('2026-03-01 11:30'), $this->at('2026-03-01 12:29')));
    }

    public function test_state_closes_rather_than_standing_open_on_last_years_carol_service(): void
    {
        self::assertSame(Chat::CLOSED, Chat::state(true, $this->at('2025-12-24 18:00'), $this->at('2025-12-24 19:30'), $this->at('2026-03-01 10:00')));
        self::assertSame(Chat::CLOSED, Chat::state(true, $this->at('2026-03-01 10:00'), $this->at('2026-03-01 11:30'), $this->at('2026-03-01 12:31')));
    }

    public function test_state_gives_a_stream_with_no_end_time_a_sensible_one_rather_than_for_ever(): void
    {
        $start = $this->at('2026-03-01 10:00');
        // Two hours assumed, then the hour after: open at 12:59, shut at 13:01.
        self::assertSame(Chat::OPEN, Chat::state(true, $start, null, $this->at('2026-03-01 12:59')));
        self::assertSame(Chat::CLOSED, Chat::state(true, $start, null, $this->at('2026-03-01 13:01')));
        // An end time before the start is no end time at all.
        self::assertSame(Chat::OPEN, Chat::state(true, $start, $this->at('2026-02-01 10:00'), $this->at('2026-03-01 12:59')));
    }

    public function test_state_is_off_when_the_chat_was_never_switched_on(): void
    {
        self::assertSame(Chat::OFF, Chat::state(false, $this->at('2026-03-01 10:00'), null, $this->at('2026-03-01 10:30')));
    }

    // -- cleanMessage -----------------------------------------------------

    public function test_clean_keeps_what_somebody_wrote(): void
    {
        self::assertSame('Praying for the Ndlovu family — João too.', Chat::clean('  Praying for the Ndlovu family — João too.  '));
    }

    public function test_clean_collapses_the_shouting_a_length_limit_does_not_stop(): void
    {
        self::assertSame('AMEN!!! yes', Chat::clean("AMEN!!!!!!!!!!!!!!!!\n\n\n    yes"));
        self::assertSame('Haaa', Chat::clean('Haaaaaaaaaaaaaaaaaaaa'));
    }

    public function test_clean_refuses_nothing_at_all(): void
    {
        self::assertNull(Chat::clean('   '));
        self::assertNull(Chat::clean("\n\n"));
    }

    public function test_clean_refuses_an_essay(): void
    {
        self::assertNull(Chat::clean(str_repeat('word ', 200)));
        self::assertNull(Chat::clean(str_repeat('x', 200000)), 'and does not go looking through a megabyte to say so');
    }

    // -- waitSeconds ------------------------------------------------------

    public function test_wait_is_nothing_when_slow_mode_is_off(): void
    {
        self::assertSame(0, Chat::waitSeconds(0, $this->at('2026-03-01 10:00'), $this->at('2026-03-01 10:00:01')));
    }

    public function test_wait_is_nothing_for_somebody_who_has_not_written_yet(): void
    {
        self::assertSame(0, Chat::waitSeconds(30, null, $this->at('2026-03-01 10:00')));
    }

    public function test_wait_counts_down_from_their_own_last_message_not_the_chats(): void
    {
        self::assertSame(20, Chat::waitSeconds(30, $this->at('2026-03-01 10:00:00'), $this->at('2026-03-01 10:00:10')));
    }

    public function test_wait_is_nothing_once_the_wait_has_passed(): void
    {
        self::assertSame(0, Chat::waitSeconds(30, $this->at('2026-03-01 10:00:00'), $this->at('2026-03-01 10:00:31')));
    }

    // -- visibleMessages --------------------------------------------------

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return [
            ['id' => 'c1', 'user_id' => 'ruth', 'author_name' => 'Ruth', 'body' => 'Hello', 'hidden' => 0, 'created_at' => '2026-03-01 10:00:00'],
            ['id' => 'c2', 'user_id' => 'boaz', 'author_name' => 'Boaz', 'body' => 'Rude words', 'hidden' => 1, 'created_at' => '2026-03-01 10:01:00'],
            ['id' => 'c3', 'user_id' => 'boaz', 'author_name' => 'Boaz', 'body' => 'Hello too', 'hidden' => 0, 'created_at' => '2026-03-01 10:02:00'],
        ];
    }

    public function test_visible_never_delivers_a_hidden_message_even_to_a_poll_that_was_behind(): void
    {
        self::assertSame(['c1', 'c3'], array_column(Chat::visible($this->rows(), 'ruth', false), 'id'));
    }

    public function test_visible_carries_no_account_id_out_to_anybody(): void
    {
        foreach (Chat::visible($this->rows(), 'ruth', true) as $message) {
            self::assertSame(['id', 'author', 'body', 'createdAt', 'mine', 'canDelete'], array_keys($message));
            self::assertStringNotContainsString('boaz', json_encode($message) ?: '');
        }
    }

    public function test_visible_says_who_may_take_a_message_down(): void
    {
        $mine = Chat::visible($this->rows(), 'ruth', false);
        self::assertTrue($mine[0]['canDelete'], 'her own');
        self::assertFalse($mine[1]['canDelete'], 'somebody else\'s');
        self::assertTrue(Chat::visible($this->rows(), 'ruth', true)[1]['canDelete'], 'a moderator takes down anybody\'s');
        self::assertFalse(Chat::visible($this->rows(), null, false)[0]['canDelete'], 'a guest takes down nothing');
    }
}
