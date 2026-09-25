<?php

declare(strict_types=1);

namespace App\Modules\Profile;

use App\Core\ApiError;
use App\Core\App;
use App\Core\Db;
use App\Core\Json;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Core\Validator;
use App\Modules\Access\Passwords;
use App\Modules\Audit\Audit;

/**
 * The profile shell: /profile (what's waiting), /profile/inbox and
 * /profile/settings, and the member's own APIs. Plugins add sections
 * (profile.sections), overview cards (profile.overview) and settings groups
 * (profile.settings), and the account fields they own through
 * profile.fields.
 */
final class Routes
{
    /**
     * Account fields PATCH /api/profile accepts, each with the plugin that
     * owns it: a field whose plugin is off is refused rather than stored.
     */
    public const FIELDS = [
        'displayName' => ['display_name', 'profiles', ['string', 'nullable', 'max' => 80]],
        'notificationFrequency' => ['notification_frequency', 'notifications', ['enum', 'enum' => ['INSTANT', 'DAILY']]],
        'emailNotifications' => ['email_notifications', 'notifications', ['bool']],
        'phone' => ['phone', 'broadcasts', ['string', 'nullable', 'max' => 32, 'pattern' => '/^\+?[0-9 ()-]{6,32}$/']],
        'smsOptIn' => ['sms_opt_in', 'broadcasts', ['bool']],
        'broadcastEmails' => ['broadcast_emails', 'broadcasts', ['bool']],
        'directoryListed' => ['directory_listed', 'profiles', ['bool']],
        'directoryShowEmail' => ['directory_show_email', 'profiles', ['bool']],
        'directoryShowPhone' => ['directory_show_phone', 'profiles', ['bool']],
        'directoryNote' => ['directory_note', 'profiles', ['text', 'nullable', 'max' => 500]],
    ];

    public const EXPORTS_PER_MINUTE = 2;

    public function __construct(private readonly App $app)
    {
    }

    public static function register(Router $r, App $app): void
    {
        $self = new self($app);
        $member = Middleware::member($app);
        $r->get('/profile', [$self, 'overview'], [$member]);
        $r->get('/profile/inbox', [$self, 'inboxPage'], [$member]);
        $r->get('/profile/settings', [$self, 'settingsPage'], [$member]);
        $r->get('/api/inbox', [$self, 'inboxList'], [$member]);
        $r->add('PATCH', '/api/inbox', [$self, 'inboxRead'], [$member]);
        $r->add('DELETE', '/api/inbox', [$self, 'inboxDelete'], [$member]);
        $r->add('PATCH', '/api/profile', [$self, 'update'], [$member]);
        $r->add('DELETE', '/api/profile', [$self, 'deleteAccount'], [$member]);
        $r->get('/api/profile/export', [$self, 'export'], [$member]);
        // The port's additions for local accounts.
        $r->post('/api/profile/password', [$self, 'changePassword'], [$member]);
        $r->add('DELETE', '/api/profile/sessions', [$self, 'signOutElsewhere'], [$member]);
    }

    private function db(): Db
    {
        return $this->app->db();
    }

    private function userId(): string
    {
        return (string) $this->app->currentUser()->id();
    }

    /** @return array<string, mixed> */
    private function user(): array
    {
        return (array) $this->app->currentUser()->user();
    }

    private function pluginOn(string $slug): bool
    {
        return $this->app->plugins()->isLoaded($slug);
    }

    /**
     * The profile's own navigation: core sections, then what plugins add.
     *
     * @return list<array{href: string, label: string, badge?: int}>
     */
    public function sections(): array
    {
        $sections = [
            ['href' => '/profile', 'label' => t('profile.overview')],
            ['href' => '/profile/inbox', 'label' => t('profile.inbox'), 'badge' => Inbox::unreadCount($this->db(), $this->userId())],
            ['href' => '/profile/shared-links', 'label' => t('share.mine')],
        ];
        $extra = $this->app->hooks->apply('profile.sections', [], $this->user());
        foreach (is_array($extra) ? $extra : [] as $item) {
            if (is_array($item) && is_string($item['href'] ?? null) && is_string($item['label'] ?? null) && str_starts_with($item['href'], '/profile/')) {
                $sections[] = ['href' => $item['href'], 'label' => $item['label']];
            }
        }
        $sections[] = ['href' => '/profile/settings', 'label' => t('profile.settings')];
        return $sections;
    }

    /** @param array<string, mixed> $vars */
    private function page(string $template, string $title, array $vars = []): Response
    {
        return $this->app->page($template, ['title' => $title, 'sections' => $this->sections()] + $vars);
    }

