<?php
/**
 * Plugin Name: Chapters
 * Slug:        chapters
 * Version:     1.0.0
 * Description: Shows a jump-to-section chapter list under a video, admin-managed per video.
 * Author:      Marine Team
 * Requires PHP: 8.2
 * Requires App: 3.0
 * Category Override: yes
 */

declare(strict_types=1);

use App\Core\App;
use App\Core\Hooks;
use App\Core\Url;
use App\Modules\Plugins\BasePlugin;

/**
 * The jump-to-section list under a playable video. The chapters are the
 * library's content (edited on /admin/videos/[id]); this plugin shows them:
 * each seeks the player in place (data-seek, the player's own convention)
 * and carries its own ?t= link to copy.
 */
return new class (__DIR__) extends BasePlugin {
    public function boot(Hooks $hooks, App $app): void
    {
        $this->useLang($hooks);
        $this->useTemplates($hooks);
        $hooks->filter('page.video.panels', function (array $panels, array $ctx) use ($app): array {
            if (!($ctx['plugins']['chapters'] ?? false) || $ctx['player'] === null) {
                return $panels;
            }
            $chapters = $app->db()->all('SELECT title, timestamp_seconds FROM {{chapters}} WHERE video_id = ? ORDER BY timestamp_seconds, position', [$ctx['video']['id']]);
            if ($chapters === []) {
                return $panels;
            }
            return [...$panels, ['area' => 'top', 'order' => 10, 'html' => $app->view()->partial('chapters/list', [
                'chapters' => $chapters,
                'page' => Url::absolute('/videos/' . $ctx['video']['slug']),
            ])]];
        });
    }
};
