<?php

declare(strict_types=1);

namespace App\Modules\Themes;

use App\Core\App;
use App\Core\Validator;
use App\Modules\Branding\Branding;

/**
 * The active theme's customizer settings: declared by customizer.json in
 * the theme and its parents, stored per theme under theme.customizer.<slug>,
 * and merged over the branding — a setting whose key is a branding field
 * (name, shortName, brand, brandDeep, brandLight, logoUrl) replaces it, so
 * the branding stays the base every theme inherits.
 *
 * Everything else reaches the page three ways: a colour or image becomes a
 * custom property (--theme-<key>), a toggle a class on <html>
 * (theme-<key>), a select a class (theme-<key>-<value>); and every value is
 * in $shell['themeSettings'] for templates.
 */
final class Appearance
{
    public const TYPES = ['colour', 'color', 'image', 'text', 'select', 'toggle'];
    public const BRANDING_KEYS = ['name', 'shortName', 'brand', 'brandDeep', 'brandLight', 'logoUrl'];
    private const KEY = '/^[A-Za-z][A-Za-z0-9_-]{0,40}$/';

    /**
     * The declared settings, cleaned: unknown types and malformed keys are
     * dropped, a later declaration of the same key (the child's) wins.
     *
     * @param list<array<string, mixed>> $declared
     * @return array<string, array{key: string, type: string, label: string, default: mixed, options: list<array{value: string, label: string}>}>
     */
    public static function schema(array $declared): array
    {
        $out = [];
        foreach ($declared as $s) {
            $key = $s['key'] ?? null;
            $type = $s['type'] ?? null;
            if (!is_string($key) || !preg_match(self::KEY, $key) || !in_array($type, self::TYPES, true)) {
                continue;
            }
            $type = $type === 'color' ? 'colour' : $type;
            $options = [];
            if ($type === 'select') {
                foreach ((array) ($s['options'] ?? []) as $o) {
                    $value = is_array($o) ? ($o['value'] ?? null) : $o;
                    $label = is_array($o) ? ($o['label'] ?? $value) : $o;
                    if (is_scalar($value) && preg_match('/^[a-z0-9-]{1,40}$/', (string) $value)) {
                        $options[] = ['value' => (string) $value, 'label' => mb_substr((string) $label, 0, 80)];
                    }
                }
                if ($options === []) {
                    continue;
                }
            }
            $entry = [
                'key' => $key,
                'type' => $type,
                'label' => is_string($s['label'] ?? null) ? mb_substr($s['label'], 0, 120) : $key,
                'default' => null,
                'options' => $options,
            ];
            $entry['default'] = self::clean($entry, $s['default'] ?? null);
            $out[$key] = $entry;
        }
        return $out;
    }

