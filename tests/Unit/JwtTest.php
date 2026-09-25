<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Jwt;
use App\Support\JwtException;
use PHPUnit\Framework\TestCase;

final class JwtTest extends TestCase
{
    /** @return array{0: \OpenSSLAsymmetricKey, 1: array<string, string>} a key and its JWK */
    private static function rsa(string $kid): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $d = openssl_pkey_get_details($key);
        return [$key, ['kty' => 'RSA', 'kid' => $kid, 'use' => 'sig', 'alg' => 'RS256', 'n' => Jwt::b64e($d['rsa']['n']), 'e' => Jwt::b64e($d['rsa']['e'])]];
    }

    /** @return array{0: \OpenSSLAsymmetricKey, 1: array<string, string>} */
    private static function ec(string $kid): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $d = openssl_pkey_get_details($key);
        return [$key, ['kty' => 'EC', 'kid' => $kid, 'use' => 'sig', 'crv' => 'P-256', 'x' => Jwt::b64e($d['ec']['x']), 'y' => Jwt::b64e($d['ec']['y'])]];
    }

    private static function rs256(array $payload, \OpenSSLAsymmetricKey $key, string $kid, string $alg = 'RS256'): string
    {
        $signed = Jwt::b64e((string) json_encode(['alg' => $alg, 'kid' => $kid])) . '.' . Jwt::b64e((string) json_encode($payload));
        openssl_sign($signed, $sig, $key, OPENSSL_ALGO_SHA256);
        return $signed . '.' . Jwt::b64e($sig);
    }

    private static function claims(array $extra = []): array
    {
        return $extra + ['iss' => 'https://idp.example', 'aud' => 'client-1', 'sub' => 'u1', 'exp' => time() + 300, 'iat' => time(), 'nonce' => 'n-1'];
    }

    public function test_rs256_against_a_jwks_picks_the_key_by_kid(): void
    {
        [$k1, $j1] = self::rsa('one');
        [, $j2] = self::rsa('two');
        $payload = Jwt::verify(self::rs256(self::claims(), $k1, 'one'), ['keys' => [$j2, $j1]]);
        self::assertSame('u1', $payload['sub']);
        Jwt::checkClaims($payload, 'https://idp.example', 'client-1', 'n-1');
        $this->addToAssertionCount(1);
    }

    public function test_es256_from_a_jwk_and_back(): void
    {
        [$key, $jwk] = self::ec('ec1');
        $pem = '';
        openssl_pkey_export($key, $pem);
        $token = Jwt::signEs256(['kid' => 'ec1'], self::claims(), $pem);
        self::assertSame(64, strlen(Jwt::b64d(explode('.', $token)[2])));
        self::assertSame('u1', Jwt::verify($token, ['keys' => [$jwk]])['sub']);
    }

    public function test_a_tampered_payload_fails(): void
    {
        [$key, $jwk] = self::rsa('one');
        [$h, , $s] = explode('.', self::rs256(self::claims(), $key, 'one'));
        $this->expectException(JwtException::class);
        Jwt::verify($h . '.' . Jwt::b64e((string) json_encode(self::claims(['sub' => 'admin']))) . '.' . $s, ['keys' => [$jwk]]);
    }

    public function test_an_unknown_kid_says_so_so_the_keys_can_be_refetched(): void
    {
        [$key] = self::rsa('rotated');
        [, $old] = self::rsa('old');
        try {
            Jwt::verify(self::rs256(self::claims(), $key, 'rotated'), ['keys' => [$old]]);
            self::fail('accepted');
        } catch (JwtException $e) {
            self::assertTrue($e->unknownKey);
        }
    }

    public function test_none_and_unlisted_algorithms_are_refused(): void
    {
        $none = Jwt::b64e('{"alg":"none"}') . '.' . Jwt::b64e((string) json_encode(self::claims())) . '.';
        foreach ([$none, Jwt::signHs256(self::claims(), 'secret')] as $token) {
            try {
                Jwt::verify($token, ['keys' => []]);
                self::fail('accepted ' . $token);
            } catch (JwtException $e) {
                self::assertStringContainsString('isn’t accepted', $e->getMessage());
            }
        }
    }

    public function test_hs256_with_the_shared_secret(): void
    {
        $token = Jwt::signHs256(self::claims(), 'project-secret');
        self::assertSame('u1', Jwt::verify($token, [], ['HS256'], 'project-secret')['sub']);
        $this->expectException(JwtException::class);
        Jwt::verify($token, [], ['HS256'], 'wrong');
    }

    public function test_pem_certificates_keyed_by_kid(): void
    {
        [$key] = self::rsa('x');
        $csr = openssl_csr_new(['commonName' => 'securetoken'], $key);
        openssl_x509_export(openssl_csr_sign($csr, null, $key, 1), $cert);
        self::assertSame('u1', Jwt::verify(self::rs256(self::claims(), $key, 'cert1'), ['cert1' => $cert], ['RS256'])['sub']);
    }

    public function test_claim_checks(): void
    {
        $bad = [
            'expired' => [self::claims(['exp' => time() - 3600]), 'expired'],
            'issuer' => [self::claims(['iss' => 'https://evil.example']), 'somebody else'],
            'audience' => [self::claims(['aud' => ['other']]), 'different application'],
            'nonce' => [self::claims(['nonce' => 'n-2']), 'doesn’t belong'],
            'future' => [self::claims(['nbf' => time() + 3600]), 'isn’t valid yet'],
        ];
        foreach ($bad as $case => [$claims, $words]) {
            try {
                Jwt::checkClaims($claims, 'https://idp.example', 'client-1', 'n-1');
                self::fail("$case accepted");
            } catch (JwtException $e) {
                self::assertStringContainsString($words, $e->getMessage(), $case);
            }
        }
        Jwt::checkClaims(self::claims(['aud' => ['x', 'client-1'], 'exp' => time() - 30]), ['https://a', 'https://idp.example'], 'client-1');
        $this->addToAssertionCount(1);
    }
}
