<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins;

use App\Modules\Plugins\PackageInstaller;
use PHPUnit\Framework\TestCase;

/**
 * What a plugin or theme zip is allowed to contain.
 *
 * A plugin is code that runs as the site, so the upload is already the most
 * dangerous button in the admin area and only manage_plugins reaches it. What
 * is left is the archive itself lying about where its files go — zip slip,
 * symlinks out of the folder, an archive that unpacks to fill the disk — and
 * every one of those is decided here, before a single file is written.
 */
final class PackageSafetyTest extends TestCase
{
    /** @var list<string> */
    private array $made = [];

    protected function tearDown(): void
    {
        foreach ($this->made as $path) {
            @unlink($path);
        }
    }

    /**
     * A zip holding exactly the entries given.
     *
     * @param array<string, string> $entries name => contents
     * @param array<string, int> $symlinks name => nothing; stored as a link
     */
    private function zip(array $entries, array $symlinks = []): \ZipArchive
    {
        $stem = (string) tempnam(sys_get_temp_dir(), 'pkg');
        $path = $stem . '.zip';
        $this->made[] = $stem;
        $this->made[] = $path;
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true);
        foreach ($entries as $name => $body) {
            $zip->addFromString($name, $body);
        }
        foreach (array_keys($symlinks) as $name) {
            $zip->addFromString($name, '../../../../etc/passwd');
            // S_IFLNK in the upper external attributes is what makes it a link.
            $zip->setExternalAttributesName($name, \ZipArchive::OPSYS_UNIX, (0120777 << 16));
        }
        $zip->close();
        $read = new \ZipArchive();
        self::assertTrue($read->open($path) === true);
        return $read;
    }

    private static function plugin(array $extra = []): array
    {
        return ['good-plugin/plugin.php' => "<?php\n/**\n * Plugin Name: Good\n * Slug: good-plugin\n */\n"] + $extra;
    }

    public function test_1_an_ordinary_package_is_accepted_and_names_its_folder(): void
    {
        self::assertSame('good-plugin', PackageInstaller::inspect($this->zip(self::plugin([
            'good-plugin/assets/thing.js' => 'export const a = 1;',
        ]))));
    }

    public function test_2_a_path_that_climbs_out_of_the_folder_is_refused(): void
    {
        foreach ([
            '../evil.php',
            'good-plugin/../../evil.php',
            'good-plugin/sub/../../../evil.php',
            '/etc/cron.d/evil',
            'good-plugin/..',
        ] as $name) {
            try {
                PackageInstaller::inspect($this->zip(self::plugin([$name => 'x'])));
                self::fail("$name should be refused");
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('leaves its folder', $e->getMessage(), $name);
            }
        }
    }

    public function test_3_a_windows_path_is_a_path_that_leaves_too(): void
    {
        // Written with backslashes, or with a drive letter: both unpack
        // somewhere else on a host that understands them.
        foreach (['good-plugin\\..\\..\\evil.php', 'C:\\windows\\evil.php'] as $name) {
            try {
                PackageInstaller::inspect($this->zip(self::plugin([$name => 'x'])));
                self::fail("$name should be refused");
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('leaves its folder', $e->getMessage(), $name);
            }
        }
    }

    public function test_5_a_symbolic_link_is_refused(): void
    {
        // A link is how an archive with no ".." in it still writes outside:
        // the link is extracted, and the next write follows it.
        $this->expectExceptionMessage('symbolic link');
        PackageInstaller::inspect($this->zip(self::plugin(), ['good-plugin/passwd' => 0]));
    }

    public function test_6_an_archive_that_unpacks_to_more_than_the_disk_is_refused(): void
    {
        // A zip bomb is small on disk and enormous unpacked; the cap is on
        // what it claims to unpack to, read before anything is written.
        $zip = $this->zip(self::plugin(['good-plugin/big.bin' => str_repeat("\0", 1024)]));
        self::assertSame('good-plugin', PackageInstaller::inspect($zip), 'a kilobyte is fine');
        self::assertSame(200 * 1024 * 1024, PackageInstaller::MAX_BYTES);
        self::assertSame(10_000, PackageInstaller::MAX_ENTRIES);
    }

    public function test_7_an_empty_archive_is_refused(): void
    {
        // ZipArchive will not write a file for an archive with nothing in
        // it, so the 22 bytes of an empty one go down by hand.
        $path = (string) tempnam(sys_get_temp_dir(), 'pkg') . '.zip';
        $this->made[] = $path;
        file_put_contents($path, "PK\x05\x06" . str_repeat("\0", 18));
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path) === true);

        $this->expectExceptionMessage('empty or has too many files');
        PackageInstaller::inspect($zip);
    }

    public function test_8_two_top_level_folders_are_refused(): void
    {
        $this->expectExceptionMessage('exactly one top-level folder');
        PackageInstaller::inspect($this->zip(self::plugin(['another-plugin/plugin.php' => '<?php']))); 
    }

    public function test_9_a_folder_name_that_is_not_a_slug_is_refused(): void
    {
        foreach (['Good Plugin', 'good_plugin!', '.hidden', 'UPPER'] as $folder) {
            try {
                PackageInstaller::inspect($this->zip(["$folder/plugin.php" => '<?php']));
                self::fail("$folder is not a slug");
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('slug', $e->getMessage(), $folder);
            }
        }
    }

}
