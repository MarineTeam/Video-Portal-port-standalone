<?php

declare(strict_types=1);

namespace MarineTeam\Plugins\Schedules;

use App\Core\Db;
use App\Core\Json;

/**
 * What the device keeps, and what has changed since it last asked.
 *
 * The calendar is the one saved thing here that keeps itself current: a few
 * kilobytes of text against a hymnal's forty megabytes, so opening the page
 * with a connection quietly folds in what is new rather than making somebody
 * press update to find out they are on for Sunday.
 *
 * Two things this payload deliberately does not try to say, because the
 * device works them out for itself (mergeSnapshot, in offline-calendar.js):
 * a schedule that was turned off takes its dates with it, though disabling
 * it touched no event row; and a day that has fallen out behind the window
 * is dropped, though nothing deleted it.
 */
final class Snapshot
{
    /** How far back and forward a saved calendar reaches. */
    public const DAYS_BACK = 30;
    public const DAYS_FORWARD = 365;

    /** A delta this large is not worth assembling; send the lot. */
    public const FULL_ABOVE = 500;

    /**
     * @param array{since?: ?string, signedIn?: bool, today?: ?string} $options
     * @return array<string, mixed>
     */
    public static function build(Db $db, array $options = []): array
    {
        $today = (string) ($options['today'] ?? gmdate('Y-m-d'));
        $signedIn = (bool) ($options['signedIn'] ?? false);
        // A signed-out sync is always a full, nameless snapshot: a copy
        // saved on a shared laptop while somebody was signed in stops
        // carrying their names the next time it updates, rather than
        // keeping them because no row happened to change.
        $since = $signedIn ? ($options['since'] ?? null) : null;
        $zone = new \DateTimeZone('UTC');
        $at = new \DateTimeImmutable($today, $zone);
        $from = $at->modify('-' . self::DAYS_BACK . ' days')->format('Y-m-d');
        $to = $at->modify('+' . self::DAYS_FORWARD . ' days')->format('Y-m-d');

        $schedules = array_map(
            fn (array $s) => [
                'id' => (string) $s['id'],
                'slug' => (string) $s['slug'],
                'name' => (string) $s['name'],
                'icon' => (string) $s['icon'],
                'color' => (string) $s['color'],
                'displayOrder' => (int) $s['display_order'],
            ],
            $db->all('SELECT * FROM {{schedules}} WHERE enabled = 1 AND deleted_at IS NULL ORDER BY display_order, name'),
        );
        $scheduleIds = array_map(fn (array $s) => $s['id'], $schedules);

        $full = $since === null || !self::isInstant($since);
        $events = $scheduleIds === [] ? [] : self::events($db, $scheduleIds, $from, $to, $full ? null : (string) $since);
        if (!$full && count($events) > self::FULL_ABOVE) {
            $full = true;
            $events = self::events($db, $scheduleIds, $from, $to, null);
        }

        $snapshot = [
            'full' => $full,
            'syncedAt' => Json::instant(Db::now()),
            'from' => $from,
            'to' => $to,
            'schedules' => $schedules,
            'people' => self::people($db),
            'events' => array_values(array_filter($events, fn (array $e) => $e['deletedAt'] === null)),
            'deleted' => array_values(array_map(
                fn (array $e) => (string) $e['id'],
                array_filter($events, fn (array $e) => $e['deletedAt'] !== null),
            )),
        ];
        return Visibility::visibleSnapshot($snapshot, $signedIn);
    }

    /**
     * @param list<string> $scheduleIds
     * @return list<array<string, mixed>>
     */
    private static function events(Db $db, array $scheduleIds, string $from, string $to, ?string $since): array
    {
        $in = implode(',', array_fill(0, count($scheduleIds), '?'));
        $params = [...$scheduleIds, $from, $to];
        $sql = "SELECT * FROM {{calendar_events}} WHERE schedule_id IN ($in) AND date BETWEEN ? AND ?";
        if ($since !== null) {
            // A row deleted since last time is reported, so the device can
            // drop it; a row deleted before last time was already dropped.
            $sql .= ' AND updated_at > ?';
            $params[] = Db::datetime(new \DateTimeImmutable($since, new \DateTimeZone('UTC')));
        } else {
            $sql .= ' AND deleted_at IS NULL';
        }
        $sql .= ' ORDER BY date, start_time LIMIT 5000';
        $rows = $db->all($sql, $params);
        $people = self::peopleByEvent($db, array_map(fn (array $r) => (string) $r['id'], $rows));
        return array_map(fn (array $r) => [
            'id' => (string) $r['id'],
            'scheduleId' => (string) $r['schedule_id'],
            'date' => (string) $r['date'],
            'endDate' => $r['end_date'],
            'allDay' => (bool) $r['all_day'],
            'startTime' => $r['start_time'],
            'endTime' => $r['end_time'],
            'title' => $r['title'],
            'notes' => $r['notes'],
            'location' => $r['location'],
            'status' => (string) $r['status'],
            'people' => $people[(string) $r['id']] ?? [],
            'updatedAt' => Json::instant((string) $r['updated_at']),
            'deletedAt' => Json::instant($r['deleted_at']),
        ], $rows);
    }

    /**
     * @param list<string> $eventIds
     * @return array<string, list<array<string, mixed>>>
     */
    public static function peopleByEvent(Db $db, array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($eventIds), '?'));
        $rows = $db->all(
            "SELECT ep.*, p.display_name FROM {{calendar_event_people}} ep JOIN {{people}} p ON p.id = ep.person_id
             WHERE ep.event_id IN ($in) AND p.deleted_at IS NULL ORDER BY ep.position, p.display_name",
            $eventIds,
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['event_id']][] = [
                'personId' => (string) $row['person_id'],
                'displayName' => (string) $row['display_name'],
                'role' => $row['role'],
            ];
        }
        return $out;
    }

    /** @return list<array<string, mixed>> */
    public static function people(Db $db): array
    {
        return array_map(fn (array $p) => [
            'id' => (string) $p['id'],
            'displayName' => (string) $p['display_name'],
            'active' => (bool) $p['active'],
        ], $db->all('SELECT * FROM {{people}} WHERE deleted_at IS NULL AND active = 1 ORDER BY display_name LIMIT 2000'));
    }

    private static function isInstant(mixed $value): bool
    {
        if (!is_string($value) || $value === '') {
            return false;
        }
        try {
            new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
