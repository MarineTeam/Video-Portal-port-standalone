<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Paths;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Where the site keeps the one directory it writes to.
 *
 * By default that is storage/ inside the install, protected by an .htaccess.
 * On a host that ignores .htaccess the installer refuses to go on and says to
 * move storage/ above the document root, and a one-line storage-path.php
 * beside app/ is what tells the site where it went. Everything the site holds
 * that is not in the database is in there: config.php with the database
 * password and the key every stored secret is encrypted with, the sessions,
 * the uploads, the logs.
 *
 * So the pointer is not a preference. Once it exists it is the only correct
 * answer to "where is storage", and guessing instead of following it means
 * guessing the web-readable folder the administrator moved away from.
 */
final class StorageLocationTest extends TestCase
{
    private string $root = '';
    private string $away = '';

    protected function setUp(): void
    {
        putenv('MT_STORAGE_DIR');
        $this->root = sys_get_temp_dir() . '/mt-root-' . bin2hex(random_bytes(4));
        $this->away = sys_get_temp_dir() . '/mt-away-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/app', 0775, true);
        mkdir($this->root . '/storage', 0775, true);
        mkdir($this->away, 0775, true);
    }

    protected function tearDown(): void
    {
        \App\Modules\Plugins\PackageInstaller::removeTree($this->root);
        \App\Modules\Plugins\PackageInstaller::removeTree($this->away);
    }

    private function pointAt(string $value): void
    {
        file_put_contents($this->root . '/storage-path.php', "<?php return $value;\n");
    }

    #[TestDox('with no pointer, storage sits in the install as it always has')]
    public function testTheDefault(): void
    {
        $paths = Paths::detect($this->root);
        self::assertSame($this->root . '/storage', $paths->storage);
        self::assertSame($this->root . '/storage/config.php', $paths->config());
        self::assertSame($this->root . '/plugins', $paths->plugins, 'the rest of the tree does not move with it');
        self::assertSame($this->root . '/themes', $paths->themes);
    }

    #[TestDox('a pointer moves storage, and nothing else')]
    public function testThePointerIsFollowed(): void
    {
        $this->pointAt(var_export($this->away, true));
        $paths = Paths::detect($this->root);
        self::assertSame($this->away, $paths->storage);
        self::assertSame($this->away . '/config.php', $paths->config());
        self::assertSame($this->away . '/installed.lock', $paths->installedLock());
        self::assertSame($this->root . '/plugins', $paths->plugins);

        // A trailing slash is what somebody types; it must not double up.
        $this->pointAt(var_export($this->away . '/', true));
        self::assertSame($this->away, Paths::detect($this->root)->storage);
    }

    #[TestDox('a pointer that leads nowhere stops the site rather than guessing')]
    public function testAPointerThatLeadsNowhere(): void
    {
        // A host migration that changed the absolute path, a typo, a restore
        // that put the folder back somewhere else. Falling back to the tree
        // means the site cannot find its config, decides it was never
        // installed, and offers the installer to every visitor — writing a
        // fresh storage folder, with its install key, into the very place the
        // administrator moved away from because it can be downloaded.
        // Keyed by the reason, because PHP would turn a key of "42" into a
        // number and the point of that one is that it is written as code.
        foreach ([
            'a directory that is not there' => "'/no/such/place'",
            'a file rather than a directory' => "'" . $this->root . "/app/bootstrap.php'",
            'something that is not a path at all' => '42',
            'nothing' => 'null',
            'an empty string' => "''",
        ] as $why => $value) {
            $this->pointAt($value);
            try {
                $storage = Paths::detect($this->root)->storage;
                self::fail("$why: carried on with $storage");
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('storage-path.php', $e->getMessage(), $why);
            }
        }
    }

    #[TestDox('the test suite\'s own override still wins, and only it')]
    public function testTheTestOverride(): void
    {
        // The suite installs throwaway sites into temporary directories. No
        // host sets this, and it is read before the pointer so a checkout
        // with a pointer in it can still be tested.
        $this->pointAt(var_export($this->away, true));
        $elsewhere = sys_get_temp_dir() . '/mt-env-' . bin2hex(random_bytes(4));
        mkdir($elsewhere, 0775, true);
        putenv("MT_STORAGE_DIR=$elsewhere");
        self::assertSame($elsewhere, Paths::detect($this->root)->storage);

        // Pointed at nothing, it is ignored rather than obeyed: it is a
        // convenience for the suite, not an instruction from an administrator.
        putenv('MT_STORAGE_DIR=/no/such/place');
        self::assertSame($this->away, Paths::detect($this->root)->storage, 'and the pointer is still followed');
        putenv('MT_STORAGE_DIR');
        \App\Modules\Plugins\PackageInstaller::removeTree($elsewhere);
    }
}
