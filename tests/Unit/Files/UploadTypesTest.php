<?php

declare(strict_types=1);

namespace Tests\Unit\Files;

use App\Modules\Files\UploadTypes;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** lib/upload-types.test.ts */
final class UploadTypesTest extends TestCase
{
    #[TestDox('extensionOf: takes the last extension, lower-cased, without the dot')]
    public function testExt(): void
    {
        self::assertSame('pdf', UploadTypes::extensionOf('Hymnal.Final.PDF'));
    }

    #[TestDox('extensionOf: ignores a query or fragment')]
    public function testExtQuery(): void
    {
        self::assertSame('mp3', UploadTypes::extensionOf('/files/a.mp3?download=1#t'));
    }

    #[TestDox('extensionOf: is empty for no extension or a dotfile')]
    public function testExtEmpty(): void
    {
        self::assertSame('', UploadTypes::extensionOf('README'));
        self::assertSame('', UploadTypes::extensionOf('.htaccess'));
    }

    #[TestDox('uploadType: knows the reader\'s formats and common media')]
    public function testKnows(): void
    {
        foreach (['a.pdf', 'b.epub', 'c.mp3', 'd.m4a', 'e.jpg', 'f.png', 'g.webp'] as $name) {
            self::assertNotNull(UploadTypes::uploadType($name), $name);
        }
    }

    #[TestDox('uploadType: refuses anything that can run as a page')]
    public function testRefusesPages(): void
    {
        foreach (['x.html', 'x.htm', 'x.svg', 'x.xhtml', 'x.php', 'x.phtml', 'x.phar', 'x.js', 'x.xml', 'x.shtml'] as $name) {
            self::assertNull(UploadTypes::uploadType($name), $name);
        }
    }

    #[TestDox('uploadType: refuses an unknown extension, and no extension')]
    public function testRefusesUnknown(): void
    {
        self::assertNull(UploadTypes::uploadType('x.exe'));
        self::assertNull(UploadTypes::uploadType('noext'));
    }

    #[TestDox('uploadType: is decided by the extension, not by a type the browser claims')]
    public function testExtensionDecides(): void
    {
        // There is no parameter for the browser's claim at all.
        self::assertSame('application/pdf', UploadTypes::uploadType('notes.pdf')['type']);
        self::assertNull(UploadTypes::uploadType('notes.html'));
    }

    #[TestDox('bytes that disagree with the extension are refused — a PHP file or an SVG named .jpg')]
    public function testBytes(): void
    {
        $dir = sys_get_temp_dir();
        $php = "$dir/mt-test-" . bin2hex(random_bytes(4)) . '.jpg';
        file_put_contents($php, "<?php echo 'hi';");
        self::assertFalse(UploadTypes::bytesMatch($php, 'jpg'));
        file_put_contents($php, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        self::assertFalse(UploadTypes::bytesMatch($php, 'jpg'));
        $img = imagecreatetruecolor(2, 2);
        imagejpeg($img, $php);
        self::assertTrue(UploadTypes::bytesMatch($php, 'jpg'));
        self::assertFalse(UploadTypes::bytesMatch($php, 'png'));
        unlink($php);
    }

    #[TestDox('objectName: keeps the id and the extension, and nothing of the client\'s name')]
    public function testObjectName(): void
    {
        self::assertSame('files/cabc12345def.pdf', UploadTypes::objectName('cabc12345def', 'My "secret" plan.PDF'));
    }

    #[TestDox('objectName: cannot be steered out of the files/ prefix')]
    public function testObjectNameTraversal(): void
    {
        self::assertNull(UploadTypes::objectName('../etc', 'x.pdf'));
        self::assertNull(UploadTypes::objectName('a/b', 'x.pdf'));
        self::assertSame('files/cabc12345def.pdf', UploadTypes::objectName('cabc12345def', '../../x.pdf'));
    }

    #[TestDox('objectName: is null for a refused type')]
    public function testObjectNameRefused(): void
    {
        self::assertNull(UploadTypes::objectName('cabc12345def', 'x.html'));
    }

    #[TestDox('servePolicy: shows a reader format or media inline, with nosniff')]
    public function testInline(): void
    {
        $p = UploadTypes::servePolicy('files/x.pdf');
        self::assertSame(['application/pdf', 'inline', 'nosniff'], [$p['type'], $p['disposition'], $p['headers']['X-Content-Type-Options']]);
        self::assertSame('inline', UploadTypes::servePolicy('files/x.mp3')['disposition']);
        self::assertSame('attachment', UploadTypes::servePolicy('files/x.pdf', download: true)['disposition']);
    }

    #[TestDox('servePolicy: forces a download for a document')]
    public function testDocument(): void
    {
        self::assertSame('attachment', UploadTypes::servePolicy('files/x.vtt')['disposition']);
    }

    #[TestDox('servePolicy: serves anything off the list as an opaque download that can\'t render')]
    public function testOpaque(): void
    {
        $p = UploadTypes::servePolicy('files/x.html');
        self::assertSame('application/octet-stream', $p['type']);
        self::assertSame('attachment', $p['disposition']);
        self::assertSame('sandbox', $p['headers']['Content-Security-Policy']);
        self::assertSame('nosniff', $p['headers']['X-Content-Type-Options']);
    }

    #[TestDox('servePolicy: never consults a stored MIME type')]
    public function testNoStoredMime(): void
    {
        $method = new \ReflectionMethod(UploadTypes::class, 'servePolicy');
        self::assertSame(['storedPath', 'download'], array_map(fn ($p) => $p->getName(), $method->getParameters()));
    }

    #[TestDox('the list: has no type that a browser would render as a document with script')]
    public function testNoScriptTypes(): void
    {
        foreach (UploadTypes::TYPES as $ext => $t) {
            self::assertDoesNotMatchRegularExpression('#html|xml|svg|javascript|ecmascript#', $t['type'], $ext);
        }
    }

    #[TestDox('the list: only shows inline what cannot carry a script')]
    public function testInlineSafe(): void
    {
        foreach (UploadTypes::TYPES as $ext => $t) {
            if ($t['inline']) {
                self::assertContains($t['kind'], ['document', 'audio', 'image'], $ext);
            }
        }
    }

    #[TestDox('the list: names the allowed extensions in the refusal')]
    public function testRefusal(): void
    {
        self::assertStringContainsString('PDF', UploadTypes::refusal());
        self::assertStringContainsString('PNG', UploadTypes::refusal('image'));
        self::assertStringNotContainsString('PDF', UploadTypes::refusal('image'));
    }
}
