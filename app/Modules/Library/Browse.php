<?php

declare(strict_types=1);

namespace App\Modules\Library;

use App\Core\App;
use App\Core\Cache;
use App\Core\Db;
use App\Services\Video\VideoProvider;
use App\Services\Video\VideoRef;

/**
 * What the public pages list, always through ContentAccess: a reader only
 * ever sees what they may open, and a visitor never learns that
 * members-only content exists. Every list sorts pinned first, then by
 * position (what the admin's ↑/↓ set), then by name.
 */
final class Browse
{
    public function __construct(private readonly App $app, private readonly ContentAccess $access)
    {
    }

    public static function for(App $app): self
    {
        return Cache::memo('browse', fn () => new self($app, ContentAccess::for($app)));
    }

    public function access(): ContentAccess
    {
        return $this->access;
    }

    private function db(): Db
    {
        return $this->app->db();
    }

    // Categories -------------------------------------------------------------------------

    /**
     * The categories under $parentId (null: the top level) this reader may open.
     *
     * @return list<array<string, mixed>>
     */
    public function categories(?string $parentId): array
    {
        $out = [];
        foreach ($this->access->categories() as $c) {
            if ($c['parent_id'] !== $parentId || !Visibility::isVisible($c, $this->access->now()) || $this->access->category($c) !== ContentAccess::OK) {
                continue;
            }
            // A category shows only on the way to something the reader can open.
            $out[] = $c;
        }
        usort($out, fn ($a, $b) => [(int) !$a['pinned'], (int) $a['position'], mb_strtolower((string) $a['name'])] <=> [(int) !$b['pinned'], (int) $b['position'], mb_strtolower((string) $b['name'])]);
        return $out;
    }

    /** @return array<string, mixed>|null */
    public function categoryBySlug(string $slug): ?array
    {
        return $this->db()->one('SELECT * FROM {{categories}} WHERE slug = ? AND deleted_at IS NULL', [$slug]);
    }

    /**
     * The category's ancestors, top first, for breadcrumbs.
     *
     * @return list<array<string, mixed>>
     */
    public function trail(?string $categoryId): array
    {
        $all = $this->access->categories();
        $out = [];
        foreach (array_reverse($this->access->tree()->chain($categoryId)) as $id) {
            if (isset($all[$id])) {
                $out[] = $all[$id];
            }
        }
        return $out;
    }

    // Series -----------------------------------------------------------------------------

    /**
     * Series in a category (null: those in none), each with videoCount,
     * fileCount and a thumbnail.
     *
     * @return list<array<string, mixed>>
     */
    public function series(?string $categoryId, ?int $limit = null): array
    {
        [$where, $params] = $this->access->seriesListSql('s');
        $where .= $categoryId === null ? ' AND s.category_id IS NULL' : ' AND s.category_id = ?';
        if ($categoryId !== null) {
            $params[] = $categoryId;
        }
        return $this->withCounts($this->db()->all(
            "SELECT s.* FROM {{series}} s WHERE $where ORDER BY s.pinned DESC, s.position, s.title" . ($limit !== null ? ' LIMIT ' . $limit : ''),
            $params,
        ));
    }

    /**
     * Series by an arbitrary extra condition (a tag, recency).
     *
     * @param list<mixed> $extraParams
     * @return list<array<string, mixed>>
     */
    public function seriesWhere(string $extra, array $extraParams, string $order = 's.pinned DESC, s.position, s.title', int $limit = 100): array
    {
        [$where, $params] = $this->access->seriesListSql('s');
        return $this->withCounts($this->db()->all(
            "SELECT s.* FROM {{series}} s WHERE $where AND ($extra) ORDER BY $order LIMIT " . max(1, $limit),
            [...$params, ...$extraParams],
        ));
    }

    /** @return array<string, mixed>|null the series, or the one an old slug now names */
    public function seriesBySlug(string $slug, bool &$aliased = false): ?array
    {
        return $this->bySlug('series', 'SERIES', $slug, $aliased);
    }

    /** @return array<string, mixed>|null */
    public function videoBySlug(string $slug, bool &$aliased = false): ?array
    {
        return $this->bySlug('videos', 'VIDEO', $slug, $aliased);
    }

