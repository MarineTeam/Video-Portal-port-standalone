<?php

declare(strict_types=1);

namespace MarineTeam\Plugins\Events;

/**
 * RFC 5545 repeat rules, as much of them as a church diary contains (the
 * original's lib/recurrence.ts). Rules are real RRULEs — the syntax every
 * calendar exports — but nobody types one: the form offers five shapes and
 * this builds and reads them.
 *
 * Everything here works in calendar days. A repeating event is a wall
 * clock and a zone, never a list of instants: "Tuesdays at 19:30" is 19:30
 * in January and in July, and a series expanded as instants loses an hour
 * every March.
 */
final class Recurrence
{
    public const FREQS = ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'];
    public const DAYS = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];
    /** Parts this app refuses rather than ignores: ignoring one answers a question nobody asked. */
    private const REFUSED = ['BYSETPOS', 'BYWEEKNO', 'BYYEARDAY', 'BYHOUR', 'BYMINUTE', 'BYSECOND', 'BYEASTER'];
    /** Occurrences one answer may carry. */
    public const MAX_RESULTS = 500;
    /** Periods to look through before deciding a rule can never land again. */
    private const MAX_PERIODS = 20000;

    /**
     * @return array{freq: string, interval: int, byday: list<array{pos: ?int, day: string}>, bymonthday: list<int>, bymonth: list<int>, count: ?int, until: ?string}
     */
    public static function parseRule(string $rule): array
    {
        $text = trim($rule);
        if (stripos($text, 'RRULE:') === 0) {
            // The prefix an .ics line carries.
            $text = substr($text, 6);
        }
        if ($text === '') {
            throw new \InvalidArgumentException('A repeat rule is empty.');
        }
        $parts = [];
        foreach (explode(';', $text) as $piece) {
            if (trim($piece) === '') {
                continue;
            }
            if (!str_contains($piece, '=')) {
                throw new \InvalidArgumentException("This repeat rule can't be read: $rule");
            }
            [$name, $value] = explode('=', $piece, 2);
            $name = strtoupper(trim($name));
            if (isset($parts[$name])) {
                throw new \InvalidArgumentException("$name is given twice.");
            }
            $parts[$name] = trim($value);
        }
        foreach (self::REFUSED as $name) {
            if (isset($parts[$name])) {
                throw new \InvalidArgumentException("This app can't work out $name, so it won't pretend to.");
            }
        }
        if (isset($parts['WKST']) && strtoupper($parts['WKST']) !== 'MO') {
            throw new \InvalidArgumentException('Weeks start on Monday here.');
        }
        unset($parts['WKST']);
        $freq = strtoupper($parts['FREQ'] ?? '');
        if (!in_array($freq, self::FREQS, true)) {
            throw new \InvalidArgumentException('A repeat rule needs FREQ=DAILY, WEEKLY, MONTHLY or YEARLY.');
        }
        $interval = 1;
        if (isset($parts['INTERVAL'])) {
            if (!preg_match('/^\d+$/', $parts['INTERVAL']) || (int) $parts['INTERVAL'] < 1) {
                throw new \InvalidArgumentException('INTERVAL must be a whole number of 1 or more.');
            }
            $interval = (int) $parts['INTERVAL'];
        }
        $byday = [];
        foreach (self::list($parts['BYDAY'] ?? null) as $token) {
            if (!preg_match('/^([+-]?\d{1,2})?(MO|TU|WE|TH|FR|SA|SU)$/i', $token, $m)) {
                throw new \InvalidArgumentException("BYDAY can't be read: $token");
            }
            $pos = $m[1] === '' ? null : (int) $m[1];
            if ($pos !== null && ($pos === 0 || $pos > 5 || $pos < -5)) {
                throw new \InvalidArgumentException("There is no $token in a month.");
            }
            $byday[] = ['pos' => $pos, 'day' => strtoupper($m[2])];
        }
        $bymonthday = [];
        foreach (self::list($parts['BYMONTHDAY'] ?? null) as $token) {
            if (!preg_match('/^-?\d{1,2}$/', $token) || (int) $token === 0 || abs((int) $token) > 31) {
                throw new \InvalidArgumentException("BYMONTHDAY can't be read: $token");
            }
            $bymonthday[] = (int) $token;
        }
        $bymonth = [];
        foreach (self::list($parts['BYMONTH'] ?? null) as $token) {
            if (!preg_match('/^\d{1,2}$/', $token) || (int) $token < 1 || (int) $token > 12) {
                throw new \InvalidArgumentException("BYMONTH can't be read: $token");
            }
            $bymonth[] = (int) $token;
        }
        $count = null;
        if (isset($parts['COUNT'])) {
            if (!preg_match('/^\d+$/', $parts['COUNT']) || (int) $parts['COUNT'] < 1) {
                throw new \InvalidArgumentException('COUNT must be a whole number of 1 or more.');
            }
            $count = (int) $parts['COUNT'];
        }
        $until = null;
        if (isset($parts['UNTIL'])) {
            // A day here: the time of day an .ics carries is dropped.
            if (!preg_match('/^(\d{4})(\d{2})(\d{2})(T\d{6}Z?)?$/', $parts['UNTIL'], $m)) {
                throw new \InvalidArgumentException("UNTIL can't be read: {$parts['UNTIL']}");
            }
            $until = "$m[1]-$m[2]-$m[3]";
            if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                throw new \InvalidArgumentException("UNTIL is not a date: {$parts['UNTIL']}");
            }
        }
        if ($count !== null && $until !== null) {
            throw new \InvalidArgumentException('A rule ends at COUNT or at UNTIL, not both.');
        }
        unset($parts['FREQ'], $parts['INTERVAL'], $parts['BYDAY'], $parts['BYMONTHDAY'], $parts['BYMONTH'], $parts['COUNT'], $parts['UNTIL']);
        if ($parts !== []) {
            throw new \InvalidArgumentException('This app can\'t work out ' . implode(', ', array_keys($parts)) . ', so it won\'t pretend to.');
        }
        $numbered = array_filter($byday, fn (array $d) => $d['pos'] !== null) !== [];
        if ($numbered && $freq !== 'MONTHLY' && $freq !== 'YEARLY') {
            throw new \InvalidArgumentException('A numbered weekday only means something monthly or yearly.');
        }
        if ($bymonthday !== [] && !in_array($freq, ['MONTHLY', 'YEARLY'], true)) {
            throw new \InvalidArgumentException('BYMONTHDAY only means something monthly or yearly.');
        }
        if ($numbered && $bymonthday !== []) {
            throw new \InvalidArgumentException('A month day and a numbered weekday together don\'t mean what they look like.');
        }
        if ($bymonth !== [] && $freq !== 'YEARLY') {
            throw new \InvalidArgumentException('BYMONTH only means something yearly.');
        }
        return ['freq' => $freq, 'interval' => $interval, 'byday' => $byday, 'bymonthday' => $bymonthday, 'bymonth' => $bymonth, 'count' => $count, 'until' => $until];
    }

    /** @return list<string> */
    private static function list(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $value)), fn (string $s) => $s !== ''));
    }

    /** @param array<string, mixed>|string $rule */
    public static function formatRule(array|string $rule): string
    {
        $parts = is_string($rule) ? self::parseRule($rule) : $rule;
        $out = ['FREQ=' . $parts['freq']];
        if (($parts['interval'] ?? 1) > 1) {
            $out[] = 'INTERVAL=' . $parts['interval'];
        }
        if (($parts['bymonth'] ?? []) !== []) {
            $out[] = 'BYMONTH=' . implode(',', $parts['bymonth']);
        }
        if (($parts['byday'] ?? []) !== []) {
            $out[] = 'BYDAY=' . implode(',', array_map(fn (array $d) => ($d['pos'] ?? null) === null ? $d['day'] : $d['pos'] . $d['day'], $parts['byday']));
        }
        if (($parts['bymonthday'] ?? []) !== []) {
            $out[] = 'BYMONTHDAY=' . implode(',', $parts['bymonthday']);
        }
        if (($parts['count'] ?? null) !== null) {
            $out[] = 'COUNT=' . $parts['count'];
        }
        if (($parts['until'] ?? null) !== null) {
            $out[] = 'UNTIL=' . str_replace('-', '', (string) $parts['until']);
        }
        return implode(';', $out);
    }

    /**
     * The days this rule lands on inside the window, the start date always
     * among them.
     *
     * @param array<string, mixed>|string $rule
     * @return list<string> calendar days, in order
     */
    public static function occurrencesBetween(array|string $rule, string $startDate, string $from, string $to): array
    {
        $parts = is_string($rule) ? self::parseRule($rule) : $rule;
        if ($to < $from) {
            return [];
        }
        $stop = $parts['until'] !== null && $parts['until'] < $to ? $parts['until'] : $to;
        $out = [];
        $seen = 0;
        $take = function (string $day) use (&$out, &$seen, $parts, $startDate, $from, $stop): bool {
            if ($day < $startDate || ($parts['until'] !== null && $day > $parts['until'])) {
                return true;
            }
            // COUNT counts from the start, not from the window.
            $seen++;
            if ($parts['count'] !== null && $seen > $parts['count']) {
                return false;
            }
            if ($day >= $from && $day <= $stop) {
                $out[] = $day;
            }
            return count($out) < self::MAX_RESULTS && $day <= $stop;
        };
        if (!$take($startDate)) {
            return array_values(array_unique($out));
        }
        $empty = 0;
        for ($period = 0; $period < self::MAX_PERIODS; $period++) {
            $days = self::periodDays($parts, $startDate, $period);
            if ($days === []) {
                // A rule that can never land again is given up on, rather
                // than looked for until the end of time.
                if (++$empty > 400) {
                    break;
                }
                continue;
            }
            $empty = 0;
            $past = true;
            foreach ($days as $day) {
                if ($day === $startDate) {
                    continue;
                }
                if ($day <= $stop) {
                    $past = false;
                }
                if (!$take($day)) {
                    return self::tidy($out);
                }
            }
            if ($past && ($days[0] ?? '') > $stop) {
                break;
            }
        }
        return self::tidy($out);
    }

    /** @param list<string> $days @return list<string> */
    private static function tidy(array $days): array
    {
        $unique = array_values(array_unique($days));
        sort($unique);
        return $unique;
    }

    /**
     * The days one period of the rule lands on: period 0 is the one the
     * start date is in.
     *
     * @param array<string, mixed> $parts
     * @return list<string>
     */
    private static function periodDays(array $parts, string $startDate, int $period): array
    {
        $start = new \DateTimeImmutable($startDate . ' 00:00:00', new \DateTimeZone('UTC'));
        $step = $period * (int) $parts['interval'];
        $days = [];
        switch ($parts['freq']) {
            case 'DAILY':
                $day = $start->modify("+$step days");
                $names = array_column($parts['byday'], 'day');
                if ($names === [] || in_array(self::dayName($day), $names, true)) {
                    $days[] = $day->format('Y-m-d');
                }
                break;
            case 'WEEKLY':
                // Weeks are counted from the start's own week, which begins
                // on a Monday, so the rest of the starting week is kept.
                $monday = $start->modify('monday this week');
                $names = array_column($parts['byday'], 'day') ?: [self::dayName($start)];
                foreach ($names as $name) {
                    $offset = (int) array_search($name, self::DAYS, true);
                    $days[] = $monday->modify('+' . ($step * 7 + $offset) . ' days')->format('Y-m-d');
                }
                break;
            case 'MONTHLY':
                $month = $start->modify('first day of this month')->modify("+$step months");
                $days = self::monthDays($parts, $month, (int) $start->format('j'));
                break;
            case 'YEARLY':
                $year = (int) $start->format('Y') + $step;
                $months = $parts['bymonth'] !== [] ? $parts['bymonth'] : [(int) $start->format('n')];
                foreach ($months as $m) {
                    $month = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $m), new \DateTimeZone('UTC'));
                    array_push($days, ...self::monthDays($parts, $month, (int) $start->format('j')));
                }
                break;
        }
        sort($days);
        return $days;
    }

    /**
     * The days one month lands on, from the rule's month day or numbered
     * weekday, or the day of the month the series started on.
     *
     * @param array<string, mixed> $parts
     * @return list<string>
     */
    private static function monthDays(array $parts, \DateTimeImmutable $month, int $fallbackDay): array
    {
        $length = (int) $month->format('t');
        $days = [];
        if ($parts['byday'] !== []) {
            foreach ($parts['byday'] as $spec) {
                $found = self::weekdayOfMonth($month, $spec['day'], $spec['pos']);
                foreach ($found as $day) {
                    $days[] = $day;
                }
            }
            return $days;
        }
        foreach ($parts['bymonthday'] !== [] ? $parts['bymonthday'] : [$fallbackDay] as $number) {
            $day = $number > 0 ? $number : $length + 1 + $number;
            // A month too short for the day is skipped, rather than sliding
            // the meeting to the 28th.
            if ($day >= 1 && $day <= $length) {
                $days[] = $month->setDate((int) $month->format('Y'), (int) $month->format('n'), $day)->format('Y-m-d');
            }
        }
        return $days;
    }

    /**
     * @return list<string> the nth (or every) given weekday of this month
     */
    private static function weekdayOfMonth(\DateTimeImmutable $month, string $day, ?int $pos): array
    {
        $offset = (int) array_search($day, self::DAYS, true);
        $first = $month->modify('first day of this month');
        $shift = ($offset - ((int) $first->format('N') - 1) + 7) % 7;
        $all = [];
        for ($date = $first->modify("+$shift days"); $date->format('n') === $month->format('n'); $date = $date->modify('+7 days')) {
            $all[] = $date->format('Y-m-d');
        }
        if ($pos === null) {
            return $all;
        }
        $index = $pos > 0 ? $pos - 1 : count($all) + $pos;
        return isset($all[$index]) ? [$all[$index]] : [];
    }

    private static function dayName(\DateTimeInterface $date): string
    {
        return self::DAYS[(int) $date->format('N') - 1];
    }

    /** Which weekday of the month a date is, counted forwards and backwards. */
    public static function monthPositionOf(string $date): array
    {
        $at = new \DateTimeImmutable($date . ' 00:00:00', new \DateTimeZone('UTC'));
        $day = (int) $at->format('j');
        $length = (int) $at->format('t');
        return [
            'day' => self::dayName($at),
            'week' => (int) floor(($day - 1) / 7) + 1,
            'fromEnd' => -(int) floor(($length - $day) / 7) - 1,
        ];
    }

    /** A rule in words somebody can check before saving it. */
    public static function describeRule(array|string $rule): string
    {
        try {
            $parts = is_string($rule) ? self::parseRule($rule) : $rule;
        } catch (\InvalidArgumentException) {
            // Something rather than an exception: this goes on a screen.
            return is_string($rule) ? $rule : 'A repeat this app can\'t describe';
        }
        $every = match ($parts['interval']) {
            1 => match ($parts['freq']) {
                'DAILY' => 'Every day',
                'WEEKLY' => 'Every week',
                'MONTHLY' => 'Every month',
                default => 'Every year',
            },
            2 => match ($parts['freq']) {
                'DAILY' => 'Every other day',
                'WEEKLY' => 'Every other week',
                'MONTHLY' => 'Every other month',
                default => 'Every other year',
            },
            default => 'Every ' . $parts['interval'] . ' ' . match ($parts['freq']) {
                'DAILY' => 'days',
                'WEEKLY' => 'weeks',
                'MONTHLY' => 'months',
                default => 'years',
            },
        };
        $names = ['MO' => 'Monday', 'TU' => 'Tuesday', 'WE' => 'Wednesday', 'TH' => 'Thursday', 'FR' => 'Friday', 'SA' => 'Saturday', 'SU' => 'Sunday'];
        $on = [];
        foreach ($parts['byday'] as $spec) {
            $name = $names[$spec['day']];
            $on[] = match (true) {
                $spec['pos'] === null => $name,
                $spec['pos'] === -1 => "last $name",
                $spec['pos'] < 0 => self::ordinal(-$spec['pos']) . " $name from the end",
                default => self::ordinal($spec['pos']) . ' ' . $name,
            };
        }
        foreach ($parts['bymonthday'] as $number) {
            $on[] = $number === -1 ? 'last day' : ($number < 0 ? self::ordinal(-$number) . ' day from the end' : 'the ' . self::ordinal($number));
        }
        $sentence = $every . ($on === [] ? '' : ' on ' . self::join($on));
        if ($parts['bymonth'] !== []) {
            $sentence .= ' in ' . self::join(array_map(fn (int $m) => date('F', mktime(0, 0, 0, $m, 1, 2001)), $parts['bymonth']));
        }
        if ($parts['count'] !== null) {
            $sentence .= ', ' . $parts['count'] . ' ' . ($parts['count'] === 1 ? 'time' : 'times');
        }
        if ($parts['until'] !== null) {
            $sentence .= ', until ' . (new \DateTimeImmutable($parts['until']))->format('j F Y');
        }
        return $sentence;
    }

    public static function ordinal(int $n): string
    {
        $suffix = match (true) {
            $n % 100 >= 11 && $n % 100 <= 13 => 'th',
            $n % 10 === 1 => 'st',
            $n % 10 === 2 => 'nd',
            $n % 10 === 3 => 'rd',
            default => 'th',
        };
        return $n . $suffix;
    }

    /** @param list<string> $items */
    private static function join(array $items): string
    {
        if (count($items) < 2) {
            return $items[0] ?? '';
        }
        $last = array_pop($items);
        return implode(', ', $items) . ' and ' . $last;
    }

    public static function isKnownTimeZone(string $zone): bool
    {
        return in_array($zone, \DateTimeZone::listIdentifiers(), true);
    }

    /**
     * A wall clock in a zone as the instant it happens at. The hour that
     * never happens on the morning the clocks go forward resolves forward,
     * to when people actually arrive; the hour that happens twice in
     * October resolves to the first of them.
     */
    public static function zonedInstant(string $date, ?string $time, string $zone): \DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \InvalidArgumentException("This is not a date: $date");
        }
        $clock = $time === null || $time === '' ? '00:00' : $time;
        if (!preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $clock, $m) || (int) $m[1] > 23 || (int) $m[2] > 59) {
            throw new \InvalidArgumentException("This is not a time of day: $clock");
        }
        $wall = sprintf('%s %02d:%02d', $date, (int) $m[1], (int) $m[2]);
        // A zone this host has never heard of is UTC rather than a crash.
        $tz = self::isKnownTimeZone($zone) ? new \DateTimeZone($zone) : new \DateTimeZone('UTC');
        $utc = new \DateTimeZone('UTC');
        $naive = new \DateTimeImmutable($wall, $utc);
        $candidates = [];
        foreach ([-7200, 0, 7200] as $probe) {
            $offset = $tz->getOffset($naive->modify($probe . ' seconds'));
            $instant = new \DateTimeImmutable('@' . ($naive->getTimestamp() - $offset));
            if ($instant->setTimezone($tz)->format('Y-m-d H:i') === $wall) {
                $candidates[$instant->getTimestamp()] = $instant;
            }
        }
        if ($candidates !== []) {
            // The earliest: the first of an hour that happens twice.
            ksort($candidates);
            return (new \DateTimeImmutable('@' . array_key_first($candidates)))->setTimezone($utc);
        }
        // An hour that never happened: PHP's own reading moves it forward.
        return (new \DateTimeImmutable($wall, $tz))->setTimezone($utc);
    }

    /** The local day an instant falls on, which need not be the UTC one. */
    public static function dayInZone(\DateTimeInterface $instant, string $zone): string
    {
        $tz = self::isKnownTimeZone($zone) ? new \DateTimeZone($zone) : new \DateTimeZone('UTC');
        return (new \DateTimeImmutable('@' . $instant->getTimestamp()))->setTimezone($tz)->format('Y-m-d');
    }
}
