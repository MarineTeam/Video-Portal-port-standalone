<?php

declare(strict_types=1);

namespace App\Modules\Library;

use App\Core\App;
use App\Core\Db;
use App\Core\Id;
use App\Modules\Plugins\PluginStates;

/**
 * The homepage's configurable rows (/admin/home-rows): the four built-in
 * sections, seeded once, which an admin can turn off, rename and reorder,
 * plus curated rows pointing at a category or a tag. With nothing
 * configured, or the table unreadable, the built-in order stands — the same
 * fail-open as plugin states. Continue watching always sits directly above
 * the browse list; every other row follows it in the order set here.
 */
final class HomeRows
{
    public const BUILT_IN = ['CONTINUE_WATCHING', 'RECOMMENDATIONS', 'TRENDING', 'RECENTLY_ADDED'];
    public const CURATED = ['CATEGORY', 'TAG'];
    public const PER_ROW = 12;
    public const TRENDING_DAYS = 7;

    /** Adds any built-in row that is missing, after the others. */
    public static function ensureSeeded(Db $db): void
    {
        $have = array_column($db->all('SELECT type FROM {{home_rows}}'), 'type');
        $next = (int) $db->value('SELECT COALESCE(MAX(position), -1) + 1 FROM {{home_rows}}');
        foreach (self::BUILT_IN as $type) {
            if (!in_array($type, $have, true)) {
                $db->insert('home_rows', ['id' => Id::new(), 'type' => $type, 'enabled' => 1, 'position' => $next++]);
            }
        }
    }

    /**
     * Every row in order, with the category's name for a category row.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(Db $db): array
    {
        try {
            $rows = $db->all('SELECT r.*, c.name AS category_name, c.slug AS category_slug FROM {{home_rows}} r LEFT JOIN {{categories}} c ON c.id = r.category_id AND c.deleted_at IS NULL ORDER BY r.position, r.created_at');
        } catch (\Throwable) {
            $rows = [];
        }
        if ($rows === []) {
            return array_map(fn (string $type, int $i) => ['id' => null, 'type' => $type, 'title' => null, 'enabled' => 1, 'position' => $i, 'category_id' => null, 'tag' => null, 'category_name' => null, 'category_slug' => null], self::BUILT_IN, array_keys(self::BUILT_IN));
        }
        return $rows;
    }

    /** What a row is called when the admin hasn't named it. */
    public static function defaultTitle(array $row): string
    {
        return match ((string) $row['type']) {
            'CONTINUE_WATCHING' => t('library.continueWatching'),
            'RECOMMENDATIONS' => t('home.becauseYouWatchedAny'),
            'TRENDING' => t('home.trending'),
            'RECENTLY_ADDED' => t('library.recentlyAdded'),
            'CATEGORY' => (string) ($row['category_name'] ?? t('home.missingCategory')),
            'TAG' => '#' . (string) ($row['tag'] ?? ''),
            default => (string) $row['type'],
        };
    }

