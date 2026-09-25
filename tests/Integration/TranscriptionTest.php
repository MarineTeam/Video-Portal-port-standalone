<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\ApiError;
use App\Core\App;
use App\Core\Cache;
use App\Core\Db;
use App\Core\Http;
use App\Core\HttpResponse;
use App\Core\Id;
use App\Core\Migrator;
use App\Core\Paths;
use App\Modules\Library\Transcription;
use App\Services\Video\LocalVideoProvider;

/**
 * The transcription queue against a real database and a recorded service:
 * queued videos are claimed one at a time and come back DONE with the
 * service's text or FAILED with its reason; one stuck RUNNING for half an
 * hour is queued again; the file goes as multipart with the model field;
 * a file over the limit never leaves.
 */
final class TranscriptionTest extends DatabaseTestCase
{
    private const PREFIX = 'tr_';
    private const URL = 'https://stt.example.org/v1/audio/transcriptions';
    private static ?Db $db = null;
    private static ?App $app = null;
    private static string $storage = '';
    /** @var list<array{string, string, array<string, string>, string}> method, url, headers, body sent */
    private array $sent = [];

    public static function setUpBeforeClass(): void
    {
        $name = getenv('MT_TEST_DB_NAME');
        if (!is_string($name) || $name === '') {
            return;
        }
        $db = self::connect(self::PREFIX);
        self::dropPrefix($db, self::PREFIX);
        (new Migrator($db, dirname(__DIR__, 2) . '/app/Migrations'))->runAll();
        self::$db = $db;
        self::$storage = sys_get_temp_dir() . '/mt-tr-' . bin2hex(random_bytes(4));
        mkdir(self::$storage . '/videos', 0777, true);
        mkdir(self::$storage . '/public-videos', 0777, true);
        $root = dirname(__DIR__, 2);
        self::$app = new App(new Paths($root, self::$storage, "$root/plugins", "$root/themes"));
        self::$app->config = ['database' => self::dbConfig(self::PREFIX)];
        LocalVideoProvider::configure(self::$storage . '/videos', self::$storage . '/public-videos');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::dropPrefix(self::$db, self::PREFIX);
            \App\Modules\Plugins\PackageInstaller::removeTree(self::$storage);
        }
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('MT_TEST_DB_NAME is not set.');
        }
        Cache::forgetMemo();
        Cache::forget('settings');
        self::$db->run('DELETE FROM {{videos}}');
        self::$app->settings()->set('integration.transcription', ['url' => self::URL, 'apiKey' => null, 'model' => 'whisper-1', 'language' => '', 'maxMb' => 1]);
        Http::fakeResolver(fn (string $host) => ['93.184.216.34']);
    }

    protected function tearDown(): void
    {
        Http::fake(null);
        Http::fakeResolver(null);
    }

    /** @param callable(string): HttpResponse $answer given the multipart body */
    private function service(callable $answer): void
    {
        $this->sent = [];
        Http::fake(function (string $method, string $url, array $headers, ?string $body, array $options) use ($answer): HttpResponse {
            $payload = isset($options['bodyFile']) ? (string) file_get_contents((string) $options['bodyFile']) : (string) $body;
            $this->sent[] = [$method, $url, $headers, $payload];
            return $answer($payload);
        });
    }

    /** A host-disk video with a file of $bytes bytes. */
    private function video(int $bytes = 64, array $extra = []): string
    {
        $name = bin2hex(random_bytes(12)) . '.mp4';
        file_put_contents(self::$storage . '/videos/' . $name, str_repeat('V', $bytes));
        $id = Id::new();
        self::$db->insert('videos', $extra + ['id' => $id, 'title' => 'Sermon', 'slug' => $id, 'provider' => 'local', 'external_id' => $name, 'status' => 'READY', 'scripture_refs' => [], 'provider_data' => []]);
        return $id;
    }

    /** @return array<string, mixed> */
    private function row(string $id): array
    {
        return (array) self::$db->one('SELECT * FROM {{videos}} WHERE id = ?', [$id]);
    }

    public function test_a_queued_video_comes_back_with_the_services_text(): void
    {
        $id = $this->video();
        Transcription::queue(self::$app, $this->row($id));
        self::assertSame('QUEUED', $this->row($id)['transcript_status']);
        $this->service(fn () => new HttpResponse(200, ['content-type' => 'application/json'], '{"text":"  In the beginning was the Word.  "}'));

        $summary = Transcription::run(self::$app, microtime(true) + 20);

        self::assertSame('transcribed 1', $summary);
        $row = $this->row($id);
        self::assertSame(['DONE', 'In the beginning was the Word.', null], [$row['transcript_status'], $row['transcript'], $row['transcript_error']]);
        [$method, $url, $headers, $body] = $this->sent[0];
        self::assertSame(['POST', self::URL], [$method, $url]);
        self::assertArrayNotHasKey('Authorization', $headers, 'no key, no header');
        self::assertMatchesRegularExpression('/^multipart\/form-data; boundary=(mt[0-9a-f]{24})$/', $headers['Content-Type']);
        self::assertStringContainsString("name=\"file\"; filename=\"video.mp4\"\r\nContent-Type: video/mp4\r\n\r\n" . str_repeat('V', 64) . "\r\n", $body);
        self::assertStringContainsString("name=\"model\"\r\n\r\nwhisper-1\r\n", $body);
        self::assertStringNotContainsString('name="language"', $body);
        self::assertSame([], glob(self::$storage . '/tmp/transcribe/*'), 'nothing left in storage/tmp');
    }

    public function test_the_services_refusal_is_kept_as_the_reason(): void
    {
        $id = $this->video();
        Transcription::queue(self::$app, $this->row($id));
        $this->service(fn () => new HttpResponse(401, ['content-type' => 'application/json'], '{"error":{"message":"Incorrect API key provided"}}'));
        self::assertSame('transcribed 0, failed 1', Transcription::run(self::$app, microtime(true) + 20));
        $row = $this->row($id);
        self::assertSame('FAILED', $row['transcript_status']);
        self::assertStringContainsString('(401): Incorrect API key provided', (string) $row['transcript_error']);
    }

    public function test_a_file_over_the_limit_never_leaves(): void
    {
        $id = $this->video(2 * 1024 * 1024);
        Transcription::queue(self::$app, $this->row($id));
        $this->service(fn () => new HttpResponse(200, [], '{"text":"x"}'));
        Transcription::run(self::$app, microtime(true) + 20);
        self::assertSame([], $this->sent);
        self::assertStringContainsString('at most 1 MB', (string) $this->row($id)['transcript_error']);
    }

    public function test_one_stuck_running_is_queued_again_and_a_fresh_one_is_left_alone(): void
    {
        $stuck = $this->video(64, ['transcript_status' => 'RUNNING', 'transcript_started_at' => gmdate('Y-m-d H:i:s', time() - 3600)]);
        $busy = $this->video(64, ['transcript_status' => 'RUNNING', 'transcript_started_at' => gmdate('Y-m-d H:i:s', time() - 60)]);
        $this->service(fn () => new HttpResponse(200, [], '{"text":"Amen."}'));
        self::assertSame('transcribed 1, re-queued 1', Transcription::run(self::$app, microtime(true) + 20));
        self::assertSame('DONE', $this->row($stuck)['transcript_status']);
        self::assertSame('RUNNING', $this->row($busy)['transcript_status']);
    }

    public function test_the_job_stops_starting_work_at_its_deadline(): void
    {
        $a = $this->video();
        $b = $this->video();
        Transcription::queue(self::$app, $this->row($a));
        Transcription::queue(self::$app, $this->row($b));
        $this->service(fn () => new HttpResponse(200, [], '{"text":"ok"}'));
        self::assertSame('transcribed 0', Transcription::run(self::$app, microtime(true) - 1));
        self::assertSame(['QUEUED', 'QUEUED'], [$this->row($a)['transcript_status'], $this->row($b)['transcript_status']]);
    }

    public function test_it_refuses_to_queue_without_a_service_or_a_file(): void
    {
        $id = $this->video();
        self::$db->update('videos', ['external_id' => 'aaaaaaaaaaaaaaaaaaaaaaaa.mp4'], ['id' => $id]);
        try {
            Transcription::queue(self::$app, $this->row($id));
            self::fail('queued a video with no file');
        } catch (ApiError $e) {
            self::assertStringContainsString('missing', $e->getMessage());
        }
        self::$app->settings()->delete('integration.transcription');
        Cache::forgetMemo();
        self::assertStringContainsString('No transcription service', (string) Transcription::reason(self::$app, $this->row($this->video())));
    }

    public function test_the_settings_test_sends_a_second_of_silence(): void
    {
        $this->service(fn () => new HttpResponse(200, [], '{"text":""}'));
        $r = Transcription::test(['url' => self::URL, 'apiKey' => 'sk-test', 'model' => 'whisper-1', 'language' => 'en']);
        self::assertTrue($r->ok, $r->message);
        [, , $headers, $body] = $this->sent[0];
        self::assertSame('Bearer sk-test', $headers['Authorization']);
        self::assertStringContainsString("filename=\"silence.wav\"\r\nContent-Type: audio/wav\r\n\r\nRIFF", $body);
        self::assertStringContainsString("name=\"language\"\r\n\r\nen\r\n", $body);

        self::assertFalse(Transcription::test(['url' => 'http://127.0.0.1:9000/asr'])->ok, 'a private address is refused');
        $this->service(fn () => new HttpResponse(200, [], '{"transcript":"no"}'));
        self::assertStringContainsString('without {"text"', Transcription::test(['url' => self::URL])->message);
    }
}
