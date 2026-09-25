<?php

declare(strict_types=1);

namespace App\Modules\Plugins;

/**
 * The header block at the top of plugin.php, WordPress-style:
 *
 *   /**
 *    * Plugin Name: Sermon notes
 *    * Slug:        sermon-notes
 *    * Version:     1.0.0
 *    * Requires PHP: 8.2
 *    * Requires App: 3.0
 *    * Provides:    video
 *    * Depends:     favorites, playlists
 *    * Category Override: yes
 *    *\/
 *
 * Read as text, never executed: a plugin whose header doesn't parse is never
 * loaded at all.
 */
final class Header
{
    public const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{0,62}$/';

    /**
     * @return array{name: string, slug: string, version: string, description: string, author: string, requiresPhp: string, requiresApp: string, provides: list<string>, depends: list<string>, categoryOverride: bool}|null
     */
    public static function parse(string $source): ?array
    {
        $head = substr($source, 0, 8192);
        if (!preg_match('#/\*\*(.*?)\*/#s', $head, $block)) {
            return null;
        }
        $fields = [];
        foreach (preg_split('/\R/', $block[1]) ?: [] as $line) {
            if (preg_match('/^\s*\*?\s*([A-Za-z][A-Za-z ]{1,30}?)\s*:\s*(.+?)\s*$/', $line, $m)) {
                $fields[strtolower($m[1])] = $m[2];
            }
        }
        $slug = $fields['slug'] ?? '';
        $name = $fields['plugin name'] ?? ($fields['theme name'] ?? '');
        if ($name === '' || !preg_match(self::SLUG_PATTERN, $slug)) {
            return null;
        }
        $list = fn (string $key) => array_values(array_filter(array_map('trim', preg_split('/[,|]/', $fields[$key] ?? '') ?: []), fn ($v) => $v !== '' && !str_starts_with($v, '(')));
        return [
            'name' => mb_substr($name, 0, 120),
            'slug' => $slug,
            'version' => mb_substr($fields['version'] ?? '0.0.0', 0, 32),
            'description' => mb_substr($fields['description'] ?? '', 0, 500),
            'author' => mb_substr($fields['author'] ?? '', 0, 120),
            'requiresPhp' => $fields['requires php'] ?? '8.2',
            'requiresApp' => $fields['requires app'] ?? '3.0',
            'provides' => $list('provides'),
            'depends' => $list('depends'),
            'categoryOverride' => in_array(strtolower($fields['category override'] ?? 'no'), ['yes', 'true', '1'], true),
        ];
    }

    public static function read(string $pluginFile): ?array
    {
        $source = @file_get_contents($pluginFile, false, null, 0, 8192);
        return $source === false ? null : self::parse($source);
    }
}
