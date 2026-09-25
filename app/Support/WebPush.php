<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Web Push without a library: VAPID (RFC 8292) and the aes128gcm payload
 * encryption of RFC 8291, from OpenSSL's P-256 key agreement, hash_hkdf and
 * AES-128-GCM. Keys are kept the way `web-push generate-vapid-keys` prints
 * them — base64url, the public key as the 65-byte uncompressed point, the
 * private key as the 32-byte scalar — so a pair from the old site carries
 * over unchanged.
 */
final class WebPush
{
    public const RECORD_SIZE = 4096;
    public const TTL = 86400;

    /** @return array{publicKey: string, privateKey: string} */
    public static function generateKeys(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($key === false) {
            throw new \RuntimeException('OpenSSL could not make a P-256 key.');
        }
        $d = openssl_pkey_get_details($key);
        return [
            'publicKey' => Jwt::b64e(self::point($d['ec']['x'], $d['ec']['y'])),
            'privateKey' => Jwt::b64e(str_pad($d['ec']['d'], 32, "\0", STR_PAD_LEFT)),
        ];
    }

    /** Whether a pair belongs together and can sign. */
    public static function validKeys(string $publicKey, string $privateKey): bool
    {
        try {
            $pem = self::privatePem($publicKey, $privateKey);
            $token = Jwt::signEs256([], ['aud' => 'https://push.example', 'exp' => time() + 60], $pem);
            $keys = ['keys' => [self::publicJwk($publicKey)]];
            Jwt::verify($token, $keys, ['ES256']);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The request for one subscription: where, which headers, what body.
     *
     * @param array{endpoint: string, p256dh: string, auth: string} $subscription
     * @param array{publicKey: string, privateKey: string, subject: string} $vapid
     * @return array{url: string, headers: array<string, string>, body: string}
     */
    public static function request(array $subscription, string $payload, array $vapid, int $ttl = self::TTL): array
    {
        $parts = parse_url($subscription['endpoint']);
        $audience = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $token = Jwt::signEs256([], ['aud' => $audience, 'exp' => time() + 12 * 3600, 'sub' => $vapid['subject']], self::privatePem($vapid['publicKey'], $vapid['privateKey']));
        return [
            'url' => $subscription['endpoint'],
            'headers' => [
                'Authorization' => 'vapid t=' . $token . ', k=' . $vapid['publicKey'],
                'Content-Encoding' => 'aes128gcm',
                'Content-Type' => 'application/octet-stream',
                'TTL' => (string) $ttl,
                'Urgency' => 'normal',
            ],
            'body' => self::encrypt($payload, Jwt::b64d($subscription['p256dh']), Jwt::b64d($subscription['auth'])),
        ];
    }

    /**
     * RFC 8291: one aes128gcm record for the user agent's key and secret.
     *
     * @param ?string $salt and $senderPem for tests only: fixed inputs give fixed output
     */
    public static function encrypt(string $payload, string $uaPublic, string $authSecret, ?string $salt = null, ?\OpenSSLAsymmetricKey $sender = null): string
    {
        if (strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04" || strlen($authSecret) !== 16) {
            throw new \InvalidArgumentException('Not a push subscription key.');
        }
        if (strlen($payload) > self::RECORD_SIZE - 17 - 86) {
            throw new \InvalidArgumentException('A push message must be under 4 KB.');
        }
        $sender ??= openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($sender === false) {
            throw new \RuntimeException('OpenSSL could not make a P-256 key.');
        }
        $d = openssl_pkey_get_details($sender);
        $asPublic = self::point($d['ec']['x'], $d['ec']['y']);
        $secret = self::ecdh($sender, $uaPublic);
        $salt ??= random_bytes(16);
        $ikm = hash_hkdf('sha256', $secret, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $tag = '';
        $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false) {
            throw new \RuntimeException('The push message could not be encrypted.');
        }
        return $salt . pack('N', self::RECORD_SIZE) . chr(65) . $asPublic . $cipher . $tag;
    }

    /** The shared secret between our key and theirs. */
    public static function ecdh(\OpenSSLAsymmetricKey $private, string $peerPoint): string
    {
        $peer = openssl_pkey_get_public((string) Jwt::jwkToPem(self::pointJwk($peerPoint)));
        $secret = $peer === false ? false : openssl_pkey_derive($peer, $private, 32);
        if ($secret === false) {
            throw new \RuntimeException('The push key agreement failed.');
        }
        return $secret;
    }

    private static function point(string $x, string $y): string
    {
        return "\x04" . str_pad($x, 32, "\0", STR_PAD_LEFT) . str_pad($y, 32, "\0", STR_PAD_LEFT);
    }

    /** @return array<string, string> */
    private static function pointJwk(string $point): array
    {
        if (strlen($point) !== 65 || $point[0] !== "\x04") {
            throw new \InvalidArgumentException('Not an uncompressed P-256 point.');
        }
        return ['kty' => 'EC', 'crv' => 'P-256', 'x' => Jwt::b64e(substr($point, 1, 32)), 'y' => Jwt::b64e(substr($point, 33, 32))];
    }

    /** @return array<string, string> */
    public static function publicJwk(string $publicKey): array
    {
        return self::pointJwk(Jwt::b64d($publicKey)) + ['use' => 'sig'];
    }

    /** SEC 1 ECPrivateKey DER for the scalar and its point, as PEM. */
    public static function privatePem(string $publicKey, string $privateKey): string
    {
        $d = Jwt::b64d($privateKey);
        $pub = Jwt::b64d($publicKey);
        if (strlen($d) !== 32 || strlen($pub) !== 65) {
            throw new \InvalidArgumentException('The VAPID keys are not the right length.');
        }
        $oid = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
        $body = "\x02\x01\x01" . "\x04\x20" . $d . "\xa0" . chr(strlen($oid)) . $oid . "\xa1\x44\x03\x42\x00" . $pub;
        $der = "\x30" . chr(strlen($body)) . $body;
        return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
    }
}
