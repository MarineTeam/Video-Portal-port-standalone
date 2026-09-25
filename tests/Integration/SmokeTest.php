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
final class SmokeTest extends DatabaseTestCase
{
    private const PREFIX = 'smk_';

    /** @var resource|null */
    private static $server = null;
    private static string $base = '';
    private static string $storage = '';
    private static string $jar = '';

    public static function setUpBeforeClass(): void
    {
        if (!function_exists('proc_open') || !function_exists('curl_init')) {
            self::markTestSkipped('proc_open and curl are needed to run the server.');
        }
        $db = self::connect(self::PREFIX);
        self::dropPrefix($db, self::PREFIX);
        self::$storage = sys_get_temp_dir() . '/mt-smoke-' . bin2hex(random_bytes(4));
        mkdir(self::$storage, 0775, true);
        self::$jar = self::$storage . '.cookies';
        $port = 18000 + random_int(0, 999);
        self::$base = "http://127.0.0.1:$port";
        $root = dirname(__DIR__, 2);
        $env = array_merge(getenv(), ['MT_STORAGE_DIR' => self::$storage, 'PHP_CLI_SERVER_WORKERS' => '4']);
        self::$server = proc_open(
            [PHP_BINARY, '-d', 'memory_limit=128M', '-d', 'max_execution_time=30', '-S', "127.0.0.1:$port", '-t', "$root/public", "$root/tools/dev/router.php"],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            $root,
            $env,
        );
        for ($i = 0; $i < 50; $i++) {
            if (@fsockopen('127.0.0.1', $port) !== false) {
                break;
            }
            usleep(100_000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
        }
        foreach (['throws-on-load', 'parse-error', 'exhausts-memory', 'good-plugin', 'throws-in-hook'] as $slug) {
            \App\Modules\Plugins\PackageInstaller::removeTree(dirname(__DIR__, 2) . "/plugins/$slug");
        }
        if (self::$storage !== '') {
            \App\Modules\Plugins\PackageInstaller::removeTree(self::$storage);
            @unlink(self::$jar);
        }
    }

    /** @return array{status: int, body: string, location: ?string} */
    private static function http(string $method, string $path, array $form = []): array
    {
        $ch = curl_init(self::$base . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => self::$jar,
            CURLOPT_COOKIEFILE => self::$jar,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HEADER => true,
        ]);
        if ($form !== []) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
        }
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $headers = substr($raw, 0, $headerSize);
        preg_match('/^Location:\s*(\S+)/mi', $headers, $m);
        return ['status' => $status, 'body' => substr($raw, $headerSize), 'location' => $m[1] ?? null];
    }

    private static function csrf(string $path): string
    {
        preg_match('/name="_csrf" value="([^"]+)"/', self::http('GET', $path)['body'], $m);
        return $m[1] ?? '';
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

    #[TestDox('signs the first administrator in and serves the admin area, refusing a login without its CSRF token')]
    public function testSignIn(): void
    {
        self::assertSame(403, self::http('POST', '/auth/login', ['email' => 'admin@smoke.test', 'password' => 'correct horse battery'])['status']);
        $r = self::http('POST', '/auth/login', ['_csrf' => self::csrf('/auth/login'), 'email' => 'admin@smoke.test', 'password' => 'correct horse battery']);
        self::assertSame(303, $r['status']);
        foreach (['/admin', '/admin/plugins', '/admin/providers', '/admin/jobs', '/admin/system', '/admin/logs'] as $path) {
            self::assertSame(200, self::http('GET', $path)['status'], $path);
        }
    }

    #[TestDox('a plugin that throws, parse-fails or exhausts memory on load is switched off with the reason, and the site stays up')]
    public function testBrokenPlugins(): void
    {
        $root = dirname(__DIR__, 2);
        $slugs = ['throws-on-load', 'parse-error', 'exhausts-memory', 'good-plugin'];
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
        foreach ($db->all('SELECT slug, enabled, deactivated_reason FROM {{plugins}} WHERE slug IN (?, ?, ?, ?)', $slugs) as $row) {
            $rows[$row['slug']] = $row;
        }
        self::assertSame('load_error', $rows['throws-on-load']['deactivated_reason']);
        self::assertSame('load_error', $rows['parse-error']['deactivated_reason']);
        self::assertSame('fatal_error', $rows['exhausts-memory']['deactivated_reason']);
        self::assertSame(1, (int) $rows['good-plugin']['enabled']);
        self::assertSame('hello from a plugin', self::http('GET', '/hello-plugin')['body']);
        // The plugin list is reachable, and says what happened.
        $list = self::http('GET', '/admin/plugins');
        self::assertSame(200, $list['status']);
        self::assertStringContainsString('switched off automatically', $list['body']);
    }

    #[TestDox('/cron/run refuses a wrong token and runs with the right one')]
    public function testCron(): void
    {
        self::assertSame(401, self::http('GET', '/cron/run?token=wrong')['status']);
        $db = self::connect(self::PREFIX);
        $token = json_decode((string) $db->value('SELECT value FROM {{settings}} WHERE name = ?', ['cron.token']), true);
        self::assertSame(200, self::http('GET', '/cron/run?token=' . urlencode((string) $token))['status']);
    }
}
