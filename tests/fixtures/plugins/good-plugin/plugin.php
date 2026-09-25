<?php
/**
 * Plugin Name: Good plugin
 * Slug: good-plugin
 * Version: 1.0.0
 * Description: Adds one route and one nav item, using nothing but the hook API.
 */
use App\Core\App;
use App\Core\Hooks;
use App\Core\Response;
use App\Core\Router;
use App\Modules\Plugins\BasePlugin;

return new class (__DIR__) extends BasePlugin {
    public function boot(Hooks $hooks, App $app): void
    {
        $hooks->on('routes.register', function (Router $r): void {
            $r->get('/hello-plugin', fn () => Response::text('hello from a plugin'));
        });
        $hooks->filter('nav.sections', fn (array $nav) => [...$nav, ['href' => '/hello-plugin', 'label' => 'Hello', 'icon' => 'sparkle']]);
    }
};
