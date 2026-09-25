<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Core\Http;
use App\Core\HttpResponse;
use App\Services\Auth\ClerkProvider;
use App\Services\Auth\FirebaseProvider;
use App\Support\Jwt;
use App\Support\JwtException;
use PHPUnit\Framework\TestCase;

final class TokenProvidersTest extends TestCase
{
    private static \OpenSSLAsymmetricKey $key;
    /** @var array<string, string> */
    private static array $jwk;

    public static function setUpBeforeClass(): void
    {
        self::$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $d = openssl_pkey_get_details(self::$key);
        self::$jwk = ['kty' => 'RSA', 'kid' => 'k1', 'use' => 'sig', 'n' => Jwt::b64e($d['rsa']['n']), 'e' => Jwt::b64e($d['rsa']['e'])];
    }

    protected function setUp(): void
    {
        \App\Core\Cache::configure(null);
    }

    protected function tearDown(): void
    {
        Http::fake(null);
    }

    private static function sign(array $claims): string
    {
        $signed = Jwt::b64e('{"alg":"RS256","kid":"k1"}') . '.' . Jwt::b64e((string) json_encode($claims));
        openssl_sign($signed, $sig, self::$key, OPENSSL_ALGO_SHA256);
        return $signed . '.' . Jwt::b64e($sig);
    }

    /** @param array<string, HttpResponse> $routes url prefix => answer */
    private function fake(array $routes): void
    {
        Http::fake(function (string $m, string $url) use ($routes): HttpResponse {
            foreach ($routes as $prefix => $answer) {
                if (str_starts_with($url, $prefix)) {
                    return $answer;
                }
            }
            return new HttpResponse(599, [], 'no fixture ' . $url);
        });
    }

    private static function json(mixed $d): HttpResponse
    {
        return new HttpResponse(200, ['content-type' => 'application/json'], (string) json_encode($d));
    }

    private static function clerkKey(): string
    {
        return 'pk_live_' . base64_encode('clerk.church.example.org$');
    }

    public function test_the_frontend_api_comes_out_of_the_publishable_key(): void
    {
        self::assertSame('clerk.church.example.org', ClerkProvider::frontendApi(self::clerkKey()));
        self::assertNull(ClerkProvider::frontendApi('sk_live_abc'));
        self::assertNull(ClerkProvider::frontendApi('pk_live_' . base64_encode('no-dollar')));
    }

    public function test_clerk_with_a_jwt_template_needs_no_lookup(): void
    {
        $this->fake(['https://clerk.church.example.org/.well-known/jwks.json' => self::json(['keys' => [self::$jwk]])]);
        $p = new ClerkProvider(['publishable_key' => self::clerkKey(), 'secret_key' => 'sk']);
        $id = $p->identityFromToken(self::sign(['iss' => 'https://clerk.church.example.org', 'sub' => 'user_1', 'exp' => time() + 60, 'azp' => 'https://church.example.org', 'email' => 'ruth@example.org', 'email_verified' => 'true', 'org_id' => 'org_9']), []);
        self::assertSame(['clerk|user_1', 'ruth@example.org', true, 'org_9'], [$id->sub, $id->email, $id->emailVerified, $id->membership]);
    }

    public function test_clerk_looks_the_address_up_when_the_token_has_none(): void
    {
        $this->fake([
            'https://clerk.church.example.org/.well-known/jwks.json' => self::json(['keys' => [self::$jwk]]),
            'https://api.clerk.com/v1/users/user_2' => self::json(['primary_email_address_id' => 'e2', 'first_name' => 'Boaz', 'last_name' => '', 'email_addresses' => [
                ['id' => 'e1', 'email_address' => 'old@example.org', 'verification' => ['status' => 'verified']],
                ['id' => 'e2', 'email_address' => 'boaz@example.org', 'verification' => ['status' => 'unverified']],
            ]]),
        ]);
        $id = (new ClerkProvider(['publishable_key' => self::clerkKey(), 'secret_key' => 'sk']))->identityFromToken(self::sign(['iss' => 'https://clerk.church.example.org', 'sub' => 'user_2', 'exp' => time() + 60]), []);
        self::assertSame(['boaz@example.org', false, 'Boaz'], [$id->email, $id->emailVerified, $id->name]);
    }

    public function test_clerk_refuses_another_sites_token_and_another_issuer(): void
    {
        $this->fake(['https://clerk.church.example.org/.well-known/jwks.json' => self::json(['keys' => [self::$jwk]])]);
        $p = new ClerkProvider(['publishable_key' => self::clerkKey(), 'secret_key' => 'sk']);
        foreach ([
            'azp' => ['iss' => 'https://clerk.church.example.org', 'sub' => 'user_1', 'exp' => time() + 60, 'azp' => 'https://evil.example', 'email' => 'a@b.org'],
            'iss' => ['iss' => 'https://clerk.other.example', 'sub' => 'user_1', 'exp' => time() + 60, 'email' => 'a@b.org'],
            'exp' => ['iss' => 'https://clerk.church.example.org', 'sub' => 'user_1', 'exp' => time() - 600, 'email' => 'a@b.org'],
        ] as $case => $claims) {
            try {
                $p->identityFromToken(self::sign($claims), []);
                self::fail("$case accepted");
            } catch (JwtException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_firebase_checks_googles_certificate_the_project_and_the_issuer(): void
    {
        $csr = openssl_csr_new(['commonName' => 'securetoken'], self::$key);
        openssl_x509_export(openssl_csr_sign($csr, null, self::$key, 1), $cert);
        $this->fake([FirebaseProvider::CERTS => new HttpResponse(200, ['content-type' => 'application/json', 'cache-control' => 'public, max-age=20000'], (string) json_encode(['k1' => $cert]))]);
        $p = new FirebaseProvider(['api_key' => 'AIza', 'project_id' => 'grace-church']);
        $good = ['iss' => 'https://securetoken.google.com/grace-church', 'aud' => 'grace-church', 'sub' => 'fb1', 'exp' => time() + 60, 'iat' => time(), 'auth_time' => time() - 10, 'email' => 'ruth@example.org', 'email_verified' => true];
        $id = $p->identityFromToken(self::sign($good), []);
        self::assertSame(['firebase|fb1', true, false], [$id->sub, $id->emailVerified, $id->membershipApplicable]);
        $this->expectException(JwtException::class);
        $p->identityFromToken(self::sign(['aud' => 'another-project', 'iss' => 'https://securetoken.google.com/another-project'] + $good), []);
    }

    public function test_the_widgets_name_their_scripts_and_hosts(): void
    {
        $clerk = (new ClerkProvider(['publishable_key' => self::clerkKey()]))->widget();
        self::assertSame('https://clerk.church.example.org/npm/@clerk/clerk-js@5/dist/clerk.browser.js', $clerk['scripts'][0]);
        self::assertContains('https://clerk.church.example.org', $clerk['csp']['script']);
        $fb = (new FirebaseProvider(['api_key' => 'AIza', 'project_id' => 'grace-church']))->widget();
        self::assertCount(2, $fb['scripts']);
        self::assertSame('grace-church.firebaseapp.com', $fb['config']['firebase']['authDomain']);
        self::assertArrayNotHasKey('secret_key', $clerk['config']);
    }
}
