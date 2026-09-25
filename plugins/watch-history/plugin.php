<?php
/**
 * Plugin Name: Watch history
 * Slug:        watch-history
 * Version:     1.0.0
 * Description: Shows a "Recently Played" page and nav tab of everything a member has watched.
 * Author:      Marine Team
 * Requires PHP: 8.2
 * Requires App: 3.0
 */

declare(strict_types=1);

use App\Core\App;
use App\Core\Hooks;
use App\Core\Middleware;
use App\Core\Router;
use App\Modules\Library\Browse;
use App\Modules\Library\ContentAccess;
use App\Modules\Plugins\BasePlugin;

/**
 * /recently-played: every video the member has started, most recent
 * first, from the watch progress the player already keeps — finished ones
 * too, marked as watched. Only what they may still open is listed.
 */
return new class (__DIR__) extends BasePlugin {
    public const LIMIT = 200;

    public function boot(Hooks $hooks, App $app): void
    {
        $this->useLang($hooks);
        $this->useTemplates($hooks);
        $hooks->on('routes.register', function (Router $r) use ($app): void {
            $r->get('/recently-played', function () use ($app) {
                $userId = (string) $app->currentUser()->id();
                $videos = (new Browse($app, ContentAccess::for($app)))->videosWhere('1 = 1', [], 'w.updated_at DESC', self::LIMIT, 'JOIN {{watch_progresses}} w ON w.video_id = v.id AND w.user_id = ?', [$userId]);
                return $app->page('watch-history/page', ['title' => t('history.title'), 'videos' => $videos]);
            }, [Middleware::member($app)]);
        });
        $hooks->filter('nav.sections', fn (array $nav, ?array $user) => $user === null ? $nav : [...$nav, ['href' => '/recently-played', 'label' => t('history.nav'), 'icon' => 'history']]);
        $hooks->filter('profile.overview', fn (array $cards, array $user) => [...$cards, [
            'title' => t('history.nav'),
            'count' => (int) $app->db()->value('SELECT COUNT(*) FROM {{watch_progresses}} WHERE user_id = ?', [$user['id']]),
            'href' => '/recently-played',
        ]]);
    }
};
