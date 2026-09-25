<?php

declare(strict_types=1);

namespace Tests\Unit\Video;

use App\Core\HttpResponse;
use App\Services\Video\Bunny;
use App\Services\Video\VideoProviderException;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** lib/bunny.test.ts */
final class BunnyTest extends TestCase
{
    #[TestDox('parseBunnyResolutions returns nothing for an empty or missing string')]
    public function test_parse_empty(): void
    {
        $this->assertSame([], Bunny::parseResolutions(null));
        $this->assertSame([], Bunny::parseResolutions(''));
    }

    #[TestDox("parseBunnyResolutions parses a comma list, highest first, regardless of Bunny's order")]
    public function test_parse_order(): void
    {
        $this->assertSame([720, 480, 360, 240], Bunny::parseResolutions('240p,720p,360p,480p'));
    }

    #[TestDox("parseBunnyResolutions drops resolutions we don't have an MP4 constant for")]
    public function test_parse_unknown(): void
    {
        $this->assertSame([720, 360], Bunny::parseResolutions('360p,720p,1440p,2160p,foo,144p'));
    }

    #[TestDox('parseBunnyResolutions de-duplicates')]
    public function test_parse_dedupe(): void
    {
        $this->assertSame([480, 240], Bunny::parseResolutions('480p,480p, 240p,240p'));
    }

    #[TestDox('selectMp4Height picks the highest available at or under the default 720p cap')]
    public function test_select_default_cap(): void
    {
        $this->assertSame(720, Bunny::selectMp4Height([1080, 720, 480]));
    }

    #[TestDox("selectMp4Height steps down when the cap isn't available — the 480p-only case")]
    public function test_select_steps_down(): void
    {
        $this->assertSame(480, Bunny::selectMp4Height([480, 360]));
    }

    #[TestDox('selectMp4Height respects a configured maximum lower than what\'s available')]
    public function test_select_lower_cap(): void
    {
        $this->assertSame(360, Bunny::selectMp4Height([720, 480, 360], 360));
    }

    #[TestDox('selectMp4Height returns null when nothing available is at or under the cap')]
    public function test_select_nothing_under(): void
    {
        $this->assertNull(Bunny::selectMp4Height([1080, 720], 480));
    }

    #[TestDox("selectMp4Height returns null when there's nothing to choose from")]
    public function test_select_empty(): void
    {
        $this->assertNull(Bunny::selectMp4Height([]));
    }

    #[TestDox('downloadHeight defaults to 720 when unset or invalid')]
    public function test_download_height_default(): void
    {
        foreach ([null, '', 'abc', 0, 555, '2160'] as $bad) {
            $this->assertSame(720, Bunny::downloadHeight($bad));
        }
    }

    #[TestDox('downloadHeight honors a valid configured height')]
    public function test_download_height_valid(): void
    {
        $this->assertSame(480, Bunny::downloadHeight('480'));
        $this->assertSame(1080, Bunny::downloadHeight(1080));
    }

    #[TestDox('bunnyStreamMp4Url builds the correct Bunny MP4 fallback path for the selected height')]
    public function test_mp4_url(): void
    {
        $this->assertSame('https://vz-abc-123.b-cdn.net/0f2e/play_480p.mp4', Bunny::mp4Url('vz-abc-123.b-cdn.net', '0f2e', 480));
        $this->assertSame('https://vz-abc-123.b-cdn.net/0f2e/play_720p.mp4', Bunny::mp4Url('https://vz-abc-123.b-cdn.net/', '0f2e', 720));
    }

    #[TestDox('bunnyStreamMp4Url never defaults to 720p — height is required')]
    public function test_mp4_url_height_required(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Bunny::mp4Url('vz-abc-123.b-cdn.net', '0f2e', 0);
    }

    #[TestDox("bunnyStreamMp4Url throws rather than returning a broken URL when the hostname isn't configured")]
    public function test_mp4_url_no_host(): void
    {
        $this->expectException(VideoProviderException::class);
        Bunny::mp4Url('', '0f2e', 720);
    }

    private static function fetch(int $status): callable
    {
        return fn (string $u) => new HttpResponse($status, [], '');
    }

    #[TestDox('probeBunnyMp4 classifies 403/401 as forbidden, not missing')]
    public function test_probe_forbidden(): void
    {
        $this->assertSame('forbidden', Bunny::probeMp4('https://x/y.mp4', self::fetch(403)));
        $this->assertSame('forbidden', Bunny::probeMp4('https://x/y.mp4', self::fetch(401)));
    }

    #[TestDox('probeBunnyMp4 classifies 404/410 as missing')]
    public function test_probe_missing(): void
    {
        $this->assertSame('missing', Bunny::probeMp4('https://x/y.mp4', self::fetch(404)));
        $this->assertSame('missing', Bunny::probeMp4('https://x/y.mp4', self::fetch(410)));
    }

    #[TestDox('probeBunnyMp4 classifies a 200/206 as ok')]
    public function test_probe_ok(): void
    {
        $this->assertSame('ok', Bunny::probeMp4('https://x/y.mp4', self::fetch(200)));
        $this->assertSame('ok', Bunny::probeMp4('https://x/y.mp4', self::fetch(206)));
    }

    #[TestDox('probeBunnyMp4 classifies a network failure or 5xx as a generic error, not missing')]
    public function test_probe_error(): void
    {
        $this->assertSame('error', Bunny::probeMp4('https://x/y.mp4', self::fetch(503)));
        $this->assertSame('error', Bunny::probeMp4('https://x/y.mp4', fn () => throw new \RuntimeException('down')));
        $this->assertSame('error', Bunny::probeMp4('https://x/y.mp4', fn () => null));
    }

    #[TestDox('probeBunnyMp4 treats an empty URL as an error rather than fetching')]
    public function test_probe_empty(): void
    {
        $fetched = false;
        $this->assertSame('error', Bunny::probeMp4('', function () use (&$fetched) {
            $fetched = true;
            return new HttpResponse(200, [], '');
        }));
        $this->assertFalse($fetched);
    }

    public function test_signing(): void
    {
        $this->assertSame(hash('sha256', 'keyvid1700000000'), Bunny::embedToken('key', 'vid', 1700000000));
        $this->assertSame(hash('sha256', '123secret1700000000vid'), Bunny::tusSignature('123', 'secret', 1700000000, 'vid'));
        $signed = Bunny::signCdnUrl('https://vz.b-cdn.net/vid/thumbnail.jpg', 'k', 1700000000);
        $expected = rtrim(strtr(base64_encode(hash('sha256', 'k/vid/thumbnail.jpg1700000000', true)), '+/', '-_'), '=');
        $this->assertSame('https://vz.b-cdn.net/vid/thumbnail.jpg?token=' . $expected . '&expires=1700000000', $signed);
        $this->assertSame('https://vz.b-cdn.net/a.jpg', Bunny::signCdnUrl('https://vz.b-cdn.net/a.jpg', null, 1), 'unsigned without a key');
        $this->assertSame(Bunny::expiry(3600, 1700000000), Bunny::expiry(3600, 1700000030), 'stable within a step');
        $this->assertSame(['READY', 'FAILED', 'PROCESSING'], [Bunny::status(4), Bunny::status(5), Bunny::status(2)]);
    }
}
