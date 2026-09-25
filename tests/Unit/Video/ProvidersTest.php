<?php

declare(strict_types=1);

namespace Tests\Unit\Video;

use App\Core\Http;
use App\Core\HttpResponse;
use App\Services\TestContext;
use App\Services\Video\ArchiveProvider;
use App\Services\Video\BunnyStreamProvider;
use App\Services\Video\DirectProvider;
use App\Services\Video\PlayerOptions;
use App\Services\Video\S3Provider;
use App\Services\Video\UploadHints;
use App\Services\Video\VideoProviderException;
use App\Services\Video\VideoRef;
use App\Services\Video\VimeoProvider;
use App\Services\Video\YouTubeProvider;
use PHPUnit\Framework\TestCase;

/**
 * Each provider against recorded responses, so CI covers them without an
 * account anywhere. The fixtures are the shapes each API documents.
 */
final class ProvidersTest extends TestCase
{
    /** @var list<array{0: string, 1: string, 2: array<string, string>, 3: ?string}> */
    private array $requests = [];

    /** @param array<string, HttpResponse|callable> $routes "METHOD url-prefix" => response */
    private function fake(array $routes): void
    {
        $this->requests = [];
        Http::fake(function (string $method, string $url, array $headers, ?string $body) use ($routes): HttpResponse {
            $this->requests[] = [$method, $url, $headers, $body];
            foreach ($routes as $key => $response) {
                [$m, $prefix] = explode(' ', $key, 2);
                if ($m === $method && str_starts_with($url, $prefix)) {
                    return is_callable($response) ? $response($url, $headers, $body) : $response;
                }
            }
            return new HttpResponse(599, [], 'no fixture for ' . $method . ' ' . $url);
        });
        Http::fakeResolver(fn (string $host) => ['93.184.216.34']);
    }

    protected function tearDown(): void
    {
        Http::fake(null);
        Http::fakeResolver(null);
    }

    private static function json(mixed $data, int $status = 200, array $headers = []): HttpResponse
    {
        return new HttpResponse($status, $headers + ['content-type' => 'application/json'], (string) json_encode($data));
    }

