<?php

declare(strict_types=1);

namespace MarineTeam\Plugins\Schedules;

/**
 * Rota names are for members (the original's lib/schedules/visibility.ts).
 *
 * The schedules module came from an app built for people who never log in,
 * and published every volunteer's name beside the days they are at the
 * building; ported as-is that sat oddly beside a directory that needs
 * opt-in and sign-in before a name appears. The structure stays public —
 * which rotas, what days, what notes — and the people need a sign-in.
 *
 * It is enforced by handing a signed-out reader events with nobody on them,
 * rather than events with names to be hidden.
 */
final class Visibility
{
    public const NAMES_WITHHELD = 'Sign in to see who is on.';

    public static function canSeeNames(bool $signedIn): bool
    {
        return $signedIn;
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    public static function visibleEvent(array $event, bool $signedIn): array
    {
        if (self::canSeeNames($signedIn)) {
            return $event;
        }
        // A copy: what it was given is never changed.
        $stripped = $event;
        // Not an empty list of people with ids in it — no people at all.
        unset($stripped['people'], $stripped['personIds'], $stripped['participants']);
        $stripped['namesWithheld'] = true;
        return $stripped;
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    public static function visibleEvents(array $events, bool $signedIn): array
    {
        return array_map(fn (array $event) => self::visibleEvent($event, $signedIn), $events);
    }

    /**
     * @param list<array<string, mixed>> $people
     * @return list<array<string, mixed>>
     */
    public static function visiblePeople(array $people, bool $signedIn): array
    {
        // A copy for a member, and nothing at all for a stranger.
        return self::canSeeNames($signedIn) ? array_values($people) : [];
    }

    /**
     * The snapshot a device keeps. Saved while signed out it holds the
     * dates and no names, and a signed-out sync always fetches a full one,
     * so a copy saved on a shared laptop while somebody was signed in stops
     * carrying their names the next time it updates.
     *
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    public static function visibleSnapshot(array $snapshot, bool $signedIn): array
    {
        if (self::canSeeNames($signedIn)) {
            return $snapshot;
        }
        $out = $snapshot;
        $out['people'] = [];
        $out['events'] = self::visibleEvents(array_values((array) ($snapshot['events'] ?? [])), false);
        $out['namesWithheld'] = true;
        return $out;
    }
}
