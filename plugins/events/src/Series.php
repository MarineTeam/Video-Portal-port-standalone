<?php

declare(strict_types=1);

namespace MarineTeam\Plugins\Events;

/**
 * A repeating event (the original's lib/event-series.ts). A series is not
 * itself an event: every date it produces is an ordinary event row with
 * its own slug, capacity and sign-up list, because "is there a place for
 * me on the 14th" is a different question from "is there a place on the
 * 21st" and only a real row can answer both.
 *
 * Everything here is a plan the routes carry out, so generating is the
 * same work whether it comes from the daily job, a missed run or an admin
 * pressing save.
 */
final class Series
{
    /** The five repeats a church diary actually contains. */
    public const SHAPES = ['WEEKLY', 'DAILY', 'MONTHLY_DATE', 'MONTHLY_NTH', 'MONTHLY_LAST'];
    /** How far ahead the diary is kept filled in. */
    public const HORIZON_MONTHS = 6;

    /** The last day occurrences are laid down to. */
    public static function horizonEnd(\DateTimeImmutable $now): string
    {
        return $now->setTime(0, 0)->modify('+' . self::HORIZON_MONTHS . ' months')->format('Y-m-d');
    }

    /**
     * What each date comes to as a real event: its instants, and its own
     * sign-up window. The window moves with each date rather than copying
     * one pair of instants onto every one of them — that would close
     * December's sign-up in September.
     *
     * @param array<string, mixed> $series
     * @param list<string> $dates
     * @return list<array{date: string, startsAt: \DateTimeImmutable, endsAt: ?\DateTimeImmutable, opensAt: ?\DateTimeImmutable, closesAt: ?\DateTimeImmutable}>
     */
    public static function planOccurrences(array $series, array $dates): array
    {
        $zone = (string) ($series['time_zone'] ?? 'UTC');
        $allDay = (bool) ($series['all_day'] ?? false);
        $minutes = ($series['duration_minutes'] ?? null) === null ? null : (int) $series['duration_minutes'];
        $opens = ($series['opens_days_before'] ?? null) === null ? null : (int) $series['opens_days_before'];
        $closes = ($series['closes_days_before'] ?? null) === null ? null : (int) $series['closes_days_before'];
        $out = [];
        foreach ($dates as $date) {
            // An all-day occurrence starts at local midnight, whatever time
            // the series happens to have stored.
            $startsAt = Recurrence::zonedInstant($date, $allDay ? null : (string) ($series['start_time'] ?? '00:00'), $zone);
            $out[] = [
                'date' => $date,
                'startsAt' => $startsAt,
                'endsAt' => $minutes === null ? null : $startsAt->modify("+$minutes minutes"),
                'opensAt' => $opens === null ? null : Recurrence::zonedInstant(self::shift($date, -$opens), '00:00', $zone),
                // The end of that day: "closes 0 days before" is the end of
                // the event's own day, not midnight at the start of it.
                'closesAt' => $closes === null ? null : Recurrence::zonedInstant(self::shift($date, -$closes), '23:59', $zone)->modify('+59 seconds'),
            ];
        }
        return $out;
    }

