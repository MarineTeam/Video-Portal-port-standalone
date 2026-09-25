<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Core\Http;
use App\Core\HttpException;
use App\Core\HttpResponse;
use App\Services\Auth\SupabaseProvider;
use App\Services\TestContext;
use App\Support\Jwt;
use App\Support\JwtException;
use PHPUnit\Framework\TestCase;

final class SupabaseProviderTest extends TestCase
{
    private const URL = 'https://abc.supabase.co';
    private const SECRET = 'super-secret-jwt-token-with-at-least-32-characters';

    /** @var list<array{string, string, array<string, string>, ?string}> */
    private array $calls = [];

    protected function setUp(): void
    {
        \App\Core\Cache::configure(null);
        $this->calls = [];
    }

    protected function tearDown(): void
    {
        Http::fake(null);
    }

    /** @param array<string, HttpResponse> $routes "METHOD url-prefix" => answer */
    private function fake(array $routes): void
    {
        Http::fake(function (string $m, string $url, array $headers = [], ?string $body = null) use ($routes): HttpResponse {
            $this->calls[] = [$m, $url, $headers, $body];
            foreach ($routes as $key => $answer) {
                [$method, $prefix] = explode(' ', $key, 2);
                if ($method === $m && str_starts_with($url, $prefix)) {
                    return $answer;
                }
            }
            return new HttpResponse(599, [], 'no fixture ' . $m . ' ' . $url);
        });
    }

    private static function json(mixed $d, int $status = 200): HttpResponse
    {
        return new HttpResponse($status, ['content-type' => 'application/json'], (string) json_encode($d));
    }

    /** @param array<string, mixed> $config */
    private static function provider(array $config = []): SupabaseProvider
    {
        return new SupabaseProvider($config + ['url' => self::URL . '/', 'anon_key' => 'anon', 'jwt_secret' => self::SECRET]);
    }

    /** @param array<string, mixed> $claims */
    private static function token(array $claims = []): string
    {
        return Jwt::signHs256($claims + ['iss' => self::URL . '/auth/v1', 'aud' => 'authenticated', 'sub' => 'u-1', 'exp' => time() + 3600, 'email' => 'ruth@example.org', 'role' => 'authenticated'], self::SECRET);
    }

    /** @return array<string, mixed> a GoTrue token response */
    private static function session(string $token, array $user = []): array
    {
        return ['access_token' => $token, 'token_type' => 'bearer', 'expires_in' => 3600, 'refresh_token' => 'r', 'user' => $user + [
            'id' => 'u-1', 'aud' => 'authenticated', 'email' => 'ruth@example.org', 'email_confirmed_at' => '2026-01-02T03:04:05Z',
            'user_metadata' => ['full_name' => 'Ruth Moabite', 'avatar_url' => 'https://example.org/r.png'],
        ]];
    }

    public function test_a_password_grant_posts_to_gotrue_with_the_anon_key(): void
    {
        $session = self::session(self::token());
        $this->fake(['POST ' . self::URL . '/auth/v1/token?grant_type=password' => self::json($session)]);
        $got = self::provider()->tokenGrant('password', ['email' => 'ruth@example.org', 'password' => 'pw']);
        self::assertSame($session['access_token'], $got['access_token']);
        [, , $headers, $body] = $this->calls[0];
        self::assertSame('anon', $headers['apikey']);
        self::assertSame('Bearer anon', $headers['Authorization']);
        self::assertSame(['email' => 'ruth@example.org', 'password' => 'pw'], json_decode((string) $body, true));
    }

    public function test_gotrue_s_own_reason_comes_back_on_a_refusal(): void
    {
        $this->fake(['POST ' . self::URL . '/auth/v1/token' => self::json(['error' => 'invalid_grant', 'error_description' => 'Invalid login credentials'], 400)]);
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Invalid login credentials');
        self::provider()->tokenGrant('password', ['email' => 'x@example.org', 'password' => 'no']);
    }

    public function test_the_identity_comes_from_a_verified_hs256_token(): void
    {
        $this->fake([]);
        $id = self::provider()->identityFromSession(self::session(self::token()));
        self::assertSame(['supabase|u-1', 'ruth@example.org', true, 'Ruth Moabite', 'https://example.org/r.png'], [$id->sub, $id->email, $id->emailVerified, $id->name, $id->picture]);
        self::assertSame([], $this->calls, 'no lookup when the token verifies here');
    }

    public function test_an_unconfirmed_address_is_not_verified(): void
    {
        $this->fake([]);
        $id = self::provider()->identityFromSession(self::session(self::token(), ['email_confirmed_at' => null]));
        self::assertFalse($id->emailVerified);
    }

