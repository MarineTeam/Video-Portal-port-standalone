<?php

declare(strict_types=1);

namespace MarineTeam\Plugins\SermonNotes;

/**
 * A sermon note sheet: plain text with three or more underscores marking
 * each gap (the original's lib/outline.ts). Gaps are numbered across the
 * whole sheet; an answer belongs to a gap by that number alone, so the
 * outline's fingerprint is stored with the answers and an edited sheet is
 * reported rather than silently misaligned.
 */
final class Outline
{
    public const GAP = '/_{3,}/';

    /**
     * Lines of segments: ['text' => …] as printed, ['gap' => n] to fill in.
     *
     * @return list<list<array{text: string}|array{gap: int}>>
     */
    public static function parse(string $outline): array
    {
        $lines = [];
        $gap = 0;
        foreach (explode("\n", self::normalise($outline)) as $line) {
            $segments = [];
            $parts = preg_split(self::GAP, $line);
            foreach ((array) $parts as $i => $part) {
                if ($i > 0) {
                    $segments[] = ['gap' => $gap++];
                }
                if ($part !== '') {
                    $segments[] = ['text' => (string) $part];
                }
            }
            $lines[] = $segments;
        }
        return $lines;
    }

    public static function gapCount(string $outline): int
    {
        return preg_match_all(self::GAP, self::normalise($outline));
    }

    /** What the sheet said, whatever its line endings. */
    public static function fingerprint(string $outline): string
    {
        return hash('sha256', self::normalise($outline));
    }

    /**
     * The sheet with the answers written into it; an unfilled gap stays a gap.
     *
     * @param array<int|string, string> $answers gap number => text
     */
    public static function toText(string $outline, array $answers): string
    {
        $out = [];
        foreach (self::parse($outline) as $line) {
            $text = '';
            foreach ($line as $segment) {
                if (isset($segment['gap'])) {
                    $answer = trim((string) ($answers[$segment['gap']] ?? $answers[(string) $segment['gap']] ?? ''));
                    $text .= $answer !== '' ? $answer : '___';
                } else {
                    $text .= $segment['text'];
                }
            }
            $out[] = $text;
        }
        return implode("\n", $out);
    }

    private static function normalise(string $outline): string
    {
        return str_replace(["\r\n", "\r"], "\n", $outline);
    }
}
