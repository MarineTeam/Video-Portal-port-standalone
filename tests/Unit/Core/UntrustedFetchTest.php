<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Http;
use App\Core\HttpException;
use App\Core\HttpResponse;
use PHPUnit\Framework\TestCase;

/**
 * The one door every URL somebody typed goes through.
 *
 * A church site is on a shared machine, often behind a cloud provider's
 * metadata service, and half a dozen features take a URL from a form: the
 * direct-link and shared-link video providers, the transcription service,
 * the JSON SMS webhook, the Webhooks plugin. Each of those is a way to ask
 * this server to fetch something on somebody's behalf, so the checks here
 * are the whole defence, and the redirect case is the one that gets missed.
 */
final class UntrustedFetchTest extends TestCase
{
    protected function tearDown(): void
    {
        Http::fake(null);
        Http::fakeResolver(null);
    }

    public function test_1_loopback_and_private_ranges_are_not_public(): void
    {
        foreach ([
            '127.0.0.1', '127.1.2.3', '0.0.0.0', '10.0.0.1', '172.16.5.4', '192.168.1.1',
            '169.254.169.254', '100.64.0.1', '192.0.0.1', '198.18.0.1', '224.0.0.1', '240.0.0.1',
        ] as $ip) {
            self::assertFalse(Http::isPublicIp($ip), $ip);
        }
    }

    public function test_2_the_cloud_metadata_address_is_refused_however_it_is_written(): void
    {
        // The one an attacker actually wants: it hands out the machine's
        // credentials to anything that can make it fetch a URL.
        foreach (['169.254.169.254', '::ffff:169.254.169.254', '0:0:0:0:0:ffff:a9fe:a9fe'] as $spelling) {
            self::assertFalse(Http::isPublicIp($spelling), $spelling);
        }
        self::assertFalse(Http::isPublicHttpUrl('http://169.254.169.254/latest/meta-data/'));
    }

    public function test_3_the_ipv6_ways_of_saying_here_are_refused(): void
    {
        foreach (['::1', '::', 'fc00::1', 'fd12:3456::1', 'fe80::1', 'ff02::1', '2001:db8::1', '64:ff9b::7f00:1'] as $ip) {
            self::assertFalse(Http::isPublicIp($ip), $ip);
        }
        self::assertTrue(Http::isPublicIp('2606:4700:4700::1111'), 'and a real one still is');
    }

    public function test_4_an_ipv4_address_wearing_an_ipv6_coat_is_judged_as_ipv4(): void
    {
        self::assertFalse(Http::isPublicIp('::ffff:127.0.0.1'));
        self::assertFalse(Http::isPublicIp('::ffff:10.0.0.1'));
        self::assertTrue(Http::isPublicIp('::ffff:93.184.216.34'));
    }

    public function test_5_only_http_and_https_and_never_with_credentials(): void
    {
        foreach ([
            'file:///etc/passwd', 'gopher://example.org/', 'ftp://example.org/x',
            'javascript:alert(1)', 'data:text/plain,hello', 'dict://example.org:11211/',
        ] as $url) {
            self::assertFalse(Http::isPublicHttpUrl($url), $url);
        }
        // Credentials in a URL are how a fetch is aimed at something that
        // trusts them, and nothing here ever needs them.
        self::assertFalse(Http::isPublicHttpUrl('http://user:pass@example.org/'));
        self::assertFalse(Http::isPublicHttpUrl('https://admin@example.org/'));
    }

    public function test_6_a_name_that_only_means_something_on_a_lan_is_refused(): void
    {
        foreach ([
            'http://localhost/', 'http://LOCALHOST/', 'http://printer/', 'http://db.internal/',
            'http://host.local/', 'http://server.lan/', 'http://thing.home.arpa/', 'http://box.intranet/',
        ] as $url) {
            self::assertFalse(Http::isPublicHttpUrl($url), $url);
        }
    }

    public function test_7_an_address_spelled_as_a_number_is_still_an_address(): void
    {
        // 2130706433 and 0x7f000001 are both 127.0.0.1 to a resolver.
        foreach (['http://2130706433/', 'http://0x7f000001/', 'http://0x7f.1/', 'http://017700000001/'] as $url) {
            self::assertFalse(Http::isPublicHttpUrl($url), $url);
        }
    }

    public function test_8_an_ordinary_address_passes(): void
    {
        foreach (['https://example.org/a', 'http://example.org:8080/a', 'https://sub.example.co.uk/'] as $url) {
            self::assertTrue(Http::isPublicHttpUrl($url), $url);
        }
        self::assertTrue(Http::isPublicHttpUrl('https://93.184.216.34/'));
    }

