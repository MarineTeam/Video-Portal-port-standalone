<?php

declare(strict_types=1);

namespace App\Modules\I18n;

/**
 * Translation: one PHP file per language returning key => string, English
 * being the reference. A test asserts every catalogue has exactly the English
 * keys and keeps every {placeholder} — what the original's type system did
 * for free.
 *
 * The chosen locale is the marine-locale cookie, because pages are rendered
 * on the server and the server cannot read localStorage; failing that, the
 * browser's Accept-Language, honoured with its quality weights.
 */
final class I18n
{
    public const LOCALES = ['en' => 'English', 'es' => 'Español'];
    public const COOKIE = 'marine-locale';
    public const DEFAULT = 'en';

    private static string $locale = self::DEFAULT;

    /** @var array<string, array<string, string>> */
    private static array $catalogues = [];

    /** @var array<string, array<string, string>> added by plugins through lang.catalogue */
    private static array $extra = [];

    private static ?string $dir = null;

    public static function configure(string $dir): void
    {
        self::$dir = rtrim($dir, '/');
    }

    public static function setLocale(string $locale): void
    {
        self::$locale = isset(self::LOCALES[$locale]) ? $locale : self::DEFAULT;
    }

    public static function locale(): string
    {
        return self::$locale;
    }

    /** @param array<string, string> $strings */
    public static function extend(string $locale, array $strings): void
    {
        self::$extra[$locale] = array_merge(self::$extra[$locale] ?? [], $strings);
    }

    /** @return array<string, string> */
    public static function catalogue(string $locale): array
    {
        if (!isset(self::$catalogues[$locale])) {
            $file = (self::$dir ?? dirname(__DIR__, 2) . '/Lang') . "/$locale.php";
            $strings = is_file($file) ? require $file : [];
            self::$catalogues[$locale] = is_array($strings) ? $strings : [];
        }
        return array_merge(self::$catalogues[$locale], self::$extra[$locale] ?? []);
    }

    /** @param array<string, string|int|float> $vars */
    public static function t(string $key, array $vars = []): string
    {
        $text = self::catalogue(self::$locale)[$key] ?? self::catalogue(self::DEFAULT)[$key] ?? $key;
        return self::format($text, $vars);
    }

    /**
     * Fills {name} placeholders; one it wasn't given is left standing rather
     * than becoming an empty string.
     *
     * @param array<string, string|int|float> $vars
     */
    public static function format(string $template, array $vars): string
    {
        return (string) preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', fn ($m) => array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : $m[0], $template);
    }

    /**
     * The best language this app speaks for an Accept-Language header:
     * quality weights over order, a regional tag matching its base language,
     * a refused (q=0) language ignored, English when nothing matches.
     */
    public static function pickLocale(?string $header): string
    {
        if ($header === null || trim($header) === '') {
            return self::DEFAULT;
        }
        $candidates = [];
        foreach (explode(',', $header) as $index => $part) {
            $pieces = array_map('trim', explode(';', $part));
            $tag = strtolower($pieces[0]);
            if ($tag === '' || !preg_match('/^[a-z]{1,8}(-[a-z0-9]{1,8})*$|^\*$/', $tag)) {
                continue;
            }
            $q = 1.0;
            foreach (array_slice($pieces, 1) as $param) {
                if (preg_match('/^q\s*=\s*([0-9.]+)$/i', $param, $m) && is_numeric($m[1])) {
                    $q = max(0.0, min(1.0, (float) $m[1]));
                }
            }
            if ($q <= 0.0) {
                continue;
            }
            $candidates[] = ['tag' => $tag, 'q' => $q, 'i' => $index];
        }
        usort($candidates, fn ($a, $b) => [$b['q'], $a['i']] <=> [$a['q'], $b['i']]);
        foreach ($candidates as $candidate) {
            $base = explode('-', $candidate['tag'])[0];
            if (isset(self::LOCALES[$base])) {
                return $base;
            }
        }
        return self::DEFAULT;
    }

    /** The locale for a request: the cookie, then the header. */
    public static function fromRequest(?string $cookie, ?string $acceptLanguage): string
    {
        if ($cookie !== null && isset(self::LOCALES[$cookie])) {
            return $cookie;
        }
        return self::pickLocale($acceptLanguage);
    }

    /** @return list<string> the {placeholders} in a string */
    public static function placeholders(string $text): array
    {
        preg_match_all('/\{([a-zA-Z0-9_]+)\}/', $text, $m);
        $names = array_values(array_unique($m[1]));
        sort($names);
        return $names;
    }
}
