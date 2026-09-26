<?php

declare(strict_types=1);

namespace App\Modules\Analytics;

use App\Core\Db;

/**
 * What the congregation actually watched and sang, over a window.
 *
 * Everything here is counted from logs something else already keeps — the
 * view events behind the Trending row, the watch-progress heartbeats behind
 * "resume where you left off", the hymn openings the reader records. Nothing
 * on this page adds tracking of its own, which is the whole design: a church
 * should not have to be watched twice to be counted once.
 */
final class Analytics
{
    /** The windows offered, in days. Anything else is read as 30. */
    public const WINDOWS = [7, 30, 90];
    public const TOP = 10;

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * The window a request asked for, or a month.
     *
     * A plain cast would read "7; DROP TABLE" as 7. Nothing bad follows from
     * that here — the number only ever becomes a date — but a page that
     * quietly makes sense of nonsense teaches everybody reading it that the
     * next such cast is fine too.
     */
    public static function days(mixed $asked): int
    {
        if (is_int($asked) && in_array($asked, self::WINDOWS, true)) {
            return $asked;
        }
        if (is_string($asked) && preg_match('/^\d+$/', $asked) === 1 && in_array((int) $asked, self::WINDOWS, true)) {
            return (int) $asked;
        }
        return 30;
    }

    private function since(int $days): string
    {
        return gmdate('Y-m-d H:i:s', time() - $days * 86400);
    }

    /**
     * Everything the page shows, for one window.
     *
     * @return array{days: int, since: string, views: int, viewers: int, series: list<array<string, mixed>>, videos: list<array<string, mixed>>, hymns: list<array<string, mixed>>}
     */
    public function report(int $days): array
    {
        return [
            'days' => $days,
            'since' => $this->since($days),
            'views' => $this->views($days),
            'viewers' => $this->viewers($days),
            'series' => $this->topSeries($days),
            'videos' => $this->topVideos($days),
            'hymns' => $this->topHymns($days),
        ];
    }

    public function views(int $days): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM {{view_events}} WHERE created_at > ?', [$this->since($days)]);
    }

    /**
     * People, as far as this can honestly say: a signed-in member counts
     * once, and everybody else is counted by the hash of their address,
     * which is one household or one office rather than one person.
     */
    public function viewers(int $days): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(DISTINCT COALESCE(user_id, ip_hash)) FROM {{view_events}} WHERE created_at > ?',
            [$this->since($days)],
        );
    }

    /** @return list<array<string, mixed>> */
    public function topSeries(int $days): array
    {
        $rows = $this->db->all(
            'SELECT COALESCE(e.series_id, v.series_id) AS id, COUNT(*) AS views
               FROM {{view_events}} e LEFT JOIN {{videos}} v ON v.id = e.video_id
              WHERE e.created_at > ? AND COALESCE(e.series_id, v.series_id) IS NOT NULL
              GROUP BY id ORDER BY views DESC, id LIMIT ' . self::TOP,
            [$this->since($days)],
        );
        return $this->named($rows, 'series', 'title');
    }

    /**
     * The top videos, each with how far through people got.
     *
     * The rate is the share of this window's progress rows for the video
     * that are marked finished. A video nobody's player reported on is left
     * without one rather than shown as 0%: "nobody finished it" and "nobody
     * told us" are different answers, and only one of them is bad news.
     *
     * @return list<array<string, mixed>>
     */
    public function topVideos(int $days): array
    {
        $since = $this->since($days);
        $rows = $this->db->all(
            'SELECT e.video_id AS id, COUNT(*) AS views FROM {{view_events}} e
              WHERE e.created_at > ? AND e.video_id IS NOT NULL
              GROUP BY e.video_id ORDER BY views DESC, e.video_id LIMIT ' . self::TOP,
            [$since],
        );
        $rows = $this->named($rows, 'videos', 'title');
        foreach ($rows as &$row) {
            $progress = $this->db->one(
                'SELECT COUNT(*) AS rows_seen, SUM(completed) AS finished FROM {{watch_progresses}}
                  WHERE video_id = ? AND updated_at > ?',
                [$row['id'], $since],
            );
            $seen = (int) ($progress['rows_seen'] ?? 0);
            $row['watchedThrough'] = $seen === 0 ? null : (int) round(((int) ($progress['finished'] ?? 0)) / $seen * 100);
        }
        return $rows;
    }

    /**
     * The hymns opened most often, named by the book's own contents.
     *
     * A hymn that is a file of its own is named by that file; one inside a
     * scanned book is named by the entry its number falls inside, so a whole
     * book does not read as one much-sung hymn called "Hymnal".
     *
     * @return list<array<string, mixed>>
     */
    public function topHymns(int $days): array
    {
        $rows = $this->db->all(
            'SELECT l.file_id, l.number, COUNT(*) AS lookups
               FROM {{hymn_lookups}} l
              WHERE l.created_at > ?
              GROUP BY l.file_id, l.number
              ORDER BY lookups DESC, l.number IS NULL, l.number LIMIT ' . self::TOP,
            [$this->since($days)],
        );
        $out = [];
        foreach ($rows as $row) {
            $fileId = (string) $row['file_id'];
            // Null for a hymn that is its own file: the file says which it is.
            $number = $row['number'] === null ? null : (int) $row['number'];
            $out[] = [
                'fileId' => $fileId,
                'number' => $number,
                'title' => $this->hymnTitle($fileId, $number),
                'lookups' => (int) $row['lookups'],
            ];
        }
        return $out;
    }

    /** The book's own entry for a number, or the file's title. */
    private function hymnTitle(string $fileId, ?int $number): string
    {
        if ($number !== null) {
            $hymn = $this->db->one(
                'SELECT title FROM {{book_hymns}} WHERE file_id = ? AND number = ? LIMIT 1',
                [$fileId, $number],
            );
            if ($hymn !== null) {
                return (string) $hymn['title'];
            }
        }
        $file = $this->db->one('SELECT title FROM {{file_assets}} WHERE id = ?', [$fileId]);
        if ($file === null) {
            return $number === null ? 'A hymn since deleted' : "Hymn $number";
        }
        // A hymn that is its own file is named by the file; a number in a
        // book nobody has indexed can only be given as a number beside it.
        return $number === null ? (string) $file['title'] : (string) $file['title'] . ", no. $number";
    }

    /**
     * Attach the titles, keeping the order the counts gave and dropping a
     * row whose thing has since been deleted.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function named(array $rows, string $table, string $titleColumn): array
    {
        $ids = array_values(array_filter(array_map(fn (array $r) => (string) $r['id'], $rows), fn (string $id) => $id !== ''));
        if ($ids === []) {
            return [];
        }
        $titles = [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        foreach ($this->db->all("SELECT id, $titleColumn AS title, slug FROM {{{$table}}} WHERE id IN ($in)", $ids) as $row) {
            $titles[(string) $row['id']] = ['title' => (string) $row['title'], 'slug' => (string) $row['slug']];
        }
        $out = [];
        foreach ($rows as $row) {
            $id = (string) $row['id'];
            if (!isset($titles[$id])) {
                continue;
            }
            $out[] = ['id' => $id, 'title' => $titles[$id]['title'], 'slug' => $titles[$id]['slug'], 'views' => (int) $row['views']];
        }
        return $out;
    }
}
