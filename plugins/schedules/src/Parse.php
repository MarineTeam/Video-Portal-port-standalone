<?php

declare(strict_types=1);

namespace MarineTeam\Plugins\Schedules;

/**
 * Reading a rota out of a spreadsheet (the original's lib/sheets/parse.ts).
 *
 * Two layouts are understood: `Date | Names`, with everybody in one cell,
 * and `Date | Devin | Cindy | …`, with a column each marked with a tick. A
 * cell with other text in it doubles as the job. Columns obviously not
 * people — Notes, Week, Location, Time — are skipped, so a sheet with a
 * notes column doesn't acquire a person called Notes.
 *
 * Nothing here throws: a row nobody can read is skipped and reported, so
 * one bad row never aborts an import.
 */
final class Parse
{
    public const DATE_NAMES = 'DATE_NAMES';
    public const NAME_COLUMNS = 'NAME_COLUMNS';
    public const FORMATS = [self::DATE_NAMES, self::NAME_COLUMNS];

    /** Headers that are never a person, however they are capitalised. */
    public const NOT_PEOPLE = ['date', 'day', 'week', 'notes', 'note', 'location', 'where', 'time', 'title', 'event', 'comments', 'month', 'year', 'no', 'number', 'id'];
    /** What counts as a tick. */
    public const TICKS = ['x', '×', '✓', '✔', 'y', 'yes', 'on', '1', 'true', '*'];
    /** And what counts as an explicit no. */
    public const CROSSES = ['-', '–', '—', 'n', 'no', '0', 'false', 'off', 'n/a', 'na'];
    /** More rows than any church rota, and enough to stop a runaway sheet. */
    public const MAX_ROWS = 2000;

    /**
     * A column letter as an index: A is 0, AA is 26.
     */
    public static function columnIndex(string $letter): ?int
    {
        $text = strtoupper(trim($letter));
        if ($text === '' || !preg_match('/^[A-Z]+$/', $text)) {
            return null;
        }
        $index = 0;
        foreach (str_split($text) as $char) {
            $index = $index * 26 + (ord($char) - 64);
        }
        return $index - 1;
    }

    /**
     * Where a column is: a header match wins over a letter, and the letter
     * is what is left when no header matches.
     *
     * @param list<string> $header
     */
    public static function resolveColumn(array $header, ?string $wanted): ?int
    {
        if ($wanted === null || trim($wanted) === '') {
            return null;
        }
        $needle = Names::normalizeName($wanted);
        foreach ($header as $i => $cell) {
            if ($needle !== '' && Names::normalizeName((string) $cell) === $needle) {
                return $i;
            }
        }
        return self::columnIndex($wanted);
    }

    /**
     * Reads a sheet into events and a list of what was skipped and why.
     *
     * @param list<list<mixed>> $rows as the sheet gives them
     * @param array<string, mixed> $config
     * @return array{events: list<array<string, mixed>>, skipped: list<array{row: int, reason: string}>, truncated: bool}
     */
    public static function parse(array $rows, array $config = []): array
    {
        $format = (string) ($config['format'] ?? self::DATE_NAMES);
        $headerRow = (int) ($config['headerRow'] ?? ($format === self::NAME_COLUMNS ? 1 : 0));
        $maxRows = min(self::MAX_ROWS, (int) ($config['maxRows'] ?? self::MAX_ROWS));
        $truncated = count($rows) > $maxRows + $headerRow;
        $rows = array_slice($rows, 0, $maxRows + $headerRow);
        $header = $headerRow > 0 ? array_map(fn ($c) => (string) $c, (array) ($rows[$headerRow - 1] ?? [])) : [];
        $body = array_slice($rows, $headerRow);
        if ($body === []) {
            return ['events' => [], 'skipped' => [['row' => 0, 'reason' => 'The sheet is empty.']], 'truncated' => false];
        }
        $out = $format === self::NAME_COLUMNS
            ? self::nameColumns($header, $body, $headerRow, $config)
            : self::dateNames($header, $body, $headerRow, $config);
        $out['truncated'] = $truncated;
        if ($truncated) {
            $out['skipped'][] = ['row' => $maxRows + $headerRow, 'reason' => "The sheet is longer than $maxRows rows; the rest was left."];
        }
        return $out;
    }

