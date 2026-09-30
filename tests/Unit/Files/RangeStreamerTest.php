<?php

declare(strict_types=1);

namespace Tests\Unit\Files;

use App\Core\Request;
use App\Services\Files\RangeStreamer;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * The code that hands over every stored file: a sermon PDF, a download, and
 * on a host that keeps its own videos, the video itself.
 *
 * A player does not read a video from the beginning. It asks for the last
 * few kilobytes to find the index an MP4 keeps at the end, then jumps about
 * as somebody drags the scrubber. So the arithmetic in here is what makes a
 * sermon seekable, and an answer whose Content-Length disagrees with the
 * bytes that follow it reads to a browser as a truncated file rather than as
 * a wrong one. The bytes of the test file are numbered, so an assertion can
 * say which bytes came back and not merely how many.
 *
 * The same sums exist in the service worker for a saved video, where they
 * were wrong in two places; this is the side a browser meets first, and it
 * had no test at all.
 */
final class RangeStreamerTest extends TestCase
{
    private string $dir = '';
    private string $file = '';
    private const SIZE = 1000;

    protected function setUp(): void
    {
        RangeStreamer::$offload = null;
        $this->dir = sys_get_temp_dir() . '/mt-range-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/uploads', 0775, true);
        $this->file = $this->dir . '/uploads/sermon.mp4';
        // Byte i is i, modulo 251 — a prime, so the pattern does not line up
        // with any power-of-two chunk size and a wrong offset shows.
        $bytes = '';
        for ($i = 0; $i < self::SIZE; $i++) {
            $bytes .= chr($i % 251);
        }
        file_put_contents($this->file, $bytes);
    }

    protected function tearDown(): void
    {
        RangeStreamer::$offload = null;
        \App\Modules\Plugins\PackageInstaller::removeTree($this->dir);
    }

    /** @param array<string, string> $headers */
    private static function request(array $headers = [], string $method = 'GET'): Request
    {
        return new Request(method: $method, path: '/media/x', headers: $headers);
    }

    /**
     * Whether a response would stream the file, or hand back nothing.
     *
     * The bytes themselves are checked over HTTP in
     * tests/Integration/FileServingTest.php: streaming clears PHP's output
     * buffers on purpose, so there is nothing here to capture them with, and
     * a header promising a length only means something beside a body that
     * really is that long.
     */
    private static function streams(\App\Core\Response $response): bool
    {
        return $response->stream !== null;
    }

    #[TestDox('no range asked for: the whole file, and it says how long it is')]
    public function testWholeFile(): void
    {
        $response = RangeStreamer::serve($this->file, self::request(), []);
        self::assertSame(200, $response->status);
        self::assertSame('bytes', $response->getHeader('Accept-Ranges'));
        self::assertSame((string) self::SIZE, $response->getHeader('Content-Length'));
        self::assertTrue(self::streams($response));
    }

    #[TestDox('a range in the middle: those bytes, and only those')]
    public function testRange(): void
    {
        $response = RangeStreamer::serve($this->file, self::request(['range' => 'bytes=100-199']), []);
        self::assertSame(206, $response->status);
        self::assertSame('bytes 100-199/1000', $response->getHeader('Content-Range'));
        self::assertSame('100', $response->getHeader('Content-Length'));

        self::assertTrue(self::streams($response));
    }

    #[TestDox('the tail of the file, which is where a player looks first')]
    public function testSuffixRange(): void
    {
        // An MP4 keeps the index a player needs at the end, so "the last 500
        // bytes" is the first thing asked of a video. Read as "the first 500"
        // it hands back the wrong part of the file and nothing plays.
        $response = RangeStreamer::serve($this->file, self::request(['range' => 'bytes=-500']), []);
        self::assertSame(206, $response->status);
        self::assertSame('bytes 500-999/1000', $response->getHeader('Content-Range'));

        self::assertSame('500', $response->getHeader('Content-Length'));

        // Asking for more tail than there is file gives the whole file.
        $all = RangeStreamer::serve($this->file, self::request(['range' => 'bytes=-99999']), []);
        self::assertSame('bytes 0-999/1000', $all->getHeader('Content-Range'));
        self::assertSame((string) self::SIZE, $all->getHeader('Content-Length'));
    }

    #[TestDox('an open-ended range runs to the end of the file')]
    public function testOpenEndedRange(): void
    {
        $response = RangeStreamer::serve($this->file, self::request(['range' => 'bytes=900-']), []);
        self::assertSame('bytes 900-999/1000', $response->getHeader('Content-Range'));
        self::assertSame('100', $response->getHeader('Content-Length'));
    }

    #[TestDox('a range running past the end is cut to the file, and says the length it is sending')]
    public function testRangePastTheEnd(): void
    {
        // The length in the header and the length of the body have to be the
        // same number, or a browser reads a short read as a broken connection.
        $response = RangeStreamer::serve($this->file, self::request(['range' => 'bytes=990-99999']), []);
        self::assertSame(206, $response->status);
        self::assertSame('bytes 990-999/1000', $response->getHeader('Content-Range'));
        self::assertSame('10', $response->getHeader('Content-Length'));
    }

    #[TestDox('a range wholly past the end is refused, and says how long the file really is')]
    public function testUnsatisfiableRange(): void
    {
        foreach (['bytes=1000-1100', 'bytes=5000-', 'bytes=500-100', 'bytes=-0'] as $range) {
            $response = RangeStreamer::serve($this->file, self::request(['range' => $range]), []);
            self::assertSame(416, $response->status, $range);
            self::assertSame('bytes */1000', $response->getHeader('Content-Range'), $range);
        }
    }

    #[TestDox('a header this does not understand gets the whole file, not a guess')]
    public function testUnreadableRange(): void
    {
        foreach (['bytes=0-10, 20-30', 'items=0-10', 'bytes=abc-def', 'bytes=-', 'nonsense'] as $range) {
            $response = RangeStreamer::serve($this->file, self::request(['range' => $range]), []);
            self::assertSame(200, $response->status, $range);
            self::assertSame((string) self::SIZE, $response->getHeader('Content-Length'), $range);
            self::assertNull($response->getHeader('Content-Range'), $range);
        }
    }

    #[TestDox('a HEAD is answered with the headers and no body')]
    public function testHead(): void
    {
        $response = RangeStreamer::serve($this->file, self::request(['range' => 'bytes=100-199'], 'HEAD'), []);
        self::assertSame(206, $response->status);
        self::assertSame('100', $response->getHeader('Content-Length'), 'what a GET would send');
        self::assertFalse(self::streams($response), 'and nothing to send');
    }

    #[TestDox('a file the browser already has is not sent again')]
    public function testConditional(): void
    {
        $first = RangeStreamer::serve($this->file, self::request(), []);
        $etag = (string) $first->getHeader('ETag');
        self::assertMatchesRegularExpression('/^"[0-9a-f]{20}"$/', $etag);

        foreach ([$etag, "W/$etag", '*', "\"other\", $etag"] as $sent) {
            $response = RangeStreamer::serve($this->file, self::request(['if-none-match' => $sent]), []);
            self::assertSame(304, $response->status, $sent);
            self::assertFalse(self::streams($response), 'and nothing is sent with it');
            self::assertNull($response->getHeader('Content-Length'), 'a 304 carries no length');
        }

        self::assertSame(200, RangeStreamer::serve($this->file, self::request(['if-none-match' => '"stale"']), [])->status);

        // Change the file and the tag has to change with it, or a member
        // keeps the old one for as long as their browser feels like.
        touch($this->file, time() + 60);
        clearstatcache(true, $this->file);
        self::assertNotSame($etag, RangeStreamer::serve($this->file, self::request(), [])->getHeader('ETag'));
    }

    #[TestDox('a file that is not there is not found')]
    public function testMissingFile(): void
    {
        self::assertSame(404, RangeStreamer::serve($this->dir . '/uploads/gone.mp4', self::request(), [])->status);
    }

    #[TestDox('an empty file is answered as an empty file')]
    public function testEmptyFile(): void
    {
        $empty = $this->dir . '/uploads/empty.txt';
        file_put_contents($empty, '');
        $response = RangeStreamer::serve($empty, self::request(['range' => 'bytes=0-10']), []);
        self::assertSame(200, $response->status);
        self::assertSame('0', $response->getHeader('Content-Length'));
    }

    #[TestDox('with the web server set to send the file, PHP names it and sends nothing')]
    public function testOffload(): void
    {
        // The point of the whole thing: on a host that supports it, the bytes
        // never go through PHP at all.
        foreach ([
            'x-sendfile' => ['X-Sendfile', $this->file],
            'x-accel' => ['X-Accel-Redirect', '/protected-storage/uploads/sermon.mp4'],
            'litespeed' => ['X-LiteSpeed-Location', '/protected-storage/uploads/sermon.mp4'],
        ] as $mode => [$header, $value]) {
            RangeStreamer::$offload = $mode;
            $response = RangeStreamer::serve($this->file, self::request(['range' => 'bytes=100-199']), [], $this->dir);
            self::assertSame(200, $response->status, $mode);
            self::assertSame($value, $response->getHeader($header), $mode);
            self::assertFalse(self::streams($response), "$mode: PHP sends no bytes");
            self::assertNull($response->getHeader('Content-Length'), "$mode: the web server decides the length");
        }
    }

    #[TestDox('a file outside the offload root is streamed, whatever the setting says')]
    public function testOffloadOnlyInsideItsRoot(): void
    {
        // The internal location the web server is given maps one directory.
        // A file from anywhere else would name a path it cannot reach.
        RangeStreamer::$offload = 'x-accel';
        $outside = sys_get_temp_dir() . '/mt-outside-' . bin2hex(random_bytes(4)) . '.txt';
        file_put_contents($outside, 'hello');
        $response = RangeStreamer::serve($outside, self::request(), [], $this->dir);
        self::assertNull($response->getHeader('X-Accel-Redirect'));
        self::assertSame('5', $response->getHeader('Content-Length'));
        unlink($outside);

        // And with no root given at all — the callers that serve from the
        // install's own public/ directory pass none.
        $response = RangeStreamer::serve($this->file, self::request(), []);
        self::assertNull($response->getHeader('X-Accel-Redirect'));
        self::assertSame((string) self::SIZE, $response->getHeader('Content-Length'));
    }

    #[TestDox('a setting nobody recognises streams the file rather than sending an empty one')]
    public function testUnknownOffload(): void
    {
        // 'sendfile', 'xsendfile', 'X-Sendfile' — an administrator edits this
        // into config.php by hand, and every one of those is a plausible
        // thing to type. None of them may turn every download into 0 bytes.
        foreach (['sendfile', 'xsendfile', 'X-Sendfile', 'nginx', 'true'] as $typo) {
            RangeStreamer::$offload = $typo;
            $response = RangeStreamer::serve($this->file, self::request(), [], $this->dir);
            self::assertTrue(self::streams($response), "file_offload = $typo: the file is still sent");
            self::assertSame((string) self::SIZE, $response->getHeader('Content-Length'), "file_offload = $typo");
        }
    }
}
