<?php

declare(strict_types=1);

/*
 * For the minority of hosts with a shell: applies every pending core and
 * plugin migration. Never required — /admin/update does the same from the
 * browser, one file per request.
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
$migrator = new App\Core\Migrator($app->db(), $app->paths->app() . '/Migrations');
while (($result = $migrator->runNext())['applied'] !== null) {
    echo "applied {$result['applied']} ({$result['remaining']} left)\n";
}
echo "up to date\n";
