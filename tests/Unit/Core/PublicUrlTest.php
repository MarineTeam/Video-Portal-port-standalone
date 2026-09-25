<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Http;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** lib/public-url.test.ts — Http::isPublicHttpUrl */
final class PublicUrlTest extends TestCase
{
    #[TestDox('accepts ordinary public endpoints')]
    public function testPublic(): void
    {
        foreach (['https://hooks.example.com/in', 'http://example.org:8080/x?y=1', 'https://8.8.8.8/', 'https://[2606:4700::1111]/'] as $url) {
            self::assertTrue(Http::isPublicHttpUrl($url), $url);
        }
    }

    #[TestDox('refuses loopback, private and link-local addresses')]
    public function testPrivate(): void
    {
        foreach ([
            'http://127.0.0.1/', 'http://10.0.0.1/', 'http://192.168.1.10/', 'http://172.16.5.4/', 'http://172.31.255.255/',
            'http://169.254.169.254/latest/meta-data', 'http://100.64.0.1/', 'http://0.0.0.0/', 'http://[::1]/',
            'http://[fe80::1]/', 'http://[fd00::1]/', 'http://[::ffff:127.0.0.1]/', 'http://[::ffff:169.254.169.254]/',
        ] as $url) {
            self::assertFalse(Http::isPublicHttpUrl($url), $url);
        }
    }

    #[TestDox('refuses names that only mean something inside a network')]
    public function testInternalNames(): void
    {
        foreach (['http://localhost/', 'http://intranet/', 'http://printer.local/', 'http://db.internal/', 'http://2130706433/', 'http://0x7f.1/'] as $url) {
            self::assertFalse(Http::isPublicHttpUrl($url), $url);
        }
    }

    #[TestDox('does not block a public range that merely neighbours a private one')]
    public function testNeighbours(): void
    {
        foreach (['http://172.32.0.1/', 'http://172.15.255.255/', 'http://11.0.0.1/', 'http://192.169.0.1/', 'http://100.128.0.1/', 'http://169.253.1.1/'] as $url) {
            self::assertTrue(Http::isPublicHttpUrl($url), $url);
        }
    }

    #[TestDox('refuses other schemes, credentials, and non-URLs')]
    public function testSchemes(): void
    {
        foreach (['ftp://example.com/', 'file:///etc/passwd', 'gopher://example.com', 'https://user:pw@example.com/', 'not a url', '', 'javascript:alert(1)'] as $url) {
            self::assertFalse(Http::isPublicHttpUrl($url), $url);
        }
    }

    #[TestDox('refuses a host that resolves to a private address before connecting')]
    public function testResolution(): void
    {
        $this->expectException(\App\Core\HttpException::class);
        Http::checkUntrusted('http://127.0.0.1:8080/');
    }
}
