<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Ics;
use PHPUnit\Framework\TestCase;

/** iCalendar files, case for case as the original's lib/ics.test.ts. */
final class IcsTest extends TestCase
{
    private function at(string $when): \DateTimeImmutable
    {
        return new \DateTimeImmutable($when, new \DateTimeZone('UTC'));
    }

    // -- escapeText -------------------------------------------------------

    public function test_escapes_the_four_characters_the_format_reserves(): void
    {
        self::assertSame('a\\\\b\;c\\,d\\ne', Ics::escapeText("a\\b;c,d\ne"));
    }

    public function test_leaves_a_colon_alone_so_a_url_in_a_description_survives(): void
    {
        self::assertSame('See https://church.example/events', Ics::escapeText('See https://church.example/events'));
    }

    public function test_escapes_the_backslash_first_so_an_escape_is_not_escaped_twice(): void
    {
        self::assertSame('\\\\n', Ics::escapeText('\\n'), 'a literal backslash-n stays a literal one');
    }

    // -- foldLine ---------------------------------------------------------

    public function test_leaves_a_short_line_alone(): void
    {
        self::assertSame('SUMMARY:Carol service', Ics::foldLine('SUMMARY:Carol service'));
    }

    public function test_folds_at_75_octets_continuing_with_a_space(): void
    {
        $folded = Ics::foldLine('SUMMARY:' . str_repeat('a', 100));
        $lines = explode(Ics::CRLF, $folded);
        self::assertSame(75, strlen($lines[0]));
        self::assertStringStartsWith(' ', $lines[1]);
        self::assertSame('SUMMARY:' . str_repeat('a', 100), str_replace(Ics::CRLF . ' ', '', $folded), 'nothing is lost');
    }

    public function test_never_cuts_a_character_in_half(): void
    {
        $line = 'DESCRIPTION:' . str_repeat('x', 62) . '—an em dash across the boundary';
        foreach (explode(Ics::CRLF . ' ', Ics::foldLine($line)) as $piece) {
            self::assertSame($piece, mb_convert_encoding($piece, 'UTF-8', 'UTF-8'), 'every piece is still valid UTF-8');
        }
        // Unfolding — CRLF and the one space that follows it — gives the line back.
        self::assertSame($line, str_replace(Ics::CRLF . ' ', '', Ics::foldLine($line)), 'and nothing is lost');
    }

    // -- stamps -----------------------------------------------------------

    public function test_writes_an_instant_as_utc_with_no_punctuation(): void
    {
        self::assertSame('20260303T193000Z', Ics::stampInstant($this->at('2026-03-03 19:30:00')));
    }

    public function test_writes_a_day_as_eight_digits(): void
    {
        self::assertSame('20260303', Ics::stampDay($this->at('2026-03-03 19:30:00')));
    }

    // -- icsCalendar ------------------------------------------------------

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function entry(array $extra = []): array
    {
        return $extra + [
            'uid' => 'event-1@church.example',
            'summary' => 'Prayer meeting',
            'starts' => $this->at('2026-03-03 19:30:00'),
            'ends' => $this->at('2026-03-03 21:00:00'),
            'allDay' => false,
        ];
    }

    public function test_writes_a_calendar_a_client_will_accept(): void
    {
        $ics = Ics::icsCalendar([$this->entry()], "What's on");
        foreach (['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:', 'BEGIN:VEVENT', 'UID:event-1@church.example', 'DTSTART:20260303T193000Z', 'DTEND:20260303T210000Z', 'SUMMARY:Prayer meeting', 'END:VEVENT', 'END:VCALENDAR'] as $needle) {
            self::assertStringContainsString($needle, $ics, $needle);
        }
    }

    public function test_ends_every_line_with_crlf_which_the_format_requires(): void
    {
        $ics = Ics::icsCalendar([$this->entry()], 'Diary');
        self::assertStringEndsWith(Ics::CRLF, $ics);
        self::assertSame(0, preg_match('/[^\r]\n/', $ics), 'no bare newline anywhere');
    }

    public function test_ends_an_all_day_event_on_the_following_day_because_dtend_is_exclusive(): void
    {
        $ics = Ics::icsCalendar([$this->entry(['allDay' => true, 'ends' => null, 'starts' => $this->at('2026-07-04 00:00:00')])], 'Diary');
        self::assertStringContainsString('DTSTART;VALUE=DATE:20260704', $ics);
        self::assertStringContainsString('DTEND;VALUE=DATE:20260705', $ics);
    }

    public function test_keeps_an_explicit_all_day_finish(): void
    {
        $ics = Ics::icsCalendar([$this->entry(['allDay' => true, 'starts' => $this->at('2026-07-03 00:00:00'), 'ends' => $this->at('2026-07-06 00:00:00')])], 'Diary');
        self::assertStringContainsString('DTEND;VALUE=DATE:20260706', $ics);
    }

    public function test_leaves_the_finish_out_when_there_is_not_one(): void
    {
        self::assertStringNotContainsString('DTEND', Ics::icsCalendar([$this->entry(['ends' => null])], 'Diary'));
    }

    public function test_marks_a_cancellation_rather_than_dropping_it(): void
    {
        self::assertStringContainsString('STATUS:CANCELLED', Ics::icsCalendar([$this->entry(['status' => 'CANCELLED'])], 'Diary'));
    }

    public function test_writes_a_url_unescaped(): void
    {
        $ics = Ics::icsCalendar([$this->entry(['url' => 'https://church.example/events/prayer-meeting-2026-03-03'])], 'Diary');
        self::assertStringContainsString('URL:https://church.example/events/prayer-meeting-2026-03-03', str_replace(Ics::CRLF . ' ', '', $ics));
    }

    public function test_escapes_a_description_that_would_otherwise_break_the_file(): void
    {
        $ics = str_replace(Ics::CRLF . ' ', '', Ics::icsCalendar([$this->entry(['description' => "Bring a friend;\nand a Bible, please"])], 'Diary'));
        self::assertStringContainsString('DESCRIPTION:Bring a friend\;\\nand a Bible\\, please', $ics);
    }

    public function test_offers_a_refresh_interval_to_anything_subscribing(): void
    {
        $ics = Ics::icsCalendar([], 'Diary');
        self::assertStringContainsString('REFRESH-INTERVAL;VALUE=DURATION:PT60M', $ics);
        self::assertStringContainsString('X-PUBLISHED-TTL:PT60M', $ics);
    }

    public function test_balances_begin_and_end_for_every_event(): void
    {
        $ics = Ics::icsCalendar([$this->entry(), $this->entry(['uid' => 'two']), $this->entry(['uid' => 'three'])], 'Diary');
        self::assertSame(3, substr_count($ics, 'BEGIN:VEVENT'));
        self::assertSame(3, substr_count($ics, 'END:VEVENT'));
        self::assertSame(1, substr_count($ics, 'BEGIN:VCALENDAR'));
        self::assertSame(1, substr_count($ics, 'END:VCALENDAR'));
    }

    // -- icsFilename ------------------------------------------------------

    public function test_makes_a_name_a_browser_will_save(): void
    {
        self::assertSame('Carol-service.ics', Ics::icsFilename('Carol service'));
        self::assertSame('calendar.ics', Ics::icsFilename('   '));
    }

    public function test_drops_anything_that_could_break_the_header_it_sits_in(): void
    {
        $name = Ics::icsFilename("evil\r\nContent-Type: text/html\"; x=\"y/../..");
        self::assertSame('evil-Content-Type-text-html-x-y', substr($name, 0, -4));
        self::assertDoesNotMatchRegularExpression('/["\r\n\/\\\\]/', $name);
    }
}
