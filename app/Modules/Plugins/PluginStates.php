<?php

declare(strict_types=1);

namespace App\Modules\Plugins;

use App\Core\Cache;
use App\Core\Db;
use App\Modules\Library\CategoryTree;

/**
 * Every plugin's enabled state for a page, in two or three queries however
 * deep the category tree or however many plugins: the site-wide value, then
 * the nearest ancestor category's override. The only way a page asks
 * "is this plugin on here".
 *
 * Fail open for a plugin with no row: a feature that hasn't been seeded yet
 * behaves as shipped rather than vanishing.
 */
final class PluginStates
{
    /**
     * The pure resolution.
     *
     * @param array<string, array{id: string, enabled: bool}> $rows slug => row
     * @param array<string, array<string, bool>> $overrides pluginId => categoryId => enabled
     * @param list<string> $chain the page's category, nearest first
     * @param list<string> $slugs every plugin to answer for
     * @return array<string, bool>
     */
    public static function resolve(array $rows, array $overrides, array $chain, array $slugs): array
    {
        $out = [];
        foreach ($slugs as $slug) {
            $row = $rows[$slug] ?? null;
            if ($row === null) {
                $out[$slug] = true;
                continue;
            }
            $state = $row['enabled'];
            foreach ($chain as $categoryId) {
                if (isset($overrides[$row['id']][$categoryId])) {
                    $state = $overrides[$row['id']][$categoryId];
                    break;
                }
            }
            $out[$slug] = $state;
        }
        return $out;
    }

    /** @return array<string, bool> */
    public static function forCategory(Db $db, ?string $categoryId = null): array
    {
        return Cache::memo('plugin-states:' . ($categoryId ?? ''), function () use ($db, $categoryId) {
            [$rows, $overrides] = self::load($db);
            $chain = $categoryId === null ? [] : CategoryTree::load($db)->chain($categoryId);
            return self::resolve($rows, $overrides, $chain, array_values(array_unique([...Features::slugs(), ...array_keys($rows)])));
        });
    }

    public static function enabled(Db $db, string $slug, ?string $categoryId = null): bool
    {
        return self::forCategory($db, $categoryId)[$slug] ?? false;
    }

    /** @return array{0: array<string, array{id: string, enabled: bool}>, 1: array<string, array<string, bool>>} */
    private static function load(Db $db): array
    {
        return Cache::remember('plugin-rows', 3600, function () use ($db) {
            $rows = [];
            foreach ($db->all('SELECT id, slug, enabled FROM {{plugins}}') as $row) {
                $rows[(string) $row['slug']] = ['id' => (string) $row['id'], 'enabled' => (bool) $row['enabled']];
            }
            $overrides = [];
            foreach ($db->all('SELECT plugin_id, category_id, enabled FROM {{plugin_category_overrides}}') as $row) {
                $overrides[(string) $row['plugin_id']][(string) $row['category_id']] = (bool) $row['enabled'];
            }
            return [$rows, $overrides];
        });
    }

    public static function forget(): void
    {
        Cache::forget('plugin-rows');
        Cache::forgetMemo('plugin-states:');
    }
}
