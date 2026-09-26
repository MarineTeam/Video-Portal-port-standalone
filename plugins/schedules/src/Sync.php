<?php

declare(strict_types=1);

namespace MarineTeam\Plugins\Schedules;

use App\Core\Db;

/**
 * Importing a sheet into the events people read.
 *
 * Three rules the calendar app learned the hard way, and this keeps:
 *
 *  - A failed sync deletes nothing. If Google is unreachable, or the sheet
 *    has been renamed, what was imported before stays exactly as it was —
 *    an empty rota on a Sunday morning is worse than a stale one.
 *  - A payload that has not changed upstream does no writes at all. The
 *    fingerprint ignores name casing and the order people are listed in,
 *    because neither is a change to anybody's week.
 *  - A row nobody can read is skipped and reported, never guessed at, and
 *    one bad row never aborts the import (that is Parse's half of it).
 */
final class Sync
{
    public const OK = 'OK';
    public const FAILED = 'FAILED';
    public const UNCHANGED = 'UNCHANGED';
    public const NEVER = 'NEVER';

    /** Where an imported event came from, as against one typed in here. */
    public const ORIGIN_SHEET = 'SHEET';
    public const ORIGIN_WEB = 'WEB';

    /**
     * Write a parsed sheet into one schedule's events.
     *
     * @param list<array<string, mixed>> $events as Parse gives them
     * @return array{status: string, imported: int, updated: int, removed: int, people: int}
     */
    public static function apply(Db $db, string $scheduleId, array $events, string $fingerprint, ?string $lastHash): array
    {
        if ($lastHash !== null && $lastHash === $fingerprint) {
            return ['status' => self::UNCHANGED, 'imported' => 0, 'updated' => 0, 'removed' => 0, 'people' => 0];
        }
        $counts = ['status' => self::OK, 'imported' => 0, 'updated' => 0, 'removed' => 0, 'people' => 0];
        $db->transaction(function () use ($db, $scheduleId, $events, &$counts): void {
            $seen = [];
            $from = null;
            $to = null;
            foreach ($events as $event) {
                $externalId = (string) ($event['externalId'] ?? '');
                if ($externalId === '') {
                    continue;
                }
                $seen[$externalId] = true;
                $date = (string) $event['date'];
                $from = $from === null || $date < $from ? $date : $from;
                $to = $to === null || $date > $to ? $date : $to;
                $counts[self::write($db, $scheduleId, $event, $externalId, $counts['people']) ? 'imported' : 'updated']++;
            }
            // Only inside the stretch the sheet covered: a schedule whose
            // window moved on has history behind it that nothing deleted,
            // and an import is not the place to lose it.
            if ($from !== null && $to !== null) {
                $rows = $db->all(
                    'SELECT id, external_id FROM {{calendar_events}}
                     WHERE schedule_id = ? AND origin = ? AND deleted_at IS NULL AND date BETWEEN ? AND ?',
                    [$scheduleId, self::ORIGIN_SHEET, $from, $to],
                );
                foreach ($rows as $row) {
                    if (!isset($seen[(string) $row['external_id']])) {
                        $db->update('calendar_events', ['deleted_at' => Db::now()], ['id' => $row['id']]);
                        $counts['removed']++;
                    }
                }
            }
        });
        return $counts;
    }

    /**
     * One event, in place if this sheet has named it before.
     *
     * @param array<string, mixed> $event
     * @return bool whether it is new
     */
    private static function write(Db $db, string $scheduleId, array $event, string $externalId, int &$people): bool
    {
        $fields = [
            'date' => (string) $event['date'],
            'end_date' => $event['endDate'] ?? null,
            'all_day' => ($event['startTime'] ?? null) === null ? 1 : 0,
            'start_time' => $event['startTime'] ?? null,
            'end_time' => $event['endTime'] ?? null,
            'title' => $event['title'] ?? null,
            'notes' => $event['notes'] ?? null,
            'location' => $event['location'] ?? null,
            'status' => Logic::CONFIRMED,
            'origin' => self::ORIGIN_SHEET,
            'source_row' => $event['sourceRow'] ?? null,
            'deleted_at' => null,
        ];
        $existing = $db->one('SELECT id FROM {{calendar_events}} WHERE schedule_id = ? AND external_id = ?', [$scheduleId, $externalId]);
        $isNew = $existing === null;
        if ($isNew) {
            $eventId = $db->insert('calendar_events', $fields + ['schedule_id' => $scheduleId, 'external_id' => $externalId]);
        } else {
            $eventId = (string) $existing['id'];
            $db->update('calendar_events', $fields, ['id' => $eventId]);
        }
        self::writePeople($db, $eventId, $event, $people);
        return $isNew;
    }

    /** @param array<string, mixed> $event */
    private static function writePeople(Db $db, string $eventId, array $event, int &$made): void
    {
        $roles = (array) ($event['roles'] ?? []);
        $wanted = [];
        $position = 0;
        foreach ((array) ($event['people'] ?? []) as $i => $name) {
            $personId = People::resolve($db, (string) $name, false);
            if ($personId === null) {
                $personId = People::resolve($db, (string) $name);
                if ($personId === null) {
                    continue;
                }
                $made++;
            }
            $role = $roles[$i] ?? null;
            $wanted[$personId] = ['role' => is_string($role) && trim($role) !== '' ? trim($role) : null, 'position' => $position++];
        }
        foreach ($db->all('SELECT * FROM {{calendar_event_people}} WHERE event_id = ?', [$eventId]) as $row) {
            $personId = (string) $row['person_id'];
            if (!isset($wanted[$personId])) {
                $db->delete('calendar_event_people', ['id' => $row['id']]);
                continue;
            }
            $db->update('calendar_event_people', $wanted[$personId], ['id' => $row['id']]);
            unset($wanted[$personId]);
        }
        foreach ($wanted as $personId => $row) {
            try {
                $db->insert('calendar_event_people', $row + ['event_id' => $eventId, 'person_id' => $personId]);
            } catch (\Throwable $e) {
                if (!Db::isDuplicate($e)) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Whether a source is due, by its own interval.
     *
     * @param array<string, mixed> $source
     */
    public static function isDue(array $source, ?string $now = null): bool
    {
        $last = $source['last_synced_at'] ?? null;
        if (!is_string($last) || $last === '') {
            return true;
        }
        $minutes = max(1, (int) ($source['sync_interval_minutes'] ?? 60));
        $due = (new \DateTimeImmutable($last, new \DateTimeZone('UTC')))->modify("+$minutes minutes");
        return new \DateTimeImmutable($now ?? 'now', new \DateTimeZone('UTC')) >= $due;
    }

    /** @param array<string, mixed> $source */
    public static function parserConfig(array $source): array
    {
        $config = json_decode((string) ($source['parser_config'] ?? '{}'), true);
        $config = is_array($config) ? $config : [];
        if (($source['format'] ?? null) !== null && ($config['format'] ?? null) === null) {
            $config['format'] = (string) $source['format'];
        }
        return $config;
    }
}
