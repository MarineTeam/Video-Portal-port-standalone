<?php

declare(strict_types=1);

namespace Tests\Unit\Video;

use App\Core\Http;
use App\Core\HttpResponse;
use App\Modules\Library\VideoFeeds;
use PHPUnit\Framework\TestCase;

/** lib/video-feed-sync.test.ts, and the two fetchers against recorded answers. */
final class VideoFeedSyncTest extends TestCase
{
    protected function tearDown(): void
    {
        Http::fake(null);
    }

    public function test_overwrites_a_field_nobody_has_touched(): void
    {
        self::assertTrue(VideoFeeds::mayOverwrite('Sunday Service', 'Sunday Service'));
    }

    public function test_leaves_a_field_somebody_rewrote_alone(): void
    {
        self::assertFalse(VideoFeeds::mayOverwrite('The Cost of Discipleship', 'Sunday Service 12/10/25 || FULL SERVICE'));
    }

    public function test_treats_an_emptied_field_as_an_edit_not_as_an_invitation(): void
    {
        self::assertFalse(VideoFeeds::mayOverwrite('', 'A long description'));
    }

    public function test_leaves_a_row_alone_when_there_is_no_record_of_what_was_imported(): void
    {
        self::assertFalse(VideoFeeds::mayOverwrite('Anything', null));
    }

    public function test_counts_a_null_live_field_against_an_empty_imported_one_as_untouched(): void
    {
        self::assertTrue(VideoFeeds::mayOverwrite(null, ''));
    }

    public function test_takes_the_new_wording_when_nothing_was_edited_here(): void
    {
        $update = VideoFeeds::mergeImported(
            ['title' => 'Old', 'description' => 'Old words', 'imported_title' => 'Old', 'imported_description' => 'Old words'],
            ['title' => 'New', 'description' => 'New words'],
        );
        self::assertSame(['title' => 'New', 'description' => 'New words', 'imported_title' => 'New', 'imported_description' => 'New words'], $update);
    }

    public function test_keeps_a_renamed_title_while_still_taking_the_new_description(): void
    {
        $update = VideoFeeds::mergeImported(
            ['title' => 'The Cost of Discipleship', 'description' => 'Old words', 'imported_title' => 'Sunday Service', 'imported_description' => 'Old words'],
            ['title' => 'Sunday Service (edited upstream)', 'description' => 'New words'],
        );
        self::assertArrayNotHasKey('title', $update);
        self::assertSame('New words', $update['description']);
    }

    public function test_always_brings_the_record_of_what_the_source_says_up_to_date(): void
    {
        $update = VideoFeeds::mergeImported(
            ['title' => 'Mine', 'description' => 'Mine too', 'imported_title' => 'Theirs', 'imported_description' => 'Theirs too'],
            ['title' => 'Theirs, v2', 'description' => null],
        );
        self::assertSame(['imported_title' => 'Theirs, v2', 'imported_description' => ''], $update);
    }

    public function test_reads_what_youtube_reports(): void
    {
        self::assertSame(3723, VideoFeeds::parseIsoDuration('PT1H2M3S'));
        self::assertSame(95, VideoFeeds::parseIsoDuration('PT1M35S'));
    }

    public function test_gives_null_rather_than_zero_for_something_it_cant_read(): void
    {
        self::assertNull(VideoFeeds::parseIsoDuration('P'));
        self::assertNull(VideoFeeds::parseIsoDuration('nonsense'));
        self::assertNull(VideoFeeds::parseIsoDuration(null));
    }

    public function test_takes_the_widest_offered_since_a_card_is_bigger_than_a_favicon(): void
    {
        self::assertSame('https://i/large.jpg', VideoFeeds::bestThumbnail([
            ['url' => 'https://i/small.jpg', 'width' => 120],
            ['url' => 'https://i/large.jpg', 'width' => 1280],
            ['url' => 'https://i/medium.jpg', 'width' => 480],
        ]));
    }

    public function test_copes_with_no_widths_and_with_nothing_at_all(): void
    {
        self::assertSame('https://i/only.jpg', VideoFeeds::bestThumbnail([['url' => 'https://i/only.jpg']]));
        self::assertNull(VideoFeeds::bestThumbnail([]));
    }

