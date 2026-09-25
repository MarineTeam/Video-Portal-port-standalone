<?php

declare(strict_types=1);

namespace Tests\Unit\Library;

use App\Modules\Library\VideoSource;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** lib/video-source.test.ts */
final class VideoSourceTest extends TestCase
{
    private const YT = ['provider' => 'youtube', 'external_id' => 'dQw4w9WgXcQ'];
    private const VIMEO = ['provider' => 'vimeo', 'external_id' => '76979871'];
    private const BUNNY = ['provider' => 'bunny', 'external_id' => 'b1a2c3', 'bunny_library_id' => '123'];

    #[TestDox("videoEmbedUrl uses YouTube's no-cookie player and turns off related videos")]
    public function test_youtube_no_cookie(): void
    {
        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?rel=0', VideoSource::embedUrl(self::YT));
    }

    #[TestDox("videoEmbedUrl carries a start time into each player's own way of taking one")]
    public function test_start_time(): void
    {
        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?rel=0&start=90', VideoSource::embedUrl(self::YT, 90));
        $this->assertSame('https://player.vimeo.com/video/76979871?dnt=1#t=90s', VideoSource::embedUrl(self::VIMEO, 90));
        $this->assertSame('bunny:b1a2c3@90', VideoSource::embedUrl(self::BUNNY, 90, fn (string $id, int $s) => "bunny:$id@$s"));
    }

    #[TestDox('videoEmbedUrl leaves the start off entirely at zero rather than sending start=0')]
    public function test_zero_start(): void
    {
        $this->assertStringNotContainsString('start', VideoSource::embedUrl(self::YT, 0));
        $this->assertStringNotContainsString('#t=', VideoSource::embedUrl(self::VIMEO, 0));
    }

    #[TestDox('videoEmbedUrl asks Vimeo not to track')]
    public function test_vimeo_dnt(): void
    {
        $this->assertStringContainsString('dnt=1', VideoSource::embedUrl(self::VIMEO));
    }

    #[TestDox('videoEmbedUrl escapes an id rather than pasting it into a URL')]
    public function test_escapes_id(): void
    {
        $url = VideoSource::embedUrl(['provider' => 'youtube', 'external_id' => 'x"><script>&y=1']);
        $this->assertStringNotContainsString('<', $url);
        $this->assertStringNotContainsString('&y=1', $url);
        $this->assertStringContainsString('x%22%3E%3Cscript%3E%26y%3D1', $url);
    }

    #[TestDox('videoEmbedUrl gives nothing for a source with no id, rather than a broken frame')]
    public function test_no_id(): void
    {
        $this->assertSame('', VideoSource::embedUrl(['provider' => 'youtube', 'external_id' => null]));
        $this->assertSame('', VideoSource::embedUrl(['provider' => 'bunny', 'external_id' => ''], 0, fn () => 'x'));
    }

    #[TestDox('videoThumbnailUrl uses the one the source gave us')]
    public function test_thumbnail_given(): void
    {
        $this->assertSame('https://i.ytimg.com/vi/x/hqdefault.jpg', VideoSource::thumbnailUrl(self::YT + ['external_thumbnail_url' => 'https://i.ytimg.com/vi/x/hqdefault.jpg']));
    }

    #[TestDox('videoThumbnailUrl returns an empty string rather than a broken image')]
    public function test_thumbnail_empty(): void
    {
        $this->assertSame('', VideoSource::thumbnailUrl(self::YT));
    }

    #[TestDox('videoThumbnailUrl, for a video stored here, asks Bunny rather than calling itself')]
    public function test_thumbnail_bunny(): void
    {
        $this->assertSame('https://cdn.example/b1a2c3/thumbnail_2.jpg', VideoSource::thumbnailUrl(self::BUNNY + ['thumbnail_file_name' => 'thumbnail_2.jpg'], fn (string $id, ?string $f) => "https://cdn.example/$id/$f"));
    }

    #[TestDox('isBunnyVideo is what gates the Bunny-only features')]
    public function test_is_bunny(): void
    {
        $this->assertTrue(VideoSource::isBunnyVideo(self::BUNNY));
        $this->assertFalse(VideoSource::isBunnyVideo(self::YT));
        $this->assertFalse(VideoSource::isBunnyVideo(['provider' => 'bunny', 'external_id' => null]));
    }

    #[TestDox('watchAtSourceUrl points at the page a person would land on')]
    public function test_watch_at_source(): void
    {
        $this->assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', VideoSource::watchAtSourceUrl(self::YT));
        $this->assertSame('https://vimeo.com/76979871', VideoSource::watchAtSourceUrl(self::VIMEO));
        $this->assertSame('https://youtu.be/abc', VideoSource::watchAtSourceUrl(self::YT + ['external_url' => 'https://youtu.be/abc']));
    }

    #[TestDox('watchAtSourceUrl has nowhere to send somebody for a video that lives here')]
    public function test_watch_at_source_bunny(): void
    {
        $this->assertNull(VideoSource::watchAtSourceUrl(self::BUNNY));
    }

    #[TestDox('sourceName names the three')]
    public function test_source_name(): void
    {
        $this->assertSame(['Bunny Stream', 'YouTube', 'Vimeo'], [VideoSource::sourceName('BUNNY'), VideoSource::sourceName('YOUTUBE'), VideoSource::sourceName('VIMEO')]);
        $this->assertSame(['BUNNY', 'YOUTUBE', 'VIMEO'], [VideoSource::source(self::BUNNY), VideoSource::source(self::YT), VideoSource::source(self::VIMEO)]);
    }
}
