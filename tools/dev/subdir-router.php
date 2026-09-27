<?php

// Development only: serves the site as if it had been unzipped into a
// subdirectory, which is how a lot of shared hosting ends up. Apache would do
// the same with a DocumentRoot and a rewrite; this stands in for it.
//
//   MT_STORAGE_DIR=/somewhere/sub-storage \
//   php -S 127.0.0.1:8099 -t public tools/dev/subdir-router.php
//
// The storage directory it is pointed at holds a config.php whose base_url
// ends in the same prefix; it may share a database with the root install.

$root = dirname(__DIR__, 2);
$prefix = rtrim((string) (getenv('MT_SUBDIR_PREFIX') ?: '/church'), '/');

$uri = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if ($uri !== $prefix && !str_starts_with($uri, $prefix . '/')) {
    // Whatever else lives on this domain. Answering here proves the site
    // never emits an address it does not own.
    http_response_code(404);
    header('Content-Type: text/plain');
    echo "outside the install\n";
    return true;
}

$path = substr($uri, strlen($prefix));
$path = $path === '' ? '/' : $path;
$file = $root . '/public' . $path;
if ($path !== '/' && is_file($file) && !str_ends_with($file, '.php')) {
    $types = [
        'css' => 'text/css', 'js' => 'text/javascript', 'mjs' => 'text/javascript',
        'json' => 'application/json', 'webmanifest' => 'application/manifest+json',
        'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg',
        'webp' => 'image/webp', 'ico' => 'image/x-icon', 'html' => 'text/html',
        'woff2' => 'font/woff2', 'mp4' => 'video/mp4', 'txt' => 'text/plain',
    ];
    header('Content-Type: ' . ($types[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
    readfile($file);
    return true;
}

$_SERVER['SCRIPT_NAME'] = $prefix . '/index.php';
require $root . '/public/index.php';
