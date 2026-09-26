<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins;

use MarineTeam\Plugins\Schedules\Dates;
use MarineTeam\Plugins\Schedules\Names;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/plugins/schedules/src/Names.php';
require_once dirname(__DIR__, 3) . '/plugins/schedules/src/Dates.php';

/** Names and dates off a spreadsheet: lib/names.test.ts and lib/sheets/dates.test.ts. */
final class SchedulesNamesTest extends TestCase
{
    // -- normalizeName ----------------------------------------------------

    public function test_normalize_folds_the_case_and_whitespace_variants_onto_one_key(): void
    {
        foreach (['Dave', 'DAVE', 'dave', ' Dave '] as $spelling) {
            self::assertSame('dave', Names::normalizeName($spelling), $spelling);
        }
    }

    public function test_normalize_collapses_internal_whitespace(): void
    {
        self::assertSame('dave smith', Names::normalizeName("Dave   Smith"));
        self::assertSame('dave smith', Names::normalizeName("Dave \t Smith"));
    }

    public function test_normalize_folds_accents_so_jose_and_josé_match(): void
    {
        self::assertSame(Names::normalizeName('Jose'), Names::normalizeName('José'));
    }

    public function test_normalize_normalizes_typographic_apostrophes_and_hyphens(): void
    {
        self::assertSame(Names::normalizeName("O'Brien"), Names::normalizeName('O’Brien'));
        self::assertSame(Names::normalizeName('Anne-Marie'), Names::normalizeName("Anne\u{2013}Marie"));
    }

    public function test_normalize_returns_an_empty_string_for_input_with_nothing_usable(): void
    {
        foreach (['', '   ', '×', '...'] as $nothing) {
            self::assertSame('', Names::normalizeName($nothing), $nothing);
        }
    }

    public function test_normalize_keeps_genuinely_different_people_apart(): void
    {
        self::assertNotSame(Names::normalizeName('Dave'), Names::normalizeName('Davey'));
        self::assertNotSame(Names::normalizeName('Dave Smith'), Names::normalizeName('Dave Smyth'));
    }

    // -- toDisplayName ----------------------------------------------------

    public function test_display_preserves_a_deliberate_mixed_case_spelling(): void
    {
        self::assertSame('McDonald', Names::toDisplayName('McDonald'));
        self::assertSame('de Vries', Names::toDisplayName('de Vries'));
    }

    public function test_display_title_cases_spreadsheet_artefacts(): void
    {
        self::assertSame('Dave Smith', Names::toDisplayName('DAVE SMITH'));
        self::assertSame('Dave Smith', Names::toDisplayName('dave smith'));
    }

    public function test_display_capitalizes_after_hyphens_and_apostrophes(): void
    {
        self::assertSame("Anne-Marie O'Brien", Names::toDisplayName("ANNE-MARIE O'BRIEN"));
    }

    public function test_display_trims_and_collapses_whitespace(): void
    {
        self::assertSame('Dave Smith', Names::toDisplayName("  dave   smith  "));
    }

    public function test_display_returns_an_empty_string_for_empty_input(): void
    {
        self::assertSame('', Names::toDisplayName('   '));
    }

    // -- splitNames -------------------------------------------------------

    public function test_split_splits_on_the_common_separators(): void
    {
        foreach ([',', '/', '&', ';', '+'] as $separator) {
            self::assertSame(['Dave', 'Sue'], Names::splitNames("Dave $separator Sue"), $separator);
        }
        self::assertSame(['Dave', 'Sue'], Names::splitNames("Dave and Sue"));
    }

    public function test_split_handles_a_mix_of_separators(): void
    {
        self::assertSame(['Dave', 'Sue', 'Bob'], Names::splitNames("Dave, Sue & Bob"));
    }

    public function test_split_drops_blank_fragments(): void
    {
        self::assertSame(['Dave', 'Sue'], Names::splitNames("Dave,, / Sue,"));
    }

    public function test_split_deduplicates_on_the_normalized_form(): void
    {
        self::assertSame(['Dave', 'Sue'], Names::splitNames("Dave, DAVE, Sue"));
    }

    public function test_split_respects_a_custom_separator_list(): void
    {
        self::assertSame(['Dave & Sue', 'Bob'], Names::splitNames('Dave & Sue | Bob', ['|']));
    }

    public function test_split_does_not_split_a_name_that_merely_contains_the_letters_and(): void
    {
        self::assertSame(['Alexandra'], Names::splitNames('Alexandra'));
        self::assertSame(['Sandy'], Names::splitNames('Sandy'));
    }

