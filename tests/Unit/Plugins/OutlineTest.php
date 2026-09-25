<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins;

use MarineTeam\Plugins\SermonNotes\Outline;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/plugins/sermon-notes/src/Outline.php';

/** The sermon note sheet, case for case as the original's lib/outline.test.ts. */
final class OutlineTest extends TestCase
{
    public function test_parse_splits_a_line_into_what_is_printed_and_what_is_filled_in(): void
    {
        self::assertSame([[['text' => 'Grace is '], ['gap' => 0], ['text' => ' and '], ['gap' => 1], ['text' => '.']]], Outline::parse('Grace is ___ and _____.'));
    }

    public function test_parse_numbers_the_gaps_across_the_whole_sheet_not_per_line(): void
    {
        $lines = Outline::parse("One ___\nTwo ___\nThree ___");
        self::assertSame(['gap' => 2], $lines[2][1]);
    }

    public function test_parse_keeps_blank_lines_so_the_sheet_has_the_shape_it_was_typed_in(): void
    {
        self::assertSame([[['text' => 'Point one']], [], [['text' => 'Point two']]], Outline::parse("Point one\n\nPoint two"));
    }

    public function test_parse_doesnt_treat_two_underscores_as_a_gap(): void
    {
        self::assertSame([[['text' => 'snake__case']]], Outline::parse('snake__case'));
    }

    public function test_parse_handles_a_gap_at_the_very_start_and_the_very_end(): void
    {
        self::assertSame([[['gap' => 0], ['text' => ' is love, and love is '], ['gap' => 1]]], Outline::parse('___ is love, and love is ___'));
    }

    public function test_parse_reads_windows_line_endings_as_lines_not_as_text(): void
    {
        self::assertSame([[['text' => 'A ']], [['text' => 'B '], ['gap' => 0]]], Outline::parse("A \r\nB ___"));
    }

    public function test_the_fingerprint_changes_when_a_gap_is_added(): void
    {
        self::assertNotSame(Outline::fingerprint("One ___\nTwo"), Outline::fingerprint("One ___\nTwo ___"));
    }

    public function test_the_fingerprint_is_the_same_whatever_the_line_endings(): void
    {
        self::assertSame(Outline::fingerprint("One ___\nTwo"), Outline::fingerprint("One ___\r\nTwo"));
    }

    public function test_to_text_puts_the_answers_back_into_the_sentence(): void
    {
        self::assertSame("Grace is unearned and free.", Outline::toText('Grace is ___ and ___.', ['0' => 'unearned', 1 => 'free']));
    }

    public function test_to_text_leaves_a_gap_that_was_never_filled_in(): void
    {
        self::assertSame("Grace is ___ and free.\n\nAmen", Outline::toText("Grace is _____ and ___.\n\nAmen", [1 => 'free']));
    }
}
