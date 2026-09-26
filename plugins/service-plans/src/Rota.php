<?php

declare(strict_types=1);

namespace MarineTeam\Plugins\ServicePlans;

/**
 * The rota beside the running order (the original's lib/rota.ts):
 * ServiceTeam and its members are the pick-list, an assignment is one ask
 * with its answer, and a blockout is when somebody is away. The job is
 * free text on the assignment rather than a positions table — every church
 * names those differently — and it is empty rather than null so the unique
 * index over (plan, person, job) actually constrains anything.
 */
final class Rota
{
    public const INVITED = 'INVITED';
    public const ACCEPTED = 'ACCEPTED';
    public const DECLINED = 'DECLINED';
    public const STATUSES = [self::INVITED, self::ACCEPTED, self::DECLINED];

    /**
     * Whether somebody is away on the day of a service. Both ends count,
     * because that is how people say it: away "the 3rd to the 5th" means
     * all three.
     *
     * @param list<array<string, mixed>> $blockouts
     */
    public static function isBlockedOut(array $blockouts, ?string $serviceDate): bool
    {
        if ($serviceDate === null || $serviceDate === '') {
            // Nothing to say about a service with no date.
            return false;
        }
        $day = substr($serviceDate, 0, 10);
        foreach ($blockouts as $blockout) {
            $from = substr((string) $blockout['start_date'], 0, 10);
            $to = substr((string) $blockout['end_date'], 0, 10);
            if ($day >= $from && $day <= $to) {
                return true;
            }
        }
        return false;
    }

    /**
     * What to call the job: what was written down, else the team, so a row
     * is never nameless.
     *
     * @param array<string, mixed> $assignment
     * @param array<string, mixed>|null $team
     */
    public static function assignmentRole(array $assignment, ?array $team): string
    {
        $position = trim((string) ($assignment['position'] ?? ''));
        return $position !== '' ? $position : trim((string) ($team['name'] ?? ''));
    }

    /** The name somebody chose for themselves, else the one on the account. */
    public static function personName(array $user): string
    {
        foreach (['display_name', 'name'] as $field) {
            $value = trim((string) ($user[$field] ?? ''));
            if ($value !== '' && !str_contains($value, '@')) {
                return $value;
            }
        }
        return '';
    }
}
