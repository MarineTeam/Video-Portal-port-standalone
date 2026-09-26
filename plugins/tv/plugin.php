<?php
/**
 * Plugin Name: Television
 * Slug:        tv
 * Version:     1.0.0
 * Description: A remote-friendly /tv screen, a catalogue feed a Roku channel can be built from, and sign-in by a code on the screen so nobody types a password with a remote.
 * Author:      Marine Team
 * Requires PHP: 8.2
 * Requires App: 3.0
 */

declare(strict_types=1);

require_once __DIR__ . '/src/Pairing.php';
require_once __DIR__ . '/src/Feed.php';

use App\Core\ApiError;
use App\Core\App;
use App\Core\Cache;
use App\Core\Db;
use App\Core\Hooks;
use App\Core\Id;
use App\Core\Middleware;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Core\Url;
use App\Core\Validator;
use App\Modules\Jobs\Scheduler;
use App\Modules\Library\Browse;
use App\Modules\Library\Player;
use App\Modules\Plugins\BasePlugin;
use App\Services\Video\BunnyStreamProvider;
use App\Services\Video\VideoRef;
use MarineTeam\Plugins\Tv\Feed;
use MarineTeam\Plugins\Tv\Pairing;

/**
 * Three separate things, and it is worth being plain about which is which.
 *
 * Signing in with a code on the screen. You cannot type an email address and
 * a password with a remote, so the television shows six characters, somebody
 * opens /link on their phone and is asked — by name — whether to sign that
 * television in. The code on the screen is not what signs anything in: it is
 * visible to everybody in the room, so it only ever *names* a request, and a
 * separate secret that never leaves the television is what redeems it.
 *
 * A catalogue feed. /api/tv/feed.json is what Roku's Direct Publisher reads;
 * /api/tv/feed.xml is the same catalogue as MRSS. Only public content goes
 * in either: a feed is fetched by somebody else's server with no session and
 * republished to every television that installs the channel, so there is no
 * login to put in front of it.
 *
 * A screen for a remote. /tv is the app at arm's length — large type,
 * margins that survive overscan, and focus that moves with four arrows.
 */
