<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Encryption and signing under the install's app_key, which lives in
 * storage/config.php and never in the database.
 *
 * Provider secrets are stored as "v1:" + base64(nonce | tag | ciphertext),
 * AES-256-GCM with a fresh nonce per value, so a database dump — a backup, a
 * shared host's neighbour with a bad day — yields nothing usable.
 */
final class Crypto
{
    private static ?string $appKey = null;

    public static function configure(string $appKey): void
    {
        $raw = ctype_xdigit($appKey) && strlen($appKey) === 64 ? hex2bin($appKey) : $appKey;
        if ($raw === false || strlen($raw) < 32) {
            throw new \InvalidArgumentException('app_key must be at least 32 bytes.');
        }
        self::$appKey = $raw;
    }

    public static function generateAppKey(): string
    {
        return bin2hex(random_bytes(32));
    }

    private static function key(string $purpose): string
    {
        if (self::$appKey === null) {
            throw new \LogicException('Crypto is not configured.');
        }
        return hash_hkdf('sha256', self::$appKey, 32, "marine-team:$purpose");
    }

    public static function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plaintext, 'aes-256-gcm', self::key('secrets'), OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($ct === false) {
            throw new \RuntimeException('Encryption failed.');
        }
        return 'v1:' . base64_encode($nonce . $tag . $ct);
    }

    public static function decrypt(string $stored): ?string
    {
        if (!str_starts_with($stored, 'v1:')) {
            return null;
        }
        $raw = base64_decode(substr($stored, 3), true);
        if ($raw === false || strlen($raw) < 28) {
            return null;
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key('secrets'), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? null : $plain;
    }

    public static function hmac(string $purpose, string $data): string
    {
        return hash_hmac('sha256', $data, self::key($purpose));
    }

    /**
     * A short-lived signed value: the Services screen signs a passing test
     * result this way, so a stale pass can't be replayed after a field changes.
     */
    public static function sign(string $purpose, array $payload, int $ttl): string
    {
        $payload['exp'] = time() + $ttl;
        $body = rtrim(strtr(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        return $body . '.' . self::hmac($purpose, $body);
    }

    /** @return array<string, mixed>|null */
    public static function verify(string $purpose, string $token): ?array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2 || !hash_equals(self::hmac($purpose, $parts[0]), $parts[1])) {
            return null;
        }
        $payload = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true);
        if (!is_array($payload) || !isset($payload['exp']) || $payload['exp'] < time()) {
            return null;
        }
        return $payload;
    }
}
