<?php

declare(strict_types=1);

namespace App\Modules\Access;

use App\Core\App;
use App\Core\Db;
use App\Core\ErrorPage;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Url;
use App\Core\Validator;
use App\Services\Auth\LocalProvider;
use App\Services\Email\Message;

/**
 * Local sign-in: /auth/login, /auth/logout, /auth/register, /auth/verify,
 * /auth/reset, /auth/magic — plus /access-denied and the registration check
 * the Auth0 Pre-User-Registration Action (and Supabase's hook) call.
 *
 * Every refusal answers the same way however it was reached: an unknown
 * address, a wrong password and a suspended account all read "that email and
 * password don't match", and take about as long.
 */
final class Routes
{
    public static function register(Router $r, App $app): void
    {
        $self = new self($app);
        $r->get('/auth/login', [$self, 'loginForm']);
        $r->post('/auth/login', [$self, 'login']);
        $r->post('/auth/logout', [$self, 'logout']);
        $r->get('/auth/logout', fn () => Response::redirect(Url::to('/')));
        $r->get('/auth/register', [$self, 'registerForm']);
        $r->post('/auth/register', [$self, 'registerSubmit']);
        $r->get('/auth/verify/[token]', [$self, 'verify']);
        $r->get('/auth/reset', [$self, 'resetForm']);
        $r->post('/auth/reset', [$self, 'resetRequest']);
        $r->get('/auth/reset/[token]', [$self, 'resetTokenForm']);
        $r->post('/auth/reset/[token]', [$self, 'resetTokenSubmit']);
        $r->post('/auth/magic', [$self, 'magicRequest']);
        $r->get('/auth/magic/[token]', [$self, 'magicConfirm']);
        $r->post('/auth/magic/[token]', [$self, 'magicUse']);
        $r->get('/auth/guest', [$self, 'guest']);
        $r->get('/access-denied', [$self, 'accessDenied']);
        $r->get('/auth/recover', [$self, 'recoverForm']);
        $r->post('/auth/recover', [$self, 'recoverSubmit']);
        $r->post('/api/auth/registration-check', [$self, 'registrationCheck']);
    }

    public function __construct(private readonly App $app)
    {
    }

    private function local(): LocalProvider
    {
        $provider = $this->app->services()->get('auth', 'local');
        return $provider instanceof LocalProvider ? $provider : new LocalProvider([]);
    }

