<?php

declare(strict_types=1);

namespace Tests\Unit\I18n;

use App\Modules\I18n\I18n;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** lib/i18n/i18n.test.ts */
final class I18nTest extends TestCase
{
    #[TestDox('the catalogues: has more than one language to check')]
    public function testMoreThanOne(): void
    {
        self::assertGreaterThan(1, count(I18n::LOCALES));
    }

    #[TestDox('the catalogues: every language has exactly the English keys, and keeps every {placeholder}')]
    public function testKeysAndPlaceholders(): void
    {
        $en = I18n::catalogue('en');
        foreach (array_keys(I18n::LOCALES) as $locale) {
            $cat = I18n::catalogue($locale);
            self::assertSame([], array_values(array_diff(array_keys($en), array_keys($cat))), "$locale is missing keys");
            self::assertSame([], array_values(array_diff(array_keys($cat), array_keys($en))), "$locale has extra keys");
            foreach ($en as $key => $text) {
                self::assertSame(I18n::placeholders($text), I18n::placeholders($cat[$key]), "$locale:$key drops or adds a placeholder");
            }
        }
    }

    #[TestDox('the catalogues: names every language in its own language')]
    public function testOwnName(): void
    {
        foreach (I18n::LOCALES as $locale => $name) {
            self::assertSame($name, I18n::catalogue($locale)['language.name']);
        }
    }

    #[TestDox('format: fills a placeholder in')]
    public function testFill(): void
    {
        self::assertSame('3 places', I18n::format('{n} places', ['n' => 3]));
    }

    #[TestDox('format: leaves an unknown one standing rather than writing \'undefined\'')]
    public function testUnknown(): void
    {
        self::assertSame('{n} places', I18n::format('{n} places', []));
    }

    #[TestDox('format: fills every occurrence')]
    public function testEvery(): void
    {
        self::assertSame('a and a', I18n::format('{x} and {x}', ['x' => 'a']));
    }

    #[TestDox('pickLocale: takes a language this app speaks')]
    public function testSpeaks(): void
    {
        self::assertSame('es', I18n::pickLocale('es'));
    }

    #[TestDox('pickLocale: matches a regional variant to its base language')]
    public function testRegional(): void
    {
        self::assertSame('es', I18n::pickLocale('es-ES,en;q=0.5'));
        self::assertSame('es', I18n::pickLocale('es-419'));
    }

    #[TestDox('pickLocale: honours the quality weights rather than the order')]
    public function testWeights(): void
    {
        self::assertSame('es', I18n::pickLocale('en;q=0.4, es;q=0.9'));
    }

    #[TestDox('pickLocale: falls back to English for a language it doesn\'t speak')]
    public function testFallback(): void
    {
        self::assertSame('en', I18n::pickLocale('fr-FR, de;q=0.8'));
    }

    #[TestDox('pickLocale: falls back for a missing or unreadable header')]
    public function testMissing(): void
    {
        self::assertSame('en', I18n::pickLocale(null));
        self::assertSame('en', I18n::pickLocale(';;;,,,'));
    }

    #[TestDox('pickLocale: still honours a language whose weight is malformed')]
    public function testMalformedWeight(): void
    {
        self::assertSame('es', I18n::pickLocale('es;q=abc'));
    }

    #[TestDox('pickLocale: ignores a language the browser explicitly refused')]
    public function testRefused(): void
    {
        self::assertSame('en', I18n::pickLocale('es;q=0, en;q=0.1'));
    }

    #[TestDox('the language list in device settings: offers exactly the languages there are catalogues for')]
    public function testDeviceList(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 3) . '/public/assets/js/device-settings.js');
        preg_match('/LANGUAGES\s*=\s*(\[[^\]]*\])/', $js, $m);
        $list = json_decode(str_replace("'", '"', $m[1] ?? '[]'), true);
        self::assertSame(array_keys(I18n::LOCALES), array_column((array) $list, 'value'));
        self::assertSame(array_values(I18n::LOCALES), array_column((array) $list, 'label'));
    }
}
