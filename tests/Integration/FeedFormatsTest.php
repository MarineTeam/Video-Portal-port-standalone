<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Db;
use App\Core\Id;

/**
 * The feeds other software reads, in the shapes that software expects.
 *
 * These are the outputs with a consumer that is not a person: a podcast app,
 * a calendar, a search engine, the television. A church that moves to this
 * port keeps the same addresses, so a subscriber's app must go on working
 * without anybody re-adding the feed — which means the shape is a contract
 * and not a rendering choice.
 */
final class FeedFormatsTest extends ServerTestCase
{
    protected static function prefix(): string
    {
        return 'feed_';
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::install();
        $db = self::connect(self::prefix());

        $series = Id::new();
        // A title somebody really typed, with the three characters that
        // break a feed if they reach it unescaped.
        $db->insert('series', [
            'id' => $series, 'title' => 'Romans 1 & 2 — "the gospel"', 'slug' => 'romans', 'published' => 1,
            'tags' => '[]', 'description' => 'Paul & the church at Rome <not a tag>',
        ]);
        $db->insert('videos', [
            'id' => Id::new(), 'title' => 'Romans 1', 'slug' => 'romans-1', 'provider' => 'direct',
            'series_id' => $series, 'published' => 1, 'status' => 'READY', 'scripture_refs' => '[]',
            'provider_data' => '{"url":"https://cdn.example.org/r1.mp4"}', 'duration_seconds' => 2400,
            'description' => 'The first two chapters',
        ]);
        // Audio in the series, which is what a podcast feed carries.
        $db->insert('file_assets', [
            'id' => Id::new(), 'title' => 'Romans 1 (audio)', 'backend' => 'local', 'storage_path' => 'files/r1.mp3',
            'mime_type' => 'audio/mpeg', 'size_bytes' => 12_345_678, 'published' => 1, 'series_id' => $series,
            'podcast_published' => 1,
        ]);
        $db->insert('events', [
            'id' => Id::new(), 'title' => 'Men’s breakfast; eggs & bacon', 'slug' => 'breakfast',
            'starts_at' => Db::datetime(new \DateTimeImmutable('+10 days')), 'published' => 1,
            'location' => "The hall\nSecond floor",
        ]);
        self::flushCache();
    }

    public function test_1_the_site_feed_is_rss_a_reader_can_parse(): void
    {
        $answer = self::http('GET', '/feed.xml', null, 'guest');
        self::assertSame(200, $answer['status']);
        self::assertStringContainsString('xml', (string) $answer['headers']['content-type']);

        $xml = @simplexml_load_string($answer['body']);
        self::assertNotFalse($xml, 'it parses as XML at all');
        self::assertSame('rss', $xml->getName());
        self::assertSame('2.0', (string) $xml['version']);
        self::assertNotEmpty($xml->channel->title);
        self::assertNotEmpty($xml->channel->item, 'and carries the published videos');
    }

    public function test_2_a_title_with_markup_in_it_cannot_break_the_feed(): void
    {
        // "Romans 1 & 2 — \"the gospel\"" is a real title somebody typed.
        $body = self::http('GET', '/feed.xml', null, 'guest')['body'];
        self::assertNotFalse(@simplexml_load_string($body));
        self::assertStringNotContainsString('Romans 1 & 2', $body, 'the ampersand is escaped');
        self::assertStringContainsString('Romans 1 &amp; 2', $body);
        self::assertStringNotContainsString('<not a tag>', $body);
    }

    public function test_3_the_podcast_feed_carries_what_a_podcast_app_needs(): void
    {
        $answer = self::http('GET', '/series/romans/podcast.xml', null, 'guest');
        self::assertSame(200, $answer['status'], (string) $answer['body']);

        $xml = @simplexml_load_string($answer['body']);
        self::assertNotFalse($xml);
        self::assertSame('rss', $xml->getName());
        // The iTunes namespace is what makes it a podcast rather than a blog.
        self::assertStringContainsString('itunes', (string) $answer['body']);

        $item = $xml->channel->item[0] ?? null;
        self::assertNotNull($item, 'an episode');
        $enclosure = $item->enclosure[0] ?? null;
        self::assertNotNull($enclosure, 'with a file on it');
        // An app will not play an episode whose enclosure has no length or
        // type; several will not even list it.
        self::assertNotSame('', (string) $enclosure['url']);
        self::assertSame('audio/mpeg', (string) $enclosure['type']);
        self::assertSame('12345678', (string) $enclosure['length']);
    }

    public function test_4_the_calendar_feed_is_icalendar_a_calendar_can_read(): void
    {
        $answer = self::http('GET', '/events/calendar.ics', null, 'guest');
        self::assertSame(200, $answer['status'], (string) $answer['body']);
        self::assertStringContainsString('text/calendar', (string) $answer['headers']['content-type']);

        $body = $answer['body'];
        self::assertStringStartsWith("BEGIN:VCALENDAR\r\n", $body, 'CRLF, as the format says');
        self::assertStringEndsWith("END:VCALENDAR\r\n", $body);
        self::assertStringContainsString("VERSION:2.0\r\n", $body);
        self::assertStringContainsString('PRODID:', $body);
        self::assertStringContainsString("BEGIN:VEVENT\r\n", $body);
        self::assertStringContainsString('UID:', $body);
        self::assertStringContainsString('DTSTAMP:', $body);
    }

    public function test_5_the_characters_icalendar_reserves_are_escaped(): void
    {
        $body = self::http('GET', '/events/calendar.ics', null, 'guest')['body'];
        // A semicolon, a comma and a newline all mean something in a
        // property value; unescaped, one event's location eats the next line.
        self::assertStringContainsString('Men’s breakfast\; eggs &', $body);
        self::assertStringContainsString('\\n', $body, 'the newline in the location is escaped, not sent');
        foreach (explode("\r\n", $body) as $line) {
            self::assertLessThanOrEqual(75, strlen($line), 'no line is longer than the format allows: ' . $line);
        }
    }

    public function test_6_the_sitemap_is_a_sitemap(): void
    {
        $answer = self::http('GET', '/sitemap.xml', null, 'guest');
        self::assertSame(200, $answer['status']);
        $xml = @simplexml_load_string($answer['body']);
        self::assertNotFalse($xml);
        self::assertSame('urlset', $xml->getName());
        self::assertSame('http://www.sitemaps.org/schemas/sitemap/0.9', (string) $xml->getNamespaces()['']);
        self::assertNotEmpty($xml->url);
        foreach ($xml->url as $url) {
            self::assertStringStartsWith('http', (string) $url->loc);
        }
    }

    public function test_7_the_manifest_is_json_a_browser_will_install(): void
    {
        $answer = self::http('GET', '/api/manifest', null, 'guest');
        self::assertSame(200, $answer['status']);
        $manifest = $answer['json'];
        self::assertIsArray($manifest);
        foreach (['name', 'short_name', 'start_url', 'display', 'icons'] as $key) {
            self::assertArrayHasKey($key, $manifest, $key);
        }
        self::assertNotEmpty($manifest['icons']);
    }

    public function test_8_none_of_them_is_cached_by_a_proxy_as_somebody_elses(): void
    {
        // These are public, but a signed-in reader's copy of a feed must not
        // be handed to the next caller by a shared cache.
        foreach (['/feed.xml', '/series/romans/podcast.xml', '/sitemap.xml'] as $path) {
            $headers = self::http('GET', $path, null, 'guest')['headers'];
            $cache = strtolower((string) ($headers['cache-control'] ?? ''));
            if ($cache !== '') {
                self::assertStringNotContainsString('private', $cache, "$path is public and says so");
            }
        }
    }
}
