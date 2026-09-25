<?php

declare(strict_types=1);

namespace App\Modules\Site;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Url;
use App\Modules\Branding\Branding;
use App\Modules\I18n\I18n;

final class Routes
{
    public static function register(Router $r, App $app): void
    {
        $r->get('/api/manifest', function () use ($app): Response {
            $response = Response::json(Branding::manifest(\App\Modules\Themes\Appearance::forPage($app)['branding'], Url::basePath()));
            $response->header('Content-Type', 'application/manifest+json; charset=utf-8');
            $response->header('Cache-Control', 'public, max-age=300');
            return $response;
        });
        $r->post('/api/locale', function (Request $req): Response {
            $locale = $req->input()['locale'] ?? null;
            if (!is_string($locale) || !isset(I18n::LOCALES[$locale])) {
                return Response::error('Unknown language', 400, 'invalid');
            }
            $response = Response::json(['locale' => $locale]);
            // Not HttpOnly: the page's own script reads it to stay in step.
            $response->cookie(I18n::COOKIE, $locale, [
                'maxAge' => 365 * 86400,
                'path' => Url::basePath() === '' ? '/' : Url::basePath() . '/',
                'secure' => $req->https,
                'httponly' => false,
            ]);
            return $response;
        });
    }
}
