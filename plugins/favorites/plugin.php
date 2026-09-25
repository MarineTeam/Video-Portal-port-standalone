<?php
/**
 * Plugin Name: Favorites
 * Slug:        favorites
 * Version:     1.0.0
 * Description: Lets members bookmark series and videos to a My Favorites page.
 * Author:      Marine Team
 * Requires PHP: 8.2
 * Requires App: 3.0
 * Category Override: yes
 */

declare(strict_types=1);

use App\Core\App;
use App\Core\Hooks;
use App\Core\Id;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Modules\Library\Browse;
use App\Modules\Library\ContentAccess;
use App\Modules\Library\ContentTarget;
use App\Modules\Library\Viewer;
use App\Modules\Plugins\BasePlugin;

/**
 * A member's bookmarks: a button on each series and video page toggles one
 * (POST /api/favorites {seriesId} or {videoId} → {favorited}), and
 * /favorites lists them, newest first, showing only what the member may
 * still open. Written against the hook API and the library's classes only.
 */
return new class (__DIR__) extends BasePlugin {
    private const TABLES = ['series' => ['series_favorites', 'series_id'], 'video' => ['video_favorites', 'video_id']];

    public function boot(Hooks $hooks, App $app): void
    {
        $this->useTemplates($hooks);
        $this->useLang($hooks);
        $hooks->on('routes.register', function (Router $r) use ($app): void {
            $r->post('/api/favorites', fn (Request $req) => $this->toggle($app, $req), [Middleware::member($app)]);
            $r->get('/favorites', fn () => $this->page($app), [Middleware::member($app)]);
        });
        $hooks->filter('nav.sections', fn (array $nav, ?array $user) => $user === null ? $nav : [...$nav, ['href' => '/favorites', 'label' => t('favorites.nav'), 'icon' => 'star']]);
        $hooks->filter('page.series.panels', fn (array $panels, array $ctx) => [...$panels, ...$this->button($app, $ctx, 'series', (string) $ctx['series']['id'])]);
        $hooks->filter('page.video.panels', fn (array $panels, array $ctx) => [...$panels, ...$this->button($app, $ctx, 'video', (string) $ctx['video']['id'])]);
        $hooks->filter('profile.overview', function (array $cards, array $user) use ($app): array {
            $n = 0;
            foreach (self::TABLES as [$table]) {
                $n += (int) $app->db()->value("SELECT COUNT(*) FROM {{{$table}}} WHERE user_id = ?", [$user['id']]);
            }
            return [...$cards, ['title' => t('favorites.nav'), 'count' => $n, 'href' => '/favorites']];
        });
    }

    /**
     * The toggle button, for a signed-in member where the plugin is on.
     *
     * @param array<string, mixed> $ctx
     * @return list<array{area: string, html: string, order: int}>
     */
    private function button(App $app, array $ctx, string $kind, string $id): array
    {
        $viewer = $ctx['viewer'];
        if (!$viewer instanceof Viewer || !$viewer->signedIn() || !($ctx['plugins']['favorites'] ?? false)) {
            return [];
        }
        [$table, $column] = self::TABLES[$kind];
        $on = $app->db()->value("SELECT 1 FROM {{{$table}}} WHERE user_id = ? AND $column = ?", [$viewer->id(), $id]) !== null;
        return [['area' => 'actions', 'order' => 20, 'html' => $app->view()->partial('favorites/button', ['on' => $on, 'body' => [$kind . 'Id' => $id]])]];
    }

    private function toggle(App $app, Request $req): Response
    {
        $target = ContentTarget::from($app, $req->input(), ['series', 'video'], 'favorites');
        [$table, $column] = self::TABLES[$target->kind];
        $db = $app->db();
        $userId = (string) $app->currentUser()->id();
        if ($db->run("DELETE FROM {{{$table}}} WHERE user_id = ? AND $column = ?", [$userId, $target->id])->rowCount() > 0) {
            return Response::json(['favorited' => false]);
        }
        $db->run("INSERT IGNORE INTO {{{$table}}} (id, user_id, $column) VALUES (?, ?, ?)", [Id::new(), $userId, $target->id]);
        return Response::json(['favorited' => true]);
    }

    private function page(App $app): Response
    {
        $userId = (string) $app->currentUser()->id();
        $browse = new Browse($app, new ContentAccess($app, Viewer::current($app)));
        $order = array_flip(array_map('strval', $app->db()->column('SELECT series_id FROM {{series_favorites}} WHERE user_id = ? ORDER BY created_at DESC', [$userId])));
        $series = $order === [] ? [] : $browse->seriesWhere('s.id IN (SELECT series_id FROM {{series_favorites}} WHERE user_id = ?)', [$userId], 's.title', 500);
        usort($series, fn ($a, $b) => ($order[$a['id']] ?? 0) <=> ($order[$b['id']] ?? 0));
        $videos = $browse->videosWhere('1 = 1', [], 'f.created_at DESC', 500, 'JOIN {{video_favorites}} f ON f.video_id = v.id AND f.user_id = ?', [$userId]);
        return $app->page('favorites/page', ['title' => t('favorites.title'), 'series' => $series, 'videos' => $videos]);
    }
};
