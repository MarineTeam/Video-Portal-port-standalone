<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Timestamps as people write them in a ?t= link or a "Share at" box:
 * "90", "1:30", "1:02:03", "1h2m3s", "2m", with or without a trailing "s".
 */
final class Timestamp
{
    /** Seconds, or null when it isn't a time. */
    public static function parse(?string $text): ?int
    {
        $text = strtolower(trim((string) $text));
        if ($text === '') {
            return null;
        }
        if (preg_match('/^\d{1,6}s?$/', $text)) {
            return (int) $text;
        }
        if (preg_match('/^(?:(\d{1,3}):)?(\d{1,2}):(\d{2})$/', $text, $m)) {
            if ((int) $m[3] > 59 || ($m[1] !== '' && (int) $m[2] > 59)) {
                return null;
            }
            return (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3];
        }
        if (preg_match('/^(?:(\d{1,3})h)?(?:(\d{1,4})m)?(?:(\d{1,6})s)?$/', $text, $m)) {
            return (int) ($m[1] ?? 0) * 3600 + (int) ($m[2] ?? 0) * 60 + (int) ($m[3] ?? 0);
        }
        return null;
    }

    /** "1:02:03" or "2:05". */
    public static function format(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    }
}
