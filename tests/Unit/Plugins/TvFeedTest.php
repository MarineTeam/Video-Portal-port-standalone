<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins;

use MarineTeam\Plugins\Tv\Feed;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/plugins/tv/src/Feed.php';

/**
 * The catalogue a television platform reads (lib/tv-feed.test.ts).
 */
final class TvFeedTest extends TestCase
{
    private const SITE = ['title' => 'Grace Church', 'language' => 'en', 'url' => 'https://church.example.org'];

    /** @return array<string, mixed> */
    private function video(array $extra = []): array
    {
        return $extra + [
            'id' => 'vid1',
            'title' => 'The Sermon on the Mount',
            'description' => 'Matthew 5, the first of four.',
            'durationSeconds' => 2456,
            'publishAt' => '2026-03-08 10:30:00',
            'createdAt' => '2026-05-01 09:00:00',
            'streamUrl' => 'https://vz-1.b-cdn.net/vid1/playlist.m3u8',
            'externalUrl' => null,
            'thumbnailUrl' => 'https://vz-1.b-cdn.net/vid1/thumbnail.jpg',
            'pageUrl' => 'https://church.example.org/videos/sermon-on-the-mount',
            'seriesTitle' => 'The Sermon on the Mount',
            'speakerName' => 'Devin Liao',
            'language' => 'en',
        ];
    }

    // -- isFeedable ---------------------------------------------------------

    public function testItTakesAnOrdinaryVideo(): void
    {
        $this->assertTrue(Feed::isFeedable($this->video()));
    }

    public function testItLeavesOutOneWithNoDurationRatherThanHavingTheFeedRejected(): void
    {
        $this->assertFalse(Feed::isFeedable($this->video(['durationSeconds' => null])));
        $this->assertFalse(Feed::isFeedable($this->video(['durationSeconds' => 0])));
    }

    public function testItLeavesOutAnImportedVideoWithNowhereToPlayIt(): void
    {
        $this->assertFalse(Feed::isFeedable($this->video(['streamUrl' => null, 'externalUrl' => null])));
        $this->assertTrue(
            Feed::isFeedable($this->video(['streamUrl' => null, 'externalUrl' => 'https://www.youtube.com/watch?v=abc'])),
            'but one with the source’s own page is describable',
        );
    }

    // -- The pieces ---------------------------------------------------------

    public function testTheDurationIsAWholeNumberOfSeconds(): void
    {
        $this->assertSame(2456, Feed::feedDuration($this->video()));
        $this->assertSame(91, Feed::feedDuration($this->video(['durationSeconds' => 90.6])));
        $this->assertSame(0, Feed::feedDuration($this->video(['durationSeconds' => 'soon'])));
    }

    public function testTheDatePrefersThePublishDateToWhenTheRowHappenedToBeMade(): void
    {
        $this->assertSame('2026-03-08T10:30:00Z', Feed::feedDate($this->video()));
        $this->assertSame('2026-05-01T09:00:00Z', Feed::feedDate($this->video(['publishAt' => null])));
    }

    public function testTheStreamKindIsReadFromTheUrl(): void
    {
        $this->assertSame('HLS', Feed::streamKind($this->video()));
        $this->assertSame('MP4', Feed::streamKind($this->video(['streamUrl' => 'https://files.example.org/a.mp4'])));
    }

    // -- rokuFeed -----------------------------------------------------------

    /** @return array<string, mixed> */
    private function firstItem(array $videos): array
    {
        $feed = Feed::rokuFeed($videos, self::SITE, '2026-06-10 06:00:00');
        return $feed['shortFormVideos'][0] ?? [];
    }

    public function testItDescribesEachVideoTheWayDirectPublisherExpects(): void
    {
        $feed = Feed::rokuFeed([$this->video()], self::SITE, '2026-06-10 06:00:00');
        $this->assertSame('Grace Church', $feed['providerName']);
        $this->assertSame('2026-06-10T06:00:00Z', $feed['lastUpdated']);
        $item = $feed['shortFormVideos'][0];
        $this->assertSame('vid1', $item['id']);
        $this->assertSame('2026-03-08', $item['releaseDate']);
        $this->assertSame(2456, $item['content']['duration']);
        $this->assertSame('HLS', $item['content']['videos'][0]['videoType']);
        $this->assertSame('https://vz-1.b-cdn.net/vid1/playlist.m3u8', $item['content']['videos'][0]['url']);
    }

    public function testItPointsAtTheSourcesOwnUrlForAnImportedVideo(): void
    {
        $item = $this->firstItem([$this->video(['streamUrl' => null, 'externalUrl' => 'https://www.youtube.com/watch?v=abc'])]);
        $this->assertSame('https://www.youtube.com/watch?v=abc', $item['content']['videos'][0]['url']);
    }

