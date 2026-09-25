<?php

declare(strict_types=1);

namespace App\Modules\Library;

use App\Core\App;

/**
 * What plugins add to a series or video page, through the page.series.panels
 * and page.video.panels filters. Each panel is
 * ['area' => 'actions'|'below', 'html' => string, 'order' => int]: "actions"
 * sits in the row of buttons under the title, "below" under the page's own
 * content. The html is the plugin's own rendering, escaped by it.
 */
final class Panels
{
    public const AREAS = ['actions', 'below'];

    /**
     * @param array<string, mixed> $context
     * @return array{actions: list<string>, below: list<string>}
     */
    public static function collect(App $app, string $hook, array $context): array
    {
        $panels = $app->hooks->apply($hook, [], $context + ['app' => $app]);
        $out = ['actions' => [], 'below' => []];
        $ordered = [];
        foreach (is_array($panels) ? $panels : [] as $i => $panel) {
            if (!is_array($panel) || !in_array($panel['area'] ?? null, self::AREAS, true) || !is_string($panel['html'] ?? null) || $panel['html'] === '') {
                continue;
            }
            $ordered[] = [(int) ($panel['order'] ?? 10), $i, $panel['area'], $panel['html']];
        }
        sort($ordered);
        foreach ($ordered as [, , $area, $html]) {
            $out[$area][] = $html;
        }
        return $out;
    }
}
