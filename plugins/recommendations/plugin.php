<?php
/**
 * Plugin Name: Recommendations
 * Slug:        recommendations
 * Version:     1.0.0
 * Description: Shows a personalized "Because you watched" row on the homepage, based on the member's most recent watch.
 * Author:      Marine Team
 * Requires PHP: 8.2
 * Requires App: 3.0
 */

declare(strict_types=1);

use App\Core\App;
use App\Core\Hooks;
use App\Modules\Library\HomeRows;
use App\Modules\Plugins\BasePlugin;

/**
 * "Because you watched Romans": anchored on the series of the member's
 * latest watch, filled with series sharing its category or tags — the
 * same likeness Related content uses. A guest, or a member with nothing
 * watched yet, gets no row.
 */
return new class (__DIR__) extends BasePlugin {
    public function boot(Hooks $hooks, App $app): void
    {
        $hooks->filter('home.row', function (?array $row, string $type, array $ctx): ?array {
            if ($type !== 'RECOMMENDATIONS') {
                return $row;
            }
            [$anchor, $series] = HomeRows::recommendations($ctx['browse']);
            if ($anchor === null || $series === []) {
                return null;
            }
            return ['title' => $ctx['title'] ?? t('home.becauseYouWatched', ['title' => $anchor['title']]), 'series' => $series];
        });
    }
};
