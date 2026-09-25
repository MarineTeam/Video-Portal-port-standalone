<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\App;
use App\Core\Http;
use App\Core\HttpException;
use App\Core\Router;
use App\Modules\Access\Identity;
use App\Services\BaseProvider;
use App\Services\TestContext;
use App\Services\TestResult;
use App\Support\Jwks;
use App\Support\Jwt;
use App\Support\JwtException;

/**
 * Firebase Authentication, token flow: the Firebase JS SDK from Google's
 * CDN signs the person in (email and password, or Google), and the ID
 * token it hands over is verified here against Google's published
 * certificates — iss https://securetoken.google.com/<project>, aud the
 * project — before anything is taken from it.
 */
final class FirebaseProvider extends BaseProvider implements TokenProvider
{
    public const SDK = '10.14.1';
    public const CERTS = 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';

    public static function slot(): string
    {
        return 'auth';
    }

    public static function id(): string
    {
        return 'firebase';
    }

    public static function label(): string
    {
        return 'Firebase Authentication';
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
        return 'Needs HTTPS. Add this site’s domain to Firebase → Authentication → Settings → Authorized domains.';
    }

    public static function configSchema(): array
    {
        return [
            ['key' => 'api_key', 'label' => 'Web API key', 'type' => 'text', 'required' => true, 'help' => 'From the web app’s config (it is public by design).'],
            ['key' => 'project_id', 'label' => 'Project ID', 'type' => 'text', 'required' => true],
            ['key' => 'auth_domain', 'label' => 'Auth domain', 'type' => 'text', 'help' => 'Default: <project id>.firebaseapp.com'],
            ['key' => 'google', 'label' => 'Offer “Continue with Google”', 'type' => 'toggle', 'default' => true],
            ['key' => 'password', 'label' => 'Offer email and password', 'type' => 'toggle', 'default' => true],
        ];
    }

    public function flow(): string
    {
        return 'token';
    }

    public function routes(Router $router, App $app): void
    {
    }

    public function logoutUrl(?string $returnTo): ?string
    {
        return null;
    }

    public function membershipClaim(): ?string
    {
        return null;
    }

    private function project(): string
    {
        return $this->str('project_id');
    }

    private function authDomain(): string
    {
        $d = $this->str('auth_domain');
        return $d !== '' ? $d : $this->project() . '.firebaseapp.com';
    }

    public function widget(): array
    {
        $base = 'https://www.gstatic.com/firebasejs/' . self::SDK;
        return [
            'scripts' => ["$base/firebase-app-compat.js", "$base/firebase-auth-compat.js"],
            'config' => [
                'kind' => 'firebase',
                'firebase' => ['apiKey' => $this->str('api_key'), 'authDomain' => $this->authDomain(), 'projectId' => $this->project()],
                'google' => (bool) $this->cfg('google', true),
                'password' => (bool) $this->cfg('password', true),
            ],
            'csp' => [
                'script' => ['https://www.gstatic.com', 'https://apis.google.com'],
                'connect' => ['https://identitytoolkit.googleapis.com', 'https://securetoken.googleapis.com'],
                'frame' => ['https://' . $this->authDomain()],
                'img' => ['https://lh3.googleusercontent.com'],
            ],
        ];
    }

    public function identityFromToken(string $jwt, array $organizationIds): Identity
    {
        $claims = Jwks::verify($jwt, self::CERTS, ['RS256']);
        Jwt::checkClaims($claims, 'https://securetoken.google.com/' . $this->project(), $this->project());
        $sub = (string) ($claims['sub'] ?? '');
        if ($sub === '' || strlen($sub) > 128 || (int) ($claims['auth_time'] ?? PHP_INT_MAX) > time() + Jwt::LEEWAY) {
            throw new JwtException('The token names no user.');
        }
        return new Identity(
            'firebase|' . $sub,
            'firebase',
            (string) ($claims['email'] ?? ''),
            ($claims['email_verified'] ?? false) === true,
            isset($claims['name']) ? (string) $claims['name'] : null,
            isset($claims['picture']) ? (string) $claims['picture'] : null,
            null,
            false,
        );
    }

    public function test(TestContext $context): TestResult
    {
        $steps = [];
        if (!$context->https) {
            return TestResult::fail('The site must be reached over HTTPS first.');
        }
        try {
            Jwks::get(self::CERTS, fresh: true);
            $steps[] = 'Google’s signing certificates fetched';
            $r = Http::json('GET', 'https://identitytoolkit.googleapis.com/v1/projects?key=' . rawurlencode($this->str('api_key')));
        } catch (HttpException $e) {
            return TestResult::fail($e->getMessage(), $steps);
        }
        $d = $r->json();
        if (!$r->ok() || !is_array($d)) {
            return TestResult::fail('Firebase refused the web API key (' . $r->status . ').', $steps);
        }
        if (isset($d['projectId']) && $d['projectId'] !== $this->project()) {
            return TestResult::fail('That API key belongs to the project ' . $d['projectId'] . ', not ' . $this->project() . '.', $steps);
        }
        $domains = (array) ($d['authorizedDomains'] ?? []);
        $host = (string) parse_url(\App\Core\Url::baseUrl(), PHP_URL_HOST);
        if ($domains !== [] && !in_array($host, $domains, true)) {
            return TestResult::fail("Add $host to the project’s authorized domains (Authentication → Settings).", $steps);
        }
        $steps[] = 'Project configuration read';
        return TestResult::ok('Firebase answers. Next, sign in with it once to prove it works before the switch.', $steps);
    }
}
