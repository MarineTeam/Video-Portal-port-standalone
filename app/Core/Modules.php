<?php

declare(strict_types=1);

namespace App\Core;

/**
 * The core modules, each registering its routes. Bundled and third-party
 * plugins register theirs afterwards through routes.register.
 */
final class Modules
{
    /** @var list<class-string> */
    public const CORE = [
        \App\Modules\Site\Routes::class,
        \App\Modules\Site\Assets::class,
        \App\Modules\Access\Routes::class,
        \App\Modules\Access\AdminRoutes::class,
        \App\Modules\Branding\AdminRoutes::class,
        \App\Modules\Themes\AdminRoutes::class,
        \App\Modules\Update\Routes::class,
        \App\Modules\Tools\Routes::class,
        \App\Modules\Admin\Routes::class,
        \App\Modules\Jobs\Routes::class,
        \App\Modules\Uploads\Uploads::class,
    ];

    public static function register(App $app): void
    {
        foreach (self::CORE as $class) {
            $class::register($app->router, $app);
        }
    }
}
