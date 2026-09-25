<?php

declare(strict_types=1);

namespace App\Support;

/**
 * JSON Web Tokens, as the sign-in providers need them: verifying RS256 and
 * ES256 signatures against a JWKS (or against PEM certificates, as Google
 * publishes Firebase's), HS256 against a shared secret, the standard claim
 * checks, and signing ES256 for Sign in with Apple's client secret. Only
 * those three algorithms are accepted, and never "none": the algorithm
 * comes from what the caller allows, not from what the token says.
 */
final class Jwt
{
    public const LEEWAY = 60;

    /**
     * @return array{header: array<string, mixed>, payload: array<string, mixed>, signed: string, signature: string}
     */
    public static function decode(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new JwtException('Not a JWT.');
        }
        $header = json_decode(self::b64d($parts[0]), true);
        $payload = json_decode(self::b64d($parts[1]), true);
        if (!is_array($header) || !is_array($payload)) {
            throw new JwtException('The token isn’t readable.');
        }
        return ['header' => $header, 'payload' => $payload, 'signed' => $parts[0] . '.' . $parts[1], 'signature' => self::b64d($parts[2])];
    }

    /**
     * Verifies the signature and returns the payload (claims unchecked).
     *
     * @param array<string, mixed> $keys a JWKS ({"keys": [...]}) or kid => PEM
     * @param list<string> $algorithms the ones this provider may use
     * @return array<string, mixed>
     */
    public static function verify(string $jwt, array $keys, array $algorithms = ['RS256', 'ES256'], ?string $hmacSecret = null): array
    {
        $t = self::decode($jwt);
        $alg = (string) ($t['header']['alg'] ?? '');
        if (!in_array($alg, $algorithms, true) || !in_array($alg, ['RS256', 'ES256', 'HS256'], true)) {
            throw new JwtException("The token is signed with $alg, which isn’t accepted here.");
        }
        if ($alg === 'HS256') {
            if ($hmacSecret === null || $hmacSecret === '') {
                throw new JwtException('No secret to check the token with.');
            }
            if (!hash_equals(hash_hmac('sha256', $t['signed'], $hmacSecret, true), $t['signature'])) {
                throw new JwtException('The token’s signature doesn’t match.');
            }
            return $t['payload'];
        }
        $kid = isset($t['header']['kid']) ? (string) $t['header']['kid'] : null;
        $pem = self::keyFor($keys, $kid, $alg);
        if ($pem === null) {
            throw new JwtException('No published key matches the token (kid ' . ($kid ?? 'none') . ').', unknownKey: true);
        }
        $signature = $alg === 'ES256' ? self::rawToDer($t['signature']) : $t['signature'];
        if (openssl_verify($t['signed'], $signature, $pem, OPENSSL_ALGO_SHA256) !== 1) {
            throw new JwtException('The token’s signature doesn’t match.');
        }
        return $t['payload'];
    }

    /**
     * The standard checks: expiry, not-before, issued-at, issuer, audience
     * and, when given, the nonce this browser was sent off with.
     *
     * @param array<string, mixed> $claims
     * @param list<string>|string $issuer
     * @param list<string>|string $audience
     */
    public static function checkClaims(array $claims, array|string $issuer, array|string $audience, ?string $nonce = null, ?int $now = null): void
    {
        $now ??= time();
        if (!isset($claims['exp']) || !is_numeric($claims['exp']) || (int) $claims['exp'] + self::LEEWAY < $now) {
            throw new JwtException('The token has expired.');
        }
        if (isset($claims['nbf']) && (int) $claims['nbf'] - self::LEEWAY > $now) {
            throw new JwtException('The token isn’t valid yet.');
        }
        if (isset($claims['iat']) && (int) $claims['iat'] - self::LEEWAY > $now) {
            throw new JwtException('The token was issued in the future.');
        }
        if (!in_array((string) ($claims['iss'] ?? ''), (array) $issuer, true)) {
            throw new JwtException('The token was issued by somebody else (' . (string) ($claims['iss'] ?? 'nobody') . ').');
        }
        $aud = (array) ($claims['aud'] ?? []);
        if (array_intersect(array_map('strval', $aud), (array) $audience) === []) {
            throw new JwtException('The token was meant for a different application.');
        }
        if ($nonce !== null && !hash_equals($nonce, (string) ($claims['nonce'] ?? ''))) {
            throw new JwtException('The sign-in response doesn’t belong to this browser.');
        }
    }

    /** @param array<string, mixed> $keys */
    private static function keyFor(array $keys, ?string $kid, string $alg): ?string
    {
        if (isset($keys['keys']) && is_array($keys['keys'])) {
            $candidates = [];
            foreach ($keys['keys'] as $jwk) {
                if (!is_array($jwk) || ($jwk['use'] ?? 'sig') !== 'sig') {
                    continue;
                }
                if ($kid !== null && ($jwk['kid'] ?? null) !== $kid) {
                    continue;
                }
                if (isset($jwk['alg']) && $jwk['alg'] !== $alg) {
                    continue;
                }
                $candidates[] = $jwk;
            }
            // Without a kid, only an unambiguous single key will do.
            if ($candidates === [] || ($kid === null && count($candidates) > 1)) {
                return null;
            }
            return self::jwkToPem($candidates[0]);
        }
        // kid => PEM certificate or key (Google's Firebase certificates).
        if ($kid === null || !isset($keys[$kid]) || !is_string($keys[$kid])) {
            return null;
        }
        $pem = $keys[$kid];
        if (str_contains($pem, 'BEGIN CERTIFICATE')) {
            $public = openssl_pkey_get_public($pem);
            $details = $public === false ? false : openssl_pkey_get_details($public);
            return $details === false ? null : (string) $details['key'];
        }
        return $pem;
    }

    /** @param array<string, mixed> $jwk */
    public static function jwkToPem(array $jwk): ?string
    {
        $kty = $jwk['kty'] ?? null;
        if ($kty === 'RSA' && isset($jwk['n'], $jwk['e'])) {
            $rsa = self::seq(self::int(self::b64d((string) $jwk['n'])) . self::int(self::b64d((string) $jwk['e'])));
            $algo = self::seq("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00");
            return self::pem(self::seq($algo . self::bitString($rsa)));
        }
        if ($kty === 'EC' && ($jwk['crv'] ?? null) === 'P-256' && isset($jwk['x'], $jwk['y'])) {
            $x = str_pad(self::b64d((string) $jwk['x']), 32, "\0", STR_PAD_LEFT);
            $y = str_pad(self::b64d((string) $jwk['y']), 32, "\0", STR_PAD_LEFT);
            // id-ecPublicKey, prime256v1
            $algo = self::seq("\x06\x07\x2a\x86\x48\xce\x3d\x02\x01" . "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07");
            return self::pem(self::seq($algo . self::bitString("\x04" . $x . $y)));
        }
        return null;
    }

    /**
     * An ES256 token (Sign in with Apple's client secret).
     *
     * @param array<string, mixed> $header
     * @param array<string, mixed> $payload
     */
    public static function signEs256(array $header, array $payload, string $privateKeyPem): string
    {
        $signed = self::b64e((string) json_encode(['alg' => 'ES256', 'typ' => 'JWT'] + $header, JSON_UNESCAPED_SLASHES)) . '.' . self::b64e((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false || !openssl_sign($signed, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new JwtException('The private key couldn’t sign.');
        }
        return $signed . '.' . self::b64e(self::derToRaw($der));
    }

    /**
     * HS256, for tests and for a provider that shares a secret.
     *
     * @param array<string, mixed> $payload
     */
    public static function signHs256(array $payload, string $secret): string
    {
        $signed = self::b64e('{"alg":"HS256","typ":"JWT"}') . '.' . self::b64e((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        return $signed . '.' . self::b64e(hash_hmac('sha256', $signed, $secret, true));
    }

    // Encoding ------------------------------------------------------------------------------------

    public static function b64e(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function b64d(string $data): string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4), true);
        if ($decoded === false) {
            throw new JwtException('The token isn’t readable.');
        }
        return $decoded;
    }

    private static function len(int $n): string
    {
        if ($n < 0x80) {
            return chr($n);
        }
        $bytes = ltrim(pack('N', $n), "\0");
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function seq(string $body): string
    {
        return "\x30" . self::len(strlen($body)) . $body;
    }

    private static function int(string $bytes): string
    {
        $bytes = ltrim($bytes, "\0");
        if ($bytes === '' || ord($bytes[0]) > 0x7f) {
            $bytes = "\0" . $bytes;
        }
        return "\x02" . self::len(strlen($bytes)) . $bytes;
    }

    private static function bitString(string $bytes): string
    {
        return "\x03" . self::len(strlen($bytes) + 1) . "\0" . $bytes;
    }

    private static function pem(string $der): string
    {
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /** JOSE's r||s to the DER SEQUENCE OpenSSL verifies. */
    private static function rawToDer(string $raw): string
    {
        if (strlen($raw) !== 64) {
            throw new JwtException('The token’s signature is the wrong length.');
        }
        return self::seq(self::int(substr($raw, 0, 32)) . self::int(substr($raw, 32)));
    }

    /** OpenSSL's DER ECDSA signature to JOSE's 64-byte r||s. */
    private static function derToRaw(string $der): string
    {
        $offset = 2 + (ord($der[1]) & 0x80 ? ord($der[1]) & 0x7f : 0);
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$offset + 1]);
            $out .= str_pad(ltrim(substr($der, $offset + 2, $len), "\0"), 32, "\0", STR_PAD_LEFT);
            $offset += 2 + $len;
        }
        return $out;
    }
}
