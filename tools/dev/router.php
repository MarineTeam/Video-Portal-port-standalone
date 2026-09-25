<?php

// Development only: `php -S 127.0.0.1:8080 -t public tools/dev/router.php`
// stands in for Apache's rewrite — a real file under public/ is served as
// is, anything else goes to the front controller.
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$file = __DIR__ . '/../../public' . $path;
if ($path !== '/' && is_file($file) && !str_ends_with($file, '.php')) {
    return false;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/../../public/index.php';