    public function test_youtube_resolves_by_oembed_without_a_key_and_by_the_api_with_one(): void
    {
        $this->fake(['GET https://www.youtube.com/oembed' => self::json(['title' => 'Sunday service'])]);
        $v = (new YouTubeProvider([]))->resolveLink('https://youtu.be/dQw4w9WgXcQ');
        $this->assertSame(['dQw4w9WgXcQ', 'Sunday service', null, 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg'], [$v->id, $v->title, $v->durationSeconds, $v->thumbnailUrl]);

        $this->fake(['GET https://www.googleapis.com/youtube/v3/videos' => self::json(['items' => [['snippet' => ['title' => 'Romans 8', 'description' => 'Grace'], 'contentDetails' => ['duration' => 'PT45M12S']]]])]);
        $v = (new YouTubeProvider(['apiKey' => 'k']))->resolveLink('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
        $this->assertSame(['Romans 8', 2712, 'Grace'], [$v->title, $v->durationSeconds, $v->description]);

        $this->fake(['GET https://www.googleapis.com/youtube/v3/videos' => self::json(['items' => []])]);
        $this->expectException(VideoProviderException::class);
        (new YouTubeProvider(['apiKey' => 'k']))->resolveLink('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
    }

    public function test_youtube_player_and_limits(): void
    {
        $p = (new YouTubeProvider([]))->player(new VideoRef('dQw4w9WgXcQ'), new PlayerOptions(90));
        $this->assertSame(['iframe', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?rel=0&enablejsapi=1&origin=https%3A%2F%2Fchurch.example.org&start=90'], [$p->kind, $p->src]);
        $this->assertFalse((new YouTubeProvider([]))->capabilities()->upload, 'no upload without OAuth');
        $this->assertFalse((new YouTubeProvider([]))->owns(new VideoRef('dQw4w9WgXcQ')), 'a pasted link isn’t ours to delete');
    }

    public function test_youtube_upload_opens_a_resumable_session_from_the_server(): void
    {
        $this->fake([
            'POST https://oauth2.googleapis.com/token' => self::json(['access_token' => 'ya29.x']),
            'POST https://www.googleapis.com/upload/youtube/v3/videos' => new HttpResponse(200, ['location' => 'https://www.googleapis.com/upload/youtube/v3/videos?upload_id=SESSION'], ''),
        ]);
        $ticket = (new YouTubeProvider(['clientId' => 'c', 'clientSecret' => 's', 'refreshToken' => 'r']))->createUpload('Easter', new UploadHints('easter.mp4', 1000));
        $this->assertSame('resumable', $ticket->kind);
        $this->assertSame('https://www.googleapis.com/upload/youtube/v3/videos?upload_id=SESSION', $ticket->client['url']);
        $this->assertStringContainsString('"privacyStatus":"unlisted"', (string) $this->requests[1][3]);
        $this->assertSame('Bearer ya29.x', $this->requests[1][2]['Authorization']);
    }

    public function test_vimeo_link_upload_and_plan_reason(): void
    {
        $this->fake(['GET https://vimeo.com/api/oembed.json' => self::json(['title' => 'Harvest', 'duration' => 600, 'thumbnail_url' => 'https://i.vimeocdn.com/video/1_640'])]);
        $v = (new VimeoProvider([]))->resolveLink('https://vimeo.com/76979871/abcdef1234');
        $this->assertSame(['76979871', 'Harvest', 600, 'abcdef1234'], [$v->id, $v->title, $v->durationSeconds, $v->data['hash']]);
        $this->assertSame('https://player.vimeo.com/video/76979871?dnt=1&api=1&h=abcdef1234#t=5s', (new VimeoProvider([]))->player(new VideoRef('76979871', ['hash' => 'abcdef1234']), new PlayerOptions(5))->src);

        $this->fake(['POST https://api.vimeo.com/me/videos' => self::json(['uri' => '/videos/123456', 'upload' => ['upload_link' => 'https://us-files.tus.vimeo.com/files/abc']])]);
        $ticket = (new VimeoProvider(['token' => 't']))->createUpload('Harvest', new UploadHints('h.mp4', 5000, 'video/mp4', true));
        $this->assertSame(['tus', '123456', 'https://us-files.tus.vimeo.com/files/abc'], [$ticket->kind, $ticket->id, $ticket->client['uploadUrl']]);
        $this->assertStringContainsString('"view":"disable"', (string) $this->requests[0][3], 'members-only is private at Vimeo');

        $this->fake(['GET https://api.vimeo.com/videos/123456' => self::json(['files' => []])]);
        $this->assertSame('plan', (new VimeoProvider(['token' => 't']))->mp4(new VideoRef('123456'), 720)->reason);
    }

    private function bunny(array $extra = []): BunnyStreamProvider
    {
        return new BunnyStreamProvider($extra + ['libraryId' => '123', 'apiKey' => 'secret-key', 'cdnHostname' => 'vz-abc.b-cdn.net']);
    }

    public function test_bunny_upload_presigns_tus_without_handing_over_the_key(): void
    {
        $this->fake(['POST https://video.bunnycdn.com/library/123/videos' => self::json(['guid' => 'gu-id'])]);
        $ticket = $this->bunny()->createUpload('Sermon', new UploadHints('s.mp4', 100));
        $this->assertSame(['tus', 'gu-id', 'https://video.bunnycdn.com/tusupload'], [$ticket->kind, $ticket->id, $ticket->client['endpoint']]);
        $expires = (int) $ticket->client['headers']['AuthorizationExpire'];
        $this->assertSame(hash('sha256', '123secret-key' . $expires . 'gu-id'), $ticket->client['headers']['AuthorizationSignature']);
        $this->assertStringNotContainsString('secret-key', (string) json_encode($ticket->client));
        $this->assertSame('secret-key', $this->requests[0][2]['AccessKey']);
    }

    public function test_bunny_status_and_embed(): void
    {
        $this->fake(['GET https://video.bunnycdn.com/library/123/videos/gu-id' => self::json(['status' => 4, 'length' => 1800, 'thumbnailFileName' => 'thumb_1.jpg', 'hasMP4Fallback' => true, 'availableResolutions' => '360p,720p'])]);
        $info = $this->bunny()->get(new VideoRef('gu-id'));
        $this->assertSame(['READY', 1800, 'thumb_1.jpg', true, '360p,720p'], [$info->status, $info->durationSeconds, $info->thumbnailFileName, $info->hasMp4Fallback, $info->mp4Resolutions]);
        $plain = $this->bunny()->player(new VideoRef('gu-id'), new PlayerOptions(30))->src;
        $this->assertStringStartsWith('https://iframe.mediadelivery.net/embed/123/gu-id?', $plain);
        $this->assertStringContainsString('t=30', $plain);
        $this->assertStringNotContainsString('token=', $plain, 'unsigned without a token key');
        $signed = $this->bunny(['embedTokenKey' => 'tk'])->player(new VideoRef('gu-id'), new PlayerOptions())->src;
        parse_str((string) parse_url($signed, PHP_URL_QUERY), $q);
        $this->assertSame(hash('sha256', 'tkgu-id' . $q['expires']), $q['token']);
    }

    public function test_bunny_mp4_reasons_from_cached_state_and_the_probe(): void
    {
        $cached = fn (bool $has, string $res) => new VideoRef('gu-id', ['hasMp4Fallback' => $has, 'mp4Resolutions' => $res]);
        $this->fake([]);
        $this->assertSame('mp4_unavailable', $this->bunny()->mp4($cached(false, ''), 720)->reason);
        $this->assertSame([], $this->requests, 'a video cached as having no fallback asks Bunny nothing');
        $this->assertSame('resolution_unavailable', $this->bunny()->mp4($cached(true, '1080p'), 720)->reason);

        $this->fake(['GET https://vz-abc.b-cdn.net/gu-id/play_480p.mp4' => new HttpResponse(403, [], '')]);
        $this->assertSame('mp4_forbidden', $this->bunny()->mp4($cached(true, '240p,480p'), 720)->reason);
        $this->fake(['GET https://vz-abc.b-cdn.net/gu-id/play_480p.mp4' => new HttpResponse(404, [], '')]);
        $this->assertSame('mp4_missing', $this->bunny()->mp4($cached(true, '480p'), 720)->reason);
        $this->fake(['GET https://vz-abc.b-cdn.net/gu-id/play_480p.mp4' => fn () => throw new \App\Core\HttpException('timeout')]);
        $ok = $this->bunny()->mp4($cached(true, '480p'), 720);
        $this->assertTrue($ok->ok, 'a probe that failed to answer does not block a file Bunny confirmed');
        $this->assertSame(480, $ok->height);

        $this->fake(['GET https://video.bunnycdn.com/library/123/videos/gu-id' => new HttpResponse(500, [], '')]);
        $this->assertSame('provider_error', $this->bunny()->mp4(new VideoRef('gu-id'), 720)->reason, 'never synced, and the metadata fetch failed');
    }

    public function test_bunny_test_checks_the_key_and_the_cdn(): void
    {
        $this->fake(['GET https://video.bunnycdn.com/library/123/videos' => new HttpResponse(401, [], '')]);
        $this->assertFalse($this->bunny()->test(new TestContext('a@x.test', true, 'https://church.example.org'))->ok);
        $this->fake([
            'GET https://video.bunnycdn.com/library/123/videos' => self::json(['items' => [['guid' => 'gu-id']]]),
            'HEAD https://vz-abc.b-cdn.net/' => new HttpResponse(404, [], ''),
            'GET https://vz-abc.b-cdn.net/gu-id/thumbnail.jpg?token=' => new HttpResponse(206, [], 'x'),
            'GET https://vz-abc.b-cdn.net/gu-id/thumbnail.jpg' => new HttpResponse(403, [], ''),
        ]);
        $this->assertTrue($this->bunny(['cdnTokenKey' => 'ck'])->test(new TestContext('a@x.test', true, 'https://church.example.org'))->ok);
    }

    private function s3(): S3Provider
    {
        return new S3Provider(['endpoint' => 'https://acct.r2.cloudflarestorage.com', 'region' => 'auto', 'bucket' => 'sermons', 'accessKeyId' => 'AKID', 'secretAccessKey' => 'SECRET', 'prefix' => 'videos/']);
    }

    public function test_s3_small_upload_is_one_presigned_put_and_large_is_multipart(): void
    {
        $this->fake([]);
        $small = $this->s3()->createUpload('Talk', new UploadHints('talk.mp4', 10_000_000));
        $this->assertSame('put', $small->kind);
        $this->assertStringStartsWith('https://acct.r2.cloudflarestorage.com/sermons/videos/' . $small->id . '?', $small->client['url']);
        $this->assertStringContainsString('X-Amz-Signature=', $small->client['url']);
        $this->assertStringNotContainsString('SECRET', $small->client['url']);

        $this->fake(['POST https://acct.r2.cloudflarestorage.com/sermons/videos/' => new HttpResponse(200, [], '<InitiateMultipartUploadResult><UploadId>up-1</UploadId></InitiateMultipartUploadResult>')]);
        $big = $this->s3()->createUpload('Talk', new UploadHints('talk.mp4', 150 * 1024 * 1024));
        $this->assertSame(['multipart', 10, 'up-1'], [$big->kind, count($big->client['parts']), $big->data['uploadId']]);
        $this->assertStringContainsString('partNumber=2', $big->client['parts'][1]['url']);

        $this->fake([
            'POST https://acct.r2.cloudflarestorage.com/sermons/videos/' => new HttpResponse(200, [], '<CompleteMultipartUploadResult/>'),
            'HEAD https://acct.r2.cloudflarestorage.com/sermons/videos/' => new HttpResponse(200, [], ''),
        ]);
        $this->s3()->completeUpload(new VideoRef($big->id, $big->data + ['parts' => [['number' => 1, 'etag' => '"e1"'], ['number' => 2, 'etag' => '"e2"']]]));
        $this->assertStringContainsString('<Part><PartNumber>2</PartNumber><ETag>"e2"</ETag></Part>', (string) $this->requests[0][3]);
        $this->assertStringContainsString('AWS4-HMAC-SHA256', $this->requests[0][2]['authorization']);
    }

    public function test_s3_playback_is_presigned_per_request_unless_the_bucket_is_public(): void
    {
        $ref = new VideoRef('a.mp4', ['key' => 'videos/a.mp4', 'type' => 'video/mp4']);
        $this->assertStringContainsString('X-Amz-Expires=900', $this->s3()->player($ref, new PlayerOptions())->sources[0]['src']);
        $public = new S3Provider(['endpoint' => 'https://e', 'bucket' => 'b', 'accessKeyId' => 'a', 'secretAccessKey' => 's', 'publicBucket' => true, 'publicBaseUrl' => 'https://cdn.example.org']);
        $this->assertSame('https://cdn.example.org/videos/a.mp4', $public->player($ref, new PlayerOptions())->sources[0]['src']);
        $this->assertTrue($this->s3()->capabilities()->enforcesPrivacy);
        $this->assertFalse($public->capabilities()->enforcesPrivacy);
    }

    public function test_direct_link_checks_the_type_through_the_untrusted_door(): void
    {
        $this->fake(['HEAD https://media.example.org/talk.mp4' => new HttpResponse(200, ['content-type' => 'video/mp4', 'accept-ranges' => 'bytes'], '')]);
        $v = (new DirectProvider([]))->resolveLink('https://media.example.org/talk.mp4');
        $this->assertSame(['talk', 'video/mp4', true], [$v->title, $v->data['type'], $v->data['ranges']]);
        $this->fake(['HEAD https://media.example.org/page' => new HttpResponse(200, ['content-type' => 'text/html'], '')]);
        try {
            (new DirectProvider([]))->resolveLink('https://media.example.org/page');
            $this->fail('accepted a web page');
        } catch (VideoProviderException $e) {
            $this->assertStringContainsString('text/html', $e->getMessage());
        }
        Http::fakeResolver(fn () => ['10.0.0.5']);
        $this->expectException(\App\Core\HttpException::class);
        (new DirectProvider([]))->resolveLink('https://intranet.example.org/talk.mp4');
    }

    public function test_archive_plays_the_h264_derivative(): void
    {
        $this->fake(['GET https://archive.org/metadata/sermon_1' => self::json(['metadata' => ['title' => 'Sermon one'], 'files' => [['name' => 'sermon.mp4', 'format' => 'MPEG4', 'size' => '9'], ['name' => 'sermon.ia.mp4', 'format' => 'h.264', 'size' => '5', 'length' => '1800.4']]])]);
        $v = (new ArchiveProvider([]))->resolveLink('https://archive.org/details/sermon_1');
        $this->assertSame(['Sermon one', 1800, 'sermon.ia.mp4'], [$v->title, $v->durationSeconds, $v->data['file']]);
        $this->assertSame('https://archive.org/download/sermon_1/sermon.ia.mp4', (new ArchiveProvider([]))->player(new VideoRef('sermon_1', $v->data), new PlayerOptions())->sources[0]['src']);
    }
}
