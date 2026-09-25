<?php

declare(strict_types=1);

namespace App\Modules\Library;

/**
 * The rules that decide whether a piece of content exists for a reader, as
 * pure functions over rows (snake_case columns, as the database returns
 * them). The database-backed checks in ContentAccess compose these.
 *
 *   live      published, not trashed, inside its publish window;
 *   visible   live and not hidden — hidden content doesn't exist for anyone
 *             but the people managing it, direct URL included;
 *   premiere  a published video with a future publish time, marked premiere:
 *             its page shows a countdown instead of staying hidden.
 */
final class Visibility
{
    /** @param array<string, mixed> $row */
    public static function isLive(array $row, \DateTimeImmutable $now): bool
    {
        if (!(bool) ($row['published'] ?? false) || ($row['deleted_at'] ?? null) !== null) {
            return false;
        }
        $at = self::time($row['publish_at'] ?? null);
        if ($at !== null && $at > $now) {
            return false;
        }
        $until = self::time($row['unpublish_at'] ?? null);
        return $until === null || $until > $now;
    }

    /** @param array<string, mixed> $row */
    public static function isVisible(array $row, \DateTimeImmutable $now): bool
    {
        return self::isLive($row, $now) && !(bool) ($row['hidden'] ?? false);
    }

    /** @param array<string, mixed> $video */
    public static function isPremiere(array $video, \DateTimeImmutable $now): bool
    {
        if (!(bool) ($video['is_premiere'] ?? false) || !(bool) ($video['published'] ?? false)
            || ($video['deleted_at'] ?? null) !== null || (bool) ($video['hidden'] ?? false)) {
            return false;
        }
        $at = self::time($video['publish_at'] ?? null);
        return $at !== null && $at > $now;
    }

    /**
     * lib/content.ts canAccess: anyone for an item that isn't members-only;
     * a signed-in member for one that is.
     *
     * @param array<string, mixed> $item
     */
    public static function canAccess(array $item, ?array $user): bool
    {
        $memberOnly = (bool) ($item['member_only'] ?? $item['memberOnly'] ?? false);
        return !$memberOnly || $user !== null;
    }

    /**
     * The ids of a series' videos a member can't open yet because an earlier
     * one isn't finished. Nothing is locked for a visitor (there is no
     * progress to go on) or when the series doesn't ask for it — and in
     * neither case is progress looked up.
     *
     * @param list<array{id: string, position: int|string}> $videos
     * @param callable(): list<string> $completedIds
     * @return list<string>
     */
    public static function sequentialLockedVideoIds(bool $signedIn, bool $requireSequential, array $videos, callable $completedIds): array
    {
        if (!$signedIn || !$requireSequential || $videos === []) {
            return [];
        }
        usort($videos, fn ($a, $b) => [(int) $a['position'], $a['id']] <=> [(int) $b['position'], $b['id']]);
        $done = array_flip($completedIds());
        $locked = [];
        $blocked = false;
        foreach ($videos as $video) {
            if ($blocked) {
                $locked[] = $video['id'];
            } elseif (!isset($done[$video['id']])) {
                // The first unfinished one stays open; everything after waits for it.
                $blocked = true;
            }
        }
        return $locked;
    }

    /**
     * The SQL for "live and not hidden" on one alias, with its parameters.
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function sql(string $alias, \DateTimeImmutable $now, bool $includeHidden = false): array
    {
        $t = \App\Core\Db::datetime($now);
        $sql = "$alias.published = 1 AND $alias.deleted_at IS NULL AND ($alias.publish_at IS NULL OR $alias.publish_at <= ?) AND ($alias.unpublish_at IS NULL OR $alias.unpublish_at > ?)";
        if (!$includeHidden) {
            $sql .= " AND $alias.hidden = 0";
        }
        return [$sql, [$t, $t]];
    }

    private static function time(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }
        try {
            return new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }
}
