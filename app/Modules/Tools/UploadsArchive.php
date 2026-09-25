<?php

declare(strict_types=1);

namespace App\Modules\Tools;

use App\Core\App;

/**
 * storage/uploads (and storage/media, the uploaded logo and artwork) as zip
 * files of about 100 MB each, built one per request and deleted once
 * downloaded, so a site with gigabytes of files never needs one huge
 * archive or one long request. Files are stored, not compressed: they are
 * mostly audio, video, images and PDFs that don't shrink.
 */
final class UploadsArchive
{
    public const PART_BYTES = 100 * 1024 * 1024;
    public const ROOTS = ['uploads', 'media'];

    public function __construct(private readonly App $app)
    {
    }

    /**
     * Every file, sorted, as path-inside-the-zip => size.
     *
     * @return array<string, int>
     */
    public function files(): array
    {
        $out = [];
        foreach (self::ROOTS as $root) {
            $dir = $this->app->paths->storage($root);
            if (!is_dir($dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                /** @var \SplFileInfo $file */
                // Skip links, hidden files and the guard files that keep a
                // web server from listing or running anything in here.
                if ($file->isLink() || !$file->isFile() || str_starts_with($file->getFilename(), '.') || $file->getFilename() === 'index.php') {
                    continue;
                }
                $rel = $root . '/' . str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
                $out[$rel] = (int) $file->getSize();
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
            $zip->addFile($this->app->paths->storage($rel), $rel);
            $zip->setCompressionName($rel, \ZipArchive::CM_STORE);
        }
        if (!$zip->close()) {
            @unlink($path);
            throw new \RuntimeException('The archive could not be written (is the disk full?).');
        }
        return $path;
    }
}