    public function test_a_token_signed_with_another_secret_is_refused(): void
    {
        $this->fake([]);
        $forged = Jwt::signHs256(['iss' => self::URL . '/auth/v1', 'aud' => 'authenticated', 'sub' => 'u-1', 'exp' => time() + 60], 'another-secret');
        $this->expectException(JwtException::class);
        self::provider()->identityFromSession(self::session($forged));
    }

    public function test_another_project_s_token_is_refused(): void
    {
        $this->fake([]);
        $this->expectException(JwtException::class);
        self::provider()->identityFromSession(self::session(self::token(['iss' => 'https://other.supabase.co/auth/v1'])));
    }

    public function test_a_user_that_isn_t_the_token_s_subject_is_refused(): void
    {
        $this->fake([]);
        $this->expectException(JwtException::class);
        $this->expectExceptionMessage('don’t match');
        self::provider()->identityFromSession(self::session(self::token(), ['id' => 'u-2']));
    }

    public function test_without_the_secret_supabase_itself_vouches_for_the_token(): void
    {
        $token = self::token();
        $this->fake(['GET ' . self::URL . '/auth/v1/user' => self::json(self::session($token)['user'])]);
        $id = self::provider(['jwt_secret' => ''])->identityFromSession(['access_token' => $token]);
        self::assertSame('supabase|u-1', $id->sub);
        // /user was asked with the person's own token.
        self::assertSame('Bearer ' . $token, $this->calls[0][2]['Authorization']);
    }

    public function test_without_the_secret_a_token_supabase_doesn_t_know_is_refused(): void
    {
        $this->fake(['GET ' . self::URL . '/auth/v1/user' => self::json(['msg' => 'invalid JWT'], 401)]);
        $this->expectException(JwtException::class);
        self::provider(['jwt_secret' => ''])->identityFromSession(['access_token' => self::token()]);
    }

    public function test_an_asymmetric_token_is_checked_against_the_project_s_jwks(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $d = openssl_pkey_get_details($key);
        openssl_pkey_export($key, $pem);
        $jwk = ['kty' => 'EC', 'crv' => 'P-256', 'kid' => 'sb1', 'use' => 'sig', 'x' => Jwt::b64e($d['ec']['x']), 'y' => Jwt::b64e($d['ec']['y'])];
        $this->fake(['GET ' . self::URL . '/auth/v1/.well-known/jwks.json' => self::json(['keys' => [$jwk]])]);
        $token = Jwt::signEs256(['kid' => 'sb1'], ['iss' => self::URL . '/auth/v1', 'aud' => 'authenticated', 'sub' => 'u-1', 'exp' => time() + 60], $pem);
        $claims = self::provider(['jwt_secret' => ''])->verifyAccessToken($token);
        self::assertSame('u-1', $claims['sub']);
    }

    public function test_the_social_list_keeps_only_plain_provider_names(): void
    {
        $p = self::provider(['social' => ' Google, github ,, a b, x, azure?<script>']);
        self::assertSame(['google', 'github'], $p->socialProviders());
        self::assertSame(['password' => true, 'magicLink' => false, 'social' => ['google', 'github']], $p->forms());
    }

    public function test_the_test_needs_https_and_each_social_provider_enabled(): void
    {
        $https = new TestContext('admin@example.org', true, 'https://church.example.org');
        self::assertFalse(self::provider()->test(new TestContext('admin@example.org', false, 'http://church.example.org'))->ok);

        $this->fake(['GET ' . self::URL . '/auth/v1/settings' => self::json(['external' => ['google' => true, 'github' => false, 'email' => true]])]);
        self::assertTrue(self::provider(['social' => 'google'])->test($https)->ok);
        $r = self::provider(['social' => 'google, github'])->test($https);
        self::assertFalse($r->ok);
        self::assertStringContainsString('github', $r->message);
    }

    public function test_the_test_reports_a_wrong_anon_key_and_a_refused_service_key(): void
    {
        $https = new TestContext('admin@example.org', true, 'https://church.example.org');
        $this->fake(['GET ' . self::URL . '/auth/v1/settings' => self::json(['message' => 'Invalid API key'], 401)]);
        self::assertStringContainsString('401', self::provider()->test($https)->message);

        $this->fake([
            'GET ' . self::URL . '/auth/v1/settings' => self::json(['external' => []]),
            'GET ' . self::URL . '/auth/v1/admin/users' => self::json(['msg' => 'bad'], 403),
        ]);
        $r = self::provider(['service_role_key' => 'srv'])->test($https);
        self::assertFalse($r->ok);
        self::assertStringContainsString('service-role', $r->message);
    }
}
