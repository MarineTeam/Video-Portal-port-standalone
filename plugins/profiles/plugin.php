<?php
/**
 * Plugin Name: Profiles
 * Slug:        profiles
 * Version:     1.0.0
 * Description: Lets members set a display name shown instead of their sign-in name in comments and the navbar.
 * Author:      Marine Team
 * Requires PHP: 8.2
 * Requires App: 3.0
 */

declare(strict_types=1);

require_once __DIR__ . '/src/Directory.php';

use App\Core\App;
use App\Core\Hooks;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Router;
use App\Modules\Plugins\BasePlugin;
use MarineTeam\Plugins\Profiles\Directory;

/**
 * A member's display name, and the directory at /directory. The account
 * fields themselves (display name, and each directory choice) are the
 * profile shell's, accepted by PATCH /api/profile only while this plugin is
 * on; this plugin adds the page that reads them. See src/Directory.php for
 * the rules.
 */
return new class (__DIR__) extends BasePlugin {
    public function boot(Hooks $hooks, App $app): void
    {
        $this->useLang($hooks);
        $this->useTemplates($hooks);
        $hooks->on('routes.register', function (Router $r) use ($app): void {
            $r->get('/directory', function (Request $req) use ($app) {
                $rows = $app->db()->all(
                    'SELECT id, email, name, display_name, phone, authorized, directory_listed, directory_show_email, directory_show_phone, directory_note
                     FROM {{users}} WHERE directory_listed = 1 AND authorized = 1',
                );
                $q = mb_substr(trim((string) $req->query('q')), 0, 100);
                $me = (array) $app->db()->one('SELECT email, name, display_name, phone, authorized, directory_listed, directory_show_email, directory_show_phone, directory_note FROM {{users}} WHERE id = ?', [$app->currentUser()->id()]);
                [$standingKey, $standingVars] = Directory::directoryStanding($me);
                $response = $app->page('profiles/directory', [
                    'title' => t('directory.title'),
                    'members' => Directory::searchDirectory(Directory::visibleDirectory($rows), $q),
                    'q' => $q,
                    'standing' => t($standingKey, $standingVars),
                ]);
                $response->header('X-Robots-Tag', 'noindex');
                $response->header('Cache-Control', 'no-store');
                return $response;
            }, [Middleware::member($app)]);
        });
        $hooks->filter('profile.sections', fn (array $items, array $user) => [...$items, ['href' => '/directory', 'label' => t('directory.title')]]);
    }
};