    public function test_9_a_public_name_pointing_at_a_private_address_is_refused(): void
    {
        // The attack the syntactic check cannot see: the name is fine and the
        // answer is not.
        Http::fakeResolver(fn () => ['127.0.0.1']);
        $this->expectExceptionMessage('inside a private network');
        Http::checkUntrusted('https://totally-normal.example/');
    }

    public function test_10_one_private_answer_among_several_is_enough_to_refuse(): void
    {
        // A name that answers with a public address and a private one is the
        // way round a check that only looks at the first.
        Http::fakeResolver(fn () => ['93.184.216.34', '169.254.169.254']);
        $this->expectExceptionMessage('inside a private network');
        Http::checkUntrusted('https://mixed.example/');
    }

    public function test_11_a_name_that_answers_with_nothing_is_refused(): void
    {
        Http::fakeResolver(fn () => []);
        $this->expectExceptionMessage("Couldn't find the server");
        Http::checkUntrusted('https://nowhere.example/');
    }

    public function test_12_a_redirect_into_a_private_network_is_refused_at_the_hop(): void
    {
        // The case worth the machinery: the first address is public and the
        // 302 points at the metadata service. Checking only the URL somebody
        // typed would follow it.
        Http::fakeResolver(fn (string $host) => $host === 'evil.example' ? ['93.184.216.34'] : ['169.254.169.254']);
        Http::fake(fn () => new HttpResponse(302, ['location' => 'http://metadata.example/latest/meta-data/'], ''));

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('inside a private network');
        Http::fetchUntrusted('GET', 'https://evil.example/start');
    }

    public function test_13_a_redirect_to_a_scheme_we_do_not_speak_is_refused(): void
    {
        Http::fakeResolver(fn () => ['93.184.216.34']);
        Http::fake(fn () => new HttpResponse(302, ['location' => 'file:///etc/passwd'], ''));

        $this->expectException(HttpException::class);
        Http::fetchUntrusted('GET', 'https://example.org/start');
    }

    public function test_14_a_redirect_loop_ends_rather_than_spinning(): void
    {
        Http::fakeResolver(fn () => ['93.184.216.34']);
        $hops = 0;
        Http::fake(function () use (&$hops): HttpResponse {
            $hops++;
            return new HttpResponse(302, ['location' => 'https://example.org/again'], '');
        });

        try {
            Http::fetchUntrusted('GET', 'https://example.org/start');
            self::fail('a loop should end in an exception');
        } catch (HttpException $e) {
            self::assertStringContainsString('redirects', $e->getMessage());
        }
        self::assertLessThan(15, $hops, 'and does not spin a hundred times first');
    }

    public function test_15_a_redirect_that_is_fine_is_followed(): void
    {
        Http::fakeResolver(fn () => ['93.184.216.34']);
        $seen = [];
        Http::fake(function (string $method, string $url) use (&$seen): HttpResponse {
            $seen[] = "$method $url";
            return count($seen) === 1
                ? new HttpResponse(302, ['location' => '/moved'], '')
                : new HttpResponse(200, [], 'here it is');
        });

        $answer = Http::fetchUntrusted('POST', 'https://example.org/start', [], 'body');

        self::assertSame(200, $answer->status);
        self::assertSame('here it is', $answer->body);
        // A 302 after a POST is followed as a GET, and a relative Location
        // resolves against where it came from.
        self::assertSame(['POST https://example.org/start', 'GET https://example.org/moved'], $seen);
    }

    public function test_16_the_fetch_is_capped_and_timed_out(): void
    {
        Http::fakeResolver(fn () => ['93.184.216.34']);
        $options = null;
        Http::fake(function (string $method, string $url, array $headers, ?string $body, array $given) use (&$options): HttpResponse {
            $options = $given;
            return new HttpResponse(200, [], 'ok');
        });
        Http::fetchUntrusted('GET', 'https://example.org/a');

        self::assertSame(Http::UNTRUSTED_TIMEOUT, $options['timeout'] ?? null, 'it cannot hang the request that asked');
        self::assertSame(Http::UNTRUSTED_MAX_BYTES, $options['maxBytes'] ?? null, 'and cannot be handed a hundred gigabytes');
        self::assertFalse($options['followRedirects'] ?? true, 'curl must not follow one behind our back');
        self::assertSame('93.184.216.34', $options['resolve']['ip'] ?? null, 'the address checked is the address dialled');
    }
}
