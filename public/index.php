<?php

declare(strict_types=1);

/*
 * The front controller: every request that isn't a static file arrives here.
 */

/** @var App\Core\App $app */
$app = require dirname(__DIR__) . '/app/bootstrap.php';

$trustProxy = (bool) ($app->config['trust_proxy'] ?? false);
$basePath = (string) (parse_url((string) ($app->config['base_url'] ?? ''), PHP_URL_PATH) ?? '');
if ($basePath === '') {
    $script = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
    $basePath = rtrim((string) preg_replace('#/public$#', '', $script), '/');
}

try {
    $request = App\Core\Request::fromGlobals($basePath, $trustProxy);
    $response = $app->handle($request);
} catch (Throwable $e) {
    // The last line of defence: something failed before the app's own
    // handler could run. Log it and show the plain page.
    error_log('Marine Team: ' . $e::class . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    $response = App\Core\ErrorPage::render(500);
}
$response->send();

// After the response is on its way: let scheduled jobs piggyback on a page
// view when no real cron is running.
if ($app->isInstalled() && isset($request) && !$request->isApi() && function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}
if ($app->isInstalled() && isset($request) && !str_starts_with($request->path, '/cron/') && $app->hasDb()) {
    App\Modules\Jobs\Routes::maybeTriggerFromPageView($app);
}
