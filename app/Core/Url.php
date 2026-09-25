<?php

declare(strict_types=1);

namespace App\Core;

/**
 * The one place a URL is built. Everything is relative to the configured base
 * URL, so a site installed at example.org/church/ never emits a link to /.
 */
final class Url
{
    private static string $baseUrl = 'http://localhost';
    private static string $basePath = '';

    public static function configure(string $baseUrl): void
    {
        $baseUrl = rtrim($baseUrl, '/');
        self::$baseUrl = $baseUrl;
        self::$basePath = rtrim((string) parse_url($baseUrl, PHP_URL_PATH), '/');
    }

    public static function basePath(): string
    {
        return self::$basePath;
    }

    public static function baseUrl(): string
    {
        return self::$baseUrl;
    }

    /** A root-relative URL for an app path: /series/x → /church/series/x. */
    public static function to(string $path, array $query = []): string
    {
        $url = self::$basePath . '/' . ltrim($path, '/');
        return $query === [] ? $url : $url . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /** An absolute URL, for feeds, emails, redirect URIs and Open Graph. */
    /** scheme://host[:port] of the site, for an Origin header or a CORS rule. */
    public static function origin(): string
    {
        $p = parse_url(self::baseUrl());
        if (!is_array($p) || !isset($p['host'])) {
            return '';
        }
        return ($p['scheme'] ?? 'https') . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    }

    public static function absolute(string $path, array $query = []): string
    {
        $origin = preg_replace('#^(https?://[^/]+).*$#', '$1', self::$baseUrl);
        return $origin . self::to($path, $query);
    }

    public static function isHttps(): bool
    {
        return str_starts_with(self::$baseUrl, 'https://');
    }

    /**
     * A post-action destination is accepted only as a relative path under the
     * base path. A scheme, a protocol-relative //, a backslash (which some
     * browsers read as /) or anything outside the base falls back to home.
     */
    public static function safeReturnTo(?string $candidate, string $fallback = '/'): string
    {
        $fallbackUrl = self::to($fallback);
        if ($candidate === null || $candidate === '') {
            return $fallbackUrl;
        }
        if (preg_match('/[\x00-\x1f\\\\]/', $candidate)
            || !str_starts_with($candidate, '/')
            || str_starts_with($candidate, '//')
            || preg_match('#^/[^/]*:#', $candidate)
        ) {
            return $fallbackUrl;
        }
        $base = self::$basePath;
        if ($base !== '' && $candidate !== $base && !str_starts_with($candidate, $base . '/')) {
            return $fallbackUrl;
        }
        $path = (string) parse_url('http://x' . $candidate, PHP_URL_PATH);
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..' || $segment === '.') {
                return $fallbackUrl;
            }
        }
        return $candidate;
    }
}
