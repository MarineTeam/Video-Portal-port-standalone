<?php

declare(strict_types=1);

/*
 * For the minority of hosts with a shell: applies every pending core and
 * active-plugin migration and records the version. Never required —
 * /admin/update does the same from the browser, one file per request. It
 * doesn't touch maintenance mode: take the site down first if you need to.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}
/** @var App\Core\App $app */
$app = require dirname(__DIR__) . '/app/bootstrap.php';
if (!$app->isInstalled()) {
    fwrite(STDERR, "Not installed yet: open the site in a browser to run the installer.\n");
    exit(1);
}
App\Core\Crypto::configure((string) $app->config['app_key']);
App\Core\Cache::configure($app->paths->storage('cache'));
$updater = new App\Modules\Update\Updater($app);
$migrator = $updater->migrator();
while (($result = $migrator->runNext())['applied'] !== null) {
    echo "applied {$result['applied']} ({$result['remaining']} left)\n";
}
$app->settings()->set(App\Modules\Update\Updater::VERSION_SETTING, App\Core\App::VERSION);
App\Modules\Update\Updater::clearCaches();
echo 'up to date at ' . App\Core\App::VERSION . "\n";
