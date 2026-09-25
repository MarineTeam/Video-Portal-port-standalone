<?php

declare(strict_types=1);

namespace App\Modules\Branding;

use App\Core\Cache;
use App\Core\Db;

/**
 * The site's skin: a name, a short name, a logo and three colours; every
 * other colour token is derived here, so the admin form stays three swatches
 * and a theme paints entirely through the custom properties written below.
 *
 * Colours are validated on write and again on read, and normalised before
 * they reach the stylesheet: a bad value here would paint the whole site, or
 * — interpolated raw into a <style> — do worse.
 */
final class Branding
{
    public const DEFAULTS = [
        'name' => 'Marine Team',
        'shortName' => 'Marine Team',
        'brand' => '#1a8fd1',
        'brandDeep' => '#0288d1',
        'brandLight' => '#4fc3f7',
        'logoUrl' => null,
    ];

    public const NAME_MAX = 60;
    public const SHORT_NAME_MAX = 30;

    public static function isHexColor(mixed $value): bool
    {
        return is_string($value) && preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $value) === 1;
    }

    public static function normalizeHex(string $hex): string
    {
        $hex = strtolower($hex);
        if (strlen($hex) === 4) {
            $hex = '#' . $hex[1] . $hex[1] . $hex[2] . $hex[2] . $hex[3] . $hex[3];
        }
        return $hex;
    }

    /** "#1a8fd1" → "26, 143, 209", for rgba() literals. */
    public static function hexToRgbChannels(string $hex): string
    {
        $hex = self::normalizeHex($hex);
        return implode(', ', array_map(fn ($i) => (string) hexdec(substr($hex, $i, 2)), [1, 3, 5]));
    }

    /**
     * @param array<string, mixed>|null $raw
     * @return array{name: string, shortName: string, brand: string, brandDeep: string, brandLight: string, logoUrl: ?string}
     */
    public static function normalizeBranding(?array $raw): array
    {
        $raw ??= [];
        $out = self::DEFAULTS;
        foreach (['name' => self::NAME_MAX, 'shortName' => self::SHORT_NAME_MAX] as $field => $max) {
            $value = $raw[$field] ?? null;
            if (is_string($value)) {
                $value = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $value));
                if ($value !== '') {
                    $out[$field] = mb_substr($value, 0, $max);
                }
            }
        }
        foreach (['brand', 'brandDeep', 'brandLight'] as $field) {
            if (self::isHexColor($raw[$field] ?? null)) {
                $out[$field] = self::normalizeHex($raw[$field]);
            }
        }
        $logo = $raw['logoUrl'] ?? null;
        $out['logoUrl'] = is_string($logo) && self::isAcceptableLogo($logo) ? $logo : null;
        return $out;
    }

    /** A same-origin path, or https — never javascript:, data:, or protocol-relative. */
    public static function isAcceptableLogo(string $url): bool
    {
        if (strlen($url) > 2000 || preg_match('/[\s"\'<>\\\\]/', $url)) {
            return false;
        }
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return true;
        }
        return str_starts_with(strtolower($url), 'https://') && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * The custom properties both themes are painted with.
     *
     * @param array<string, mixed> $branding
     */
    public static function brandingCss(array $branding): string
    {
        $b = self::normalizeBranding($branding);
        $brand = $b['brand'];
        $deep = $b['brandDeep'];
        $light = $b['brandLight'];
        $rgb = self::hexToRgbChannels($brand);
        $lightRgb = self::hexToRgbChannels($light);
        return ":root{--brand:$brand;--brand-deep:$deep;--brand-light:$light;--brand-rgb:$rgb;"
            . "--accent:$deep;--accent-strong:$brand;--accent-soft:rgba($rgb, 0.12);--accent-ring:rgba($rgb, 0.35);"
            . "--hero-from:$deep;--hero-to:$light}"
            . "html.dark{--accent:$light;--accent-strong:$light;--accent-soft:rgba($lightRgb, 0.16);--accent-ring:rgba($lightRgb, 0.4);"
            . "--hero-from:$brand;--hero-to:$deep}";
    }

    /** @return array{name: string, shortName: string, brand: string, brandDeep: string, brandLight: string, logoUrl: ?string} */
    public static function load(Db $db): array
    {
        return Cache::remember('branding', 86400, function () use ($db) {
            try {
                $row = $db->one('SELECT name, short_name, brand, brand_deep, brand_light, logo_url FROM {{brand_settings}} WHERE id = ?', ['singleton']);
            } catch (\Throwable) {
                $row = null;
            }
            return self::normalizeBranding($row === null ? null : [
                'name' => $row['name'],
                'shortName' => $row['short_name'],
                'brand' => $row['brand'],
                'brandDeep' => $row['brand_deep'],
                'brandLight' => $row['brand_light'],
                'logoUrl' => $row['logo_url'],
            ]);
        });
    }

    /** @param array<string, mixed> $input */
    public static function save(Db $db, array $input): array
    {
        $b = self::normalizeBranding($input);
        $db->run(
            'INSERT INTO {{brand_settings}} (id, name, short_name, brand, brand_deep, brand_light, logo_url)
             VALUES (\'singleton\', ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), short_name = VALUES(short_name), brand = VALUES(brand),
               brand_deep = VALUES(brand_deep), brand_light = VALUES(brand_light), logo_url = VALUES(logo_url)',
            [$b['name'], $b['shortName'], $b['brand'], $b['brandDeep'], $b['brandLight'], $b['logoUrl']],
        );
        Cache::forget('branding');
        return $b;
    }

    public static function reset(Db $db): void
    {
        $db->delete('brand_settings', ['id' => 'singleton']);
        Cache::forget('branding');
    }

    /**
     * The web app manifest, rendered from branding (/api/manifest).
     *
     * @param array<string, mixed> $branding
     * @return array<string, mixed>
     */
    public static function manifest(array $branding, string $basePath): array
    {
        $b = self::normalizeBranding($branding);
        $base = rtrim($basePath, '/');
        return [
            'name' => $b['name'],
            'short_name' => $b['shortName'],
            'description' => 'Watch sermons, series, and downloads.',
            'start_url' => $base . '/',
            'display' => 'standalone',
            'background_color' => $b['brandDeep'],
            'theme_color' => $b['brandDeep'],
            'icons' => [
                ['src' => "$base/icon.svg", 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any'],
                ['src' => "$base/icon-192.png", 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => "$base/icon-512.png", 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => "$base/icon-maskable-192.png", 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
                ['src' => "$base/icon-maskable-512.png", 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ];
    }
}
