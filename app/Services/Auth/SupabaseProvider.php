<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\App;
use App\Core\Http;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Url;
use App\Modules\Access\ExternalSignIn;
use App\Modules\Access\Identity;
use App\Services\BaseProvider;
use App\Services\TestContext;
use App\Services\TestResult;
use App\Support\Jwks;
use App\Support\Jwt;
use App\Support\JwtException;

/**
 * Supabase Auth over GoTrue's REST API, no SDK: a password form, a magic
 * link, and any social provider the project has enabled, through its PKCE
 * flow. The access token is verified here — HS256 with the project's JWT
 * secret, or the project's JWKS when it signs asymmetrically — and asked of
 * /auth/v1/user only when neither is available; email_confirmed_at is what
 * email_verified means.
 */
final class SupabaseProvider extends BaseProvider implements AuthProvider
{
    private const PKCE_TTL = 900;

    public static function slot(): string
    {
        return 'auth';
    }

    public static function id(): string
    {
        return 'supabase';
    }

    public static function label(): string
    {
        return 'Supabase Auth';
    }

    public static function requiresHttps(): bool
    {
        return true;
    }

    public static function requiresOutboundHttps(): bool
    {
        return true;
    }

    public static function limits(): string
    {
        return 'Needs HTTPS. Add ' . '/auth/supabase/callback on this site to the project’s redirect URLs. For magic links, the email template may link to /auth/supabase/verify?token_hash={{ .TokenHash }}&type=magiclink; the default PKCE link works too.';
    }

    public static function configSchema(): array
    {
        return [
            ['key' => 'url', 'label' => 'Project URL', 'type' => 'text', 'required' => true, 'help' => 'https://<project>.supabase.co'],
            ['key' => 'anon_key', 'label' => 'Anon (publishable) key', 'type' => 'text', 'required' => true],
            ['key' => 'jwt_secret', 'label' => 'JWT secret (legacy HS256 projects)', 'type' => 'password', 'secret' => true, 'help' => 'Leave blank if the project uses signing keys: its JWKS is used instead.'],
            ['key' => 'service_role_key', 'label' => 'Service-role key (optional)', 'type' => 'password', 'secret' => true, 'help' => 'Only used by the test, to check the admin API answers.'],
            ['key' => 'password', 'label' => 'Offer email and password', 'type' => 'toggle', 'default' => true],
            ['key' => 'magic_link', 'label' => 'Offer a magic link by email', 'type' => 'toggle', 'default' => false],
            ['key' => 'social', 'label' => 'Social providers (comma separated)', 'type' => 'text', 'help' => 'As enabled in Supabase, e.g. google, github, azure.'],
        ];
    }

    public function flow(): string
    {
        return 'form';
    }

    public function logoutUrl(?string $returnTo): ?string
    {
        return null;
    }

    public function membershipClaim(): ?string
    {
        return null;
    }

    public function base(): string
    {
        return rtrim($this->str('url'), '/') . '/auth/v1';
    }

    /** @return array<string, string> */
    public function headers(?string $bearer = null): array
    {
        return ['apikey' => $this->str('anon_key'), 'Authorization' => 'Bearer ' . ($bearer ?? $this->str('anon_key'))];
    }

    /** @return list<string> */
    public function socialProviders(): array
    {
        return array_values(array_filter(array_map(fn ($p) => strtolower(trim($p)), explode(',', $this->str('social'))), fn ($p) => preg_match('/^[a-z0-9_-]{2,30}$/', $p) === 1));
    }

    /** What /auth/login shows. @return array{password: bool, magicLink: bool, social: list<string>} */
    public function forms(): array
    {
        return ['password' => (bool) $this->cfg('password', true), 'magicLink' => (bool) $this->cfg('magic_link', false), 'social' => $this->socialProviders()];
    }

    // Tokens --------------------------------------------------------------------------------

    /**
     * The identity from a GoTrue token response ({access_token, user}).
     *
     * @param array<string, mixed> $session
     */
    public function identityFromSession(array $session): Identity
    {
        $token = (string) ($session['access_token'] ?? '');
        $claims = $this->verifyAccessToken($token);
        $user = is_array($session['user'] ?? null) ? $session['user'] : $this->user($token);
        if ((string) ($user['id'] ?? '') !== (string) ($claims['sub'] ?? '')) {
            throw new JwtException('The token and the user it came with don’t match.');
        }
        $meta = is_array($user['user_metadata'] ?? null) ? $user['user_metadata'] : [];
        return new Identity(
            'supabase|' . $claims['sub'],
            'supabase',
            (string) ($user['email'] ?? $claims['email'] ?? ''),
            !empty($user['email_confirmed_at']),
            isset($meta['full_name']) ? (string) $meta['full_name'] : (isset($meta['name']) ? (string) $meta['name'] : null),
            isset($meta['avatar_url']) ? (string) $meta['avatar_url'] : null,
            null,
            false,
        );
    }