    public function testItNeverSendsAnEmptyDescriptionWhichFailsTheirValidation(): void
    {
        $item = $this->firstItem([$this->video(['description' => null])]);
        $this->assertSame(Feed::FALLBACK_DESCRIPTION, $item['shortDescription']);
        $this->assertSame(Feed::FALLBACK_DESCRIPTION, $item['longDescription']);
        $this->assertNotSame('', $this->firstItem([$this->video(['description' => '   '])])['shortDescription']);
    }

    public function testItTrimsToTheirLimitsRatherThanBeingTruncatedMidWordByThem(): void
    {
        $long = str_repeat('Every word of this sentence matters. ', 40);
        $item = $this->firstItem([$this->video(['description' => $long])]);
        $this->assertLessThanOrEqual(Feed::MAX_DESCRIPTION, mb_strlen($item['shortDescription']));
        $this->assertLessThanOrEqual(Feed::MAX_LONG_DESCRIPTION, mb_strlen($item['longDescription']));
        $this->assertStringEndsWith('…', $item['shortDescription']);
        // Cut at a space, so the last word kept is a whole one.
        $kept = rtrim($item['shortDescription'], '…');
        $this->assertStringStartsWith($kept . ' ', trim($long), 'the cut falls on a word boundary');
    }

    public function testItCarriesTheSeriesAndTheSpeakerAsTagsAndDropsTheOnesMissing(): void
    {
        $this->assertSame(['The Sermon on the Mount', 'Devin Liao'], $this->firstItem([$this->video()])['tags']);
        $this->assertSame(['Devin Liao'], $this->firstItem([$this->video(['seriesTitle' => null])])['tags']);
        $this->assertSame([], $this->firstItem([$this->video(['seriesTitle' => '', 'speakerName' => null])])['tags']);
    }

    public function testItSilentlyLeavesOutWhatItCannotDescribe(): void
    {
        $feed = Feed::rokuFeed([
            $this->video(),
            $this->video(['id' => 'vid2', 'durationSeconds' => null]),
            $this->video(['id' => 'vid3', 'streamUrl' => null, 'externalUrl' => null]),
            $this->video(['id' => 'vid4', 'title' => '   ']),
        ], self::SITE);
        $this->assertSame(['vid1'], array_column($feed['shortFormVideos'], 'id'));
    }

    public function testAVideoWithNoThumbnailSimplyHasNone(): void
    {
        $this->assertArrayNotHasKey('thumbnail', $this->firstItem([$this->video(['thumbnailUrl' => ''])]));
    }

    // -- mrssFeed -----------------------------------------------------------

    public function testItIsTheSameCatalogueInTheOtherShape(): void
    {
        $xml = Feed::mrssFeed([$this->video()], self::SITE, '2026-06-10 06:00:00');
        $this->assertStringContainsString('<media:content url="https://vz-1.b-cdn.net/vid1/playlist.m3u8"', $xml);
        $this->assertStringContainsString('duration="2456"', $xml);
        $this->assertStringContainsString('type="application/x-mpegURL"', $xml);
        $this->assertStringContainsString('<media:category>Devin Liao</media:category>', $xml);
        $this->assertStringContainsString('Sun, 08 Mar 2026 10:30:00 GMT', $xml);
        $this->assertNotFalse(simplexml_load_string($xml), 'and it parses');
    }

    public function testItEscapesWhatATitleCanLegallyContain(): void
    {
        $xml = Feed::mrssFeed([$this->video(['title' => 'Bread & wine <for all>', 'description' => 'He said "come".'])], self::SITE);
        $this->assertStringContainsString('Bread &amp; wine &lt;for all&gt;', $xml);
        $this->assertStringNotContainsString('<for all>', $xml);
        $this->assertNotFalse(simplexml_load_string($xml));
    }

    public function testItLeavesOutExactlyWhatTheJsonLeavesOut(): void
    {
        $videos = [
            $this->video(),
            $this->video(['id' => 'vid2', 'durationSeconds' => null]),
            $this->video(['id' => 'vid3', 'streamUrl' => null, 'externalUrl' => null]),
        ];
        $xml = Feed::mrssFeed($videos, self::SITE);
        $json = Feed::rokuFeed($videos, self::SITE);
        $this->assertSame(count($json['shortFormVideos']), substr_count($xml, '<item>'));
        $this->assertStringNotContainsString('vid2', $xml);
        $this->assertStringNotContainsString('vid3', $xml);
    }
}
