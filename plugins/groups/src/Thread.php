<?php

declare(strict_types=1);

namespace MarineTeam\Plugins\Groups;

/**
 * The group's conversation (the original's lib/group-messages.ts): a
 * thread on the group's own page, for the six days it isn't meeting.
 *
 * Only people actually in the group read or write it — not somebody whose
 * ask is unanswered, not somebody on the waiting list: as private as the
 * address, and for the same reason. A site manager outside the group gets
 * nothing, which is the one place this departs from the address rule: an
 * address is an operational fact somebody running the site may need; a
 * conversation isn't. Putting them in the group works, and leaves a row
 * saying so.
 */
final class Thread
{
    public const OPEN = 'OPEN';
    public const SIGN_IN = 'SIGN_IN';
    public const ASKED = 'ASKED';
    public const WAITING = 'WAITING';
    public const OUTSIDE = 'OUTSIDE';

    public const MAX_LENGTH = 4000;
    /** Messages one page carries. */
    public const PAGE = 50;
    /** Repeats of one character kept. */
    public const RUN = 4;

    /** @param list<array<string, mixed>> $members */
    public static function inTheThread(array $members, ?string $userId): bool
    {
        return $userId !== null && Groups::standingIn($members, $userId) === Groups::ACTIVE;
    }

    /**
     * Who may take anybody's message down: this group's leaders. A site
     * manager who is in the group leads it by capability; one outside it
     * does not.
     *
     * @param list<array<string, mixed>> $members
     */
    public static function canModerate(array $members, ?string $userId, bool $keepsTheList = false): bool
    {
        if (!self::inTheThread($members, $userId)) {
            return false;
        }
        return Groups::canLead($members, $userId, $keepsTheList);
    }

    /** @param list<array<string, mixed>> $members */
    public static function threadState(array $members, ?string $userId): string
    {
        if ($userId === null) {
            return self::SIGN_IN;
        }
        return match (Groups::standingIn($members, $userId)) {
            Groups::ACTIVE => self::OPEN,
            Groups::REQUESTED => self::ASKED,
            Groups::WAITLIST => self::WAITING,
            default => self::OUTSIDE,
        };
    }

    /**
     * The messages this reader may have. A hidden one is dropped here as
     * well as in the query, so a message hidden between two polls can't
     * arrive in the second one, and no account id travels out.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<array<string, mixed>> $members
     * @return list<array{id: string, author: string, body: string, createdAt: string, mine: bool, canRemove: bool}>
     */
    public static function visibleThread(array $rows, array $members, ?string $userId, bool $keepsTheList = false): array
    {
        if (!self::inTheThread($members, $userId)) {
            return [];
        }
        $moderator = self::canModerate($members, $userId, $keepsTheList);
        $out = [];
        foreach ($rows as $row) {
            if ((bool) ($row['hidden'] ?? false)) {
                continue;
            }
            $mine = (string) $row['user_id'] === $userId;
            $out[] = [
                'id' => (string) $row['id'],
                'author' => (string) $row['author_name'],
                'body' => (string) $row['body'],
                'createdAt' => (string) \App\Core\Json::instant((string) $row['created_at']),
                'mine' => $mine,
                'canRemove' => $mine || $moderator,
            ];
        }
        return $out;
    }

    /**
     * Authors remove their own; a leader removes anything. Somebody who has
     * left the group removes nothing, their own included.
     *
     * @param array<string, mixed> $message
     * @param list<array<string, mixed>> $members
     */
    public static function canRemoveMessage(array $message, array $members, ?string $userId, bool $keepsTheList = false): bool
    {
        if (!self::inTheThread($members, $userId)) {
            return false;
        }
        return (string) $message['user_id'] === $userId || self::canModerate($members, $userId, $keepsTheList);
    }

    /**
     * What will be stored, or null for a message that is refused. A
     * paragraph is fine here — unlike a stream chat — but the runs that
     * turn one message into a screenful are collapsed.
     */
    public static function cleanGroupMessage(string $body): ?string
    {
        if (mb_strlen($body) > self::MAX_LENGTH * 4) {
            return null;
        }
        $text = str_replace(["\r\n", "\r"], "\n", $body);
        // Blank lines beyond one, and runs of one character, come back to size.
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);
        $text = (string) preg_replace('/[ \t]{3,}/', ' ', $text);
        $text = (string) preg_replace('/(\S)\1{' . self::RUN . ',}/u', str_repeat('$1', self::RUN), $text);
        $text = trim($text);
        return $text === '' || mb_strlen($text) > self::MAX_LENGTH ? null : $text;
    }

    /**
     * Who hears about a message: active members other than the author,
     * minus the muted.
     *
     * @param list<array<string, mixed>> $members
     * @return list<string> user ids
     */
    public static function notifiable(array $members, string $authorId): array
    {
        $out = [];
        foreach (Groups::activeMembers($members) as $member) {
            if ((string) $member['user_id'] !== $authorId && !(bool) ($member['muted'] ?? false)) {
                $out[] = (string) $member['user_id'];
            }
        }
        return $out;
    }

    /**
     * The newest page, in reading order, without touching what it was given.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function latest(array $rows, int $page = self::PAGE): array
    {
        $sorted = $rows;
        usort($sorted, fn (array $a, array $b) => [(string) $a['created_at'], (string) $a['id']] <=> [(string) $b['created_at'], (string) $b['id']]);
        return array_values(array_slice($sorted, -max(1, $page)));
    }

    /** The first line only: a group thread is exactly where the whole of a message shouldn't sit on a lock screen. */
    public static function firstLine(string $body, int $limit = 120): string
    {
        $line = trim((string) (preg_split('/\n/', $body)[0] ?? ''));
        return mb_strlen($line) > $limit ? mb_substr($line, 0, $limit - 1) . '…' : $line;
    }
}
