<?php

declare(strict_types=1);

namespace App\Modules\Library;

use App\Core\ApiError;
use App\Core\App;
use App\Core\Db;
use App\Core\Http;
use App\Core\HttpException;
use App\Core\Log;
use App\Modules\Admin\Integrations;
use App\Services\TestResult;
use App\Services\Video\LocalVideoProvider;
use App\Services\Video\VideoProvider;
use App\Services\Video\VideoRef;

/**
 * Automatic transcription: a queue on the video row (transcript_status
 * QUEUED → RUNNING → DONE, or FAILED with transcript_error), drained by the
 * `transcribe` job one video at a time, because an hour of audio takes
 * minutes — longer than a request should live. The service is any that
 * takes a multipart POST with a "file" field and answers {"text": …}.
 *
 * The file is the video's own MP4 (the host's disk, or the provider's MP4
 * address), streamed to storage/tmp and on to the service without being
 * held in memory. The service's address was typed by an admin, so it is
 * checked like any typed URL and the request pinned to the address that
 * check resolved.
 */
final class Transcription
{
    public const STALE_SECONDS = 1800;
    public const DEFAULT_MAX_MB = 25;
    public const REQUEST_TIMEOUT = 900;

    public const FIELDS = [
        ['key' => 'url', 'label' => 'Service address', 'type' => 'text', 'required' => true, 'help' => 'e.g. https://api.openai.com/v1/audio/transcriptions, or your own Whisper server’s.'],
        ['key' => 'apiKey', 'label' => 'API key (sent as a Bearer token)', 'type' => 'password', 'secret' => true],
        ['key' => 'model', 'label' => 'Model (sent as the “model” field when set)', 'type' => 'text', 'help' => 'e.g. whisper-1 for OpenAI, whisper-large-v3 for Groq.'],
        ['key' => 'language', 'label' => 'Language code (optional)', 'type' => 'text', 'help' => 'e.g. en. Left empty, the service detects it.'],
        ['key' => 'maxMb', 'label' => 'Largest file to send, in MB', 'type' => 'number', 'default' => self::DEFAULT_MAX_MB, 'help' => 'OpenAI takes 25 MB; your own server may take more.'],
    ];

    /** @return array<string, mixed> */
    public static function config(App $app): array
    {
        return Integrations::config($app, 'transcription');
    }

    /** @param array<string, mixed> $config */
    private static function maxBytes(array $config): int
    {
        $mb = is_numeric($config['maxMb'] ?? null) ? (float) $config['maxMb'] : self::DEFAULT_MAX_MB;
        return (int) (max(1, min(4096, $mb)) * 1024 * 1024);
    }

    /**
     * Why this video can't be transcribed, or null when it can.
     *
     * @param array<string, mixed> $video
     */
    public static function reason(App $app, array $video): ?string
    {
        if (trim((string) (self::config($app)['url'] ?? '')) === '') {
            return 'No transcription service is set up. Add one under Admin → Services → Transcription.';
        }
        try {
            self::source($app, $video);
        } catch (ApiError $e) {
            return $e->getMessage();
        }
        return null;
    }

    /**
     * Where the video's file is: ['path' => …] on this host's disk, or
     * ['url' => …] at its provider.
     *
     * @param array<string, mixed> $video
     * @return array{path?: string, url?: string}
     */
    private static function source(App $app, array $video): array
    {
        $ref = VideoRef::fromRow($video);
        if ($video['provider'] === LocalVideoProvider::id()) {
            $path = LocalVideoProvider::filePath($ref);
            return $path !== null ? ['path' => $path] : throw ApiError::invalid('The video’s file is missing from this host.');
        }
        $provider = $app->services()->get('video', (string) $video['provider']);
        if (!$provider instanceof VideoProvider) {
            throw ApiError::invalid('This video’s service isn’t set up any more.');
        }
        // The smallest rendition: the words are the same and the upload is shorter.
        $mp4 = $provider->mp4($ref, 360);
        if (!$mp4->ok || $mp4->url === null || !preg_match('#^https?://#', $mp4->url)) {
            throw ApiError::invalid($provider::label() . ' gives no file to send for transcription' . ($mp4->reason === 'not_supported' ? '; paste the transcript instead.' : ' (' . (string) $mp4->reason . ').'));
        }
        return ['url' => $mp4->url];
    }

    /** @param array<string, mixed> $video */
    public static function queue(App $app, array $video): void
    {
        $why = self::reason($app, $video);
        if ($why !== null) {
            throw new ApiError($why, 409, 'transcription_unavailable');
        }
        if (($video['transcript_status'] ?? null) === 'RUNNING') {
            throw new ApiError('This video is being transcribed now.', 409, 'transcription_running');
        }
        $app->db()->update('videos', ['transcript_status' => 'QUEUED', 'transcript_error' => null, 'transcript_started_at' => null], ['id' => $video['id']]);
    }

