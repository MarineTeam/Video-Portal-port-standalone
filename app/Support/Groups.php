<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The one rule about a small group's address, shared by the group's own page
 * (the groups plugin) and "Download my data", so the two can't drift apart:
 * only people actually in the group get the house. Somebody who has asked,
 * is on the waiting list, or was turned down gets the area.
 */
final class Groups
{
    public const STATUSES = ['REQUESTED', 'ACTIVE', 'WAITLIST', 'DECLINED'];

    public static function canSeeAddress(?string $status): bool
    {
        return $status === 'ACTIVE';
    }
}
