<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\App;
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
 * Clerk, native: ClerkJS from the instance's Frontend API mounts Clerk's
 * sign-in on /auth/login, and the browser posts the session JWT here. It
 * is verified against the Frontend API's JWKS; sub (user_…) and org_id come
 * from it, and the address and its verification from a JWT template's
 * email / email_verified claims when the admin made one, else from the
 * Backend API with the secret key.
 */
final class ClerkProvider extends BaseProvider implements TokenProvider
{
    public const JS_VERSION = '5';

    public static function slot(): string
    {
        return 'auth';
    }

    public static function id(): string
    {
        return 'clerk';
    }

    public static function label(): string
    {
        return 'Clerk';
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
        return 'Needs HTTPS and the DNS records Clerk asks for (production instances). For the address, add a JWT template named “marine-team” with {"email": "{{user.primary_email_address}}", "email_verified": "{{user.email_verified}}"} — or leave it out and the secret key is used to look the address up.';
    }

    public static function configSchema(): array
    {
        return [
            ['key' => 'publishable_key', 'label' => 'Publishable key', 'type' => 'text', 'required' => true, 'help' => 'pk_live_… (or pk_test_… while trying it out).'],
            ['key' => 'secret_key', 'label' => 'Secret key', 'type' => 'password', 'secret' => true, 'required' => true],
            ['key' => 'jwt_template', 'label' => 'JWT template name (optional)', 'type' => 'text', 'help' => 'The template carrying email and email_verified.'],
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
        // Clerk's own session ends in the browser when the page script signs out.
        return null;
    }

    public function membershipClaim(): string
    {
        return 'org_id';
    }

    /** The Frontend API host, which the publishable key encodes ("clerk.example.org$"). */
    public static function frontendApi(string $publishableKey): ?string
    {
        if (!preg_match('/^pk_(live|test)_([A-Za-z0-9+\/=_-]+)$/', trim($publishableKey), $m)) {
            return null;
        }
        $decoded = base64_decode(strtr($m[2], '-_', '+/'), true);
        if (!is_string($decoded) || !str_ends_with($decoded, '$')) {
            return null;
        }
        $host = rtrim($decoded, '$');
        return preg_match('/^[a-z0-9.-]+$/i', $host) ? strtolower($host) : null;
    }

    private function fapi(): string
    {
        return self::frontendApi($this->str('publishable_key')) ?? throw new \RuntimeException('That isn’t a Clerk publishable key.');
    }

    public function widget(): array
    {
        $host = $this->fapi();
        return [
            'scripts' => ["https://$host/npm/@clerk/clerk-js@" . self::JS_VERSION . '/dist/clerk.browser.js'],
            'config' => ['kind' => 'clerk', 'publishableKey' => $this->str('publishable_key'), 'template' => $this->str('jwt_template') !== '' ? $this->str('jwt_template') : null],
            'csp' => [
                'script' => ["https://$host"],
                'connect' => ["https://$host"],
                'img' => ['https://img.clerk.com'],
                'frame' => ["https://$host", 'https://challenges.cloudflare.com'],
            ],
        ];
    }

    public function identityFromToken(string $jwt, array $organizationIds): Identity
    {
        $host = $this->fapi();
        $claims = Jwks::verify($jwt, "https://$host/.well-known/jwks.json", ['RS256']);
        if ((string) ($claims['iss'] ?? '') !== "https://$host") {
            throw new JwtException('The token was issued by somebody else.');
        }
        if (!isset($claims['exp']) || (int) $claims['exp'] + Jwt::LEEWAY < time() || (int) ($claims['nbf'] ?? 0) - Jwt::LEEWAY > time()) {
            throw new JwtException('The token has expired.');
        }
        // A session token names the site it was made for; it must be this one.
        if (isset($claims['azp']) && rtrim((string) $claims['azp'], '/') !== rtrim(Url::origin(), '/')) {
            throw new JwtException('The token was made for a different site.');
        }
        $sub = (string) ($claims['sub'] ?? '');
        if (!str_starts_with($sub, 'user_')) {
            throw new JwtException('The token names no user.');
        }
        if (is_string($claims['email'] ?? null) && $claims['email'] !== '') {
            $email = $claims['email'];
            $verified = in_array($claims['email_verified'] ?? false, [true, 'true', 1, '1'], true);
            $name = isset($claims['name']) ? (string) $claims['name'] : null;
        } else {
            [$email, $verified, $name] = $this->lookUp($sub);
        }
        return new Identity('clerk|' . $sub, 'clerk', $email, $verified, $name, null, isset($claims['org_id']) ? (string) $claims['org_id'] : null, true);
    }

    /** @return array{0: string, 1: bool, 2: ?string} the primary address, whether it's verified, the name */
    private function lookUp(string $userId): array
    {
        $r = Http::json('GET', 'https://api.clerk.com/v1/users/' . rawurlencode($userId), null, ['Authorization' => 'Bearer ' . $this->str('secret_key')]);
        $u = $r->json();
        if (!$r->ok() || !is_array($u)) {
            throw new HttpException('Clerk wouldn’t say who that is (' . $r->status . ').');
        }
        foreach ((array) ($u['email_addresses'] ?? []) as $a) {
            if (is_array($a) && ($a['id'] ?? null) === ($u['primary_email_address_id'] ?? false)) {
                $name = trim((string) ($u['first_name'] ?? '') . ' ' . (string) ($u['last_name'] ?? ''));
                return [(string) ($a['email_address'] ?? ''), ($a['verification']['status'] ?? '') === 'verified', $name !== '' ? $name : null];
            }
        }
        return ['', false, null];
    }

    public function test(TestContext $context): TestResult
    {
        $steps = [];
        if (!$context->https) {
            return TestResult::fail('The site must be reached over HTTPS first.');
        }
        $host = self::frontendApi($this->str('publishable_key'));
        if ($host === null) {
            return TestResult::fail('That isn’t a Clerk publishable key (pk_live_… or pk_test_…).');
        }
        try {
            Jwks::get("https://$host/.well-known/jwks.json", fresh: true);
            $steps[] = "Signing keys fetched from $host";
            $r = Http::json('GET', 'https://api.clerk.com/v1/users?limit=1', null, ['Authorization' => 'Bearer ' . $this->str('secret_key')]);
        } catch (HttpException $e) {
            return TestResult::fail($e->getMessage(), $steps);
        }
        if (!$r->ok()) {
            return TestResult::fail('Clerk refused the secret key (' . $r->status . ').', $steps);
        }
        $steps[] = 'Secret key accepted';
        return TestResult::ok('Clerk answers. Next, sign in with it once to prove it works before the switch.', $steps);
    }
}
