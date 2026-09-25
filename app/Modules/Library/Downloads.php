<?php

declare(strict_types=1);

namespace App\Modules\Library;

use App\Core\ApiError;
use App\Core\App;
use App\Core\Db;
use App\Core\Id;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Validator;
use App\Modules\Audit\Audit;
use App\Modules\Plugins\PluginStates;
use App\Services\Video\Mp4Result;
use App\Services\Video\VideoProvider;
use App\Services\Video\VideoProviderException;
use App\Services\Video\VideoRef;

/**
 * Saving a video to the device. Four gates, all of which must pass, and
 * none can widen access (a video you can't watch is never offered):
 *   the feature  the Downloads plugin, with its per-category override;
 *   the content  a three-way setting on video, series and each category,
 *                most specific first (resolveDownloadEnabled);
 *   the people   any member, or named groups and people (admins always);
 *   the platform web, installed app, or both — the one thing the browser
 *                asserts, so a placement rule rather than a boundary.
 * The file itself is a short-lived link to an MP4 rendition, streamed by the
 * browser into Cache Storage; what a device holds never reaches the server.
 */
final class Downloads
{
    public const PLATFORMS = ['BOTH', 'PWA', 'WEB'];
    public const AUDIENCES = ['ALL_MEMBERS', 'SPECIFIC'];
    /** What the caller asks for; each provider caps it with its own setting. */
    public const MAX_HEIGHT = 2160;

    public function __construct(private readonly App $app)
    {
    }

    public static function register(Router $r, App $app): void
    {
        $self = new self($app);
        $r->get('/api/downloads/[videoId]', [$self, 'download'], [Middleware::member($app)]);
        $r->get('/profile/downloads', [$self, 'profilePage'], [Middleware::member($app)]);
        // The menu files this beside the plugins; so does the permission.
        $admin = Middleware::can($app, 'manage_plugins');
        $r->get('/admin/downloads', [$self, 'adminPage'], [$admin]);
        $r->get('/api/admin/downloads', [$self, 'adminGet'], [$admin]);
        $r->add('PATCH', '/api/admin/downloads', [$self, 'adminPatch'], [$admin]);
    }

    // The rules (lib/downloads.ts) --------------------------------------------------------

    /**
     * Most specific first: the video, its series, then its categories
     * nearest first; the first that isn't inheriting (null) decides, and
     * nothing anywhere with an opinion means allowed.
     *
     * @param list<?bool> $categories nearest first
     */
    public static function resolveDownloadEnabled(?bool $video, ?bool $series, array $categories): bool
    {
        foreach ([$video, $series, ...$categories] as $setting) {
            if ($setting !== null) {
                return $setting;
            }
        }
        return true;
    }

    public static function isPlatformAllowed(string $platform, bool $standalone): bool
    {
        return match ($platform) {
            'PWA' => $standalone,
            'WEB' => !$standalone,
            default => true,
        };
    }

    /**
     * @param list<string> $groupIds the member's permission groups
     * @param list<string> $allowedUserIds
     * @param list<string> $allowedGroupIds
     */
    public static function isAudienceAllowed(string $audience, bool $isAdmin, ?string $userId, array $groupIds, array $allowedUserIds, array $allowedGroupIds): bool
    {
        if ($isAdmin) {
            return true;
        }
        if ($userId === null) {
            return false;
        }
        return $audience !== 'SPECIFIC' || in_array($userId, $allowedUserIds, true) || array_intersect($groupIds, $allowedGroupIds) !== [];
    }

    // The policy ----------------------------------------------------------------------------

    /** @return array{platform: string, audience: string, maxDeviceGb: int, groupIds: list<string>, userIds: list<string>} */
    public function policy(): array
    {
        $db = $this->app->db();
        $row = $db->one("SELECT * FROM {{download_policies}} WHERE id = 'singleton'");
        return [
            'platform' => in_array($row['platform'] ?? '', self::PLATFORMS, true) ? (string) $row['platform'] : 'BOTH',
            'audience' => in_array($row['audience'] ?? '', self::AUDIENCES, true) ? (string) $row['audience'] : 'ALL_MEMBERS',
            'maxDeviceGb' => (int) ($row['max_device_gb'] ?? 8),
            'groupIds' => array_map('strval', $db->column("SELECT group_id FROM {{download_policy_groups}} WHERE policy_id = 'singleton'")),
            'userIds' => array_map('strval', $db->column("SELECT user_id FROM {{download_policy_users}} WHERE policy_id = 'singleton'")),
        ];
    }