    /**
     * `Date | Names`: everybody in one cell.
     *
     * @param list<string> $header
     * @param list<list<mixed>> $body
     * @param array<string, mixed> $config
     * @return array{events: list<array<string, mixed>>, skipped: list<array{row: int, reason: string}>}
     */
    private static function dateNames(array $header, array $body, int $headerRow, array $config): array
    {
        $dateAt = self::resolveColumn($header, (string) ($config['dateColumn'] ?? 'A')) ?? 0;
        $namesAt = self::resolveColumn($header, (string) ($config['namesColumn'] ?? 'B')) ?? 1;
        if ($header !== [] && self::resolveColumn($header, (string) ($config['dateColumn'] ?? '')) === null && ($config['dateColumn'] ?? '') !== '') {
            // Fell through to the letter; that is fine.
        }
        $optional = [
            'title' => self::resolveColumn($header, $config['titleColumn'] ?? null),
            'notes' => self::resolveColumn($header, $config['notesColumn'] ?? null),
            'location' => self::resolveColumn($header, $config['locationColumn'] ?? null),
            'time' => self::resolveColumn($header, $config['timeColumn'] ?? null),
        ];
        $events = [];
        $skipped = [];
        $seenDates = [];
        $anyDate = false;
        foreach ($body as $i => $row) {
            $number = $headerRow + $i + 1;
            $cells = array_values((array) $row);
            $dateCell = $cells[$dateAt] ?? '';
            $namesCell = (string) ($cells[$namesAt] ?? '');
            if (self::blankRow($cells)) {
                continue;
            }
            $date = Dates::parseSheetDate($dateCell, $config);
            if ($date['date'] === null) {
                $skipped[] = ['row' => $number, 'reason' => $date['problem'] === Dates::EMPTY ? 'No date in this row.' : 'This date could not be read: ' . self::show($dateCell)];
                continue;
            }
            $anyDate = true;
            if (!self::inWindow($date['date'], $config)) {
                $skipped[] = ['row' => $number, 'reason' => 'Outside the window this schedule imports.'];
                continue;
            }
            $names = [];
            foreach (Names::splitNames($namesCell, isset($config['separators']) ? array_map('strval', (array) $config['separators']) : null) as $name) {
                if (Names::isPlausibleName($name)) {
                    $names[] = $name;
                } else {
                    $skipped[] = ['row' => $number, 'reason' => 'Not a name: ' . self::show($name)];
                }
            }
            if ($names === [] && !(bool) ($config['keepUnassigned'] ?? false)) {
                $skipped[] = ['row' => $number, 'reason' => 'Nobody is listed.'];
                continue;
            }
            $seenDates[$date['date']] = ($seenDates[$date['date']] ?? 0) + 1;
            $events[] = self::event($date['date'], $names, $cells, $optional, $number, $seenDates[$date['date']]);
        }
        // A whole sheet with nothing readable in its date column is a
        // mapping mistake, and is said so rather than importing nothing
        // silently. One row that happens to be missing a date is not.
        if (!$anyDate && $events === [] && count($skipped) > 1) {
            $skipped[] = ['row' => 0, 'reason' => 'No date column was found, so nothing could be imported.'];
        }
        return ['events' => $events, 'skipped' => $skipped];
    }

