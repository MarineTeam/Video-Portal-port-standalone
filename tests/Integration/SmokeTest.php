<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\TestDox;

/**
 * The shared-hosting smoke test, in miniature: a real server, the installer
 * driven by HTTP alone, then plugins that throw, parse-fail and exhaust
 * memory on load — and the site staying up through all of it.
 *
 * CI runs the full version against Apache with the banned functions disabled;
 * this one runs anywhere PHP's built-in server does.
 */
final class SmokeTest extends ServerTestCase
{
    private const PREFIX = 'smk_';

    protected static function prefix(): string
    {
        return self::PREFIX;
    }

    public static function tearDownAfterClass(): void
    {
        foreach (['throws-on-load', 'parse-error', 'exhausts-memory', 'needs-the-future', 'good-plugin', 'throws-in-hook'] as $slug) {
            \App\Modules\Plugins\PackageInstaller::removeTree(dirname(__DIR__, 2) . "/plugins/$slug");
        }
        parent::tearDownAfterClass();
    }

    #[TestDox('installs by HTTP alone, refusing a wrong install key, and closes /install afterwards')]
    public function testInstall(): void
    {
        self::assertSame(303, self::http('GET', '/')['status']);
        self::assertSame(200, self::http('GET', '/install')['status']);
        $key = trim((string) file_get_contents(self::$storage . '/install.key'));
        self::assertStringNotContainsString('Check this host', self::http('POST', '/install', ['key' => 'wrong'])['body']);
        self::assertSame(303, self::http('POST', '/install', ['key' => $key])['status']);
        self::assertSame(303, self::http('POST', '/install/requirements', ['_csrf' => self::csrf('/install/requirements')])['status']);
        $db = self::dbConfig(self::PREFIX);
        $r = self::http('POST', '/install/database', ['_csrf' => self::csrf('/install/database'), 'host' => $db['host'], 'port' => (string) $db['port'], 'name' => $db['name'], 'user' => $db['user'], 'password' => $db['password'], 'prefix' => self::PREFIX]);
        self::assertSame(303, $r['status'], $r['body']);
        $c = self::csrf('/install/migrate');
        for ($i = 0; $i < 20; $i++) {
            $step = json_decode(self::http('POST', '/install/migrate', ['_csrf' => $c])['body'], true);
            if (($step['done'] ?? false) === true) {
                break;
            }
        }
        $r = self::http('POST', '/install/site', ['_csrf' => self::csrf('/install/site'), 'name' => 'Smoke Church', 'shortName' => 'Smoke', 'brand' => '#123456', 'brandDeep' => '#123456', 'brandLight' => '#abcdef', 'locale' => 'en', 'timezone' => 'UTC', 'email' => 'admin@smoke.test', 'password' => 'correct horse battery']);
        self::assertSame(303, $r['status'], $r['body']);
        self::assertSame(303, self::http('POST', '/install/services', ['_csrf' => self::csrf('/install/services')])['status']);
        self::assertSame(303, self::http('POST', '/install/finish', ['_csrf' => self::csrf('/install/finish')])['status']);
        self::assertFileExists(self::$storage . '/installed.lock');
        self::assertFileDoesNotExist(self::$storage . '/install.key');
        self::assertSame(404, self::http('GET', '/install')['status']);
        self::assertSame(200, self::http('GET', '/')['status']);
    }

    #[TestDox('every bundled plugin comes up on a fresh install, with nothing switched off')]
    public function testBundledPluginsActivate(): void
    {
        $root = dirname(__DIR__, 2);
        $bundled = [];
        foreach (glob("$root/plugins/*/.bundled") ?: [] as $marker) {
            $bundled[] = basename(dirname($marker));
        }
        // The fixtures the broken-plugin test plants are not bundled, so a
        // run in either order sees the same list.
        sort($bundled);
        self::assertGreaterThan(25, count($bundled), 'the bundled plugins are on disk');

        $db = self::connect(self::PREFIX);
        $rows = [];
        foreach ($db->all('SELECT slug, enabled, deactivated_reason FROM {{plugins}}') as $row) {
            $rows[(string) $row['slug']] = $row;
        }

        $missing = [];
        $off = [];
        foreach ($bundled as $slug) {
            if (!isset($rows[$slug])) {
                $missing[] = $slug;
            } elseif ((int) $rows[$slug]['enabled'] !== 1) {
                $off[] = $slug . ' (' . (string) ($rows[$slug]['deactivated_reason'] ?? 'no reason given') . ')';
            }
        }
        self::assertSame([], $missing, 'every bundled plugin was seeded by the install');
        self::assertSame([], $off, 'and every one of them is on');

        // Seeded is not the same as loaded: a plugin that throws on boot is
        // switched off by the loader on the first request, so ask for a page
        // and look again.
        self::assertSame(200, self::http('GET', '/')['status']);
        foreach ($db->all('SELECT slug, deactivated_reason FROM {{plugins}} WHERE enabled = 0') as $row) {
            self::assertNotContains((string) $row['slug'], $bundled, (string) $row['slug'] . ' was switched off: ' . (string) $row['deactivated_reason']);
        }
    }

    #[TestDox('signs the first administrator in and serves the admin area, refusing a login without its CSRF token')]
    public function testSignIn(): void
    {
        self::assertSame(403, self::http('POST', '/auth/login', ['email' => 'admin@smoke.test', 'password' => 'correct horse battery'])['status']);
        $r = self::http('POST', '/auth/login', ['_csrf' => self::csrf('/auth/login'), 'email' => 'admin@smoke.test', 'password' => 'correct horse battery']);
        self::assertSame(303, $r['status']);
        foreach (['/admin', '/admin/plugins', '/admin/providers', '/admin/jobs', '/admin/system', '/admin/logs', '/admin/users', '/admin/authorized-emails', '/admin/access-attempts', '/admin/permissions', '/admin/audit', '/admin/branding', '/admin/appearance', '/admin/update', '/admin/tools', '/admin/email', '/profile', '/profile/inbox', '/profile/settings'] as $path) {
            self::assertSame(200, self::http('GET', $path)['status'], $path);
        }
    }

