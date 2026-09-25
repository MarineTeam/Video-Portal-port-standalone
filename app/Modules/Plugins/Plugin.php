<?php

declare(strict_types=1);

namespace App\Modules\Plugins;

use App\Core\App;
use App\Core\Hooks;

/**
 * What plugins/<slug>/plugin.php returns. Plugin code runs with the whole
 * application's privileges, like a WordPress plugin: install only what you
 * would let edit the site.
 */
interface Plugin
{
    /** Every request while active: register hooks, routes, jobs, providers. */
    public function boot(Hooks $hooks, App $app): void;

    /** Once, on activation. */
    public function activate(App $app): void;

    /** On deactivation. Must not drop data. */
    public function deactivate(App $app): void;

    /** Only from the admin's explicit "delete data". */
    public function uninstall(App $app): void;

    /**
     * The directory holding this plugin's numbered migrations (tables named
     * p_<slug>_…), or null for none.
     */
    public function migrations(): ?string;
}
