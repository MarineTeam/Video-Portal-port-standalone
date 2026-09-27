<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * A theme installed from a zip, overriding a template, and a broken one.
 *
 * A theme is somebody else's code and templates running as the site, which
 * is why the last case is the one that matters: a theme that throws on load
 * must leave the site standing, on the default, saying what happened —
 * rather than taking the church's site down until somebody with FTP can be
 * found on a Sunday.
 */
final class ThemesTest extends ServerTestCase
{
    private const SLUG = 'seaside';

    protected static function prefix(): string
    {
        return 'thm_';
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::install();
    }

    public static function tearDownAfterClass(): void
    {
        \App\Modules\Plugins\PackageInstaller::removeTree(dirname(__DIR__, 2) . '/themes/' . self::SLUG);
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        \App\Modules\Plugins\PackageInstaller::removeTree(dirname(__DIR__, 2) . '/themes/' . self::SLUG);
        $db = self::connect(self::prefix());
        $db->run('DELETE FROM {{settings}} WHERE name IN (?, ?)', ['theme.active', 'theme.notice']);
        self::flushCache();
    }

    /**
     * A theme zip: theme.json, and whatever else is asked for.
     *
     * @param array<string, string> $files path inside the theme => contents
     */
    private static function zip(array $files): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'theme') . '.zip';
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true);
        $zip->addFromString(self::SLUG . '/theme.json', (string) json_encode([
            'name' => 'Seaside', 'slug' => self::SLUG, 'version' => '1.0.0', 'author' => 'A volunteer',
        ]));
        foreach ($files as $name => $body) {
            $zip->addFromString(self::SLUG . '/' . $name, $body);
        }
        $zip->close();
        return $path;
    }

    /** Uploads a theme zip through the chunked uploader, as the page does. */
    private static function install_(string $zipPath): array
    {
        $bytes = (string) file_get_contents($zipPath);
        $created = self::api('POST', '/api/uploads', ['purpose' => 'theme', 'fileName' => 'theme.zip', 'size' => strlen($bytes)]);
        self::assertSame(201, $created['status'], (string) $created['body']);
        $id = (string) $created['json']['id'];
        $put = self::http('PUT', "/api/uploads/$id/chunk?offset=0", $bytes, 'admin', [
            'Content-Type' => 'application/octet-stream',
            'X-CSRF-Token' => self::token(),
        ]);
        self::assertSame(200, $put['status'], (string) $put['body']);
        return self::api('POST', '/api/admin/appearance/install', ['upload' => $id]);
    }

    private static function token(): string
    {
        preg_match('/name="csrf-token" content="([^"]+)"/', self::http('GET', '/')['body'], $m);
        return $m[1] ?? '';
    }

    private static function activate(string $slug): array
    {
        return self::api('POST', '/api/admin/appearance/theme', ['slug' => $slug]);
    }

    public function test_1_a_theme_installs_from_a_zip(): void
    {
        $answer = self::install_(self::zip([]));
        self::assertSame(201, $answer['status'], (string) $answer['body']);
        self::assertSame(self::SLUG, $answer['json']['slug']);
        self::assertFileExists(dirname(__DIR__, 2) . '/themes/' . self::SLUG . '/theme.json');

        // And it is offered on the page that lists them.
        self::assertStringContainsString('Seaside', self::http('GET', '/admin/appearance')['body']);
    }

    public function test_2_a_theme_overrides_one_template_and_leaves_the_rest(): void
    {
        self::install_(self::zip([
            'templates/partials/notices.php' => '<?php /** @var \App\Core\View $v */ ?><p>A notice from the seaside</p>',
        ]));
        self::assertSame(200, self::activate(self::SLUG)['status']);
        self::flushCache();

        $page = self::http('GET', '/');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('A notice from the seaside', $page['body'], 'the theme’s template won');
        // The rest of the site is still the core's: the theme replaced one
        // file, not the layout around it.
        self::assertStringContainsString('<html', $page['body']);
        self::assertStringContainsString('</body>', $page['body']);
    }

    public function test_3_a_theme_that_throws_on_load_leaves_the_site_standing(): void
    {
        self::install_(self::zip([
            'functions.php' => '<?php throw new \RuntimeException("boom from functions.php");',
        ]));
        self::assertSame(200, self::activate(self::SLUG)['status']);
        self::flushCache();

        // The whole point: the site answers.
        $page = self::http('GET', '/');
        self::assertSame(200, $page['status'], 'a broken theme does not take the site down');

        $db = self::connect(self::prefix());
        self::assertSame('"default"', (string) $db->value('SELECT value FROM {{settings}} WHERE name = ?', ['theme.active']), 'and it is back on the default');
        $notice = (string) $db->value('SELECT value FROM {{settings}} WHERE name = ?', ['theme.notice']);
        self::assertStringContainsString('boom from functions.php', $notice, 'with what went wrong recorded');
        self::assertStringContainsString(self::SLUG, $notice);
    }

    public function test_4_an_administrator_is_told_rather_than_left_wondering(): void
    {
        self::install_(self::zip(['functions.php' => '<?php throw new \RuntimeException("boom from functions.php");']));
        self::activate(self::SLUG);
        self::flushCache();
        self::http('GET', '/');

        $page = self::http('GET', '/admin');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString(self::SLUG, $page['body'], 'the notice names the theme');
    }

    public function test_5_a_zip_that_is_not_a_theme_is_refused(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'theme') . '.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('seaside/readme.txt', 'no theme.json here');
        $zip->close();

        $answer = self::install_($path);
        self::assertSame(400, $answer['status']);
        self::assertFileDoesNotExist(dirname(__DIR__, 2) . '/themes/' . self::SLUG . '/theme.json');
    }

    public function test_6_only_an_administrator_installs_one(): void
    {
        self::member('ruth@test.example', 'ruth');
        self::assertSame(403, self::api('POST', '/api/uploads', ['purpose' => 'theme', 'fileName' => 't.zip', 'size' => 10], 'ruth')['status']);
        self::assertContains(self::api('POST', '/api/admin/appearance/install', ['upload' => 'x'], 'ruth')['status'], [401, 403]);
    }
}
