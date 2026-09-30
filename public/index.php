<?php

declare(strict_types=1);

/*
 * The front controller: every request that isn't a static file arrives here.
 */

/** @var App\Core\App $app */
try {
    $app = require dirname(__DIR__) . '/app/bootstrap.php';
} catch (Throwable $e) {
    // Loading failed before there is an app to ask anything of — today the
    // one way that happens is a storage-path.php pointing somewhere that
    // isn't there. The reason goes to the host's error log, where an
    // administrator can read it; a visitor gets the plain page, because the
    // reason names a path on disk.
    error_log('Marine Team: ' . $e::class . ': ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        , '<meta name="viewport" content="width=device-width, initial-scale=1">'
        , '<meta name="robots" content="noindex"><title>Something went wrong</title>'
        , '<style>body{font-family:system-ui,sans-serif;max-width:40rem;margin:4rem auto;padding:0 16px;color:#18181b}'
        , '@media (prefers-color-scheme:dark){body{background:#09090b;color:#fafafa}}</style></head>'
        , '<body><h1>Something went wrong</h1><p>Sorry — this site couldn’t start. '
        , 'The reason is in the server’s error log.</p></body></html>';
    exit;
}

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
