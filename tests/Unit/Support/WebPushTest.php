<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Jwt;
use App\Support\WebPush;
use PHPUnit\Framework\TestCase;

/**
 * Web Push against the other side of it: a "browser" key pair decrypts
 * what WebPush::encrypt sends (RFC 8291 worked backwards), and the VAPID
 * token verifies with the public key the Authorization header names.
 */
final class WebPushTest extends TestCase
{
    /** The browser's side: its key pair and auth secret. @return array{0: \OpenSSLAsymmetricKey, 1: string, 2: string} */
    private static function browser(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $d = openssl_pkey_get_details($key);
        $point = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
        return [$key, $point, random_bytes(16)];
    }

    /** What a browser does with a push body. */
    private static function decrypt(string $body, \OpenSSLAsymmetricKey $uaKey, string $uaPublic, string $auth): string
    {
        $salt = substr($body, 0, 16);
        $rs = unpack('N', substr($body, 16, 4))[1];
        $idlen = ord($body[20]);
        $asPublic = substr($body, 21, $idlen);
        $record = substr($body, 21 + $idlen);
        self::assertSame(4096, $rs);
        $secret = WebPush::ecdh($uaKey, $asPublic);
        $ikm = hash_hkdf('sha256', $secret, 32, "WebPush: info\0" . $uaPublic . $asPublic, $auth);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $plain = openssl_decrypt(substr($record, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($record, -16));
        self::assertIsString($plain);
        self::assertSame("\x02", substr($plain, -1), 'the last-record delimiter');
        return substr($plain, 0, -1);
    }

    public function test_a_browser_decrypts_what_is_sent(): void
    {
        [$key, $point, $auth] = self::browser();
        $payload = (string) json_encode(['title' => 'New sermon', 'body' => 'Romans 8 — “no condemnation”', 'url' => '/videos/romans-8']);
        self::assertSame($payload, self::decrypt(WebPush::encrypt($payload, $point, $auth), $key, $point, $auth));
    }

    public function test_the_request_carries_a_vapid_token_the_public_key_verifies(): void
    {
        $vapid = WebPush::generateKeys() + ['subject' => 'mailto:admin@example.org'];
        self::assertTrue(WebPush::validKeys($vapid['publicKey'], $vapid['privateKey']));
        [$key, $point, $auth] = self::browser();
        $r = WebPush::request(['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc', 'p256dh' => Jwt::b64e($point), 'auth' => Jwt::b64e($auth)], '{"title":"x"}', $vapid);
        self::assertSame('aes128gcm', $r['headers']['Content-Encoding']);
        self::assertMatchesRegularExpression('/^vapid t=([^,]+), k=(.+)$/', $r['headers']['Authorization']);
        preg_match('/^vapid t=([^,]+), k=(.+)$/', $r['headers']['Authorization'], $m);
        self::assertSame($vapid['publicKey'], $m[2]);
        $claims = Jwt::verify($m[1], ['keys' => [WebPush::publicJwk($m[2])]], ['ES256']);
        self::assertSame('https://fcm.googleapis.com', $claims['aud']);
        self::assertSame('mailto:admin@example.org', $claims['sub']);
        self::assertGreaterThan(time(), $claims['exp']);
        self::assertSame('{"title":"x"}', self::decrypt($r['body'], $key, $point, $auth));
    }

    public function test_a_pair_that_doesnt_belong_together_is_refused(): void
    {
        $a = WebPush::generateKeys();
        $b = WebPush::generateKeys();
        self::assertFalse(WebPush::validKeys($a['publicKey'], $b['privateKey']));
        self::assertFalse(WebPush::validKeys('short', $a['privateKey']));
    }

    public function test_keys_from_the_web_push_tool_are_taken_as_they_are(): void
    {
        // A pair as `npx web-push generate-vapid-keys` prints it (RFC 8292 test vector key).
        $public = 'BA1Hxzyi1RUM1b5wjxsn7nGxAszw2u61m164i3MrAIxHF6YK5h4SDYic-dRuU_RCPCfA5aq9ojSwk5Y2EmClBPs';
        $private = 'I8V5XbbbEUwXNvPqYd5tOK5drzzBEc84yGSOtdYhaH8';
        // Whether or not it signs depends only on the pair being a pair; it must parse.
        self::assertStringContainsString('BEGIN EC PRIVATE KEY', WebPush::privatePem($public, $private));
    }
}
