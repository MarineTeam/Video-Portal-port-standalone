<?php
/**
 * Plugin Name: Playlists
 * Slug:        playlists
 * Version:     1.0.0
 * Description: Lets members build their own ordered video playlists.
 * Author:      Marine Team
 * Requires PHP: 8.2
 * Requires App: 3.0
 * Category Override: yes
 */

declare(strict_types=1);

use App\Core\ApiError;
use App\Core\App;
use App\Core\ErrorPage;
use App\Core\Hooks;
use App\Core\Id;
use App\Core\Json;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Validator;
use App\Modules\Library\Browse;
use App\Modules\Library\ContentAccess;
use App\Modules\Library\ContentTarget;
use App\Modules\Library\Viewer;
use App\Modules\Plugins\BasePlugin;

/**
 * A member's own ordered lists of videos, separate from Watch later. A
 * list is its owner's alone unless they make it shareable; then anyone
 * with its address may read it, signed in or not — and sees only the
 * videos they themselves may open.
 *
 * GET/POST /api/playlists; GET/PATCH/DELETE /api/playlists/[id]
 * ({title}, {public}); POST/PATCH/DELETE /api/playlists/[id]/items
 * ({videoId}; PATCH {videoId, move: up|down} or {order: [videoId…]});
 * GET /api/playlists/for-video?videoId= for the "Add to playlist" menu.
 */
