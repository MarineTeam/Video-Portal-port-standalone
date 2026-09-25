<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Core\Http;
use App\Core\Url;
use App\Modules\Files\UploadTypes;
use App\Services\TestContext;
use App\Services\TestResult;

/**
 * The host's own disk: the last resort, for a church with a few videos.
 * Uploads come through this site in slices; a video anyone may watch is
 * published under public/media/videos/<random>.mp4 so the web server serves
 * it without PHP; a members-only one stays under storage/videos/ and streams
 * through the site (Range, and X-Sendfile where the host has it) after the
 * access check. No transcoding.
 */
final class LocalVideoProvider extends BaseVideoProvider
{
    private static string $storageDir = '';
    private static string $publicDir = '';

    public static function configure(string $storageDir, string $publicDir): void
    {
        self::$storageDir = rtrim($storageDir, '/');
        self::$publicDir = rtrim($publicDir, '/');
    }

    public static function id(): string
    {
        return 'local';
    }

    public static function label(): string
    {
        return 'This host’s own disk';
    }

    public static function limits(): string
    {
        return 'For a church with a few videos: they use the hosting plan’s disk and bandwidth, and members-only ones pass through PHP. No transcoding — upload an MP4 browsers can play.';
    }

    public function capabilities(): VideoCapabilities
    {
        return new VideoCapabilities(upload: true, mp4: true, enforcesPrivacy: true, progressEvents: true);
    }

    public static function isName(string $name): bool
    {
        return (bool) preg_match('/^[a-f0-9]{24}\.(mp4|m4v|webm|mov)$/', $name);
    }

    public static function privatePath(string $name): string
    {
        return self::$storageDir . '/' . $name;
    }

    public static function publicPath(string $name): string
    {
        return self::$publicDir . '/' . $name;
    }

    public function createUpload(string $title, UploadHints $hints): UploadTicket
    {
        $ext = strtolower(pathinfo($hints->fileName, PATHINFO_EXTENSION));
        $name = bin2hex(random_bytes(12)) . '.' . (in_array($ext, ['mp4', 'm4v', 'webm', 'mov'], true) ? $ext : 'mp4');
        return new UploadTicket('chunked', $name, ['purpose' => 'video'], ['type' => $ext === 'webm' ? 'video/webm' : 'video/mp4', 'public' => false]);
    }

    /** $video->data['localPath'] is the finished chunked upload; it becomes the private copy. */
    public function completeUpload(VideoRef $video): VideoInfo
    {
        $from = (string) ($video->data['localPath'] ?? '');
        if (!self::isName($video->id) || !is_file($from)) {
            throw new VideoProviderException('The upload didn’t arrive complete.');
        }
        $ext = pathinfo($video->id, PATHINFO_EXTENSION);
        if (!UploadTypes::bytesMatch($from, $ext === 'm4v' ? 'mp4' : $ext)) {
            throw new VideoProviderException('That file isn’t a video this site can play.');
        }
        self::ensureDir(self::$storageDir, false);
        if (!@rename($from, self::privatePath($video->id)) && !(@copy($from, self::privatePath($video->id)) && @unlink($from))) {
            throw new VideoProviderException('storage/videos isn’t writable.');
        }
        return new VideoInfo('READY');
    }

