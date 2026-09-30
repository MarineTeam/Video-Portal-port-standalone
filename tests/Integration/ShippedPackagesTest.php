<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\App;
use App\Core\Paths;
use App\Modules\Plugins\PackageInstaller;

/**
 * The themes and plugins this port ships, packaged the way its own
 * documentation tells an author to package one, and installed.
 *
 * ThemesTest and PackageSafetyTest both build their zips in the test: the
 * right way to ask what happens to a traversal path or a symlink, since
 * those are what an attacker sends. But every one of them is a handful of
 * files written beside the code that reads them, and none is a real theme
 * or plugin. The obvious way to write a third-party theme is to copy
 * themes/default and change it — so if what this port ships could not be
 * installed as a package, the first thing every author does would fail,
 * and nothing here would say so.
 *
 * Two guards ride along, both of which the installer states in a comment
 * and neither of which had a test: an uploaded package can never claim to
 * be bundled, and a bundled plugin cannot be replaced by an upload. The
 * bundled badge is what hides the Delete button, so a package that could
 * claim it would be one an administrator could not remove.
 */
final class ShippedPackagesTest extends DatabaseTestCase
{
    private string $work = '';
    private string $root = '';

    protected function setUp(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('the zip extension is needed to make a package.');
        }
        $this->work = sys_get_temp_dir() . '/mt-pkg-' . bin2hex(random_bytes(4));
        $this->root = $this->work . '/site';
        foreach (['themes', 'plugins', 'storage/tmp'] as $dir) {
            mkdir($this->root . '/' . $dir, 0775, true);
        }
    }

    protected function tearDown(): void
    {
        PackageInstaller::removeTree($this->work);
    }

    private function app(): App
    {
        return new App(new Paths($this->root, $this->root . '/storage', $this->root . '/plugins', $this->root . '/themes'));
    }

    /**
     * One folder named after the slug, holding what the shipped copy holds
     * — which is what THEMES.md and PLUGINS.md both describe.
     *
     * @param callable(string, string): string $rewrite path, body => body
     */
    private function package(string $from, string $slug, callable $rewrite): string
    {
        $path = $this->work . '/' . $slug . '.zip';
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true);
        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($from) + 1);
            $zip->addFromString("$slug/$relative", $rewrite($relative, (string) file_get_contents($file->getPathname())));
        }
        $zip->close();
        return $path;
    }

    public function testTheShippedThemeInstallsAsAThirdPartyThemeWould(): void
    {
        $root = dirname(__DIR__, 2);
        $zip = $this->package($root . '/themes/default', 'harbour', static function (string $path, string $body): string {
            if ($path !== 'theme.json') {
                return $body;
            }
            $meta = json_decode($body, true);
            return (string) json_encode(['slug' => 'harbour', 'name' => 'Harbour', 'parent' => 'default'] + (array) $meta);
        });

        $slug = (new PackageInstaller($this->app()))->install($zip, 'theme');
        self::assertSame('harbour', $slug);
        self::assertFileExists($this->root . '/themes/harbour/theme.json');
        self::assertFileExists($this->root . '/themes/harbour/assets/theme.css');
        // The installer adds its own guard to any assets folder, so a theme
        // that ships a .php beside its stylesheet cannot have it executed.
        self::assertFileExists($this->root . '/themes/harbour/assets/.htaccess');
    }

    public function testAShippedPluginInstallsAsAThirdPartyPluginWould(): void
    {
        $root = dirname(__DIR__, 2);
        $from = $root . '/plugins/book-reader';
        self::assertFileExists($from . '/.bundled', 'this is one of the bundled ones');

        $zip = $this->package($from, 'harbour-reader', static function (string $path, string $body): string {
            // A plugin's header names its slug and the folder must match.
            return $path === 'plugin.php'
                ? (string) preg_replace('/^(\s*\*\s*Slug:\s*).*$/mi', '${1}harbour-reader', $body, 1)
                : $body;
        });

        $slug = (new PackageInstaller($this->app()))->install($zip, 'plugin');
        self::assertSame('harbour-reader', $slug);
        self::assertFileExists($this->root . '/plugins/harbour-reader/plugin.php');
        self::assertFileExists($this->root . '/plugins/harbour-reader/src/Books.php', 'the whole tree, not just the top');
        self::assertFileExists($this->root . '/plugins/harbour-reader/assets/.htaccess');

        // The guard the installer states in a comment: a package can never
        // claim to be bundled. The badge is what hides the Delete button.
        self::assertFileDoesNotExist(
            $this->root . '/plugins/harbour-reader/.bundled',
            'an upload cannot arrive claiming to be one of ours',
        );
    }

    public function testABundledPluginCannotBeReplacedByAnUpload(): void
    {
        $root = dirname(__DIR__, 2);
        // A plugin already on disk and marked bundled, as a real install has.
        mkdir($this->root . '/plugins/book-reader', 0775, true);
        touch($this->root . '/plugins/book-reader/.bundled');

        $zip = $this->package($root . '/plugins/book-reader', 'book-reader', static fn (string $p, string $b): string => $b);

        $this->expectExceptionMessageMatches('/bundled plugin with that slug/');
        (new PackageInstaller($this->app()))->install($zip, 'plugin');
    }
}