    /**
     * The job: re-queue anything stuck RUNNING for half an hour, then take
     * queued videos one at a time while the budget allows starting another.
     */
    public static function run(App $app, float $deadline): string
    {
        $db = $app->db();
        $stale = $db->run(
            "UPDATE {{videos}} SET transcript_status = 'QUEUED' WHERE transcript_status = 'RUNNING' AND (transcript_started_at IS NULL OR transcript_started_at < ?)",
            [Db::datetime(new \DateTimeImmutable('-' . self::STALE_SECONDS . ' seconds'))],
        )->rowCount();
        $config = self::config($app);
        if (trim((string) ($config['url'] ?? '')) === '') {
            return 'no service set' . ($stale > 0 ? ", $stale re-queued" : '');
        }
        $done = 0;
        $failed = 0;
        while (microtime(true) < $deadline) {
            $video = $db->one("SELECT * FROM {{videos}} WHERE transcript_status = 'QUEUED' AND deleted_at IS NULL ORDER BY updated_at LIMIT 1");
            if ($video === null) {
                break;
            }
            // Claim it: another run that got here first wins.
            $claimed = $db->run("UPDATE {{videos}} SET transcript_status = 'RUNNING', transcript_started_at = ? WHERE id = ? AND transcript_status = 'QUEUED'", [Db::datetime(new \DateTimeImmutable()), $video['id']])->rowCount();
            if ($claimed !== 1) {
                continue;
            }
            try {
                $text = self::transcribe($app, $video, $config);
                $db->update('videos', ['transcript' => $text, 'transcript_status' => 'DONE', 'transcript_error' => null], ['id' => $video['id']]);
                $done++;
            } catch (\Throwable $e) {
                $message = $e instanceof ApiError || $e instanceof HttpException ? $e->getMessage() : 'The transcription failed: ' . $e->getMessage();
                Log::warning('Transcription of ' . $video['id'] . ' failed: ' . $e->getMessage());
                $db->update('videos', ['transcript_status' => 'FAILED', 'transcript_error' => mb_substr($message, 0, 2000)], ['id' => $video['id']]);
                $failed++;
            }
        }
        return "transcribed $done" . ($failed > 0 ? ", failed $failed" : '') . ($stale > 0 ? ", re-queued $stale" : '');
    }

