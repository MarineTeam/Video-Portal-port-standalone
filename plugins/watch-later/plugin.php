<?php
/**
 * Plugin Name: Watch later
 * Slug:        watch-later
 * Version:     1.0.0
 * Description: Lets members queue a series or video to a Watch Later page, separate from Favorites.
 * Author:      Marine Team
 * Requires PHP: 8.2
 * Requires App: 3.0
 * Category Override: yes
 */

declare(strict_types=1);

use App\Core\ApiError;
use App\Core\App;
use App\Core\Hooks;
use App\Core\Id;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Validator;
use App\Modules\Library\Browse;
use App\Modules\Library\ContentAccess;
use App\Modules\Library\Viewer;
use App\Modules\Library\Visibility;
use App\Modules\Plugins\BasePlugin;
use App\Modules\Plugins\PluginStates;

/**
 * A member's queue, separate from Favorites: a button on each category,
 * series and video page toggles an entry (POST /api/watch-later
 * {categoryId|seriesId|videoId} → {saved}), and /watch-later lists what the
 * member may still open, newest first.
 */
return new class (__DIR__) extends BasePlugin {
    private const TABLES = [
        'category' => ['category_watch_laters', 'category_id'],
        'series' => ['series_watch_laters', 'series_id'],
        'video' => ['video_watch_laters', 'video_id'],
    ];

    public function boot(Hooks $hooks, App $app): void
    {
        $this->useTemplates($hooks);
        $dir = $this->dir;
        $hooks->filter('lang.catalogue', function (array $strings, string $locale) use ($dir): array {
            $file = $dir . '/lang/' . (is_file($dir . '/lang/' . $locale . '.php') ? $locale : 'en') . '.php';
            return $strings + (array) require $file;
        });
        $hooks->on('routes.register', function (Router $r) use ($app): void {
            $r->post('/api/watch-later', fn (Request $req) => $this->toggle($app, $req), [Middleware::member($app)]);
            $r->get('/watch-later', fn () => $this->page($app), [Middleware::member($app)]);
        });
        $hooks->filter('nav.sections', fn (array $nav, ?array $user) => $user === null ? $nav : [...$nav, ['href' => '/watch-later', 'label' => t('watchLater.nav'), 'icon' => 'clock']]);
        $hooks->filter('page.category.panels', fn (array $panels, array $ctx) => [...$panels, ...$this->button($app, $ctx, 'category', (string) $ctx['category']['id'])]);
        $hooks->filter('page.series.panels', fn (array $panels, array $ctx) => [...$panels, ...$this->button($app, $ctx, 'series', (string) $ctx['series']['id'])]);
        $hooks->filter('page.video.panels', fn (array $panels, array $ctx) => [...$panels, ...$this->button($app, $ctx, 'video', (string) $ctx['video']['id'])]);
        $hooks->filter('profile.overview', function (array $cards, array $user) use ($app): array {
            $n = 0;
            foreach (self::TABLES as [$table]) {
                $n += (int) $app->db()->value("SELECT COUNT(*) FROM {{{$table}}} WHERE user_id = ?", [$user['id']]);
            }
            return [...$cards, ['title' => t('watchLater.nav'), 'count' => $n, 'href' => '/watch-later']];
        });
        $hooks->filter('profile.export', function (array $doc, string $userId) use ($app): array {
            $doc['watchLater'] = [
                'categories' => $app->db()->all('SELECT w.category_id, c.name, w.created_at FROM {{category_watch_laters}} w JOIN {{categories}} c ON c.id = w.category_id WHERE w.user_id = ? ORDER BY w.created_at', [$userId]),
                'series' => $app->db()->all('SELECT w.series_id, s.title, w.created_at FROM {{series_watch_laters}} w JOIN {{series}} s ON s.id = w.series_id WHERE w.user_id = ? ORDER BY w.created_at', [$userId]),
                'videos' => $app->db()->all('SELECT w.video_id, v.title, w.created_at FROM {{video_watch_laters}} w JOIN {{videos}} v ON v.id = w.video_id WHERE w.user_id = ? ORDER BY w.created_at', [$userId]),
            ];
            return $doc;
        });
    }

    /**
     * @param array<string, mixed> $ctx
     * @return list<array{area: string, html: string, order: int}>
     */
    private function button(App $app, array $ctx, string $kind, string $id): array
    {
        $viewer = $ctx['viewer'];
        if (!$viewer instanceof Viewer || !$viewer->signedIn() || !($ctx['plugins']['watch-later'] ?? false)) {
            return [];
        }
        [$table, $column] = self::TABLES[$kind];
        $on = $app->db()->value("SELECT 1 FROM {{{$table}}} WHERE user_id = ? AND $column = ?", [$viewer->id(), $id]) !== null;
        return [['area' => 'actions', 'order' => 21, 'html' => $app->view()->partial('watch-later/button', ['on' => $on, 'body' => [$kind . 'Id' => $id]])]];
    }

    private function toggle(App $app, Request $req): Response
    {
        $data = Validator::check($req->input(), ['categoryId' => ['id', 'nullable'], 'seriesId' => ['id', 'nullable'], 'videoId' => ['id', 'nullable']]);
        $kind = isset($data['videoId']) ? 'video' : (isset($data['seriesId']) ? 'series' : (isset($data['categoryId']) ? 'category' : throw ApiError::invalid('Say which category, series or video.')));
        $id = (string) $data[$kind . 'Id'];
        $db = $app->db();
        $access = new ContentAccess($app, Viewer::current($app));
        $categoryId = null;
        $ok = false;
        if ($kind === 'category') {
            $row = $db->one('SELECT * FROM {{categories}} WHERE id = ? AND deleted_at IS NULL', [$id]);
            $ok = $row !== null && Visibility::isVisible($row, $access->now()) && $access->category($row) === ContentAccess::OK;
            $categoryId = $id;
        } elseif ($kind === 'series') {
            $row = $db->one('SELECT * FROM {{series}} WHERE id = ? AND deleted_at IS NULL', [$id]);
            $ok = $row !== null && $access->series($row) === ContentAccess::OK;
            $categoryId = $row['category_id'] ?? null;
        } else {
            $row = $db->one('SELECT * FROM {{videos}} WHERE id = ? AND deleted_at IS NULL', [$id]);
            $series = $row !== null && $row['series_id'] !== null ? $db->one('SELECT * FROM {{series}} WHERE id = ?', [$row['series_id']]) : null;
            $ok = $row !== null && $access->video($row, $series) === ContentAccess::OK;
            $categoryId = $row['category_id'] ?? ($series['category_id'] ?? null);
        }
        if (!$ok) {
            throw ApiError::notFound();
        }
        if (!PluginStates::enabled($db, 'watch-later', $categoryId !== null ? (string) $categoryId : null)) {
            throw new ApiError(t('watchLater.off'), 403, 'plugin_disabled');
        }
        [$table, $column] = self::TABLES[$kind];
        $userId = (string) $app->currentUser()->id();
        if ($db->run("DELETE FROM {{{$table}}} WHERE user_id = ? AND $column = ?", [$userId, $id])->rowCount() > 0) {
            return Response::json(['saved' => false]);
        }
        $db->run("INSERT IGNORE INTO {{{$table}}} (id, user_id, $column) VALUES (?, ?, ?)", [Id::new(), $userId, $id]);
        return Response::json(['saved' => true]);
    }

    private function page(App $app): Response
    {
        $db = $app->db();
        $userId = (string) $app->currentUser()->id();
        $access = new ContentAccess($app, Viewer::current($app));
        $browse = new Browse($app, $access);
        $categories = [];
        foreach ($db->all('SELECT c.* FROM {{category_watch_laters}} w JOIN {{categories}} c ON c.id = w.category_id AND c.deleted_at IS NULL WHERE w.user_id = ? ORDER BY w.created_at DESC', [$userId]) as $c) {
            if (Visibility::isVisible($c, $access->now()) && $access->category($c) === ContentAccess::OK) {
                $categories[] = $c;
            }
        }
        $order = array_flip(array_map('strval', $db->column('SELECT series_id FROM {{series_watch_laters}} WHERE user_id = ? ORDER BY created_at DESC', [$userId])));
        $series = $order === [] ? [] : $browse->seriesWhere('s.id IN (SELECT series_id FROM {{series_watch_laters}} WHERE user_id = ?)', [$userId], 's.title', 500);
        usort($series, fn ($a, $b) => ($order[$a['id']] ?? 0) <=> ($order[$b['id']] ?? 0));
        $videos = $browse->videosWhere('1 = 1', [], 'w.created_at DESC', 500, 'JOIN {{video_watch_laters}} w ON w.video_id = v.id AND w.user_id = ?', [$userId]);
        return $app->page('watch-later/page', ['title' => t('watchLater.title'), 'categories' => $categories, 'series' => $series, 'videos' => $videos]);
    }
};
