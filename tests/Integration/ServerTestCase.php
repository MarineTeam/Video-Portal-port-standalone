<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * A real site behind PHP's built-in server, for tests that go through HTTP
 * the way a browser does: its own table prefix and storage folder, cookie
 * jars per person, the CSRF token each form or API call needs, and a
 * one-call install for tests that only need a working site.
 */
abstract class ServerTestCase extends DatabaseTestCase
{
    /** @var resource|null */
    protected static $server = null;
    protected static string $base = '';
    protected static string $storage = '';
    /** @var array<string, string> who => cookie jar */
    protected static array $jars = [];

    /** The table prefix this test class owns. */
    abstract protected static function prefix(): string;

    public static function setUpBeforeClass(): void
    {
        if (!function_exists('proc_open') || !function_exists('curl_init')) {
            self::markTestSkipped('proc_open and curl are needed to run the server.');
        }
        $name = getenv('MT_TEST_DB_NAME');
        if (!is_string($name) || $name === '') {
            self::markTestSkipped('MT_TEST_DB_NAME is not set.');
        }
        $db = self::connect(static::prefix());
        self::dropPrefix($db, static::prefix());
        self::$storage = sys_get_temp_dir() . '/mt-srv-' . bin2hex(random_bytes(4));
        mkdir(self::$storage, 0775, true);
        self::$jars = [];
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
        if (self::$storage !== '') {
            \App\Modules\Plugins\PackageInstaller::removeTree(self::$storage);
        }
        foreach (self::$jars as $jar) {
            @unlink($jar);
        }
        $name = getenv('MT_TEST_DB_NAME');
        if (is_string($name) && $name !== '') {
            self::dropPrefix(self::connect(static::prefix()), static::prefix());
        }
    }

    protected static function jar(string $who): string
    {
        return self::$jars[$who] ??= self::$storage . '.' . $who . '.cookies';
    }

    /**
     * @param array<string, string>|string|null $body a form, or a JSON string
     * @param array<string, string> $headers
     * @return array{status: int, body: string, location: ?string, json: mixed, headers: array<string, string>}
     */
    protected static function http(string $method, string $path, array|string|null $body = null, string $who = 'admin', array $headers = []): array
    {
        $ch = curl_init(self::$base . $path);
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = "$k: $v";
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => self::jar($who),
            CURLOPT_COOKIEFILE => self::jar($who),
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => $lines,
        ]);
        if (is_array($body) && $body !== []) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body));
        } elseif (is_string($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $head = substr($raw, 0, $headerSize);
        preg_match('/^Location:\s*(\S+)/mi', $head, $m);
        $text = substr($raw, $headerSize);
        $headers = [];
        foreach (preg_split('/\r?\n/', $head) ?: [] as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }
        return ['status' => $status, 'body' => $text, 'location' => $m[1] ?? null, 'json' => json_decode($text, true), 'headers' => $headers];
    }

    protected static function csrf(string $path, string $who = 'admin'): string
    {
        preg_match('/name="_csrf" value="([^"]+)"/', self::http('GET', $path, null, $who)['body'], $m);
        return $m[1] ?? '';
    }

    /**
     * A JSON API call as $who, with the CSRF header the page's meta tag carries.
     *
     * @return array{status: int, body: string, location: ?string, json: mixed, headers: array<string, string>}
     */
    protected static function api(string $method, string $path, mixed $payload = null, string $who = 'admin'): array
    {
        preg_match('/name="csrf-token" content="([^"]+)"/', self::http('GET', '/', null, $who)['body'], $m);
        return self::http($method, $path, $payload === null ? null : (string) json_encode($payload), $who, [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-CSRF-Token' => $m[1] ?? '',
        ]);
    }

    protected static function signIn(string $email, string $password, string $who): void
    {
        $r = self::http('POST', '/auth/login', ['_csrf' => self::csrf('/auth/login', $who), 'email' => $email, 'password' => $password], $who);
        if ($r['status'] !== 303 || str_contains((string) $r['location'], '/access-denied')) {
            throw new \RuntimeException("Sign-in as $email failed ({$r['status']}).");
        }
    }

    /** Installs the site by HTTP and signs admin@test.example in as "admin". */
    protected static function install(): void
    {
        self::http('GET', '/install');
        $key = trim((string) file_get_contents(self::$storage . '/install.key'));
        self::http('POST', '/install', ['key' => $key]);
        self::http('POST', '/install/requirements', ['_csrf' => self::csrf('/install/requirements')]);
        $db = self::dbConfig(static::prefix());
        self::http('POST', '/install/database', ['_csrf' => self::csrf('/install/database'), 'host' => $db['host'], 'port' => (string) $db['port'], 'name' => $db['name'], 'user' => $db['user'], 'password' => $db['password'], 'prefix' => static::prefix()]);
        $c = self::csrf('/install/migrate');
        for ($i = 0; $i < 30; $i++) {
            if ((self::http('POST', '/install/migrate', ['_csrf' => $c])['json']['done'] ?? false) === true) {
                break;
            }
        }
        self::http('POST', '/install/site', ['_csrf' => self::csrf('/install/site'), 'name' => 'Test Church', 'shortName' => 'Test', 'brand' => '#123456', 'brandDeep' => '#123456', 'brandLight' => '#abcdef', 'locale' => 'en', 'timezone' => 'UTC', 'email' => 'admin@test.example', 'password' => 'correct horse battery']);
        self::http('POST', '/install/services', ['_csrf' => self::csrf('/install/services')]);
        self::http('POST', '/install/finish', ['_csrf' => self::csrf('/install/finish')]);
        if (!is_file(self::$storage . '/installed.lock')) {
            throw new \RuntimeException('The install did not finish.');
        }
        self::signIn('admin@test.example', 'correct horse battery', 'admin');
    }

    /** A member account, signed in as $who. */
    protected static function member(string $email, string $who): string
    {
        $db = self::connect(static::prefix());
        $id = \App\Core\Id::new();
        $db->insert('users', ['id' => $id, 'email' => $email, 'role' => 'MEMBER', 'authorized' => 1, 'email_verified_at' => gmdate('Y-m-d H:i:s'), 'password_hash' => password_hash('member password 123', PASSWORD_DEFAULT)]);
        // On the "who can sign in" list, as a real member is.
        $db->insert('authorized_emails', ['id' => \App\Core\Id::new(), 'email' => $email]);
        self::flushCache();
        self::signIn($email, 'member password 123', $who);
        return $id;
    }

    /** Forgets the file cache, after a test changes the database behind the site's back. */
    protected static function flushCache(): void
    {
        array_map('unlink', glob(self::$storage . '/cache/*.cache') ?: []);
    }
}
