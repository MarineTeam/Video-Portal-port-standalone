<?php

declare(strict_types=1);

namespace App\Core;

/**
 * A per-session token on every state-changing request, compared in constant
 * time; the browser's own Sec-Fetch-Site and Origin headers as a second
 * layer. GET never changes state, so it is never checked.
 *
 * Exempt: bearer-authenticated /api/v1, /cron/run, the registration check
 * (a bearer secret), the television's pair/poll (a device secret), and the
 * signature-verified SMS webhooks. Each of those authenticates the request by
 * something a cross-site page cannot send.
 */
final class Csrf
{
    public const FIELD = '_csrf';
    public const HEADER = 'x-csrf-token';

    /** @var list<string> path prefixes that authenticate by other means */
    private const EXEMPT = [
        '/api/v1',
        '/cron/run',
        '/api/auth/registration-check',
        // Apple's form_post arrives cross-site; the spent-once state is its CSRF check.
        '/auth/callback',
        '/api/tv/pair',
        '/api/tv/poll',
        '/api/sms/status/',
        '/api/sms/inbound/',
    ];

    public static function token(Session $session): string
    {
        $token = $session->get('_csrf');
        if (!is_string($token) || $token === '') {
            $session->ensure();
            $token = Id::token(32);
            $session->set('_csrf', $token);
        }
        return $token;
    }

    public static function isExempt(string $path): bool
    {
        foreach (self::EXEMPT as $prefix) {
            if ($path === rtrim($prefix, '/') || str_starts_with($path, rtrim($prefix, '/') . '/') || ($prefix[-1] === '/' && str_starts_with($path, $prefix))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Writes the browser labels cross-site are refused outright, whatever
     * they carry. A caller with no Sec-Fetch-Site header — a server, a
     * television — is left to the token check.
     */
    public static function isCrossSiteWrite(Request $request): bool
    {
        if ($request->isSafeMethod()) {
            return false;
        }
        $site = $request->header('sec-fetch-site');
        if ($site === 'cross-site') {
            return true;
        }
        $origin = $request->header('origin');
        if ($origin !== null && $origin !== 'null') {
            $host = parse_url($origin, PHP_URL_HOST);
            if (is_string($host) && strtolower($host) !== strtolower(explode(':', $request->host)[0])) {
                return true;
            }
        }
        return false;
    }

    public static function check(Request $request, Session $session): bool
    {
        if ($request->isSafeMethod() || self::isExempt($request->path)) {
            return true;
        }
        if (self::isCrossSiteWrite($request)) {
            return false;
        }
        $expected = $session->get('_csrf');
        if (!is_string($expected) || $expected === '') {
            return false;
        }
        $given = $request->header(self::HEADER);
        if ($given === null) {
            $input = $request->input();
            $given = is_string($input[self::FIELD] ?? null) ? $input[self::FIELD] : null;
        }
        return is_string($given) && hash_equals($expected, $given);
    }
}
