<?php

declare(strict_types=1);

namespace MarineTeam\Plugins\Schedules;

/**
 * Dates as people type them into a rota (the original's lib/sheets/dates.ts).
 * "July 10", "july 10th", "Sunday, July 12", "7/10", "2026-07-10" and a
 * real spreadsheet date all work; a cell nobody can read is reported, never
 * guessed at, because one bad row must never abort an import.
 */
final class Dates
{
    /** How a refusal is reported. */
    public const EMPTY = 'empty';
    public const UNREADABLE = 'unreadable';
    public const IMPLAUSIBLE = 'implausible';

    /** A spreadsheet serial is days since this day. */
    public const SERIAL_EPOCH = '1899-12-30';
    /** Serials outside this range are not dates anybody meant. */
    public const SERIAL_MIN = 20000;
    public const SERIAL_MAX = 80000;
    /** A date this far from today is almost always a typo. */
    public const YEARS_EITHER_WAY = 5;
    public const MAX_LENGTH = 40;

    /**
     * @param array{dayFirst?: bool, defaultYear?: ?int, today?: string} $options
     * @return array{date: ?string, problem: ?string}
     */
    public static function parseSheetDate(mixed $cell, array $options = []): array
    {
        $today = (string) ($options['today'] ?? gmdate('Y-m-d'));
        if (is_float($cell) || is_int($cell)) {
            return self::fromSerial((float) $cell, $today);
        }
        if (!is_string($cell)) {
            return ['date' => null, 'problem' => self::UNREADABLE];
        }
        $text = trim($cell);
        if ($text === '') {
            return ['date' => null, 'problem' => self::EMPTY];
        }
        if (mb_strlen($text) > self::MAX_LENGTH) {
            // Not worth trying to parse a sentence.
            return ['date' => null, 'problem' => self::UNREADABLE];
        }
        if (preg_match('/^\d+(\.\d+)?$/', $text)) {
            return self::fromSerial((float) $text, $today);
        }
        // A leading weekday is a courtesy, not information.
        $text = (string) preg_replace('/^(mon|tue|tues|wed|weds|thu|thur|thurs|fri|sat|sun)[a-z]*\s*[,\.]?\s*/i', '', $text);
        $text = (string) preg_replace('/(\d+)(st|nd|rd|th)\b/i', '$1', $text);
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $text, $m)) {
            return self::make((int) $m[1], (int) $m[2], (int) $m[3], $today);
        }
        if (preg_match('/^(\d{1,2})[\/\.\-](\d{1,2})([\/\.\-](\d{2,4}))?$/', $text, $m)) {
            $a = (int) $m[1];
            $b = (int) $m[2];
            $year = isset($m[4]) ? self::expandYear((int) $m[4]) : null;
            $dayFirst = (bool) ($options['dayFirst'] ?? false);
            // An unambiguous day/month is read as one even when the sheet is
            // configured month-first: there is no 13th month.
            if ($a > 12 && $b <= 12) {
                $dayFirst = true;
            } elseif ($b > 12 && $a <= 12) {
                $dayFirst = false;
            }
            [$month, $day] = $dayFirst ? [$b, $a] : [$a, $b];
            return self::make($year ?? self::guessYear($month, $day, $options, $today), $month, $day, $today);
        }
        // Month-name forms, either way round.
        $months = self::months();
        $name = '(' . implode('|', array_keys($months)) . ')';
        if (preg_match('/^' . $name . '\s+(\d{1,2})(\s*,?\s*(\d{4}))?$/i', $text, $m)) {
            $month = $months[strtolower($m[1])];
            $day = (int) $m[2];
            return self::make(isset($m[4]) && $m[4] !== '' ? (int) $m[4] : self::guessYear($month, $day, $options, $today), $month, $day, $today);
        }
        if (preg_match('/^(\d{1,2})\s+' . $name . '(\s*,?\s*(\d{4}))?$/i', $text, $m)) {
            $month = $months[strtolower($m[2])];
            $day = (int) $m[1];
            return self::make(isset($m[4]) && $m[4] !== '' ? (int) $m[4] : self::guessYear($month, $day, $options, $today), $month, $day, $today);
        }
        return ['date' => null, 'problem' => self::UNREADABLE];
    }

    /** @return array<string, int> */
    private static function months(): array
    {
        $out = [];
        foreach (['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december'] as $i => $month) {
            $out[$month] = $i + 1;
            $out[substr($month, 0, 3)] = $i + 1;
        }
        $out['sept'] = 9;
        return $out;
    }

    /** @return array{date: ?string, problem: ?string} */
    private static function fromSerial(float $serial, string $today): array
    {
        if ($serial < self::SERIAL_MIN || $serial > self::SERIAL_MAX) {
            return ['date' => null, 'problem' => self::UNREADABLE];
        }
        $date = (new \DateTimeImmutable(self::SERIAL_EPOCH, new \DateTimeZone('UTC')))->modify('+' . (int) floor($serial) . ' days');
        return self::plausible($date->format('Y-m-d'), $today);
    }

    /** @return array{date: ?string, problem: ?string} */
    private static function make(int $year, int $month, int $day, string $today): array
    {
        if (!checkdate($month, $day, $year)) {
            return ['date' => null, 'problem' => self::UNREADABLE];
        }
        return self::plausible(sprintf('%04d-%02d-%02d', $year, $month, $day), $today);
    }

    /** @return array{date: ?string, problem: ?string} */
    private static function plausible(string $date, string $today): array
    {
        $years = (int) substr($date, 0, 4) - (int) substr($today, 0, 4);
        if (abs($years) > self::YEARS_EITHER_WAY) {
            // Decades away is a typo, not a rota.
            return ['date' => null, 'problem' => self::IMPLAUSIBLE];
        }
        return ['date' => $date, 'problem' => null];
    }

    public static function expandYear(int $year): int
    {
        return $year >= 100 ? $year : ($year >= 70 ? 1900 + $year : 2000 + $year);
    }

    /**
     * Which year a bare "July 10" means: the coming one, unless it has only
     * just passed, or unless the sheet says which year it is about.
     *
     * @param array{defaultYear?: ?int, today?: string} $options
     */
    public static function guessYear(int $month, int $day, array $options = [], ?string $today = null): int
    {
        if (($options['defaultYear'] ?? null) !== null) {
            return (int) $options['defaultYear'];
        }
        $today ??= (string) ($options['today'] ?? gmdate('Y-m-d'));
        $thisYear = (int) substr($today, 0, 4);
        $candidate = sprintf('%04d-%02d-%02d', $thisYear, $month, $day);
        // A rota is about what is coming; a date a few weeks past is still
        // this year's, because that is a row somebody has just filled in.
        $daysPast = (strtotime($today . ' UTC') - strtotime($candidate . ' UTC')) / 86400;
        return $daysPast > 60 ? $thisYear + 1 : $thisYear;
    }

    /** "HH:mm", or null for a cell that isn't a time. */
    public static function parseSheetTime(mixed $cell): ?string
    {
        if (!is_string($cell)) {
            return null;
        }
        $text = strtolower(trim($cell));
        if ($text === '') {
            return null;
        }
        if (!preg_match('/^(\d{1,2})(?:[:.](\d{2}))?\s*(am|pm)?$/', $text, $m)) {
            return null;
        }
        $hour = (int) $m[1];
        $minute = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;
        $suffix = $m[3] ?? '';
        if ($suffix === 'pm' && $hour < 12) {
            $hour += 12;
        }
        if ($suffix === 'am' && $hour === 12) {
            $hour = 0;
        }
        if ($hour > 23 || $minute > 59) {
            return null;
        }
        return sprintf('%02d:%02d', $hour, $minute);
    }
}
