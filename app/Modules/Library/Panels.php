<?php

declare(strict_types=1);

namespace App\Modules\Library;

use App\Core\App;

/**
 * What plugins add to a series or video page, through the page.series.panels
 * and page.video.panels filters. Each panel is
 * ['area' => 'actions'|'top'|'below', 'html' => string, 'order' => int]:
 * "actions" sits in the row of buttons under the title, "top" just under
 * that and above the page's own text, "below" under the page's own content. The html is the plugin's own rendering, escaped by it.
 */
final class Panels
{
    public const AREAS = ['actions', 'top', 'below'];

    /**
     * @param array<string, mixed> $context
     * @return array{actions: list<string>, top: list<string>, below: list<string>}
     */
    public static function collect(App $app, string $hook, array $context): array
    {
        $panels = $app->hooks->apply($hook, [], $context + ['app' => $app]);
        $out = ['actions' => [], 'top' => [], 'below' => []];
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
