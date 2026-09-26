<?php
/**
 * Plugin Name: Needs the future
 * Slug: needs-the-future
 * Version: 1.0.0
 * Requires App: 99.0
 * Description: Asks for a version of the site that does not exist yet, so the loader should leave it off rather than run it.
 */
use App\Core\App;
use App\Core\Hooks;
use App\Modules\Plugins\BasePlugin;

return new class (__DIR__) extends BasePlugin {
    public function boot(Hooks $hooks, App $app): void
    {
        throw new \RuntimeException('This should never run: the requirement is not met.');
    }
};