    private function page(string $template, array $vars = [], int $status = 200): Response
    {
        $response = $this->app->page($template, $vars, $status, 'layouts/auth');
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    private function message(string $title, string $body, int $status = 200): Response
    {
        return $this->page('auth/message', ['title' => $title, 'body' => $body], $status);
    }

    public function loginForm(Request $req): Response
    {
        if ($this->app->currentUser()->isSignedIn()) {
            return Response::redirect(Url::safeReturnTo($req->query('returnTo')));
        }
        return $this->page('auth/login', $this->loginVars($req));
    }

    /** @return array<string, mixed> */
    private function loginVars(Request $req, ?string $error = null, string $email = ''): array
    {
        $local = $this->local();
        return [
            'error' => $error,
            'email' => $email,
            'returnTo' => Url::safeReturnTo($req->query('returnTo') ?? (string) ($req->input()['returnTo'] ?? '')),
            'magicLink' => $local->magicLink() && $this->app->mailer()->isConfigured(),
            'selfRegistration' => $local->selfRegistration(),
            'breakGlass' => Access::breakGlass($this->app),
            'primary' => $this->app->services()->activeId('auth'),
        ] + $this->externalVars($req);
    }

    /**
     * How an external primary provider appears on /auth/login: a button for
     * the redirect flow, the provider's own widget for the token flow; the
     * password form then stays for administrators (and members, if allowed).
     *
     * @return array<string, mixed>
     */
    private function externalVars(Request $req): array
    {
        $trial = $req->query('trial') === '1' && is_array($this->app->session()->get('auth_trial'));
        $primary = $this->app->services()->active('auth');
        if ($trial) {
            $t = (array) $this->app->session()->get('auth_trial');
            $class = $this->app->services()->providerClass('auth', (string) $t['provider']);
            $config = json_decode((string) \App\Core\Crypto::decrypt((string) $t['config']), true);
            $primary = $class !== null && is_array($config) ? new $class($config) : null;
        }
        if (!$primary instanceof \App\Services\Auth\AuthProvider || $primary::id() === 'local') {
            return ['external' => null, 'localOpen' => true];
        }
        $local = $this->local();
        return [
            'external' => [
                'label' => $primary instanceof \App\Services\Auth\RedirectProvider ? $primary->displayName() : $primary::label(),
                'flow' => $primary->flow(),
                'widget' => $primary instanceof \App\Services\Auth\TokenProvider ? $primary->widget() : null,
                'trial' => $trial,
            ],
            // Everybody may still try the password form while local accounts are open to members.
            'localOpen' => $local->membersMayUse(),
        ];
    }

    public function login(Request $req): Response
    {
        $input = $req->input();
        $email = Authorization::normalizeEmail((string) ($input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $db = $this->app->db();
        $limiter = new RateLimiter($db);
        $accountBucket = RateLimiter::bucket('login-account', $email);
        $ipBucket = RateLimiter::bucket('login-ip', $req->ip);

        $wait = max($limiter->blocked($accountBucket), $limiter->blocked($ipBucket));
        if ($wait > 0) {
            return $this->page('auth/login', $this->loginVars($req, t('auth.throttled', ['minutes' => (int) ceil($wait / 60)]), $email), 429);
        }

        $user = Validator::isEmail($email) ? $db->one('SELECT * FROM {{users}} WHERE email = ?', [$email]) : null;
        $ok = Passwords::verify($password, $user['password_hash'] ?? null);
        if ($ok && $user !== null && !$this->mayUseLocal($user)) {
            $ok = false;
        }
        if (!$ok || $user === null) {
            $limiter->fail($accountBucket, free: 5);
            $limiter->fail($ipBucket, free: 20);
            return $this->page('auth/login', $this->loginVars($req, t('auth.wrongCredentials'), $email), 401);
        }
        $limiter->clear($accountBucket);
        if (Passwords::needsRehash((string) $user['password_hash'])) {
            $db->update('users', ['password_hash' => Passwords::hash($password)], ['id' => $user['id']]);
        }
        return $this->finish($req, $user, 'LOGIN');
    }

    /**
     * Local sign-in is open to ADMIN accounts unless an administrator turned
     * that off (and always while the break-glass file exists), and to members
     * when it is the primary provider or allowed beside another.
     *
     * @param array<string, mixed> $user
     */
    private function mayUseLocal(array $user): bool
    {
        return Access::mayUseLocal($this->app, $user);
    }

    /** @param array<string, mixed> $user */
    private function finish(Request $req, array $user, string $type): Response
    {
        $identity = new Identity(
            sub: IdentityLinking::storedSub('local', (string) $user['id']),
            provider: 'local',
            email: (string) $user['email'],
            emailVerified: $user['email_verified_at'] !== null,
            name: $user['name'] ?? null,
            membershipApplicable: false,
        );
        $result = (new SignIn($this->app))->complete($identity, $type);
        if (!$result['ok']) {
            return Response::redirect(Url::to('/access-denied'));
        }
        return Response::redirect(Url::safeReturnTo((string) ($req->input()['returnTo'] ?? $req->query('returnTo') ?? '')));
    }

    public function logout(Request $req): Response
    {
        $identity = $this->app->session()->get('identity');
        $this->app->session()->logout();
        $provider = is_array($identity) ? (string) ($identity['provider'] ?? 'local') : 'local';
        $auth = $this->app->services()->get('auth', $provider === 'local' ? 'local' : $this->app->services()->activeId('auth') ?? 'local');
        $external = $auth instanceof \App\Services\Auth\AuthProvider ? $auth->logoutUrl(Url::absolute('/')) : null;
        return Response::redirect($external ?? Url::to('/'));
    }

    public function registerForm(Request $req): Response
    {
        if (!$this->local()->selfRegistration()) {
            return ErrorPage::render(404);
        }
        return $this->page('auth/register', ['error' => null, 'email' => '', 'name' => '']);
    }

    public function registerSubmit(Request $req): Response
    {
        if (!$this->local()->selfRegistration()) {
            return ErrorPage::render(404);
        }
        $db = $this->app->db();
        $limiter = new RateLimiter($db);
        if (!$limiter->hit(RateLimiter::bucket('register-ip', $req->ip), 5, 3600)) {
            return $this->message(t('auth.register'), 'Too many sign-ups from here. Try again later.', 429);
        }
        $input = $req->input();
        if (($input['website'] ?? '') !== '') {
            // The honeypot: a person never sees this field.
            return $this->message(t('auth.register'), t('auth.checkEmail', ['email' => '']));
        }
        $email = Authorization::normalizeEmail((string) ($input['email'] ?? ''));
        $name = Validator::cleanText((string) ($input['name'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $vars = ['email' => $email, 'name' => $name];
        if (!Validator::isEmail($email)) {
            return $this->page('auth/register', $vars + ['error' => 'Enter a valid email address.'], 400);
        }
        if (($problem = Passwords::problem($password, $email)) !== null) {
            return $this->page('auth/register', $vars + ['error' => $problem], 400);
        }
        // Registration is by invitation: an ACTIVE allowlist row, the same
        // question the Pre-User-Registration Action asks. The answer to
        // somebody not invited reads exactly like the answer to somebody who is.
        $invited = Access::authorization($this->app)->isEmailAuthorized($email);
        $existing = $db->one('SELECT id, email_verified_at FROM {{users}} WHERE email = ?', [$email]);
        if ($invited && $existing === null && $this->app->mailer()->isConfigured()) {
            $userId = $db->insert('users', [
                'email' => $email,
                'name' => $name === '' ? null : mb_substr($name, 0, 255),
                'password_hash' => Passwords::hash($password),
                'role' => 'MEMBER',
            ]);
            $token = AuthTokens::issue($db, $userId, 'verify');
            $this->app->mailer()->send(Message::plain($email, 'Confirm your email address', 'Confirm this address to finish creating your account.', Url::absolute('/auth/verify/' . $token), 'Confirm my address', sensitive: true));
        } else {
            Passwords::hash($password);
        }
        return $this->message(t('auth.register'), t('auth.checkEmail', ['email' => $email]));
    }

    public function verify(Request $req, array $p): Response
    {
        $row = AuthTokens::consume($this->app->db(), $p['token'], 'verify');
        if ($row === null) {
            return $this->message(t('auth.linkExpired'), t('auth.linkExpired'), 410);
        }
        $this->app->db()->update('users', ['email_verified_at' => Db::now()], ['id' => $row['user_id']]);
        return $this->message(t('auth.verified'), t('auth.verified'));
    }

    public function resetForm(Request $req): Response
    {
        return $this->page('auth/reset-request', ['configured' => $this->app->mailer()->isConfigured()]);
    }

    public function resetRequest(Request $req): Response
    {
        $email = Authorization::normalizeEmail((string) ($req->input()['email'] ?? ''));
        if (!$this->app->mailer()->isConfigured()) {
            return $this->message(t('auth.resetTitle'), t('auth.resetUnavailable'));
        }
        $this->sendLink($req, $email, 'reset');
        return $this->message(t('auth.resetTitle'), t('auth.resetSent', ['email' => $email]));
    }

    /**
     * Sends a reset or magic link when the address has an account; either way
     * the caller answers identically, and roughly as slowly.
     */
    private function sendLink(Request $req, string $email, string $purpose): void
    {
        $db = $this->app->db();
        $limiter = new RateLimiter($db);
        $allowed = $limiter->hit(RateLimiter::bucket("$purpose-ip", $req->ip), 10, 3600)
            && $limiter->hit(RateLimiter::bucket("$purpose-account", $email), 3, 3600);
        $user = $allowed && Validator::isEmail($email) ? $db->one('SELECT * FROM {{users}} WHERE email = ?', [$email]) : null;
        if ($user === null || !$this->mayUseLocal($user) || !Access::authorization($this->app)->isEmailAuthorized($email)) {
            usleep(random_int(150_000, 400_000));
            return;
        }
        $token = AuthTokens::issue($db, (string) $user['id'], $purpose);
        if ($purpose === 'reset') {
            $message = Message::plain($email, 'Reset your password', "Somebody asked to reset the password for this address. If it wasn't you, ignore this message.\n\nThe link works once, for an hour.", Url::absolute('/auth/reset/' . $token), 'Choose a new password', sensitive: true);
        } else {
            $message = Message::plain($email, 'Your sign-in link', "Use this link to sign in. It works once, for fifteen minutes.", Url::absolute('/auth/magic/' . $token), 'Sign in', sensitive: true);
        }
        $this->app->mailer()->send($message);
    }

    public function resetTokenForm(Request $req, array $p): Response
    {
        $row = AuthTokens::peek($this->app->db(), $p['token'], 'reset');
        if ($row === null) {
            return $this->message(t('auth.linkExpired'), t('auth.linkExpired'), 410);
        }
        return $this->page('auth/reset', ['token' => $p['token'], 'error' => null]);
    }

    public function resetTokenSubmit(Request $req, array $p): Response
    {
        $db = $this->app->db();
        $password = (string) ($req->input()['password'] ?? '');
        $row = AuthTokens::peek($db, $p['token'], 'reset');
        if ($row === null) {
            return $this->message(t('auth.linkExpired'), t('auth.linkExpired'), 410);
        }
        $email = (string) $db->value('SELECT email FROM {{users}} WHERE id = ?', [$row['user_id']]);
        if (($problem = Passwords::problem($password, $email)) !== null) {
            return $this->page('auth/reset', ['token' => $p['token'], 'error' => $problem], 400);
        }
        if (AuthTokens::consume($db, $p['token'], 'reset') === null) {
            return $this->message(t('auth.linkExpired'), t('auth.linkExpired'), 410);
        }
        $db->update('users', ['password_hash' => Passwords::hash($password), 'email_verified_at' => Db::now()], ['id' => $row['user_id']]);
        // A new password signs every other device out.
        $db->delete('sessions', ['user_id' => $row['user_id']]);
        return $this->message(t('auth.resetTitle'), t('auth.passwordChanged'));
    }

    public function magicRequest(Request $req): Response
    {
        if (!$this->local()->magicLink() || !$this->app->mailer()->isConfigured()) {
            return ErrorPage::render(404);
        }
        $email = Authorization::normalizeEmail((string) ($req->input()['email'] ?? ''));
        $this->sendLink($req, $email, 'magic');
        return $this->message(t('auth.emailMeALink'), t('auth.linkSent', ['email' => $email, 'minutes' => 15]));
    }

    /**
     * A GET only shows a button: mail scanners open links, and a sign-in
     * link consumed by a scanner is a sign-in link that no longer works.
     */
    public function magicConfirm(Request $req, array $p): Response
    {
        $row = AuthTokens::peek($this->app->db(), $p['token'], 'magic');
        if ($row === null) {
            return $this->message(t('auth.linkExpired'), t('auth.linkExpired'), 410);
        }
        $email = (string) $this->app->db()->value('SELECT email FROM {{users}} WHERE id = ?', [$row['user_id']]);
        return $this->page('auth/magic', ['token' => $p['token'], 'email' => $email]);
    }

    public function magicUse(Request $req, array $p): Response
    {
        $db = $this->app->db();
        $row = AuthTokens::consume($db, $p['token'], 'magic');
        if ($row === null) {
            return $this->message(t('auth.linkExpired'), t('auth.linkExpired'), 410);
        }
        $user = $db->one('SELECT * FROM {{users}} WHERE id = ?', [$row['user_id']]);
        if ($user === null) {
            return $this->message(t('auth.linkExpired'), t('auth.linkExpired'), 410);
        }
        // Following an emailed link proves the address.
        if ($user['email_verified_at'] === null) {
            $db->update('users', ['email_verified_at' => Db::now()], ['id' => $user['id']]);
            $user['email_verified_at'] = Db::now();
        }
        return $this->finish($req, $user, 'LOGIN');
    }

    /**
     * /auth/guest: only meaningful with a provider that names an organisation
     * on the login request, and only while the admin's switch is open.
     * Otherwise it 404s exactly like a path that doesn't exist.
     */
    public function guest(Request $req): Response
    {
        $open = GuestLogin::enabled($this->app->db());
        $primary = $this->app->services()->active('auth');
        if (!$open || !$primary instanceof \App\Services\Auth\AuthProvider || !method_exists($primary, 'guestLoginUrl')) {
            return ErrorPage::render(404);
        }
        return Response::redirect((string) $primary->guestLoginUrl());
    }

    public function accessDenied(Request $req): Response
    {
        $open = false;
        try {
            $open = GuestLogin::enabled($this->app->db());
        } catch (\Throwable) {
        }
        $response = $this->page('access-denied', ['guestOpen' => $open]);
        return $response;
    }

    /**
     * The lockout path that needs neither email nor a shell. While
     * storage/enable-local-login exists, this page accepts a code the app
     * writes to storage/recovery.key — readable only by somebody with the
     * site's files, the same proof the installer asks for — and sets a new
     * password for an administrator.
     */
    private function recoveryCode(): ?string
    {
        if (!Access::breakGlass($this->app)) {
            return null;
        }
        $file = $this->app->paths->storage('recovery.key');
        if (!is_file($file)) {
            @file_put_contents($file, bin2hex(random_bytes(16)) . "\n");
            @chmod($file, 0640);
        }
        $code = trim((string) @file_get_contents($file));
        return $code === '' ? null : $code;
    }

    public function recoverForm(Request $req): Response
    {
        if ($this->recoveryCode() === null) {
            return ErrorPage::render(404);
        }
        return $this->page('auth/recover', ['error' => null]);
    }

    public function recoverSubmit(Request $req): Response
    {
        $code = $this->recoveryCode();
        if ($code === null) {
            return ErrorPage::render(404);
        }
        $db = $this->app->db();
        $limiter = new RateLimiter($db);
        $bucket = RateLimiter::bucket('recover-ip', $req->ip);
        if ($limiter->blocked($bucket) > 0) {
            return $this->page('auth/recover', ['error' => 'Too many attempts. Wait a few minutes.'], 429);
        }
        $input = $req->input();
        $email = Authorization::normalizeEmail((string) ($input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $user = $db->one("SELECT * FROM {{users}} WHERE email = ? AND role = 'ADMIN'", [$email]);
        if (!hash_equals($code, trim((string) ($input['code'] ?? ''))) || $user === null) {
            $limiter->fail($bucket, free: 5);
            return $this->page('auth/recover', ['error' => 'That code and address don’t match an administrator.'], 400);
        }
        if (($problem = Passwords::problem($password, $email)) !== null) {
            return $this->page('auth/recover', ['error' => $problem], 400);
        }
        $db->update('users', ['password_hash' => Passwords::hash($password), 'email_verified_at' => $user['email_verified_at'] ?? Db::now()], ['id' => $user['id']]);
        $db->delete('sessions', ['user_id' => $user['id']]);
        @unlink($this->app->paths->storage('recovery.key'));
        \App\Modules\Audit\Audit::log($db, $email, 'auth.recover', 'User', (string) $user['id'], 'Password set through storage/enable-local-login');
        return $this->message(t('auth.resetTitle'), t('auth.passwordChanged') . ' Delete storage/enable-local-login once you are back in.');
    }

    /**
     * Called by the Auth0 Pre-User-Registration Action (and Supabase's
     * "before user created" hook): may this address create an account?
     * Bearer secret, fails closed, answers with nothing but a boolean.
     */
    public function registrationCheck(Request $req): Response
    {
        $secret = (string) $this->app->settings()->get('auth.registration_check_secret', '');
        $given = $req->bearerToken() ?? '';
        if ($secret === '' || !hash_equals(hash('sha256', $secret), hash('sha256', $given))) {
            return Response::json(['allowed' => false], 401);
        }
        $limiter = new RateLimiter($this->app->db());
        if (!$limiter->hit(RateLimiter::bucket('registration-check', $req->ip), 60, 60)) {
            return Response::json(['allowed' => false], 429);
        }
        $input = $req->json();
        $email = is_string($input['email'] ?? null) ? $input['email'] : '';
        $allowed = Access::authorization($this->app)->isEmailAuthorized($email);
        if (!$allowed && Validator::isEmail($email)) {
            AccessAttempts::record($this->app->db(), [
                'email' => $email,
                'sub' => is_string($input['auth0UserId'] ?? null) ? $input['auth0UserId'] : null,
                'provider' => is_string($input['provider'] ?? null) ? mb_substr($input['provider'], 0, 64) : null,
                'type' => 'SIGNUP',
                'reason' => Authorization::EMAIL_NOT_AUTHORIZED,
            ], $req->ip, $req->header('user-agent'), Access::mailer($this->app), Access::bootstrapAdmins($this->app));
        }
        return Response::json(['allowed' => $allowed]);
    }
}
