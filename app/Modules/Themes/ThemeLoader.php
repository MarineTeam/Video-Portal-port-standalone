<?php

declare(strict_types=1);

namespace App\Modules\Themes;

use App\Core\App;
use App\Core\Cache;
use App\Core\Log;

/**
 * Themes: themes/<slug>/ with theme.json, templates/ mirroring app/Templates
 * (any one of which it may override), assets/theme.css and theme.js, an
 * optional functions.php receiving the same hooks as a plugin, and an
 * optional customizer.json whose settings merge over the branding.
 *
 * Resolution is child → parent → core. A theme whose functions.php throws is
 * switched back to the default with the same notice a plugin gets.
 */
final class ThemeLoader
{
    public const DEFAULT = 'default';

    /** @var array<string, mixed>|null */
    private ?array $active = null;

    /** @var list<array<string, mixed>> the active theme and its ancestors, child first */
    private array $lineage = [];

    public function __construct(private readonly App $app)
    {
    }

    /** @return array<string, array<string, mixed>> */
    public function available(): array
    {
        $out = [];
        foreach (glob($this->app->paths->themes . '/*/theme.json') ?: [] as $file) {
            $meta = self::readMeta($file);
            if ($meta !== null && $meta['slug'] === basename(dirname($file))) {
                $meta['dir'] = dirname($file);
                $out[$meta['slug']] = $meta;
            }
        }
        return $out;
    }

    /** @return array<string, mixed>|null */
    public static function readMeta(string $file): ?array
    {
        $meta = json_decode((string) @file_get_contents($file), true);
        if (!is_array($meta) || !is_string($meta['slug'] ?? null) || !preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $meta['slug'])) {
            return null;
        }
        return [
            'slug' => $meta['slug'],
            'name' => is_string($meta['name'] ?? null) ? mb_substr($meta['name'], 0, 120) : $meta['slug'],
            'version' => is_string($meta['version'] ?? null) ? $meta['version'] : '0.0.0',
            'parent' => is_string($meta['parent'] ?? null) ? $meta['parent'] : null,
            'author' => is_string($meta['author'] ?? null) ? $meta['author'] : '',
            'screenshot' => is_string($meta['screenshot'] ?? null) ? $meta['screenshot'] : null,
        ];
    }

    public function activeSlug(): string
    {
        try {
            $slug = $this->app->settings()->string('theme.active', self::DEFAULT);
        } catch (\Throwable) {
            $slug = self::DEFAULT;
        }
        return $slug === '' ? self::DEFAULT : $slug;
    }

    public function boot(bool $defaultOnly = false): void
    {
        $themes = $this->available();
        $slug = $defaultOnly ? self::DEFAULT : $this->activeSlug();
        if (!isset($themes[$slug])) {
            $slug = self::DEFAULT;
        }
        $lineage = [];
        $seen = [];
        $current = $themes[$slug] ?? null;
        while ($current !== null && !isset($seen[$current['slug']]) && count($lineage) < 5) {
            $seen[$current['slug']] = true;
            $lineage[] = $current;
            $current = $current['parent'] !== null ? ($themes[$current['parent']] ?? null) : null;
        }
        $this->lineage = $lineage;
        $this->active = $lineage[0] ?? null;

        $view = $this->app->view();
        foreach (array_reverse($lineage) as $theme) {
            if (is_dir($theme['dir'] . '/templates')) {
                $view->prependPath($theme['dir'] . '/templates');
            }
        }
        foreach (array_reverse($lineage) as $theme) {
            $functions = $theme['dir'] . '/functions.php';
            if (!is_file($functions)) {
                continue;
            }
            try {
                $this->app->hooks->as('theme:' . $theme['slug'], function () use ($functions) {
                    $hooks = $this->app->hooks;
                    $app = $this->app;
                    (static function (string $__file) use ($hooks, $app): void {
                        require $__file;
                    })($functions);
                });
            } catch (\Throwable $e) {
                $this->app->hooks->forget('theme:' . $theme['slug']);
                $this->fallBack($theme['slug'], $e);
                return;
            }
        }
    }

    private function fallBack(string $slug, \Throwable $e): void
    {
        Log::error("Theme $slug failed to load and was switched back to the default: " . $e->getMessage());
        if ($slug !== self::DEFAULT) {
            $this->app->settings()->set('theme.active', self::DEFAULT);
            $this->app->settings()->set('theme.notice', [
                'slug' => $slug,
                'error' => mb_strcut($e::class . ': ' . $e->getMessage(), 0, 2048),
                'at' => gmdate('c'),
            ]);
        }
        Cache::forget('settings');
    }

    /** @return array<string, mixed>|null */
    public function active(): ?array
    {
        return $this->active;
    }

    /**
     * Stylesheets and scripts the layout adds after the core's: each theme in
     * the lineage, parent first.
     *
     * @return array{css: list<string>, js: list<string>}
     */
    public function assets(): array
    {
        $css = [];
        $js = [];
        foreach (array_reverse($this->lineage) as $theme) {
            foreach (['css' => 'theme.css', 'js' => 'theme.js'] as $kind => $file) {
                $path = $theme['dir'] . '/assets/' . $file;
                if (is_file($path)) {
                    ${$kind}[] = \App\Core\Url::to('/themes/' . $theme['slug'] . '/assets/' . $file, ['v' => (string) filemtime($path)]);
                }
            }
        }
        return ['css' => $css, 'js' => $js];
    }

    /** @return list<array<string, mixed>> the customizer.json settings of the active lineage */
    public function customizer(): array
    {
        $out = [];
        foreach (array_reverse($this->lineage) as $theme) {
            $file = $theme['dir'] . '/customizer.json';
            $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            if (is_array($decoded)) {
                foreach ($decoded as $setting) {
                    if (is_array($setting) && is_string($setting['key'] ?? null) && in_array($setting['type'] ?? '', ['colour', 'color', 'image', 'text', 'select', 'toggle'], true)) {
                        $out[] = $setting;
                    }
                }
            }
        }
        return $out;
    }
}
