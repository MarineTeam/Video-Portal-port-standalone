<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Cache;
use App\Core\Http;
use App\Core\HttpException;

/**
 * A provider's published signing keys, kept in the file cache. A token
 * naming a key we haven't seen triggers one fresh fetch (a rotation), at
 * most once a minute per key set, so a stream of junk tokens can't turn
 * into a stream of requests to the provider.
 */
final class Jwks
{
    public const TTL = 6 * 3600;

    /** @return array<string, mixed> */
    public static function get(string $url, bool $fresh = false): array
    {
        $key = 'jwks:' . hash('sha256', $url);
        if (!$fresh) {
            $cached = Cache::get($key);
            if (is_array($cached)) {
                return $cached;
            }
        }
        $r = Http::request('GET', $url, ['Accept' => 'application/json'], null, ['timeout' => 10, 'maxBytes' => 1_000_000]);
        $keys = $r->json();
        if (!$r->ok() || !is_array($keys)) {
            throw new HttpException('The provider’s signing keys couldn’t be fetched (' . $r->status . ').');
        }
        // Google says how long its certificates live; everybody else gets six hours.
        $ttl = preg_match('/max-age=(\d+)/', (string) $r->header('cache-control'), $m) ? max(300, min((int) $m[1], 86400)) : self::TTL;
        Cache::set($key, $keys, $ttl);
        return $keys;
    }

    /**
     * Verifies a token against the key set at $url, refetching once if it
     * names a key that isn't there yet.
     *
     * @param list<string> $algorithms
     * @return array<string, mixed> the payload
     */
    public static function verify(string $jwt, string $url, array $algorithms = ['RS256', 'ES256']): array
    {
        try {
            return Jwt::verify($jwt, self::get($url), $algorithms);
        } catch (JwtException $e) {
            if (!$e->unknownKey || Cache::hit('jwks-refresh:' . hash('sha256', $url), 60) > 1) {
                throw $e;
            }
            return Jwt::verify($jwt, self::get($url, fresh: true), $algorithms);
        }
    }
}
