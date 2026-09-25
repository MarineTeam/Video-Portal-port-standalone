<?php
/**
 * Plugin Name: Related content
 * Slug:        related-content
 * Version:     1.0.0
 * Description: Shows "More like this" / "You might also like" rows.
 * Author:      Marine Team
 * Requires PHP: 8.2
 * Requires App: 3.0
 * Category Override: yes
 */

declare(strict_types=1);

use App\Core\App;
use App\Core\Hooks;
use App\Modules\Library\Browse;
use App\Modules\Library\ContentAccess;
use App\Modules\Plugins\BasePlugin;

/**
 * Under a series: "More like this" — the same category, then shared tags.
 * Under a video in a series: "More from this series"; under one standing
 * alone: "You might also like" — others in its category or by its speaker.
 * Everything is listed through the library, so a guest never sees
 * members-only content here either. Other plugins may reorder or add
 * through the related.items filter.
 */
return new class (__DIR__) extends BasePlugin {
    public const LIMIT = 8;

    public function boot(Hooks $hooks, App $app): void
    {
        $this->useLang($hooks);
        $this->useTemplates($hooks);
        $hooks->filter('page.series.panels', function (array $panels, array $ctx) use ($app, $hooks): array {
            if (!($ctx['plugins']['related-content'] ?? false)) {
                return $panels;
            }
            $series = (array) $hooks->apply('related.items', $this->browse($app)->related($ctx['series'], self::LIMIT), 'series', $ctx);
            return [...$panels, ...$this->row($app, t('related.moreLikeThis'), $series, [])];
        });
        $hooks->filter('page.video.panels', function (array $panels, array $ctx) use ($app, $hooks): array {
            if (!($ctx['plugins']['related-content'] ?? false)) {
                return $panels;
            }
            $video = $ctx['video'];
            if ($ctx['series'] !== null) {
                $others = array_values(array_filter((array) $ctx['siblings'], fn ($v) => $v['id'] !== $video['id']));
                $videos = (array) $hooks->apply('related.items', array_slice($others, 0, self::LIMIT), 'video', $ctx);
                return [...$panels, ...$this->row($app, t('related.moreFromSeries'), [], $videos)];
            }
            $conditions = [];
            $params = [(string) $video['id']];
            foreach (['category_id', 'speaker_id'] as $column) {
                if (($video[$column] ?? null) !== null) {
                    $conditions[] = "v.$column = ?";
                    $params[] = (string) $video[$column];
                }
            }
            if ($conditions === []) {
                return $panels;
            }
            $videos = $this->browse($app)->videosWhere('v.series_id IS NULL AND v.id <> ? AND (' . implode(' OR ', $conditions) . ')', $params, 'v.created_at DESC', self::LIMIT);
            $videos = (array) $hooks->apply('related.items', $videos, 'video', $ctx);
            return [...$panels, ...$this->row($app, t('related.youMightLike'), [], $videos)];
        });
    }

    private function browse(App $app): Browse
    {
        return new Browse($app, ContentAccess::for($app));
    }

    /**
     * @param list<array<string, mixed>> $series
     * @param list<array<string, mixed>> $videos
     * @return list<array{area: string, html: string, order: int}>
     */
    private function row(App $app, string $title, array $series, array $videos): array
    {
        if ($series === [] && $videos === []) {
            return [];
        }
        return [['area' => 'below', 'order' => 50, 'html' => $app->view()->partial('related-content/row', ['title' => $title, 'series' => $series, 'videos' => $videos])]];
    }
};