return new class (__DIR__) extends BasePlugin {
    public const MAX_PLAYLISTS = 100;
    public const MAX_ITEMS = 500;

    public function boot(Hooks $hooks, App $app): void
    {
        $this->useLang($hooks);
        $this->useTemplates($hooks);
        $hooks->on('routes.register', function (Router $r) use ($app): void {
            $member = Middleware::member($app);
            $r->get('/playlists', fn () => $app->page('playlists/index', ['title' => t('playlists.title'), 'playlists' => $this->mine($app)]), [$member]);
            $r->get('/playlists/[id]', fn (Request $req, array $p) => $this->show($app, $p['id']));
            $r->get('/api/playlists', fn () => Response::json($this->mine($app)), [$member]);
            $r->post('/api/playlists', fn (Request $req) => $this->create($app, $req), [$member]);
            $r->get('/api/playlists/for-video', fn (Request $req) => $this->forVideo($app, $req), [$member]);
            $r->get('/api/playlists/[id]', function (Request $req, array $p) use ($app): Response {
                $playlist = $this->readable($app, $p['id']);
                return Response::json(['playlist' => $this->present($playlist), 'items' => $this->items($app, $playlist)]);
            });
            $r->add('PATCH', '/api/playlists/[id]', fn (Request $req, array $p) => $this->update($app, $req, $p['id']), [$member]);
            $r->add('DELETE', '/api/playlists/[id]', function (Request $req, array $p) use ($app): Response {
                $app->db()->delete('playlists', ['id' => $this->owned($app, $p['id'])['id']]);
                return Response::json(['ok' => true]);
            }, [$member]);
            $r->post('/api/playlists/[id]/items', fn (Request $req, array $p) => $this->add($app, $req, $p['id']), [$member]);
            $r->add('PATCH', '/api/playlists/[id]/items', fn (Request $req, array $p) => $this->reorder($app, $req, $p['id']), [$member]);
            $r->add('DELETE', '/api/playlists/[id]/items', function (Request $req, array $p) use ($app): Response {
                $playlist = $this->owned($app, $p['id']);
                $videoId = (string) ($req->input()['videoId'] ?? $req->query('videoId') ?? '');
                $app->db()->run('DELETE FROM {{playlist_items}} WHERE playlist_id = ? AND video_id = ?', [$playlist['id'], $videoId]);
                $this->touch($app, (string) $playlist['id']);
                return Response::json(['ok' => true]);
            }, [$member]);
        });
        $hooks->filter('nav.sections', fn (array $nav, ?array $user) => $user === null ? $nav : [...$nav, ['href' => '/playlists', 'label' => t('playlists.nav'), 'icon' => 'playlist']]);
        $hooks->filter('page.video.panels', function (array $panels, array $ctx) use ($app): array {
            $viewer = $ctx['viewer'];
            if (!$viewer instanceof Viewer || !$viewer->signedIn() || !($ctx['plugins']['playlists'] ?? false)) {
                return $panels;
            }
            return [...$panels, ['area' => 'actions', 'order' => 22, 'html' => $app->view()->partial('playlists/add', ['videoId' => (string) $ctx['video']['id'], 'script' => $this->asset('playlists.js')])]];
        });
        $hooks->filter('profile.overview', fn (array $cards, array $user) => [...$cards, [
            'title' => t('playlists.nav'),
            'count' => (int) $app->db()->value('SELECT COUNT(*) FROM {{playlists}} WHERE user_id = ?', [$user['id']]),
            'href' => '/playlists',
        ]]);
    }

    /** @return array<string, mixed> */
    private function present(array $playlist): array
    {
        return Json::row('playlists', $playlist, ['user_id']) + (isset($playlist['item_count']) ? ['itemCount' => (int) $playlist['item_count']] : []);
    }

    /** @return list<array<string, mixed>> */
    private function mine(App $app): array
    {
        return array_map(fn ($p) => $this->present($p), $app->db()->all(
            'SELECT p.*, (SELECT COUNT(*) FROM {{playlist_items}} i WHERE i.playlist_id = p.id) AS item_count FROM {{playlists}} p WHERE p.user_id = ? ORDER BY p.updated_at DESC',
            [$app->currentUser()->id()],
        ));
    }

    /** The owner's, or a shared one; anything else is not there. @return array<string, mixed> */
    private function readable(App $app, string $id): array
    {
        $row = Id::isValid($id) ? $app->db()->one('SELECT * FROM {{playlists}} WHERE id = ?', [$id]) : null;
        if ($row === null || (!(bool) $row['public'] && $row['user_id'] !== $app->currentUser()->id())) {
            throw ApiError::notFound();
        }
        return $row;
    }

    /** @return array<string, mixed> */
    private function owned(App $app, string $id): array
    {
        $row = Id::isValid($id) ? $app->db()->one('SELECT * FROM {{playlists}} WHERE id = ? AND user_id = ?', [$id, $app->currentUser()->id()]) : null;
        return $row ?? throw ApiError::notFound();
    }

    /** The videos, in order, that this reader may open. @return list<array<string, mixed>> */
    private function items(App $app, array $playlist): array
    {
        return (new Browse($app, ContentAccess::for($app)))->videosWhere('1 = 1', [], 'pi.position, pi.created_at', self::MAX_ITEMS, 'JOIN {{playlist_items}} pi ON pi.video_id = v.id AND pi.playlist_id = ?', [$playlist['id']]);
    }

    private function show(App $app, string $id): Response
    {
        try {
            $playlist = $this->readable($app, $id);
        } catch (ApiError) {
            return ErrorPage::render(404);
        }
        $owner = $playlist['user_id'] === $app->currentUser()->id();
        $response = $app->page('playlists/show', [
            'title' => (string) $playlist['title'],
            'playlist' => $this->present($playlist),
            'videos' => $this->items($app, $playlist),
            'owner' => $owner,
            'link' => \App\Core\Url::absolute('/playlists/' . $playlist['id']),
        ]);
        if (!$owner) {
            $response->header('X-Robots-Tag', 'noindex');
        }
        return $response;
    }

    private function create(App $app, Request $req): Response
    {
        $input = $req->input();
        $data = Validator::check($input, ['title' => ['string', 'required', 'min' => 1, 'max' => 255]]);
        $db = $app->db();
        $userId = (string) $app->currentUser()->id();
        if ((int) $db->value('SELECT COUNT(*) FROM {{playlists}} WHERE user_id = ?', [$userId]) >= self::MAX_PLAYLISTS) {
            throw ApiError::invalid(t('playlists.tooMany'));
        }
        $video = isset($input['videoId']) ? ContentTarget::from($app, $input, ['video'], 'playlists') : null;
        $id = $db->insert('playlists', ['id' => Id::new(), 'user_id' => $userId, 'title' => trim((string) $data['title'])]);
        if ($video !== null) {
            $db->insert('playlist_items', ['id' => Id::new(), 'playlist_id' => $id, 'video_id' => $video->id, 'position' => 0]);
        }
        return Response::json($this->present($this->owned($app, $id)), 201);
    }

    private function update(App $app, Request $req, string $id): Response
    {
        $playlist = $this->owned($app, $id);
        $data = Validator::check($req->input(), ['title' => ['string', 'required', 'min' => 1, 'max' => 255], 'public' => ['bool']], partial: true);
        $row = [];
        if (isset($data['title'])) {
            $row['title'] = trim((string) $data['title']);
        }
        if (array_key_exists('public', $data)) {
            $row['public'] = $data['public'] ? 1 : 0;
        }
        if ($row !== []) {
            $app->db()->update('playlists', $row, ['id' => $playlist['id']]);
        }
        return Response::json($this->present($this->owned($app, $id)));
    }

    private function add(App $app, Request $req, string $id): Response
    {
        $playlist = $this->owned($app, $id);
        $video = ContentTarget::from($app, $req->input(), ['video'], 'playlists');
        $db = $app->db();
        if ((int) $db->value('SELECT COUNT(*) FROM {{playlist_items}} WHERE playlist_id = ?', [$playlist['id']]) >= self::MAX_ITEMS) {
            throw ApiError::invalid(t('playlists.full'));
        }
        $db->run(
            'INSERT IGNORE INTO {{playlist_items}} (id, playlist_id, video_id, position) VALUES (?, ?, ?, (SELECT n FROM (SELECT COALESCE(MAX(position), -1) + 1 AS n FROM {{playlist_items}} WHERE playlist_id = ?) t))',
            [Id::new(), $playlist['id'], $video->id, $playlist['id']],
        );
        $this->touch($app, (string) $playlist['id']);
        return Response::json(['ok' => true], 201);
    }

    private function reorder(App $app, Request $req, string $id): Response
    {
        $playlist = $this->owned($app, $id);
        $input = $req->input();
        $db = $app->db();
        $ids = array_map('strval', $db->column('SELECT video_id FROM {{playlist_items}} WHERE playlist_id = ? ORDER BY position, created_at', [$playlist['id']]));
        if (isset($input['order']) && is_array($input['order'])) {
            $wanted = array_values(array_filter(array_map('strval', $input['order']), fn ($v) => in_array($v, $ids, true)));
            if (count(array_unique($wanted)) !== count($ids)) {
                throw ApiError::invalid('order must name every video in the playlist once.');
            }
            $ids = $wanted;
        } else {
            $data = Validator::check($input, ['videoId' => ['id', 'required'], 'move' => ['enum', 'required', 'enum' => ['up', 'down']]]);
            $i = array_search((string) $data['videoId'], $ids, true);
            if ($i === false) {
                throw ApiError::notFound();
            }
            $j = $data['move'] === 'up' ? $i - 1 : $i + 1;
            if ($j >= 0 && $j < count($ids)) {
                [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
            }
        }
        $db->transaction(function () use ($db, $ids, $playlist): void {
            foreach ($ids as $position => $videoId) {
                $db->run('UPDATE {{playlist_items}} SET position = ? WHERE playlist_id = ? AND video_id = ?', [$position, $playlist['id'], $videoId]);
            }
        });
        $this->touch($app, (string) $playlist['id']);
        return Response::json(['order' => $ids]);
    }

    private function forVideo(App $app, Request $req): Response
    {
        $video = ContentTarget::from($app, $req->query, ['video'], 'playlists');
        return Response::json(array_map(fn ($p) => ['id' => $p['id'], 'title' => $p['title'], 'contains' => (bool) $p['contains']], $app->db()->all(
            'SELECT p.id, p.title, EXISTS (SELECT 1 FROM {{playlist_items}} i WHERE i.playlist_id = p.id AND i.video_id = ?) AS contains FROM {{playlists}} p WHERE p.user_id = ? ORDER BY p.updated_at DESC',
            [$video->id, $app->currentUser()->id()],
        )));
    }

    private function touch(App $app, string $id): void
    {
        $app->db()->run('UPDATE {{playlists}} SET updated_at = CURRENT_TIMESTAMP(3) WHERE id = ?', [$id]);
    }
};
