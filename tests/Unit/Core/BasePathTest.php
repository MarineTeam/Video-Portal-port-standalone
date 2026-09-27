<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Request;
use App\Core\Session;
use App\Core\Url;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A site unzipped into a subdirectory — example.org/church — which is a
 * deployment this port supports and a shared host makes easy to end up with.
 *
 * Everything the app generates has to carry that prefix and everything it
 * reads has to have it taken off, and the two are written in different places:
 * Request::stripBase on the way in, Url on the way out, with the session
 * cookie's own path between them. None of it is exercised by the suite's
 * other tests, which all run at a domain root.
 */
final class BasePathTest extends TestCase
{
    protected function tearDown(): void
    {
        Url::configure('http://localhost');
    }

    #[TestDox('the prefix comes off an incoming path, and only when it is really the prefix')]
    public function testStripBase(): void
    {
        self::assertSame('/', Request::stripBase('/church', '/church'));
        self::assertSame('/', Request::stripBase('/church/', '/church'));
        self::assertSame('/videos/romans-1', Request::stripBase('/church/videos/romans-1', '/church'));
        // The one that bites: a sibling whose name starts the same way.
        self::assertSame('/churchyard', Request::stripBase('/churchyard', '/church'));
        self::assertSame('/churchyard/gates', Request::stripBase('/churchyard/gates', '/church'));
        // Nothing to strip.
        self::assertSame('/videos/romans-1', Request::stripBase('/videos/romans-1', ''));
        self::assertSame('/elsewhere', Request::stripBase('/elsewhere', '/church'));
    }

    #[TestDox('a path that tries to walk out of the subdirectory does not')]
    public function testNoWalking(): void
    {
        foreach (['/church/../admin', '/church/./admin', '/church/a/../../admin', '/../etc', "/church/\0/admin"] as $sneaky) {
            // Nothing below here should have to think about a walking path, so
            // it never becomes one: it lands on the site's own front door.
            self::assertSame('/', Request::stripBase($sneaky, '/church'), $sneaky);
        }
    }

    #[TestDox('every address the app writes carries the subdirectory')]
    public function testGeneratedUrls(): void
    {
        Url::configure('https://example.org/church');

        self::assertSame('/church', Url::basePath());
        self::assertSame('/church/', Url::to('/'));
        self::assertSame('/church/videos/romans-1', Url::to('/videos/romans-1'));
        self::assertSame('/church/search?q=grace', Url::to('/search', ['q' => 'grace']));
        self::assertSame('https://example.org/church/videos/romans-1', Url::absolute('/videos/romans-1'));
    }

    #[TestDox('a returnTo is kept only when it stays inside the subdirectory')]
    public function testReturnTo(): void
    {
        Url::configure('https://example.org/church');

        self::assertSame('/church/videos/romans-1', Url::safeReturnTo('/church/videos/romans-1'));
        // The site's own root address, which is a place, so it is kept as it is.
        self::assertSame('/church', Url::safeReturnTo('/church'));
        // Everything below lands somewhere this site does not own, or nowhere.
        foreach ([
            '/videos/romans-1' => 'a path without the prefix belongs to whatever else is on this domain',
            '/churchyard/gates' => 'a sibling directory that merely starts the same way',
            '//evil.example/church' => 'another host',
            '/church/../admin' => 'walking out of the subdirectory',
            'https://evil.example' => 'an absolute address',
            '/\\evil.example' => 'a backslash some browsers read as a slash',
        ] as $candidate => $why) {
            self::assertSame('/church/', Url::safeReturnTo($candidate), $why);
        }
    }

    #[TestDox('the session cookie is scoped to the subdirectory, and drops __Host- which cannot describe it')]
    public function testCookie(): void
    {
        Url::configure('https://example.org/church');
        // __Host- means "this origin, path /, secure". A site in a
        // subdirectory cannot honour the path part, and a browser rejects the
        // cookie outright rather than scoping it — so the plain name is used.
        self::assertSame('mt_session', Session::cookieName(true));
        self::assertSame('mt_session', Session::cookieName(false));

        Url::configure('https://example.org');
        self::assertSame('__Host-mt_session', Session::cookieName(true), 'at a root over https it can be bound');
        self::assertSame('mt_session', Session::cookieName(false), 'but never without https');
    }
}
