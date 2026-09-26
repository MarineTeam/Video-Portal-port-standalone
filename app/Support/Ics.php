<?php

declare(strict_types=1);

namespace App\Support;

/**
 * iCalendar files (RFC 5545), the port of the original's lib/ics.ts.
 * Everything this app knows the date of can be read by Google Calendar,
 * Outlook, Apple Calendar and every phone, so the details here are the
 * ones that decide whether a calendar shows the right thing.
 */
final class Ics
{
    public const CRLF = "\r\n";
    /** What a subscribing client is asked to wait between fetches. */
    public const REFRESH_MINUTES = 60;

    /** The four characters the format reserves; a colon is not one of them. */
    public static function escapeText(string $text): string
    {
        // The backslash first, so an escape isn't escaped twice.
        $out = str_replace('\\', '\\\\', $text);
        $out = str_replace(["\r\n", "\r", "\n"], '\\n', $out);
        return str_replace([';', ','], ['\;', '\\,'], $out);
    }

    /**
     * Folds at 75 octets, continuing with a space. Octets rather than
     * characters: cutting between the bytes of an em dash produces a black
     * diamond halfway through a word, which a calendar shows rather than
     * rejecting.
     */
    public static function foldLine(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out = '';
        $room = 75;
        $length = strlen($line);
        for ($i = 0; $i < $length;) {
            // How many octets this character takes.
            $byte = ord($line[$i]);
            $width = match (true) {
                $byte >= 0xF0 => 4,
                $byte >= 0xE0 => 3,
                $byte >= 0xC0 => 2,
                default => 1,
            };
            if ($width > $room) {
                $out .= self::CRLF . ' ';
                $room = 74;
            }
            $out .= substr($line, $i, $width);
            $room -= $width;
            $i += $width;
        }
        return $out;
    }

    /** An instant as UTC with no punctuation. */
    public static function stampInstant(\DateTimeInterface $at): string
    {
        return (new \DateTimeImmutable('@' . $at->getTimestamp()))->format('Ymd\THis\Z');
    }

    /** A day as eight digits. */
    public static function stampDay(\DateTimeInterface $at): string
    {
        return $at->format('Ymd');
    }

    /**
     * A calendar a client will accept.
     *
     * @param list<array<string, mixed>> $entries
     */
    public static function icsCalendar(array $entries, string $name, ?\DateTimeInterface $now = null): string
    {
        $stamp = self::stampInstant($now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Marine Team//Church portal//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . self::escapeText($name),
            // Anything subscribing is offered a refresh interval.
            'REFRESH-INTERVAL;VALUE=DURATION:PT' . self::REFRESH_MINUTES . 'M',
            'X-PUBLISHED-TTL:PT' . self::REFRESH_MINUTES . 'M',
        ];
        foreach ($entries as $entry) {
            array_push($lines, ...self::event($entry, $stamp));
        }
        $lines[] = 'END:VCALENDAR';
        return implode(self::CRLF, array_map([self::class, 'foldLine'], $lines)) . self::CRLF;
    }

    /**
     * @param array<string, mixed> $entry
     * @return list<string>
     */
    private static function event(array $entry, string $stamp): array
    {
        $allDay = (bool) ($entry['allDay'] ?? false);
        $starts = $entry['starts'];
        $ends = $entry['ends'] ?? null;
        $lines = ['BEGIN:VEVENT', 'UID:' . self::escapeText((string) $entry['uid']), 'DTSTAMP:' . $stamp];
        if ($allDay) {
            $lines[] = 'DTSTART;VALUE=DATE:' . self::stampDay($starts);
            // DTEND is exclusive: writing the same day is what makes an
            // all-day event vanish from month view in some clients.
            $finish = $ends instanceof \DateTimeInterface ? $ends : (new \DateTimeImmutable('@' . $starts->getTimestamp()))->modify('+1 day');
            $lines[] = 'DTEND;VALUE=DATE:' . self::stampDay($finish);
        } else {
            $lines[] = 'DTSTART:' . self::stampInstant($starts);
            if ($ends instanceof \DateTimeInterface) {
                $lines[] = 'DTEND:' . self::stampInstant($ends);
            }
        }
        $lines[] = 'SUMMARY:' . self::escapeText((string) ($entry['summary'] ?? ''));
        foreach (['description' => 'DESCRIPTION', 'location' => 'LOCATION'] as $key => $field) {
            if (($entry[$key] ?? null) !== null && (string) $entry[$key] !== '') {
                $lines[] = $field . ':' . self::escapeText((string) $entry[$key]);
            }
        }
        if (($entry['url'] ?? null) !== null && (string) $entry['url'] !== '') {
            // A URL is a URI value: it is not escaped, or a calendar can't follow it.
            $lines[] = 'URL:' . str_replace(["\r", "\n"], '', (string) $entry['url']);
        }
        if (($entry['relatedTo'] ?? null) !== null) {
            $lines[] = 'RELATED-TO:' . self::escapeText((string) $entry['relatedTo']);
        }
        if (($entry['status'] ?? null) !== null) {
            // A declined date is marked cancelled rather than left out: a
            // missing entry stays on the phone of whoever already said no.
            $lines[] = 'STATUS:' . strtoupper((string) $entry['status']);
        }
        $lines[] = 'END:VEVENT';
        return $lines;
    }

    /** A name a browser will save, with nothing that could break the header it sits in. */
    public static function icsFilename(string $name): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($name)) ?? '';
        $clean = trim((string) preg_replace('/-+/', '-', $clean), '-.');
        $clean = $clean === '' ? 'calendar' : $clean;
        return mb_substr($clean, 0, 80) . '.ics';
    }
}
