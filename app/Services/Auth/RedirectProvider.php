<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\App;
use App\Core\Cache;
use App\Core\Http;
use App\Core\HttpException;
use App\Core\Router;
use App\Core\Url;
use App\Modules\Access\Identity;
use App\Services\BaseProvider;
use App\Services\TestContext;
use App\Services\TestResult;
use App\Support\Jwks;
use App\Support\Jwt;
use App\Support\JwtException;

/**
 * The redirect flow every OpenID Connect provider shares: discovery, the
 * authorization request with state, nonce and PKCE (S256), the code
 * exchange, and the ID token verified against the provider's JWKS — its
 * issuer, audience, expiry and nonce — before any identity is taken from
 * it. The routes (/auth/start, /auth/callback) are the core's, in
 * Access\ExternalSignIn; a provider supplies the details.
 */
abstract class RedirectProvider extends BaseProvider implements AuthProvider
{
    public static function slot(): string
    {
        return 'auth';
    }

    public static function requiresHttps(): bool
    {
        return true;
    }

    public static function requiresOutboundHttps(): bool
    {
        return true;
    }

    public function flow(): string
    {
        return 'redirect';
    }

    public function routes(Router $router, App $app): void
    {
    }

    /** What the sign-in button calls this provider. */
    public function displayName(): string
    {
        return static::label();
    }

    /** The discovery document's URL. */
    abstract public function discoveryUrl(): string;

    /** The provider id subs are namespaced with (<prefix>|<sub>). */
    abstract public function subPrefix(): string;

    public function clientId(): string
    {
        return $this->str('client_id');
    }

    public function clientSecret(): string
    {
        return $this->str('client_secret');
    }

    public function scopes(): string
    {
        return 'openid email profile';
    }

    /** form_post for Sign in with Apple; null for the ordinary query response. */
    public function responseMode(): ?string
    {
        return null;
    }

    /**
     * Extra authorization parameters (Auth0's organization, Google's hd).
     *
     * @param array{mode: string, organizationIds: list<string>, guest: bool} $context
     * @return array<string, string>
     */
    public function authorizeParams(array $context): array
    {
        return [];
    }

    /**
     * The identity from verified claims.
     *
     * @param array<string, mixed> $claims
     * @param array<string, mixed> $callback the callback's own parameters (Apple sends the name there)
     * @param list<string> $organizationIds the accepted membership values
     */
    abstract public function identityFrom(array $claims, array $callback, array $organizationIds): Identity;

    public static function redirectUri(): string
    {
        return Url::absolute('/auth/callback');
    }

    // Discovery -------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function discovery(bool $fresh = false): array
    {
        $url = $this->discoveryUrl();
        $key = 'oidc-discovery:' . hash('sha256', $url);
        if (!$fresh) {
            $cached = Cache::get($key);
            if (is_array($cached)) {
                return $cached;
            }
        }
        $r = Http::request('GET', $url, ['Accept' => 'application/json'], null, ['timeout' => 10]);
        $d = $r->json();
        if (!$r->ok() || !is_array($d) || !isset($d['authorization_endpoint'], $d['token_endpoint'], $d['jwks_uri'], $d['issuer'])) {
            throw new HttpException('The provider’s discovery document at ' . $url . ' couldn’t be read (' . $r->status . ').');
        }
        Cache::set($key, $d, 6 * 3600);
        return $d;
    }

    // The flow --------------------------------------------------------------------------------

    public static function pkceChallenge(string $verifier): string
    {
        return Jwt::b64e(hash('sha256', $verifier, true));
    }

    /** @param array{mode: string, organizationIds: list<string>, guest: bool} $context */
    public function authorizeUrl(string $state, string $nonce, string $verifier, array $context): string
    {
        $params = [
            'response_type' => 'code',
            'client_id' => $this->clientId(),
            'redirect_uri' => self::redirectUri(),
            'scope' => $this->scopes(),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => self::pkceChallenge($verifier),
            'code_challenge_method' => 'S256',
        ] + $this->authorizeParams($context);
        if ($this->responseMode() !== null) {
            $params['response_mode'] = $this->responseMode();
        }
        $endpoint = (string) $this->discovery()['authorization_endpoint'];
        return $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Code for tokens.
     *
     * @return array<string, mixed>
     */
    public function exchange(string $code, string $verifier): array
    {
        $d = $this->discovery();
        $fields = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::redirectUri(),
            'code_verifier' => $verifier,
            'client_id' => $this->clientId(),
        ];
        $headers = ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'];
        $secret = $this->clientSecret();
        $methods = (array) ($d['token_endpoint_auth_methods_supported'] ?? ['client_secret_basic']);
        if ($secret !== '') {
            if (in_array('client_secret_post', $methods, true) && !in_array('client_secret_basic', $methods, true) || $this->prefersPostAuth()) {
                $fields['client_secret'] = $secret;
            } else {
                $headers['Authorization'] = 'Basic ' . base64_encode(rawurlencode($this->clientId()) . ':' . rawurlencode($secret));
            }
        }
        $r = Http::request('POST', (string) $d['token_endpoint'], $headers, http_build_query($fields), ['timeout' => 15]);
        $t = $r->json();
        if (!$r->ok() || !is_array($t) || !is_string($t['id_token'] ?? null)) {
            $why = is_array($t) ? (string) ($t['error_description'] ?? $t['error'] ?? '') : '';
            throw new HttpException('The provider wouldn’t exchange the sign-in code' . ($why !== '' ? ': ' . $why : ' (' . $r->status . ').'));
        }
        return $t;
    }

