<?php
/**
 * Plugin Name: Social share
 * Slug:        social-share
 * Version:     1.0.0
 * Description: Shows copy-link and share-to buttons on series and video pages.
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
 * Copy link, the device's own share sheet where it has one, X and
 * Facebook, under a series or video; on a video, "Share at" copies a link
 * back to a moment (?t=, which the video page reads before the viewer's own
 * resume position). A members-only page shares the same way: whoever opens
 * the link is asked to sign in.
 */
return new class (__DIR__) extends BasePlugin {
    public function boot(Hooks $hooks, App $app): void
    {
        $this->useLang($hooks);
        $this->useTemplates($hooks);
        $hooks->filter('page.series.panels', fn (array $panels, array $ctx) => [...$panels, ...$this->panel($app, $ctx, '/series/' . $ctx['series']['slug'], (string) $ctx['series']['title'], false)]);
        $hooks->filter('page.video.panels', fn (array $panels, array $ctx) => [...$panels, ...$this->panel($app, $ctx, '/videos/' . $ctx['video']['slug'], (string) $ctx['video']['title'], $ctx['player'] !== null)]);
    }

    /**
     * @param array<string, mixed> $ctx
     * @return list<array{area: string, html: string, order: int}>
     */
    private function panel(App $app, array $ctx, string $path, string $title, bool $shareAt): array
    {
        if (!($ctx['plugins']['social-share'] ?? false)) {
            return [];
        }
        $link = Url::absolute($path);
        return [['area' => 'actions', 'order' => 40, 'html' => $app->view()->partial('social-share/buttons', [
            'link' => $link,
            'title' => $title,
            'shareAt' => $shareAt,
            'x' => 'https://twitter.com/intent/tweet?' . http_build_query(['url' => $link, 'text' => $title]),
            'facebook' => 'https://www.facebook.com/sharer/sharer.php?' . http_build_query(['u' => $link]),
            'script' => $this->asset('share.js'),
        ])]];
    }
};
