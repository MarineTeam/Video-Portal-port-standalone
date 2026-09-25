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
    // The installer lives beside app/, in install/, so it can be deleted
    // after installation on hosts that like to.
    if (str_starts_with($class, 'App\\Install\\')) {
        $file = dirname(__DIR__) . '/install/' . str_replace('\\', '/', substr($class, 12)) . '.php';
    } else {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    }
    if (is_file($file)) {
        require $file;
    }
});

require_once __DIR__ . '/helpers.php';

$vendor = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($vendor)) {
    require_once $vendor;
}
