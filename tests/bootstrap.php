<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/autoload.php';

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Tests\\')) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 6)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

date_default_timezone_set('UTC');
App\Core\Crypto::configure(str_repeat('ab', 32));
App\Core\Url::configure('https://church.example.org');
