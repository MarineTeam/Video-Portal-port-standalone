<?php

declare(strict_types=1);

namespace MarineTeam\Plugins\Live;

/**
 * The rules a live chat runs by (the original's lib/live-chat.ts), kept
 * apart from the routes because they are the part worth reading: when the
 * box is open, what a message may say, how long somebody waits under slow
 * mode, and what a poll is allowed to carry back.
 */
final class Chat
{
    /** The chat was never switched on for this stream. */
    public const OFF = 'OFF';
    /** Switched on, but too early to write yet. */
    public const EARLY = 'EARLY';
    public const OPEN = 'OPEN';
    /** Over: the messages stay readable, the box goes. */
    public const CLOSED = 'CLOSED';

    /** Open early enough that people arriving can say hello. */
    public const OPENS_BEFORE = 1800;
    /** Long enough after that the conversation a service starts isn't cut off. */
    public const CLOSES_AFTER = 3600;
    /** What a stream with no end time is assumed to run for. */
    public const ASSUMED_LENGTH = 7200;

    /** Longer than anybody says in a chat, and short enough to read. */
    public const MAX_LENGTH = 500;
    /** Repeats of one character kept: '!!!!!!!!!!' is '!!!'. */
    public const RUN = 3;

    /** When a stream is over, for everything that needs an end: its own, or an assumed one. */
    public static function endsAt(\DateTimeImmutable $start, ?\DateTimeImmutable $end): \DateTimeImmutable
    {
        return $end !== null && $end > $start ? $end : $start->modify('+' . self::ASSUMED_LENGTH . ' seconds');
    }

    /** Whether the box is there, and why not when it isn't. */
    public static function state(bool $enabled, \DateTimeImmutable $start, ?\DateTimeImmutable $end, \DateTimeImmutable $now): string
    {
        if (!$enabled) {
            return self::OFF;
        }
        if ($now < $start->modify('-' . self::OPENS_BEFORE . ' seconds')) {
            return self::EARLY;
        }
        return $now <= self::endsAt($start, $end)->modify('+' . self::CLOSES_AFTER . ' seconds') ? self::OPEN : self::CLOSED;
    }

    /**
     * What will be stored, or null for a message that is refused: nothing
     * at all, or an essay. Whitespace collapses to single spaces and a run
     * of one character to three, which is the shouting a length limit
     * leaves standing.
     */
    public static function clean(string $body): ?string
    {
        if (mb_strlen($body) > self::MAX_LENGTH * 20) {
            return null;
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', str_replace(["\r\n", "\r"], "\n", $body)));
        $text = (string) preg_replace('/(.)\1{' . self::RUN . ',}/u', str_repeat('$1', self::RUN), $text);
        return $text === '' || mb_strlen($text) > self::MAX_LENGTH ? null : $text;
    }

    /**
     * Seconds this person must still wait. Slow mode counts from their own
     * last message, not the chat's — limiting the whole chat would let one
     * fast typist silence everybody else.
     */
    public static function waitSeconds(int $slowMode, ?\DateTimeImmutable $lastAt, \DateTimeImmutable $now): int
    {
        if ($slowMode <= 0 || $lastAt === null) {
            return 0;
        }
        return max(0, $slowMode - ($now->getTimestamp() - $lastAt->getTimestamp()));
    }

    /**
     * The messages a poll may carry back: never a hidden one, however far
     * behind the tab asking is, and never an account id. Whether somebody
     * may take a message down travels instead of who wrote it.
     *
     * @param list<array<string, mixed>> $rows live_chat_messages rows, oldest first
     * @return list<array{id: string, author: string, body: string, createdAt: string, mine: bool, canDelete: bool}>
     */
    public static function visible(array $rows, ?string $viewerId, bool $moderator): array
    {
        $out = [];
        foreach ($rows as $row) {
            if ((bool) ($row['hidden'] ?? false)) {
                continue;
            }
            $mine = $viewerId !== null && (string) $row['user_id'] === $viewerId;
            $out[] = [
                'id' => (string) $row['id'],
                'author' => (string) $row['author_name'],
                'body' => (string) $row['body'],
                'createdAt' => (string) \App\Core\Json::instant((string) $row['created_at']),
                'mine' => $mine,
                'canDelete' => $mine || $moderator,
            ];
        }
        return $out;
    }
}
