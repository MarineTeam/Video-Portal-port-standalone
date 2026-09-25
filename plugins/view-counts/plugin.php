<?php
/**
 * Plugin Name: View counts
 * Slug:        view-counts
 * Version:     1.0.0
 * Description: Shows a play/view counter on series and video pages.
 * Author:      Marine Team
 * Requires PHP: 8.2
 * Requires App: 3.0
 * Category Override: yes
 */

declare(strict_types=1);

use App\Core\App;
use App\Core\Hooks;
use App\Modules\Library\HomeRows;
use App\Modules\Plugins\BasePlugin;

/**
 * "1,234 views" beside a series or video's buttons. The counting itself is
 * the library's (a throttled beacon, one per visitor per half hour); this
 * plugin only shows the number, and its being on is what lets the homepage
 * show "Trending this week".
 */
return new class (__DIR__) extends BasePlugin {
    public function boot(Hooks $hooks, App $app): void
    {
        $this->useLang($hooks);
        $hooks->filter('page.series.panels', fn (array $panels, array $ctx) => [...$panels, ...$this->counter($ctx, (int) ($ctx['series']['view_count'] ?? 0))]);
        $hooks->filter('page.video.panels', fn (array $panels, array $ctx) => [...$panels, ...$this->counter($ctx, (int) ($ctx['video']['view_count'] ?? 0))]);
        // The homepage's "Trending this week" row is this plugin's.
        $hooks->filter('home.row', fn (?array $row, string $type, array $ctx) => $type === 'TRENDING'
            ? ['series' => HomeRows::trending($ctx['browse'], $ctx['db'])]
            : $row);
    }

    /**
     * @param array<string, mixed> $ctx
     * @return list<array{area: string, html: string, order: int}>
     */
    private function counter(array $ctx, int $n): array
    {
        if (!($ctx['plugins']['view-counts'] ?? false)) {
            return [];
        }
        $text = $n === 1 ? t('viewCounts.one') : t('viewCounts.many', ['count' => number_format($n)]);
        return [['area' => 'actions', 'order' => 1, 'html' => '<span class="small muted" data-view-count>' . e($text) . '</span>']];
    }
};
