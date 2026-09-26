<?php

declare(strict_types=1);

namespace App\Modules\Profile;

use App\Core\App;
use App\Core\ApiError;
use App\Core\Db;
use App\Core\Id;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Url;
use App\Modules\Branding\Branding;
use App\Support\Ics;

/**
 * A member's own diary as a calendar feed: what they're serving at, what
 * they've signed up for, and the dates a rota names them on, at
 * /api/calendar/<token>/marine-team.ics. Plugins fill it through the
 * calendar.entries filter.
 *
 * The token in the URL is the whole of the authentication, because a
 * calendar application cannot log in. So: nobody has one until they ask,
 * it can be replaced (which stops every calendar following the old link)
 * or stopped, it is never kept by a shared cache, it says noindex, and
 * `calendarToken` is on the data export's forbidden-key list so it can
 * never travel in a downloaded file.
 */
final class Calendar
{
    public const FILE = 'marine-team.ics';

    public static function register(Router $r, App $app, callable $member): void
    {
        $r->post('/api/profile/calendar', function () use ($app): Response {
            $token = self::make($app, (string) $app->currentUser()->id());
            return Response::json(['url' => self::url($token)]);
        }, [$member]);
        $r->add('DELETE', '/api/profile/calendar', function () use ($app): Response {
            self::stop($app, (string) $app->currentUser()->id());
            return Response::json(['ok' => true]);
        }, [$member]);
        $r->get('/api/calendar/[token]/' . self::FILE, fn (Request $req, array $p) => self::feed($app, (string) $p['token']));
    }

    public static function url(?string $token): ?string
    {
        return $token === null || $token === '' ? null : Url::absolute('/api/calendar/' . rawurlencode($token) . '/' . self::FILE);
    }

    /** A new token, which stops every calendar following the old link. */
    public static function make(App $app, string $userId): string
    {
        $token = Id::token(24);
        $app->db()->update('users', ['calendar_token' => $token], ['id' => $userId]);
        return $token;
    }

    public static function stop(App $app, string $userId): void
    {
        $app->db()->update('users', ['calendar_token' => null], ['id' => $userId]);
    }

    public static function tokenFor(Db $db, string $userId): ?string
    {
        $token = $db->value('SELECT calendar_token FROM {{users}} WHERE id = ?', [$userId]);
        return $token === null ? null : (string) $token;
    }

    private static function feed(App $app, string $token): Response
    {
        $user = strlen($token) >= 16 ? $app->db()->one('SELECT * FROM {{users}} WHERE calendar_token = ? AND authorized = 1', [$token]) : null;
        if ($user === null) {
            throw ApiError::notFound();
        }
        $entries = $app->hooks->apply('calendar.entries', [], $app, $user);
        $body = Ics::icsCalendar(is_array($entries) ? array_values(array_filter($entries, 'is_array')) : [], (string) (Branding::load($app->db())['name'] ?? '') . ' — my diary');
        $response = new Response(200, $body);
        $response->header('Content-Type', 'text/calendar; charset=utf-8');
        $response->header('Content-Disposition', 'inline; filename="' . Ics::icsFilename(self::FILE) . '"');
        // A personal feed is never kept by a shared cache, and never indexed.
        $response->header('Cache-Control', 'private, no-store');
        $response->header('X-Robots-Tag', 'noindex');
        return $response;
    }
}
