<?php

declare(strict_types=1);

namespace App\Modules\Library;

use App\Core\App;
use App\Core\Cache;
use App\Core\Db;

/**
 * Whether this reader may open a category, series, video or file, and the
 * SQL that keeps listings to what they may see (canViewSeries, canViewVideo,
 * canViewFile and publishedNow in the original).
 *
 * A decision is one of:
 *   ok       show it;
 *   login    members only, and nobody is signed in — the page shows a
 *            "sign in to view" gate rather than a 404, so a shared link
 *            still invites somebody in;
 *   denied   signed in, but restricted to other people;
 *   missing  unpublished, scheduled, trashed or hidden — it doesn't exist
 *            for readers (people who manage it see it, marked as a preview).
 *
 * Members-only is inherited: a video is members-only if it, its series or
 * any category above it says so. A restricted item (viewer groups or
 * people) is for those people and admins only, whatever members-only says.
 * A share link carrying a grant opens its series or video for whoever holds
 * it, account or not.
 */
final class ContentAccess
{
    public const OK = 'ok';
    public const LOGIN = 'login';
    public const DENIED = 'denied';
    public const MISSING = 'missing';

    private readonly \DateTimeImmutable $now;

    public function __construct(private readonly App $app, private readonly Viewer $viewer, ?\DateTimeImmutable $now = null)
    {
        $this->now = $now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public static function for(App $app): self
    {
        return Cache::memo('content-access', fn () => new self($app, Viewer::current($app)));
    }

    public function viewer(): Viewer
    {
        return $this->viewer;
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    private function db(): Db
    {
        return $this->app->db();
    }

    // What the whole request shares --------------------------------------------------

    /** @return array<string, array<string, mixed>> every category, by id */
    public function categories(): array
    {
        return Cache::memo('categories-all', function (): array {
            $out = [];
            foreach ($this->db()->all('SELECT id, name, slug, parent_id, member_only, hidden, published, publish_at, unpublish_at, deleted_at, download_enabled, require_sequential, hymnal_style, position, pinned, featured, cover_image_url FROM {{categories}}') as $row) {
                $out[(string) $row['id']] = $row;
            }
            return $out;
        });
    }

    public function tree(): CategoryTree
    {
        return CategoryTree::load($this->db());
    }

    /** Members-only somewhere on the way up from this category. */
    public function categoryIsMemberOnly(?string $categoryId): bool
    {
        $categories = $this->categories();
        foreach ($this->tree()->chain($categoryId) as $id) {
            if ((bool) ($categories[$id]['member_only'] ?? false)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> categories whose content is members-only, descendants included */
    public function memberOnlyCategoryIds(): array
    {
        return Cache::memo('member-only-categories', function (): array {
            $roots = array_keys(array_filter($this->categories(), fn ($c) => (bool) $c['member_only']));
            return $this->tree()->descendants(array_map('strval', $roots));
        });
    }

    /**
     * Restricted items and who they are for.
     *
     * @return array{series: array<string, array{groups: list<string>, users: list<string>}>, videos: array<string, array{groups: list<string>, users: list<string>}>}
     */
    public function restrictions(): array
    {
        return Cache::memo('viewer-restrictions', function (): array {
            $out = ['series' => [], 'videos' => []];
            foreach ([
                ['series', 'SELECT series_id AS item, group_id AS who FROM {{series_viewer_groups}}', 'groups'],
                ['series', 'SELECT series_id AS item, user_id AS who FROM {{series_viewers}}', 'users'],
                ['videos', 'SELECT video_id AS item, group_id AS who FROM {{video_viewer_groups}}', 'groups'],
                ['videos', 'SELECT video_id AS item, user_id AS who FROM {{video_viewers}}', 'users'],
            ] as [$kind, $sql, $field]) {
                foreach ($this->db()->all($sql) as $row) {
                    $out[$kind][(string) $row['item']] ??= ['groups' => [], 'users' => []];
                    $out[$kind][(string) $row['item']][$field][] = (string) $row['who'];
                }
            }
            return $out;
        });
    }

    /** @param array{groups: list<string>, users: list<string>} $who */
    private function isListed(array $who): bool
    {
        return $this->viewer->isAdmin
            || ($this->viewer->id() !== null && in_array($this->viewer->id(), $who['users'], true))
            || array_intersect($this->viewer->groupIds, $who['groups']) !== [];
    }

    /** Whether the reader manages this part of the library (and so may preview it). */
    public function manages(string $capability, ?string $categoryId, ?string $seriesId = null): bool
    {
        if ($this->viewer->user === null) {
            return false;
        }
        if ($this->viewer->isAdmin) {
            return true;
        }
        return $this->app->permissions()->has($this->viewer->user, $capability, ['categoryId' => $categoryId, 'seriesId' => $seriesId]);
    }

    // Decisions -----------------------------------------------------------------------

    /** @param array<string, mixed> $category */
    public function category(array $category): string
    {
        if (!Visibility::isVisible($category, $this->now) && !$this->manages('manage_categories', (string) $category['id'])) {
            return self::MISSING;
        }
        if ($this->categoryIsMemberOnly((string) $category['id']) && !$this->viewer->signedIn()) {
            return self::LOGIN;
        }
        return self::OK;
    }

    /** @param array<string, mixed> $series */
    public function series(array $series): string
    {
        $id = (string) $series['id'];
        $categoryId = $series['category_id'] !== null ? (string) $series['category_id'] : null;
        if (!Visibility::isVisible($series, $this->now) && !$this->manages('manage_series', $categoryId, $id)) {
            return self::MISSING;
        }
        return $this->seriesGate($series);
    }

    /**
     * The series' own gate, without its publish state: what a video inside it
     * inherits.
     *
     * @param array<string, mixed> $series
     */
    private function seriesGate(array $series): string
    {
        $id = (string) $series['id'];
        if ($this->viewer->hasSeriesGrant($id)) {
            return self::OK;
        }
        $restricted = $this->restrictions()['series'][$id] ?? null;
        if ($restricted !== null) {
            return $this->isListed($restricted) ? self::OK : ($this->viewer->signedIn() ? self::DENIED : self::LOGIN);
        }
        $memberOnly = (bool) $series['member_only'] || $this->categoryIsMemberOnly($series['category_id'] !== null ? (string) $series['category_id'] : null);
        return $memberOnly && !$this->viewer->signedIn() ? self::LOGIN : self::OK;
    }

    /**
     * @param array<string, mixed> $video
     * @param array<string, mixed>|null $series its series row, if it has one
     */
    public function video(array $video, ?array $series = null): string
    {
        $id = (string) $video['id'];
        $seriesId = $video['series_id'] !== null ? (string) $video['series_id'] : null;
        $categoryId = $video['category_id'] !== null ? (string) $video['category_id'] : ($series['category_id'] ?? null);
        $shown = Visibility::isVisible($video, $this->now) || Visibility::isPremiere($video, $this->now);
        if (!$shown && !$this->manages('manage_videos', $categoryId !== null ? (string) $categoryId : null, $seriesId)) {
            return self::MISSING;
        }
        if ($this->viewer->hasVideoGrant($id, $seriesId)) {
            return self::OK;
        }
        $restricted = $this->restrictions()['videos'][$id] ?? null;
        if ($restricted !== null) {
            return $this->isListed($restricted) ? self::OK : ($this->viewer->signedIn() ? self::DENIED : self::LOGIN);
        }
        if ($series !== null) {
            $gate = $this->seriesGate($series);
            if ($gate !== self::OK) {
                return $gate;
            }
        }
        $memberOnly = (bool) $video['member_only'] || $this->categoryIsMemberOnly($categoryId !== null ? (string) $categoryId : null);
        return $memberOnly && !$this->viewer->signedIn() ? self::LOGIN : self::OK;
    }

    /**
     * Files follow their own members-only flag and their container's; viewer
     * restrictions don't apply to them, a grant on their series does.
     *
     * @param array<string, mixed> $file
     * @param array<string, mixed>|null $series
     */
    public function file(array $file, ?array $series = null): string
    {
        $seriesId = $file['series_id'] !== null ? (string) $file['series_id'] : null;
        $categoryId = $file['category_id'] !== null ? (string) $file['category_id'] : ($series['category_id'] ?? null);
        if (!Visibility::isVisible($file, $this->now) && !$this->manages('manage_files', $categoryId !== null ? (string) $categoryId : null, $seriesId)) {
            return self::MISSING;
        }
        if ($this->viewer->hasSeriesGrant($seriesId)) {
            return self::OK;
        }
        $memberOnly = (bool) $file['member_only'] || (bool) ($series['member_only'] ?? false)
            || $this->categoryIsMemberOnly($categoryId !== null ? (string) $categoryId : null);
        return $memberOnly && !$this->viewer->signedIn() ? self::LOGIN : self::OK;
    }

    // Listings ------------------------------------------------------------------------

    /**
     * The WHERE for a public listing of series under alias $a: live, not
     * hidden; for a visitor, nothing members-only (its own flag or its
     * categories'); nothing restricted to other people. A shared series
     * shows for its holder.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public function seriesListSql(string $a = 's'): array
    {
        [$sql, $params] = Visibility::sql($a, $this->now);
        if (!$this->viewer->signedIn()) {
            $sql .= " AND ($a.member_only = 0" . $this->notInCategories("$a.category_id", $this->memberOnlyCategoryIds(), $params) . ')';
        }
        $blocked = array_keys(array_filter($this->restrictions()['series'], fn ($who, $id) => !$this->isListed($who) && !$this->viewer->hasSeriesGrant((string) $id), ARRAY_FILTER_USE_BOTH));
        $sql .= $this->notIn("$a.id", array_map('strval', $blocked), $params);
        return [$sql, $params];
    }

    /**
     * The WHERE for a public listing of videos under alias $v, whose series
     * (if any) is joined as $s.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public function videoListSql(string $v = 'v', string $s = 's'): array
    {
        [$sql, $params] = Visibility::sql($v, $this->now);
        if (!$this->viewer->signedIn()) {
            $categories = $this->memberOnlyCategoryIds();
            $sql .= " AND $v.member_only = 0 AND ($s.id IS NULL OR $s.member_only = 0)"
                . $this->notInCategories("COALESCE($v.category_id, $s.category_id)", $categories, $params);
        }
        $blockedVideos = array_keys(array_filter($this->restrictions()['videos'], fn ($who, $id) => !$this->isListed($who) && !$this->viewer->hasVideoGrant((string) $id, null), ARRAY_FILTER_USE_BOTH));
        $blockedSeries = array_keys(array_filter($this->restrictions()['series'], fn ($who, $id) => !$this->isListed($who) && !$this->viewer->hasSeriesGrant((string) $id), ARRAY_FILTER_USE_BOTH));
        $sql .= $this->notIn("$v.id", array_map('strval', $blockedVideos), $params);
        if ($blockedSeries !== []) {
            // A video with its own grants is decided by them, not its series'.
            $own = array_map('strval', array_keys($this->restrictions()['videos']));
            $sql .= " AND ($v.series_id IS NULL" . $this->notIn("$v.series_id", array_map('strval', $blockedSeries), $params, ' OR')
                . ($own !== [] ? $this->in("$v.id", $own, $params, ' OR') : '') . ')';
        }
        return [$sql, $params];
    }

    /**
     * The WHERE for a public listing of files under alias $f, whose series
     * (if any) is joined as $s.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public function fileListSql(string $f = 'f', string $s = 's'): array
    {
        [$sql, $params] = Visibility::sql($f, $this->now);
        if (!$this->viewer->signedIn()) {
            $sql .= " AND $f.member_only = 0 AND ($s.id IS NULL OR $s.member_only = 0)"
                . $this->notInCategories("COALESCE($f.category_id, $s.category_id)", $this->memberOnlyCategoryIds(), $params);
        }
        return [$sql, $params];
    }

    /** @param list<string> $ids @param list<mixed> $params */
    private function notInCategories(string $column, array $ids, array &$params): string
    {
        if ($ids === []) {
            return '';
        }
        array_push($params, ...$ids);
        return " AND ($column IS NULL OR $column NOT IN (" . implode(', ', array_fill(0, count($ids), '?')) . '))';
    }

    /** @param list<string> $ids @param list<mixed> $params */
    private function notIn(string $column, array $ids, array &$params, string $join = ' AND'): string
    {
        if ($ids === []) {
            return $join === ' AND' ? '' : " OR 1 = 1";
        }
        array_push($params, ...$ids);
        return "$join $column NOT IN (" . implode(', ', array_fill(0, count($ids), '?')) . ')';
    }

    /** @param list<string> $ids @param list<mixed> $params */
    private function in(string $column, array $ids, array &$params, string $join): string
    {
        array_push($params, ...$ids);
        return "$join $column IN (" . implode(', ', array_fill(0, count($ids), '?')) . ')';
    }
}