    /**
     * The homepage's sections, filled for this viewer: 'continue' for the row
     * above the browse list, 'rows' for those below it. Empty rows, rows whose
     * plugin is off and rows pointing at something this viewer can't see are
     * left out.
     *
     * @return array{continue: ?array{title: string, videos: list<array<string, mixed>>}, rows: list<array{type: string, title: string, href: ?string, series: list<array<string, mixed>>}>}
     */
    public static function sections(App $app, Browse $browse): array
    {
        $db = $app->db();
        $continue = null;
        $rows = [];
        foreach (self::all($db) as $row) {
            if (!(bool) $row['enabled']) {
                continue;
            }
            $title = trim((string) ($row['title'] ?? '')) !== '' ? (string) $row['title'] : null;
            switch ((string) $row['type']) {
                case 'CONTINUE_WATCHING':
                    $videos = $browse->continueWatching(self::PER_ROW);
                    if ($videos !== []) {
                        $continue = ['title' => $title ?? self::defaultTitle($row), 'videos' => $videos];
                    }
                    break;
                case 'RECOMMENDATIONS':
                    if (PluginStates::enabled($db, 'recommendations')) {
                        [$anchor, $series] = self::recommendations($browse);
                        if ($anchor !== null && $series !== []) {
                            $rows[] = ['type' => 'RECOMMENDATIONS', 'title' => $title ?? t('home.becauseYouWatched', ['title' => $anchor['title']]), 'href' => null, 'series' => $series];
                        }
                    }
                    break;
                case 'TRENDING':
                    if (PluginStates::enabled($db, 'view-counts')) {
                        $rows[] = ['type' => 'TRENDING', 'title' => $title ?? self::defaultTitle($row), 'href' => null, 'series' => self::trending($browse, $db)];
                    }
                    break;
                case 'RECENTLY_ADDED':
                    $recent = $browse->seriesWhere('1 = 1', [], 's.created_at DESC', self::PER_ROW);
                    // One series alone is already the hero.
                    if (count($recent) > 1) {
                        $rows[] = ['type' => 'RECENTLY_ADDED', 'title' => $title ?? self::defaultTitle($row), 'href' => '/recently-added', 'series' => $recent];
                    }
                    break;
                case 'CATEGORY':
                    $category = $row['category_id'] !== null ? $browse->categoryById((string) $row['category_id']) : null;
                    if ($category !== null && Visibility::isVisible($category, $browse->access()->now()) && $browse->access()->category($category) === ContentAccess::OK) {
                        $ids = $browse->access()->tree()->descendants([(string) $category['id']]);
                        $marks = implode(',', array_fill(0, count($ids), '?'));
                        $rows[] = ['type' => 'CATEGORY', 'title' => $title ?? (string) $category['name'], 'href' => '/categories/' . $category['slug'], 'series' => $browse->seriesWhere("s.category_id IN ($marks)", $ids, 's.pinned DESC, s.created_at DESC', self::PER_ROW)];
                    }
                    break;
                case 'TAG':
                    $tag = (string) ($row['tag'] ?? '');
                    if ($tag !== '') {
                        $rows[] = ['type' => 'TAG', 'title' => $title ?? '#' . $tag, 'href' => '/tags/' . rawurlencode($tag), 'series' => $browse->seriesWhere('s.id IN (SELECT st.series_id FROM {{series_tags}} st WHERE st.tag = ?)', [$tag], 's.created_at DESC', self::PER_ROW)];
                    }
                    break;
            }
        }
        return ['continue' => $continue, 'rows' => array_values(array_filter($rows, fn ($r) => $r['series'] !== []))];
    }

    /**
     * "Because you watched": the series of the viewer's latest watch, and
     * others sharing its category or its tags, most shared tags first.
     *
     * @return array{0: ?array<string, mixed>, 1: list<array<string, mixed>>}
     */
    public static function recommendations(Browse $browse): array
    {
        $userId = $browse->access()->viewer()->id();
        if ($userId === null) {
            return [null, []];
        }
        $last = $browse->videosWhere('v.series_id IS NOT NULL', [], 'w.updated_at DESC', 1, 'JOIN {{watch_progresses}} w ON w.video_id = v.id AND w.user_id = ?', [$userId]);
        $anchorId = $last[0]['series_id'] ?? null;
        $anchor = $anchorId !== null ? ($browse->seriesWhere('s.id = ?', [$anchorId], 's.id', 1)[0] ?? null) : null;
        if ($anchor === null) {
            return [null, []];
        }
        return [$anchor, $browse->related($anchor, self::PER_ROW)];
    }

    /**
     * The series with the most logged views this week — a video's view
     * counts for its series.
     *
     * @return list<array<string, mixed>>
     */
    public static function trending(Browse $browse, Db $db): array
    {
        $since = gmdate('Y-m-d H:i:s', time() - self::TRENDING_DAYS * 86400);
        $counts = [];
        foreach ($db->all(
            'SELECT COALESCE(e.series_id, v.series_id) AS sid, COUNT(*) AS n FROM {{view_events}} e LEFT JOIN {{videos}} v ON v.id = e.video_id
             WHERE e.created_at > ? AND COALESCE(e.series_id, v.series_id) IS NOT NULL GROUP BY sid ORDER BY n DESC LIMIT 50',
            [$since],
        ) as $r) {
            $counts[(string) $r['sid']] = (int) $r['n'];
        }
        if ($counts === []) {
            return [];
        }
        $ids = array_keys($counts);
        $series = $browse->seriesWhere('s.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids, 's.id', 50);
        usort($series, fn ($a, $b) => ($counts[$b['id']] ?? 0) <=> ($counts[$a['id']] ?? 0));
        return array_slice($series, 0, self::PER_ROW);
    }
}
