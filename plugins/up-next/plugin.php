<?php
/**
 * Plugin Name: Up next
 * Slug:        up-next
 * Version:     1.0.0
 * Description: Shows an "Up next" panel with the next video in a series, with an autoplay option.
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
 * The next episode in the series, under the player, with an autoplay
 * switch that is the device's own autoplay setting (Profile → Settings),
 * not a second preference. With it on, the page counts down and moves on
 * when the video ends — heard from the player where it reports its
 * position, else when the video's known duration has passed.
 */
return new class (__DIR__) extends BasePlugin {
    public function boot(Hooks $hooks, App $app): void
    {
        $this->useLang($hooks);
        $this->useTemplates($hooks);
        $hooks->filter('page.video.panels', function (array $panels, array $ctx) use ($app): array {
            if (!($ctx['plugins']['up-next'] ?? false) || $ctx['series'] === null || $ctx['player'] === null) {
                return $panels;
            }
            $siblings = array_values((array) $ctx['siblings']);
            $index = array_search((string) $ctx['video']['id'], array_map(fn ($v) => (string) $v['id'], $siblings), true);
            $next = $index !== false ? ($siblings[$index + 1] ?? null) : null;
            if ($next === null) {
                return $panels;
            }
            return [...$panels, ['area' => 'below', 'order' => 5, 'html' => $app->view()->partial('up-next/panel', [
                'next' => $next,
                'href' => Url::to('/videos/' . $next['slug']),
                // Only an embed that reports no position falls back on the known duration.
                'duration' => ($ctx['player']['progressEvents'] ?? true) ? 0 : (int) ($ctx['video']['duration_seconds'] ?? 0),
                'script' => $this->asset('up-next.js'),
            ])]];
        });
    }
};
