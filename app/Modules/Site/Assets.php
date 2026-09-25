<?php

declare(strict_types=1);

namespace App\Modules\Site;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Services\Files\RangeStreamer;

/**
 * /plugins/<slug>/assets/* and /themes/<slug>/assets/*: a plugin's or theme's
 * static files, with long cache headers. Nothing executable and nothing
 * hidden is ever served, and a path can't leave the assets directory.
 *
 * /media/*: images an administrator uploaded (the logo, category artwork),
 * kept in storage/media because storage/ is the only place the site writes.
 * Their names are random and never reused, so they cache forever.
 */
final class Assets
{
    private const TYPES = [
        'js' => 'text/javascript; charset=utf-8', 'mjs' => 'text/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8', 'json' => 'application/json; charset=utf-8',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'ico' => 'image/x-icon', 'woff' => 'font/woff', 'woff2' => 'font/woff2',
        'txt' => 'text/plain; charset=utf-8', 'map' => 'application/json; charset=utf-8',
    ];

    public static function register(Router $r, App $app): void
    {
        $r->get('/plugins/[slug]/assets/[...path]', fn (Request $req, array $p) => self::serve($app->paths->plugins, $p['slug'], $p['path'], $req));
        $r->get('/themes/[slug]/assets/[...path]', fn (Request $req, array $p) => self::serve($app->paths->themes, $p['slug'], $p['path'], $req));
        $r->get('/media/[...path]', fn (Request $req, array $p) => self::media($app->paths->storage('media'), $p['path'], $req));
    }

    /** A stored image, or null. Only images; only names this site generated. */
    public static function resolveMedia(string $root, string $path): ?string
    {
        if (!preg_match('~^[a-z0-9-]{1,40}/[a-f0-9]{16,64}\.(png|jpg|gif|webp)$~', $path)) {
            return null;
        }
        $file = realpath("$root/$path");
        $base = realpath($root);
        if ($base === false || $file === false || !str_starts_with($file, $base . DIRECTORY_SEPARATOR) || !is_file($file)) {
            return null;
        }
        return $file;
    }

    private static function media(string $root, string $path, Request $req): Response
    {
        $file = self::resolveMedia($root, $path);
        if ($file === null) {
            return Response::text('Not found', 404);
        }
        return RangeStreamer::serve($file, $req, [
            'Content-Type' => self::TYPES[strtolower(pathinfo($file, PATHINFO_EXTENSION))],
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public static function resolve(string $root, string $slug, string $path): ?string
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $slug)) {
            return null;
        }
        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment[0] === '.' || !preg_match('/^[A-Za-z0-9._-]+$/', $segment)) {
                return null;
            }
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$ext])) {
            // .php, .phtml, .phar, .htaccess and anything else unlisted.
            return null;
        }
        $base = realpath("$root/$slug/assets");
        $file = realpath("$root/$slug/assets/$path");
        if ($base === false || $file === false || !str_starts_with($file, $base . DIRECTORY_SEPARATOR) || !is_file($file)) {
            return null;
        }
        return $file;
    }

    private static function serve(string $root, string $slug, string $path, Request $req): Response
    {
        $file = self::resolve($root, $slug, $path);
        if ($file === null) {
            return Response::text('Not found', 404);
        }
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $versioned = $req->query('v') !== null;
        return RangeStreamer::serve($file, $req, [
            'Content-Type' => self::TYPES[$ext],
            'Cache-Control' => $versioned ? 'public, max-age=31536000, immutable' : 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
