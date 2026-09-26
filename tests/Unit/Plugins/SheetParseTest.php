<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins;

use MarineTeam\Plugins\Schedules\Parse;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/plugins/schedules/src/Names.php';
require_once dirname(__DIR__, 3) . '/plugins/schedules/src/Dates.php';
require_once dirname(__DIR__, 3) . '/plugins/schedules/src/Parse.php';

/** Reading a rota out of a spreadsheet: the original's lib/sheets/parse.test.ts. */
final class SheetParseTest extends TestCase
{
    private const TODAY = '2026-06-01';

    /** @param list<list<mixed>> $rows @param array<string, mixed> $config */
    private function parse(array $rows, array $config = []): array
    {
        return Parse::parse($rows, $config + ['today' => self::TODAY]);
    }

    /** @return list<string> */
    private function reasons(array $result): array
    {
        return array_column($result['skipped'], 'reason');
    }

    // -- column resolution ------------------------------------------------

    public function test_columns_convert_letters_to_indexes(): void
    {
        self::assertSame(0, Parse::columnIndex('A'));
        self::assertSame(25, Parse::columnIndex('Z'));
        self::assertSame(26, Parse::columnIndex('AA'));
        self::assertNull(Parse::columnIndex('not a column'));
    }

    public function test_columns_prefer_a_header_match_over_a_letter(): void
    {
        self::assertSame(2, Parse::resolveColumn(['Week', 'Names', 'Date'], 'Date'));
    }

    public function test_columns_fall_back_to_the_letter_when_no_header_matches(): void
    {
        self::assertSame(0, Parse::resolveColumn(['Week', 'Names'], 'A'));
        self::assertNull(Parse::resolveColumn(['Week'], null));
    }

    // -- DATE_NAMES -------------------------------------------------------

    /** @return list<list<mixed>> */
    private function dateNames(): array
    {
        return [
            ['Date', 'Names', 'Notes'],
            ['July 5', 'Dave, Sue', 'Communion'],
            ['July 12', 'Bob & Dave', ''],
        ];
    }

    public function test_date_names_parses_the_documented_example(): void
    {
        $out = $this->parse($this->dateNames(), ['format' => Parse::DATE_NAMES, 'headerRow' => 1, 'notesColumn' => 'Notes']);
        self::assertSame([], $out['skipped']);
        self::assertSame(['2026-07-05', '2026-07-12'], array_column($out['events'], 'date'));
        self::assertSame(['Dave', 'Sue'], $out['events'][0]['people']);
        self::assertSame('Communion', $out['events'][0]['notes']);
    }

    public function test_date_names_splits_and_collapses_duplicate_names_inside_one_cell(): void
    {
        $out = $this->parse([['Date', 'Names'], ['July 5', 'Dave / Sue; DAVE + Bob']], ['format' => Parse::DATE_NAMES, 'headerRow' => 1]);
        self::assertSame(['Dave', 'Sue', 'Bob'], $out['events'][0]['people']);
    }

    public function test_date_names_skips_blank_rows_silently(): void
    {
        $rows = [['Date', 'Names'], ['', ''], ['July 5', 'Dave'], ['', '']];
        $out = $this->parse($rows, ['format' => Parse::DATE_NAMES, 'headerRow' => 1]);
        self::assertCount(1, $out['events']);
        self::assertSame([], $out['skipped']);
    }

    public function test_date_names_reports_a_row_with_content_but_no_date(): void
    {
        $out = $this->parse([['Date', 'Names'], ['', 'Dave']], ['format' => Parse::DATE_NAMES, 'headerRow' => 1]);
        self::assertSame(['No date in this row.'], $this->reasons($out));
    }

    public function test_date_names_reports_a_missing_date_column_rather_than_importing_nothing_silently(): void
    {
        // The whole column is empty: a mapping mistake, not a missing row.
        $rows = [['Week', 'Names'], ['27', 'Dave'], ['28', 'Sue'], ['29', 'Bob']];
        $out = $this->parse($rows, ['format' => Parse::DATE_NAMES, 'headerRow' => 1, 'dateColumn' => 'Date']);
        self::assertSame([], $out['events']);
        self::assertStringContainsString('No date column was found', implode(' ', $this->reasons($out)));
    }

    public function test_date_names_reports_an_invalid_date_and_keeps_the_surrounding_rows(): void
    {
        $rows = [['Date', 'Names'], ['July 5', 'Dave'], ['whenever', 'Sue'], ['July 19', 'Bob']];
        $out = $this->parse($rows, ['format' => Parse::DATE_NAMES, 'headerRow' => 1]);
        self::assertSame(['2026-07-05', '2026-07-19'], array_column($out['events'], 'date'));
        self::assertStringContainsString('whenever', $this->reasons($out)[0]);
        self::assertSame(3, $out['skipped'][0]['row'], 'and says which row');
    }

