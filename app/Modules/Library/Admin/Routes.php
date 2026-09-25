<?php

declare(strict_types=1);

namespace App\Modules\Library\Admin;

use App\Core\ApiError;
use App\Core\App;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Url;
use App\Modules\Files\Images;
use App\Modules\Uploads\Uploads;

/** Every library admin screen, and the image upload their cover fields share. */
final class Routes
{
    public static function register(Router $r, App $app): void
    {
        CategoriesAdmin::register($r, $app);
        SeriesAdmin::register($r, $app);
        VideosAdmin::register($r, $app);
        FilesAdmin::register($r, $app);
        ViewersAdmin::register($r, $app);
        SpeakersAdmin::register($r, $app);
        TrashAdmin::register($r, $app);

        // A cover, a speaker's photo: stored as an image this site redrew,
        // under storage/media, and answered with the address to put in the
        // field. (The port's; the original uploaded these to Bunny Storage.)
        $r->post('/api/admin/media', function (Request $req) use ($app): Response {
            $input = $req->input();
            $kind = (string) ($input['kind'] ?? 'covers');
            if (!in_array($kind, ['covers', 'speakers', 'thumbnails'], true)) {
                throw ApiError::invalid('Unknown image kind.');
            }
            $uploadId = (string) ($input['upload'] ?? '');
            $upload = Uploads::take($app, $uploadId, 'image');
            try {
                $name = Images::store($upload['path'], $upload['fileName'], $app->paths->storage('media/' . $kind), maxSide: 1600);
            } finally {
                Uploads::discard($app, $uploadId);
            }
            return Response::json(['url' => Url::to("/media/$kind/$name")], 201);
        }, [Middleware::staff($app)]);
    }
}