    /** Some providers (Apple) take the secret in the body only. */
    protected function prefersPostAuth(): bool
    {
        return false;
    }

    /**
     * The ID token's claims, once its signature, issuer, audience, expiry
     * and nonce hold.
     *
     * @return array<string, mixed>
     */
    public function verifyIdToken(string $idToken, string $nonce): array
    {
        $d = $this->discovery();
        $algorithms = array_values(array_intersect((array) ($d['id_token_signing_alg_values_supported'] ?? ['RS256']), ['RS256', 'ES256']));
        $claims = Jwks::verify($idToken, (string) $d['jwks_uri'], $algorithms !== [] ? $algorithms : ['RS256']);
        $issuer = (string) $d['issuer'];
        // Entra's multi-tenant discovery names the issuer with a {tenantid} placeholder.
        if (str_contains($issuer, '{tenantid}') && is_string($claims['tid'] ?? null)) {
            $issuer = str_replace('{tenantid}', $claims['tid'], $issuer);
        }
        Jwt::checkClaims($claims, $issuer, $this->clientId(), $nonce);
        return $claims;
    }

    public function logoutUrl(?string $returnTo): ?string
    {
        try {
            $endpoint = $this->discovery()['end_session_endpoint'] ?? null;
        } catch (HttpException) {
            return null;
        }
        if (!is_string($endpoint) || $endpoint === '') {
            return null;
        }
        return $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . http_build_query(['client_id' => $this->clientId(), 'post_logout_redirect_uri' => Url::absolute($returnTo ?? '/')]);
    }

    // Helpers for identityFrom ----------------------------------------------------------------

    /** "true", true and 1 all mean verified; Apple sends a string. */
    protected static function truthy(mixed $v): bool
    {
        return $v === true || $v === 1 || $v === 'true' || $v === '1';
    }

    /**
     * A membership claim that may be a list (groups): the value that is on
     * the accepted list when there is one, else the first.
     *
     * @param list<string> $accepted
     */
    protected static function membershipValue(mixed $claim, array $accepted): ?string
    {
        if (is_string($claim) && $claim !== '') {
            return $claim;
        }
        if (is_array($claim)) {
            $values = array_values(array_filter(array_map(fn ($v) => is_scalar($v) ? (string) $v : null, $claim)));
            foreach ($values as $v) {
                if (in_array($v, $accepted, true)) {
                    return $v;
                }
            }
            return $values[0] ?? null;
        }
        return null;
    }

    // The test --------------------------------------------------------------------------------

    public function test(TestContext $context): TestResult
    {
        $steps = [];
        if (!$context->https || !str_starts_with(self::redirectUri(), 'https://')) {
            return TestResult::fail('The site must be reached over HTTPS first: the sign-in callback, ' . self::redirectUri() . ', has to be an https:// address.');
        }
        if ($this->clientId() === '') {
            return TestResult::fail('Enter the client ID.');
        }
        try {
            $d = $this->discovery(fresh: true);
            $steps[] = 'Discovery document read (issuer ' . $d['issuer'] . ')';
            $keys = Jwks::get((string) $d['jwks_uri'], fresh: true);
            $steps[] = 'Signing keys fetched (' . count((array) ($keys['keys'] ?? $keys)) . ')';
        } catch (HttpException $e) {
            return TestResult::fail($e->getMessage(), $steps);
        }
        $steps[] = 'Add this callback address to the application at the provider: ' . self::redirectUri();
        return TestResult::ok('The provider answers. Next, sign in with it once to prove it works before the switch.', $steps);
    }

    /** @return list<array<string, mixed>> the fields every redirect provider has */
    protected static function clientFields(bool $secretRequired = true): array
    {
        return [
            ['key' => 'client_id', 'label' => 'Client ID', 'type' => 'text', 'required' => true],
            ['key' => 'client_secret', 'label' => 'Client secret', 'type' => 'password', 'secret' => true, 'required' => $secretRequired],
        ];
    }

    /** Wraps a claim failure for the callback's one plain refusal. */
    public static function describe(\Throwable $e): string
    {
        return $e instanceof JwtException || $e instanceof HttpException ? $e->getMessage() : 'The sign-in couldn’t be completed.';
    }
}
