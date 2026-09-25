<?php
/**
 * Plugin Name: Throws in hook
 * Slug: throws-in-hook
 * Version: 1.0.0
 */
use App\Core\App;
use App\Core\Hooks;
use App\Modules\Plugins\BasePlugin;

return new class (__DIR__) extends BasePlugin {
    public function boot(Hooks $hooks, App $app): void
    {
        $hooks->filter('nav.sections', function (array $nav): array {
            throw new RuntimeException('hook boom');
        });
    }
};
