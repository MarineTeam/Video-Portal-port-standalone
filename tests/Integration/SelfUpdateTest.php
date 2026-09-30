<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\App;
use App\Core\Paths;
use App\Modules\Plugins\PackageInstaller;
use App\Modules\Update\Release;

/**
 * A site updating itself, from a release the real builder really built.
 *
 * ReleaseTest states each rule against a zip assembled in the test — the
 * right way to ask what happens to an unsafe path or a broken checksum,
 * because those zips are what an attacker sends. But every one of them was
 * also written beside the code that reads it, so none of them can say
 * whether what `tools/release/build.php` puts out is something
 * `Release::prepare` accepts. CI ran the builder and checked only that it
 * exited 0.
 *
 * That gap is the whole update path: every church gets its security fixes
 * through this, and a release the updater rejects, or accepts and installs
 * wrongly, is found on the day it ships.
 *
 * So this builds a real signed release, then walks a copy of the install
 * through prepare, extract, swap and rollback, and looks at the files on
 * disk at each step.
 */
final class SelfUpdateTest extends DatabaseTestCase
{
    private string $work = '';
    private string $root = '';
    private string $zip = '';
    private string $pub = '';

    protected function setUp(): void
    {
        if (!class_exists(\ZipArchive::class) || !function_exists('sodium_crypto_sign_keypair')) {
            self::markTestSkipped('zip and sodium are needed to build a release.');
        }
        $this->work = sys_get_temp_dir() . '/mt-update-' . bin2hex(random_bytes(4));
        mkdir($this->work, 0775, true);
    }

    protected function tearDown(): void
    {
        if ($this->work !== '') {
            PackageInstaller::removeTree($this->work);
        }
    }

    /** Builds a release with the real builder, signed with a key made here. */
    private function buildRelease(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $this->pub = $this->work . '/release-key.pub';
        file_put_contents($this->pub, "# a key for this test only\n" . base64_encode(sodium_crypto_sign_publickey($pair)) . "\n");
        $this->zip = $this->work . '/release.zip';

        $process = proc_open(
            [PHP_BINARY, 'tools/release/build.php', '--out', $this->zip],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
            array_merge(getenv(), ['RELEASE_SIGNING_KEY' => base64_encode(sodium_crypto_sign_secretkey($pair))]),
        );
        self::assertIsResource($process, 'the builder would not start');
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), "the builder failed:\n$err");
        self::assertStringContainsString('signed', $out, "the release is signed:\n$out");
        self::assertFileExists($this->zip);
    }

    /** A copy of this install, which the update is applied to instead of the real one. */
    private function copyOfTheSite(): App
    {
        $this->root = $this->work . '/site';
        $from = dirname(__DIR__, 2);
        mkdir($this->root, 0775, true);
        foreach (['app', 'public', 'plugins', 'themes', 'install', 'bin', 'vendor'] as $dir) {
            if (is_dir("$from/$dir")) {
                self::copyTree("$from/$dir", "$this->root/$dir");
            }
        }
        foreach (['INSTALL.md', 'composer.json'] as $file) {
            if (is_file("$from/$file")) {
                copy("$from/$file", "$this->root/$file");
            }
        }
        mkdir($this->root . '/storage/tmp', 0775, true);
        return new App(new Paths($this->root, $this->root . '/storage', $this->root . '/plugins', $this->root . '/themes'));
    }

    private static function copyTree(string $from, string $to): void
    {
        mkdir($to, 0775, true);
        /** @var \SplFileInfo $item */
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        ) as $item) {
            $target = $to . '/' . substr($item->getPathname(), strlen($from) + 1);
            $item->isDir() ? @mkdir($target, 0775, true) : @copy($item->getPathname(), $target);
        }
    }

    public function testAReleaseTheBuilderBuiltIsOneTheUpdaterInstalls(): void
    {
        $this->buildRelease();
        $app = $this->copyOfTheSite();
        $release = new Release($app);

        $key = Release::publicKey($this->pub);
        self::assertNotNull($key, 'the key file the builder’s counterpart reads');

        // A mark in a shipped file, so "this file was replaced" is something
        // the test can see rather than infer from a timestamp.
        $marked = $this->root . '/INSTALL.md';
        file_put_contents($marked, "THE OLD COPY\n");

        // 1. Prepare: the signature, the manifest and the file list.
        $copy = $this->work . '/upload.zip';
        copy($this->zip, $copy);
        $state = $release->prepare($copy, $key);
        self::assertSame('verified', $state['stage']);
        self::assertSame('marine-team/', $state['prefix'], 'the one top folder the builder writes');
        self::assertNotSame([], $state['units']);
        self::assertContains('MANIFEST.json', $state['units'], 'the manifest travels with the release');

        // 2. Extract: every file checked against its checksum on the way out.
        $state = $release->extract($state['id']);
        self::assertSame('extracted', $state['stage'], 'every checksum in the manifest matched');

        // 3. Swap: the new files are in place, the old ones kept aside.
        $state = $release->swap($state['id']);
        self::assertSame('swapped', $state['stage']);
        self::assertStringNotContainsString('THE OLD COPY', (string) file_get_contents($marked), 'a shipped file was replaced');
        self::assertFileExists($this->root . '/app/bootstrap.php', 'and the site is still a site');
        self::assertFileExists($this->root . '/MANIFEST.json', 'which now records what it installed');

        // The one directory that must never be touched by an update.
        self::assertDirectoryExists($this->root . '/storage/tmp', 'storage survives an update');

        // 4. Rollback: back to exactly what was there before.
        $state = $release->rollback($state['id']);
        self::assertSame('rolled_back', $state['stage']);
        self::assertSame("THE OLD COPY\n", (string) file_get_contents($marked), 'the old copy is back, byte for byte');
    }

    public function testAReleaseSignedWithTheWrongKeyIsRefused(): void
    {
        $this->buildRelease();
        $app = $this->copyOfTheSite();

        $other = sodium_crypto_sign_keypair();
        $wrong = $this->work . '/other.pub';
        file_put_contents($wrong, base64_encode(sodium_crypto_sign_publickey($other)) . "\n");

        $copy = $this->work . '/upload.zip';
        copy($this->zip, $copy);
        $this->expectExceptionMessageMatches('/signature does not match/');
        (new Release($app))->prepare($copy, Release::publicKey($wrong));
    }

    public function testAReleaseChangedAfterItWasSignedIsRefused(): void
    {
        $this->buildRelease();
        $app = $this->copyOfTheSite();

        // Somebody puts their own file into a signed release.
        $tampered = $this->work . '/tampered.zip';
        copy($this->zip, $tampered);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($tampered) === true);
        $zip->addFromString('marine-team/app/evil.php', '<?php echo "not from the maintainers";');
        $zip->close();

        $this->expectExceptionMessageMatches('/does not hold exactly the files its manifest lists|signature does not match/');
        (new Release($app))->prepare($tampered, Release::publicKey($this->pub));
    }
}
