<?php

declare(strict_types=1);

namespace MarineTeam\Plugins\Schedules;

/**
 * What the calendar shows (the original's lib/schedules/logic.ts): which
 * events, in what order, for whom. Everything here is pure, because the
 * same questions are asked by the page, the API, the reminders job and the
 * copy kept on a phone.
 */
final class Logic
{
    public const CONFIRMED = 'CONFIRMED';
    public const CANCELLED = 'CANCELLED';
    /** How far ahead "what's coming up" looks. */
    public const HORIZON_DAYS = 90;

    /**
     * The wire calls it personId and displayName, because that is what the
     * offline shell reads; in here either spelling is answered to.
     *
     * @param array<string, mixed> $person
     */
    public static function personId(array $person): string
    {
        return (string) ($person['personId'] ?? $person['id'] ?? '');
    }

    /** @param array<string, mixed> $person */
    public static function personName(array $person): string
    {
        return (string) ($person['displayName'] ?? $person['name'] ?? '');
    }

    /** @param array<string, mixed> $event */
    public static function involvesPerson(array $event, string $personId): bool
    {
        foreach ((array) ($event['people'] ?? []) as $person) {
            // On the id, never the name: two people can share a spelling.
            if (self::personId($person) === $personId) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<array<string, mixed>> $events
     * @param array{personId?: ?string, scheduleIds?: list<string>, includeCancelled?: bool} $filter
     * @return list<array<string, mixed>>
     */
    public static function filterEvents(array $events, array $filter = []): array
    {
        $personId = $filter['personId'] ?? null;
        $scheduleIds = $filter['scheduleIds'] ?? [];
        $cancelled = (bool) ($filter['includeCancelled'] ?? false);
        $out = [];
        foreach ($events as $event) {
            if (!$cancelled && (string) ($event['status'] ?? self::CONFIRMED) === self::CANCELLED) {
                continue;
            }
            if ($personId !== null && !self::involvesPerson($event, $personId)) {
                continue;
            }
            // An empty list of schedules means all of them, not none.
            if ($scheduleIds !== [] && !in_array((string) ($event['scheduleId'] ?? ''), $scheduleIds, true)) {
                continue;
            }
            $out[] = $event;
        }
        return $out;
    }

    /**
     * By date, then by time, with all-day first; stable for identical days.
     *
     * @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    public static function sortEvents(array $events): array
    {
        $keyed = [];
        foreach ($events as $i => $event) {
            $keyed[] = [$i, $event];
        }
        usort($keyed, function (array $a, array $b) {
            $one = [(string) $a[1]['date'], (bool) ($a[1]['allDay'] ?? true) ? '' : (string) ($a[1]['startTime'] ?? ''), (string) ($a[1]['id'] ?? ''), $a[0]];
            $two = [(string) $b[1]['date'], (bool) ($b[1]['allDay'] ?? true) ? '' : (string) ($b[1]['startTime'] ?? ''), (string) ($b[1]['id'] ?? ''), $b[0]];
            return $one <=> $two;
        });
        return array_map(fn (array $pair) => $pair[1], $keyed);
    }

    /** Whether an event is on a day, a span counting every day it covers. */
    public static function coversDay(array $event, string $day): bool
    {
        $from = (string) $event['date'];
        $to = ($event['endDate'] ?? null) === null ? $from : (string) $event['endDate'];
        return $day >= $from && $day <= $to;
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    public static function eventsOnDay(array $events, string $day, ?string $personId = null): array
    {
        $out = [];
        foreach (self::sortEvents($events) as $event) {
            if (self::coversDay($event, $day) && ($personId === null || self::involvesPerson($event, $personId))) {
                $out[] = $event;
            }
        }
        return $out;
    }

    /**
     * What is coming up. Today has its own section on the page, so it is
     * left out unless it is asked for; an event still running today counts
     * when it is.
     *
     * @param list<array<string, mixed>> $events
     * @param array{includeToday?: bool, horizonDays?: int, limit?: ?int, personId?: ?string} $options
     * @return list<array<string, mixed>>
     */
    public static function upcomingEvents(array $events, string $today, array $options = []): array
    {
        $includeToday = (bool) ($options['includeToday'] ?? false);
        $horizon = (new \DateTimeImmutable($today, new \DateTimeZone('UTC')))
            ->modify('+' . max(0, (int) ($options['horizonDays'] ?? self::HORIZON_DAYS)) . ' days')->format('Y-m-d');
        $personId = $options['personId'] ?? null;
        $out = [];
        foreach (self::sortEvents($events) as $event) {
            $ends = ($event['endDate'] ?? null) === null ? (string) $event['date'] : (string) $event['endDate'];
            $starts = (string) $event['date'];
            $onOrAfter = $includeToday ? $ends >= $today : $starts > $today;
            if (!$onOrAfter || $starts > $horizon) {
                continue;
            }
            if ($personId !== null && !self::involvesPerson($event, $personId)) {
                continue;
            }
            $out[] = $event;
            if (($options['limit'] ?? null) !== null && count($out) >= (int) $options['limit']) {
                break;
            }
        }
        return $out;
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>> most recent first
     */
    public static function pastEvents(array $events, string $today, ?string $personId = null, ?int $limit = null): array
    {
        $out = [];
        foreach (array_reverse(self::sortEvents($events)) as $event) {
            $ends = ($event['endDate'] ?? null) === null ? (string) $event['date'] : (string) $event['endDate'];
            if ($ends >= $today) {
                // Today is not past.
                continue;
            }
            if ($personId !== null && !self::involvesPerson($event, $personId)) {
                continue;
            }
            $out[] = $event;
            if ($limit !== null && count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array<string, mixed>|null
     */
    public static function nextEventForPerson(array $events, string $personId, string $today): ?array
    {
        return self::upcomingEvents($events, $today, ['includeToday' => true, 'personId' => $personId, 'limit' => 1])[0] ?? null;
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return list<array{day: string, events: list<array<string, mixed>>}>
     */
    public static function groupByDay(array $events): array
    {
        $days = [];
        foreach (self::sortEvents($events) as $event) {
            $days[(string) $event['date']][] = $event;
        }
        ksort($days);
        $out = [];
        foreach ($days as $day => $onThatDay) {
            $out[] = ['day' => (string) $day, 'events' => $onThatDay];
        }
        return $out;
    }

    /**
     * Every day an event touches, a span included.
     *
     * @param list<array<string, mixed>> $events
     * @return list<string>
     */
    public static function daysWithEvents(array $events): array
    {
        $days = [];
        foreach ($events as $event) {
            $day = new \DateTimeImmutable((string) $event['date'], new \DateTimeZone('UTC'));
            $last = new \DateTimeImmutable(($event['endDate'] ?? null) === null ? (string) $event['date'] : (string) $event['endDate'], new \DateTimeZone('UTC'));
            for ($at = $day; $at <= $last; $at = $at->modify('+1 day')) {
                $days[$at->format('Y-m-d')] = true;
            }
        }
        $out = array_keys($days);
        sort($out);
        return $out;
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return list<array{id: string, name: string}>
     */
    public static function peopleInEvents(array $events): array
    {
        $people = [];
        foreach ($events as $event) {
            foreach ((array) ($event['people'] ?? []) as $person) {
                $id = self::personId((array) $person);
                if ($id !== '') {
                    $people[$id] = ['id' => $id, 'name' => self::personName((array) $person)];
                }
            }
        }
        $out = array_values($people);
        usort($out, fn (array $a, array $b) => [mb_strtolower($a['name']), $a['id']] <=> [mb_strtolower($b['name']), $b['id']]);
        return $out;
    }

    /**
     * The people on one event, in the order they were listed — which is the
     * order the sheet had them in, and often the order they are doing
     * things in.
     *
     * @param array<string, mixed> $event
     */
    public static function describeParticipants(array $event): string
    {
        $names = [];
        foreach ((array) ($event['people'] ?? []) as $person) {
            $name = trim(self::personName((array) $person));
            if ($name !== '') {
                $names[] = ($person['role'] ?? null) !== null && trim((string) $person['role']) !== ''
                    ? $name . ' (' . trim((string) $person['role']) . ')'
                    : $name;
            }
        }
        if ($names === []) {
            return '';
        }
        if (count($names) === 1) {
            return $names[0];
        }
        $last = array_pop($names);
        return implode(', ', $names) . ' and ' . $last;
    }

    /**
     * @param list<array<string, mixed>> $schedules
     * @return list<array<string, mixed>>
     */
    public static function visibleSchedules(array $schedules): array
    {
        $out = array_values(array_filter($schedules, fn (array $s) => (bool) ($s['enabled'] ?? true) && ($s['deletedAt'] ?? null) === null));
        usort($out, fn (array $a, array $b) => [(int) ($a['displayOrder'] ?? 0), mb_strtolower((string) ($a['name'] ?? ''))] <=> [(int) ($b['displayOrder'] ?? 0), mb_strtolower((string) ($b['name'] ?? ''))]);
        return $out;
    }

    /**
     * The name somebody chose on this device: by id, and by name when the
     * id has changed under them (a merge, a re-import).
     *
     * @param list<array{id: string, name: string}> $people
     * @return array{id: string, name: string}|null
     */
    public static function resolveSelectedPerson(array $people, ?string $id, ?string $name = null): ?array
    {
        foreach ($people as $person) {
            if ($id !== null && self::personId($person) === $id) {
                return $person;
            }
        }
        if ($name !== null && trim($name) !== '') {
            $key = Names::normalizeName($name);
            foreach ($people as $person) {
                if (Names::normalizeName(self::personName($person)) === $key) {
                    return $person;
                }
            }
        }
        return null;
    }
}