    /**
     * One value against its setting; null when it doesn't fit.
     *
     * @param array{key: string, type: string, options: list<array{value: string, label: string}>} $setting
     */
    public static function clean(array $setting, mixed $value): mixed
    {
        switch ($setting['type']) {
            case 'toggle':
                return is_bool($value) ? $value : (in_array($value, [1, '1', 'true', 'on'], true) ? true : (in_array($value, [0, '0', 'false', 'off', ''], true) ? false : null));
            case 'colour':
                if (!is_string($value) || !preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value)) {
                    return null;
                }
                $hex = strtolower(substr($value, 1));
                return '#' . (strlen($hex) === 3 ? $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2] : $hex);
            case 'image':
                return is_string($value) && $value !== '' && Branding::isAcceptableLogo($value) ? $value : null;
            case 'text':
                if (!is_string($value)) {
                    return null;
                }
                $max = $setting['key'] === 'shortName' ? Branding::SHORT_NAME_MAX : ($setting['key'] === 'name' ? Branding::NAME_MAX : 200);
                $text = mb_substr(Validator::cleanText($value), 0, $max);
                return $text === '' ? null : $text;
            case 'select':
                return is_string($value) && in_array($value, array_column($setting['options'], 'value'), true) ? $value : null;
        }
        return null;
    }

    /**
     * Stored values over defaults, each cleaned; a setting with neither is absent.
     *
     * @param array<string, array{key: string, type: string, label: string, default: mixed, options: list<array{value: string, label: string}>}> $schema
     * @param array<mixed> $stored
     * @return array<string, mixed>
     */
    public static function values(array $schema, array $stored): array
    {
        $out = [];
        foreach ($schema as $key => $setting) {
            $value = array_key_exists($key, $stored) ? self::clean($setting, $stored[$key]) : null;
            $value ??= $setting['default'];
            if ($value !== null) {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $branding
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public static function mergeBranding(array $branding, array $values): array
    {
        foreach (self::BRANDING_KEYS as $key) {
            if (isset($values[$key])) {
                $branding[$key] = $values[$key];
            }
        }
        return Branding::normalizeBranding($branding);
    }

    /**
     * Custom properties for colour and image settings that aren't branding
     * fields. Values were cleaned above: a colour is #rrggbb, an image URL
     * has no quote, bracket, backslash or space, so nothing here can close
     * the declaration or the <style>.
     *
     * @param array<string, array{key: string, type: string, label: string, default: mixed, options: list<array{value: string, label: string}>}> $schema
     * @param array<string, mixed> $values
     */
    public static function css(array $schema, array $values): string
    {
        $decls = [];
        foreach ($values as $key => $value) {
            $type = $schema[$key]['type'] ?? null;
            if (in_array($key, self::BRANDING_KEYS, true) || !is_string($value)) {
                continue;
            }
            if ($type === 'colour') {
                $decls[] = '--theme-' . self::kebab($key) . ':' . $value;
            } elseif ($type === 'image') {
                $decls[] = '--theme-' . self::kebab($key) . ':url("' . $value . '")';
            }
        }
        return $decls === [] ? '' : ':root{' . implode(';', $decls) . '}';
    }

    /**
     * @param array<string, array{key: string, type: string, label: string, default: mixed, options: list<array{value: string, label: string}>}> $schema
     * @param array<string, mixed> $values
     */
    public static function classes(array $schema, array $values): string
    {
        $classes = [];
        foreach ($values as $key => $value) {
            $type = $schema[$key]['type'] ?? null;
            if ($type === 'toggle' && $value === true) {
                $classes[] = 'theme-' . self::kebab($key);
            } elseif ($type === 'select' && is_string($value)) {
                $classes[] = 'theme-' . self::kebab($key) . '-' . $value;
            }
        }
        return implode(' ', $classes);
    }

    public static function kebab(string $key): string
    {
        return strtolower((string) preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', str_replace('_', '-', $key)));
    }

    // With the app ------------------------------------------------------------

    /** @return array<string, array{key: string, type: string, label: string, default: mixed, options: list<array{value: string, label: string}>}> */
    public static function activeSchema(App $app): array
    {
        return self::schema($app->themes()->customizer());
    }

    /** @return array<string, mixed> */
    public static function activeValues(App $app): array
    {
        $slug = $app->themes()->active()['slug'] ?? ThemeLoader::DEFAULT;
        $stored = $app->settings()->get('theme.customizer.' . $slug, []);
        return self::values(self::activeSchema($app), is_array($stored) ? $stored : []);
    }

    /**
     * What the page is painted with: branding, customizer over it.
     *
     * @return array{branding: array<string, mixed>, css: string, classes: string, values: array<string, mixed>}
     */
    public static function forPage(App $app): array
    {
        $branding = Branding::load($app->db());
        try {
            $schema = self::activeSchema($app);
            $values = self::activeValues($app);
        } catch (\Throwable) {
            $schema = [];
            $values = [];
        }
        $merged = self::mergeBranding($branding, $values);
        return [
            'branding' => $merged,
            'css' => Branding::brandingCss($merged) . self::css($schema, $values),
            'classes' => self::classes($schema, $values),
            'values' => $values,
        ];
    }
}