    private static function ensureDir(string $dir, bool $public): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new VideoProviderException(($public ? 'public/media/videos' : 'storage/videos') . ' can’t be created: the folder isn’t writable.');
        }
        if ($public && !is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Options -Indexes -ExecCGI\nphp_flag engine off\nRemoveHandler .php .phtml .phar\n<FilesMatch \"\\.(php|phtml|phar|html?|svg|js)$\">\n  Require all denied\n</FilesMatch>\n<IfModule mod_headers.c>\n  Header set X-Content-Type-Options \"nosniff\"\n</IfModule>\n");
            @file_put_contents($dir . '/index.html', '');
        }
    }

    /**
     * Puts the file where its audience says: the public folder when anyone
     * may watch, storage otherwise. Returns the provider data to keep.
     *
     * @return array<string, mixed>
     */
    public function publish(VideoRef $video, bool $public): array
    {
        if (!self::isName($video->id)) {
            return $video->data;
        }
        $private = self::privatePath($video->id);
        $open = self::publicPath($video->id);
        if ($public && !is_file($open) && is_file($private)) {
            self::ensureDir(self::$publicDir, true);
            if (!@rename($private, $open)) {
                throw new VideoProviderException('public/media/videos isn’t writable, so the video can’t be published for the web server to serve.');
            }
        } elseif (!$public && is_file($open)) {
            self::ensureDir(self::$storageDir, false);
            @rename($open, $private);
        }
        return ['public' => is_file($open)] + $video->data;
    }

    public function owns(VideoRef $video): bool
    {
        return self::isName($video->id);
    }

    public function delete(VideoRef $video): void
    {
        if (!self::isName($video->id)) {
            return;
        }
        foreach ([self::privatePath($video->id), self::publicPath($video->id)] as $path) {
            if (is_file($path) && !@unlink($path)) {
                throw new VideoProviderException('The video file couldn’t be deleted.');
            }
        }
    }

    /** Where the file is on disk now (in storage or public), or null. */
    public static function filePath(VideoRef $video): ?string
    {
        if (!self::isName($video->id)) {
            return null;
        }
        foreach ([self::publicPath($video->id), self::privatePath($video->id)] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }
        return null;
    }

    public function playbackUrl(VideoRef $video): string
    {
        return is_file(self::publicPath($video->id))
            ? Url::to('/media/videos/' . $video->id)
            : Url::to('/api/videos/local/' . $video->id);
    }

    public function player(VideoRef $video, PlayerOptions $options): PlayerSpec
    {
        return PlayerSpec::native([['src' => $this->playbackUrl($video), 'type' => (string) ($video->data['type'] ?? 'video/mp4')]], isset($video->data['poster']) ? (string) $video->data['poster'] : null);
    }

    public function thumbnailUrl(VideoRef $video, ?string $file): ?string
    {
        return isset($video->data['poster']) ? (string) $video->data['poster'] : null;
    }

    public function mp4(VideoRef $video, int $maxHeight): Mp4Result
    {
        return ($video->data['type'] ?? 'video/mp4') === 'video/mp4' ? Mp4Result::ok($this->playbackUrl($video)) : Mp4Result::reason('not_supported');
    }

    public function test(TestContext $context): TestResult
    {
        try {
            self::ensureDir(self::$storageDir, false);
            self::ensureDir(self::$publicDir, true);
        } catch (VideoProviderException $e) {
            return TestResult::fail($e->getMessage());
        }
        $probe = 'probe-' . bin2hex(random_bytes(6)) . '.txt';
        $content = 'marine-team ' . bin2hex(random_bytes(4));
        if (@file_put_contents(self::publicPath($probe), $content) === false) {
            return TestResult::fail('public/media/videos isn’t writable by PHP.');
        }
        try {
            $r = Http::request('GET', Url::absolute('/media/videos/' . $probe), [], null, ['timeout' => 8]);
            $served = $r->ok() && trim($r->body) === $content;
        } catch (\Throwable) {
            $served = false;
        } finally {
            @unlink(self::publicPath($probe));
        }
        $free = @disk_free_space(self::$storageDir);
        $space = is_float($free) ? ' About ' . round($free / 1073741824, 1) . ' GB of disk is free.' : '';
        if (!$served) {
            return TestResult::fail('The folder is writable, but the web server didn’t serve a test file from /media/videos/. Check that public/ is the site’s document root.' . $space);
        }
        return TestResult::ok('Writable, and the web server serves files from /media/videos/.' . $space . ' This is for a few videos: each one uses the plan’s disk and bandwidth.');
    }
}