    public function overview(Request $req): Response
    {
        $cards = $this->app->hooks->apply('profile.overview', [], $this->user());
        return $this->page('profile/overview', t('profile.title'), [
            'unread' => Inbox::unreadCount($this->db(), $this->userId()),
            'cards' => array_values(array_filter(is_array($cards) ? $cards : [], fn ($c) => is_array($c) && is_string($c['title'] ?? null))),
            'name' => $this->app->currentUser()->displayName(),
        ]);
    }

    public function inboxPage(Request $req): Response
    {
        $page = Inbox::page($this->db(), $this->userId(), $req->query('before'));
        return $this->page('profile/inbox', t('profile.inbox'), [
            'notifications' => Json::rows('notifications', $page['rows']),
            'hasMore' => $page['hasMore'],
            'unread' => Inbox::unreadCount($this->db(), $this->userId()),
            'pushPanel' => $this->capture('profile.inbox.top'),
        ]);
    }

    public function settingsPage(Request $req): Response
    {
        $user = $this->user();
        $fields = [];
        foreach (self::FIELDS as $key => [$column, $plugin]) {
            if ($this->pluginOn($plugin)) {
                $fields[$key] = $user[$column] ?? null;
            }
        }
        return $this->page('profile/settings', t('profile.settings'), [
            'fields' => Json::row('users', array_combine(
                array_map(fn ($k) => self::FIELDS[$k][0], array_keys($fields)),
                array_values($fields),
            ) ?: []),
            'email' => (string) $user['email'],
            'hasPassword' => ($user['password_hash'] ?? null) !== null,
            'otherSessions' => (int) $this->db()->value('SELECT COUNT(*) FROM {{sessions}} WHERE user_id = ? AND id_hash <> ?', [$this->userId(), $this->app->session()->idHash()]),
            'extra' => $this->capture('profile.settings'),
        ]);
    }

    private function capture(string $hook): string
    {
        ob_start();
        try {
            $this->app->hooks->do($hook, $this->app, $this->user());
        } finally {
            $out = (string) ob_get_clean();
        }
        return $out;
    }

    // Inbox API -------------------------------------------------------------------

    public function inboxList(Request $req): Response
    {
        $limit = max(1, min(100, (int) ($req->query('limit') ?? Inbox::PAGE)));
        $page = Inbox::page($this->db(), $this->userId(), $req->query('before'), $limit);
        return Response::json([
            'notifications' => Json::rows('notifications', $page['rows']),
            'hasMore' => $page['hasMore'],
            'unreadCount' => Inbox::unreadCount($this->db(), $this->userId()),
        ]);
    }

    /** @return list<string>|null the ids asked for, or null for "all" */
    private static function target(Request $req): ?array
    {
        $input = $req->input();
        if (($input['all'] ?? false) === true) {
            return null;
        }
        $ids = $input['ids'] ?? (isset($input['id']) ? [$input['id']] : null);
        if (!is_array($ids) || $ids === [] || count($ids) > 500) {
            throw ApiError::invalid('Say which notifications (ids), or all.');
        }
        $out = [];
        foreach ($ids as $id) {
            if (!is_string($id) || !preg_match('/^[a-z0-9]{8,32}$/', $id)) {
                throw ApiError::invalid('Say which notifications (ids), or all.');
            }
            $out[] = $id;
        }
        return $out;
    }

    public function inboxRead(Request $req): Response
    {
        Inbox::markRead($this->db(), $this->userId(), self::target($req));
        return Response::json(['unreadCount' => Inbox::unreadCount($this->db(), $this->userId())]);
    }

    public function inboxDelete(Request $req): Response
    {
        Inbox::delete($this->db(), $this->userId(), self::target($req));
        return Response::json(['unreadCount' => Inbox::unreadCount($this->db(), $this->userId())]);
    }

    // Account ---------------------------------------------------------------------

