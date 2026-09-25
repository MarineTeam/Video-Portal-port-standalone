<?php

declare(strict_types=1);

namespace Tests\Unit\Site;

use App\Modules\Site\Assets;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MediaPathTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/mt-media-' . bin2hex(random_bytes(4));
        mkdir(self::$root . '/branding', 0775, true);
        file_put_contents(self::$root . '/branding/0123456789abcdef01234567.png', 'x');
        file_put_contents(self::$root . '/branding/0123456789abcdef01234567.php', 'x');
        file_put_contents(self::$root . '/secret.txt', 'x');
    }

    public static function tearDownAfterClass(): void
    {
        foreach (glob(self::$root . '/branding/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir(self::$root . '/branding');
        unlink(self::$root . '/secret.txt');
        rmdir(self::$root);
    }

    public function test_serves_an_uploaded_image_by_its_generated_name(): void
    {
        $this->assertSame(realpath(self::$root . '/branding/0123456789abcdef01234567.png'), Assets::resolveMedia(self::$root, 'branding/0123456789abcdef01234567.png'));
    }

    /** @return iterable<string, array{string}> */
    public static function refused(): iterable
    {
        yield 'php' => ['branding/0123456789abcdef01234567.php'];
        yield 'traversal' => ['branding/../secret.txt'];
        yield 'not generated' => ['branding/logo.png'];
        yield 'top level' => ['secret.txt'];
        yield 'missing' => ['branding/ffffffffffffffffffffffff.png'];
        yield 'nested' => ['a/b/0123456789abcdef01234567.png'];
    }

    #[DataProvider('refused')]
    public function test_refuses_anything_else(string $path): void
    {
        $this->assertNull(Assets::resolveMedia(self::$root, $path));
    }
}
