<?php

declare(strict_types=1);

namespace MarineTeam\Plugins\Prayer;

/**
 * The prayer wall's two decisions, one function each (the original's
 * lib/prayer.ts): canSee for whether a reader may see a request at all,
 * bylineFor for what they may be told about who wrote it. Every read path
 * — the wall, the moderation queue, the API — goes through visibleTo,
 * which composes them, so a where clause copied between four queries
 * can't quietly disagree with the rule. Here disagreement means somebody's
 * name on something they asked to post anonymously.
 */
final class Prayer
{
    public const EVERYONE = 'EVERYONE';
    public const MEMBERS = 'MEMBERS';
    public const LEADERS = 'LEADERS';
    public const VISIBILITIES = [self::EVERYONE, self::MEMBERS, self::LEADERS];

    public const PENDING = 'PENDING';
    public const APPROVED = 'APPROVED';
    public const ANSWERED = 'ANSWERED';
    public const HIDDEN = 'HIDDEN';
    public const STATUSES = [self::PENDING, self::APPROVED, self::ANSWERED, self::HIDDEN];

    /** @param array<string, mixed> $request */
    private static function mine(array $request, ?string $userId): bool
    {
        // A visitor is not the author of every request a visitor wrote.
        return $userId !== null && $request['user_id'] !== null && (string) $request['user_id'] === $userId;
    }

    /**
     * Whether this reader may see this request at all.
     *
     * @param array<string, mixed> $request
     */
    public static function canSee(array $request, ?string $userId, bool $moderator): bool
    {
        if ($moderator) {
            return true;
        }
        if ((string) $request['status'] === self::HIDDEN) {
            return false;
        }
        if (self::mine($request, $userId)) {
            return true;
        }
        if (!in_array((string) $request['status'], [self::APPROVED, self::ANSWERED], true)) {
            return false;
        }
        return match ((string) $request['visibility']) {
            self::EVERYONE => true,
            self::MEMBERS => $userId !== null,
            default => false,
        };
    }

    /**
     * The only place a name is allowed out.
     *
     * @param array<string, mixed> $request
     */
    public static function bylineFor(array $request): ?string
    {
        if ((bool) $request['anonymous']) {
            return null;
        }
        $name = trim((string) ($request['name'] ?? ''));
        // Never a blank, and never an address somebody typed into a name box.
        return $name === '' || str_contains($name, '@') ? null : $name;
    }

    /**
     * One request as a reader is given it: a byline at most, never an
     * account id, not even in a moderator's own queue.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public static function present(array $request, ?string $userId, bool $moderator, int $prayers = 0, bool $prayed = false): array
    {
        return [
            'id' => (string) $request['id'],
            'body' => (string) $request['body'],
            'author' => self::bylineFor($request),
            'anonymous' => (bool) $request['anonymous'],
            'visibility' => (string) $request['visibility'],
            'status' => (string) $request['status'],
            'answeredNote' => $request['answered_note'] !== null ? (string) $request['answered_note'] : null,
            'answeredAt' => \App\Core\Json::instant($request['answered_at'] !== null ? (string) $request['answered_at'] : null),
            'createdAt' => \App\Core\Json::instant((string) $request['created_at']),
            'prayers' => $prayers,
            'prayed' => $prayed,
            'mine' => self::mine($request, $userId),
            'canDelete' => self::canDelete($request, $userId, $moderator),
            'canPray' => self::canPrayFor($request, $userId, $moderator),
        ];
    }

    /**
     * The rows this reader may see, presented. What they may not see is
     * dropped without them being told it was there.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, int> $prayers request id => how many have prayed
     * @param list<string> $prayed request ids this reader has prayed for
     * @return list<array<string, mixed>>
     */
    public static function visibleTo(array $rows, ?string $userId, bool $moderator, array $prayers = [], array $prayed = []): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (self::canSee($row, $userId, $moderator)) {
                $out[] = self::present($row, $userId, $moderator, $prayers[(string) $row['id']] ?? 0, in_array((string) $row['id'], $prayed, true));
            }
        }
        return $out;
    }

    /**
     * "I prayed for this" needs an account, so that pressing it twice isn't
     * two, and it is never counted for something nobody has been shown.
     *
     * @param array<string, mixed> $request
     */
    public static function canPrayFor(array $request, ?string $userId, bool $moderator): bool
    {
        return $userId !== null
            && in_array((string) $request['status'], [self::APPROVED, self::ANSWERED], true)
            && self::canSee($request, $userId, $moderator);
    }

    /** @param array<string, mixed> $request */
    public static function canDelete(array $request, ?string $userId, bool $moderator): bool
    {
        return $moderator || self::mine($request, $userId);
    }
}
