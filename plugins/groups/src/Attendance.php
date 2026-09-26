<?php

declare(strict_types=1);

namespace MarineTeam\Plugins\Groups;

/**
 * The roll a leader writes up on the night (the original's
 * lib/attendance.ts). The only reason to keep one is knowing who to ring,
 * which is why apologies is a real answer rather than a shade of absent: a
 * group that can't tell "let us know" from "vanished" rings the wrong
 * person.
 *
 * A member is handed a list of at most one row — their own — rather than a
 * flag or a count, so there is nothing there to total by accident.
 */
final class Attendance
{
    public const PRESENT = 'PRESENT';
    public const APOLOGIES = 'APOLOGIES';
    public const ABSENT = 'ABSENT';
    public const STATUSES = [self::PRESENT, self::APOLOGIES, self::ABSENT];

    /** Meetings looked back over when deciding somebody has gone quiet. */
    public const RUN = 3;
    /** Meetings the rate is taken over. */
    public const WINDOW = 8;

    /** @param list<array<string, mixed>> $members */
    public static function canKeepRoll(array $members, ?string $userId, bool $keepsTheList = false): bool
    {
        return Groups::canLead($members, $userId, $keepsTheList);
    }

    /**
     * The roll as this reader is given it: everything to a leader, their own
     * row and nothing else to a member, nothing at all to anybody outside.
     *
     * @param list<array<string, mixed>> $rows attendance rows for one meeting
     * @param list<array<string, mixed>> $members
     * @return list<array<string, mixed>>
     */
    public static function visibleAttendance(array $rows, array $members, ?string $userId, bool $keepsTheList = false): array
    {
        if (self::canKeepRoll($members, $userId, $keepsTheList)) {
            return array_values($rows);
        }
        if ($userId === null || Groups::standingIn($members, $userId) !== Groups::ACTIVE) {
            return [];
        }
        // At most one row: their own. Never a count, which is a size.
        return array_values(array_filter($rows, fn (array $r) => (string) $r['user_id'] === $userId));
    }

    /**
     * What one night came to.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{present: int, apologies: int, absent: int, visitors: int, inTheRoom: int}
     */
    public static function summariseRoll(array $rows, int $visitorCount = 0): array
    {
        $count = fn (string $status) => count(array_filter($rows, fn (array $r) => (string) $r['status'] === $status));
        $present = $count(self::PRESENT);
        // A negative visitor count is refused rather than subtracted from the room.
        $visitors = max(0, $visitorCount);
        return [
            'present' => $present,
            'apologies' => $count(self::APOLOGIES),
            'absent' => $count(self::ABSENT),
            'visitors' => $visitors,
            'inTheRoom' => $present + $visitors,
        ];
    }

    /**
     * How often somebody has come, over the last few meetings that happened.
     * A member with no row at all was missed, and apologies are not coming.
     *
     * @param list<array{id: string, date: string, cancelled: bool}> $meetings newest first
     * @param array<string, array<string, string>> $byMeeting meeting id => user id => status
     */
    public static function attendanceRate(array $meetings, array $byMeeting, string $userId, int $window = self::WINDOW): float
    {
        $held = array_values(array_filter($meetings, fn (array $m) => !$m['cancelled']));
        $held = array_slice($held, 0, max(1, $window));
        if ($held === []) {
            return 0.0;
        }
        $came = 0;
        foreach ($held as $meeting) {
            if (($byMeeting[$meeting['id']][$userId] ?? null) === self::PRESENT) {
                $came++;
            }
        }
        return $came / count($held);
    }

    /**
     * People nobody has marked present for three meetings running who
     * didn't send apologies for the last one — the prompt to pick up the
     * phone, which is the only reason to keep a roll at all.
     *
     * @param list<array{id: string, date: string, cancelled: bool}> $meetings newest first
     * @param array<string, array<string, string>> $byMeeting meeting id => user id => status
     * @param list<string> $memberIds people currently in the group
     * @return list<string>
     */
    public static function quietlyMissing(array $meetings, array $byMeeting, array $memberIds): array
    {
        // Cancelled weeks are skipped: they count against nobody.
        $held = array_values(array_filter($meetings, fn (array $m) => !$m['cancelled']));
        if (count($held) < self::RUN) {
            // Nothing to judge on yet.
            return [];
        }
        $run = array_slice($held, 0, self::RUN);
        $out = [];
        foreach ($memberIds as $userId) {
            $lastStatus = $byMeeting[$run[0]['id']][$userId] ?? null;
            if ($lastStatus === self::APOLOGIES) {
                // They said something, which is the whole distinction.
                continue;
            }
            $missedEvery = true;
            foreach ($run as $meeting) {
                if (($byMeeting[$meeting['id']][$userId] ?? null) === self::PRESENT) {
                    $missedEvery = false;
                    break;
                }
            }
            if ($missedEvery) {
                $out[] = $userId;
            }
        }
        return $out;
    }

    /** A roll can't be written for an evening that hasn't happened. */
    public static function canRecordFor(string $date, string $today): bool
    {
        return $date <= $today;
    }
}