    /** @return array<string, mixed> */
    public function verifyAccessToken(string $token): array
    {
        $header = Jwt::decode($token)['header'];
        if (($header['alg'] ?? '') === 'HS256') {
            if ($this->str('jwt_secret') === '') {
                // Nothing to check a shared-secret token with here: Supabase itself must vouch.
                $user = $this->user($token);
                return ['sub' => (string) ($user['id'] ?? ''), 'email' => $user['email'] ?? null];
            }
            $claims = Jwt::verify($token, [], ['HS256'], $this->str('jwt_secret'));
        } else {
            $claims = Jwks::verify($token, $this->base() . '/.well-known/jwks.json', ['RS256', 'ES256']);
        }
        Jwt::checkClaims($claims, $this->base(), 'authenticated');
        return $claims;
    }

    /** @return array<string, mixed> GET /auth/v1/user with the person's own token */
    private function user(string $token): array
    {
        $r = Http::json('GET', $this->base() . '/user', null, $this->headers($token));
        $u = $r->json();
        if (!$r->ok() || !is_array($u) || !isset($u['id'])) {
            throw new JwtException('Supabase didn’t recognise the sign-in.');
        }
        return $u;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function tokenGrant(string $grant, array $payload): array
    {
        $r = Http::json('POST', $this->base() . '/token?grant_type=' . $grant, $payload, $this->headers());
        $d = $r->json();
        if (!$r->ok() || !is_array($d) || !isset($d['access_token'])) {
            $why = is_array($d) ? (string) ($d['error_description'] ?? $d['msg'] ?? $d['error'] ?? '') : '';
            throw new HttpException($why !== '' ? $why : 'Supabase refused the sign-in (' . $r->status . ').');
        }
        return $d;
    }

    // Its own routes ----------------------------------------------------------------------------

    public function routes(Router $router, App $app): void
    {
        $router->post('/auth/supabase/password', fn (Request $req) => self::handle($app, $req, 'password'));
        $router->post('/auth/supabase/magic', fn (Request $req) => self::handle($app, $req, 'magic'));
        $router->get('/auth/supabase/social', fn (Request $req) => self::handle($app, $req, 'social'));
        $router->get('/auth/supabase/callback', fn (Request $req) => self::handle($app, $req, 'callback'));
        $router->get('/auth/supabase/verify', fn (Request $req) => self::handle($app, $req, 'verify'));
    }

    private static function handle(App $app, Request $req, string $action): Response
    {
        $session = $app->session();
        $trial = $req->query('trial') === '1' || ($req->input()['trial'] ?? null) === '1'
            || ($action === 'callback' || $action === 'verify') && (bool) ($session->get('supabase_pkce')['trial'] ?? false);
        $flow = new ExternalSignIn($app);
        $provider = $flow->provider($trial);
        if (!$provider instanceof self) {
            return \App\Core\ErrorPage::render(404);
        }
        $returnTo = Url::safeReturnTo(is_string($req->input()['returnTo'] ?? null) ? $req->input()['returnTo'] : $req->query('returnTo'));
        // Back to the form with the reason, kept in the session rather than the address.
        $back = function (string $error) use ($session, $trial, $returnTo): Response {
            $session->set('login_error', $error);
            return Response::redirect(Url::to('/auth/login', array_filter(['returnTo' => $returnTo !== '/' ? $returnTo : null, 'trial' => $trial ? '1' : null])));
        };
        try {
            switch ($action) {
                case 'password':
                    $in = $req->input();
                    $limiter = new \App\Core\RateLimiter($app->db());
                    if (!$limiter->hit(\App\Core\RateLimiter::bucket('supabase-login', $req->ip), 20, 900)) {
                        return $back('Too many attempts. Try again in a few minutes.');
                    }
                    $tokens = $provider->tokenGrant('password', ['email' => (string) ($in['email'] ?? ''), 'password' => (string) ($in['password'] ?? '')]);
                    return $flow->finish($provider->identityFromSession($tokens), $returnTo, $trial);
                case 'magic':
                    $limiter = new \App\Core\RateLimiter($app->db());
                    if (!$limiter->hit(\App\Core\RateLimiter::bucket('supabase-magic', $req->ip), 10, 900)) {
                        return $back('Too many attempts. Try again in a few minutes.');
                    }
                    $verifier = $provider->startPkce($app, $returnTo, $trial);
                    $email = (string) ($req->input()['email'] ?? '');
                    // As supabase-js sends it: the redirect in the query, no new accounts from here.
                    Http::json('POST', $provider->base() . '/otp?' . http_build_query(['redirect_to' => Url::absolute('/auth/supabase/callback')]), [
                        'email' => $email,
                        'create_user' => false,
                        'code_challenge' => RedirectProvider::pkceChallenge($verifier),
                        'code_challenge_method' => 's256',
                    ], $provider->headers());
                    // The same answer whether or not the address is known.
                    return $app->page('auth/message', ['title' => t('auth.signInTitle'), 'body' => t('auth.magicSent', ['email' => $email])], 200, 'layouts/auth');
                case 'social':
                    $name = (string) $req->query('provider');
                    if (!in_array($name, $provider->socialProviders(), true)) {
                        return \App\Core\ErrorPage::render(404);
                    }
                    $verifier = $provider->startPkce($app, $returnTo, $trial);
                    return Response::redirect($provider->base() . '/authorize?' . http_build_query([
                        'provider' => $name,
                        'redirect_to' => Url::absolute('/auth/supabase/callback'),
                        'code_challenge' => RedirectProvider::pkceChallenge($verifier),
                        'code_challenge_method' => 's256',
                    ]));
                case 'callback':
                    $pkce = $provider->takePkce($app);
                    if ($pkce === null || !is_string($req->query('code'))) {
                        return $flow->refused(null, (string) ($req->query('error_description') ?? 'Missing or expired sign-in state.'), $trial);
                    }
                    $tokens = $provider->tokenGrant('pkce', ['auth_code' => $req->query('code'), 'code_verifier' => $pkce['verifier']]);
                    return $flow->finish($provider->identityFromSession($tokens), (string) $pkce['returnTo'], $trial);
                case 'verify':
                    $hash = (string) $req->query('token_hash');
                    $type = in_array($req->query('type'), ['magiclink', 'email', 'signup', 'recovery'], true) ? (string) $req->query('type') : 'magiclink';
                    $r = Http::json('POST', $provider->base() . '/verify', ['type' => $type, 'token_hash' => $hash], $provider->headers());
                    $d = $r->json();
                    if (!$r->ok() || !is_array($d) || !isset($d['access_token'])) {
                        return $flow->refused(null, 'The sign-in link has expired or was already used.', $trial);
                    }
                    return $flow->finish($provider->identityFromSession($d), $returnTo, $trial);
            }
        } catch (HttpException $e) {
            return $action === 'password' ? $back($e->getMessage()) : $flow->refused(null, $e->getMessage(), $trial);
        } catch (JwtException $e) {
            return $flow->refused(null, $e->getMessage(), $trial);
        }
        return \App\Core\ErrorPage::render(404);
    }

    /** A verifier for one PKCE round trip, kept in the session. */
    public function startPkce(App $app, string $returnTo, bool $trial): string
    {
        $verifier = Jwt::b64e(random_bytes(32));
        $app->session()->set('supabase_pkce', ['verifier' => $verifier, 'returnTo' => $returnTo, 'trial' => $trial, 'at' => time()]);
        return $verifier;
    }

    /** @return array{verifier: string, returnTo: string, trial: bool, at: int}|null spent once */
    public function takePkce(App $app): ?array
    {
        $p = $app->session()->pull('supabase_pkce');
        return is_array($p) && (int) ($p['at'] ?? 0) > time() - self::PKCE_TTL ? $p : null;
    }

    // The test ----------------------------------------------------------------------------------

    public function test(TestContext $context): TestResult
    {
        $steps = [];
        if (!$context->https) {
            return TestResult::fail('The site must be reached over HTTPS first.');
        }
        try {
            $r = Http::json('GET', $this->base() . '/settings', null, $this->headers());
            if (!$r->ok()) {
                return TestResult::fail('Supabase refused the project URL or anon key (' . $r->status . ').');
            }
            $steps[] = 'Project settings read with the anon key';
            $external = array_keys(array_filter((array) ($r->json()['external'] ?? [])));
            foreach ($this->socialProviders() as $p) {
                if (!in_array($p, $external, true)) {
                    return TestResult::fail("The social provider “{$p}” isn’t enabled in this Supabase project.", $steps);
                }
            }
            if ($this->str('service_role_key') !== '') {
                $admin = Http::json('GET', $this->base() . '/admin/users?per_page=1', null, ['apikey' => $this->str('service_role_key'), 'Authorization' => 'Bearer ' . $this->str('service_role_key')]);
                if (!$admin->ok()) {
                    return TestResult::fail('The service-role key was refused (' . $admin->status . ').', $steps);
                }
                $steps[] = 'Service-role key accepted';
            }
            if ($this->str('jwt_secret') === '') {
                Jwks::get($this->base() . '/.well-known/jwks.json', fresh: true);
                $steps[] = 'Signing keys fetched';
            }
        } catch (HttpException $e) {
            return TestResult::fail($e->getMessage(), $steps);
        }
        return TestResult::ok('Supabase answers. Next, sign in with it once to prove it works before the switch.', $steps);
    }
}