    /**
     * `Date | Devin | Cindy | …`: a column each, marked with a tick.
     *
     * @param list<string> $header
     * @param list<list<mixed>> $body
     * @param array<string, mixed> $config
     * @return array{events: list<array<string, mixed>>, skipped: list<array{row: int, reason: string}>}
     */
    private static function nameColumns(array $header, array $body, int $headerRow, array $config): array
    {
        if ($header === []) {
            return ['events' => [], 'skipped' => [['row' => 0, 'reason' => 'This layout needs a header row of names.']]];
        }
        $dateAt = self::resolveColumn($header, (string) ($config['dateColumn'] ?? 'A')) ?? 0;
        $optional = [
            'title' => self::resolveColumn($header, $config['titleColumn'] ?? null),
            'notes' => self::resolveColumn($header, $config['notesColumn'] ?? null),
            'location' => self::resolveColumn($header, $config['locationColumn'] ?? null),
            'time' => self::resolveColumn($header, $config['timeColumn'] ?? null),
        ];
        $skipped = [];
        // Which columns are people, and which are obviously not.
        $people = [];
        $byKey = [];
        foreach ($header as $i => $cell) {
            $text = trim((string) $cell);
            if ($i === $dateAt || in_array($i, array_filter($optional, fn ($v) => $v !== null), true)) {
                continue;
            }
            if ($text === '' || in_array(mb_strtolower($text), self::NOT_PEOPLE, true) || !Names::isPlausibleName($text)) {
                continue;
            }
            $key = Names::normalizeName($text);
            if (isset($byKey[$key])) {
                $skipped[] = ['row' => $headerRow, 'reason' => 'Two columns for ' . Names::toDisplayName($text) . '; they were read as one person.'];
                $people[$i] = $byKey[$key];
                continue;
            }
            $byKey[$key] = Names::toDisplayName($text);
            $people[$i] = $byKey[$key];
        }
        $events = [];
        $seenDates = [];
        foreach ($body as $i => $row) {
            $number = $headerRow + $i + 1;
            $cells = array_values((array) $row);
            if (self::blankRow($cells)) {
                continue;
            }
            $date = Dates::parseSheetDate($cells[$dateAt] ?? '', $config);
            if ($date['date'] === null) {
                $skipped[] = ['row' => $number, 'reason' => $date['problem'] === Dates::EMPTY ? 'No date in this row.' : 'This date could not be read: ' . self::show($cells[$dateAt] ?? '')];
                continue;
            }
            if (!self::inWindow($date['date'], $config)) {
                $skipped[] = ['row' => $number, 'reason' => 'Outside the window this schedule imports.'];
                continue;
            }
            $names = [];
            $roles = [];
            foreach ($people as $at => $name) {
                // A row shorter than the header is not an error.
                $mark = trim((string) ($cells[$at] ?? ''));
                if ($mark === '' || in_array(mb_strtolower($mark), self::CROSSES, true)) {
                    continue;
                }
                $names[$name] = true;
                if (!in_array(mb_strtolower($mark), self::TICKS, true)) {
                    // Free text in a person's column is the job they are doing.
                    $roles[$name] = $mark;
                }
            }
            if ($names === [] && !(bool) ($config['keepUnassigned'] ?? false)) {
                $skipped[] = ['row' => $number, 'reason' => 'Nobody is marked in this row.'];
                continue;
            }
            $seenDates[$date['date']] = ($seenDates[$date['date']] ?? 0) + 1;
            $event = self::event($date['date'], array_keys($names), $cells, $optional, $number, $seenDates[$date['date']]);
            $event['roles'] = $roles;
            $events[] = $event;
        }
        return ['events' => $events, 'skipped' => $skipped];
    }

    /**
     * @param list<string> $names
     * @param list<mixed> $cells
     * @param array<string, ?int> $optional
     * @return array<string, mixed>
     */
    private static function event(string $date, array $names, array $cells, array $optional, int $row, int $nth): array
    {
        $at = static function (?int $index) use ($cells): ?string {
            if ($index === null) {
                return null;
            }
            $value = trim((string) ($cells[$index] ?? ''));
            return $value === '' ? null : $value;
        };
        return [
            // Two events on the same day are told apart, so re-syncing
            // updates rows rather than duplicating them.
            'externalId' => $date . ($nth > 1 ? '#' . $nth : ''),
            'date' => $date,
            'people' => array_values($names),
            'roles' => [],
            'title' => $at($optional['title']),
            'notes' => $at($optional['notes']),
            'location' => $at($optional['location']),
            'startTime' => Dates::parseSheetTime($at($optional['time']) ?? ''),
            'sourceRow' => $row,
        ];
    }

    /** @param list<mixed> $cells */
    private static function blankRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }
        return true;
    }

    /** @param array<string, mixed> $config */
    private static function inWindow(string $date, array $config): bool
    {
        $from = $config['importFrom'] ?? null;
        $to = $config['importTo'] ?? null;
        return (!is_string($from) || $date >= $from) && (!is_string($to) || $date <= $to);
    }

    private static function show(mixed $value): string
    {
        return '"' . mb_substr(trim((string) (is_scalar($value) ? $value : '')), 0, 40) . '"';
    }

    /**
     * A hash of what a sheet came to, so a sync that finds nothing changed
     * upstream does no writes. Name casing and the order people are listed
     * in are not changes.
     *
     * @param list<array<string, mixed>> $events
     */
    public static function fingerprintEvents(array $events): string
    {
        $rows = [];
        foreach ($events as $event) {
            $names = array_map([Names::class, 'normalizeName'], (array) ($event['people'] ?? []));
            sort($names);
            $rows[] = implode('|', [
                (string) ($event['date'] ?? ''),
                (string) ($event['externalId'] ?? ''),
                implode(',', $names),
                (string) ($event['title'] ?? ''),
                (string) ($event['notes'] ?? ''),
                (string) ($event['location'] ?? ''),
                (string) ($event['startTime'] ?? ''),
            ]);
        }
        sort($rows);
        return hash('sha256', implode("\n", $rows));
    }
}
