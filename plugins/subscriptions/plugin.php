<?php
/**
 * Plugin Name: Subscriptions
 * Slug:        subscriptions
 * Version:     1.0.0
 * Description: Lets members follow a series or category and get notified when it publishes new content.
 * Author:      Marine Team
 * Requires PHP: 8.2
 * Requires App: 3.0
 * Category Override: yes
 */

declare(strict_types=1);

use App\Core\App;
use App\Core\Hooks;
use App\Core\Id;
use App\Core\Log;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Url;
use App\Core\Validator;
use App\Modules\Library\Browse;
use App\Modules\Library\ContentAccess;
use App\Modules\Library\ContentTarget;
use App\Modules\Library\Viewer;
use App\Modules\Library\Visibility;
use App\Modules\Plugins\BasePlugin;
use App\Modules\Profile\Inbox;
use App\Modules\Push\Push;

/**
 * Follow a series or a category. When a video goes live in a followed
 * series, or anywhere under a followed category, its followers who may
 * watch it get a push and an inbox entry — on top of, and independent
 * from, the Notifications plugin. A follow can be muted: kept, but silent.
 *
 * POST /api/subscriptions {seriesId|categoryId} toggles → {following};
 * PATCH /api/subscriptions {seriesId|categoryId, muted} → {muted}.
 */
