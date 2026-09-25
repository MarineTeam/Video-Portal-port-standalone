<?php

declare(strict_types=1);

namespace Tests\Unit\Library;

use App\Core\Http;
use App\Core\HttpResponse;
use App\Modules\Library\Admin\MediaCheckAdmin;
use PHPUnit\Framework\TestCase;

/** The media check's link probe: what counts as a link that still serves a video. */
final class MediaCheckProbeTest extends TestCase
{
    protected function setUp(): void
    {
        Http::fakeResolver(fn (string $host) => $host === 'lan.example.org' ? ['192.168.1.20'] : ['93.184.216.34']);
    }

    protected function tearDown(): void
    {
        Http::fake(null);
        Http::fakeResolver(null);
    }

    private function answer(int $status, string $type): void
    {
        Http::fake(fn (string $m) => new HttpResponse($status, $type === '' ? [] : ['content-type' => $type], ''));
    }

    public function test_a_video_that_answers_is_ok(): void
    {
        $this->answer(200, 'video/mp4; codecs="avc1"');
        self::assertSame(['ok' => true, 'detail' => 'video/mp4'], MediaCheckAdmin::probe('https://cdn.example.org/a.mp4'));
        $this->answer(200, 'application/octet-stream');
        self::assertTrue(MediaCheckAdmin::probe('https://cdn.example.org/a.mp4')['ok']);
    }

    public function test_an_error_a_web_page_or_no_address_is_broken(): void
    {
        $this->answer(404, 'text/html');
        self::assertSame(['ok' => false, 'detail' => 'Answered 404.'], MediaCheckAdmin::probe('https://cdn.example.org/a.mp4'));
        $this->answer(200, 'text/html');
        self::assertStringContainsString('not a video', MediaCheckAdmin::probe('https://cdn.example.org/a.mp4')['detail']);
        self::assertFalse(MediaCheckAdmin::probe('')['ok']);
    }

    public function test_a_private_address_is_never_asked(): void
    {
        Http::fake(fn () => throw new \LogicException('asked'));
        $r = MediaCheckAdmin::probe('https://lan.example.org/a.mp4');
        self::assertFalse($r['ok']);
        self::assertStringNotContainsString('asked', $r['detail']);
    }
}
