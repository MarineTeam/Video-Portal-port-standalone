<?php

declare(strict_types=1);

namespace App\Modules\Library;

use App\Core\ApiError;
use App\Core\App;
use App\Core\Db;
use App\Core\Id;
use App\Core\Json;
use App\Core\Middleware;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Url;
use App\Core\Validator;
use App\Modules\Audit\Audit;
use App\Modules\Profile\Inbox;
use App\Services\Email\Message;

/**
 * Share links: a revocable, tracked link to one series or video, opened at
 * /s/[token]. Any member may share what they can watch; a link lets somebody
 * past members-only or a viewer restriction only when its sharer ticked the
 * override, which needs share_content where the item sits. Redeeming puts
 * the token (never a grant) in the httpOnly share_access cookie, and every
 * request re-checks it, so a revoke is immediate.
 */
final class Sharing
{
    public function __construct(private readonly App $app)
    {
    }

    public static function register(Router $r, App $app): void
    {
        $self = new self($app);
        $member = Middleware::member($app);
        $r->get('/s/[token]', [$self, 'open']);
        $r->get('/share/unlock/[token]', [$self, 'unlockPage']);
        $r->post('/api/share-links/unlock', [$self, 'unlock']);
        $r->get('/share/unavailable', [$self, 'unavailable']);
        $r->get('/api/share-links', [$self, 'mine'], [$member]);
        $r->post('/api/share-links', fn (Request $q) => $self->create($q, false), [$member]);
        $r->add('PATCH', '/api/share-links/[id]', fn (Request $q, array $p) => $self->update($q, $p['id'], false), [$member]);
        $r->add('DELETE', '/api/share-links/[id]', fn (Request $q, array $p) => $self->revoke($p['id'], false), [$member]);
        $r->get('/profile/shared-links', [$self, 'profilePage'], [$member]);

        $admin = Middleware::can($app, 'share_content', anywhere: true);
        $r->get('/admin/share-links', [$self, 'adminPage'], [$admin]);
        $r->get('/api/admin/share-links', [$self, 'adminList'], [$admin]);
        $r->post('/api/admin/share-links', fn (Request $q) => $self->create($q, true), [$admin]);
        $r->add('PATCH', '/api/admin/share-links/[id]', fn (Request $q, array $p) => $self->update($q, $p['id'], true), [$admin]);
        $r->add('DELETE', '/api/admin/share-links/[id]', fn (Request $q, array $p) => $self->revoke($p['id'], true), [$admin]);
    }

