<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * The bytes, over HTTP, as a browser asks for them.
 *
 * RangeStreamerTest settles the arithmetic: which byte a range starts at,
 * what Content-Range says, when a request is refused. What it cannot check
 * is whether the body that follows those headers is really what they promise
 * — streaming clears PHP's output buffers on purpose, so there is nothing in
 * process to catch it with, and a Content-Length that disagrees with the
 * bytes after it is exactly the failure a browser reports as a broken
 * connection rather than as a wrong answer.
 *
 * So this asks a real server, and looks at the bytes. The file's byte i is
 * i modulo 251 — a prime, so it lines up with no chunk size — which means an
 * assertion can say which part of the file came back and not merely how much.
 */
final class FileServingTest extends ServerTestCase
{
    protected static function prefix(): string
    {
        return 'srv_';
    }

    private const SIZE = 3000;
    private const PATH = '/media/covers/00112233445566778899aabbccddeeff.png';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::install();
        $dir = self::$storage . '/media/covers';
        mkdir($dir, 0775, true);
        $bytes = '';
        for ($i = 0; $i < self::SIZE; $i++) {
            $bytes .= chr($i % 251);
        }
        file_put_contents($dir . '/00112233445566778899aabbccddeeff.png', $bytes);
    }

    /** @return array{status: int, body: string, headers: array<string, string>} */
    private static function fetch(?string $range, string $method = 'GET'): array
    {
        $r = self::http($method, self::PATH, null, 'nobody', $range === null ? [] : ['Range' => $range]);
        return ['status' => $r['status'], 'body' => $r['body'], 'headers' => $r['headers']];
    }

    private static function assertBytes(string $body, int $from, int $to, string $why): void
    {
        self::assertSame($to - $from + 1, strlen($body), "$why: how many bytes");
        for ($i = 0; $i < strlen($body); $i++) {
            if (ord($body[$i]) !== ($from + $i) % 251) {
                self::fail("$why: byte " . ($from + $i) . ' of the file is not what came back at offset ' . $i);
            }
        }
    }

    public function test_1_the_whole_file(): void
    {
        $r = self::fetch(null);
        self::assertSame(200, $r['status']);
        self::assertSame('bytes', $r['headers']['accept-ranges'] ?? null);
        self::assertSame((string) self::SIZE, $r['headers']['content-length'] ?? null);
        self::assertBytes($r['body'], 0, self::SIZE - 1, 'no range asked for');
    }

    public function test_2_a_slice_is_the_slice_it_says(): void
    {
        foreach ([[0, 0], [0, 99], [1000, 1999], [2999, 2999], [1, 2]] as [$from, $to]) {
            $r = self::fetch("bytes=$from-$to");
            self::assertSame(206, $r['status'], "bytes=$from-$to");
            self::assertSame("bytes $from-$to/" . self::SIZE, $r['headers']['content-range'] ?? null);
            self::assertSame((string) ($to - $from + 1), $r['headers']['content-length'] ?? null);
            self::assertBytes($r['body'], $from, $to, "bytes=$from-$to");
        }
    }

    public function test_3_the_tail_a_player_asks_for_first(): void
    {
        // An MP4 keeps the index at the end, so this is the first thing a
        // video player asks of a file it is about to play. Read as "the
        // first 500 bytes" it hands over the wrong part and nothing plays.
        $r = self::fetch('bytes=-500');
        self::assertSame(206, $r['status']);
        self::assertSame('bytes 2500-2999/' . self::SIZE, $r['headers']['content-range'] ?? null);
        self::assertBytes($r['body'], 2500, 2999, 'the last 500 bytes');

        $all = self::fetch('bytes=-99999');
        self::assertSame('bytes 0-2999/' . self::SIZE, $all['headers']['content-range'] ?? null);
        self::assertBytes($all['body'], 0, self::SIZE - 1, 'more tail than there is file');
    }

    public function test_4_an_open_ended_range_runs_to_the_end(): void
    {
        $r = self::fetch('bytes=2900-');
        self::assertSame('bytes 2900-2999/' . self::SIZE, $r['headers']['content-range'] ?? null);
        self::assertBytes($r['body'], 2900, self::SIZE - 1, 'bytes=2900-');
    }

    public function test_5_a_range_past_the_end_sends_what_there_is_and_says_so(): void
    {
        // The length in the header and the length of the body have to agree.
        // A browser that is promised 911 bytes and given 10 does not show a
        // small file; it reports the connection as broken.
        $r = self::fetch('bytes=2990-99999');
        self::assertSame(206, $r['status']);
        self::assertSame('bytes 2990-2999/' . self::SIZE, $r['headers']['content-range'] ?? null);
        self::assertSame('10', $r['headers']['content-length'] ?? null);
        self::assertBytes($r['body'], 2990, 2999, 'clamped to the end of the file');
    }

    public function test_6_a_range_wholly_past_the_end_is_refused(): void
    {
        foreach (['bytes=3000-3100', 'bytes=9999-', 'bytes=500-100'] as $range) {
            $r = self::fetch($range);
            self::assertSame(416, $r['status'], $range);
            self::assertSame('bytes */' . self::SIZE, $r['headers']['content-range'] ?? null, $range);
            self::assertSame('', $r['body'], "$range: and nothing with it");
        }
    }

    public function test_7_a_header_it_cannot_read_gets_the_whole_file(): void
    {
        foreach (['bytes=0-10, 20-30', 'items=0-10', 'bytes=abc-def', 'nonsense'] as $range) {
            $r = self::fetch($range);
            self::assertSame(200, $r['status'], $range);
            self::assertBytes($r['body'], 0, self::SIZE - 1, $range);
        }
    }

    public function test_8_a_head_is_the_headers_of_the_get_with_no_body(): void
    {
        $get = self::fetch('bytes=100-199');
        $head = self::fetch('bytes=100-199', 'HEAD');
        self::assertSame(206, $head['status']);
        self::assertSame($get['headers']['content-length'], $head['headers']['content-length'] ?? null);
        self::assertSame($get['headers']['content-range'], $head['headers']['content-range'] ?? null);
        self::assertSame('', $head['body']);
    }

    public function test_9_a_file_the_browser_already_has_is_not_sent_again(): void
    {
        $first = self::fetch(null);
        $etag = $first['headers']['etag'] ?? '';
        self::assertNotSame('', $etag);

        $again = self::http('GET', self::PATH, null, 'nobody', ['If-None-Match' => $etag]);
        self::assertSame(304, $again['status']);
        self::assertSame('', $again['body']);

        $stale = self::http('GET', self::PATH, null, 'nobody', ['If-None-Match' => '"nothing-like-it"']);
        self::assertSame(200, $stale['status']);
        self::assertBytes($stale['body'], 0, self::SIZE - 1, 'a tag that does not match');
    }

    public function test_10_what_is_not_served(): void
    {
        foreach ([
            '/media/covers/not-hex.png' => 'a name that is not one of ours',
            '/media/covers/00112233445566778899aabbccddeeff.php' => 'an extension that is not an image',
            '/media/../config.php' => 'walking out of the media folder',
            '/media/covers/../../config.php' => 'walking out from further in',
        ] as $path => $why) {
            $r = self::http('GET', $path, null, 'nobody');
            self::assertNotSame(200, $r['status'], $why);
            self::assertStringNotContainsString('app_key', $r['body'], $why);
        }
    }
}
