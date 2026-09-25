<?php
/**
 * Plugin Name: Likes / dislikes
 * Slug:        likes-dislikes
 * Version:     1.0.0
 * Description: Lets members like or dislike a series or video.
 * Author:      Marine Team
 * Requires PHP: 8.2
 * Requires App: 3.0
 * Category Override: yes
 */

declare(strict_types=1);

use App\Core\ApiError;
use App\Core\App;
use App\Core\Db;
use App\Core\Hooks;
use App\Core\Id;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Modules\Library\ContentTarget;
use App\Modules\Library\Viewer;
use App\Modules\Plugins\BasePlugin;

/**
 * A thumbs up or down on a series or video, one per member, independent of
 * the star Ratings plugin. GET /api/reactions?seriesId=… or ?videoId=… and
 * POST /api/reactions {seriesId|videoId, type: "LIKE", "DISLIKE" or null}
 * answer {likes, dislikes, mine}.
 */
return new class (__DIR__) extends BasePlugin {
    private const TYPES = ['LIKE', 'DISLIKE'];

    public function boot(Hooks $hooks, App $app): void
    {
        $this->useLang($hooks);
        $this->useTemplates($hooks);
        $hooks->on('routes.register', function (Router $r) use ($app): void {
            $r->get('/api/reactions', fn (Request $req) => Response::json($this->summary($app->db(), ContentTarget::from($app, $req->query, ['series', 'video'], 'likes-dislikes'), $app->currentUser()->id())));
            $r->post('/api/reactions', fn (Request $req) => $this->react($app, $req), [Middleware::member($app)]);
        });
        $hooks->filter('page.series.panels', fn (array $panels, array $ctx) => [...$panels, ...$this->panel($app, $ctx, 'series', (string) $ctx['series']['id'])]);
        $hooks->filter('page.video.panels', fn (array $panels, array $ctx) => [...$panels, ...$this->panel($app, $ctx, 'video', (string) $ctx['video']['id'])]);
        $hooks->filter('profile.export', function (array $doc, string $userId) use ($app): array {
            $doc['reactions'] = $app->db()->all(
                'SELECT r.series_id, s.title AS series_title, r.video_id, v.title AS video_title, r.type, r.updated_at FROM {{reactions}} r
                 LEFT JOIN {{series}} s ON s.id = r.series_id LEFT JOIN {{videos}} v ON v.id = r.video_id WHERE r.user_id = ? ORDER BY r.updated_at',
                [$userId],
            );
            return $doc;
        });
    }

    /** @return array{likes: int, dislikes: int, mine: ?string} */
    private function summary(Db $db, ContentTarget $target, ?string $userId): array
    {
        $column = $target->column();
        $counts = ['LIKE' => 0, 'DISLIKE' => 0];
        foreach ($db->all("SELECT type, COUNT(*) AS n FROM {{reactions}} WHERE $column = ? GROUP BY type", [$target->id]) as $row) {
            $counts[(string) $row['type']] = (int) $row['n'];
        }
        $mine = $userId !== null ? $db->value("SELECT type FROM {{reactions}} WHERE $column = ? AND user_id = ?", [$target->id, $userId]) : null;
        return ['likes' => $counts['LIKE'], 'dislikes' => $counts['DISLIKE'], 'mine' => is_string($mine) ? $mine : null];
    }

    private function react(App $app, Request $req): Response
    {
        $input = $req->input();
        $target = ContentTarget::from($app, $input, ['series', 'video'], 'likes-dislikes');
        $type = $input['type'] ?? null;
        if ($type !== null && !in_array($type, self::TYPES, true)) {
            throw ApiError::invalid('type must be LIKE, DISLIKE or null.');
        }
        $db = $app->db();
        $userId = (string) $app->currentUser()->id();
        $column = $target->column();
        if ($type === null) {
            $db->run("DELETE FROM {{reactions}} WHERE user_id = ? AND $column = ?", [$userId, $target->id]);
        } else {
            $db->run(
                "INSERT INTO {{reactions}} (id, user_id, $column, type) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE type = VALUES(type)",
                [Id::new(), $userId, $target->id, $type],
            );
        }
        return Response::json($this->summary($db, $target, $userId));
    }

    /**
     * @param array<string, mixed> $ctx
     * @return list<array{area: string, html: string, order: int}>
     */
    private function panel(App $app, array $ctx, string $kind, string $id): array
    {
        if (!($ctx['plugins']['likes-dislikes'] ?? false)) {
            return [];
        }
        $viewer = $ctx['viewer'];
        $signedIn = $viewer instanceof Viewer && $viewer->signedIn();
        return [['area' => 'actions', 'order' => 31, 'html' => $app->view()->partial('likes-dislikes/thumbs', [
            'summary' => $this->summary($app->db(), ContentTarget::resolve($app, $kind, $id), $signedIn ? $viewer->id() : null),
            'signedIn' => $signedIn,
            'body' => [$kind . 'Id' => $id],
            'script' => $this->asset('reactions.js'),
        ])]];
    }
};
