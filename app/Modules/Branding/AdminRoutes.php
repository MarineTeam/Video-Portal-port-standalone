<?php

declare(strict_types=1);

namespace App\Modules\Branding;

use App\Core\ApiError;
use App\Core\App;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Url;
use App\Core\Validator;
use App\Modules\Audit\Audit;
use App\Modules\Files\Images;
use App\Modules\Uploads\Uploads;

/** /admin/branding and /api/admin/branding: the name, short name, three colours and the logo. */
final class AdminRoutes
{
    public static function register(Router $r, App $app): void
    {
        $admin = Middleware::admin($app);
        $r->get('/admin/branding', fn () => $app->page('admin/branding', ['title' => 'Branding', 'branding' => Branding::load($app->db()), 'gd' => extension_loaded('gd')], 200, 'layouts/admin'), [$admin]);
        $r->get('/api/admin/branding', fn () => Response::json(Branding::load($app->db())), [$admin]);
        $r->add('PUT', '/api/admin/branding', function (Request $req) use ($app): Response {
            $input = $req->input();
            $data = Validator::check($input, [
                'name' => ['string', 'required', 'max' => Branding::NAME_MAX],
                'shortName' => ['string', 'required', 'max' => Branding::SHORT_NAME_MAX],
                'brand' => ['hex', 'required'],
                'brandDeep' => ['hex', 'required'],
                'brandLight' => ['hex', 'required'],
                'logoUrl' => ['string', 'nullable', 'max' => 2000],
            ]);
            if (($data['logoUrl'] ?? null) !== null && !Branding::isAcceptableLogo($data['logoUrl'])) {
                throw ApiError::invalid('The logo must be an https:// address or a file uploaded here.');
            }
            $saved = Branding::save($app->db(), $data);
            Audit::log($app->db(), (string) $app->currentUser()->email(), 'branding.update', 'BrandSettings', 'singleton');
            return Response::json($saved);
        }, [$admin]);
        $r->add('DELETE', '/api/admin/branding', function () use ($app): Response {
            Branding::reset($app->db());
            Audit::log($app->db(), (string) $app->currentUser()->email(), 'branding.reset', 'BrandSettings', 'singleton');
            return Response::json(Branding::load($app->db()));
        }, [$admin]);
        $r->post('/api/admin/branding/logo', function (Request $req) use ($app): Response {
            $upload = Uploads::take($app, (string) ($req->input()['upload'] ?? ''), 'image');
            try {
                $name = Images::store($upload['path'], $upload['fileName'], $app->paths->storage('media/branding'), maxSide: 1024);
            } finally {
                Uploads::discard($app, (string) ($req->input()['upload'] ?? ''));
            }
            $current = Branding::load($app->db());
            $saved = Branding::save($app->db(), ['logoUrl' => Url::to('/media/branding/' . $name)] + $current);
            Audit::log($app->db(), (string) $app->currentUser()->email(), 'branding.logo', 'BrandSettings', 'singleton');
            return Response::json($saved);
        }, [$admin]);
    }
}