return new class (__DIR__) extends BasePlugin {
    /** @var list<array<string, mixed>> */
    private array $published = [];

    public function boot(Hooks $hooks, App $app): void
    {
        $this->useLang($hooks);
        $this->useTemplates($hooks);
        $hooks->on('routes.register', function (Router $r) use ($app): void {
            $member = Middleware::member($app);
            $r->post('/api/subscriptions', fn (Request $req) => $this->toggle($app, $req), [$member]);
            $r->add('PATCH', '/api/subscriptions', fn (Request $req) => $this->mute($app, $req), [$member]);
            $r->get('/subscriptions', fn () => $this->page($app), [$member]);
        });
        $hooks->filter('nav.sections', fn (array $nav, ?array $user) => $user === null ? $nav : [...$nav, ['href' => '/subscriptions', 'label' => t('subscriptions.nav'), 'icon' => 'bell']]);
        $hooks->filter('page.series.panels', fn (array $panels, array $ctx) => [...$panels, ...$this->button($app, $ctx, 'series', (string) $ctx['series']['id'])]);
        $hooks->filter('page.category.panels', fn (array $panels, array $ctx) => [...$panels, ...$this->button($app, $ctx, 'category', (string) $ctx['category']['id'])]);
        $hooks->filter('profile.overview', fn (array $cards, array $user) => [...$cards, [
            'title' => t('subscriptions.nav'),
            'count' => (int) $app->db()->value('SELECT COUNT(*) FROM {{subscriptions}} WHERE user_id = ?', [$user['id']]),
            'href' => '/subscriptions',
        ]]);
        $hooks->on('video.published', function (array $video) use ($app): void {
            if ($this->published === []) {
                register_shutdown_function(function () use ($app): void {
                    if (function_exists('fastcgi_finish_request')) {
                        fastcgi_finish_request();
                    }
                    foreach ($this->published as $row) {
                        try {
                            $this->notify($app, $row);
                        } catch (\Throwable $e) {
                            Log::error('Notifying followers failed: ' . $e->getMessage(), ['video' => $row['id']]);
                        }
                    }
                });
            }
            $this->published[] = $video;
        });
    }

    /**
     * @param array<string, mixed> $ctx
     * @return list<array{area: string, html: string, order: int}>
     */
    private function button(App $app, array $ctx, string $kind, string $id): array
    {
        $viewer = $ctx['viewer'];
        if (!$viewer instanceof Viewer || !$viewer->signedIn() || !($ctx['plugins']['subscriptions'] ?? false)) {
            return [];
        }
        $on = $app->db()->value("SELECT 1 FROM {{subscriptions}} WHERE user_id = ? AND {$kind}_id = ?", [$viewer->id(), $id]) !== null;
        return [['area' => 'actions', 'order' => 19, 'html' => $app->view()->partial('subscriptions/button', ['on' => $on, 'body' => [$kind . 'Id' => $id]])]];
    }

    private function toggle(App $app, Request $req): Response
    {
        $target = ContentTarget::from($app, $req->input(), ['category', 'series'], 'subscriptions');
        $db = $app->db();
        $userId = (string) $app->currentUser()->id();
        $column = $target->column();
        if ($db->run("DELETE FROM {{subscriptions}} WHERE user_id = ? AND $column = ?", [$userId, $target->id])->rowCount() > 0) {
            return Response::json(['following' => false]);
        }
        $db->run("INSERT IGNORE INTO {{subscriptions}} (id, user_id, $column) VALUES (?, ?, ?)", [Id::new(), $userId, $target->id]);
        return Response::json(['following' => true]);
    }

    private function mute(App $app, Request $req): Response
    {
        $input = $req->input();
        $data = Validator::check($input, ['muted' => ['bool', 'required'], 'seriesId' => ['id', 'nullable'], 'categoryId' => ['id', 'nullable']]);
        $column = isset($data['seriesId']) ? 'series_id' : 'category_id';
        $id = (string) ($data['seriesId'] ?? $data['categoryId'] ?? '');
        $n = $app->db()->run("UPDATE {{subscriptions}} SET muted = ? WHERE user_id = ? AND $column = ?", [$data['muted'] ? 1 : 0, $app->currentUser()->id(), $id])->rowCount();
        $exists = $app->db()->value("SELECT 1 FROM {{subscriptions}} WHERE user_id = ? AND $column = ?", [$app->currentUser()->id(), $id]) !== null;
        if ($n === 0 && !$exists) {
            throw \App\Core\ApiError::notFound();
        }
        return Response::json(['muted' => (bool) $data['muted']]);
    }

    private function page(App $app): Response
    {
        $db = $app->db();
        $userId = (string) $app->currentUser()->id();
        $access = ContentAccess::for($app);
        $browse = new Browse($app, $access);
        $follows = [];
        foreach ($db->all('SELECT * FROM {{subscriptions}} WHERE user_id = ? ORDER BY created_at DESC', [$userId]) as $sub) {
            if ($sub['series_id'] !== null) {
                $series = $browse->seriesWhere('s.id = ?', [$sub['series_id']], 's.id', 1)[0] ?? null;
                if ($series !== null) {
                    $follows[] = ['kind' => 'series', 'id' => (string) $series['id'], 'title' => (string) $series['title'], 'href' => '/series/' . $series['slug'], 'muted' => (bool) $sub['muted']];
                }
            } elseif ($sub['category_id'] !== null) {
                $category = $browse->categoryById((string) $sub['category_id']);
                if ($category !== null && Visibility::isVisible($category, $access->now()) && $access->category($category) === ContentAccess::OK) {
                    $follows[] = ['kind' => 'category', 'id' => (string) $category['id'], 'title' => (string) $category['name'], 'href' => '/categories/' . $category['slug'], 'muted' => (bool) $sub['muted']];
                }
            }
        }
        return $app->page('subscriptions/page', ['title' => t('subscriptions.title'), 'follows' => $follows, 'pushReady' => Push::vapid($app) !== null]);
    }

    /**
     * Pushes to the unmuted followers of the video's series and of every
     * category above it who may watch it.
     *
     * @param array<string, mixed> $video
     */
    public function notify(App $app, array $video): int
    {
        $db = $app->db();
        $series = $video['series_id'] !== null ? $db->one('SELECT * FROM {{series}} WHERE id = ?', [$video['series_id']]) : null;
        $categoryId = $video['category_id'] ?? ($series['category_id'] ?? null);
        $chain = $categoryId !== null ? ContentAccess::for($app)->tree()->chain((string) $categoryId) : [];
        $where = [];
        $params = [];
        if ($series !== null) {
            $where[] = 's.series_id = ?';
            $params[] = $series['id'];
        }
        if ($chain !== []) {
            $where[] = 's.category_id IN (' . implode(',', array_fill(0, count($chain), '?')) . ')';
            array_push($params, ...$chain);
        }
        if ($where === []) {
            return 0;
        }
        $followers = $db->all('SELECT DISTINCT u.* FROM {{subscriptions}} s JOIN {{users}} u ON u.id = s.user_id WHERE s.muted = 0 AND u.authorized = 1 AND (' . implode(' OR ', $where) . ')', $params);
        $title = $series !== null ? t('subscriptions.newIn', ['title' => $series['title']]) : t('subscriptions.newVideo');
        $url = Url::absolute('/videos/' . $video['slug']);
        $recipients = [];
        foreach ($followers as $user) {
            if ((new ContentAccess($app, Viewer::forUser($app, $user)))->video($video, $series) !== ContentAccess::OK) {
                continue;
            }
            Inbox::add($db, (string) $user['id'], $title, (string) $video['title'], $url);
            $recipients[] = (string) $user['id'];
        }
        return Push::send($app, $recipients, ['title' => $title, 'body' => (string) $video['title'], 'url' => $url]);
    }
};
