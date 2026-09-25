<?php
/**
 * Plugin Name: Ratings
 * Slug:        ratings
 * Version:     1.0.0
 * Description: Lets members leave a 1-5 star rating on a series or video.
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
 * One 1–5 star rating per member per series or video. Everybody who may
 * open the page sees the average and how many rated; a member also sees and
 * changes their own. The page is rendered with the numbers in it, so the
 * stars never flicker in after load.
 *
 * GET /api/ratings?seriesId=… or ?videoId=… and POST /api/ratings
 * {seriesId|videoId, value: 1–5, or null to take it back} both answer
 * {average, count, mine}.
 */
return new class (__DIR__) extends BasePlugin {
    public function boot(Hooks $hooks, App $app): void
    {
        $this->useLang($hooks);
        $this->useTemplates($hooks);
        $hooks->on('routes.register', function (Router $r) use ($app): void {
            $r->get('/api/ratings', fn (Request $req) => Response::json($this->summary($app->db(), ContentTarget::from($app, $req->query, ['series', 'video'], 'ratings'), $app->currentUser()->id())));
            $r->post('/api/ratings', fn (Request $req) => $this->rate($app, $req), [Middleware::member($app)]);
        });
        $hooks->filter('page.series.panels', fn (array $panels, array $ctx) => [...$panels, ...$this->panel($app, $ctx, 'series', (string) $ctx['series']['id'])]);
        $hooks->filter('page.video.panels', fn (array $panels, array $ctx) => [...$panels, ...$this->panel($app, $ctx, 'video', (string) $ctx['video']['id'])]);
        $hooks->filter('profile.export', function (array $doc, string $userId) use ($app): array {
            $doc['ratings'] = $app->db()->all(
                'SELECT r.series_id, s.title AS series_title, r.video_id, v.title AS video_title, r.value, r.updated_at FROM {{ratings}} r
                 LEFT JOIN {{series}} s ON s.id = r.series_id LEFT JOIN {{videos}} v ON v.id = r.video_id WHERE r.user_id = ? ORDER BY r.updated_at',
                [$userId],
            );
            return $doc;
        });
    }

    /** @return array{average: ?float, count: int, mine: ?int} */
    private function summary(Db $db, ContentTarget $target, ?string $userId): array
    {
        $column = $target->column();
        $row = $db->one("SELECT AVG(value) AS average, COUNT(*) AS n FROM {{ratings}} WHERE $column = ?", [$target->id]);
        $mine = $userId !== null ? $db->value("SELECT value FROM {{ratings}} WHERE $column = ? AND user_id = ?", [$target->id, $userId]) : null;
        $n = (int) ($row['n'] ?? 0);
        return ['average' => $n > 0 ? round((float) $row['average'], 1) : null, 'count' => $n, 'mine' => $mine !== null ? (int) $mine : null];
    }

    private function rate(App $app, Request $req): Response
    {
        $input = $req->input();
        $target = ContentTarget::from($app, $input, ['series', 'video'], 'ratings');
        $value = $input['value'] ?? null;
        if ($value !== null && (!is_int($value) || $value < 1 || $value > 5)) {
            throw ApiError::invalid('A rating is a whole number from 1 to 5, or null to take it back.');
        }
        $db = $app->db();
        $userId = (string) $app->currentUser()->id();
        $column = $target->column();
        if ($value === null) {
            $db->run("DELETE FROM {{ratings}} WHERE user_id = ? AND $column = ?", [$userId, $target->id]);
        } else {
            $db->run(
                "INSERT INTO {{ratings}} (id, user_id, $column, value) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)",
                [Id::new(), $userId, $target->id, $value],
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
        if (!($ctx['plugins']['ratings'] ?? false)) {
            return [];
        }
        $viewer = $ctx['viewer'];
        $signedIn = $viewer instanceof Viewer && $viewer->signedIn();
        $target = ContentTarget::resolve($app, $kind, $id);
        return [['area' => 'actions', 'order' => 30, 'html' => $app->view()->partial('ratings/stars', [
            'summary' => $this->summary($app->db(), $target, $signedIn ? $viewer->id() : null),
            'signedIn' => $signedIn,
            'body' => [$kind . 'Id' => $id],
            'script' => $this->asset('ratings.js'),
        ])]];
    }
};