    /**
     * One video's text.
     *
     * @param array<string, mixed> $video
     * @param array<string, mixed> $config
     */
    public static function transcribe(App $app, array $video, array $config): string
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(self::REQUEST_TIMEOUT + 300);
        }
        $max = self::maxBytes($config);
        $source = self::source($app, $video);
        $dir = $app->paths->storage('tmp/transcribe');
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new ApiError('storage/tmp isn’t writable.', 500, 'storage');
        }
        $name = 'video.' . (preg_match('/\.(mp4|m4v|webm|mov|mp3|m4a|wav)$/i', (string) ($source['path'] ?? parse_url((string) ($source['url'] ?? ''), PHP_URL_PATH)), $m) ? strtolower($m[1]) : 'mp4');
        $boundary = 'mt' . bin2hex(random_bytes(12));
        $body = $dir . '/' . bin2hex(random_bytes(8)) . '.multipart';
        $out = fopen($body, 'wb');
        if ($out === false) {
            throw new ApiError('storage/tmp isn’t writable.', 500, 'storage');
        }
        try {
            fwrite($out, "--$boundary\r\nContent-Disposition: form-data; name=\"file\"; filename=\"$name\"\r\nContent-Type: " . self::mime($name) . "\r\n\r\n");
            $bytes = isset($source['path']) ? self::copyFile((string) $source['path'], $out, $max) : self::download((string) $source['url'], $out, $max);
            fwrite($out, "\r\n");
            foreach (['model' => 'model', 'language' => 'language'] as $key => $field) {
                $value = trim((string) ($config[$key] ?? ''));
                if ($value !== '') {
                    fwrite($out, "--$boundary\r\nContent-Disposition: form-data; name=\"$field\"\r\n\r\n$value\r\n");
                }
            }
            fwrite($out, "--$boundary--\r\n");
            fclose($out);
            $out = null;
            return self::send($config, $body, $boundary, $bytes);
        } finally {
            if (is_resource($out)) {
                fclose($out);
            }
            @unlink($body);
        }
    }

    /** @param resource $out */
    private static function copyFile(string $path, $out, int $max): int
    {
        $size = (int) @filesize($path);
        if ($size > $max) {
            throw new ApiError(self::tooBig($size, $max), 413, 'too_large');
        }
        $in = fopen($path, 'rb');
        if ($in === false) {
            throw new ApiError('The video’s file couldn’t be read.', 500, 'unreadable');
        }
        $n = (int) stream_copy_to_stream($in, $out);
        fclose($in);
        return $n;
    }

    /** @param resource $out */
    private static function download(string $url, $out, int $max): int
    {
        $n = 0;
        $status = 0;
        $r = Http::request('GET', $url, [], null, [
            'timeout' => self::REQUEST_TIMEOUT,
            'onHeaders' => function (int $code, array $headers) use (&$status, $max): void {
                $status = $code;
                if ($code >= 200 && $code < 300 && isset($headers['content-length']) && (int) $headers['content-length'] > $max) {
                    throw new ApiError(self::tooBig((int) $headers['content-length'], $max), 413, 'too_large');
                }
            },
            'sink' => function (string $chunk) use ($out, &$n, &$status, $max): void {
                if ($status < 200 || $status >= 300) {
                    return;
                }
                $n += strlen($chunk);
                if ($n > $max) {
                    throw new ApiError(self::tooBig($n, $max), 413, 'too_large');
                }
                fwrite($out, $chunk);
            },
        ]);
        if (!$r->ok() || $n === 0) {
            throw new ApiError('The video’s file couldn’t be fetched from its service (' . $r->status . ').', 502, 'provider_error');
        }
        return $n;
    }

    private static function tooBig(int $size, int $max): string
    {
        return sprintf('The file is %.0f MB; the transcription service takes at most %.0f MB. Raise the limit in its settings if your service allows it.', $size / 1048576, $max / 1048576);
    }

    private static function mime(string $name): string
    {
        return match (pathinfo($name, PATHINFO_EXTENSION)) {
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            'mp3' => 'audio/mpeg',
            'm4a' => 'audio/mp4',
            'wav' => 'audio/wav',
            default => 'video/mp4',
        };
    }

    /**
     * The request itself, to the address the untrusted-URL check resolved.
     *
     * @param array<string, mixed> $config
     */
    private static function send(array $config, string $bodyFile, string $boundary, int $bytes): string
    {
        $url = trim((string) $config['url']);
        $target = Http::checkUntrusted($url);
        $headers = ['Content-Type' => 'multipart/form-data; boundary=' . $boundary, 'Accept' => 'application/json'];
        if (trim((string) ($config['apiKey'] ?? '')) !== '') {
            $headers['Authorization'] = 'Bearer ' . trim((string) $config['apiKey']);
        }
        $r = Http::request('POST', $url, $headers, null, [
            'timeout' => self::REQUEST_TIMEOUT,
            'resolve' => $target,
            'followRedirects' => false,
            'bodyFile' => $bodyFile,
            'maxBytes' => 20_000_000,
        ]);
        $d = $r->json();
        if (!$r->ok()) {
            $why = is_array($d) ? (string) ($d['error']['message'] ?? $d['error'] ?? $d['detail'] ?? $d['message'] ?? '') : '';
            throw new ApiError('The transcription service refused the file (' . $r->status . ')' . ($why !== '' ? ': ' . $why : '.'), 502, 'service_error');
        }
        if (!is_array($d) || !is_string($d['text'] ?? null)) {
            throw new ApiError('The transcription service answered without {"text": …}.', 502, 'service_error');
        }
        return trim($d['text']);
    }

    /**
     * The settings' test: one second of silence, sent the way a video is.
     *
     * @param array<string, mixed> $config
     */
    public static function test(array $config): TestResult
    {
        $url = trim((string) ($config['url'] ?? ''));
        $steps = [];
        try {
            Http::checkUntrusted($url);
            $steps[] = 'The address is a public web address';
            $boundary = 'mt' . bin2hex(random_bytes(12));
            $file = tempnam(sys_get_temp_dir(), 'mt-tr');
            if ($file === false) {
                return TestResult::fail('No temporary file could be made for the test.');
            }
            try {
                $parts = "--$boundary\r\nContent-Disposition: form-data; name=\"file\"; filename=\"silence.wav\"\r\nContent-Type: audio/wav\r\n\r\n" . self::silence() . "\r\n";
                foreach (['model' => 'model', 'language' => 'language'] as $key => $field) {
                    $value = trim((string) ($config[$key] ?? ''));
                    if ($value !== '') {
                        $parts .= "--$boundary\r\nContent-Disposition: form-data; name=\"$field\"\r\n\r\n$value\r\n";
                    }
                }
                file_put_contents($file, $parts . "--$boundary--\r\n");
                $text = self::send($config, $file, $boundary, 0);
            } finally {
                @unlink($file);
            }
        } catch (HttpException | ApiError $e) {
            return TestResult::fail($e->getMessage(), $steps);
        }
        $steps[] = 'A second of silence was transcribed' . ($text !== '' ? ' (as “' . mb_substr($text, 0, 60) . '”)' : '');
        return TestResult::ok('The service answers the way transcription needs. Save to use it.', $steps);
    }

    /** One second of 16 kHz mono silence as WAV. */
    public static function silence(): string
    {
        $samples = 16000;
        $data = str_repeat("\0\0", $samples);
        return 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVE'
            . 'fmt ' . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16)
            . 'data' . pack('V', strlen($data)) . $data;
    }
}
