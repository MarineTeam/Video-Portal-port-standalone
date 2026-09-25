<?php
/**
 * Plugin Name: Share links
 * Slug:        share-links
 * Version:     1.0.0
 * Description: Lets members create revocable share links to a series or video, public or emailed to specific people.
 * Author:      Marine Team
 * Requires PHP: 8.2
 * Requires App: 3.0
 * Category Override: yes
 */

declare(strict_types=1);

use App\Core\App;
use App\Core\Hooks;
use App\Modules\Library\Sharing;
use App\Modules\Plugins\BasePlugin;

/**
 * The "Share this" panel under a series or video. The links themselves
 * are the library's (App\Modules\Library\Sharing): opening one is part of
 * deciding who may watch, and revoking one, the member's list at
 * /profile/shared-links and the admin's at /admin/share-links never depend
 * on this plugin — switching it off must not trap anybody with a link they
 * can no longer take back. What the plugin gates is making new ones.
 */
return new class (__DIR__) extends BasePlugin {
    public function boot(Hooks $hooks, App $app): void
    {
        $panel = function (string $type, string $id) use ($app): array {
            $share = (new Sharing($app))->panel($type, $id);
            return $share === null ? [] : [['area' => 'below', 'order' => 60, 'html' => $app->view()->partial('partials/share-panel', ['share' => $share])]];
        };
        $hooks->filter('page.series.panels', fn (array $panels, array $ctx) => ($ctx['plugins']['share-links'] ?? false) ? [...$panels, ...$panel('series', (string) $ctx['series']['id'])] : $panels);
        $hooks->filter('page.video.panels', fn (array $panels, array $ctx) => ($ctx['plugins']['share-links'] ?? false) && !$ctx['locked'] ? [...$panels, ...$panel('video', (string) $ctx['video']['id'])] : $panels);
    }
};
