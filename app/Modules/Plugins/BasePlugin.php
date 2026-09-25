<?php

declare(strict_types=1);

namespace App\Modules\Plugins;

use App\Core\App;
use App\Core\Hooks;

/** No-op defaults, so a plugin writes only the methods it needs. */
abstract class BasePlugin implements Plugin
{
    public function __construct(protected readonly string $dir = '')
    {
    }

    public function boot(Hooks $hooks, App $app): void
    {
    }

    public function activate(App $app): void
    {
    }

    public function deactivate(App $app): void
    {
    }

    public function uninstall(App $app): void
    {
    }

    /** The folder name, which is the slug. */
    protected function slug(): string
    {
        return basename($this->dir);
    }

    /**
     * Serves templates/<name>.php as "<slug>/<name>", so the plugin renders
     * with $app->view()->partial('<slug>/button', …) or $app->page(…).
     */
    protected function useTemplates(Hooks $hooks): void
    {
        $prefix = $this->slug() . '/';
        $dir = $this->dir . '/templates/';
        $hooks->filter('template.resolve', function (?string $file, string $name) use ($prefix, $dir): ?string {
            if ($file === null && str_starts_with($name, $prefix)) {
                $candidate = $dir . substr($name, strlen($prefix)) . '.php';
                return is_file($candidate) ? $candidate : null;
            }
            return $file;
        });
    }

    /** Adds lang/<locale>.php (lang/en.php where a language has none) to the catalogue. */
    protected function useLang(Hooks $hooks): void
    {
        $dir = $this->dir . '/lang/';
        $hooks->filter('lang.catalogue', function (array $strings, string $locale) use ($dir): array {
            $file = is_file($dir . $locale . '.php') ? $dir . $locale . '.php' : $dir . 'en.php';
            return is_file($file) ? $strings + (array) require $file : $strings;
        });
    }

    /** The address of assets/<path>, with its modification time to bust caches. */
    protected function asset(string $path): string
    {
        $file = $this->dir . '/assets/' . $path;
        return \App\Core\Url::to('/plugins/' . $this->slug() . '/assets/' . $path) . (is_file($file) ? '?v=' . filemtime($file) : '');
    }

    public function migrations(): ?string
    {
        $dir = $this->dir . '/migrations';
        return $this->dir !== '' && is_dir($dir) ? $dir : null;
    }
}