    /**
     * Whether this reader may download this video, and if not why, in the
     * order a member would want to hear it. The platform is left to the
     * browser.
     *
     * @param array<string, mixed> $video
     * @return array{allowed: bool, reason: ?string}
     */
    public function decide(array $video, ?array $series): array
    {
        $access = ContentAccess::for($this->app);
        if ($access->video($video, $series) !== ContentAccess::OK) {
            return ['allowed' => false, 'reason' => 'not_found'];
        }
        // A video on YouTube or Vimeo has no file of ours: no button at all, rather than one that fails.
        $provider = $this->app->services()->get('video', (string) $video['provider']);
        if (!$provider instanceof VideoProvider || !$provider->capabilities()->mp4) {
            return ['allowed' => false, 'reason' => 'not_supported'];
        }
        $categoryId = $video['category_id'] ?? ($series['category_id'] ?? null);
        if (!PluginStates::enabled($this->app->db(), 'downloads', $categoryId !== null ? (string) $categoryId : null)) {
            return ['allowed' => false, 'reason' => 'feature_off'];
        }
        $categories = $access->categories();
        $chain = array_map(
            fn (string $id) => isset($categories[$id]['download_enabled']) ? (bool) $categories[$id]['download_enabled'] : null,
            $access->tree()->chain($categoryId !== null ? (string) $categoryId : null),
        );
        $enabled = self::resolveDownloadEnabled(
            $video['download_enabled'] !== null ? (bool) $video['download_enabled'] : null,
            $series !== null && $series['download_enabled'] !== null ? (bool) $series['download_enabled'] : null,
            $chain,
        );
        if (!$enabled) {
            return ['allowed' => false, 'reason' => 'content_blocked'];
        }
        $policy = $this->policy();
        $viewer = $access->viewer();
        if (!self::isAudienceAllowed($policy['audience'], $viewer->isAdmin, $viewer->id(), $viewer->groupIds, $policy['userIds'], $policy['groupIds'])) {
            return ['allowed' => false, 'reason' => 'audience'];
        }
        return ['allowed' => true, 'reason' => null];
    }

    /** @param array<string, mixed> $video */
    public static function source(App $app, array $video): Mp4Result
    {
        $provider = $app->services()->get('video', (string) $video['provider']);
        if (!$provider instanceof VideoProvider) {
            return Mp4Result::reason('provider_error');
        }
        return self::resolveMp4Source($provider, $video, function (bool $has, ?string $resolutions) use ($app, $video): void {
            $app->db()->update('videos', ['has_mp4_fallback' => $has, 'mp4_resolutions' => $resolutions], ['id' => $video['id']]);
        });
    }

    /**
     * The file (resolveMp4Source). What a sync learned about renditions is
     * cached on the row: an unsynced Bunny video asks once and $save keeps
     * the answer, and a video known to have none never asks again.
     *
     * @param array<string, mixed> $video
     * @param callable(bool, ?string): void $save
     */
    public static function resolveMp4Source(VideoProvider $provider, array $video, callable $save): Mp4Result
    {
        if (($video['has_mp4_fallback'] ?? null) === null && $video['provider'] === 'bunny') {
            try {
                $info = $provider->get(VideoRef::fromRow($video));
            } catch (VideoProviderException | \App\Core\HttpException) {
                return Mp4Result::reason('provider_error');
            }
            if ($info->hasMp4Fallback !== null) {
                $save($info->hasMp4Fallback, $info->mp4Resolutions);
                $video['has_mp4_fallback'] = $info->hasMp4Fallback;
                $video['mp4_resolutions'] = $info->mp4Resolutions;
            }
        }
        try {
            return $provider->mp4(VideoRef::fromRow($video), self::MAX_HEIGHT);
        } catch (VideoProviderException | \App\Core\HttpException) {
            return Mp4Result::reason('provider_error');
        }
    }

    // Routes ---------------------------------------------------------------------------------

    public const REASONS = [
        'not_found' => 'This video isn’t available to you.',
        'not_supported' => 'This video plays from another site, so there’s no file here to save.',
        'feature_off' => 'Downloads aren’t turned on here.',
        'content_blocked' => 'This video can’t be downloaded.',
        'audience' => 'Downloads are limited to certain people, and you aren’t one of them yet.',
    ];

    /** @param array<string, string> $p */
    public function download(Request $req, array $p): Response
    {
        $db = $this->app->db();
        $video = Id::isValid($p['videoId']) ? $db->one('SELECT * FROM {{videos}} WHERE id = ? AND deleted_at IS NULL', [$p['videoId']]) : null;
        $series = $video !== null && $video['series_id'] !== null ? $db->one('SELECT * FROM {{series}} WHERE id = ?', [$video['series_id']]) : null;
        if ($video === null) {
            throw ApiError::notFound();
        }
        $decision = $this->decide($video, $series);
        if (!$decision['allowed']) {
            $reason = (string) $decision['reason'];
            throw $reason === 'not_found' ? ApiError::notFound() : new ApiError(self::REASONS[$reason], 403, $reason);
        }
        $file = self::source($this->app, $video);
        if (!$file->ok) {
            throw new ApiError((string) $file->message(), 409, (string) $file->reason);
        }
        $policy = $this->policy();
        return Response::json([
            'url' => $file->url,
            'height' => $file->height,
            'videoId' => (string) $video['id'],
            'title' => (string) $video['title'],
            'seriesTitle' => $series['title'] ?? null,
            'durationSeconds' => $video['duration_seconds'] !== null ? (int) $video['duration_seconds'] : null,
            'platform' => $policy['platform'],
            'maxDeviceGb' => $policy['maxDeviceGb'],
        ])->header('Cache-Control', 'no-store');
    }

