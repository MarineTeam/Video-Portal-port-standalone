<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Url;
use PHPUnit\Framework\TestCase;

/**
 * Where the site is willing to send somebody after signing in.
 *
 * Every sign-in carries a returnTo, so this is the site's open-redirect
 * surface: get a member to follow a link, let them sign in for real, and
 * land them somewhere that looks like the church's site and is not. Only a
 * relative path under the base path is accepted; everything else is `/`.
 */
final class ReturnToTest extends TestCase
{
    protected function setUp(): void
    {
        Url::configure('https://church.example');
    }

    public function test_1_an_ordinary_path_is_kept(): void
    {
        foreach (['/watch/sunday', '/profile', '/', '/search?q=grace', '/books/abc#page-3'] as $path) {
            self::assertSame($path, Url::safeReturnTo($path), $path);
        }
    }

    public function test_2_another_site_is_refused(): void
    {
        foreach ([
            'https://evil.example/', 'http://evil.example/', '//evil.example/',
            '/\\evil.example/', 'javascript:alert(1)', 'data:text/html,<script>',
        ] as $candidate) {
            self::assertSame('/', Url::safeReturnTo($candidate), $candidate);
        }
    }

    public function test_3_a_backslash_is_refused_because_a_browser_may_read_it_as_a_slash(): void
    {
        foreach (['/\\/evil.example', '\\\\evil.example', '/watch\\..\\..'] as $candidate) {
            self::assertSame('/', Url::safeReturnTo($candidate), $candidate);
        }
    }

    public function test_4_a_scheme_hidden_in_the_first_segment_is_refused(): void
    {
        // "/javascript:alert(1)" and "/https://evil.example" both start with
        // a slash and are still not paths on this site.
        foreach (['/javascript:alert(1)', '/https://evil.example', '/mailto:x@y.z'] as $candidate) {
            self::assertSame('/', Url::safeReturnTo($candidate), $candidate);
        }
    }

    public function test_5_a_control_character_is_refused(): void
    {
        foreach (["/watch\r\nSet-Cookie: a=b", "/watch\n", "/watch\0", "/watch\x1f"] as $candidate) {
            self::assertSame('/', Url::safeReturnTo($candidate), var_export($candidate, true));
        }
    }

    public function test_6_climbing_out_with_dots_is_refused(): void
    {
        foreach (['/../etc', '/watch/../../x', '/./x'] as $candidate) {
            self::assertSame('/', Url::safeReturnTo($candidate), $candidate);
        }
    }

    public function test_7_nothing_at_all_is_the_fallback(): void
    {
        self::assertSame('/', Url::safeReturnTo(null));
        self::assertSame('/', Url::safeReturnTo(''));
        self::assertSame('/profile', Url::safeReturnTo(null, '/profile'), 'and a caller may name its own');
    }

    public function test_8_in_a_subfolder_only_paths_inside_it_are_kept(): void
    {
        Url::configure('https://church.example/portal');
        self::assertSame('/portal/watch/one', Url::safeReturnTo('/portal/watch/one'));
        self::assertSame('/portal', Url::safeReturnTo('/portal'));
        // Outside the folder is somebody else's part of the same domain,
        // which on shared hosting is another customer.
        self::assertSame('/portal/', Url::safeReturnTo('/elsewhere'));
        self::assertSame('/portal/', Url::safeReturnTo('/portalx/watch'), 'and a prefix that only looks like ours');
    }
}