    public function test_is_plausible_name(): void
    {
        foreach (['Dave', "Anne-Marie O'Brien", 'José'] as $name) {
            self::assertTrue(Names::isPlausibleName($name), $name);
        }
        foreach (['', '×', 'x', 'Notes', '7/10', str_repeat('a', 200)] as $notAName) {
            self::assertSame($notAName === 'Notes' || $notAName === 'x', Names::isPlausibleName($notAName), $notAName);
        }
    }

    // -- parseSheetDate ---------------------------------------------------

    /** @return array{date: ?string, problem: ?string} */
    private function read(mixed $cell, array $options = []): array
    {
        return Dates::parseSheetDate($cell, $options + ['today' => '2026-06-01']);
    }

    public function test_dates_ignore_a_leading_weekday_and_read_day_first_wording(): void
    {
        self::assertSame('2026-07-12', $this->read('Sunday, July 12')['date']);
        self::assertSame('2026-07-10', $this->read('july 10th')['date']);
        self::assertSame('2026-07-10', $this->read('10 July')['date']);
    }

    public function test_dates_default_to_month_day_and_honour_the_day_first_setting(): void
    {
        self::assertSame('2026-07-10', $this->read('7/10')['date']);
        self::assertSame('2026-10-07', $this->read('7/10', ['dayFirst' => true])['date']);
    }

    public function test_dates_detect_an_unambiguous_day_month_even_when_configured_month_first(): void
    {
        self::assertSame('2026-07-13', $this->read('13/7')['date']);
    }

    public function test_dates_expand_two_digit_years_and_parse_iso(): void
    {
        self::assertSame('2026-07-10', $this->read('7/10/26')['date']);
        self::assertSame('2026-07-10', $this->read('2026-07-10')['date']);
    }

    public function test_dates_convert_a_spreadsheet_serial_including_one_that_arrived_as_a_string(): void
    {
        self::assertSame('2026-07-10', $this->read(46213)['date']);
        self::assertSame('2026-07-10', $this->read('46213')['date']);
    }

    public function test_dates_reject_numbers_outside_the_plausible_range(): void
    {
        self::assertSame(Dates::UNREADABLE, $this->read(12)['problem']);
        self::assertSame(Dates::UNREADABLE, $this->read(999999)['problem']);
    }

    public function test_dates_report_empty_separately_from_unrecognized(): void
    {
        self::assertSame(Dates::EMPTY, $this->read('   ')['problem']);
        self::assertSame(Dates::UNREADABLE, $this->read('next Sunday-ish')['problem']);
    }

    public function test_dates_reject_impossible_calendar_days_and_absurd_input(): void
    {
        self::assertSame(Dates::UNREADABLE, $this->read('2/30/2026')['problem']);
        self::assertSame(Dates::UNREADABLE, $this->read(str_repeat('July 10, ', 20))['problem']);
        self::assertSame(Dates::UNREADABLE, $this->read(['not', 'a', 'cell'])['problem']);
    }

    public function test_dates_reject_dates_decades_away_which_are_almost_always_typos(): void
    {
        self::assertSame(Dates::IMPLAUSIBLE, $this->read('2050-01-01')['problem']);
        self::assertSame(Dates::IMPLAUSIBLE, $this->read('1999-01-01')['problem']);
    }

    public function test_dates_infer_the_year_the_way_somebody_filling_in_a_rota_means_it(): void
    {
        // June: a July date is this year's, and a January one next year's.
        self::assertSame('2026-07-10', $this->read('July 10')['date']);
        self::assertSame('2027-01-10', $this->read('January 10')['date']);
        // One that has only just passed stays in the current year.
        self::assertSame('2026-05-24', $this->read('May 24')['date']);
        self::assertSame('2028-07-10', $this->read('July 10', ['defaultYear' => 2028])['date']);
        self::assertSame(2027, Dates::guessYear(1, 10, [], '2026-06-01'), 'the heuristic on its own');
    }

    // -- parseSheetTime ---------------------------------------------------

    public function test_times_are_read_the_way_people_write_them(): void
    {
        self::assertSame('19:30', Dates::parseSheetTime('7.30pm'));
        self::assertSame('19:30', Dates::parseSheetTime('19:30'));
        self::assertSame('09:00', Dates::parseSheetTime('9am'));
        self::assertSame('00:30', Dates::parseSheetTime('12:30am'));
        self::assertNull(Dates::parseSheetTime('half seven'));
        self::assertNull(Dates::parseSheetTime('25:00'));
    }
}
