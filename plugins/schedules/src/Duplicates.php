<?php

declare(strict_types=1);

namespace MarineTeam\Plugins\Schedules;

/**
 * Near-duplicate names (the original's lib/schedules/duplicates.ts).
 * Spellings that differ only in case, spacing or accents are already one
 * person — that is what the normalized key is for. What is left is the
 * genuine near-duplicate ("Dave" and "Davey"), which is suggested and
 * never merged automatically: only somebody who knows the church can say
 * whether those are one person.
 */
final class Duplicates
{
    /** A prefix shorter than this says nothing. */
    public const MIN_PREFIX = 3;
    /** How much longer the other spelling may be. */
    public const MAX_EXTRA = 3;
    /** Pairs one screen can act on. */
    public const LIMIT = 50;

    /**
     * @param list<array{id: string, displayName: string, normalizedName?: string}> $people
     * @return list<array{a: array<string, mixed>, b: array<string, mixed>}>
     */
    public static function possibleDuplicates(array $people, int $limit = self::LIMIT): array
    {
        $keyed = [];
        foreach ($people as $person) {
            $key = (string) ($person['normalizedName'] ?? Names::normalizeName((string) $person['displayName']));
            if ($key !== '') {
                $keyed[] = ['key' => $key, 'person' => $person];
            }
        }
        usort($keyed, fn (array $a, array $b) => [strlen($a['key']), $a['key']] <=> [strlen($b['key']), $b['key']]);
        $out = [];
        foreach ($keyed as $i => $shorter) {
            if (strlen($shorter['key']) < self::MIN_PREFIX) {
                continue;
            }
            foreach (array_slice($keyed, $i + 1) as $longer) {
                $extra = strlen($longer['key']) - strlen($shorter['key']);
                if ($extra === 0 || $extra > self::MAX_EXTRA) {
                    continue;
                }
                if (!str_starts_with($longer['key'], $shorter['key'])) {
                    continue;
                }
                $out[] = ['a' => $shorter['person'], 'b' => $longer['person']];
                if (count($out) >= $limit) {
                    return $out;
                }
            }
        }
        return $out;
    }
}