    /** @return array<string, mixed>|null */
    private function bySlug(string $table, string $aliasType, string $slug, bool &$aliased): ?array
    {
        $row = $this->db()->one("SELECT * FROM {{{$table}}} WHERE slug = ? AND deleted_at IS NULL", [$slug]);
        if ($row !== null) {
            return $row;
        }
        $target = $this->db()->value('SELECT target_id FROM {{slug_aliases}} WHERE type = ? AND old_slug = ?', [$aliasType, $slug]);
        if ($target === null) {
            return null;
        }
        $aliased = true;
        return $this->db()->one("SELECT * FROM {{{$table}}} WHERE id = ? AND deleted_at IS NULL", [$target]);
    }

    /**
     * @param list<array<string, mixed>> $series
     * @return list<array<string, mixed>>
     */
    private function withCounts(array $series): array
    {
        if ($series === []) {
            return [];
        }
        $ids = array_map(fn ($s) => (string) $s['id'], $series);
        $in = implode(', ', array_fill(0, count($ids), '?'));
        [$vWhere, $vParams] = $this->access->videoListSql('v', 's');
        $videos = [];
        foreach ($this->db()->all(
            "SELECT v.series_id, COUNT(*) AS n, MIN(CONCAT(LPAD(v.position, 10, '0'), v.id)) AS first_key FROM {{videos}} v JOIN {{series}} s ON s.id = v.series_id
             WHERE v.series_id IN ($in) AND $vWhere GROUP BY v.series_id",
            [...$ids, ...$vParams],
        ) as $r) {
            $videos[(string) $r['series_id']] = ['n' => (int) $r['n'], 'first' => substr((string) $r['first_key'], 10)];
        }
        [$fWhere, $fParams] = $this->access->fileListSql('f', 's');
        $files = array_column($this->db()->all(
            "SELECT f.series_id, COUNT(*) AS n FROM {{file_assets}} f JOIN {{series}} s ON s.id = f.series_id WHERE f.series_id IN ($in) AND $fWhere GROUP BY f.series_id",
            [...$ids, ...$fParams],
        ), 'n', 'series_id');
        $firsts = array_values(array_filter(array_column($videos, 'first')));
        $thumbs = [];
        if ($firsts !== []) {
            foreach ($this->db()->all('SELECT * FROM {{videos}} WHERE id IN (' . implode(', ', array_fill(0, count($firsts), '?')) . ')', $firsts) as $v) {
                $thumbs[(string) $v['series_id']] = $this->thumbnail($v);
            }
        }
        foreach ($series as &$s) {
            $id = (string) $s['id'];
            $s['video_count'] = $videos[$id]['n'] ?? 0;
            $s['file_count'] = (int) ($files[$id] ?? 0);
            $cover = (string) ($s['cover_image_url'] ?? '');
            $s['thumbnail'] = $cover !== '' ? $cover : ($thumbs[$id] ?? null);
        }
        return $series;
    }

    // Videos and files --------------------------------------------------------------------

    /**
     * Videos in a series, or standing alone in a category ($seriesId null).
     *
     * @return list<array<string, mixed>>
     */
    public function videos(?string $seriesId, ?string $categoryId = null): array
    {
        [$where, $params] = $this->access->videoListSql('v', 's');
        if ($seriesId !== null) {
            $where .= ' AND v.series_id = ?';
            $params[] = $seriesId;
        } else {
            $where .= ' AND v.series_id IS NULL AND v.category_id ' . ($categoryId === null ? 'IS NULL' : '= ?');
            if ($categoryId !== null) {
                $params[] = $categoryId;
            }
        }
        return $this->decorate($this->db()->all(
            "SELECT v.* FROM {{videos}} v LEFT JOIN {{series}} s ON s.id = v.series_id WHERE $where ORDER BY v.position, v.created_at, v.id",
            $params,
        ));
    }

