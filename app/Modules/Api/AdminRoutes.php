<?php

declare(strict_types=1);

namespace App\Modules\Api;

use App\Core\ApiError;
use App\Core\App;
use App\Core\Db;
use App\Core\Id;
use App\Core\Json;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Url;
use App\Core\Validator;
use App\Modules\Audit\Audit;

/**
 * Making and revoking API keys (/admin/api-keys).
 *
 * The key itself is shown once, here, and never again: what the list shows
 * afterwards is a prefix, who made it and when it was last used. Revoking
 * keeps the row, so the record of a key survives the key.
 */
final class AdminRoutes
{
    public static function register(Router $r, App $app): void
    {
        $can = Middleware::can($app, 'manage_api_keys');
        $r->get('/admin/api-keys', fn () => self::page($app, null), [$can]);
        $r->post('/api/admin/api-keys', fn (Request $req) => self::create($app, $req), [$can]);
        $r->add('DELETE', '/api/admin/api-keys/[id]', fn (Request $req, array $p) => self::revoke($app, (string) $p['id']), [$can]);
        $r->get('/api/admin/api-keys', fn () => Response::json(['keys' => self::keys($app)]), [$can]);
    }

    /** @param ?array{key: string, name: string} $made the one time it is shown */
    private static function page(App $app, ?array $made): Response
    {
        $scopes = [];
        foreach (Keys::SCOPES as $name => $about) {
            $scopes[] = ['scope' => $name] + $about;
        }
        return $app->page('admin/api-keys', [
            'title' => 'API keys',
            'keys' => self::keys($app),
            'scopes' => $scopes,
            'made' => $made,
            'docsUrl' => Url::absolute('/api/v1'),
        ], 200, 'layouts/admin');
    }

    /** @return list<array<string, mixed>> */
    private static function keys(App $app): array
    {
        return array_map(fn (array $k) => [
            'id' => (string) $k['id'],
            'name' => (string) $k['name'],
            'prefix' => (string) $k['prefix'],
            'scopes' => Keys::cleanScopes(json_decode((string) $k['scopes'], true)),
            'createdByEmail' => (string) $k['created_by_email'],
            'createdAt' => Json::instant((string) $k['created_at']),
            'expiresAt' => Json::instant($k['expires_at']),
            'revokedAt' => Json::instant($k['revoked_at']),
            'lastUsedAt' => Json::instant($k['last_used_at']),
            'state' => Keys::state($k),
        ], $app->db()->all('SELECT * FROM {{api_keys}} ORDER BY revoked_at IS NOT NULL, created_at DESC LIMIT 200'));
    }

    private static function create(App $app, Request $req): Response
    {
        $data = Validator::check($req->input(), [
            'name' => ['string', 'required', 'max' => 255],
            'scopes' => ['array', 'max' => 20],
            'expiresAt' => ['date', 'nullable'],
        ]);
        $scopes = Keys::cleanScopes($data['scopes'] ?? []);
        if ($scopes === []) {
            // A key that may read nothing is a key somebody will spend an
            // afternoon debugging.
            throw ApiError::invalid('Choose at least one thing this key may read.');
        }
        $key = Keys::newKey();
        $db = $app->db();
        $id = $db->insert('api_keys', [
            'name' => trim((string) $data['name']),
            'hashed_key' => Keys::hash($key),
            'prefix' => Keys::prefixOf($key),
            'scopes' => (string) json_encode($scopes),
            'created_by_email' => (string) $app->currentUser()->email(),
            'expires_at' => isset($data['expiresAt']) ? $data['expiresAt'] . ' 23:59:59' : null,
        ]);
        Audit::log($db, (string) $app->currentUser()->email(), 'apiKey.create', 'ApiKey', $id, implode(' ', $scopes));
        // The one time the key itself is handed over.
        return Response::json(['ok' => true, 'id' => $id, 'key' => $key, 'keys' => self::keys($app)], 201);
    }

    private static function revoke(App $app, string $id): Response
    {
        $db = $app->db();
        $done = $db->run(
            'UPDATE {{api_keys}} SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL',
            [Db::now(), Id::isValid($id) ? $id : ''],
        )->rowCount();
        if ($done === 0) {
            throw ApiError::notFound();
        }
        Audit::log($db, (string) $app->currentUser()->email(), 'apiKey.revoke', 'ApiKey', $id);
        // The row stays: the record of a key outlives the key.
        return Response::json(['ok' => true, 'keys' => self::keys($app)]);
    }
}
