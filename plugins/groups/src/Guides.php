<?php

declare(strict_types=1);

namespace MarineTeam\Plugins\Groups;

/**
 * Discussion guides (the original's lib/guides.ts): the questions a group
 * works through, written once and used by every group.
 *
 * Leader notes are absent from what a member is given, not hidden in the
 * markup — the shape handed to a member page has no leaderNotes on it at
 * all, so a page cannot print an answer it was never given.
 */
final class Guides
{
    public const QUESTION = 'QUESTION';
    public const SCRIPTURE = 'SCRIPTURE';
    public const NOTE = 'NOTE';
    public const LEADER_NOTE = 'LEADER_NOTE';
    public const KINDS = [self::QUESTION, self::SCRIPTURE, self::NOTE, self::LEADER_NOTE];
    /** The three anybody in a group may read. */
    public const MEMBER_KINDS = [self::QUESTION, self::SCRIPTURE, self::NOTE];

    public static function isMemberKind(string $kind): bool
    {
        return in_array($kind, self::MEMBER_KINDS, true);
    }

    /**
     * Anybody who leads any group may read the notes — the person hosting
     * Tuesday doesn't need a capability granted to read the notes for
     * Tuesday — as may whoever keeps the group list.
     */
    public static function canSeeLeaderNotes(bool $leadsAnyGroup, bool $keepsTheList = false): bool
    {
        return $leadsAnyGroup || $keepsTheList;
    }

    /** Anybody may open a published guide; a draft is staff's. */
    public static function canOpenGuide(array $guide, bool $isStaff): bool
    {
        return (bool) $guide['published'] || $isStaff;
    }

    /**
     * A guide as this reader is given it.
     *
     * @param array<string, mixed> $guide
     * @param list<array<string, mixed>> $items in any order
     * @return array<string, mixed>
     */
    public static function presentGuide(array $guide, array $items, bool $withLeaderNotes): array
    {
        // Read in the order it was written, whatever order the rows arrive in.
        usort($items, fn (array $a, array $b) => [(int) $a['position'], (string) $a['id']] <=> [(int) $b['position'], (string) $b['id']]);
        $visible = [];
        $notes = [];
        foreach ($items as $item) {
            $kind = (string) $item['kind'];
            $shape = ['kind' => $kind, 'body' => (string) $item['body'], 'reference' => $item['reference'] ?? null];
            if (self::isMemberKind($kind)) {
                $visible[] = $shape;
            } elseif ($kind === self::LEADER_NOTE) {
                $notes[] = $shape;
            }
        }
        $out = [
            'id' => (string) $guide['id'],
            'slug' => (string) $guide['slug'],
            'title' => (string) $guide['title'],
            'description' => $guide['description'] ?? null,
            'published' => (bool) $guide['published'],
            'items' => $visible,
            'summary' => self::describeGuide($visible),
        ];
        // The field is left off entirely rather than sent empty.
        if ($withLeaderNotes && $notes !== []) {
            $out['leaderNotes'] = $notes;
        }
        return $out;
    }

    /**
     * What a guide amounts to, in a phrase: questions are counted, not items.
     *
     * @param list<array{kind: string, body: string, reference: ?string}> $items the visible ones
     */
    public static function describeGuide(array $items): string
    {
        $questions = count(array_filter($items, fn (array $i) => $i['kind'] === self::QUESTION));
        if ($questions === 0) {
            return 'A handout';
        }
        return $questions === 1 ? '1 question' : "$questions questions";
    }
}