    public function update(Request $req): Response
    {
        $input = $req->input();
        $rules = [];
        foreach (self::FIELDS as $key => [, $plugin, $rule]) {
            if (array_key_exists($key, $input)) {
                if (!$this->pluginOn($plugin)) {
                    throw ApiError::invalid("That setting ($key) isn’t available on this site.");
                }
                $rules[$key] = $rule;
            }
        }
        $extra = $this->app->hooks->apply('profile.fields', [], $this->user());
        foreach (array_diff(array_keys($input), array_keys($rules)) as $key) {
            if (!isset($extra[$key])) {
                throw ApiError::invalid("Unknown setting: $key.");
            }
        }
        $data = Validator::check($input, $rules, partial: true);
        if (isset($data['phone']) && is_string($data['phone'])) {
            $data['phone'] = trim($data['phone']);
        }
        $row = [];
        foreach ($data as $key => $value) {
            $row[self::FIELDS[$key][0]] = $value === '' ? null : $value;
        }
        if (($row['sms_opt_in'] ?? false) === true && ($row['phone'] ?? $this->user()['phone'] ?? null) === null) {
            throw ApiError::invalid('Add a phone number before agreeing to text messages.');
        }
        if ($row !== []) {
            $this->db()->update('users', $row, ['id' => $this->userId()]);
        }
        $this->app->hooks->do('profile.updated', $this->userId(), $input);
        $fresh = (array) $this->db()->one('SELECT * FROM {{users}} WHERE id = ?', [$this->userId()]);
        $out = [];
        foreach (self::FIELDS as $key => [$column, $plugin]) {
            if ($this->pluginOn($plugin)) {
                $out[$key] = Json::row('users', [$column => $fresh[$column] ?? null])[Json::camel($column)];
            }
        }
        return Response::json($out);
    }

    public function deleteAccount(Request $req): Response
    {
        $user = $this->user();
        $confirm = Validator::normalizeEmail((string) ($req->input()['confirm'] ?? ''));
        if ($confirm !== Validator::normalizeEmail((string) $user['email'])) {
            throw ApiError::invalid('Type your email address exactly to confirm.');
        }
        if ($user['role'] === 'ADMIN' && (int) $this->db()->value("SELECT COUNT(*) FROM {{users}} WHERE role = 'ADMIN'") <= 1) {
            throw ApiError::conflict('You are the only administrator. Make somebody else an administrator first, so the site isn’t left with nobody able to let people in.');
        }
        $email = (string) $user['email'];
        $this->db()->delete('users', ['id' => $this->userId()]);
        Audit::log($this->db(), $email, 'user.delete_self', 'User', (string) $user['id']);
        $this->app->session()->logout();
        return Response::json(['ok' => true, 'redirect' => \App\Core\Url::to('/')]);
    }

    public function export(Request $req): Response
    {
        $user = $this->user();
        $email = (string) $user['email'];
        $recent = (int) $this->db()->value(
            "SELECT COUNT(*) FROM {{audit_logs}} WHERE actor_email = ? AND action = 'profile.export' AND created_at > ?",
            [$email, Db::datetime(new \DateTimeImmutable('-1 minute'))],
        );
        if ($recent >= self::EXPORTS_PER_MINUTE) {
            throw ApiError::tooMany('You have just downloaded your data. Wait a minute and try again.');
        }
        $doc = DataExport::build($this->app, (string) $user['id']);
        Audit::log($this->db(), $email, 'profile.export', 'User', (string) $user['id'], DataExport::totalRecords($doc) . ' records');
        $name = DataExport::exportFilename($user['display_name'] ?? $user['name'] ?? null, $email, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        return Response::json($doc)
            ->header('Content-Disposition', 'attachment; filename="' . $name . '"')
            ->header('Cache-Control', 'no-store');
    }

    public function changePassword(Request $req): Response
    {
        $input = $req->input();
        $user = $this->user();
        $current = (string) ($input['current'] ?? '');
        $next = (string) ($input['password'] ?? '');
        $hash = $user['password_hash'] ?? null;
        if ($hash !== null && !password_verify($current, (string) $hash)) {
            throw ApiError::invalid('Your current password isn’t right.');
        }
        if ($hash === null && !\App\Modules\Access\Access::mayUseLocal($this->app, $user)) {
            throw ApiError::forbidden('This site doesn’t use passwords for your account.');
        }
        if (($problem = Passwords::problem($next, (string) $user['email'])) !== null) {
            throw ApiError::invalid($problem);
        }
        $this->db()->update('users', ['password_hash' => Passwords::hash($next)], ['id' => $this->userId()]);
        // A new password ends every other sign-in, which is usually why it changed.
        $ended = $this->db()->run('DELETE FROM {{sessions}} WHERE user_id = ? AND id_hash <> ?', [$this->userId(), $this->app->session()->idHash()])->rowCount();
        Audit::log($this->db(), (string) $user['email'], 'user.password_change', 'User', $this->userId());
        return Response::json(['ok' => true, 'signedOut' => $ended]);
    }

    public function signOutElsewhere(Request $req): Response
    {
        $ended = $this->db()->run('DELETE FROM {{sessions}} WHERE user_id = ? AND id_hash <> ?', [$this->userId(), $this->app->session()->idHash()])->rowCount();
        Audit::log($this->db(), (string) $this->user()['email'], 'user.sign_out_elsewhere', 'User', $this->userId());
        return Response::json(['signedOut' => $ended]);
    }
}
