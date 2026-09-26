<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins;

use MarineTeam\Plugins\ServicePlans\Rota;
use MarineTeam\Plugins\ServicePlans\Services;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/plugins/service-plans/src/Services.php';
require_once dirname(__DIR__, 3) . '/plugins/service-plans/src/Rota.php';

/** Service plans and the rota, case for case as lib/services.test.ts and lib/rota.test.ts. */
final class ServicesTest extends TestCase
{
    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function file(array $extra = []): array
    {
        return $extra + [
            'id' => 'f1', 'title' => 'Be Thou My Vision', 'mime_type' => 'application/pdf',
            'page_number' => null, 'lyrics_text' => null, 'published' => 1, 'hidden' => 0, 'deleted_at' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function hymnFile(array $extra = []): array
    {
        return $this->file($extra + ['mime_type' => 'application/pdf', 'page_number' => 473, 'lyrics_text' => 'Be thou my vision…']);
    }

    /** @return array<string, mixed> */
    private function item(array $extra = []): array
    {
        return $extra + ['id' => 'i1', 'hymn_number' => null, 'note' => null, 'position' => 0];
    }

    private const HYMN_SERIES = ['id' => 's1', 'hymn_per_file' => 1];
    private const BOOK_SERIES = ['id' => 's2', 'hymn_per_file' => 0];

    // -- planItemHref -----------------------------------------------------

    public function test_href_opens_a_hymn_that_is_its_own_file_at_its_lyrics(): void
    {
        self::assertSame('/hymns/f1', Services::planItemHref($this->item(), $this->hymnFile(), self::HYMN_SERIES));
    }

    public function test_href_carries_the_number_to_a_whole_books_contents_which_knows_how_to_resolve_it(): void
    {
        self::assertSame('/books/f1?hymn=473', Services::planItemHref($this->item(['hymn_number' => 473]), $this->file(), self::BOOK_SERIES));
    }

    public function test_href_falls_back_to_the_contents_when_nobody_wrote_a_number_down(): void
    {
        self::assertSame('/books/f1', Services::planItemHref($this->item(), $this->file(), self::BOOK_SERIES));
    }

    public function test_href_keeps_a_number_off_a_hymns_own_page_which_has_nothing_to_resolve(): void
    {
        self::assertSame('/hymns/f1', Services::planItemHref($this->item(['hymn_number' => 473]), $this->hymnFile(), self::HYMN_SERIES));
    }

    public function test_href_has_nowhere_to_open_a_file_that_is_not_a_hymn_or_a_book(): void
    {
        self::assertNull(Services::planItemHref($this->item(), $this->file(['mime_type' => 'audio/mpeg']), self::BOOK_SERIES));
        self::assertNull(Services::planItemHref($this->item(), $this->file(['mime_type' => 'audio/mpeg']), null));
    }

    // -- planItemNumber ---------------------------------------------------

    public function test_number_prefers_the_number_written_for_this_service(): void
    {
        self::assertSame(12, Services::planItemNumber($this->item(['hymn_number' => 12]), $this->hymnFile()));
    }

    public function test_number_falls_back_to_the_hymns_own_printed_number(): void
    {
        self::assertSame(473, Services::planItemNumber($this->item(), $this->hymnFile()));
        self::assertNull(Services::planItemNumber($this->item(), $this->file()));
    }

    // -- planItemReadable -------------------------------------------------

    public function test_readable_opens_what_is_published_and_public(): void
    {
        self::assertTrue(Services::planItemReadable($this->file(), accessOk: true));
    }

    public function test_readable_closes_a_members_only_hymn_to_a_signed_out_visitor_and_opens_it_to_a_member(): void
    {
        self::assertFalse(Services::planItemReadable($this->file(['member_only' => 1]), accessOk: false));
        self::assertTrue(Services::planItemReadable($this->file(['member_only' => 1]), accessOk: true));
    }

    public function test_readable_closes_a_hymn_unpublished_hidden_or_trashed_since_the_plan_was_made(): void
    {
        self::assertFalse(Services::planItemReadable($this->file(['published' => 0]), accessOk: true));
        self::assertFalse(Services::planItemReadable($this->file(['hidden' => 1]), accessOk: true));
        self::assertFalse(Services::planItemReadable($this->file(['deleted_at' => '2026-03-01 10:00:00']), accessOk: true));
    }

    // -- planItemPresentable ----------------------------------------------

    public function test_presentable_presents_a_hymn_that_is_its_own_file_from_the_words_on_its_row(): void
    {
        self::assertTrue(Services::planItemPresentable($this->item(), $this->hymnFile(), self::HYMN_SERIES));
    }

    public function test_presentable_presents_a_number_inside_a_book_when_somebody_has_typed_that_numbers_words(): void
    {
        $detail = ['number' => 473, 'lyrics_text' => 'Be thou my vision…'];
        self::assertTrue(Services::planItemPresentable($this->item(['hymn_number' => 473]), $this->file(), self::BOOK_SERIES, $detail));
    }

    public function test_presentable_does_not_present_a_number_whose_own_words_are_missing(): void
    {
        self::assertFalse(Services::planItemPresentable($this->item(['hymn_number' => 474]), $this->file(), self::BOOK_SERIES, null));
    }

    public function test_presentable_does_not_present_a_scanned_book_nobody_has_typed_anything_out_of(): void
    {
        self::assertFalse(Services::planItemPresentable($this->item(['hymn_number' => 473]), $this->file(), self::BOOK_SERIES));
    }

    public function test_presentable_does_not_present_a_scanned_books_hymn_that_has_credits_but_no_words(): void
    {
        $detail = ['number' => 473, 'lyrics_text' => '   ', 'ccli_number' => '1234', 'author' => 'Eleanor Hull'];
        self::assertFalse(Services::planItemPresentable($this->item(['hymn_number' => 473]), $this->file(), self::BOOK_SERIES, $detail));
        // The same for a hymn that is its own file.
        self::assertFalse(Services::planItemPresentable($this->item(), $this->hymnFile(['lyrics_text' => null, 'ccli_number' => '1234']), self::HYMN_SERIES));
    }

    public function test_presentable_does_not_present_a_whole_book_listed_without_a_number(): void
    {
        self::assertFalse(Services::planItemPresentable($this->item(), $this->file(), self::BOOK_SERIES, ['lyrics_text' => 'Something']));
    }

    // -- presentHref ------------------------------------------------------

    public function test_present_href_carries_the_hymn_number_so_the_presenter_knows_which_hymn_of_the_book(): void
    {
        self::assertSame('/present/f1?hymn=473&plan=p1', Services::presentHref($this->item(['hymn_number' => 473]), $this->file(), self::BOOK_SERIES, 'p1'));
    }

    public function test_present_href_presents_a_hymn_that_is_its_own_file_with_no_number_to_carry(): void
    {
        self::assertSame('/present/f1?plan=p1', Services::presentHref($this->item(['hymn_number' => 473]), $this->hymnFile(), self::HYMN_SERIES, 'p1'));
    }

    public function test_present_href_leaves_the_plan_out_when_the_hymn_is_not_being_presented_as_part_of_one(): void
    {
        self::assertSame('/present/f1?hymn=473', Services::presentHref($this->item(['hymn_number' => 473]), $this->file(), self::BOOK_SERIES));
        self::assertSame('/present/f1', Services::presentHref($this->item(), $this->hymnFile(), self::HYMN_SERIES));
    }

    // -- isBlockedOut -----------------------------------------------------

    /** @return list<array<string, mixed>> */
    private function blockouts(): array
    {
        return [['start_date' => '2026-03-03 00:00:00', 'end_date' => '2026-03-05 00:00:00', 'reason' => 'Away']];
    }

    public function test_blockout_covers_both_ends_of_the_range_because_that_is_how_people_say_it(): void
    {
        foreach (['2026-03-03', '2026-03-04', '2026-03-05'] as $day) {
            self::assertTrue(Rota::isBlockedOut($this->blockouts(), $day . ' 10:30:00'), $day);
        }
    }

    public function test_blockout_does_not_cover_the_days_either_side(): void
    {
        self::assertFalse(Rota::isBlockedOut($this->blockouts(), '2026-03-02 10:30:00'));
        self::assertFalse(Rota::isBlockedOut($this->blockouts(), '2026-03-06 10:30:00'));
    }

    public function test_blockout_says_nothing_about_a_service_with_no_date(): void
    {
        self::assertFalse(Rota::isBlockedOut($this->blockouts(), null));
        self::assertFalse(Rota::isBlockedOut($this->blockouts(), ''));
    }

    public function test_blockout_is_false_for_somebody_with_no_blockouts(): void
    {
        self::assertFalse(Rota::isBlockedOut([], '2026-03-04 10:30:00'));
    }

    // -- assignmentRole / personName --------------------------------------

    public function test_role_uses_the_job_where_one_was_written_down(): void
    {
        self::assertSame('Sound desk', Rota::assignmentRole(['position' => 'Sound desk'], ['name' => 'Tech team']));
    }

    public function test_role_falls_back_to_the_team_so_a_row_is_never_nameless(): void
    {
        self::assertSame('Tech team', Rota::assignmentRole(['position' => ''], ['name' => 'Tech team']));
        self::assertSame('Tech team', Rota::assignmentRole(['position' => '   '], ['name' => 'Tech team']));
    }

    public function test_person_name_prefers_the_name_somebody_chose_for_themselves(): void
    {
        self::assertSame('Ruthie', Rota::personName(['display_name' => 'Ruthie', 'name' => 'Ruth Moab']));
        self::assertSame('Ruth Moab', Rota::personName(['display_name' => null, 'name' => 'Ruth Moab']));
        self::assertSame('', Rota::personName(['display_name' => '', 'name' => 'ruth@test.example']), 'and never an address');
    }
}
