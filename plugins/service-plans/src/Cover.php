<?php

declare(strict_types=1);

namespace MarineTeam\Plugins\ServicePlans;

/**
 * "Something has come up" (the original's lib/cover.ts). The rota models
 * an ask and an answer; what it had no word for is the thing that happens
 * most often after a yes. Without one, the whole exchange goes to text
 * message and the rota keeps saying somebody will be there who won't.
 */
final class Cover
{
    /** Nobody has asked for cover on this slot. */
    public const NOT_ASKED = 'NOT_ASKED';
    /** It is your own slot: there is nothing for you to take. */
    public const YOURS = 'YOURS';
    /** You are already on for that service, doing something else. */
    public const ALREADY_ON = 'ALREADY_ON';
    /** You said you were away. A warning, not a refusal. */
    public const AWAY = 'AWAY';
    /** The service has been and gone. */
    public const PAST = 'PAST';
    public const OPEN = 'OPEN';

    /** The states that stop somebody taking a slot; AWAY is not one of them. */
    public const REFUSALS = [self::NOT_ASKED, self::YOURS, self::ALREADY_ON, self::PAST];

    /**
     * Whether a service is still to come. The whole of its own day counts
     * as not yet past — a rota is read on the morning — and an undated plan
     * is always still to come.
     *
     * @param array<string, mixed> $plan
     */
    public static function stillToCome(array $plan, string $today): bool
    {
        $date = ($plan['service_date'] ?? null) === null ? null : substr((string) $plan['service_date'], 0, 10);
        return $date === null || $date >= $today;
    }

    /**
     * Whether this person may ask for cover on this slot.
     *
     * @param array<string, mixed> $assignment
     * @param array<string, mixed> $plan
     */
    public static function canAskForCover(array $assignment, ?string $userId, array $plan, string $today): bool
    {
        if ($userId === null || (string) $assignment['user_id'] !== $userId) {
            // Somebody else's slot is not theirs to hand on.
            return false;
        }
        if ((string) $assignment['status'] === Rota::DECLINED) {
            // Nothing to cover: they already said no.
            return false;
        }
        if ((bool) ($assignment['cover_wanted'] ?? false)) {
            return false;
        }
        if (!(bool) ($plan['published'] ?? false)) {
            // A plan nobody can see yet has nobody to ask.
            return false;
        }
        return self::stillToCome($plan, $today);
    }

    /**
     * What this person can do about a slot somebody wants covered.
     *
     * @param array<string, mixed> $assignment
     * @param array<string, mixed> $plan
     * @param list<array<string, mixed>> $theirOtherSlots their own assignments on this plan
     * @param list<array<string, mixed>> $blockouts their own blockouts
     */
    public static function coverState(array $assignment, ?string $userId, array $plan, array $theirOtherSlots, array $blockouts, string $today): string
    {
        if (!(bool) ($assignment['cover_wanted'] ?? false)) {
            return self::NOT_ASKED;
        }
        if ($userId !== null && (string) $assignment['user_id'] === $userId) {
            return self::YOURS;
        }
        if (!self::stillToCome($plan, $today)) {
            return self::PAST;
        }
        foreach ($theirOtherSlots as $slot) {
            if ((string) $slot['id'] !== (string) $assignment['id'] && (string) $slot['status'] !== Rota::DECLINED) {
                // Being already on comes ahead of being away: one is a
                // refusal, the other is only a warning.
                return self::ALREADY_ON;
            }
        }
        if (Rota::isBlockedOut($blockouts, ($plan['service_date'] ?? null) === null ? null : (string) $plan['service_date'])) {
            // They have almost certainly changed their plans, and a rota
            // that argues with the person offering to help is a rota
            // nobody helps with.
            return self::AWAY;
        }
        return self::OPEN;
    }

    /** Whether a state stops somebody taking the slot. */
    public static function refuses(string $state): bool
    {
        return in_array($state, self::REFUSALS, true);
    }

    /** Something for every state, and nothing when it is simply open. */
    public static function takeMessage(string $state): string
    {
        return match ($state) {
            self::NOT_ASKED => 'Nobody has asked for cover on that one.',
            self::YOURS => 'That one is already yours.',
            self::ALREADY_ON => 'You are already on for that service.',
            self::AWAY => 'You said you were away that day — take it anyway?',
            self::PAST => 'That service has been and gone.',
            default => '',
        };
    }

    /** The name to put against an ask: the one they chose, never an address. */
    public static function askerName(array $user): string
    {
        return Rota::personName($user);
    }
}
