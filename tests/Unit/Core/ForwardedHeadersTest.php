<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Request;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * What the site believes about where a request came from.
 *
 * Nearly every limit in this port is drawn per address: sign-in, registration,
 * password reset, share unlock, television pairing, prayer requests, event
 * sign-ups, the inbound SMS callbacks, and the key that stops one person
 * counting a view a hundred times. A signed file address can be bound to one.
 * So the address is not a note in a log — it is the thing those rules are
 * made of, and a visitor who can choose it has none of them.
 *
 * On shared hosting the site usually sees the visitor directly, and then
 * REMOTE_ADDR is the whole truth and X-Forwarded-For is just something a
 * stranger typed. Behind Cloudflare or a load balancer it is the reverse. The
 * installer asks which, with one tick box, and that answer is all this reads.
 */
final class ForwardedHeadersTest extends TestCase
{
    /** @param array<string, string> $headers */
    private static function request(array $headers, bool $trustProxy, string $remote = '203.0.113.9'): Request
    {
        $_SERVER = ['REQUEST_URI' => '/', 'REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => $remote];
        foreach ($headers as $name => $value) {
            $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $_GET = $_POST = $_COOKIE = $_FILES = [];
        return Request::fromGlobals('', $trustProxy);
    }

    #[TestDox('with no proxy configured the headers are just something a stranger typed')]
    public function testUntrusted(): void
    {
        $req = self::request(['x-forwarded-for' => '1.2.3.4', 'x-forwarded-proto' => 'https'], false);
        self::assertSame('203.0.113.9', $req->ip, 'the address the server itself saw');
        self::assertFalse($req->https, 'and the site does not think a plain request is secure');
    }

    #[TestDox('behind a proxy, the address taken is the one the proxy saw — not the one the visitor claims')]
    public function testSpoofedForwardedFor(): void
    {
        // A proxy appends: Cloudflare and nginx's $proxy_add_x_forwarded_for
        // both put what arrived first and the address they saw last. So the
        // left of that list is whatever the visitor typed, and the right of it
        // is the only part the proxy vouches for.
        $req = self::request(['x-forwarded-for' => '1.2.3.4, 198.51.100.7'], true);
        self::assertSame('198.51.100.7', $req->ip);

        // Several claims, and one that is shaped like the real thing.
        $req = self::request(['x-forwarded-for' => '198.51.100.7, 10.0.0.1, 198.51.100.7'], true);
        self::assertSame('198.51.100.7', $req->ip, 'still the last hop');

        // Nobody claimed anything: the ordinary case.
        self::assertSame('198.51.100.7', self::request(['x-forwarded-for' => '198.51.100.7'], true)->ip);
        self::assertSame('198.51.100.7', self::request(['x-forwarded-for' => ' 198.51.100.7 '], true)->ip);
        self::assertSame(
            '2001:db8::1',
            self::request(['x-forwarded-for' => '1.2.3.4, 2001:db8::1'], true)->ip,
            'a visitor on IPv6',
        );
    }

    #[TestDox('a header the proxy did not write leaves the address where it was')]
    public function testUnusableForwardedFor(): void
    {
        foreach (['', '  ', 'not-an-address', '1.2.3.4, banana', '1.2.3.4,'] as $rubbish) {
            self::assertSame(
                '203.0.113.9',
                self::request(['x-forwarded-for' => $rubbish], true)->ip,
                "the proxy's own address, rather than a visitor's claim: [$rubbish]",
            );
        }
    }

    #[TestDox('the same rule decides whether the request was secure')]
    public function testForwardedProto(): void
    {
        self::assertTrue(self::request(['x-forwarded-proto' => 'https'], true)->https);
        self::assertTrue(self::request(['x-forwarded-proto' => 'HTTPS'], true)->https);
        self::assertFalse(self::request(['x-forwarded-proto' => 'http'], true)->https);
        // A visitor claiming https in front of a proxy that appends http must
        // not make the site set a Secure cookie on a plain connection, which a
        // browser then throws away — they would lock themselves out.
        self::assertFalse(self::request(['x-forwarded-proto' => 'https, http'], true)->https);
        self::assertTrue(self::request(['x-forwarded-proto' => 'http, https'], true)->https);
    }

    #[TestDox('port 443 and the server\'s own flag are believed with or without a proxy')]
    public function testDirectHttps(): void
    {
        foreach ([true, false] as $trust) {
            $_SERVER = ['REQUEST_URI' => '/', 'REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '203.0.113.9', 'HTTPS' => 'on'];
            $_GET = $_POST = $_COOKIE = $_FILES = [];
            self::assertTrue(Request::fromGlobals('', $trust)->https);

            $_SERVER = ['REQUEST_URI' => '/', 'REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '203.0.113.9', 'HTTPS' => 'off'];
            self::assertFalse(Request::fromGlobals('', $trust)->https);
        }
    }
}