    /**
     * Videos by an arbitrary extra condition on v (a speaker, a book, recency).
     *
     * @param list<mixed> $extraParams
     * @param list<mixed> $joinParams
     * @return list<array<string, mixed>>
     */
    public function videosWhere(string $extra, array $extraParams, string $order = 'v.created_at DESC', int $limit = 100, string $join = '', array $joinParams = []): array
    {
        [$where, $params] = $this->access->videoListSql('v', 's');
        return $this->decorate($this->db()->all(
            "SELECT v.*, s.title AS series_title, s.slug AS series_slug FROM {{videos}} v LEFT JOIN {{series}} s ON s.id = v.series_id $join
             WHERE $where AND ($extra) ORDER BY $order LIMIT " . max(1, $limit),
            [...$joinParams, ...$params, ...$extraParams],
        ));
    }

    /** @return list<array<string, mixed>> */
    public function files(?string $seriesId, ?string $categoryId = null): array
    {
        [$where, $params] = $this->access->fileListSql('f', 's');
        if ($seriesId !== null) {
            $where .= ' AND f.series_id = ?';
            $params[] = $seriesId;
        } else {
            $where .= ' AND f.series_id IS NULL AND f.category_id ' . ($categoryId === null ? 'IS NULL' : '= ?');
            if ($categoryId !== null) {
                $params[] = $categoryId;
            }
        }
        return $this->db()->all(
            "SELECT f.* FROM {{file_assets}} f LEFT JOIN {{series}} s ON s.id = f.series_id WHERE $where ORDER BY f.position, f.page_number, f.title",
            $params,
        );
    }

    /**
     * Each video with its thumbnail, and for a signed-in reader their
     * progress (position_seconds, completed).
     *
     * @param list<array<string, mixed>> $videos
     * @return list<array<string, mixed>>
     */
    public function decorate(array $videos): array
    {
        if ($videos === []) {
            return [];
        }
        $progress = [];
        $userId = $this->access->viewer()->id();
        if ($userId !== null) {
            $ids = array_map(fn ($v) => (string) $v['id'], $videos);
            foreach ($this->db()->all(
                'SELECT video_id, position_seconds, completed FROM {{watch_progresses}} WHERE user_id = ? AND video_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')',
                [$userId, ...$ids],
            ) as $p) {
                $progress[(string) $p['video_id']] = $p;
            }
        }
        foreach ($videos as &$v) {
            $v['thumbnail'] = $this->thumbnail($v);
            $p = $progress[(string) $v['id']] ?? null;
            $v['progress_seconds'] = $p !== null ? (int) $p['position_seconds'] : null;
            $v['watched'] = $p !== null && (bool) $p['completed'];
        }
        return $videos;
    }

    /** @param array<string, mixed> $video */
    public function thumbnail(array $video): ?string
    {
        $given = (string) ($video['external_thumbnail_url'] ?? '');
        if ($given !== '') {
            return $given;
        }
        $provider = $this->app->services()->get('video', (string) $video['provider']);
        if (!$provider instanceof VideoProvider) {
            return null;
        }
        try {
            return $provider->thumbnailUrl(VideoRef::fromRow($video), isset($video['thumbnail_file_name']) ? (string) $video['thumbnail_file_name'] : null);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The videos a signed-in reader can't open yet because the series asks
     * for watching in order.
     *
     * @param array<string, mixed> $series
     * @param list<array<string, mixed>> $videos
     * @return list<string>
     */
    public function lockedIds(array $series, array $videos): array
    {
        $userId = $this->access->viewer()->id();
        $sequential = (bool) $series['require_sequential'];
        return Visibility::sequentialLockedVideoIds($userId !== null, $sequential, $videos, fn () => array_map(
            'strval',
            array_keys(array_filter(array_column($videos, 'watched', 'id'))),
        ));
    }

    /**
     * "Continue watching": the reader's unfinished videos, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function continueWatching(int $limit = 12): array
    {
        $userId = $this->access->viewer()->id();
        if ($userId === null) {
            return [];
        }
        return $this->videosWhere(
            'w.completed = 0 AND w.position_seconds > 0',
            [],
            'w.updated_at DESC',
            $limit,
            'JOIN {{watch_progresses}} w ON w.video_id = v.id AND w.user_id = ?',
            [$userId],
        );
    }
}
