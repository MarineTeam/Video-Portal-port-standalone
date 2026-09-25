<?php

declare(strict_types=1);

// For a host whose cron runs PHP scripts rather than fetching URLs, every
// five minutes:   php /path/to/site/bin/cron.php
// Runs due jobs exactly as /cron/run does, without the token (a shell is
// already the site's owner). Plugins are booted first, so their jobs register.

if (PHP_SAPI !== 'cli') {
    exit(1);
}
/** @var App\Core\App $app */
$app = require dirname(__DIR__) . '/app/bootstrap.php';
if (!$app->isInstalled()) {
    exit(0);
}
$request = new App\Core\Request('GET', '/cron/run', id: bin2hex(random_bytes(8)));
App\Core\Url::configure((string) ($app->config['base_url'] ?? 'http://localhost'));
App\Core\Crypto::configure((string) $app->config['app_key']);
App\Core\Log::configure($app->paths->storage('logs'));
App\Core\Cache::configure($app->paths->storage('cache'));
$app->handle($request); // sets up the request context
$app->settings()->set('cron.last_real_at', time());
foreach (App\Modules\Jobs\Jobs::scheduler($app)->run() as $ran) {
    echo "{$ran['name']}: {$ran['status']}\n";
}