    public function test_date_names_reports_rows_with_a_valid_date_but_nobody_listed_and_can_keep_them(): void
    {
        $rows = [['Date', 'Names'], ['July 5', '']];
        self::assertSame(['Nobody is listed.'], $this->reasons($this->parse($rows, ['format' => Parse::DATE_NAMES, 'headerRow' => 1])));
        $kept = $this->parse($rows, ['format' => Parse::DATE_NAMES, 'headerRow' => 1, 'keepUnassigned' => true]);
        self::assertCount(1, $kept['events']);
        self::assertSame([], $kept['events'][0]['people']);
    }

    public function test_date_names_rejects_entries_that_are_not_names(): void
    {
        $out = $this->parse([['Date', 'Names'], ['July 5', 'Dave, 07700 900123']], ['format' => Parse::DATE_NAMES, 'headerRow' => 1]);
        self::assertSame(['Dave'], $out['events'][0]['people']);
        self::assertStringContainsString('Not a name', $this->reasons($out)[0]);
    }

    public function test_date_names_reports_an_empty_sheet(): void
    {
        self::assertSame(['The sheet is empty.'], $this->reasons($this->parse([], ['format' => Parse::DATE_NAMES])));
        self::assertSame(['The sheet is empty.'], $this->reasons($this->parse([['Date', 'Names']], ['format' => Parse::DATE_NAMES, 'headerRow' => 1])));
    }

    public function test_date_names_reads_optional_title_notes_and_time_columns(): void
    {
        $rows = [['Date', 'Names', 'Title', 'Notes', 'Time', 'Where'], ['July 5', 'Dave', 'Morning', 'Bring bread', '10.30am', 'The hall']];
        $out = $this->parse($rows, ['format' => Parse::DATE_NAMES, 'headerRow' => 1, 'titleColumn' => 'Title', 'notesColumn' => 'Notes', 'timeColumn' => 'Time', 'locationColumn' => 'Where']);
        self::assertSame('Morning', $out['events'][0]['title']);
        self::assertSame('Bring bread', $out['events'][0]['notes']);
        self::assertSame('10:30', $out['events'][0]['startTime']);
        self::assertSame('The hall', $out['events'][0]['location']);
    }

    public function test_date_names_works_with_no_header_row_when_columns_are_given_by_letter(): void
    {
        $out = $this->parse([['July 5', 'Dave']], ['format' => Parse::DATE_NAMES, 'headerRow' => 0, 'dateColumn' => 'A', 'namesColumn' => 'B']);
        self::assertSame(['2026-07-05'], array_column($out['events'], 'date'));
    }

    public function test_date_names_gives_two_events_on_the_same_day_distinct_external_ids(): void
    {
        $rows = [['Date', 'Names'], ['July 5', 'Dave'], ['July 5', 'Sue']];
        $out = $this->parse($rows, ['format' => Parse::DATE_NAMES, 'headerRow' => 1]);
        self::assertSame(['2026-07-05', '2026-07-05#2'], array_column($out['events'], 'externalId'));
    }

    public function test_date_names_truncates_a_sheet_that_exceeds_max_rows_and_says_so(): void
    {
        $rows = [['Date', 'Names']];
        foreach (range(1, 12) as $n) {
            $rows[] = ['July ' . $n, 'Dave'];
        }
        $out = $this->parse($rows, ['format' => Parse::DATE_NAMES, 'headerRow' => 1, 'maxRows' => 5]);
        self::assertCount(5, $out['events']);
        self::assertTrue($out['truncated']);
        self::assertStringContainsString('longer than 5 rows', implode(' ', $this->reasons($out)));
    }

    public function test_date_names_skips_dates_outside_the_configured_import_window(): void
    {
        $rows = [['Date', 'Names'], ['July 5', 'Dave'], ['December 25', 'Sue']];
        $out = $this->parse($rows, ['format' => Parse::DATE_NAMES, 'headerRow' => 1, 'importFrom' => '2026-07-01', 'importTo' => '2026-08-31']);
        self::assertSame(['2026-07-05'], array_column($out['events'], 'date'));
        self::assertStringContainsString('Outside the window', implode(' ', $this->reasons($out)));
    }

    // -- NAME_COLUMNS -----------------------------------------------------

    /** @return list<list<mixed>> */
    private function nameColumns(): array
    {
        return [
            ['Date', 'Devin', 'Cindy', 'Notes'],
            ['July 5', 'x', 'Bread', 'Communion'],
            ['July 12', '✓', '-', ''],
        ];
    }

    public function test_name_columns_parses_the_documented_example(): void
    {
        $out = $this->parse($this->nameColumns(), ['format' => Parse::NAME_COLUMNS, 'headerRow' => 1, 'notesColumn' => 'Notes']);
        self::assertSame([], $out['skipped']);
        self::assertSame(['Devin', 'Cindy'], $out['events'][0]['people']);
        self::assertSame(['Cindy' => 'Bread'], $out['events'][0]['roles'], 'free text in a person column is the job');
        self::assertSame(['Devin'], $out['events'][1]['people'], 'an explicit negative is not being on');
    }

