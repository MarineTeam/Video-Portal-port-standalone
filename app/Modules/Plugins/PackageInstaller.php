<?php

declare(strict_types=1);

namespace App\Modules\Plugins;

use App\Core\App;
use App\Core\Cache;
use App\Core\Id;
use App\Core\Log;
use App\Modules\Themes\ThemeLoader;

/**
 * Installs a plugin or theme from a zip.
 *
 * The archive must hold exactly one top-level directory, whose header parses
 * and whose slug is that directory's name. Entries with "..", absolute paths
 * or symlinks are refused, as are archives over 10,000 entries or 200 MB
 * uncompressed. It is extracted into storage/tmp and moved into place only
 * after every check passed. Installing is not activating.
 */
final class PackageInstaller
{
    public const MAX_ENTRIES = 10_000;
    public const MAX_BYTES = 200 * 1024 * 1024;

    public function __construct(private readonly App $app)
    {
    }

    /**
     * Checks an archive's listing without extracting anything.
     *
     * @return string the single top-level directory
     */
    public static function inspect(\ZipArchive $zip): string
    {
        if ($zip->numFiles === 0 || $zip->numFiles > self::MAX_ENTRIES) {
            throw new \RuntimeException('The archive is empty or has too many files.');
        }
        $total = 0;
        $top = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                throw new \RuntimeException('The archive could not be read.');
            }
            $name = str_replace('\\', '/', (string) $stat['name']);
            if ($name === '' || str_starts_with($name, '/') || preg_match('#(^|/)\.\.(/|$)#', $name) || preg_match('#^[a-zA-Z]:#', $name) || str_contains($name, "\0")) {
                throw new \RuntimeException('The archive contains a path that leaves its folder.');
            }
            // A symlink is stored with S_IFLNK in the upper external attributes.
            if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === \ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000) {
                throw new \RuntimeException('The archive contains a symbolic link.');
            }
            $total += (int) $stat['size'];
            if ($total > self::MAX_BYTES) {
                throw new \RuntimeException('The archive is larger than 200 MB unpacked.');
            }
            $first = explode('/', $name)[0];
            if ($top === null) {
                $top = $first;
            } elseif ($first !== $top) {
                throw new \RuntimeException('The archive must contain exactly one top-level folder.');
            }
        }
        if ($top === null || !preg_match(Header::SLUG_PATTERN, $top)) {
            throw new \RuntimeException('The archive’s folder name is not a valid slug.');
        }
        return $top;
    }

    /** @param 'plugin'|'theme' $kind */
    public function install(string $zipPath, string $kind): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('This host has no zip extension. Unzip the package on your computer and upload its folder into ' . $kind . 's/ by FTP or the file manager, then refresh this page.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('That file is not a readable zip archive.');
        }
        try {
            $slug = self::inspect($zip);
            $staging = $this->app->paths->storage('tmp/packages/' . Id::new());
            if (!@mkdir($staging, 0770, true)) {
                throw new \RuntimeException('storage/tmp is not writable.');
            }
            if (!$zip->extractTo($staging)) {
                throw new \RuntimeException('The archive could not be extracted.');
            }
        } finally {
            $zip->close();
        }
        try {
            $dir = "$staging/$slug";
            if ($kind === 'plugin') {
                $header = Header::read("$dir/plugin.php");
                if ($header === null || $header['slug'] !== $slug) {
                    throw new \RuntimeException('plugin.php is missing, or its header doesn’t parse, or its Slug doesn’t match the folder name.');
                }
                if (version_compare(PHP_VERSION, $header['requiresPhp'], '<')) {
                    throw new \RuntimeException("This plugin needs PHP {$header['requiresPhp']}.");
                }
                // A package can never claim to be bundled.
                @unlink("$dir/.bundled");
            } else {
                $meta = ThemeLoader::readMeta("$dir/theme.json");
                if ($meta === null || $meta['slug'] !== $slug) {
                    throw new \RuntimeException('theme.json is missing, or its slug doesn’t match the folder name.');
                }
            }
            $target = ($kind === 'plugin' ? $this->app->paths->plugins : $this->app->paths->themes) . '/' . $slug;
            if ($kind === 'plugin' && is_file("$target/.bundled")) {
                throw new \RuntimeException('A bundled plugin with that slug already exists; it can’t be replaced by an upload.');
            }
            // Updating is uploading a newer version over the old one.
            if (is_dir($target)) {
                $old = $target . '.old-' . Id::random(6);
                if (!@rename($target, $old)) {
                    throw new \RuntimeException('The existing copy could not be moved aside.');
                }
                if (!@rename($dir, $target)) {
                    @rename($old, $target);
                    throw new \RuntimeException('The new copy could not be moved into place.');
                }
                self::removeTree($old);
            } elseif (!@rename($dir, $target)) {
                throw new \RuntimeException("The {$kind}s/ folder is not writable.");
            }
            self::protectAssets($target);
        } finally {
            self::removeTree($staging);
        }
        Cache::forget('plugins-active');
        return $slug;
    }

    /** Nothing under assets/ may execute, even where the server would otherwise run PHP there. */
    public static function protectAssets(string $dir): void
    {
        if (is_dir("$dir/assets")) {
            @file_put_contents("$dir/assets/.htaccess", "php_flag engine off\nRemoveHandler .php .phtml .phar\nRemoveType .php .phtml .phar\n<FilesMatch \"\\.(php|phtml|phar|pl|py|cgi|sh)$\">\n  Require all denied\n</FilesMatch>\nOptions -Indexes -ExecCGI\n");
        }
    }

    /** Explicit "delete data": runs uninstall(), then removes the files and row. */
    public function uninstall(string $slug): void
    {
        $loader = $this->app->plugins();
        $header = $loader->available()[$slug] ?? null;
        if ($header !== null) {
            try {
                $plugin = (static fn (string $__file) => require $__file)($header['dir'] . '/plugin.php');
                if ($plugin instanceof Plugin) {
                    $plugin->deactivate($this->app);
                    $plugin->uninstall($this->app);
                }
            } catch (\Throwable $e) {
                Log::warning("Plugin $slug's uninstall() threw: " . $e->getMessage());
            }
            self::removeTree($header['dir']);
        }
        $this->app->db()->delete('plugins', ['slug' => $slug]);
        Cache::forget('plugins-active');
        PluginStates::forget();
    }

    public static function removeTree(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            if (is_link($dir) || is_file($dir)) {
                @unlink($dir);
            }
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