    public function profilePage(Request $req): Response
    {
        $policy = $this->policy();
        $viewer = ContentAccess::for($this->app)->viewer();
        return $this->app->page('profile/downloads', [
            'title' => t('downloads.title'),
            'sections' => (new \App\Modules\Profile\Routes($this->app))->sections(),
            'enabled' => PluginStates::enabled($this->app->db(), 'downloads'),
            'audienceOk' => self::isAudienceAllowed($policy['audience'], $viewer->isAdmin, $viewer->id(), $viewer->groupIds, $policy['userIds'], $policy['groupIds']),
            'platform' => $policy['platform'],
            'maxDeviceGb' => $policy['maxDeviceGb'],
        ]);
    }

    /** @return array<string, mixed> */
    private function adminView(): array
    {
        $policy = $this->policy();
        $db = $this->app->db();
        $users = $policy['userIds'] === [] ? [] : $db->all('SELECT id, email FROM {{users}} WHERE id IN (' . implode(', ', array_fill(0, count($policy['userIds']), '?')) . ') ORDER BY email', $policy['userIds']);
        return $policy + ['users' => $users];
    }

    public function adminGet(Request $req): Response
    {
        return Response::json($this->adminView());
    }

    public function adminPage(Request $req): Response
    {
        return $this->app->page('admin/downloads', [
            'title' => 'Downloads',
            'policy' => $this->adminView(),
            'groups' => $this->app->db()->all('SELECT id, name FROM {{permission_groups}} ORDER BY name'),
            'enabled' => PluginStates::enabled($this->app->db(), 'downloads'),
        ], 200, 'layouts/admin');
    }

    public function adminPatch(Request $req): Response
    {
        $data = Validator::check($req->input(), [
            'platform' => ['enum', 'enum' => self::PLATFORMS],
            'audience' => ['enum', 'enum' => self::AUDIENCES],
            'maxDeviceGb' => ['int', 'min' => 1, 'max' => 512],
            'groupIds' => ['array', 'max' => 200, 'of' => 'id'],
            'userEmails' => ['array', 'max' => 500, 'of' => 'string'],
        ], true);
        $db = $this->app->db();
        $db->transaction(function (Db $db) use ($data): void {
            $db->run("INSERT IGNORE INTO {{download_policies}} (id) VALUES ('singleton')");
            $row = [];
            foreach (['platform' => 'platform', 'audience' => 'audience', 'maxDeviceGb' => 'max_device_gb'] as $key => $column) {
                if (array_key_exists($key, $data)) {
                    $row[$column] = $data[$key];
                }
            }
            if ($row !== []) {
                $db->update('download_policies', $row, ['id' => 'singleton']);
            }
            if (array_key_exists('groupIds', $data)) {
                $db->run("DELETE FROM {{download_policy_groups}} WHERE policy_id = 'singleton'");
                foreach (array_unique($data['groupIds']) as $groupId) {
                    if ($db->value('SELECT 1 FROM {{permission_groups}} WHERE id = ?', [$groupId]) !== null) {
                        $db->insert('download_policy_groups', ['id' => Id::new(), 'policy_id' => 'singleton', 'group_id' => $groupId]);
                    }
                }
            }
            if (array_key_exists('userEmails', $data)) {
                $db->run("DELETE FROM {{download_policy_users}} WHERE policy_id = 'singleton'");
                foreach (array_unique(array_map([Validator::class, 'normalizeEmail'], $data['userEmails'])) as $email) {
                    $userId = $db->value('SELECT id FROM {{users}} WHERE email = ?', [$email]);
                    if ($userId === null) {
                        throw ApiError::invalid("Nobody with the address $email has signed in yet.");
                    }
                    $db->insert('download_policy_users', ['id' => Id::new(), 'policy_id' => 'singleton', 'user_id' => $userId]);
                }
            }
        });
        Audit::log($db, (string) $this->app->currentUser()->email(), 'downloads.policy', 'DownloadPolicy', 'singleton', implode(', ', array_keys($data)));
        return Response::json($this->adminView());
    }
}
