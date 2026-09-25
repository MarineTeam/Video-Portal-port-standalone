<?php
/**
 * Plugin Name: Downloads
 * Slug:        downloads
 * Version:     1.0.0
 * Description: Lets members download videos to their device for offline viewing, with per-category/series/video control at /admin/downloads.
 * Author:      Marine Team
 * Requires PHP: 8.2
 * Requires App: 3.0
 * Category Override: yes
 */

declare(strict_types=1);

use App\Core\App;
use App\Core\Hooks;
use App\Modules\Library\Downloads;
use App\Modules\Library\Viewer;
use App\Modules\Plugins\BasePlugin;

/**
 * The Download button under a video. Whether a video may be saved is the
 * library's decision (App\Modules\Library\Downloads: this plugin, the
 * video/series/category setting, the platform, and a real MP4), and it is
 * made again when the file is asked for; the offline copies live in the
 * browser's Cache Storage and play from /profile/downloads.
 */
return new class (__DIR__) extends BasePlugin {
    public function boot(Hooks $hooks, App $app): void
    {
        $this->useTemplates($hooks);
        $hooks->filter('page.video.panels', function (array $panels, array $ctx) use ($app): array {
            $video = $ctx['video'];
            $viewer = $ctx['viewer'];
            if (!($ctx['plugins']['downloads'] ?? false) || $ctx['locked'] || ($ctx['premiere'] ?? false) || $video['status'] !== 'READY'
                || !$viewer instanceof Viewer || !$viewer->signedIn() || !(new Downloads($app))->decide($video, $ctx['series'])['allowed']) {
                return $panels;
            }
            return [...$panels, ['area' => 'actions', 'order' => 15, 'html' => $app->view()->partial('downloads/button', ['videoId' => (string) $video['id']])]];
        });
    }
};
