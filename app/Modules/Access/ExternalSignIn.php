<?php

declare(strict_types=1);

namespace App\Modules\Access;

use App\Core\App;
use App\Core\Crypto;
use App\Core\ErrorPage;
use App\Core\Log;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Url;
use App\Modules\Audit\Audit;
use App\Services\Auth\AuthProvider;
use App\Services\Auth\RedirectProvider;
use App\Services\Auth\TokenProvider;

/**
 * Signing in through an external provider.
 *
 *   /auth/start      the redirect flow: state, nonce and a PKCE verifier kept
 *                    in the session, then off to the provider
 *   /auth/callback   back again (GET, or POST for Apple's form_post): the
 *                    state is spent once, the code exchanged, the ID token
 *                    verified, and only then does SignIn decide
 *   /auth/token      the token flow: the provider's script signed somebody
 *                    in and the browser hands over the JWT to verify
 *
 * A switch of the sign-in slot completes only after the switching admin
 * has signed in through the new provider in trial mode: the same flows,
 * run against the tested (not yet saved) settings, which must come back
 * with that admin's own address and pass the authorization rules they
 * would face — otherwise the switch is refused and nothing changes.
 */
final class ExternalSignIn
{
    private const STATE_TTL = 600;

    public function __construct(private readonly App $app)
    {
    }

    public static function register(Router $r, App $app): void
    {
        $self = new self($app);
        $r->get('/auth/start', [$self, 'start']);
        $r->get('/auth/callback', [$self, 'callback']);
        $r->post('/auth/callback', [$self, 'callback']);
        $r->post('/auth/token', [$self, 'token']);
        $r->post('/admin/providers/auth/[provider]/trial', [$self, 'trialStart'], [Middleware::admin($app)]);
    }

    // Which provider, with which settings ---------------------------------------------------------

    /** The trial's settings while one is running, else the active provider. */
    private function provider(bool $trial): ?AuthProvider
    {
        if ($trial) {
            $t = $this->app->session()->get('auth_trial');
            if (!is_array($t) || (int) ($t['until'] ?? 0) < time()) {
                return null;
            }
            $class = $this->app->services()->providerClass('auth', (string) $t['provider']);
            $config = json_decode((string) Crypto::decrypt((string) $t['config']), true);
            $p = $class !== null && is_array($config) ? new $class($config) : null;
            return $p instanceof AuthProvider ? $p : null;
        }
        $p = $this->app->services()->active('auth');
        return $p instanceof AuthProvider ? $p : null;
    }

    /** @return array{mode: string, organizationIds: list<string>, guest: bool} */
    private function context(bool $guest): array
    {
        $s = $this->app->settings();
        return [
            'mode' => $s->string('auth.mode', 'BOTH'),
            'organizationIds' => Authorization::allowedOrganizationIds($s->string('auth.organization_ids')),
            'guest' => $guest,
        ];
    }

    // The redirect flow ---------------------------------------------------------------------------

    public function start(Request $req): Response
    {
        $trial = $req->query('trial') === '1';
        $guest = $req->query('guest') === '1';
        $provider = $this->provider($trial);
        if (!$provider instanceof RedirectProvider) {
            return ErrorPage::render(404);
        }
        if ($guest && !GuestLogin::enabled($this->app->db())) {
            return ErrorPage::render(404);
        }
        $state = bin2hex(random_bytes(16));
        $nonce = bin2hex(random_bytes(16));
        $verifier = \App\Support\Jwt::b64e(random_bytes(32));
        $session = $this->app->session();
        $pending = array_filter((array) $session->get('oidc_states', []), fn ($s) => is_array($s) && (int) ($s['at'] ?? 0) > time() - self::STATE_TTL);
        // A few tabs may be mid-sign-in at once; older attempts are dropped.
        $pending = array_slice($pending, -4, null, true);
        $pending[$state] = [
            'nonce' => $nonce,
            'verifier' => $verifier,
            'returnTo' => Url::safeReturnTo($req->query('returnTo')),
            'trial' => $trial,
            'guest' => $guest,
            'at' => time(),
        ];
        $session->set('oidc_states', $pending);
        try {
            return Response::redirect($provider->authorizeUrl($state, $nonce, $verifier, $this->context($guest)));
        } catch (\Throwable $e) {
            Log::warning('Sign-in start failed: ' . $e->getMessage());
            return $this->app->page('auth/message', ['title' => t('auth.signInTitle'), 'message' => t('auth.providerUnavailable')], 503, 'layouts/auth');
        }
    }