    #[TestDox('a plugin that throws, parse-fails or exhausts memory on load is switched off with the reason, and the site stays up')]
    public function testBrokenPlugins(): void
    {
        $root = dirname(__DIR__, 2);
        $slugs = ['throws-on-load', 'parse-error', 'exhausts-memory', 'needs-the-future', 'good-plugin'];
        foreach ($slugs as $slug) {
            \App\Modules\Plugins\PackageInstaller::removeTree("$root/plugins/$slug");
            mkdir("$root/plugins/$slug", 0775, true);
            copy("$root/tests/fixtures/plugins/$slug/plugin.php", "$root/plugins/$slug/plugin.php");
        }
        $db = self::connect(self::PREFIX);
        foreach ($slugs as $slug) {
            // As if a newer version arrived over FTP while the plugin was active.
            $db->run('INSERT INTO {{plugins}} (id, slug, name, enabled) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE enabled = 1', [\App\Core\Id::new(), $slug, $slug]);
        }
        array_map('unlink', glob(self::$storage . '/cache/*.cache') ?: []);

        for ($i = 0; $i < 4; $i++) {
            self::http('GET', '/');
        }
        self::assertSame(200, self::http('GET', '/')['status']);
        $rows = [];
        foreach ($db->all('SELECT slug, enabled, deactivated_reason FROM {{plugins}} WHERE slug IN (?, ?, ?, ?, ?)', $slugs) as $row) {
            $rows[$row['slug']] = $row;
        }
        self::assertSame('load_error', $rows['throws-on-load']['deactivated_reason']);
        self::assertSame('load_error', $rows['parse-error']['deactivated_reason']);
        self::assertSame('fatal_error', $rows['exhausts-memory']['deactivated_reason']);
        // Its boot() throws, so reaching it at all would be the failure.
        self::assertSame('requirements', $rows['needs-the-future']['deactivated_reason']);
        self::assertSame(1, (int) $rows['good-plugin']['enabled']);
        self::assertSame('hello from a plugin', self::http('GET', '/hello-plugin')['body']);
        // The plugin list is reachable, and says what happened.
        $list = self::http('GET', '/admin/plugins');
        self::assertSame(200, $list['status']);
        self::assertStringContainsString('switched off automatically', $list['body']);
    }

    #[TestDox('a plugin that throws in a hook is contained: the page still renders, and it is switched off once it keeps failing')]
    public function testPluginThrowingInAHook(): void
    {
        $root = dirname(__DIR__, 2);
        \App\Modules\Plugins\PackageInstaller::removeTree("$root/plugins/throws-in-hook");
        mkdir("$root/plugins/throws-in-hook", 0775, true);
        copy("$root/tests/fixtures/plugins/throws-in-hook/plugin.php", "$root/plugins/throws-in-hook/plugin.php");
        $db = self::connect(self::PREFIX);
        $db->run('INSERT INTO {{plugins}} (id, slug, name, enabled) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE enabled = 1, deactivated_reason = NULL', [\App\Core\Id::new(), 'throws-in-hook', 'throws-in-hook']);
        self::flushCache();

        // Its nav.sections filter throws on every page. The first few requests
        // are served anyway: one plugin's bad hook is not the site's problem.
        for ($i = 0; $i < \App\Modules\Plugins\PluginLoader::HOOK_FAILURE_LIMIT + 2; $i++) {
            $page = self::http('GET', '/');
            self::assertSame(200, $page['status'], "request $i was not served");
            self::assertStringContainsString('<nav', $page['body'], "the nav went missing on request $i");
        }

        // And it does not throw forever: ten failures in ten minutes and it is off.
        self::assertSame('hook_failures', (string) $db->value('SELECT deactivated_reason FROM {{plugins}} WHERE slug = ?', ['throws-in-hook']));
        self::assertSame(0, (int) $db->value('SELECT enabled FROM {{plugins}} WHERE slug = ?', ['throws-in-hook']));
        self::assertStringContainsString('hook boom', (string) $db->value('SELECT deactivated_error FROM {{plugins}} WHERE slug = ?', ['throws-in-hook']));
        self::assertSame(200, self::http('GET', '/admin/plugins')['status']);
    }

    #[TestDox('/cron/run refuses a wrong token and runs with the right one, under both its addresses')]
    public function testCron(): void
    {
        self::assertSame(401, self::http('GET', '/cron/run?token=wrong')['status']);
        $db = self::connect(self::PREFIX);
        $token = urlencode((string) json_decode((string) $db->value('SELECT value FROM {{settings}} WHERE name = ?', ['cron.token']), true));
        self::assertSame(200, self::http('GET', "/cron/run?token=$token")['status']);

        // The original's per-job addresses are the same door: a church moving
        // over has these in its host's cron panel already.
        self::assertSame(401, self::http('GET', '/api/cron/transcribe?token=wrong')['status']);
        $ran = self::http('GET', "/api/cron/transcribe?token=$token");
        self::assertSame(200, $ran['status']);
        self::assertSame(['transcribe'], array_column((array) $ran['json']['ran'], 'name'));

        // And a name nothing answers to says so, rather than reporting that it
        // ran nothing successfully — which is how a cron line rots unnoticed.
        $nonsense = self::http('GET', "/api/cron/no-such-job?token=$token");
        self::assertSame(404, $nonsense['status']);
        self::assertSame('no_such_job', $nonsense['json']['code'] ?? null);
    }
}