    public function test_name_columns_accept_a_variety_of_tick_marks(): void
    {
        $rows = [['Date', 'Devin', 'Cindy', 'Bob', 'Sue'], ['July 5', 'X', '✔', 'yes', '1']];
        $out = $this->parse($rows, ['format' => Parse::NAME_COLUMNS, 'headerRow' => 1]);
        self::assertSame(['Devin', 'Cindy', 'Bob', 'Sue'], $out['events'][0]['people']);
        self::assertSame([], $out['events'][0]['roles']);
    }

    public function test_name_columns_ignore_configured_non_person_columns_and_obvious_ones(): void
    {
        $rows = [['Date', 'Week', 'Notes', 'Location', 'Time', 'Devin'], ['July 5', '27', 'Something', 'The hall', '10:30', 'x']];
        $out = $this->parse($rows, ['format' => Parse::NAME_COLUMNS, 'headerRow' => 1, 'notesColumn' => 'Notes', 'locationColumn' => 'Location', 'timeColumn' => 'Time']);
        self::assertSame(['Devin'], $out['events'][0]['people'], 'a sheet with a notes column does not acquire a person called Notes');
    }

    public function test_name_columns_collapse_two_columns_for_the_same_person_and_report_it(): void
    {
        $rows = [['Date', 'Dave', 'DAVE'], ['July 5', 'x', 'Bread']];
        $out = $this->parse($rows, ['format' => Parse::NAME_COLUMNS, 'headerRow' => 1]);
        self::assertSame(['Dave'], $out['events'][0]['people']);
        self::assertStringContainsString('Two columns for Dave', implode(' ', $this->reasons($out)));
    }

    public function test_name_columns_ignore_a_header_that_is_not_usable_as_a_name(): void
    {
        $rows = [['Date', '', '12', 'Devin'], ['July 5', 'x', 'x', 'x']];
        $out = $this->parse($rows, ['format' => Parse::NAME_COLUMNS, 'headerRow' => 1]);
        self::assertSame(['Devin'], $out['events'][0]['people']);
    }

    public function test_name_columns_require_a_header_row_and_say_so_when_there_is_none(): void
    {
        $out = $this->parse([['July 5', 'x']], ['format' => Parse::NAME_COLUMNS, 'headerRow' => 0]);
        self::assertSame([], $out['events']);
        self::assertStringContainsString('needs a header row', implode(' ', $this->reasons($out)));
    }

    public function test_name_columns_report_a_row_where_nobody_is_marked(): void
    {
        $rows = [['Date', 'Devin'], ['July 5', '']];
        self::assertStringContainsString('Nobody is marked', implode(' ', $this->reasons($this->parse($rows, ['format' => Parse::NAME_COLUMNS, 'headerRow' => 1]))));
    }

    public function test_name_columns_survive_rows_shorter_than_the_header(): void
    {
        $rows = [['Date', 'Devin', 'Cindy'], ['July 5', 'x']];
        $out = $this->parse($rows, ['format' => Parse::NAME_COLUMNS, 'headerRow' => 1]);
        self::assertSame(['Devin'], $out['events'][0]['people']);
    }

    // -- fingerprintEvents ------------------------------------------------

    public function test_fingerprint_is_stable_for_identical_input(): void
    {
        $events = $this->parse($this->dateNames(), ['format' => Parse::DATE_NAMES, 'headerRow' => 1])['events'];
        self::assertSame(Parse::fingerprintEvents($events), Parse::fingerprintEvents($events));
    }

    public function test_fingerprint_is_unchanged_by_name_casing_or_ordering_within_a_row(): void
    {
        $one = $this->parse([['Date', 'Names'], ['July 5', 'Dave, Sue']], ['format' => Parse::DATE_NAMES, 'headerRow' => 1])['events'];
        $two = $this->parse([['Date', 'Names'], ['July 5', 'SUE, dave']], ['format' => Parse::DATE_NAMES, 'headerRow' => 1])['events'];
        self::assertSame(Parse::fingerprintEvents($one), Parse::fingerprintEvents($two));
    }

    public function test_fingerprint_changes_when_a_person_is_added_or_a_date_moves(): void
    {
        $base = $this->parse([['Date', 'Names'], ['July 5', 'Dave']], ['format' => Parse::DATE_NAMES, 'headerRow' => 1])['events'];
        $more = $this->parse([['Date', 'Names'], ['July 5', 'Dave, Sue']], ['format' => Parse::DATE_NAMES, 'headerRow' => 1])['events'];
        $moved = $this->parse([['Date', 'Names'], ['July 12', 'Dave']], ['format' => Parse::DATE_NAMES, 'headerRow' => 1])['events'];
        self::assertNotSame(Parse::fingerprintEvents($base), Parse::fingerprintEvents($more));
        self::assertNotSame(Parse::fingerprintEvents($base), Parse::fingerprintEvents($moved));
    }
}