    public function callback(Request $req): Response
    {
        $in = $req->method === 'POST' ? $req->input() : $req->query;
        $session = $this->app->session();
        $stateKey = (string) ($in['state'] ?? '');
        $pending = (array) $session->get('oidc_states', []);
        $state = $pending[$stateKey] ?? null;
        if ($state === null && $req->method === 'POST' && !isset($in['_again'])) {
            // A cross-site POST (Apple's form_post) carries no Lax session cookie. Posting
            // it once more from our own page does, and then the state is found.
            return $this->app->page('auth/resubmit', ['fields' => array_filter($in, fn ($v) => is_string($v) && strlen($v) < 20000)], 200, 'layouts/auth');
        }
        // The state is spent whatever happens next.
        unset($pending[$stateKey]);
        $session->set('oidc_states', $pending);
        if (!is_array($state) || (int) $state['at'] < time() - self::STATE_TTL) {
            // Missing state: an old tab, a second click, or somebody else's link.
            return $this->refused(null, 'Missing or expired sign-in state.', (bool) ($state['trial'] ?? false));
        }
        $trial = (bool) $state['trial'];
        if (isset($in['error'])) {
            $detail = trim((string) $in['error'] . ': ' . (string) ($in['error_description'] ?? ''), ': ');
            return $this->refused(null, mb_substr($detail, 0, 500), $trial);
        }
        $provider = $this->provider($trial);
        if (!$provider instanceof RedirectProvider || !is_string($in['code'] ?? null)) {
            return $this->refused(null, 'The provider sent no sign-in code.', $trial);
        }
        try {
            $tokens = $provider->exchange((string) $in['code'], (string) $state['verifier']);
            $claims = $provider->verifyIdToken((string) $tokens['id_token'], (string) $state['nonce']);
            $identity = $provider->identityFrom($claims, $in, $this->context(false)['organizationIds']);
        } catch (\Throwable $e) {
            Log::warning('Sign-in callback failed: ' . $e->getMessage());
            return $this->refused(null, RedirectProvider::describe($e), $trial);
        }
        return $this->finish($identity, (string) $state['returnTo'], $trial);
    }

    // The token flow ------------------------------------------------------------------------------

    public function token(Request $req): Response
    {
        $in = $req->input();
        $trial = ($in['trial'] ?? false) === true || ($in['trial'] ?? null) === '1';
        $provider = $this->provider($trial);
        $jwt = (string) ($in['token'] ?? '');
        if (!$provider instanceof TokenProvider || $jwt === '' || strlen($jwt) > 16384) {
            return Response::json(['error' => t('auth.refused')], 400);
        }
        try {
            $identity = $provider->identityFromToken($jwt, $this->context(false)['organizationIds']);
        } catch (\Throwable $e) {
            Log::warning('Token sign-in failed: ' . $e->getMessage());
            $response = $this->refused(null, RedirectProvider::describe($e), $trial);
            return Response::json(['redirect' => $response->getHeader('Location')], 403);
        }
        $response = $this->finish($identity, Url::safeReturnTo(is_string($in['returnTo'] ?? null) ? $in['returnTo'] : null), $trial);
        return Response::json(['redirect' => $response->getHeader('Location')], $response->status === 302 ? 200 : $response->status);
    }

    // Ending up somewhere --------------------------------------------------------------------------

    private function finish(Identity $identity, string $returnTo, bool $trial): Response
    {
        if ($trial) {
            return $this->trialFinish($identity);
        }
        $result = (new SignIn($this->app))->complete($identity);
        return Response::redirect($result['ok'] ? $returnTo : Url::to('/access-denied'));
    }

    /** One plain page for a refusal, with what the provider said recorded for the administrator. */
    private function refused(?Identity $identity, string $detail, bool $trial): Response
    {
        if ($trial) {
            $this->app->session()->forget('auth_trial');
            $this->flash('The trial sign-in didn’t work: ' . $detail . ' Nothing was switched.');
            return Response::redirect(Url::to('/admin/providers'));
        }
        $request = $this->app->request();
        AccessAttempts::record($this->app->db(), [
            'email' => $identity?->email,
            'sub' => $identity?->sub,
            'provider' => $this->app->services()->activeId('auth'),
            'type' => 'LOGIN',
            'organizationMember' => false,
            'emailAuthorized' => false,
            'reason' => Authorization::CALLBACK_ERROR,
            'detail' => $detail,
        ], $request->ip, $request->header('user-agent'), Access::mailer($this->app), Access::bootstrapAdmins($this->app));
        return Response::redirect(Url::to('/access-denied'));
    }