    public function test_fingerprint_is_the_same_for_the_same_payload_and_different_for_a_changed_one(): void
    {
        $items = [['externalId' => 'a', 'title' => 'One', 'description' => 'x', 'durationSeconds' => 10, 'thumbnail' => null]];
        self::assertSame(VideoFeeds::fingerprintFeed($items), VideoFeeds::fingerprintFeed($items));
        $changed = $items;
        $changed[0]['title'] = 'One (edited)';
        self::assertNotSame(VideoFeeds::fingerprintFeed($items), VideoFeeds::fingerprintFeed($changed));
    }

    public function test_fingerprint_notices_a_video_appearing(): void
    {
        $items = [['externalId' => 'a', 'title' => 'One']];
        self::assertNotSame(VideoFeeds::fingerprintFeed($items), VideoFeeds::fingerprintFeed([...$items, ['externalId' => 'b', 'title' => 'Two']]));
    }

    public function test_maps_every_feed_kind_to_the_player_it_imports_into(): void
    {
        self::assertSame(['youtube', 'youtube', 'vimeo', 'vimeo'], array_map([VideoFeeds::class, 'sourceOf'], VideoFeeds::KINDS));
    }

    /** @param array<string, array<string, mixed>> $routes */
    private function fake(array $routes): void
    {
        Http::fake(function (string $method, string $url) use ($routes): HttpResponse {
            foreach ($routes as $prefix => $body) {
                if (str_starts_with($url, $prefix)) {
                    return new HttpResponse(200, ['content-type' => 'application/json'], (string) json_encode($body));
                }
            }
            return new HttpResponse(404, ['content-type' => 'application/json'], '{"error":{"message":"not found"}}');
        });
    }

    public function test_a_youtube_channel_goes_through_its_uploads_playlist_and_skips_private_videos(): void
    {
        $this->fake([
            'https://www.googleapis.com/youtube/v3/channels?' => ['items' => [['contentDetails' => ['relatedPlaylists' => ['uploads' => 'UUabc']]]]],
            'https://www.googleapis.com/youtube/v3/playlistItems?' => ['items' => [['contentDetails' => ['videoId' => 'aaaaaaaaaaa']], ['contentDetails' => ['videoId' => 'bbbbbbbbbbb']]]],
            'https://www.googleapis.com/youtube/v3/videos?' => ['items' => [
                ['id' => 'aaaaaaaaaaa', 'snippet' => ['title' => 'Sunday', 'description' => 'd', 'publishedAt' => '2026-09-20T10:00:00Z', 'thumbnails' => ['default' => ['url' => 'https://i/s.jpg', 'width' => 120], 'high' => ['url' => 'https://i/h.jpg', 'width' => 480]]], 'contentDetails' => ['duration' => 'PT1H'], 'status' => ['privacyStatus' => 'public']],
                ['id' => 'bbbbbbbbbbb', 'snippet' => ['title' => 'Private'], 'contentDetails' => ['duration' => 'PT1M'], 'status' => ['privacyStatus' => 'private']],
            ]],
        ]);
        $items = VideoFeeds::fetchFeed('YOUTUBE_CHANNEL', 'UCabc', 25, 'key');
        self::assertCount(1, $items);
        self::assertSame(['aaaaaaaaaaa', 'Sunday', 3600, 'https://i/h.jpg'], [$items[0]['externalId'], $items[0]['title'], $items[0]['durationSeconds'], $items[0]['thumbnail']]);
    }

    public function test_a_vimeo_account_reads_ids_from_uris(): void
    {
        $this->fake(['https://api.vimeo.com/users/church/videos?' => ['data' => [
            ['uri' => '/videos/76979871', 'name' => 'Evening', 'description' => null, 'duration' => 1800, 'link' => 'https://vimeo.com/76979871', 'pictures' => ['sizes' => [['width' => 640, 'link' => 'https://i/640.jpg'], ['width' => 1920, 'link' => 'https://i/1920.jpg']]]],
        ]]]);
        $items = VideoFeeds::fetchFeed('VIMEO_USER', 'church', 25, 'token');
        self::assertSame(['76979871', 'Evening', 1800, 'https://i/1920.jpg'], [$items[0]['externalId'], $items[0]['title'], $items[0]['durationSeconds'], $items[0]['thumbnail']]);
    }

    public function test_a_feed_that_doesnt_exist_says_so(): void
    {
        $this->fake([]);
        $this->expectException(\App\Core\HttpException::class);
        VideoFeeds::fetchFeed('YOUTUBE_PLAYLIST', 'PLnope', 25, 'key');
    }
}
