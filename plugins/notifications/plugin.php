<?php
/**
 * Plugin Name: Notifications
 * Slug:        notifications
 * Version:     1.0.0
 * Description: Sends a web push notification to subscribed members when new content is published.
 * Author:      Marine Team
 * Requires PHP: 8.2
 * Requires App: 3.0
 */

declare(strict_types=1);

use App\Core\App;
use App\Core\Hooks;
use App\Core\Id;
use App\Core\Log;
use App\Core\Url;
use App\Modules\Jobs\Scheduler;
use App\Modules\Library\ContentAccess;
use App\Modules\Library\Viewer;
use App\Modules\Plugins\BasePlugin;
use App\Modules\Profile\Inbox;
use App\Modules\Push\Push;
use App\Services\Email\Message;

/**
 * When a video goes live, every member who may watch it gets it in their
 * inbox; those who signed a browser up for notifications get a push — at
 * once, or in one daily digest if they chose that — and those who asked
 * for email get an email as well, always at once. The work runs after the
 * admin's response has gone where the host allows. Members turn push on
 * per browser from Profile → Inbox, and choose frequency and email in
 * Profile → Settings (fields the profile shell accepts while this plugin
 * is on).
 */
return new class (__DIR__) extends BasePlugin {
    /** @var list<array<string, mixed>> videos published this request */
    private array $published = [];

    public function boot(Hooks $hooks, App $app): void
    {
        $this->useLang($hooks);
        $this->useTemplates($hooks);
        $hooks->on('profile.inbox.top', function (App $app, array $user): void {
            $vapid = Push::vapid($app);
            echo $app->view()->partial('notifications/toggle', [
                'publicKey' => $vapid['publicKey'] ?? null,
                'isAdmin' => $app->currentUser()->isAdmin(),
                'script' => $this->asset('push.js'),
            ]);
        });
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
                            Log::error('Notifying members failed: ' . $e->getMessage(), ['video' => $row['id']]);
                        }
                    }
                });
            }
            $this->published[] = $video;
        });
        $hooks->on('jobs.register', function (Scheduler $s) use ($app): void {
            $s->register('notification-digest', 86400, fn (float $deadline) => $this->digest($app, $deadline), 20.0, '13:00');
        });
    }

    /**
     * Tells every member who may watch it.
     *
     * @param array<string, mixed> $video
     * @return array{inbox: int, pushed: int, queued: int, emailed: int}
     */
    public function notify(App $app, array $video): array
    {
        $db = $app->db();
        $series = $video['series_id'] !== null ? $db->one('SELECT * FROM {{series}} WHERE id = ?', [$video['series_id']]) : null;
        $title = t('notifications.newVideo');
        $body = (string) $video['title'] . ($series !== null ? ' — ' . $series['title'] : '');
        $url = Url::absolute('/videos/' . $video['slug']);
        $count = ['inbox' => 0, 'pushed' => 0, 'queued' => 0, 'emailed' => 0];
        $instant = [];
        $mailer = $app->mailer();
        foreach ($db->all('SELECT * FROM {{users}} WHERE authorized = 1') as $user) {
            $access = new ContentAccess($app, Viewer::forUser($app, $user));
            if ($access->video($video, $series) !== ContentAccess::OK) {
                continue;
            }
            Inbox::add($db, (string) $user['id'], $title, $body, $url);
            $count['inbox']++;
            if (($user['notification_frequency'] ?? 'INSTANT') === 'DAILY') {
                $db->insert('pending_notifications', ['id' => Id::new(), 'user_id' => $user['id'], 'title' => $title, 'body' => $body, 'url' => $url]);
                $count['queued']++;
            } else {
                $instant[] = (string) $user['id'];
            }
            if ((bool) ($user['email_notifications'] ?? false) && $mailer->isConfigured()) {
                $result = $mailer->send(new Message((string) $user['email'], $title . ': ' . $video['title'], $body . "\n\n" . $url . "\n\n" . t('notifications.emailFooter', ['url' => Url::absolute('/profile/settings')])));
                $count['emailed'] += $result->ok() ? 1 : 0;
            }
        }
        // Queued rows for members with no browser signed up would never leave: drop them.
        $subscribed = Push::subscribers($app, array_map('strval', $db->column('SELECT DISTINCT user_id FROM {{pending_notifications}}')));
        if ($subscribed !== []) {
            $db->run('DELETE FROM {{pending_notifications}} WHERE user_id NOT IN (' . implode(',', array_fill(0, count($subscribed), '?')) . ')', $subscribed);
        } else {
            $db->run('DELETE FROM {{pending_notifications}}');
        }
        $count['pushed'] = Push::send($app, Push::subscribers($app, $instant), ['title' => $title, 'body' => $body, 'url' => $url]);
        return $count;
    }

    /** The daily job: one push per member for everything queued since the last. */
    public function digest(App $app, float $deadline): string
    {
        $db = $app->db();
        $sent = 0;
        foreach (array_map('strval', $db->column('SELECT DISTINCT user_id FROM {{pending_notifications}}')) as $userId) {
            if (microtime(true) > $deadline) {
                break;
            }
            $rows = $db->all('SELECT * FROM {{pending_notifications}} WHERE user_id = ? ORDER BY created_at', [$userId]);
            $n = count($rows);
            $message = $n === 1
                ? ['title' => (string) $rows[0]['title'], 'body' => (string) $rows[0]['body'], 'url' => (string) ($rows[0]['url'] ?? Url::absolute('/profile/inbox'))]
                : ['title' => t('notifications.digestTitle', ['count' => (string) $n]), 'body' => implode(' · ', array_map(fn ($r) => (string) $r['body'], array_slice($rows, 0, 5))), 'url' => Url::absolute('/profile/inbox')];
            $sent += Push::send($app, [$userId], $message) > 0 ? 1 : 0;
            $db->run('DELETE FROM {{pending_notifications}} WHERE user_id = ? AND id IN (' . implode(',', array_fill(0, $n, '?')) . ')', [$userId, ...array_column($rows, 'id')]);
        }
        return "sent $sent digests";
    }
};
