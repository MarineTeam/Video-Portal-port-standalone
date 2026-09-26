<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins;

use MarineTeam\Plugins\Groups\Guides;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/plugins/groups/src/Guides.php';

/** Discussion guides, case for case as the original's lib/guides.test.ts. */
final class GuidesTest extends TestCase
{
    /** @return array<string, mixed> */
    private function guide(bool $published = true): array
    {
        return ['id' => 'g1', 'slug' => 'romans-8', 'title' => 'Romans 8', 'description' => 'Four weeks in Romans.', 'published' => $published ? 1 : 0];
    }

    /** @return list<array<string, mixed>> */
    private function items(): array
    {
        return [
            ['id' => 'i3', 'kind' => Guides::QUESTION, 'body' => 'What does "no condemnation" mean here?', 'reference' => null, 'position' => 3],
            ['id' => 'i1', 'kind' => Guides::SCRIPTURE, 'body' => 'Read it together', 'reference' => 'Romans 8:1-11', 'position' => 1],
            ['id' => 'i4', 'kind' => Guides::LEADER_NOTE, 'body' => 'Don\'t let this become a debate about predestination.', 'reference' => null, 'position' => 4],
            ['id' => 'i2', 'kind' => Guides::NOTE, 'body' => 'Paul is writing to a church he has never visited.', 'reference' => null, 'position' => 2],
        ];
    }

    public function test_present_gives_a_member_the_questions_the_scripture_and_the_notes(): void
    {
        $shape = Guides::presentGuide($this->guide(), $this->items(), withLeaderNotes: false);
        self::assertSame([Guides::SCRIPTURE, Guides::NOTE, Guides::QUESTION], array_column($shape['items'], 'kind'));
    }

    public function test_present_never_lets_a_leader_note_reach_a_member_in_any_field(): void
    {
        $shape = Guides::presentGuide($this->guide(), $this->items(), withLeaderNotes: false);
        self::assertStringNotContainsString('predestination', (string) json_encode($shape));
    }

    public function test_present_leaves_the_field_off_entirely_rather_than_sending_an_empty_one(): void
    {
        self::assertArrayNotHasKey('leaderNotes', Guides::presentGuide($this->guide(), $this->items(), withLeaderNotes: false));
    }

    public function test_present_gives_the_notes_to_whoever_is_leading(): void
    {
        $shape = Guides::presentGuide($this->guide(), $this->items(), withLeaderNotes: true);
        self::assertCount(1, $shape['leaderNotes']);
        self::assertStringContainsString('predestination', $shape['leaderNotes'][0]['body']);
    }

    public function test_present_gives_them_to_whoever_keeps_the_group_list(): void
    {
        self::assertTrue(Guides::canSeeLeaderNotes(leadsAnyGroup: false, keepsTheList: true));
    }

    public function test_present_withholds_them_from_somebody_who_only_asked_to_join_or_is_waiting(): void
    {
        self::assertFalse(Guides::canSeeLeaderNotes(leadsAnyGroup: false));
    }

    public function test_present_withholds_them_from_a_signed_out_reader(): void
    {
        self::assertArrayNotHasKey('leaderNotes', Guides::presentGuide($this->guide(), $this->items(), withLeaderNotes: Guides::canSeeLeaderNotes(false, false)));
    }

    public function test_present_omits_the_field_when_a_leader_opens_a_guide_that_has_no_notes(): void
    {
        $items = array_values(array_filter($this->items(), fn (array $i) => $i['kind'] !== Guides::LEADER_NOTE));
        self::assertArrayNotHasKey('leaderNotes', Guides::presentGuide($this->guide(), $items, withLeaderNotes: true));
    }

    public function test_present_reads_in_the_order_it_was_written_whatever_order_the_rows_arrive_in(): void
    {
        $shape = Guides::presentGuide($this->guide(), array_reverse($this->items()), withLeaderNotes: false);
        self::assertSame('Romans 8:1-11', $shape['items'][0]['reference']);
    }

    public function test_is_member_kind_names_exactly_the_three_anybody_may_read(): void
    {
        self::assertSame([true, true, true, false], array_map([Guides::class, 'isMemberKind'], Guides::KINDS));
    }

    public function test_can_see_leader_notes_is_leading_this_group_or_keeping_the_group_list(): void
    {
        self::assertTrue(Guides::canSeeLeaderNotes(leadsAnyGroup: true));
        self::assertFalse(Guides::canSeeLeaderNotes(leadsAnyGroup: false, keepsTheList: false));
    }

    public function test_can_open_lets_anybody_open_a_published_guide(): void
    {
        self::assertTrue(Guides::canOpenGuide($this->guide(), isStaff: false));
    }

    public function test_can_open_keeps_a_draft_to_staff(): void
    {
        self::assertFalse(Guides::canOpenGuide($this->guide(published: false), isStaff: false));
        self::assertTrue(Guides::canOpenGuide($this->guide(published: false), isStaff: true));
    }

    public function test_describe_counts_questions_not_items(): void
    {
        $shape = Guides::presentGuide($this->guide(), $this->items(), withLeaderNotes: true);
        self::assertSame('1 question', $shape['summary']);
        self::assertSame('2 questions', Guides::describeGuide([['kind' => Guides::QUESTION, 'body' => 'a', 'reference' => null], ['kind' => Guides::QUESTION, 'body' => 'b', 'reference' => null], ['kind' => Guides::NOTE, 'body' => 'c', 'reference' => null]]));
    }

    public function test_describe_calls_a_guide_with_no_questions_a_handout(): void
    {
        self::assertSame('A handout', Guides::describeGuide([['kind' => Guides::NOTE, 'body' => 'Just read this', 'reference' => null]]));
        self::assertSame('A handout', Guides::describeGuide([]));
    }
}