    private function db(): Db
    {
        return $this->app->db();
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /** @return array<string, mixed> */
    private function user(): array
    {
        return (array) $this->app->currentUser()->user();
    }

    // What a link is, to a client ----------------------------------------------------------

    /**
     * The one place a link row becomes JSON: the password hash never leaves.
     *
     * @param array<string, mixed> $row with content_title/content_slug and owner_email joined
     * @return array<string, mixed>
     */
    public function present(array $row): array
    {
        $out = Json::row('share_links', $row, ['password_hash', 'failed_unlock_attempts', 'last_failed_unlock_at']);
        $out['hasPassword'] = ($row['password_hash'] ?? null) !== null;
        $out['url'] = Url::absolute('/s/' . $row['token']);
        $out['contentTitle'] = $row['content_title'] ?? null;
        $out['contentPath'] = isset($row['content_slug']) ? ($row['video_id'] !== null ? '/videos/' : '/series/') . $row['content_slug'] : null;
        $out['ownerEmail'] = $row['owner_email'] ?? null;
        $out['recipients'] = array_map('strval', $this->db()->column('SELECT email FROM {{share_link_recipients}} WHERE share_link_id = ? ORDER BY email', [$row['id']]));
        $expired = $row['expires_at'] !== null && new \DateTimeImmutable((string) $row['expires_at'], new \DateTimeZone('UTC')) <= $this->now();
        $out['status'] = $row['revoked_at'] !== null ? 'revoked' : ($expired ? 'expired' : 'active');
        return $out;
    }

    private const SELECT = "SELECT l.*, COALESCE(v.title, s.title) AS content_title, COALESCE(v.slug, s.slug) AS content_slug, u.email AS owner_email
        FROM {{share_links}} l LEFT JOIN {{videos}} v ON v.id = l.video_id LEFT JOIN {{series}} s ON s.id = l.series_id JOIN {{users}} u ON u.id = l.created_by_id";

    /** @return array<string, mixed>|null */
    private function find(string $id): ?array
    {
        return Id::isValid($id) ? $this->db()->one(self::SELECT . ' WHERE l.id = ?', [$id]) : null;
    }

    // The content being shared -------------------------------------------------------------

    /**
     * The item, its series, whether it is closed to somebody without an
     * account or a grant, and where it sits (for the override's capability).
     *
     * @return array{row: array<string, mixed>, series: ?array<string, mixed>, type: string, restricted: bool, scope: array{categoryId: ?string, seriesId: ?string}}
     */
    private function content(?string $seriesId, ?string $videoId): array
    {
        $db = $this->db();
        if (($seriesId === null) === ($videoId === null)) {
            throw ApiError::invalid('Share one series or one video.');
        }
        $guest = new ContentAccess($this->app, Viewer::guest());
        if ($videoId !== null) {
            $row = $db->one('SELECT * FROM {{videos}} WHERE id = ? AND deleted_at IS NULL', [$videoId]);
            $series = $row !== null && $row['series_id'] !== null ? $db->one('SELECT * FROM {{series}} WHERE id = ?', [$row['series_id']]) : null;
            if ($row === null || !Visibility::isVisible($row, $this->now()) || ($series !== null && !Visibility::isVisible($series, $this->now()))) {
                throw ApiError::invalid('Only published, visible content can be shared.');
            }
            if (ContentAccess::for($this->app)->video($row, $series) !== ContentAccess::OK) {
                throw ApiError::notFound();
            }
            return [
                'row' => $row, 'series' => $series, 'type' => 'video',
                'restricted' => $guest->video($row, $series) !== ContentAccess::OK,
                'scope' => ['categoryId' => $row['category_id'] ?? ($series['category_id'] ?? null), 'seriesId' => $row['series_id']],
            ];
        }
        $row = $db->one('SELECT * FROM {{series}} WHERE id = ? AND deleted_at IS NULL', [$seriesId]);
        if ($row === null || !Visibility::isVisible($row, $this->now())) {
            throw ApiError::invalid('Only published, visible content can be shared.');
        }
        if (ContentAccess::for($this->app)->series($row) !== ContentAccess::OK) {
            throw ApiError::notFound();
        }
        return [
            'row' => $row, 'series' => null, 'type' => 'series',
            'restricted' => $guest->series($row) !== ContentAccess::OK,
            'scope' => ['categoryId' => $row['category_id'], 'seriesId' => (string) $row['id']],
        ];
    }

    /** @param array{categoryId: ?string, seriesId: ?string} $scope */
    private function mayOverride(array $scope): bool
    {
        $user = $this->user();
        return ($user['role'] ?? null) === 'ADMIN' || $this->app->permissions()->has($user, 'share_content', $scope);
    }

    // Member and admin API -----------------------------------------------------------------

    public function create(Request $req, bool $asAdmin): Response
    {
        $data = Validator::check($req->input(), [
            'seriesId' => ['id', 'nullable'],
            'videoId' => ['id', 'nullable'],
            'visibility' => ['enum', 'enum' => ['PUBLIC', 'PRIVATE']],
            'recipients' => ['text', 'nullable', 'max' => 5000],
            'grantsAccess' => ['bool'],
            'note' => ['string', 'nullable', 'max' => 500],
            'password' => ['string', 'nullable', 'max' => 200],
            'expiresInDays' => ['int', 'nullable', 'min' => 0, 'max' => ShareLinks::MAX_EXPIRY_DAYS],
        ]);
        $user = $this->user();
        $recent = (int) $this->db()->value('SELECT COUNT(*) FROM {{share_links}} WHERE created_by_id = ? AND created_at > ?', [$user['id'], Db::datetime($this->now()->modify('-1 hour'))]);
        if (!$asAdmin && $recent >= ShareLinks::PER_HOUR) {
            throw ApiError::tooMany('You’ve made a lot of links in the last hour. Try again later.');
        }
        $content = $this->content($data['seriesId'] ?? null, $data['videoId'] ?? null);
        $policy = ShareLinks::policy($content['restricted'], $this->mayOverride($content['scope']), (bool) ($data['grantsAccess'] ?? false));
        if (!$policy['allowed']) {
            throw ApiError::forbidden((string) $policy['reason']);
        }
        $visibility = $data['visibility'] ?? 'PUBLIC';
        $recipients = $visibility === 'PRIVATE' ? ShareLinks::parseRecipientEmails($data['recipients'] ?? null) : [];
        if ($visibility === 'PRIVATE' && $recipients === []) {
            throw ApiError::invalid('A private link needs at least one email address.');
        }
        if (count($recipients) > 50) {
            throw ApiError::invalid('A private link can go to at most 50 people.');
        }
        $password = $data['password'] ?? null;
        if ($password !== null && $password !== '' && mb_strlen($password) < ShareLinks::MIN_PASSWORD) {
            throw ApiError::invalid('A link password needs at least ' . ShareLinks::MIN_PASSWORD . ' characters.');
        }
        $id = Id::new();
        $token = ShareLinks::newToken();
        $expires = ShareLinks::expiryFromDays($data['expiresInDays'] ?? null, $this->now());
        $this->db()->transaction(function (Db $db) use ($id, $token, $user, $content, $visibility, $policy, $data, $password, $expires, $recipients): void {
            $db->insert('share_links', [
                'id' => $id,
                'token' => $token,
                'created_by_id' => $user['id'],
                'series_id' => $content['type'] === 'series' ? $content['row']['id'] : null,
                'video_id' => $content['type'] === 'video' ? $content['row']['id'] : null,
                'visibility' => $visibility,
                'grants_access' => $policy['grantsAccess'],
                'note' => ($data['note'] ?? '') !== '' ? $data['note'] : null,
                'password_hash' => $password !== null && $password !== '' ? SharePassword::hash($password) : null,
                'expires_at' => $expires,
            ]);
            foreach ($recipients as $email) {
                $db->insert('share_link_recipients', ['id' => Id::new(), 'share_link_id' => $id, 'email' => $email]);
            }
        });
        $link = (array) $this->find($id);
        $this->notifyRecipients($link, $recipients, (string) $content['row']['title'], $password !== null && $password !== '');
        Audit::log($this->db(), (string) $user['email'], 'share_link.create', 'ShareLink', $id, ($content['type'] === 'video' ? 'video ' : 'series ') . $content['row']['id'] . ($policy['grantsAccess'] ? ', grants access' : ''));
        return Response::json($this->present($link), 201);
    }

    /**
     * @param array<string, mixed> $link
     * @param list<string> $recipients
     */
    private function notifyRecipients(array $link, array $recipients, string $title, bool $hasPassword): void
    {
        $sharer = $this->user();
        $who = (string) ($sharer['display_name'] ?? $sharer['name'] ?? $sharer['email']);
        $url = Url::absolute('/s/' . $link['token']);
        $mailer = $this->app->mailer();
        foreach ($recipients as $email) {
            $body = "$who shared “{$title}” with you." . ($hasPassword ? ' They’ll give you the password it needs.' : '') . ' Sign in as ' . $email . ' to open it.';
            if ($mailer->isConfigured()) {
                try {
                    $mailer->send(Message::plain($email, "$who shared “{$title}” with you", $body, $url, 'Open the link'));
                } catch (\Throwable $e) {
                    \App\Core\Log::warning('Share link email failed: ' . $e->getMessage());
                }
            }
            $userId = $this->db()->value('SELECT id FROM {{users}} WHERE email = ?', [$email]);
            if ($userId !== null) {
                Inbox::add($this->db(), (string) $userId, "$who shared “{$title}” with you", $hasPassword ? 'It needs a password they’ll give you.' : '', '/s/' . $link['token']);
            }
        }
    }

    public function mine(Request $req): Response
    {
        $where = 'l.created_by_id = ?';
        $params = [$this->user()['id']];
        foreach (['seriesId' => 'l.series_id', 'videoId' => 'l.video_id'] as $key => $column) {
            $value = $req->query($key);
            if (is_string($value) && Id::isValid($value)) {
                $where .= " AND $column = ?";
                $params[] = $value;
            }
        }
        return Response::json(array_map([$this, 'present'], $this->db()->all(self::SELECT . " WHERE $where ORDER BY l.created_at DESC LIMIT 200", $params)));
    }

    /** @return array<string, mixed> */
    private function owned(string $id, bool $asAdmin): array
    {
        $link = $this->find($id);
        if ($link === null || (!$asAdmin && $link['created_by_id'] !== $this->user()['id'])) {
            throw ApiError::notFound();
        }
        return $link;
    }

    /** Only the note changes; a password or expiry can't be edited — revoke and re-share instead. */
    public function update(Request $req, string $id, bool $asAdmin): Response
    {
        $link = $this->owned($id, $asAdmin);
        $data = Validator::check($req->input(), ['note' => ['string', 'nullable', 'max' => 500], 'revoked' => ['bool']], true);
        if (($data['revoked'] ?? false) === true) {
            return $this->revoke($id, $asAdmin);
        }
        if (array_key_exists('note', $data)) {
            $this->db()->update('share_links', ['note' => ($data['note'] ?? '') !== '' ? $data['note'] : null], ['id' => $link['id']]);
        }
        return Response::json($this->present((array) $this->find($id)));
    }

    /** Revoking keeps the row, so a dead link stays visible in both lists. */
    public function revoke(string $id, bool $asAdmin): Response
    {
        $link = $this->owned($id, $asAdmin);
        if ($link['revoked_at'] === null) {
            $this->db()->update('share_links', ['revoked_at' => Db::now()], ['id' => $link['id']]);
            Audit::log($this->db(), (string) $this->user()['email'], 'share_link.revoke', 'ShareLink', (string) $link['id'], $asAdmin ? 'by an administrator' : null);
        }
        return Response::json($this->present((array) $this->find($id)));
    }

    public function adminList(Request $req): Response
    {
        return Response::json($this->adminRows($req));
    }

    /** @return list<array<string, mixed>> */
    private function adminRows(Request $req): array
    {
        $state = $req->query('state');
        $where = match ($state) {
            'active' => 'l.revoked_at IS NULL AND (l.expires_at IS NULL OR l.expires_at > ?)',
            'revoked' => 'l.revoked_at IS NOT NULL',
            default => '1 = 1',
        };
        $params = $state === 'active' ? [Db::now()] : [];
        return array_map([$this, 'present'], $this->db()->all(self::SELECT . " WHERE $where ORDER BY l.created_at DESC LIMIT 500", $params));
    }

    // Opening a link -----------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    private function byToken(string $token): ?array
    {
        return ShareLinks::isTokenShaped($token) ? $this->db()->one(self::SELECT . ' WHERE l.token = ?', [$token]) : null;
    }

    /** @param array<string, mixed>|null $link */
    private function statusFor(?array $link): string
    {
        $recipients = $link === null ? [] : array_map('strval', $this->db()->column('SELECT email FROM {{share_link_recipients}} WHERE share_link_id = ?', [$link['id']]));
        $email = $this->app->currentUser()->email();
        return ShareLinks::status($link, $recipients, $email !== null ? (string) $email : null, $this->now());
    }

    /** @return list<string> */
    private function cookieTokens(Request $req): array
    {
        return ShareLinks::cookieTokens($req->cookie(ShareLinks::COOKIE));
    }

    /**
     * Records the open and puts the token in the cookie; the response is
     * a redirect to the content (from /s/) or JSON naming it (from unlock).
     *
     * @param array<string, mixed> $link
     */
    private function redeem(array $link, Request $req, bool $json = false): Response
    {
        $tokens = array_values(array_filter($this->cookieTokens($req), fn ($t) => $t !== $link['token']));
        $tokens[] = (string) $link['token'];
        $this->db()->run('UPDATE {{share_links}} SET view_count = view_count + 1, last_viewed_at = ? WHERE id = ?', [Db::now(), $link['id']]);
        $target = Url::to(($link['video_id'] !== null ? '/videos/' : '/series/') . $link['content_slug']);
        $response = $json ? Response::json(['redirect' => $target]) : Response::redirect($target);
        return $response->cookie(ShareLinks::COOKIE, implode(',', array_slice($tokens, -20)), ['maxAge' => 365 * 86400, 'secure' => $req->https, 'httponly' => true]);
    }

    /** @param array<string, string> $p */
    public function open(Request $req, array $p): Response
    {
        $link = $this->byToken($p['token']);
        $status = $this->statusFor($link);
        if ($status === 'login') {
            return Response::redirect(Url::to('/auth/login', ['returnTo' => Url::to('/s/' . $p['token'])]));
        }
        if ($status !== 'ok' || $link === null) {
            return Response::redirect(Url::to('/share/unavailable', ['reason' => $status]));
        }
        if ($link['password_hash'] !== null && !in_array($link['token'], $this->cookieTokens($req), true)) {
            return Response::redirect(Url::to('/share/unlock/' . $p['token']));
        }
        return $this->redeem($link, $req);
    }

    /** @param array<string, string> $p */
    public function unlockPage(Request $req, array $p): Response
    {
        $link = $this->byToken($p['token']);
        $status = $this->statusFor($link);
        if ($status !== 'ok' || $link === null || $link['password_hash'] === null) {
            return $this->open($req, $p);
        }
        return $this->app->page('share/unlock', ['title' => t('share.unlockTitle'), 'noindex' => true, 'token' => $p['token']]);
    }

    public function unlock(Request $req): Response
    {
        $data = Validator::check($req->input(), ['token' => ['string', 'required', 'max' => 128], 'password' => ['string', 'required', 'max' => 200]]);
        // Every guess is paid for per address too: an imported password is slow to check.
        if (!(new RateLimiter($this->db()))->hit(RateLimiter::bucket('share-unlock', $req->ip), 30, 900)) {
            throw ApiError::tooMany('Too many attempts. Try again in a few minutes.');
        }
        $link = $this->byToken((string) $data['token']);
        $status = $this->statusFor($link);
        if ($status !== 'ok' || $link === null) {
            throw new ApiError(t('share.unavailable.' . (in_array($status, ['revoked', 'expired', 'wrong_recipient'], true) ? $status : 'invalid')), 410, 'share_unavailable');
        }
        $now = $this->now();
        $last = $link['last_failed_unlock_at'] !== null ? new \DateTimeImmutable((string) $link['last_failed_unlock_at'], new \DateTimeZone('UTC')) : null;
        if (SharePassword::isUnlockLockedOut((int) $link['failed_unlock_attempts'], $last, $now)) {
            throw ApiError::tooMany('Too many wrong guesses. This link is locked for fifteen minutes.');
        }
        if ($link['password_hash'] === null || !SharePassword::verify((string) $data['password'], (string) $link['password_hash'])) {
            // A failure outside the window starts a fresh count.
            $count = SharePassword::isWithinUnlockWindow($last, $now) ? (int) $link['failed_unlock_attempts'] + 1 : 1;
            $this->db()->update('share_links', ['failed_unlock_attempts' => $count, 'last_failed_unlock_at' => Db::now()], ['id' => $link['id']]);
            throw ApiError::invalid('That password isn’t right.');
        }
        $update = ['failed_unlock_attempts' => 0, 'last_failed_unlock_at' => null];
        if (SharePassword::needsRehash((string) $link['password_hash'])) {
            $update['password_hash'] = SharePassword::hash((string) $data['password']);
        }
        $this->db()->update('share_links', $update, ['id' => $link['id']]);
        return $this->redeem($link, $req, json: true);
    }

    public function unavailable(Request $req): Response
    {
        $reason = (string) $req->query('reason');
        return $this->app->page('share/unavailable', [
            'title' => t('share.unavailableTitle'),
            'noindex' => true,
            'reason' => in_array($reason, ['revoked', 'expired', 'wrong_recipient'], true) ? $reason : 'invalid',
        ], 410);
    }

    // Pages ----------------------------------------------------------------------------------

    public function profilePage(Request $req): Response
    {
        return $this->app->page('profile/shared-links', [
            'title' => t('share.mine'),
            'sections' => (new \App\Modules\Profile\Routes($this->app))->sections(),
            'links' => array_map([$this, 'present'], $this->db()->all(self::SELECT . ' WHERE l.created_by_id = ? ORDER BY l.created_at DESC LIMIT 200', [$this->user()['id']])),
        ], 200, 'layouts/site');
    }

    public function adminPage(Request $req): Response
    {
        return $this->app->page('admin/share-links', [
            'title' => 'Share links',
            'links' => $this->adminRows($req),
            'state' => (string) ($req->query('state') ?? ''),
        ], 200, 'layouts/admin');
    }

    /**
     * The "Share a link" panel's data for a series or video page: whether the
     * reader may share, whether they may let people in, and their links to it.
     *
     * @return array<string, mixed>|null null for a visitor
     */
    public function panel(string $type, string $id): ?array
    {
        $user = $this->app->currentUser()->user();
        if ($user === null) {
            return null;
        }
        try {
            $content = $this->content($type === 'series' ? $id : null, $type === 'video' ? $id : null);
        } catch (ApiError) {
            return null;
        }
        return [
            'type' => $type,
            'id' => $id,
            'restricted' => $content['restricted'],
            'mayOverride' => $content['restricted'] && $this->mayOverride($content['scope']),
            'links' => array_map([$this, 'present'], $this->db()->all(self::SELECT . ' WHERE l.created_by_id = ? AND l.' . ($type === 'series' ? 'series_id' : 'video_id') . ' = ? ORDER BY l.created_at DESC', [$user['id'], $id])),
        ];
    }
}