return new class (__DIR__) extends BasePlugin {
    /** The television's own credential, which does not expire on its own. */
    public const COOKIE = 'mt_tv';
    public const COOKIE_LIFE = 31536000;

    /** Guessing at codes from one address. */
    public const LOOKUPS_PER_HOUR = 20;
    /** New codes from one address: a television asks once and then polls. */
    public const PAIRS_PER_HOUR = 30;

    public function boot(Hooks $hooks, App $app): void
    {
        $this->useLang($hooks);
        $this->useTemplates($hooks);
        $hooks->on('routes.register', function (Router $r) use ($app): void {
            $member = Middleware::member($app);

            $r->get('/tv', fn (Request $req) => $this->screen($app, $req));
            $r->get('/tv/[slug]', fn (Request $req, array $p) => $this->watch($app, $req, (string) $p['slug']));

            // The television's half of RFC 8628. No session, and no CSRF
            // token to fetch: these are called by a set-top browser that has
            // never seen a page of ours.
            $r->post('/api/tv/pair', fn (Request $req) => $this->pair($app, $req));
            $r->post('/api/tv/poll', fn (Request $req) => $this->poll($app, $req));

            // The phone's half.
            $r->get('/link', fn (Request $req) => $this->linkPage($app, $req));
            $r->post('/api/tv/lookup', fn (Request $req) => $this->lookup($app, $req), [$member]);
            $r->post('/api/tv/approve', fn (Request $req) => $this->approve($app, $req), [$member]);

            // The catalogue. Public by definition.
            $r->get('/api/tv/feed.json', fn () => $this->feedJson($app));
            $r->get('/api/tv/feed.xml', fn () => $this->feedXml($app));

            $r->get('/profile/devices', fn () => $app->page('tv/devices', [
                'title' => t('tv.myDevices'),
                'devices' => $this->devices($app),
            ]), [$member]);
            $r->get('/api/profile/devices', fn () => Response::json(['devices' => $this->devices($app)]), [$member]);
            $r->add('DELETE', '/api/profile/devices/[id]', fn (Request $req, array $p) => $this->signOutDevice($app, (string) $p['id']), [$member]);
        });
        $hooks->filter('profile.sections', fn (array $sections, ?array $user) => $user === null ? $sections : [...$sections, ['href' => '/profile/devices', 'label' => t('tv.myDevices')]]);
        $hooks->on('jobs.register', function (Scheduler $s) use ($app): void {
            $s->register('tv-prune-pairings', 3600, fn () => $this->prune($app), 5.0);
        });
    }

    // -- The television's credential ---------------------------------------

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * The television, from the cookie it holds.
     *
     * Looked up on every television request rather than trusted from the
     * session, so signing one out from a phone takes effect at once rather
     * than whenever its session happens to lapse.
     *
     * @return array<string, mixed>|null
     */
    private function device(App $app, Request $req): ?array
    {
        $token = (string) ($req->cookie(self::COOKIE) ?? '');
        if ($token === '') {
            return null;
        }
        $row = $app->db()->one(
            'SELECT * FROM {{tv_devices}} WHERE token_hash = ? AND status = ? AND revoked_at IS NULL',
            [self::hash($token), Pairing::LINKED],
        );
        return $row;
    }

    /**
     * Makes the television's own browser a signed-in one for this request.
     *
     * The durable credential is the pairing, not the session: a session
     * lapses after a month, and a television in a hall used on Sundays would
     * otherwise need re-pairing for no reason anybody in the room could see.
     * So the session here is a cache of the pairing, renewed from it.
     */
    private function attach(App $app, Request $req): ?array
    {
        $device = $this->device($app, $req);
        $session = $app->session();
        if ($device === null) {
            // A cookie whose pairing has been signed out takes the session
            // with it, rather than leaving the set watching on a stale one.
            if ($req->cookie(self::COOKIE) !== null && $session->userId() !== null) {
                $session->logout();
            }
            return null;
        }
        if ($session->userId() !== (string) $device['user_id']) {
            $session->login((string) $device['user_id']);
        }
        $seen = $device['last_seen_at'];
        if (!is_string($seen) || strtotime((string) $seen) < time() - 3600) {
            $app->db()->update('tv_devices', ['last_seen_at' => Db::now()], ['id' => $device['id']]);
        }
        return $device;
    }

    private function cookieOptions(Request $req): array
    {
        $base = Url::basePath();
        return ['maxAge' => self::COOKIE_LIFE, 'path' => $base === '' ? '/' : $base . '/', 'secure' => $req->https, 'httponly' => true, 'samesite' => 'Lax'];
    }

    /**
     * Guessing at codes, and asking for them, are both limited per address.
     * A code is six characters from an eighteen-letter alphabet, so the
     * lookup is the only thing standing between a patient script and
     * somebody's account.
     */
    private function limit(App $app, string $kind, string $ip, int $perHour): void
    {
        if (!(new RateLimiter($app->db()))->hit(RateLimiter::bucket($kind, 'ip:' . $ip), $perHour, 3600)) {
            throw new ApiError(t('tv.tooManyTries'), 429, 'rate_limited');
        }
    }

    // -- The television's half of the pairing --------------------------------

    /** A code on the screen, and the secret that redeems it. */
    private function pair(App $app, Request $req): Response
    {
        $this->limit($app, 'tv:pair', $req->ip, self::PAIRS_PER_HOUR);
        $data = Validator::check($req->input(), [
            'deviceName' => ['string', 'max' => 200],
            'deviceKind' => ['string', 'nullable', 'max' => 64],
        ]);
        $deviceCode = Id::token(32);
        $expires = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+' . Pairing::TTL . ' seconds');
        $userCode = '';
        for ($try = 0; $try < 5; $try++) {
            $userCode = Pairing::newUserCode();
            try {
                $app->db()->insert('tv_devices', [
                    'user_code' => $userCode,
                    'device_code_hash' => self::hash($deviceCode),
                    'status' => Pairing::PENDING,
                    'device_name' => mb_substr(trim((string) ($data['deviceName'] ?? '')), 0, 255),
                    'device_kind' => $data['deviceKind'] ?? null,
                    'expires_at' => Db::datetime($expires),
                ]);
                break;
            } catch (\Throwable $e) {
                if (!Db::isDuplicate($e) || $try === 4) {
                    throw $e;
                }
                // Two televisions switched on together drew the same code.
                $userCode = '';
            }
        }
        if ($userCode === '') {
            throw ApiError::conflict(t('tv.tryAgain'));
        }
        return Response::json([
            'userCode' => $userCode,
            'formattedUserCode' => Pairing::formatUserCode($userCode),
            'deviceCode' => $deviceCode,
            'verificationUri' => Url::absolute('/link'),
            'expiresIn' => Pairing::TTL,
            'interval' => Pairing::INTERVAL,
        ], 201);
    }

    /**
     * The television asking whether anybody has said yes yet.
     *
     * The claim is a conditional update, so two polls arriving together
     * cannot both mint a token: exactly one changes a row.
     */
    private function poll(App $app, Request $req): Response
    {
        $data = Validator::check($req->input(), ['deviceCode' => ['string', 'required', 'max' => 200]]);
        $db = $app->db();
        $hash = self::hash((string) $data['deviceCode']);
        $device = $db->one('SELECT * FROM {{tv_devices}} WHERE device_code_hash = ?', [$hash]);
        $answer = Pairing::pollAnswer($device);
        if ($answer['status'] !== Pairing::READY) {
            return Response::json($answer);
        }
        $token = Id::token(32);
        $claimed = $db->run(
            'UPDATE {{tv_devices}} SET status = ?, token_hash = ?, linked_at = ?, last_seen_at = ?
             WHERE device_code_hash = ? AND status = ? AND token_hash IS NULL',
            [Pairing::LINKED, self::hash($token), Db::now(), Db::now(), $hash, Pairing::APPROVED],
        )->rowCount();
        if ($claimed === 0) {
            // Somebody else's poll got there first, or it was revoked in the
            // same second. Either way there is no second token.
            return Response::json(['status' => Pairing::GONE, 'interval' => Pairing::INTERVAL]);
        }
        $response = Response::json(['status' => Pairing::READY, 'interval' => Pairing::INTERVAL, 'redirect' => Url::to('/tv')]);
        $response->cookie(self::COOKIE, $token, $this->cookieOptions($req));
        return $response;
    }

    // -- The phone's half -----------------------------------------------------

    private function linkPage(App $app, Request $req): Response
    {
        return $app->page('tv/link', [
            'title' => t('tv.link'),
            'noindex' => true,
            'code' => Pairing::normalizeUserCode((string) ($req->query('code') ?? '')),
            'signedIn' => $app->currentUser()->isSignedIn(),
            'script' => $this->asset('link.js'),
        ]);
    }

    /**
     * What is behind a code, for the approval screen.
     *
     * Rate-limited, because a code is six characters from an eighteen-letter
     * alphabet and this is the only thing that can tell you whether one is
     * live. It says nothing about the account: a pairing has none yet.
     */
    private function lookup(App $app, Request $req): Response
    {
        $this->limit($app, 'tv:lookup', $req->ip, self::LOOKUPS_PER_HOUR);
        $device = $this->findByUserCode($app, $req);
        return Response::json([
            'userCode' => (string) $device['user_code'],
            'deviceName' => Pairing::cleanDeviceName((string) $device['device_name']),
            'deviceKind' => $device['device_kind'],
            'prompt' => Pairing::approvalPrompt($device),
        ]);
    }

    /** @return array<string, mixed> */
    private function findByUserCode(App $app, Request $req): array
    {
        $code = Pairing::normalizeUserCode((string) ($req->input()['code'] ?? ''));
        if (!Pairing::isWellFormedUserCode($code)) {
            throw ApiError::invalid(t('tv.noSuchCode'));
        }
        $device = $app->db()->one('SELECT * FROM {{tv_devices}} WHERE user_code = ?', [$code]);
        if ($device === null) {
            throw ApiError::notFound(t('tv.noSuchCode'));
        }
        if (!Pairing::canApprove($device)) {
            throw ApiError::invalid(Pairing::hasExpired($device) ? t('tv.codeExpired') : t('tv.codeUsed'));
        }
        return $device;
    }

    /** Yes or no, from a member, about a television they can see. */
    private function approve(App $app, Request $req): Response
    {
        $device = $this->findByUserCode($app, $req);
        $yes = (bool) ($req->input()['approve'] ?? false);
        $db = $app->db();
        $done = $db->run(
            'UPDATE {{tv_devices}} SET status = ?, user_id = ?, approved_at = ? WHERE id = ? AND status = ?',
            [$yes ? Pairing::APPROVED : Pairing::DENIED, $yes ? $app->currentUser()->id() : null, Db::now(), $device['id'], Pairing::PENDING],
        )->rowCount();
        if ($done === 0) {
            throw ApiError::conflict(t('tv.codeUsed'));
        }
        \App\Modules\Audit\Audit::log($db, (string) $app->currentUser()->email(), $yes ? 'tv.approve' : 'tv.deny', 'TvDevice', (string) $device['id'], Pairing::cleanDeviceName((string) $device['device_name']));
        return Response::json(['ok' => true, 'approved' => $yes, 'message' => $yes ? t('tv.approved') : t('tv.denied')]);
    }

    // -- The member's own televisions ------------------------------------------

    /** @return list<array<string, mixed>> */
    private function devices(App $app): array
    {
        $rows = $app->db()->all(
            'SELECT * FROM {{tv_devices}} WHERE user_id = ? AND status = ? AND revoked_at IS NULL ORDER BY linked_at DESC LIMIT 100',
            [$app->currentUser()->id(), Pairing::LINKED],
        );
        return array_map(fn (array $d) => [
            'id' => (string) $d['id'],
            'name' => Pairing::cleanDeviceName((string) $d['device_name']),
            'kind' => $d['device_kind'],
            'linkedAt' => \App\Core\Json::instant($d['linked_at']),
            'lastSeenAt' => \App\Core\Json::instant($d['last_seen_at']),
        ], $rows);
    }

    private function signOutDevice(App $app, string $id): Response
    {
        $db = $app->db();
        $done = $db->run(
            'UPDATE {{tv_devices}} SET status = ?, revoked_at = ?, token_hash = NULL WHERE id = ? AND user_id = ? AND revoked_at IS NULL',
            [Pairing::REVOKED, Db::now(), Id::isValid($id) ? $id : '', $app->currentUser()->id()],
        )->rowCount();
        if ($done === 0) {
            throw ApiError::notFound();
        }
        return Response::json(['ok' => true]);
    }

    /** A pairing nobody completes is rubbish after a few minutes. */
    private function prune(App $app): string
    {
        $gone = $app->db()->run(
            'DELETE FROM {{tv_devices}} WHERE status IN (?, ?, ?) AND expires_at < ?',
            [Pairing::PENDING, Pairing::APPROVED, Pairing::DENIED, Db::now()],
        )->rowCount();
        return "dropped $gone";
    }

    // -- The screen ---------------------------------------------------------------

    private function screen(App $app, Request $req): Response
    {
        $device = $this->attach($app, $req);
        $browse = Browse::for($app);
        $rows = [];
        if ($device !== null) {
            $carryOn = $browse->continueWatching(10);
            if ($carryOn !== []) {
                $rows[] = ['title' => t('tv.continueWatching'), 'videos' => $this->tiles($carryOn)];
            }
        }
        $latest = $browse->videosWhere('1 = 1', [], 'v.publish_at IS NULL, v.publish_at DESC, v.created_at DESC', 20);
        if ($latest !== []) {
            $rows[] = ['title' => t('tv.latest'), 'videos' => $this->tiles($latest)];
        }
        foreach ($browse->categories(null) as $category) {
            $videos = $browse->videosWhere('v.category_id = ?', [$category['id']], 'v.position, v.created_at DESC', 20);
            if ($videos !== []) {
                $rows[] = ['title' => (string) $category['name'], 'videos' => $this->tiles($videos)];
            }
        }
        return $app->page('tv/screen', [
            'title' => t('tv.title'),
            'noindex' => true,
            'rows' => $rows,
            'device' => $device,
            'who' => $device === null ? null : $this->whoseSetItIs($app),
            'linkUrl' => Url::absolute('/link'),
            'script' => $this->asset('tv.js'),
        ], 200, 'tv/layout');
    }

    /**
     * Whose set this is, said on a screen in a room anybody can walk into —
     * so never an email address, the same rule the comments and the group
     * pages keep. A member with no name at all is just "you".
     */
    private function whoseSetItIs(App $app): string
    {
        $name = trim((string) ($app->currentUser()->user()['name'] ?? ''));
        return $name !== '' && !str_contains($name, '@') ? $name : t('tv.aMember');
    }

    /**
     * @param list<array<string, mixed>> $videos
     * @return list<array<string, mixed>>
     */
    private function tiles(array $videos): array
    {
        return array_map(fn (array $v) => [
            'id' => (string) $v['id'],
            'slug' => (string) $v['slug'],
            'title' => (string) $v['title'],
            'thumbnail' => (string) ($v['thumbnail'] ?? ''),
            'durationSeconds' => $v['duration_seconds'] === null ? null : (int) $v['duration_seconds'],
            'memberOnly' => (bool) $v['member_only'],
        ], $videos);
    }

    private function watch(App $app, Request $req, string $slug): Response
    {
        $this->attach($app, $req);
        $browse = Browse::for($app);
        $video = $browse->videoBySlug($slug);
        if ($video === null) {
            throw ApiError::notFound();
        }
        $can = $browse->access()->video($video);
        return $app->page('tv/watch', [
            'title' => (string) $video['title'],
            'noindex' => true,
            'video' => $video,
            'player' => $can === \App\Modules\Library\ContentAccess::OK ? Player::spec($app, $video) : null,
            'refused' => $can === \App\Modules\Library\ContentAccess::OK ? null : t('tv.membersOnly'),
            'linkUrl' => Url::absolute('/link'),
            'script' => $this->asset('tv.js'),
        ], 200, 'tv/layout');
    }

    // -- The catalogue --------------------------------------------------------------

    /**
     * Only public content, and only what a television can actually play.
     *
     * member_only is filtered on the video *and* on its series: a public
     * video inside a members-only series is members-only, and there is no
     * session on a request from somebody's crawler to catch it later.
     *
     * @return list<array<string, mixed>>
     */
    private function feedVideos(App $app): array
    {
        $rows = $app->db()->all(
            'SELECT v.*, s.title AS series_title, s.slug AS series_slug, k.name AS speaker_name
             FROM {{videos}} v
             LEFT JOIN {{series}} s ON s.id = v.series_id
             LEFT JOIN {{speakers}} k ON k.id = v.speaker_id
             WHERE v.published = 1 AND v.hidden = 0 AND v.deleted_at IS NULL AND v.member_only = 0
               AND (v.publish_at IS NULL OR v.publish_at <= ?) AND (v.unpublish_at IS NULL OR v.unpublish_at > ?)
               AND (v.series_id IS NULL OR (s.member_only = 0 AND s.published = 1 AND s.deleted_at IS NULL))
             ORDER BY v.publish_at IS NULL, v.publish_at DESC, v.created_at DESC LIMIT 500',
            [Db::now(), Db::now()],
        );
        $out = [];
        foreach ($rows as $video) {
            $out[] = [
                'id' => (string) $video['id'],
                'title' => (string) $video['title'],
                'description' => (string) ($video['description'] ?? ''),
                'durationSeconds' => $video['duration_seconds'],
                'publishAt' => $video['publish_at'],
                'createdAt' => $video['created_at'],
                'language' => $video['language'],
                'streamUrl' => $this->streamUrl($app, $video),
                'externalUrl' => \App\Modules\Library\VideoSource::watchAtSourceUrl($video),
                'thumbnailUrl' => $this->thumbnail($app, $video),
                'pageUrl' => Url::absolute('/videos/' . $video['slug']),
                'seriesTitle' => $video['series_title'],
                'speakerName' => $video['speaker_name'],
            ];
        }
        return $out;
    }

    /**
     * A stream a television can play by itself: HLS from Bunny, the file
     * from the file-based providers. An embed is not one — a set-top box has
     * no browser to put an iframe in — so a video only available that way
     * falls back to its source's own page, or drops out of the feed.
     *
     * @param array<string, mixed> $video
     */
    private function streamUrl(App $app, array $video): ?string
    {
        $provider = $app->services()->get('video', (string) $video['provider']);
        if ($provider instanceof BunnyStreamProvider) {
            return $provider->hlsUrl(VideoRef::fromRow($video));
        }
        $spec = Player::spec($app, $video);
        if ($spec === null || ($spec['kind'] ?? '') !== 'native') {
            return null;
        }
        foreach ((array) ($spec['sources'] ?? []) as $source) {
            $url = (string) ($source['src'] ?? '');
            if ($url !== '' && str_starts_with($url, 'http')) {
                return $url;
            }
        }
        return null;
    }

    /** @param array<string, mixed> $video */
    private function thumbnail(App $app, array $video): string
    {
        $given = (string) ($video['external_thumbnail_url'] ?? '');
        if ($given !== '') {
            return $given;
        }
        $provider = $app->services()->get('video', (string) $video['provider']);
        try {
            return $provider instanceof \App\Services\Video\VideoProvider
                ? (string) ($provider->thumbnailUrl(VideoRef::fromRow($video), $video['thumbnail_file_name'] === null ? null : (string) $video['thumbnail_file_name']) ?? '')
                : '';
        } catch (\Throwable) {
            return '';
        }
    }

    /** @return array{title: string, language: string, url: string} */
    private function site(App $app): array
    {
        return [
            'title' => (string) \App\Modules\Themes\Appearance::forPage($app)['branding']['name'],
            'language' => substr(\App\Modules\I18n\I18n::locale(), 0, 2),
            'url' => Url::absolute('/'),
        ];
    }

    /**
     * Built fresh and cached for an hour on our side, and told to be cached
     * for an hour on theirs: a platform fetches this once an hour and ships
     * what it got to every television that installed the channel.
     */
    private function feedJson(App $app): Response
    {
        $feed = Cache::remember('tv:feed:json', Feed::TTL, fn () => Feed::rokuFeed($this->feedVideos($app), $this->site($app)));
        return $this->cached(Response::json($feed));
    }

    private function feedXml(App $app): Response
    {
        $xml = Cache::remember('tv:feed:xml', Feed::TTL, fn () => Feed::mrssFeed($this->feedVideos($app), $this->site($app)));
        return $this->cached(Response::text((string) $xml, 200, 'application/rss+xml; charset=UTF-8'));
    }

    private function cached(Response $response): Response
    {
        return $response
            ->header('Cache-Control', 'public, max-age=' . Feed::TTL . ', s-maxage=' . Feed::TTL)
            ->header('X-Robots-Tag', 'noindex');
    }
};
