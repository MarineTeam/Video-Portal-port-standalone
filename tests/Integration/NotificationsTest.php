<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Id;
use App\Support\Jwt;

/**
 * Web Push and the Notifications plugin through a real server: the keys
 * set up from Admin → Services, a browser signing up (push services only),
 * a video going live reaching every member who may watch it in the inbox,
 * a daily-digest member's notification queued, and the digest job
 * clearing the queue.
 */
final class NotificationsTest extends ServerTestCase
{
    /** @var array<string, string> */
    private static array $ids = [];

    protected static function prefix(): string
    {
        return 'nt_';
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::install();
        self::$ids['ruth'] = self::member('ruth@test.example', 'ruth');
        self::$ids['boaz'] = self::member('boaz@test.example', 'boaz');
        $db = self::connect(self::prefix());
        $db->update('users', ['notification_frequency' => 'DAILY'], ['id' => self::$ids['ruth']]);
        $group = Id::new();
        $db->insert('permission_groups', ['id' => $group, 'name' => 'Elders', 'capabilities' => []]);
        $db->insert('group_assignments', ['id' => Id::new(), 'group_id' => $group, 'user_id' => self::$ids['ruth']]);
        self::$ids['elders'] = $group;
        $new = function (string $slug, array $extra = []) use ($db): string {
            $id = Id::new();
            $db->insert('videos', $extra + ['id' => $id, 'title' => ucfirst($slug), 'slug' => $slug, 'published' => 0, 'status' => 'READY', 'provider' => 'direct', 'external_id' => $slug, 'scripture_refs' => [], 'provider_data' => ['url' => "https://cdn.example.org/$slug.mp4"]]);
            return $id;
        };
        self::$ids['open'] = $new('sermon');
        self::$ids['elders-only'] = $new('elders-meeting');
        $db->insert('video_viewer_groups', ['id' => Id::new(), 'video_id' => self::$ids['elders-only'], 'group_id' => $group]);
        self::flushCache();
    }

    /** @return array{endpoint: string, keys: array{p256dh: string, auth: string}} */
    private static function browserSubscription(string $host = 'fcm.googleapis.com'): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $d = openssl_pkey_get_details($key);
        return ['endpoint' => "https://$host/fcm/send/" . bin2hex(random_bytes(8)), 'keys' => [
            'p256dh' => Jwt::b64e("\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT)),
            'auth' => Jwt::b64e(random_bytes(16)),
        ]];
    }

    public function test_1_signing_up_waits_for_the_keys_then_takes_only_push_services(): void
    {
        self::assertSame(409, self::api('POST', '/api/push/subscribe', self::browserSubscription(), 'ruth')['status']);
        $r = self::http('POST', '/admin/integrations/webpush', ['_csrf' => self::csrf('/admin/integrations/webpush'), 'action' => 'generate', 'subject' => 'mailto:office@test.example']);
        self::assertSame(303, $r['status']);
        self::assertStringContainsString('<span class="badge">set</span>', self::http('GET', '/admin/integrations/webpush')['body']);
        self::assertSame(400, self::api('POST', '/api/push/subscribe', self::browserSubscription('evil.example.org'), 'ruth')['status']);
        self::assertSame(201, self::api('POST', '/api/push/subscribe', self::browserSubscription(), 'ruth')['status']);
        self::assertSame(401, self::api('POST', '/api/push/subscribe', self::browserSubscription(), 'guest')['status']);
        self::assertStringContainsString('data-push-toggle=', self::http('GET', '/profile/inbox', null, 'ruth')['body']);
    }

    public function test_2_publishing_reaches_every_member_who_may_watch_it(): void
    {
        foreach (['open', 'elders-only'] as $key) {
            self::assertSame(200, self::api('PATCH', '/api/admin/videos/' . self::$ids[$key], ['published' => true])['status']);
        }
        $titles = fn (string $who) => array_column((array) self::http('GET', '/api/inbox', null, $who)['json']['notifications'], 'body');
        self::assertContains('Sermon', $titles('ruth'));
        self::assertContains('Elders-meeting', $titles('ruth'), 'Ruth is an elder');
        self::assertContains('Sermon', $titles('boaz'));
        self::assertNotContains('Elders-meeting', $titles('boaz'), 'Boaz may not watch it, so he is not told of it');
        // Ruth chose the daily digest: queued, not pushed.
        $db = self::connect(self::prefix());
        self::assertSame(2, (int) $db->value('SELECT COUNT(*) FROM {{pending_notifications}} WHERE user_id = ?', [self::$ids['ruth']]));
        // Boaz has no browser signed up: nothing queued for him to pile up.
        self::assertSame(0, (int) $db->value('SELECT COUNT(*) FROM {{pending_notifications}} WHERE user_id = ?', [self::$ids['boaz']]));
    }

    public function test_3_the_digest_job_clears_the_queue(): void
    {
        $r = self::http('POST', '/admin/jobs/notification-digest/run', ['_csrf' => self::csrf('/admin/jobs')]);
        self::assertSame(303, $r['status']);
        self::assertSame(0, (int) self::connect(self::prefix())->value('SELECT COUNT(*) FROM {{pending_notifications}}'));
    }
}
