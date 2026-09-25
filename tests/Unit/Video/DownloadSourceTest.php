<?php

declare(strict_types=1);

namespace Tests\Unit\Video;

use App\Core\Http;
use App\Core\HttpException;
use App\Core\HttpResponse;
use App\Modules\Library\Downloads;
use App\Services\Video\BunnyStreamProvider;
use PHPUnit\Framework\TestCase;

/** lib/download-source.test.ts, against recorded Bunny answers. */
final class DownloadSourceTest extends TestCase
{
    /** @var list<string> */
    private array $calls = [];
    /** @var list<array{0: bool, 1: ?string}> */
    private array $saved = [];

    protected function tearDown(): void
    {
        Http::fake(null);
    }

    /** @param array<string, HttpResponse|\Throwable> $routes url prefix => answer */
    private function fake(array $routes): void
    {
        $this->calls = [];
        Http::fake(function (string $method, string $url) use ($routes): HttpResponse {
            $this->calls[] = "$method $url";
            foreach ($routes as $prefix => $answer) {
                if (str_starts_with($url, $prefix)) {
                    if ($answer instanceof \Throwable) {
                        throw $answer;
                    }
                    return $answer;
                }
            }
            return new HttpResponse(599, [], 'unexpected');
        });
    }

    private function resolve(array $row, string $cap = '720'): \App\Services\Video\Mp4Result
    {
        $this->saved = [];
        $provider = new BunnyStreamProvider(['libraryId' => '123', 'apiKey' => 'k', 'cdnHostname' => 'vz-abc.b-cdn.net', 'downloadHeight' => $cap]);
        return Downloads::resolveMp4Source($provider, $row + ['id' => 'v1', 'provider' => 'bunny', 'external_id' => 'guid-1', 'provider_data' => '{}'], function (bool $has, ?string $res): void {
            $this->saved[] = [$has, $res];
        });
    }

    private static function cdn(int $status): HttpResponse
    {
        return new HttpResponse($status, [], '');
    }

    private static function api(array $data, int $status = 200): HttpResponse
    {
        return new HttpResponse($status, ['content-type' => 'application/json'], (string) json_encode($data));
    }

    public function test_1_available_up_to_and_including_the_configured_cap_picks_720p(): void
    {
        $this->fake(['https://vz-abc.b-cdn.net/' => self::cdn(206)]);
        $r = $this->resolve(['has_mp4_fallback' => true, 'mp4_resolutions' => '240p,360p,480p,720p,1080p']);
        self::assertTrue($r->ok);
        self::assertSame(720, $r->height);
        self::assertStringContainsString('/guid-1/play_720p.mp4', (string) $r->url);
    }

    public function test_2_only_lower_resolutions_available_picks_480p_not_720p(): void
    {
        $this->fake(['https://vz-abc.b-cdn.net/' => self::cdn(206)]);
        self::assertSame(480, $this->resolve(['has_mp4_fallback' => true, 'mp4_resolutions' => '240p,480p'])->height);
    }

    public function test_3_has_mp4_fallback_false_returns_mp4_unavailable_without_touching_bunny_again(): void
    {
        $this->fake([]);
        $r = $this->resolve(['has_mp4_fallback' => false, 'mp4_resolutions' => null]);
        self::assertSame('mp4_unavailable', $r->reason);
        self::assertSame([], $this->calls);
    }

    public function test_4_a_cdn_403_on_the_resolved_url_returns_mp4_forbidden(): void
    {
        $this->fake(['https://vz-abc.b-cdn.net/' => self::cdn(403)]);
        self::assertSame('mp4_forbidden', $this->resolve(['has_mp4_fallback' => true, 'mp4_resolutions' => '720p'])->reason);
    }

    public function test_5_a_cdn_404_on_the_resolved_url_returns_mp4_missing(): void
    {
        $this->fake(['https://vz-abc.b-cdn.net/' => self::cdn(404)]);
        self::assertSame('mp4_missing', $this->resolve(['has_mp4_fallback' => true, 'mp4_resolutions' => '720p'])->reason);
    }

    public function test_returns_resolution_unavailable_when_all_resolutions_are_above_the_cap(): void
    {
        $this->fake([]);
        self::assertSame('resolution_unavailable', $this->resolve(['has_mp4_fallback' => true, 'mp4_resolutions' => '1080p,1440p'], '720')->reason);
    }

    public function test_fetches_and_caches_metadata_when_the_row_has_never_been_synced(): void
    {
        $this->fake([
            'https://video.bunnycdn.com/library/123/videos/guid-1' => self::api(['status' => 4, 'hasMP4Fallback' => true, 'availableResolutions' => '360p,720p']),
            'https://vz-abc.b-cdn.net/' => self::cdn(206),
        ]);
        $r = $this->resolve(['has_mp4_fallback' => null, 'mp4_resolutions' => null]);
        self::assertSame(720, $r->height);
        self::assertSame([[true, '360p,720p']], $this->saved);
    }

    public function test_does_not_re_fetch_bunny_on_every_request_once_cached_as_having_no_fallback(): void
    {
        $this->fake(['https://video.bunnycdn.com/library/123/videos/guid-1' => self::api(['status' => 4, 'hasMP4Fallback' => false])]);
        self::assertSame('mp4_unavailable', $this->resolve(['has_mp4_fallback' => null])->reason);
        self::assertSame([[false, null]], $this->saved);
        // The next request carries the cached answer.
        $this->fake([]);
        self::assertSame('mp4_unavailable', $this->resolve(['has_mp4_fallback' => false])->reason);
        self::assertSame([], $this->calls);
    }

    public function test_reports_a_provider_error_not_mp4_unavailable_when_the_metadata_fetch_fails(): void
    {
        $this->fake(['https://video.bunnycdn.com/library/123/videos/guid-1' => self::api(['Message' => 'nope'], 500)]);
        self::assertSame('provider_error', $this->resolve(['has_mp4_fallback' => null])->reason);
        self::assertSame([], $this->saved);
    }

    public function test_a_network_error_on_the_diagnostic_probe_does_not_block_a_download_bunny_confirmed(): void
    {
        $this->fake(['https://vz-abc.b-cdn.net/' => new HttpException('connection reset')]);
        $r = $this->resolve(['has_mp4_fallback' => true, 'mp4_resolutions' => '720p']);
        self::assertTrue($r->ok);
        self::assertSame(720, $r->height);
    }
}
