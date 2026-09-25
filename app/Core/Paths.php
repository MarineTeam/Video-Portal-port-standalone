<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Where things live on disk. The default keeps one tree; the installer may
 * move storage/ (and with it config.php) above the document root, recording
 * where in a one-line storage-path.php beside app/.
 */
final class Paths
{
    public function __construct(
        public readonly string $root,
        public readonly string $storage,
        public readonly string $plugins,
        public readonly string $themes,
    ) {
    }

    public static function detect(string $root): self
    {
        $root = rtrim($root, '/');
        $storage = "$root/storage";
        $pointer = "$root/storage-path.php";
        // The test suite installs into a throwaway directory; no host sets this.
        $override = getenv('MT_STORAGE_DIR');
        if (is_string($override) && $override !== '' && is_dir($override)) {
            return new self($root, rtrim($override, '/'), "$root/plugins", "$root/themes");
        }
        if (is_file($pointer)) {
            $candidate = require $pointer;
            if (is_string($candidate) && is_dir($candidate)) {
                $storage = rtrim($candidate, '/');
            }
        }
        return new self($root, $storage, "$root/plugins", "$root/themes");
    }

    public function app(): string
    {
        return $this->root . '/app';
    }

    public function public(): string
    {
        return $this->root . '/public';
    }

    public function config(): string
    {
        return $this->storage . '/config.php';
    }

    public function installedLock(): string
    {
        return $this->storage . '/installed.lock';
    }

    public function maintenance(): string
    {
        return $this->storage . '/maintenance';
    }

    public function storage(string $sub = ''): string
    {
        return $sub === '' ? $this->storage : $this->storage . '/' . ltrim($sub, '/');
    }
}
