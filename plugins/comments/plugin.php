<?php
/**
 * Plugin Name: Comments
 * Slug:        comments
 * Version:     1.0.0
 * Description: Lets members discuss a series or video underneath it.
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
use App\Core\Json;
use App\Core\Middleware;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Validator;
use App\Modules\Audit\Audit;
use App\Modules\Library\ContentAccess;
use App\Modules\Library\ContentTarget;
use App\Modules\Library\Viewer;
use App\Modules\Plugins\BasePlugin;
use App\Modules\Plugins\PluginStates;

/**
 * Discussion under a series or video, one level of replies deep. Anybody
 * who may open the page reads it; members write. Authors delete their own;
 * moderators (moderate_comments, for their part of the library) delete or
 * hide any. Any other member may report a comment once; reported and
 * hidden ones make the queue at /admin/comments. A name is shown — the
 * display name while Profiles is on, else the sign-in name — never an
 * email address.
 */
return new class (__DIR__) extends BasePlugin {
    public const PER_MINUTE = 10;

    public function boot(Hooks $hooks, App $app): void
    {
        $this->useLang($hooks);
        $this->useTemplates($hooks);
        $hooks->on('routes.register', function (Router $r) use ($app): void {
            $member = Middleware::member($app);
            $r->get('/api/comments', fn (Request $req) => Response::json($this->thread($app, ContentTarget::from($app, $req->query, ['series', 'video'], 'comments'))));
            $r->post('/api/comments', fn (Request $req) => $this->create($app, $req), [$member]);
            $r->add('DELETE', '/api/comments/[id]', fn (Request $req, array $p) => $this->delete($app, $p['id']), [$member]);
            $r->post('/api/comments/[id]/report', fn (Request $req, array $p) => $this->report($app, $p['id']), [$member]);
            $moderate = Middleware::can($app, 'moderate_comments', anywhere: true);
            $r->get('/admin/comments', fn () => $app->page('comments/admin', ['title' => 'Comments', 'rows' => $this->queue($app)], 200, 'layouts/admin'), [$moderate]);
            $r->get('/api/admin/comments', fn () => Response::json($this->queue($app)), [$moderate]);
            $r->add('PATCH', '/api/admin/comments/[id]', fn (Request $req, array $p) => $this->hide($app, $req, $p['id']), [$moderate]);
        });
        $panel = function (array $panels, array $ctx, string $kind, string $id) use ($app): array {
            if (!($ctx['plugins']['comments'] ?? false) || ($ctx['locked'] ?? false)) {
                return $panels;
            }
            $viewer = $ctx['viewer'];
            return [...$panels, ['area' => 'below', 'order' => 70, 'html' => $app->view()->partial('comments/panel', [
                'body' => [$kind . 'Id' => $id],
                'comments' => $this->thread($app, ContentTarget::resolve($app, $kind, $id)),
                'signedIn' => $viewer instanceof Viewer && $viewer->signedIn(),
                'script' => $this->asset('comments.js'),
            ])]];
        };
        $hooks->filter('page.series.panels', fn (array $panels, array $ctx) => $panel($panels, $ctx, 'series', (string) $ctx['series']['id']));
        $hooks->filter('page.video.panels', fn (array $panels, array $ctx) => $panel($panels, $ctx, 'video', (string) $ctx['video']['id']));
    }

    /** The name a comment carries: never an email address. */
    private static function byline(App $app, array $row): string
    {
        $fields = PluginStates::enabled($app->db(), 'profiles') ? ['display_name', 'name'] : ['name'];
        foreach ($fields as $field) {
            $value = trim((string) ($row[$field] ?? ''));
            if ($value !== '' && !str_contains($value, '@')) {
                return $value;
            }
        }
        return t('comments.aMember');
    }

    /** Whether the reader may delete or hide this comment as a moderator. */
    private function moderates(App $app, array $comment): bool
    {
        $db = $app->db();
        [$categoryId, $seriesId] = $this->scope($db, $comment);
        return ContentAccess::for($app)->manages('moderate_comments', $categoryId, $seriesId);
    }

    /** @return array{0: ?string, 1: ?string} the comment's category and series */
    private function scope(Db $db, array $comment): array
    {
        $series = $comment['series_id'] ?? null;
        $video = $comment['video_id'] !== null ? $db->one('SELECT series_id, category_id FROM {{videos}} WHERE id = ?', [$comment['video_id']]) : null;
        $series ??= $video['series_id'] ?? null;
        $category = $video['category_id'] ?? null;
        if ($category === null && $series !== null) {
            $category = $db->value('SELECT category_id FROM {{series}} WHERE id = ?', [$series]);
        }
        return [$category !== null ? (string) $category : null, $series !== null ? (string) $series : null];
    }

    /**
     * The visible comments, top level oldest first, replies under each.
     *
     * @return list<array<string, mixed>>
     */
    private function thread(App $app, ContentTarget $target): array
    {
        $me = $app->currentUser()->id();
        $rows = $app->db()->all(
            'SELECT c.*, u.name, u.display_name FROM {{comments}} c JOIN {{users}} u ON u.id = c.user_id WHERE c.' . $target->column() . ' = ? AND c.hidden = 0 ORDER BY c.created_at',
            [$target->id],
        );
        $moderator = $rows !== [] && $this->moderates($app, $rows[0]);
        $present = fn (array $c) => [
            'id' => $c['id'],
            'body' => $c['body'],
            'author' => self::byline($app, $c),
            'createdAt' => Json::instant((string) $c['created_at']),
            'mine' => $me !== null && $c['user_id'] === $me,
            'canDelete' => $me !== null && ($c['user_id'] === $me || $moderator),
            'canReport' => $me !== null && $c['user_id'] !== $me,
        ];
        $top = [];
        $replies = [];
        foreach ($rows as $c) {
            if ($c['parent_id'] === null) {
                $top[$c['id']] = $present($c) + ['replies' => []];
            } else {
                $replies[] = $c;
            }
        }
        foreach ($replies as $c) {
            if (isset($top[$c['parent_id']])) {
                $top[$c['parent_id']]['replies'][] = $present($c);
            }
        }
        return array_values($top);
    }

    private function create(App $app, Request $req): Response
    {
        $input = $req->input();
        $target = ContentTarget::from($app, $input, ['series', 'video'], 'comments');
        $data = Validator::check($input, ['body' => ['text', 'required', 'min' => 1, 'max' => 2000], 'parentId' => ['id', 'nullable']]);
        $db = $app->db();
        $userId = (string) $app->currentUser()->id();
        if (!(new RateLimiter($db))->hit(RateLimiter::bucket('comment', $userId), self::PER_MINUTE, 60)) {
            throw new ApiError(t('comments.slowDown'), 429, 'rate_limited');
        }
        $parent = null;
        if (isset($data['parentId'])) {
            $parent = $db->one('SELECT * FROM {{comments}} WHERE id = ? AND ' . $target->column() . ' = ? AND hidden = 0', [$data['parentId'], $target->id]);
            if ($parent === null) {
                throw ApiError::notFound();
            }
            // One level deep: a reply to a reply joins the same thread.
            if ($parent['parent_id'] !== null) {
                $parent = ['id' => $parent['parent_id']];
            }
        }
        $id = $db->insert('comments', ['id' => Id::new(), 'user_id' => $userId, $target->column() => $target->id, 'body' => trim((string) $data['body']), 'parent_id' => $parent['id'] ?? null]);
        foreach ($this->thread($app, $target) as $c) {
            foreach ([$c, ...$c['replies']] as $one) {
                if ($one['id'] === $id) {
                    unset($one['replies']);
                    return Response::json($one + ['parentId' => $parent['id'] ?? null], 201);
                }
            }
        }
        throw ApiError::notFound();
    }

    private function delete(App $app, string $id): Response
    {
        $comment = Id::isValid($id) ? $app->db()->one('SELECT * FROM {{comments}} WHERE id = ?', [$id]) : null;
        if ($comment === null) {
            throw ApiError::notFound();
        }
        $mine = $comment['user_id'] === $app->currentUser()->id();
        if (!$mine && !$this->moderates($app, $comment)) {
            throw ApiError::forbidden();
        }
        $app->db()->delete('comments', ['id' => $comment['id']]);
        if (!$mine) {
            Audit::log($app->db(), (string) $app->currentUser()->email(), 'comment.delete', 'Comment', (string) $comment['id']);
        }
        return Response::json(['ok' => true]);
    }

    private function report(App $app, string $id): Response
    {
        $comment = Id::isValid($id) ? $app->db()->one('SELECT * FROM {{comments}} WHERE id = ? AND hidden = 0', [$id]) : null;
        if ($comment === null) {
            throw ApiError::notFound();
        }
        // Reading it is what a report needs.
        ContentTarget::resolve($app, $comment['video_id'] !== null ? 'video' : 'series', (string) ($comment['video_id'] ?? $comment['series_id']), 'comments');
        if ($comment['user_id'] === $app->currentUser()->id()) {
            throw ApiError::invalid(t('comments.notYourOwn'));
        }
        $app->db()->run('INSERT IGNORE INTO {{comment_reports}} (id, comment_id, user_id) VALUES (?, ?, ?)', [Id::new(), $comment['id'], $app->currentUser()->id()]);
        return Response::json(['reported' => true]);
    }

    /**
     * Reported or hidden comments this moderator looks after, most reported first.
     *
     * @return list<array<string, mixed>>
     */
    private function queue(App $app): array
    {
        $access = ContentAccess::for($app);
        $out = [];
        foreach ($app->db()->all(
            'SELECT c.*, u.name, u.display_name, (SELECT COUNT(*) FROM {{comment_reports}} r WHERE r.comment_id = c.id) AS reports,
                    s.title AS series_title, s.slug AS series_slug, v.title AS video_title, v.slug AS video_slug
             FROM {{comments}} c JOIN {{users}} u ON u.id = c.user_id LEFT JOIN {{series}} s ON s.id = c.series_id LEFT JOIN {{videos}} v ON v.id = c.video_id
             WHERE c.hidden = 1 OR EXISTS (SELECT 1 FROM {{comment_reports}} r WHERE r.comment_id = c.id)
             ORDER BY reports DESC, c.created_at DESC LIMIT 500',
        ) as $c) {
            [$categoryId, $seriesId] = $this->scope($app->db(), $c);
            if (!$access->manages('moderate_comments', $categoryId, $seriesId)) {
                continue;
            }
            $out[] = [
                'id' => $c['id'], 'body' => $c['body'], 'author' => self::byline($app, $c), 'hidden' => (bool) $c['hidden'], 'reports' => (int) $c['reports'],
                'createdAt' => Json::instant((string) $c['created_at']),
                'on' => $c['video_id'] !== null ? ['title' => $c['video_title'], 'href' => '/videos/' . $c['video_slug']] : ['title' => $c['series_title'], 'href' => '/series/' . $c['series_slug']],
            ];
        }
        return $out;
    }

    private function hide(App $app, Request $req, string $id): Response
    {
        $comment = Id::isValid($id) ? $app->db()->one('SELECT * FROM {{comments}} WHERE id = ?', [$id]) : null;
        if ($comment === null || !$this->moderates($app, $comment)) {
            throw ApiError::notFound();
        }
        $data = Validator::check($req->input(), ['hidden' => ['bool', 'required']]);
        $app->db()->update('comments', ['hidden' => $data['hidden'] ? 1 : 0], ['id' => $comment['id']]);
        if (!$data['hidden']) {
            // Let through: the reports have been dealt with.
            $app->db()->run('DELETE FROM {{comment_reports}} WHERE comment_id = ?', [$comment['id']]);
        }
        Audit::log($app->db(), (string) $app->currentUser()->email(), $data['hidden'] ? 'comment.hide' : 'comment.show', 'Comment', (string) $comment['id']);
        return Response::json(['hidden' => (bool) $data['hidden']]);
    }
};
