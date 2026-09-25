<?php

declare(strict_types=1);

/*
 * Loads the application. The front controller, bin/ scripts and the tests all
 * start here.
 */

require __DIR__ . '/autoload.php';

// Never show a visitor an error, whatever php.ini says.
ini_set('display_errors', '0');
date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

return new App\Core\App(App\Core\Paths::detect(dirname(__DIR__)));
