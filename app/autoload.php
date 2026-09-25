<?php

declare(strict_types=1);

/*
 * The application's own autoloader. There is no Composer on the host, so the
 * app never depends on vendor/autoload.php for its own classes; vendored
 * third-party libraries (PHPMailer, web-push, php-jwt) are loaded through
 * vendor/autoload.php when the release build shipped one.
 */
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

$vendor = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($vendor)) {
    require_once $vendor;
}
