<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * The headers on every response, and what an error page is allowed to say.
 *
 * These are set in one place so that adding a page cannot forget them, which
 * is exactly why they are worth asserting from outside: the test is of the
 * server's real answers, on pages reached by different routes.
 */
final class HeadersTest extends ServerTestCase
{
    protected static function prefix(): string
    {
        return 'hdr_';
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::install();
    }

    /** @return list<array{0: string, 1: string}> */
    public static function pages(): array
    {
        return [
            'the home page' => ['/', 'guest'],
            'an admin page' => ['/admin/analytics', 'admin'],
            'a page that is not there' => ['/watch/nothing-here', 'guest'],
            'the television screen' => ['/tv', 'guest'],
            'a JSON endpoint' => ['/api/v1', 'guest'],
        ];
    }

    public function test_1_every_page_carries_the_four_headers(): void
    {
        foreach (self::pages() as $what => [$path, $who]) {
            $headers = self::http('GET', $path, null, $who)['headers'];
            self::assertSame('nosniff', $headers['x-content-type-options'] ?? null, $what);
            self::assertSame('strict-origin-when-cross-origin', $headers['referrer-policy'] ?? null, $what);
            // The television screen is framed by nothing but us either.
            self::assertSame('SAMEORIGIN', $headers['x-frame-options'] ?? null, $what);
            self::assertNotSame('', (string) ($headers['permissions-policy'] ?? ''), $what);
        }
    }

    public function test_2_the_permissions_policy_grants_only_what_a_player_needs(): void
    {
        $policy = (string) self::http('GET', '/')['headers']['permissions-policy'];
        foreach (['camera=()', 'microphone=()', 'geolocation=()', 'payment=()', 'usb=()'] as $denied) {
            self::assertStringContainsString($denied, $policy, 'nothing here needs it');
        }
        foreach (['fullscreen=(self)', 'autoplay=(self)', 'picture-in-picture=(self)'] as $allowed) {
            self::assertStringContainsString($allowed, $policy, 'and a video player does');
        }
    }

    public function test_3_every_page_carries_a_content_security_policy(): void
    {
        foreach (self::pages() as $what => [$path, $who]) {
            $csp = (string) (self::http('GET', $path, null, $who)['headers']['content-security-policy'] ?? '');
            self::assertStringContainsString("default-src 'self'", $csp, $what);
            self::assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp, "$what: a nonce, not a blanket");
        }
    }

    public function test_4_the_nonce_is_new_on_every_response(): void
    {
        // A nonce reused between responses is a nonce an attacker can learn
        // from one page and spend on another.
        $nonces = [];
        for ($i = 0; $i < 5; $i++) {
            preg_match("/'nonce-([A-Za-z0-9_-]+)'/", (string) self::http('GET', '/')['headers']['content-security-policy'], $m);
            $nonces[] = $m[1] ?? '';
        }
        self::assertCount(5, array_unique(array_filter($nonces)));
    }

    public function test_5_hsts_waits_until_an_admin_has_said_https_is_permanent(): void
    {
        // Sent too early it locks a church out of its own site on the
        // temporary hostname it was set up on.
        self::assertArrayNotHasKey('strict-transport-security', self::http('GET', '/')['headers']);
    }

    public function test_6_an_error_page_says_nothing_about_the_inside_of_the_site(): void
    {
        $answer = self::http('GET', '/watch/nothing-here');
        self::assertSame(404, $answer['status']);
        foreach ([
            '/home/', 'app/Core', 'Stack trace', '#0 ', 'PDOException', 'SQLSTATE',
            'SELECT ', 'vendor/', '.php:',
        ] as $leak) {
            self::assertStringNotContainsString($leak, $answer['body'], $leak);
        }
    }

    public function test_7_a_json_endpoint_answers_json_and_is_not_cached(): void
    {
        $answer = self::http('GET', '/api/v1');
        self::assertStringContainsString('application/json', (string) $answer['headers']['content-type']);
        self::assertNotSame('', (string) ($answer['headers']['cache-control'] ?? ''), 'an API answer is not cached by a proxy by default');
    }

    public function test_8_every_response_carries_a_request_id_to_match_the_log_against(): void
    {
        // The one thing an administrator on shared hosting can give support:
        // the id off the page, found in /admin/logs.
        $id = (string) (self::http('GET', '/')['headers']['x-request-id'] ?? '');
        self::assertMatchesRegularExpression('/^[a-f0-9]{8,}$/', $id);
        self::assertNotSame($id, (string) self::http('GET', '/')['headers']['x-request-id']);
    }
}
