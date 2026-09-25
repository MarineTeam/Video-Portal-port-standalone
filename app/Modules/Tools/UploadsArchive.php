<?php

declare(strict_types=1);

namespace App\Modules\Tools;

use App\Core\App;

/**
 * storage/uploads (and storage/media, the uploaded logo and artwork, and
 * the videos kept on the host's own disk, private or public) as zip
 * files of about 100 MB each, built one per request and deleted once
 * downloaded, so a site with gigabytes of files never needs one huge
 * archive or one long request. Files are stored, not compressed: they are
 * mostly audio, video, images and PDFs that don't shrink.
 */
final class UploadsArchive
{
    public const PART_BYTES = 100 * 1024 * 1024;
    public const ROOTS = ['uploads', 'media', 'videos'];

    /** @var array<string, string> path inside the zip => the file on disk */
    private array $sources = [];

    public function __construct(private readonly App $app)
    {
    }

    /** @return list<array{0: string, 1: string}> zip prefix, directory */
    private function roots(): array
    {
        $out = [];
        foreach (self::ROOTS as $root) {
            $out[] = [$root, $this->app->paths->storage($root)];
        }
        // Videos anyone may watch sit where the web server serves them; in
        // the archive they join the private ones (a save moves them back).
        $out[] = ['videos', $this->app->paths->public() . '/media/videos'];
        return $out;
    }

    /**
     * Every file, sorted, as path-inside-the-zip => size.
     *
     * @return array<string, int>
     */
    public function files(): array
    {
        $out = [];
        foreach ($this->roots() as [$root, $dir]) {
            if (!is_dir($dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                /** @var \SplFileInfo $file */
                // Skip links, hidden files and the guard files that keep a
                // web server from listing or running anything in here.
                if ($file->isLink() || !$file->isFile() || str_starts_with($file->getFilename(), '.') || in_array($file->getFilename(), ['index.php', 'index.html'], true)) {
                    continue;
                }
                $rel = $root . '/' . str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
                $out[$rel] = (int) $file->getSize();
                $this->sources[$rel] = $file->getPathname();
            }
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /**
     * Splits the files into parts of at most $partBytes (a file bigger than
     * that is a part of its own).
     *
     * @param array<string, int> $files
     * @return list<array{files: list<string>, bytes: int}>
     */
    public static function partition(array $files, int $partBytes = self::PART_BYTES): array
    {
        $parts = [];
        $current = ['files' => [], 'bytes' => 0];
        foreach ($files as $path => $size) {
            if ($current['files'] !== [] && $current['bytes'] + $size > $partBytes) {
                $parts[] = $current;
                $current = ['files' => [], 'bytes' => 0];
            }
            $current['files'][] = $path;
            $current['bytes'] += $size;
        }
        if ($current['files'] !== []) {
            $parts[] = $current;
        }
        return $parts;
    }

    /** @return list<array{files: list<string>, bytes: int}> */
    public function parts(): array
    {
        return self::partition($this->files());
    }

    /** Builds part $index (0-based) and returns its path under storage/tmp/backups. */
    public function build(int $index): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('This host has no zip extension; download storage/uploads by FTP instead.');
        }
        $parts = $this->parts();
        if (!isset($parts[$index])) {
            throw new \RuntimeException('That part doesn’t exist any more; reload the page.');
        }
        $dir = $this->app->paths->storage('tmp/backups');
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException('storage/tmp is not writable.');
        }
        $path = $dir . '/' . bin2hex(random_bytes(16)) . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
            throw new \RuntimeException('The archive could not be created in storage/tmp.');
        }
        foreach ($parts[$index]['files'] as $rel) {
            $zip->addFile($this->sources[$rel] ?? $this->app->paths->storage($rel), $rel);
            $zip->setCompressionName($rel, \ZipArchive::CM_STORE);
        }
        if (!$zip->close()) {
            @unlink($path);
            throw new \RuntimeException('The archive could not be written (is the disk full?).');
        }
        return $path;
    }
}
