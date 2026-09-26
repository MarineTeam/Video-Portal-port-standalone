<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A hymn's words, split into what a congregation sings (lib/verses.ts).
 *
 * A blank line is the verse break, which is how every hymnal and every word
 * processor writes them. A verse is numbered; a chorus keeps its name,
 * because "2" and "Chorus" are not the same instruction from the front.
 */
final class Verses
{
    /** The names a hymnal prints over a repeated part, and nothing else. */
    public const NAMED = ['chorus', 'refrain', 'coro', 'estribillo', 'bridge', 'puente', 'tag', 'ending', 'final', 'coda', 'vamp', 'intro', 'outro', 'pre-chorus'];

    /**
     * @return list<array{label: string, number: ?int, lines: list<string>}>
     */
    public static function split(string $lyrics): array
    {
        // What pasting from a document actually looks like: CRLFs, hard
        // spaces, and three blank lines where one was meant.
        $text = str_replace(["\r\n", "\r", "\u{00a0}"], ["\n", "\n", ' '], $lyrics);
        $blocks = preg_split('/\n\s*\n+/', trim($text)) ?: [];
        $out = [];
        $number = 0;
        foreach ($blocks as $block) {
            $lines = [];
            foreach (explode("\n", $block) as $line) {
                $line = rtrim($line);
                if (trim($line) !== '') {
                    $lines[] = trim($line);
                }
            }
            if ($lines === []) {
                continue;
            }
            $name = self::nameOf($lines[0]);
            if ($name !== null) {
                // The name was a heading of its own, not the first line.
                $rest = count($lines) > 1 && self::isHeadingOnly($lines[0]) ? array_slice($lines, 1) : $lines;
                $out[] = ['label' => $name, 'number' => null, 'lines' => array_values($rest)];
                continue;
            }
            $number++;
            $out[] = ['label' => (string) $number, 'number' => $number, 'lines' => $lines];
        }
        return $out;
    }

    /** The name a block announces itself by, if it is one of the known ones. */
    private static function nameOf(string $firstLine): ?string
    {
        $head = trim((string) preg_replace('/^\s*(\d+\s*[.):]\s*)?/u', '', $firstLine));
        $head = trim($head, " \t:.-–—");
        $lower = mb_strtolower($head);
        foreach (self::NAMED as $name) {
            if ($lower === $name || $lower === $name . ':') {
                return mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
            }
        }
        return null;
    }

    private static function isHeadingOnly(string $line): bool
    {
        return mb_strlen(trim($line)) <= 20;
    }
}
