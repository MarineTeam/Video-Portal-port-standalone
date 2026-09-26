<?php

declare(strict_types=1);

namespace MarineTeam\Plugins\Events;

/**
 * What an event's sign-up comes to (the original's lib/events.ts): how many
 * places are taken, how many are left, whether sign-up is offered at all,
 * and who moves up when one frees. Nothing here touches the database: the
 * routes hold the row lock and ask these questions inside it, so four
 * people pressing the button in the same second get one yes and three
 * places on the list.
 */
final class Events
{
    public const GOING = 'GOING';
    public const WAITLIST = 'WAITLIST';
    public const CANCELLED = 'CANCELLED';
    public const STATUSES = [self::GOING, self::WAITLIST, self::CANCELLED];

    /** Sign-up is not offered, and the page says nothing about it. */
    public const NONE = 'NONE';
    /** The event has been and gone. */
    public const OVER = 'OVER';
    public const NOT_OPEN = 'NOT_OPEN';
    public const CLOSED = 'CLOSED';
    public const OPEN = 'OPEN';
    public const FULL = 'FULL';
    public const LIST_OPEN = 'WAITLIST';

    /**
     * Places taken: a guest counts as one, because a guest sits somewhere.
     * The waiting list and the cancelled are not in a hall.
     *
     * @param list<array<string, mixed>> $registrations
     */
    public static function seatsTaken(array $registrations): int
    {
        $taken = 0;
        foreach ($registrations as $one) {
            if ((string) ($one['status'] ?? self::GOING) === self::GOING) {
                $taken += 1 + max(0, (int) ($one['guests'] ?? 0));
            }
        }
        return $taken;
    }

    /** Null for an event with no limit; never negative when the limit was lowered under what is booked. */
    public static function placesLeft(?int $capacity, int $taken): ?int
    {
        return $capacity === null ? null : max(0, $capacity - $taken);
    }

    /**
     * @param array<string, mixed> $event
     */
    public static function registrationState(array $event, int $taken, \DateTimeImmutable $now): string
    {
        if (!(bool) ($event['registration'] ?? false)) {
            return self::NONE;
        }
        // An event that has been and gone leads with that, not with sign-up
        // having closed: "it finished" is the answer to the question asked.
        if (self::finishedAt($event) < $now) {
            return self::OVER;
        }
        $opens = self::at($event['opens_at'] ?? null);
        if ($opens !== null && $now < $opens) {
            return self::NOT_OPEN;
        }
        $closes = self::at($event['closes_at'] ?? null);
        if ($closes !== null && $now > $closes) {
            return self::CLOSED;
        }
        $left = self::placesLeft(self::capacity($event), $taken);
        if ($left !== null && $left < 1) {
            return (bool) ($event['waitlist'] ?? true) ? self::LIST_OPEN : self::FULL;
        }
        return self::OPEN;
    }

    /** What to say about the places left; nothing for a number an unlimited event doesn't have. */
    public static function registrationMessage(string $state, ?int $placesLeft): string
    {
        if ($placesLeft === null || !in_array($state, [self::OPEN, self::LIST_OPEN, self::FULL], true)) {
            return '';
        }
        return $placesLeft === 1 ? '1 place left' : $placesLeft . ' places left';
    }

    /**
     * Who moves up when a place frees, in the order they joined the list.
     * It stops at the first party that doesn't fit rather than skipping to
     * a smaller one behind them: passing over a family of four to seat the
     * couple behind them is exactly what people notice and rightly resent.
     *
     * @param list<array<string, mixed>> $waiting oldest first
     * @return list<string> registration ids, in order
     */
    public static function promotable(array $waiting, ?int $capacity, int $taken): array
    {
        if ($capacity === null) {
            return array_map(fn (array $one) => (string) $one['id'], $waiting);
        }
        $out = [];
        $left = max(0, $capacity - $taken);
        foreach ($waiting as $one) {
            $wants = 1 + max(0, (int) ($one['guests'] ?? 0));
            if ($wants > $left) {
                break;
            }
            $left -= $wants;
            $out[] = (string) $one['id'];
        }
        return $out;
    }

    /**
     * When an event is, in words: a day and a time, one day for an all-day
     * event rather than a midnight nobody meant, and two dates run together
     * for something spanning days.
     *
     * @param array<string, mixed> $event
     */
    public static function eventWhen(array $event, string $zone = 'UTC'): string
    {
        $tz = Recurrence::isKnownTimeZone($zone) ? new \DateTimeZone($zone) : new \DateTimeZone('UTC');
        $starts = (self::at($event['starts_at']) ?? new \DateTimeImmutable('@0'))->setTimezone($tz);
        $ends = self::at($event['ends_at'] ?? null)?->setTimezone($tz);
        $allDay = (bool) ($event['all_day'] ?? false);
        $day = fn (\DateTimeImmutable $d) => $d->format('l j F Y');
        if ($ends !== null && $ends->format('Y-m-d') !== $starts->format('Y-m-d')) {
            return $allDay
                ? $day($starts) . ' – ' . $day($ends)
                : $day($starts) . ', ' . $starts->format('H:i') . ' – ' . $day($ends) . ', ' . $ends->format('H:i');
        }
        if ($allDay) {
            return $day($starts);
        }
        return $day($starts) . ', ' . $starts->format('H:i') . ($ends !== null ? '–' . $ends->format('H:i') : '');
    }

    /** @param array<string, mixed> $event */
    public static function capacity(array $event): ?int
    {
        return ($event['capacity'] ?? null) === null ? null : (int) $event['capacity'];
    }

    /**
     * When an event is over. An event with no stated finish is over at the
     * end of its own day, so it isn't over the minute it starts.
     *
     * @param array<string, mixed> $event
     */
    public static function finishedAt(array $event): \DateTimeImmutable
    {
        $ends = self::at($event['ends_at'] ?? null);
        if ($ends !== null) {
            return $ends;
        }
        $starts = self::at($event['starts_at']) ?? new \DateTimeImmutable('@0');
        return $starts->setTime(23, 59, 59);
    }

    public static function at(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }
        if ($value === null || $value === '') {
            return null;
        }
        return new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC'));
    }
}
