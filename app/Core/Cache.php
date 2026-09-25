<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Two caches, and correctness never depends on either.
 *
 *   memo()    per-request memoisation — the port of React's cache(): the
 *             current user, plugin states, branding, read once per request
 *             however many callers ask.
 *   get/set   an optional file cache under storage/cache for the handful of
 *             site-wide reads every page does, invalidated by the writes that
 *             change them. A missing or unwritable directory is a miss.
 */
final class Cache
{
    /** @var array<string, mixed> */
    private static array $memo = [];

    private static ?string $dir = null;

    public static function configure(?string $dir): void
    {
        self::$dir = $dir === null ? null : rtrim($dir, '/');
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function memo(string $key, callable $fn): mixed
    {
        if (!array_key_exists($key, self::$memo)) {
            self::$memo[$key] = $fn();
        }
        return self::$memo[$key];
    }

    public static function forgetMemo(?string $prefix = null): void
    {
        if ($prefix === null) {
            self::$memo = [];
            return;
        }
        foreach (array_keys(self::$memo) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset(self::$memo[$key]);
            }
        }
    }

    public static function get(string $key): mixed
    {
        $file = self::path($key);
        if ($file === null || !is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $entry = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($entry) || !isset($entry['e'])) {
            return null;
        }
        if ($entry['e'] !== 0 && $entry['e'] < time()) {
            @unlink($file);
            return null;
        }
        return $entry['v'];
    }

    public static function set(string $key, mixed $value, int $ttl = 3600): void
    {
        $file = self::path($key);
        if ($file === null) {
            return;
        }
        if (!is_dir(dirname($file)) && !@mkdir(dirname($file), 0775, true)) {
            return;
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, serialize(['e' => $ttl > 0 ? time() + $ttl : 0, 'v' => $value])) !== false) {
            @rename($tmp, $file);
        }
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function remember(string $key, int $ttl, callable $fn): mixed
    {
        return self::memo("file:$key", function () use ($key, $ttl, $fn) {
            $hit = self::get($key);
            if ($hit !== null) {
                return $hit;
            }
            $value = $fn();
            if ($value !== null) {
                self::set($key, $value, $ttl);
            }
            return $value;
        });
    }

    public static function forget(string $key): void
    {
        unset(self::$memo["file:$key"]);
        $file = self::path($key);
        if ($file !== null && is_file($file)) {
            @unlink($file);
        }
    }

    /** Counts events in a rolling window, for the plugin failure breaker. */
    public static function hit(string $key, int $window): int
    {
        $now = time();
        $hits = self::get($key);
        $hits = is_array($hits) ? array_values(array_filter($hits, fn ($t) => is_int($t) && $t > $now - $window)) : [];
        $hits[] = $now;
        self::set($key, $hits, $window);
        return count($hits);
    }

    public static function clear(): void
    {
        self::$memo = [];
        if (self::$dir === null || !is_dir(self::$dir)) {
            return;
        }
        foreach (glob(self::$dir . '/*.cache') ?: [] as $file) {
            @unlink($file);
        }
    }

    private static function path(string $key): ?string
    {
        return self::$dir === null ? null : self::$dir . '/' . hash('sha256', $key) . '.cache';
    }
}