    private static function shift(string $date, int $days): string
    {
        return (new \DateTimeImmutable($date . ' 00:00:00', new \DateTimeZone('UTC')))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    /**
     * The dates that are missing: what the rule wants, less what is already
     * there and less what an organiser took out. Generating is idempotent,
     * so the daily job, a missed run and an admin pressing save all produce
     * one diary.
     *
     * @param list<string> $wanted
     * @param list<string> $existing
     * @param list<string> $excluded
     * @return list<string>
     */
    public static function datesToCreate(array $wanted, array $existing, array $excluded): array
    {
        $skip = array_flip([...$existing, ...$excluded]);
        return array_values(array_filter($wanted, fn (string $date) => !isset($skip[$date])));
    }

    /**
     * Stopping a series never deletes somebody's place: future dates with
     * nobody down for them go, and anything past, or with a sign-up on it,
     * survives as an ordinary one-off event.
     *
     * @param list<array{id: string, date: string, registrations: int}> $events
     * @return array{delete: list<string>, keep: list<string>}
     */
    public static function stopSeriesPlan(array $events, string $today): array
    {
        $plan = ['delete' => [], 'keep' => []];
        foreach ($events as $event) {
            // Today is still to come: somebody may be on their way to it.
            $future = $event['date'] >= $today;
            $plan[$future && $event['registrations'] === 0 ? 'delete' : 'keep'][] = $event['id'];
        }
        return $plan;
    }

    /**
     * Everything wrong with a series, at once rather than one at a time:
     * a form that reports its faults one by one takes five saves to fix.
     *
     * @param array<string, mixed> $series
     * @return list<string>
     */
    public static function seriesProblems(array $series): array
    {
        $problems = [];
        try {
            Recurrence::occurrencesBetween((string) ($series['rule'] ?? ''), (string) ($series['start_date'] ?? '1970-01-01'), '1970-01-01', '1970-01-02');
        } catch (\InvalidArgumentException $e) {
            $problems[] = $e->getMessage();
        }
        $zone = (string) ($series['time_zone'] ?? 'UTC');
        if (!Recurrence::isKnownTimeZone($zone)) {
            $problems[] = "There is no time zone called $zone.";
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($series['start_date'] ?? ''))) {
            $problems[] = 'A series needs a first date.';
        }
        $allDay = (bool) ($series['all_day'] ?? false);
        $time = (string) ($series['start_time'] ?? '');
        if (!$allDay && !preg_match('/^\d{1,2}:\d{2}$/', $time)) {
            $problems[] = 'A series needs a time of day, unless it is all day.';
        }
        $opens = ($series['opens_days_before'] ?? null) === null ? null : (int) $series['opens_days_before'];
        $closes = ($series['closes_days_before'] ?? null) === null ? null : (int) $series['closes_days_before'];
        if ($opens !== null && $closes !== null && $closes > $opens) {
            $problems[] = 'Sign-up would shut before it opened.';
        }
        if (trim((string) ($series['title'] ?? '')) === '') {
            $problems[] = 'A series needs a title.';
        }
        return $problems;
    }

    /**
     * The series in words, which is what is shown back before it is saved:
     * FREQ=MONTHLY;BYDAY=-1SA is not something anybody should have to read
     * to check they picked the right one.
     *
     * @param array<string, mixed> $series
     */
    public static function describeSeries(array $series): string
    {
        $sentence = Recurrence::describeRule((string) ($series['rule'] ?? ''));
        if (!(bool) ($series['all_day'] ?? false) && ($series['start_time'] ?? '') !== '') {
            $sentence .= ' at ' . $series['start_time'];
        }
        return $sentence;
    }

    /**
     * The rule behind one of the five choices the form offers. Nobody types
     * an RRULE; this is what their answers come to.
     *
     * @param array{shape: string, days?: list<string>, interval?: int, count?: ?int, until?: ?string} $choices
     */
    public static function ruleFromChoices(array $choices, string $firstDate): string
    {
        $shape = strtoupper((string) ($choices['shape'] ?? 'WEEKLY'));
        $position = Recurrence::monthPositionOf($firstDate);
        $parts = ['interval' => max(1, (int) ($choices['interval'] ?? 1)), 'byday' => [], 'bymonthday' => [], 'bymonth' => [], 'count' => null, 'until' => null];
        switch ($shape) {
            case 'DAILY':
                $parts['freq'] = 'DAILY';
                break;
            case 'MONTHLY_DATE':
                $parts['freq'] = 'MONTHLY';
                $parts['bymonthday'] = [(int) (new \DateTimeImmutable($firstDate))->format('j')];
                break;
            case 'MONTHLY_NTH':
                // Read off the first date rather than asked for twice.
                $parts['freq'] = 'MONTHLY';
                $parts['byday'] = [['pos' => $position['week'], 'day' => $position['day']]];
                break;
            case 'MONTHLY_LAST':
                $parts['freq'] = 'MONTHLY';
                $parts['byday'] = [['pos' => -1, 'day' => $position['day']]];
                break;
            default:
                $parts['freq'] = 'WEEKLY';
                $days = array_values(array_filter(
                    array_map('strtoupper', (array) ($choices['days'] ?? [])),
                    fn (string $day) => in_array($day, Recurrence::DAYS, true),
                ));
                // Whatever order they were clicked in, and the day the first
                // date lands on when they clicked none.
                $days = $days === [] ? [$position['day']] : $days;
                usort($days, fn (string $a, string $b) => array_search($a, Recurrence::DAYS, true) <=> array_search($b, Recurrence::DAYS, true));
                $parts['byday'] = array_map(fn (string $day) => ['pos' => null, 'day' => $day], array_values(array_unique($days)));
        }
        if (($choices['count'] ?? null) !== null) {
            $parts['count'] = max(1, (int) $choices['count']);
        } elseif (($choices['until'] ?? null) !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $choices['until'])) {
            $parts['until'] = (string) $choices['until'];
        }
        $rule = Recurrence::formatRule($parts);
        // Only ever a rule the parser accepts.
        Recurrence::parseRule($rule);
        return $rule;
    }
}
