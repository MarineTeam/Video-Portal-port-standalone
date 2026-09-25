<?php

declare(strict_types=1);

namespace App\Services\Files;

use App\Core\Request;
use App\Core\Response;
use App\Services\BaseProvider;
use App\Services\TestContext;
use App\Services\TestResult;

/**
 * Files on this host's own disk, under storage/uploads — never under the
 * document root — streamed by the app route with Range support. Works with
 * no account anywhere, which is why it is the default.
 */
final class LocalDiskProvider extends BaseProvider implements FilesProvider
{
    private static ?string $root = null;

    public static function configureRoot(string $storageUploads): void
    {
        self::$root = rtrim($storageUploads, '/');
    }

    public static function slot(): string
    {
        return 'files';
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
        return 'Every download passes through PHP and counts against the hosting plan’s disk and bandwidth.';
    }

    private function root(): string
    {
        return self::$root ?? throw new \LogicException('Local storage root not configured.');
    }

    /** Resolves an object name to a path, refusing anything that walks. */
    public function path(string $object): string
    {
        if (!preg_match('#^[a-z0-9_-]+(/[a-z0-9_-]+)*\.[a-z0-9]{1,8}$#', $object)) {
            throw new \InvalidArgumentException('Bad object name.');
        }
        return $this->root() . '/' . $object;
    }

    public function put(string $localPath, string $object): void
    {
        $target = $this->path($object);
        if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) {
            throw new \RuntimeException('The uploads folder is not writable.');
        }
        if (!@rename($localPath, $target)) {
            if (!@copy($localPath, $target)) {
                throw new \RuntimeException('Could not store the file.');
            }
            @unlink($localPath);
        }
        @chmod($target, 0644);
    }

    public function delete(string $object): void
    {
        $path = $this->path($object);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function exists(string $object): bool
    {
        return is_file($this->path($object));
    }

    public function serve(string $object, Request $request, array $headers): Response
    {
        return RangeStreamer::serve($this->path($object), $request, $headers, dirname($this->root()));
    }

    public function open(string $object): mixed
    {
        $handle = @fopen($this->path($object), 'rb');
        return $handle === false ? null : $handle;
    }

    public function test(TestContext $context): TestResult
    {
        $object = 'probe/' . bin2hex(random_bytes(6)) . '.txt';
        $tmp = tempnam(sys_get_temp_dir(), 'mt');
        if ($tmp === false) {
            return TestResult::fail('PHP cannot create temporary files on this host.');
        }
        file_put_contents($tmp, 'probe');
        try {
            $this->put($tmp, $object);
            $ok = file_get_contents($this->path($object)) === 'probe';
            $this->delete($object);
            @rmdir(dirname($this->path($object)));
        } catch (\Throwable $e) {
            return TestResult::fail('The uploads folder can’t be written: ' . $e->getMessage() . ' Check that storage/uploads is writable by the web server.');
        }
        return $ok
            ? TestResult::ok('Wrote, read back and deleted a probe file.', ['write', 'read', 'delete'])
            : TestResult::fail('A probe file came back different from what was written.');
    }
}