    private function flash(string $message): void
    {
        $this->app->session()->set('flash', $message);
    }

    // Trial mode ----------------------------------------------------------------------------------

    /**
     * From the service form, after a passing test: the tested settings (kept
     * in the session by the test) are used for one sign-in by this admin.
     *
     * @param array<string, string> $p
     */
    public function trialStart(Request $req, array $p): Response
    {
        $id = $p['provider'];
        $payload = Crypto::verify('service-switch', (string) ($req->input()['token'] ?? ''));
        $pending = $this->app->session()->get('pending_service');
        if ($payload === null || !is_array($pending) || $payload['slot'] !== 'auth' || $payload['provider'] !== $id
            || $pending['slot'] !== 'auth' || $pending['provider'] !== $id || !hash_equals((string) $pending['hash'], (string) $payload['hash'])
            || $payload['by'] !== (string) $this->app->currentUser()->email()) {
            $this->flash('The trial was refused: its test result was missing, expired, or for different settings. Run the test again.');
            return Response::redirect(Url::to('/admin/providers'));
        }
        $this->app->session()->set('auth_trial', [
            'provider' => $id,
            'config' => (string) $pending['config'],
            'hash' => (string) $pending['hash'],
            'until' => time() + 900,
        ]);
        $class = $this->app->services()->providerClass('auth', $id);
        $trialProvider = $class !== null ? new $class((array) json_decode((string) Crypto::decrypt((string) $pending['config']), true)) : null;
        $flow = $trialProvider instanceof AuthProvider ? $trialProvider->flow() : 'redirect';
        return Response::redirect($flow === 'redirect' ? Url::to('/auth/start', ['trial' => '1']) : Url::to('/auth/login', ['trial' => '1']));
    }

    /**
     * The trial came back. It passes only when it is this admin, by their
     * own address, and the rules they would meet let them in; then the
     * settings are saved and switched on, and the identity linked to them.
     */
    private function trialFinish(Identity $identity): Response
    {
        $session = $this->app->session();
        $trial = $session->get('auth_trial');
        $session->forget('auth_trial');
        $admin = $this->app->currentUser()->user();
        if (!is_array($trial) || $admin === null || ($admin['role'] ?? null) !== 'ADMIN') {
            return $this->refused($identity, 'The trial ran out. Start it again from Services.', true);
        }
        $email = Authorization::normalizeEmail($identity->email);
        if (!$identity->emailVerified || $email !== Authorization::normalizeEmail((string) $admin['email'])) {
            return $this->refused($identity, 'It signed in ' . ($email !== '' ? $email : 'somebody') . ($identity->emailVerified ? '' : ' (unverified)') . ', not you (' . $admin['email'] . '). Sign in there with your own address.', true);
        }
        $verdict = Access::authorization($this->app)->authorizeIdentity($email, $identity->membership, $identity->membershipApplicable);
        if (!$verdict['allowed']) {
            return $this->refused($identity, 'You would not be let in under these settings (' . $verdict['reason'] . '), so switching would lock you out. Check the organization list and the allowlist first.', true);
        }
        $config = json_decode((string) Crypto::decrypt((string) $trial['config']), true);
        if (!is_array($config) || !hash_equals((string) $trial['hash'], hash('sha256', json_encode($config, JSON_THROW_ON_ERROR)))) {
            return $this->refused($identity, 'The tested settings couldn’t be recovered.', true);
        }
        $db = $this->app->db();
        $db->run(
            'INSERT INTO {{user_identities}} (id, user_id, sub, provider, email, email_verified, last_login_at) VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE user_id = user_id, last_login_at = VALUES(last_login_at)',
            [\App\Core\Id::new(), $admin['id'], $identity->sub, $identity->provider, $email, true, \App\Core\Db::now()],
        );
        $this->app->services()->save('auth', (string) $trial['provider'], $config, activate: true, by: (string) $admin['email']);
        $session->forget('pending_service');
        Audit::log($db, (string) $admin['email'], 'service.switch', 'Service', 'auth/' . $trial['provider'], 'after a trial sign-in');
        $this->flash('Signed in through ' . $this->app->services()->providerClass('auth', (string) $trial['provider'])::label() . ' as you: it is now how people sign in. Local sign-in stays open for administrators.');
        return Response::redirect(Url::to('/admin/providers'));
    }
}
