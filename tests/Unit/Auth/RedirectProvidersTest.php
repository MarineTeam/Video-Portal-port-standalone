<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Core\Http;
use App\Core\HttpResponse;
use App\Services\Auth\Auth0Provider;
use App\Services\Auth\OidcProvider;
use App\Services\Auth\RedirectProvider;
use App\Support\Jwt;
use PHPUnit\Framework\TestCase;

final class RedirectProvidersTest extends TestCase
{
    protected function setUp(): void
    {
        \App\Core\Cache::configure(null);
        \App\Core\Url::configure('https://church.example.org');
    }

    protected function tearDown(): void
    {
        Http::fake(null);
    }

    public function test_auth0_sends_organization_only_when_one_is_required(): void
    {
        self::assertSame('org_1', Auth0Provider::organizationParam('BOTH', ['org_1'], false));
        self::assertSame('org_1', Auth0Provider::organizationParam('ORGANIZATION', ['org_1'], false));
        self::assertNull(Auth0Provider::organizationParam('BOTH', ['org_1'], true), 'never for the guest link');
        self::assertNull(Auth0Provider::organizationParam('ALLOWLIST', ['org_1'], false));
        self::assertNull(Auth0Provider::organizationParam('EITHER', ['org_1'], false));
        self::assertNull(Auth0Provider::organizationParam('BOTH', ['org_1', 'org_2'], false), 'several: Auth0 shows its picker');
        self::assertNull(Auth0Provider::organizationParam('BOTH', [], false));
    }

    public function test_auth0_keeps_subs_verbatim_and_takes_org_id_as_membership(): void
    {
        $id = (new Auth0Provider(['domain' => 'x.auth0.com']))->identityFrom(['sub' => 'google-oauth2|123', 'email' => 'a@b.org', 'email_verified' => true, 'org_id' => 'org_1'], [], ['org_1']);
        self::assertSame(['google-oauth2|123', 'auth0', 'org_1', true], [$id->sub, $id->provider, $id->membership, $id->emailVerified]);
    }

    private static function discovery(): void
    {
        Http::fake(fn (string $m, string $url) => new HttpResponse(200, ['content-type' => 'application/json'], (string) json_encode([
            'issuer' => 'https://idp.example', 'authorization_endpoint' => 'https://idp.example/authorize', 'token_endpoint' => 'https://idp.example/token', 'jwks_uri' => 'https://idp.example/jwks',
        ])));
    }

    public function test_the_authorization_request_carries_state_nonce_and_an_s256_challenge(): void
    {
        self::discovery();
        $p = new OidcProvider(['preset' => 'custom', 'issuer' => 'https://idp.example', 'client_id' => 'c1']);
        $url = $p->authorizeUrl('state-1', 'nonce-1', 'verifier-1', ['mode' => 'BOTH', 'organizationIds' => [], 'guest' => false]);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        self::assertStringStartsWith('https://idp.example/authorize?', $url);
        self::assertSame(['code', 'c1', 'https://church.example.org/auth/callback', 'state-1', 'nonce-1', 'S256'], [$q['response_type'], $q['client_id'], $q['redirect_uri'], $q['state'], $q['nonce'], $q['code_challenge_method']]);
        self::assertSame(RedirectProvider::pkceChallenge('verifier-1'), $q['code_challenge']);
        self::assertArrayNotHasKey('response_mode', $q);
    }

    public function test_presets_fill_the_issuer_membership_and_sub_prefix(): void
    {
        $google = new OidcProvider(['preset' => 'google', 'client_id' => 'c']);
        self::assertSame('https://accounts.google.com/.well-known/openid-configuration', $google->discoveryUrl());
        self::assertSame('hd', $google->membershipClaim());
        $id = $google->identityFrom(['sub' => '42', 'email' => 'a@church.org', 'email_verified' => true, 'hd' => 'church.org'], [], ['church.org']);
        self::assertSame(['google|42', 'church.org', true], [$id->sub, $id->membership, $id->membershipApplicable]);
        $personal = $google->identityFrom(['sub' => '43', 'email' => 'a@gmail.com'], [], []);
        self::assertFalse($personal->emailVerified, 'no email_verified claim means unverified');
        self::assertNull($personal->membership);
        $custom = (new OidcProvider(['preset' => 'custom', 'issuer' => 'https://idp.example/']))->identityFrom(['sub' => 's', 'email' => 'a@b.org', 'email_verified' => 'true'], [], []);
        self::assertSame(['oidc|s', false, true], [$custom->sub, $custom->membershipApplicable, $custom->emailVerified]);
    }

    public function test_a_groups_claim_yields_the_accepted_group(): void
    {
        $okta = new OidcProvider(['preset' => 'okta', 'issuer' => 'https://x.okta.com']);
        $id = $okta->identityFrom(['sub' => 's', 'email' => 'a@b.org', 'email_verified' => true, 'groups' => ['Everyone', 'Staff']], [], ['Staff']);
        self::assertSame('Staff', $id->membership);
    }

    public function test_apple_posts_back_signs_its_own_secret_and_names_on_first_sign_in(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $pem);
        $apple = new OidcProvider(['preset' => 'apple', 'client_id' => 'org.church.web', 'apple_team_id' => 'TEAM', 'apple_key_id' => 'KEY1', 'apple_private_key' => $pem]);
        self::assertSame('form_post', $apple->responseMode());
        $secret = Jwt::decode($apple->clientSecret());
        self::assertSame(['ES256', 'KEY1'], [$secret['header']['alg'], $secret['header']['kid']]);
        self::assertSame(['TEAM', 'org.church.web', 'https://appleid.apple.com'], [$secret['payload']['iss'], $secret['payload']['sub'], $secret['payload']['aud']]);
        $id = $apple->identityFrom(['sub' => '001', 'email' => 'x@privaterelay.appleid.com', 'email_verified' => 'true'], ['user' => '{"name":{"firstName":"Ruth","lastName":"Moab"}}'], []);
        self::assertSame(['apple|001', 'Ruth Moab', true], [$id->sub, $id->name, $id->emailVerified]);
    }

    public function test_the_test_insists_on_https(): void
    {
        \App\Core\Url::configure('http://church.example.org');
        $result = (new OidcProvider(['preset' => 'google', 'client_id' => 'c']))->test(new \App\Services\TestContext('a@b.org', false, 'http://church.example.org', []));
        self::assertFalse($result->ok);
        self::assertStringContainsString('HTTPS', $result->message);
    }
}
