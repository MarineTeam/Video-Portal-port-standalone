<?php

declare(strict_types=1);

namespace Tests\Unit\Files;

use App\Core\Http;
use App\Core\HttpResponse;
use App\Core\Request;
use App\Services\Files\BunnyStorageProvider;
use PHPUnit\Framework\TestCase;

/** Bunny Storage against the answers its API documents. */
final class BunnyStorageTest extends TestCase
{
    /** @var list<array{0: string, 1: string, 2: array<string, string>, 3: array<string, mixed>}> */
    private array $requests = [];

    protected function tearDown(): void
    {
        Http::fake(null);
    }

    /** @param array<string, callable|HttpResponse> $routes "METHOD url-prefix" => answer */
    private function fake(array $routes): void
    {
        $this->requests = [];
        Http::fake(function (string $method, string $url, array $headers, ?string $body, array $options = []) use ($routes): HttpResponse {
            $this->requests[] = [$method, $url, $headers, $options];
            foreach ($routes as $key => $answer) {
                [$m, $prefix] = explode(' ', $key, 2);
                if ($m === $method && str_starts_with($url, $prefix)) {
                    return is_callable($answer) ? $answer($options, $headers) : $answer;
                }
            }
            return new HttpResponse(599, [], 'no fixture for ' . $method . ' ' . $url);
        });
    }

    private function provider(array $extra = []): BunnyStorageProvider
    {
        return new BunnyStorageProvider($extra + ['zone' => 'grace-files', 'api_key' => 'pw', 'region' => 'uk', 'pull_zone_host' => 'grace.b-cdn.net']);
    }

    private static function request(array $headers = [], string $ip = '203.0.113.9'): Request
    {
        return new Request('GET', '/api/files/x/content', [], [], [], $headers, '', $ip);
    }

    public function test_storage_host_per_region(): void
    {
        self::assertSame('storage.bunnycdn.com', BunnyStorageProvider::storageHost(''));
        self::assertSame('storage.bunnycdn.com', BunnyStorageProvider::storageHost('de'));
        self::assertSame('ny.storage.bunnycdn.com', BunnyStorageProvider::storageHost('NY'));
    }

    public function test_lists_a_folder_directories_first(): void
    {
        $this->fake(['GET https://uk.storage.bunnycdn.com/grace-files/scans/' => new HttpResponse(200, ['content-type' => 'application/json'], (string) json_encode([
            ['ObjectName' => 'hymnal.pdf', 'IsDirectory' => false, 'Length' => 5242880, 'LastChanged' => '2026-09-01T10:00:00'],
            ['ObjectName' => '2025', 'IsDirectory' => true, 'Length' => 0],
        ]))]);
        $entries = $this->provider()->list('scans');
        self::assertSame(['2025', 'hymnal.pdf'], array_column($entries, 'name'));
        self::assertSame('scans/hymnal.pdf', $entries[1]['path']);
        self::assertSame('pw', $this->requests[0][2]['AccessKey']);
    }

    public function test_uploads_by_streaming_the_file_from_disk(): void
    {
        $this->fake(['PUT https://uk.storage.bunnycdn.com/grace-files/files/abc.pdf' => new HttpResponse(201, [], '{"HttpCode":201}')]);
        $tmp = (string) tempnam(sys_get_temp_dir(), 't');
        file_put_contents($tmp, '%PDF-1.4');
        $this->provider()->put($tmp, 'files/abc.pdf');
        self::assertSame($tmp, $this->requests[0][3]['bodyFile']);
        self::assertFileDoesNotExist($tmp);
    }

