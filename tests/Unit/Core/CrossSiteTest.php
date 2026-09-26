<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Csrf;
use App\Core\Request;
use PHPUnit\Framework\TestCase;

/**
 * The second lock on every write (lib/cross-site.ts).
 *
 * The token is the first. This is the browser's own account of where the
 * request came from, which a cross-site page cannot lie about — and which an
 * ordinary server or a television has no opinion on at all, so its silence
 * must not be read as an accusation.
 */
final class CrossSiteTest extends TestCase
{
    /** @param array<string, string> $headers */
    private static function request(string $method, array $headers = [], string $host = 'church.example'): Request
    {
        return new Request($method, '/api/anything', headers: $headers, host: $host);
    }

    public function test_1_a_get_is_never_cross_site_because_it_changes_nothing(): void
    {
        foreach (['GET', 'HEAD'] as $method) {
            self::assertFalse(Csrf::isCrossSiteWrite(self::request($method, ['sec-fetch-site' => 'cross-site'])), $method);
        }
    }

    public function test_2_a_write_the_browser_calls_cross_site_is_refused(): void
    {
        self::assertTrue(Csrf::isCrossSiteWrite(self::request('POST', ['sec-fetch-site' => 'cross-site'])));
        self::assertTrue(Csrf::isCrossSiteWrite(self::request('DELETE', ['sec-fetch-site' => 'cross-site'])));
    }

    public function test_3_the_labels_a_browser_uses_for_our_own_pages_pass(): void
    {
        foreach (['same-origin', 'same-site', 'none'] as $site) {
            self::assertFalse(Csrf::isCrossSiteWrite(self::request('POST', ['sec-fetch-site' => $site])), $site);
        }
    }

    public function test_4_a_caller_with_no_opinion_is_left_to_the_token(): void
    {
        // A server, a television, curl: no Sec-Fetch-Site and no Origin.
        // Refusing these outright would break the television and every
        // provider callback; they are checked by what they carry instead.
        self::assertFalse(Csrf::isCrossSiteWrite(self::request('POST')));
    }

    public function test_5_an_origin_from_somewhere_else_is_refused_whatever_the_label_says(): void
    {
        self::assertTrue(Csrf::isCrossSiteWrite(self::request('POST', ['origin' => 'https://evil.example'])));
        // Including a near-miss that only looks like us.
        self::assertTrue(Csrf::isCrossSiteWrite(self::request('POST', ['origin' => 'https://church.example.evil.test'])));
        self::assertTrue(Csrf::isCrossSiteWrite(self::request('POST', ['origin' => 'https://notchurch.example'])));
    }

    public function test_6_our_own_origin_passes_whatever_its_scheme_or_port(): void
    {
        foreach (['https://church.example', 'http://church.example', 'https://church.example:8443'] as $origin) {
            self::assertFalse(Csrf::isCrossSiteWrite(self::request('POST', ['origin' => $origin])), $origin);
        }
    }

    public function test_7_the_host_is_compared_without_its_port_and_without_case(): void
    {
        self::assertFalse(Csrf::isCrossSiteWrite(self::request('POST', ['origin' => 'https://CHURCH.example'], 'church.example:8080')));
        self::assertFalse(Csrf::isCrossSiteWrite(self::request('POST', ['origin' => 'https://church.example'], 'CHURCH.EXAMPLE')));
    }

    public function test_8_a_null_origin_is_left_to_the_token(): void
    {
        // A sandboxed frame and a file:// page both send "null". It says
        // nothing either way, so the token decides.
        self::assertFalse(Csrf::isCrossSiteWrite(self::request('POST', ['origin' => 'null'])));
    }
}
