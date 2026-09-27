<?php

declare(strict_types=1);

namespace Tests\Unit\Files;

use App\Core\ApiError;
use App\Modules\Files\Images;
use PHPUnit\Framework\TestCase;

/**
 * An image is measured before it is decoded.
 *
 * A decompression bomb is a few kilobytes on disk that asks for gigabytes of
 * memory the moment anything decodes it — on shared hosting that is the
 * whole account's memory limit, and the site is down. getimagesize reads the
 * header only, so the size is known before a decoder is ever handed the
 * file.
 */
final class ImageBombTest extends TestCase
{
    private string $dir = '';
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mt-img-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    /** A real PNG header claiming $w by $h, with no pixel data behind it. */
    private function pngClaiming(int $w, int $h): string
    {
        $ihdr = pack('NN', $w, $h) . "\x08\x06\x00\x00\x00";
        $chunk = pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr));
        $path = $this->dir . '/claim.png';
        file_put_contents($path, "\x89PNG\r\n\x1a\n" . $chunk);
        $this->files[] = $path;
        return $path;
    }

    private function realPng(int $w, int $h): string
    {
        $image = imagecreatetruecolor($w, $h);
        $path = $this->dir . '/real.png';
        imagepng($image, $path);
        imagedestroy($image);
        $this->files[] = $path;
        return $path;
    }

    public function test_1_a_header_claiming_more_than_forty_megapixels_is_refused(): void
    {
        // 40000 x 40000 is 1.6 billion pixels: about 6 GB decoded, from a
        // file of 33 bytes.
        $path = $this->pngClaiming(40000, 40000);
        self::assertLessThan(200, filesize($path), 'the file itself is tiny, which is the point');

        try {
            Images::store($path, 'bomb.png', $this->dir);
            self::fail('a bomb should be refused');
        } catch (ApiError $e) {
            self::assertSame(413, $e->status);
            self::assertStringContainsString('40 megapixels', $e->getMessage());
        }
    }

    public function test_2_the_refusal_is_on_the_area_rather_than_either_side(): void
    {
        // A long thin image is fine; a square one of the same longest side
        // is not. The cost is pixels, not width.
        foreach ([[100000, 100], [100, 100000]] as [$w, $h]) {
            $made = null;
            try {
                $made = Images::store($this->pngClaiming($w, $h), 'wide.png', $this->dir);
            } catch (ApiError $e) {
                // GD may refuse to decode a header with no data behind it;
                // what matters is that it was not the megapixel refusal.
                self::assertNotSame(413, $e->status, "{$w}x{$h} is ten megapixels and should pass the size gate");
            }
            unset($made);
        }
        try {
            Images::store($this->pngClaiming(10000, 10000), 'square.png', $this->dir);
            self::fail('a hundred megapixels should be refused');
        } catch (ApiError $e) {
            self::assertSame(413, $e->status);
        }
    }

    public function test_3_a_file_that_is_not_an_image_never_reaches_a_decoder(): void
    {
        $path = $this->dir . '/notreally.png';
        file_put_contents($path, "<?php echo 'hello'; ?>");
        $this->files[] = $path;

        try {
            Images::store($path, 'notreally.png', $this->dir);
            self::fail('bytes that are not a PNG should be refused');
        } catch (ApiError $e) {
            self::assertSame(415, $e->status, 'refused on its bytes, not its name');
        }
    }

    public function test_4_an_ordinary_image_is_stored_under_a_random_name(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('No GD on this build.');
        }
        $stored = Images::store($this->realPng(64, 48), 'Our Logo (2026).png', $this->dir);

        self::assertMatchesRegularExpression('/^[a-f0-9]{24}\.(png|jpg|webp)$/', $stored, 'nothing of the client’s name is in the path');
        self::assertStringNotContainsString('Our Logo', $stored);
        self::assertFileExists($this->dir . '/' . $stored);
    }
}
