<?php
/**
 * Plugin Name: Transcripts
 * Slug:        transcripts
 * Version:     1.0.0
 * Description: Shows a collapsible full-text transcript under a video and includes it in search results.
 * Author:      Marine Team
 * Requires PHP: 8.2
 * Requires App: 3.0
 * Category Override: yes
 */

declare(strict_types=1);

use App\Core\App;
use App\Core\Hooks;
use App\Modules\Plugins\BasePlugin;

/**
 * A video's transcript in a collapsible panel under its description.
 * The transcript itself is the library's (typed, or written by the
 * transcription job); search reads it while this plugin is on, which the
 * library's search asks of the plugin state directly.
 */
return new class (__DIR__) extends BasePlugin {
    public function boot(Hooks $hooks, App $app): void
    {
        $this->useLang($hooks);
        $this->useTemplates($hooks);
        $hooks->filter('page.video.panels', function (array $panels, array $ctx) use ($app): array {
            $text = trim((string) ($ctx['video']['transcript'] ?? ''));
            if (!($ctx['plugins']['transcripts'] ?? false) || $ctx['locked'] || $text === '') {
                return $panels;
            }
            return [...$panels, ['area' => 'below', 'order' => 1, 'html' => $app->view()->partial('transcripts/panel', ['text' => $text])]];
        });
    }
};
