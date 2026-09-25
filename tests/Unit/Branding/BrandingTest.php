<?php

declare(strict_types=1);

namespace Tests\Unit\Branding;

use App\Modules\Branding\Branding;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** lib/branding.test.ts */
final class BrandingTest extends TestCase
{
    #[TestDox('isHexColor: accepts three- and six-digit hex, in either case')]
    public function testHex(): void
    {
        foreach (['#fff', '#FFF', '#1a8fd1', '#1A8FD1'] as $c) {
            self::assertTrue(Branding::isHexColor($c), $c);
        }
    }

    #[TestDox('isHexColor: rejects anything that isn\'t one')]
    public function testNotHex(): void
    {
        foreach (['fff', '#ffff', '#gggggg', 'red', '#1a8fd1;}body{', null, 12] as $c) {
            self::assertFalse(Branding::isHexColor($c));
        }
    }

    #[TestDox('normalizeHex: expands the short form and lowercases')]
    public function testNormalize(): void
    {
        self::assertSame('#aabbcc', Branding::normalizeHex('#ABC'));
    }

    #[TestDox('hexToRgbChannels: splits a colour into channels for an rgba() literal')]
    public function testChannels(): void
    {
        self::assertSame('26, 143, 209', Branding::hexToRgbChannels('#1a8fd1'));
    }

    #[TestDox('normalizeBranding: falls back to the defaults for junk')]
    public function testDefaults(): void
    {
        self::assertSame(Branding::DEFAULTS, Branding::normalizeBranding(null));
        self::assertSame(Branding::DEFAULTS, Branding::normalizeBranding(['name' => 7, 'brand' => 'nope']));
    }

    #[TestDox('normalizeBranding: keeps the fields it recognizes when others are bad')]
    public function testKeeps(): void
    {
        $b = Branding::normalizeBranding(['name' => 'Grace Church', 'brand' => 'bad']);
        self::assertSame('Grace Church', $b['name']);
        self::assertSame('#1a8fd1', $b['brand']);
    }

    #[TestDox('normalizeBranding: drops a colour that isn\'t hex rather than letting it reach the stylesheet')]
    public function testDropsColour(): void
    {
        self::assertSame('#0288d1', Branding::normalizeBranding(['brandDeep' => 'red;}</style><script>'])['brandDeep']);
    }

    #[TestDox('normalizeBranding: treats a blank or whitespace-only name as absent')]
    public function testBlankName(): void
    {
        self::assertSame('Marine Team', Branding::normalizeBranding(['name' => '   '])['name']);
    }

    #[TestDox('normalizeBranding: caps a name rather than letting it break the header')]
    public function testCap(): void
    {
        self::assertSame(Branding::NAME_MAX, mb_strlen(Branding::normalizeBranding(['name' => str_repeat('x', 500)])['name']));
    }

    #[TestDox('normalizeBranding: accepts a same-origin path or an https logo, and nothing else')]
    public function testLogo(): void
    {
        self::assertSame('/media/logo.png', Branding::normalizeBranding(['logoUrl' => '/media/logo.png'])['logoUrl']);
        self::assertSame('https://cdn.example.org/l.png', Branding::normalizeBranding(['logoUrl' => 'https://cdn.example.org/l.png'])['logoUrl']);
        foreach (['http://x.org/l.png', '//evil.org/l.png', 'javascript:alert(1)', 'data:image/png;base64,AAA', '/x" onerror="y'] as $bad) {
            self::assertNull(Branding::normalizeBranding(['logoUrl' => $bad])['logoUrl'], $bad);
        }
    }

    #[TestDox('brandingCss: writes the chosen colours into both themes')]
    public function testCss(): void
    {
        $css = Branding::brandingCss(['brand' => '#112233', 'brandDeep' => '#445566', 'brandLight' => '#778899']);
        self::assertStringContainsString(':root{', $css);
        self::assertStringContainsString('html.dark{', $css);
        self::assertStringContainsString('--accent:#445566', $css);
        self::assertStringContainsString('--accent:#778899', $css);
    }

    #[TestDox('brandingCss: normalizes before interpolating, so a bad value can\'t reach the stylesheet')]
    public function testCssSafe(): void
    {
        $css = Branding::brandingCss(['brand' => '#fff}</style><script>alert(1)</script>']);
        self::assertStringNotContainsString('<', $css);
        self::assertStringContainsString('--brand:#1a8fd1', $css);
    }
}