    public function test_signed_redirect_with_token_authentication(): void
    {
        $response = $this->provider(['token_key' => 'tk'])->serve('files/abc.pdf', self::request(), ['Content-Type' => 'application/pdf']);
        self::assertSame(302, $response->status);
        $location = (string) $response->getHeader('Location');
        self::assertStringStartsWith('https://grace.b-cdn.net/files/abc.pdf?token=', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $q);
        $expected = rtrim(strtr(base64_encode(hash('sha256', 'tk/files/abc.pdf' . $q['expires'], true)), '+/', '-_'), '=');
        self::assertSame($expected, $q['token']);
        self::assertGreaterThan(time(), (int) $q['expires']);
        self::assertLessThanOrEqual(time() + 2 * BunnyStorageProvider::SIGNED_TTL, (int) $q['expires']);
        self::assertSame('no-store', $response->getHeader('Cache-Control'));
    }

    public function test_a_bound_link_signs_the_readers_address_too(): void
    {
        $location = (string) $this->provider(['token_key' => 'tk', 'bind_ip' => true])->serve('files/abc.pdf', self::request([], '198.51.100.7'), [])->getHeader('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $q);
        self::assertSame(rtrim(strtr(base64_encode(hash('sha256', 'tk/files/abc.pdf' . $q['expires'] . '198.51.100.7', true)), '+/', '-_'), '='), $q['token']);
    }

    public function test_without_a_token_key_it_proxies_and_forwards_range(): void
    {
        $this->fake(['GET https://grace.b-cdn.net/files/abc.pdf' => function (array $options, array $headers): HttpResponse {
            ($options['onHeaders'])(206, ['content-range' => 'bytes 0-3/100', 'content-length' => '4']);
            ($options['sink'])('%PDF');
            return new HttpResponse(206, [], '');
        }]);
        $response = $this->provider()->serve('files/abc.pdf', self::request(['range' => 'bytes=0-3']), ['Content-Type' => 'application/pdf']);
        self::assertSame('application/pdf', $response->getHeader('Content-Type'));
        $body = '';
        BunnyStorageProvider::writeUsing(function (string $chunk) use (&$body): void {
            $body .= $chunk;
        });
        $response = $this->provider()->serve('files/abc.pdf', self::request(['range' => 'bytes=0-3']), ['Content-Type' => 'application/pdf']);
        $response->send();
        BunnyStorageProvider::writeUsing(null);
        self::assertSame('%PDF', $body);
        self::assertSame('bytes=0-3', $this->requests[0][2]['Range']);
    }

    public function test_the_public_zone_is_a_separate_zone_and_copies_through_a_temporary_file(): void
    {
        $p = $this->provider(['public_zone' => 'grace-podcast', 'public_api_key' => 'pub', 'public_pull_zone_host' => 'pod.b-cdn.net']);
        self::assertTrue($p->hasPublicZone());
        $this->fake([
            'GET https://uk.storage.bunnycdn.com/grace-files/files/ep1.mp3' => function (array $options): HttpResponse {
                ($options['sink'])('ID3audio');
                return new HttpResponse(200, [], '');
            },
            'PUT https://storage.bunnycdn.com/grace-podcast/podcast/f1/ep1.mp3' => function (array $options): HttpResponse {
                TestCase::assertSame('ID3audio', file_get_contents((string) $options['bodyFile']));
                return new HttpResponse(201, [], '');
            },
        ]);
        $p->copyToPublic('files/ep1.mp3', 'podcast/f1/ep1.mp3');
        self::assertSame('pub', $this->requests[1][2]['AccessKey']);
        self::assertSame('https://pod.b-cdn.net/podcast/f1/ep1.mp3', $p->publicUrl('podcast/f1/ep1.mp3'));
    }

    public function test_the_test_refuses_a_public_zone_that_is_the_private_one(): void
    {
        $result = $this->provider(['public_zone' => 'grace-files', 'public_api_key' => 'pw', 'public_pull_zone_host' => 'grace.b-cdn.net'])->test(new \App\Services\TestContext('a@b.org', true, 'https://x', []));
        self::assertFalse($result->ok);
        self::assertStringContainsString('different storage zone', $result->message);
    }
}
