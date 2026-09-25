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

    public function migrations(): ?string
    {
        $dir = $this->dir . '/migrations';
        return $this->dir !== '' && is_dir($dir) ? $dir : null;
    }
}
