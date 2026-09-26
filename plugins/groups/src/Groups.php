<?php

declare(strict_types=1);

namespace MarineTeam\Plugins\Groups;

/**
 * Small groups (the original's lib/groups.ts). The thing this file is most
 * careful about is the address: `area` and `address` are separate, and
 * `presentGroup` is the only thing that decides whether the second travels
 * — absent rather than null when withheld, so a page that forgets to check
 * renders nothing instead of a home.
 *
 * "Has asked to join" deliberately doesn't qualify. If it did, anyone with
 * an account could learn where a leader lives by pressing a button; the
 * leader's answer is what turns a stranger into somebody who is coming.
 */
final class Groups
{
    public const LEADER = 'LEADER';
    public const MEMBER = 'MEMBER';

    public const REQUESTED = 'REQUESTED';
    public const ACTIVE = 'ACTIVE';
    public const WAITLIST = 'WAITLIST';
    public const DECLINED = 'DECLINED';
    public const STATUSES = [self::REQUESTED, self::ACTIVE, self::WAITLIST, self::DECLINED];

    /** How somebody stands to a group: the four ways, and "nothing at all". */
    public const NONE = 'NONE';

    /** What the join button says. */
    public const JOIN_SIGN_IN = 'SIGN_IN';
    public const JOIN_OPEN = 'OPEN';
    public const JOIN_ASKED = 'ASKED';
    public const JOIN_WAITING = 'WAITING';
    public const JOIN_IN = 'IN';
    public const JOIN_DECLINED = 'DECLINED';
    public const JOIN_FULL = 'FULL';
    public const JOIN_CLOSED = 'CLOSED';

    /**
     * How this person stands to the group: ACTIVE, REQUESTED, WAITLIST,
     * DECLINED, or NONE.
     *
     * @param list<array<string, mixed>> $members every membership row
     */
    public static function standingIn(array $members, ?string $userId): string
    {
        if ($userId === null) {
            return self::NONE;
        }
        foreach ($members as $member) {
            if ((string) $member['user_id'] === $userId) {
                return (string) $member['status'];
            }
        }
        return self::NONE;
    }

    /**
     * Whether this person may be given the address: people actually in the
     * group, and whoever keeps the group list.
     *
     * @param list<array<string, mixed>> $members
     */
    public static function canSeeAddress(array $members, ?string $userId, bool $keepsTheList = false): bool
    {
        return $keepsTheList || self::standingIn($members, $userId) === self::ACTIVE;
    }

    /** Whether this person leads this group, or keeps the group list. */
    public static function canLead(array $members, ?string $userId, bool $keepsTheList = false): bool
    {
        if ($keepsTheList) {
            return true;
        }
        foreach ($members as $member) {
            if ($userId !== null && (string) $member['user_id'] === $userId && (string) $member['status'] === self::ACTIVE && (string) $member['role'] === self::LEADER) {
                return true;
            }
        }
        return false;
    }

    /**
     * The people actually in it: requests, waiting names and refusals are
     * not members.
     *
     * @param list<array<string, mixed>> $members
     * @return list<array<string, mixed>>
     */
    public static function activeMembers(array $members): array
    {
        return array_values(array_filter($members, fn (array $m) => (string) $m['status'] === self::ACTIVE));
    }

