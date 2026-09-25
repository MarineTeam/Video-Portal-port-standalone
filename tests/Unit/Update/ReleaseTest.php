<?php

declare(strict_types=1);

namespace Tests\Unit\Update;

use App\Modules\Update\Release;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReleaseTest extends TestCase
{
    private static string $tmp;

    public static function setUpBeforeClass(): void
    {
        self::$tmp = sys_get_temp_dir() . '/mt-release-' . bin2hex(random_bytes(4));
        mkdir(self::$tmp);
    }

    public static function tearDownAfterClass(): void
    {
        foreach (glob(self::$tmp . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir(self::$tmp);
    }

    /** @param array<string, string> $entries */
    private function zip(array $entries, ?callable $tweak = null): \ZipArchive
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('zip extension missing');
        }
        $path = self::$tmp . '/' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        foreach ($entries as $name => $body) {
            $zip->addFromString($name, $body);
        }
        if ($tweak !== null) {
            $tweak($zip);
        }
        $zip->close();
        $zip = new \ZipArchive();
        $zip->open($path);
        return $zip;
    }

    public function test_a_signature_made_with_the_secret_key_verifies_and_any_change_does_not(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $public = sodium_crypto_sign_publickey($pair);
        $manifest = '{"version":"3.1.0","files":{"app/x.php":"' . str_repeat('a', 64) . '"}}';
        $sig = base64_encode(sodium_crypto_sign_detached($manifest, sodium_crypto_sign_secretkey($pair)));
        $this->assertTrue(Release::verifySignature($manifest, $sig, $public));
        $this->assertFalse(Release::verifySignature($manifest . ' ', $sig, $public), 'a changed manifest');
        $this->assertFalse(Release::verifySignature($manifest, $sig, sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())), 'another key');
        $this->assertFalse(Release::verifySignature($manifest, 'not base64!', $public));
        $this->assertFalse(Release::verifySignature($manifest, base64_encode('short'), $public));
    }

    public function test_the_public_key_file_is_read_past_its_comments_and_absent_when_empty(): void
    {
        $key = random_bytes(32);
        file_put_contents(self::$tmp . '/k1', "# comment\n\n" . base64_encode($key) . "\n");
        file_put_contents(self::$tmp . '/k2', "# only comments\n");
        file_put_contents(self::$tmp . '/k3', "bm90IDMyIGJ5dGVz\n");
        $this->assertSame($key, Release::publicKey(self::$tmp . '/k1'));
        $this->assertNull(Release::publicKey(self::$tmp . '/k2'));
        $this->assertNull(Release::publicKey(self::$tmp . '/k3'));
        $this->assertNull(Release::publicKey(self::$tmp . '/missing'));
    }

    public function test_the_shipped_key_file_parses(): void
    {
        $file = dirname(__DIR__, 3) . '/app/release-key.pub';
        $this->assertFileExists($file);
        $key = Release::publicKey($file);
        $this->assertTrue($key === null || strlen($key) === 32);
    }

    /** @return iterable<string, array{string}> */
    public static function unsafePaths(): iterable
    {
        yield 'parent' => ['app/../../etc/passwd'];
        yield 'absolute' => ['/etc/passwd'];
        yield 'drive' => ['C:/x'];
        yield 'backslash' => ['app\\x.php'];
        yield 'empty segment' => ['app//x.php'];
        yield 'dot' => ['./x'];
    }

    #[DataProvider('unsafePaths')]
    public function test_unsafe_paths_are_refused(string $path): void
    {
        $this->assertFalse(Release::isSafePath($path));
    }

    public function test_the_manifest_is_refused_when_it_lists_an_unsafe_path_or_bad_checksum(): void
    {
        $ok = ['version' => '3.1.0', 'files' => ['app/x.php' => str_repeat('b', 64)]];
        $this->assertSame('3.1.0', Release::parseManifest((string) json_encode($ok))['version']);
        foreach ([
            ['version' => '3.1.0', 'files' => ['../x' => str_repeat('b', 64)]],
            ['version' => '3.1.0', 'files' => ['app/x.php' => 'nope']],
            ['version' => 'latest', 'files' => ['app/x.php' => str_repeat('b', 64)]],
            ['version' => '3.1.0', 'files' => []],
        ] as $bad) {
            try {
                Release::parseManifest((string) json_encode($bad));
                $this->fail('accepted ' . json_encode($bad));
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_units_are_whole_folders_but_never_storage_and_one_per_plugin_theme_and_public_entry(): void
    {
        $this->assertSame(
            ['.htaccess', 'app', 'plugins/events', 'public/assets', 'public/index.php', 'themes/default', 'vendor'],
            Release::units(['app/Core/App.php', 'app/Lang/en.php', 'vendor/autoload.php', 'plugins/events/plugin.php', 'themes/default/theme.json', 'public/index.php', 'public/assets/css/app.css', 'storage/.htaccess', '.htaccess']),
        );
    }

    public function test_inspect_strips_the_one_top_folder_and_lists_files(): void
    {
        $zip = $this->zip(['marine-team/MANIFEST.json' => '{}', 'marine-team/app/x.php' => '<?php']);
        $this->assertSame(['prefix' => 'marine-team/', 'files' => ['MANIFEST.json', 'app/x.php']], Release::inspect($zip));
        $flat = $this->zip(['MANIFEST.json' => '{}', 'app/x.php' => '<?php']);
        $this->assertSame('', Release::inspect($flat)['prefix']);
    }

    public function test_inspect_refuses_traversal_and_symlinks(): void
    {
        $zip = $this->zip(['MANIFEST.json' => '{}', '../evil.php' => '<?php']);
        try {
            Release::inspect($zip);
            $this->fail('accepted ..');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('path', $e->getMessage());
        }
        $zip = $this->zip(['MANIFEST.json' => '{}', 'link' => '/etc/passwd'], function (\ZipArchive $z): void {
            $z->setExternalAttributesName('link', \ZipArchive::OPSYS_UNIX, (0120777 << 16));
        });
        $this->expectExceptionMessage('symbolic link');
        Release::inspect($zip);
    }
}