    /**
     * The group as this reader is given it. The address is left out
     * entirely rather than sent as null, and the district — which is what
     * somebody is choosing between — is always there.
     *
     * @param array<string, mixed> $group
     * @param list<array<string, mixed>> $members rows with `name` joined on
     * @return array<string, mixed>
     */
    public static function presentGroup(array $group, array $members, ?string $userId, bool $keepsTheList = false): array
    {
        $active = self::activeMembers($members);
        $standing = self::standingIn($members, $userId);
        $out = [
            'id' => (string) $group['id'],
            'slug' => (string) $group['slug'],
            'name' => (string) $group['name'],
            'description' => $group['description'] ?? null,
            'meetsWhen' => $group['meets_when'] ?? null,
            'area' => $group['area'] ?? null,
            'openToJoin' => (bool) $group['open_to_join'],
            'capacity' => ($group['capacity'] ?? null) === null ? null : (int) $group['capacity'],
            'waitlist' => (bool) ($group['waitlist'] ?? true),
            // Only people actually in it are counted.
            'memberCount' => count($active),
            'leaders' => array_values(array_map(
                fn (array $m) => (string) ($m['name'] ?? ''),
                array_filter($active, fn (array $m) => (string) $m['role'] === self::LEADER),
            )),
            'standing' => $standing,
            'canLead' => self::canLead($members, $userId, $keepsTheList),
            // A group with nobody to answer a request looks perfectly fine
            // on the list, so it is flagged.
            'needsLeader' => array_filter($active, fn (array $m) => (string) $m['role'] === self::LEADER) === [],
        ];
        if (self::canSeeAddress($members, $userId, $keepsTheList) && ($group['address'] ?? null) !== null && (string) $group['address'] !== '') {
            $out['address'] = (string) $group['address'];
        }
        return $out;
    }

    /**
     * What the join button says, and whether it does anything.
     *
     * @param array<string, mixed> $group
     * @param list<array<string, mixed>> $members
     */
    public static function joinState(array $group, array $members, ?string $userId): string
    {
        if ($userId === null) {
            // A visitor is asked to sign in rather than told they can join.
            return self::JOIN_SIGN_IN;
        }
        $standing = self::standingIn($members, $userId);
        if ($standing === self::ACTIVE) {
            return self::JOIN_IN;
        }
        if ($standing === self::REQUESTED) {
            return self::JOIN_ASKED;
        }
        if ($standing === self::WAITLIST) {
            return self::JOIN_WAITING;
        }
        if ($standing === self::DECLINED) {
            return self::JOIN_DECLINED;
        }
        if (!(bool) $group['open_to_join']) {
            // Closed doors, whatever the room holds.
            return self::JOIN_CLOSED;
        }
        if (self::placesLeft($group, $members) === 0) {
            return (bool) ($group['waitlist'] ?? true) ? self::JOIN_WAITING : self::JOIN_FULL;
        }
        return self::JOIN_OPEN;
    }

    /**
     * Places left, counting a request already in front of the leader as
     * taken: a place offered to somebody is not a place.
     *
     * @param array<string, mixed> $group
     * @param list<array<string, mixed>> $members
     */
    public static function placesLeft(array $group, array $members): ?int
    {
        $capacity = ($group['capacity'] ?? null) === null ? null : (int) $group['capacity'];
        if ($capacity === null) {
            return null;
        }
        $taken = count(array_filter($members, fn (array $m) => in_array((string) $m['status'], [self::ACTIVE, self::REQUESTED], true)));
        return max(0, $capacity - $taken);
    }

    /**
     * Who on the waiting list may be put in front of the leader now, oldest
     * ask first. The leader still decides — that decision is what the
     * address travels with.
     *
     * @param array<string, mixed> $group
     * @param list<array<string, mixed>> $members
     * @return list<string> membership ids, in order
     */
    public static function promotable(array $group, array $members): array
    {
        $waiting = array_values(array_filter($members, fn (array $m) => (string) $m['status'] === self::WAITLIST));
        usort($waiting, fn (array $a, array $b) => [(string) $a['created_at'], (string) $a['id']] <=> [(string) $b['created_at'], (string) $b['id']]);
        $left = self::placesLeft($group, $members);
        if ($left === null) {
            return array_map(fn (array $m) => (string) $m['id'], $waiting);
        }
        return array_map(fn (array $m) => (string) $m['id'], array_slice($waiting, 0, $left));
    }

    /** Where somebody on the list stands: 1 is next. */
    public static function waitingPosition(array $members, string $userId): ?int
    {
        $waiting = array_values(array_filter($members, fn (array $m) => (string) $m['status'] === self::WAITLIST));
        usort($waiting, fn (array $a, array $b) => [(string) $a['created_at'], (string) $a['id']] <=> [(string) $b['created_at'], (string) $b['id']]);
        foreach ($waiting as $i => $member) {
            if ((string) $member['user_id'] === $userId) {
                return $i + 1;
            }
        }
        return null;
    }
}
