<?php

declare(strict_types=1);

namespace App\Install;

use App\Core\App;
use App\Core\Crypto;
use App\Core\Db;
use App\Core\ErrorPage;
use App\Core\Http;
use App\Core\Id;
use App\Core\Migrator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Url;
use App\Core\Validator;
use App\Modules\Access\Passwords;
use App\Modules\Branding\Branding;
use App\Modules\Uploads\Uploads;

/**
 * The installation wizard, served by the front controller until
 * storage/installed.lock exists, after which /install is a 404.
 *
 * Its first screen asks for the contents of storage/install.key, which it
 * has just written: only somebody who can read the site's files can answer,
 * and a site on a temporary hostname is reachable by anyone who guesses it.
 * A config.php with no lock (a half-finished install) asks again.
 */
final class Installer
{
    private const COOKIE = 'mt_install';

    private const STEPS = ['key', 'requirements', 'database', 'migrate', 'site', 'services', 'finish'];

    public function __construct(private readonly App $app)
    {
    }

    private function storage(string $sub = ''): string
    {
        return $this->app->paths->storage($sub);
    }

    public function handle(Request $req): Response
    {
        // A probe path the requirements step fetches from itself to prove
        // pretty URLs reach the front controller.
        if ($req->path === '/install/rewrite-probe') {
            return Response::text('marine-team-rewrite-ok');
        }
        if (!str_starts_with($req->path, '/install')) {
            return Response::redirect(Url::to('/install'));
        }
        $this->protectStorage();
        if (!is_dir($this->storage()) || !is_writable($this->storage())) {
            return $this->render('Storage is not writable', '<p>The installer needs to write to <code>' . e($this->storage()) . '</code>. Make that folder writable by the web server (in your host’s file manager: permissions 755 or 775 on the folder), then reload this page.</p>');
        }
        $keyFile = $this->storage('install.key');
        if (!is_file($keyFile)) {
            file_put_contents($keyFile, bin2hex(random_bytes(32)) . "\n");
            @chmod($keyFile, 0640);
        }
        $key = trim((string) file_get_contents($keyFile));

        $step = trim(substr($req->path, strlen('/install')), '/');
        $step = $step === '' ? 'key' : explode('/', $step)[0];
        if ($step !== 'key' && !$this->proven($req, $key)) {
            return Response::redirect(Url::to('/install'));
        }
        if (in_array($step, ['migrate', 'site', 'services', 'finish'], true) && !isset($this->app->config['database'])) {
            return Response::redirect(Url::to('/install/database'));
        }
        return match ($step) {
            'key' => $this->key($req, $key),
            'requirements' => $this->requirements($req),
            'database' => $this->database($req),
            'migrate' => $this->migrate($req),
            'site' => $this->site($req),
            'services' => $this->services($req),
            'finish' => $this->finish($req),
            default => ErrorPage::render(404),
        };
    }

    private function proven(Request $req, string $key): bool
    {
        $cookie = $req->cookie(self::COOKIE);
        return is_string($cookie) && hash_equals(hash('sha256', 'install:' . $key), $cookie);
    }

    private function csrf(Request $req): string
    {
        return hash_hmac('sha256', 'install-csrf', (string) $req->cookie(self::COOKIE));
    }

    private function checkPost(Request $req): bool
    {
        return $req->method === 'POST' && hash_equals($this->csrf($req), (string) ($req->post['_csrf'] ?? ''));
    }

    // -- 1. Proof of file access -------------------------------------------------

    private function key(Request $req, string $key): Response
    {
        $error = null;
        if ($req->method === 'POST') {
            $given = trim((string) ($req->post['key'] ?? ''));
            if ($given !== '' && hash_equals($key, $given)) {
                $response = Response::redirect(Url::to('/install/requirements'));
                $response->cookie(self::COOKIE, hash('sha256', 'install:' . $key), [
                    'maxAge' => 6 * 3600,
                    'path' => Url::basePath() === '' ? '/' : Url::basePath() . '/',
                    'secure' => $req->https,
                ]);
                return $response;
            }
            usleep(500_000);
            $error = 'That isn’t the key in the file. Copy the whole line from storage/install.key.';
        }
        $html = '<p>This site hasn’t been set up yet. To prove you are the person installing it, open the file <code>storage/install.key</code> with your host’s file manager or FTP, and paste its contents here.</p>'
            . ($error ? '<p class="error">' . e($error) . '</p>' : '')
            . '<form method="post" class="stack narrow"><label>Install key<input name="key" autocomplete="off" required autofocus></label><button class="button primary" type="submit">Continue</button></form>';
        return $this->render('Welcome', $html, 1);
    }

    // -- 2. Requirements ---------------------------------------------------------

    /** @return list<array{label: string, ok: bool|null, detail: string, blocking: bool}> */
    private function checks(Request $req): array
    {
        $out = [];
        $out[] = ['label' => 'PHP 8.2 or newer', 'ok' => version_compare(PHP_VERSION, '8.2.0', '>='), 'detail' => 'This host runs PHP ' . PHP_VERSION . '. In your control panel, choose PHP 8.2, 8.3 or 8.4 for this site.', 'blocking' => true];
        foreach (['pdo_mysql' => 'talk to the database', 'mbstring' => 'handle text in every language', 'json' => 'read and write data', 'openssl' => 'encrypt secrets and check signatures', 'ctype' => 'validate input', 'fileinfo' => 'check what uploaded files really are'] as $ext => $why) {
            $out[] = ['label' => "The $ext extension", 'ok' => extension_loaded($ext), 'detail' => "Needed to $why. Ask your host to enable the $ext extension.", 'blocking' => true];
        }
        foreach (['curl' => 'reach other services quickly (it falls back to PHP streams)', 'zip' => 'install plugins, themes and updates from a zip', 'sodium' => 'check the signature of an update uploaded as a zip', 'gd' => 'resize and clean uploaded images', 'intl' => 'sort and fold names in every language'] as $ext => $why) {
            $out[] = ['label' => "The $ext extension (optional)", 'ok' => extension_loaded($ext), 'detail' => "Used to $why.", 'blocking' => false];
        }
        $out[] = ['label' => 'Web Push signing (bcmath or gmp, optional)', 'ok' => extension_loaded('bcmath') || extension_loaded('gmp'), 'detail' => 'Without one of these, push notifications report themselves unavailable; email and the inbox still work.', 'blocking' => false];
        $out[] = ['label' => 'storage/ is writable', 'ok' => is_writable($this->storage()), 'detail' => 'The installer, sessions, logs and uploads need it.', 'blocking' => true];

        // Pretty URLs: fetch a known path from ourselves.
        $rewrite = null;
        try {
            $probe = Http::request('GET', Url::absolute('/install/rewrite-probe'), [], null, ['timeout' => 5]);
            $rewrite = $probe->status === 200 && str_contains($probe->body, 'marine-team-rewrite-ok');
        } catch (\Throwable) {
            $rewrite = null;
        }
        $out[] = ['label' => 'Pretty URLs (mod_rewrite)', 'ok' => $rewrite, 'detail' => $rewrite === null
            ? 'Couldn’t check from here (this host may not let the site request itself). If this page’s own links work, you are fine.'
            : 'The .htaccess rewrite isn’t reaching the app. Make sure the .htaccess files were uploaded (they are hidden files) and ask your host to enable mod_rewrite and AllowOverride.', 'blocking' => $rewrite === false];

        // storage/ must not be readable over the web.
        $probeFile = $this->storage('web-probe.txt');
        @file_put_contents($probeFile, 'marine-team-storage-exposed');
        $exposed = null;
        $relative = self::relativeStorageUrl($this->app);
        if ($relative !== null) {
            try {
                $response = Http::request('GET', Url::absolute($relative . '/web-probe.txt'), [], null, ['timeout' => 5]);
                $exposed = $response->status === 200 && str_contains($response->body, 'marine-team-storage-exposed');
            } catch (\Throwable) {
                $exposed = null;
            }
        } else {
            $exposed = false; // outside the web root entirely
        }
        @unlink($probeFile);
        $out[] = ['label' => 'storage/ is not readable from the web', 'ok' => $exposed === null ? null : !$exposed, 'detail' => $exposed === null
            ? 'Couldn’t check from here. Open ' . Url::absolute(($relative ?? '/storage') . '/install.key') . ' in another tab: if you see the key, storage is exposed — stop and ask your host to honour .htaccess, or move storage/ above the web root.'
            : 'Files in storage/ can be downloaded by anyone. The installer refuses to continue: ask your host to honour .htaccess files (AllowOverride), or move storage/ above the document root. On nginx, add the location block from INSTALL.md.', 'blocking' => $exposed === true];

        $outbound = Http::probeOutbound();
        $out[] = ['label' => 'Outbound HTTPS', 'ok' => $outbound['ok'], 'detail' => $outbound['message'], 'blocking' => false];
        $out[] = ['label' => 'HTTPS', 'ok' => $req->https, 'detail' => $req->https ? 'This page arrived over HTTPS.' : 'This page arrived over plain HTTP. Local sign-in, video and email all work; external sign-in providers stay unavailable until the site has a certificate.', 'blocking' => false];
        $out[] = ['label' => 'Upload size', 'ok' => true, 'detail' => 'upload_max_filesize is ' . ini_get('upload_max_filesize') . '; larger files are sent in ' . round(Uploads::chunkSize() / 1048576, 1) . ' MB slices, so this is not a limit on what you can upload.', 'blocking' => false];
        $out[] = ['label' => 'Execution time', 'ok' => true, 'detail' => 'max_execution_time is ' . ini_get('max_execution_time') . 's; long jobs are split into short runs.', 'blocking' => false];
        return $out;
    }

    /** Where storage/ would be under the web root, if it is under it at all. */
    public static function relativeStorageUrl(App $app): ?string
    {
        $root = rtrim($app->paths->root, '/');
        $storage = rtrim($app->paths->storage, '/');
        return str_starts_with($storage, $root . '/') ? substr($storage, strlen($root)) : null;
    }

    private function requirements(Request $req): Response
    {
        if ($req->method === 'POST' && $this->checkPost($req)) {
            $state = $this->state();
            $state['trust_proxy'] = ($req->post['trust_proxy'] ?? '') === '1';
            $this->saveState($state);
            return Response::redirect(Url::to('/install/database'));
        }
        $checks = $this->checks($req);
        $blocked = array_filter($checks, fn ($c) => $c['blocking'] && $c['ok'] === false) !== [];
        $rows = '';
        foreach ($checks as $c) {
            $mark = $c['ok'] === true ? '<span class="ok">✓</span>' : ($c['ok'] === null ? '<span class="warn">?</span>' : ($c['blocking'] ? '<span class="error">✗</span>' : '<span class="warn">–</span>'));
            $rows .= '<tr><td>' . $mark . '</td><td><strong>' . e($c['label']) . '</strong><div class="small muted">' . e($c['detail']) . '</div></td></tr>';
        }
        $html = '<table class="table">' . $rows . '</table>';
        if ($blocked) {
            $html .= '<p class="error">Fix the items marked ✗, then reload this page.</p>';
        } else {
            $html .= '<form method="post" class="stack narrow"><input type="hidden" name="_csrf" value="' . e($this->csrf($req)) . '">'
                . '<label class="check"><input type="checkbox" name="trust_proxy" value="1"> This site sits behind a proxy or CDN that terminates HTTPS (Cloudflare, a load balancer). Only tick this if it does: it makes the site believe the X-Forwarded-Proto header.</label>'
                . '<button class="button primary" type="submit">Continue</button></form>';
        }
        return $this->render('Check this host', $html, 2);
    }

    // -- 3. Database ---------------------------------------------------------------

    private function database(Request $req): Response
    {
        $values = ['host' => 'localhost', 'port' => '3306', 'name' => '', 'user' => '', 'prefix' => 'mt_'];
        $error = null;
        if ($req->method === 'POST' && $this->checkPost($req)) {
            foreach ($values as $k => $_) {
                $values[$k] = trim((string) ($req->post[$k] ?? ''));
            }
            $password = (string) ($req->post['password'] ?? '');
            if (!preg_match(Db::PREFIX_PATTERN, $values['prefix'])) {
                $error = 'The table prefix may only use lower-case letters, digits and underscores (at most 16).';
            } else {
                $config = ['host' => $values['host'], 'port' => (int) $values['port'], 'name' => $values['name'], 'user' => $values['user'], 'password' => $password, 'prefix' => $values['prefix']];
                try {
                    $db = Db::connect($config);
                    $version = (string) $db->value('SELECT VERSION()');
                    self::assertVersion($version);
                    self::assertPrivileges($db);
                    if ((int) $db->value('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$values['prefix'] . 'users']) > 0 && ($req->post['reuse'] ?? '') !== '1') {
                        throw new \RuntimeException("Tables with the prefix “{$values['prefix']}” already exist in this database. Choose another prefix, or tick the box to continue an earlier installation.");
                    }
                    $this->writeConfig($req, $config);
                    return Response::redirect(Url::to('/install/migrate'));
                } catch (\PDOException $e) {
                    $error = self::explainPdo($e);
                } catch (\Throwable $e) {
                    $error = $e->getMessage();
                }
            }
        }
        $field = fn (string $name, string $label, string $help = '', string $type = 'text') => '<label>' . e($label) . '<input type="' . $type . '" name="' . $name . '" value="' . ($type === 'password' ? '' : e($values[$name] ?? '')) . '"' . ($name === 'name' || $name === 'user' ? ' required' : '') . '></label>' . ($help ? '<p class="small muted">' . e($help) . '</p>' : '');
        $html = '<p>Create an empty MySQL or MariaDB database and a user for it in your host’s control panel (cPanel → MySQL Databases), give the user all privileges on it, and enter the details here.</p>'
            . ($error ? '<p class="error">' . e($error) . '</p>' : '')
            . '<form method="post" class="stack narrow" autocomplete="off"><input type="hidden" name="_csrf" value="' . e($this->csrf($req)) . '">'
            . $field('host', 'Database server', 'Usually localhost.') . $field('port', 'Port') . $field('name', 'Database name') . $field('user', 'Database user') . $field('password', 'Password', '', 'password')
            . $field('prefix', 'Table prefix', 'Lets this site share a database with others. Lower-case letters, digits and underscores.')
            . '<label class="check"><input type="checkbox" name="reuse" value="1"> Continue an earlier, unfinished installation that used this prefix</label>'
            . '<button class="button primary" type="submit">Test and continue</button></form>';
        return $this->render('Database', $html, 3);
    }

    public static function assertVersion(string $version): void
    {
        $isMaria = stripos($version, 'mariadb') !== false;
        $number = (string) preg_replace('/^(\d+\.\d+(\.\d+)?).*$/', '$1', $version);
        if ($isMaria ? version_compare($number, '10.6', '<') : version_compare($number, '8.0', '<')) {
            throw new \RuntimeException("This database server is $version. The site needs MySQL 8.0 or newer, or MariaDB 10.6 or newer — ask your host which versions they offer.");
        }
    }

    /** Proves the privileges the migrations need, with a throwaway table. */
    public static function assertPrivileges(Db $db): void
    {
        $t = $db->prefix() . 'install_probe_' . bin2hex(random_bytes(3));
        try {
            $db->pdo()->exec("CREATE TABLE `$t` (id INT PRIMARY KEY, parent INT NULL, KEY p (parent), CONSTRAINT `{$t}_fk` FOREIGN KEY (parent) REFERENCES `$t` (id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $db->pdo()->exec("ALTER TABLE `$t` ADD COLUMN note VARCHAR(10) NULL, ADD INDEX n (note)");
            $db->pdo()->exec("INSERT INTO `$t` (id) VALUES (1)");
            $db->pdo()->query("SELECT id FROM `$t` FOR UPDATE")->fetchAll();
        } catch (\PDOException $e) {
            throw new \RuntimeException('The database user can’t create and change tables (' . $e->getMessage() . '). In your control panel, give this user ALL PRIVILEGES on the database.');
        } finally {
            try {
                $db->pdo()->exec("DROP TABLE IF EXISTS `$t`");
            } catch (\Throwable) {
            }
        }
    }

    public static function explainPdo(\PDOException $e): string
    {
        $code = (int) ($e->errorInfo[1] ?? 0);
        return match (true) {
            $code === 1045 => 'The database refused that user name and password.',
            $code === 1049 => 'There is no database with that name. Create it in your control panel first.',
            $code === 1044 => 'That user isn’t allowed to use this database. Add the user to the database with all privileges.',
            in_array($code, [2002, 2003, 2005], true) => 'Couldn’t reach the database server. On most hosts the server is “localhost”.',
            default => 'The database said: ' . $e->getMessage(),
        };
    }

    private function writeConfig(Request $req, array $database): void
    {
        $state = $this->state();
        $scheme = $req->https ? 'https' : 'http';
        $config = [
            'database' => $database,
            'app_key' => Crypto::generateAppKey(),
            'base_url' => $scheme . '://' . $req->host . Url::basePath(),
            'storage' => $this->storage(),
            'debug' => false,
            'trust_proxy' => (bool) ($state['trust_proxy'] ?? false),
            'file_offload' => null,
            'hsts' => false,
        ];
        $existing = is_file($this->app->paths->config()) ? require $this->app->paths->config() : null;
        if (is_array($existing) && isset($existing['app_key'])) {
            // Re-running the database step keeps the key, or encrypted values become unreadable.
            $config['app_key'] = $existing['app_key'];
        }
        $php = "<?php\n\n// Written by the installer. Keep this file private: it holds the database\n// password and the key every stored secret is encrypted with.\n\nreturn " . var_export($config, true) . ";\n";
        $tmp = $this->app->paths->config() . '.tmp';
        if (@file_put_contents($tmp, $php) === false || !@rename($tmp, $this->app->paths->config())) {
            throw new \RuntimeException('Couldn’t write storage/config.php.');
        }
        @chmod($this->app->paths->config(), 0640);
        $this->app->config = $config;
    }

    // -- 4. Migrations, one file per request ---------------------------------------

    private function migrate(Request $req): Response
    {
        $db = $this->db();
        $migrator = new Migrator($db, $this->app->paths->app() . '/Migrations');
        if ($req->method === 'POST' && $this->checkPost($req)) {
            @set_time_limit(60);
            $result = $migrator->runNext();
            return Response::json($result + ['done' => $result['remaining'] === 0]);
        }
        $pending = count($migrator->pending());
        $total = count($migrator->all());
        if ($pending === 0) {
            return Response::redirect(Url::to('/install/site'));
        }
        $html = '<p>Creating the database tables: ' . ($total - $pending) . ' of ' . $total . ' steps done. Each step runs as its own request, so a slow host can’t leave the database half-built — if this stops, reload the page and it carries on.</p>'
            . '<progress max="' . $total . '" value="' . ($total - $pending) . '" data-install-progress></progress>'
            . '<form method="post" data-install-migrate><input type="hidden" name="_csrf" value="' . e($this->csrf($req)) . '"><button class="button primary" type="submit">Run the next step</button></form>'
            . '<script type="module" src="' . e(asset('js/install.js')) . '"></script>';
        return $this->render('Setting up the database', $html, 4);
    }

    private function db(): Db
    {
        if (!isset($this->app->config['database'])) {
            throw new \RuntimeException('The database step hasn’t been completed.');
        }
        Crypto::configure((string) $this->app->config['app_key']);
        return $this->app->db();
    }

    // -- 5. Site and the first administrator ---------------------------------------

    private function site(Request $req): Response
    {
        $db = $this->db();
        if ((new Migrator($db, $this->app->paths->app() . '/Migrations'))->pending() !== []) {
            return Response::redirect(Url::to('/install/migrate'));
        }
        $values = ['name' => 'Marine Team', 'shortName' => 'Marine Team', 'brand' => '#1a8fd1', 'brandDeep' => '#0288d1', 'brandLight' => '#4fc3f7', 'locale' => 'en', 'timezone' => 'UTC', 'email' => ''];
        $error = null;
        if ($req->method === 'POST' && $this->checkPost($req)) {
            foreach ($values as $k => $_) {
                $values[$k] = trim((string) ($req->post[$k] ?? $values[$k]));
            }
            $password = (string) ($req->post['password'] ?? '');
            $email = Validator::normalizeEmail($values['email']);
            if (!Validator::isEmail($email)) {
                $error = 'Enter the administrator’s email address.';
            } elseif (($problem = Passwords::problem($password, $email)) !== null) {
                $error = $problem;
            } elseif (!in_array($values['timezone'], \DateTimeZone::listIdentifiers(), true)) {
                $error = 'Choose a time zone from the list.';
            } else {
                $this->seed($db, $values, $email, $password);
                return Response::redirect(Url::to('/install/services'));
            }
        }
        $zones = '';
        foreach (\DateTimeZone::listIdentifiers() as $zone) {
            $zones .= '<option' . ($zone === $values['timezone'] ? ' selected' : '') . '>' . e($zone) . '</option>';
        }
        $html = ($error ? '<p class="error">' . e($error) . '</p>' : '')
            . '<form method="post" class="stack narrow"><input type="hidden" name="_csrf" value="' . e($this->csrf($req)) . '">'
            . '<h2>The site</h2>'
            . '<label>Name<input name="name" value="' . e($values['name']) . '" maxlength="60" required></label>'
            . '<label>Short name (under an app icon)<input name="shortName" value="' . e($values['shortName']) . '" maxlength="30" required></label>'
            . '<div class="row"><label>Brand colour<input type="color" name="brand" value="' . e($values['brand']) . '"></label><label>Deep<input type="color" name="brandDeep" value="' . e($values['brandDeep']) . '"></label><label>Light<input type="color" name="brandLight" value="' . e($values['brandLight']) . '"></label></div>'
            . '<label>Default language<select name="locale"><option value="en"' . ($values['locale'] === 'en' ? ' selected' : '') . '>English</option><option value="es"' . ($values['locale'] === 'es' ? ' selected' : '') . '>Español</option></select></label>'
            . '<label>Time zone<select name="timezone">' . $zones . '</select></label>'
            . '<h2>The first administrator</h2>'
            . '<p class="small muted">A local account with a password. It stays available whatever sign-in you choose later: it is how you get back in.</p>'
            . '<label>Email<input type="email" name="email" value="' . e($values['email']) . '" autocomplete="username" required></label>'
            . '<label>Password<input type="password" name="password" autocomplete="new-password" minlength="12" required></label>'
            . '<p class="small muted">At least 12 characters. A few ordinary words strung together is easy to remember and hard to guess.</p>'
            . '<button class="button primary" type="submit">Continue</button></form>';
        return $this->render('Your site', $html, 5);
    }

    /** @param array<string, string> $values */
    private function seed(Db $db, array $values, string $email, string $password): void
    {
        $db->transaction(function (Db $db) use ($values, $email, $password) {
            $userId = $db->value('SELECT id FROM {{users}} WHERE email = ?', [$email]);
            if ($userId === null) {
                $db->insert('users', [
                    'email' => $email,
                    'name' => 'Administrator',
                    'role' => 'ADMIN',
                    'authorized' => true,
                    'password_hash' => Passwords::hash($password),
                    'email_verified_at' => Db::now(),
                ]);
            } else {
                $db->update('users', ['role' => 'ADMIN', 'authorized' => true, 'password_hash' => Passwords::hash($password), 'email_verified_at' => Db::now()], ['id' => $userId]);
            }
            $db->run('INSERT IGNORE INTO {{authorized_emails}} (id, email, status, note, added_by_email) VALUES (?, ?, \'ACTIVE\', \'First administrator\', \'installer\')', [Id::new(), $email]);
            $set = function (string $name, mixed $value) use ($db): void {
                $db->run('INSERT INTO {{settings}} (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$name, json_encode($value)]);
            };
            $set('auth.mode', 'BOTH');
            $set('auth.bootstrap_admins', [$email]);
            $set('site.locale', $values['locale']);
            $set('site.timezone', $values['timezone']);
            $set('theme.active', 'default');
            $set(\App\Modules\Update\Updater::VERSION_SETTING, \App\Core\App::VERSION);
            $db->run('INSERT IGNORE INTO {{settings}} (name, value) VALUES (?, ?)', ['cron.token', json_encode(bin2hex(random_bytes(24)))]);
            $db->run('INSERT IGNORE INTO {{auth_settings}} (id) VALUES (\'singleton\')');
            $db->run('INSERT IGNORE INTO {{download_policies}} (id) VALUES (\'singleton\')');
            foreach ([['auth', 'local'], ['files', 'local']] as [$slot, $provider]) {
                $db->run('INSERT INTO {{services}} (id, slot, provider, active, config, updated_by) VALUES (?, ?, ?, 1, ?, ?) ON DUPLICATE KEY UPDATE active = 1', [Id::new(), $slot, $provider, '{}', 'installer']);
            }
        });
        Branding::save($db, $values);
        $this->app->plugins()->seed();
    }

    // -- 6. Services ---------------------------------------------------------------

    private function services(Request $req): Response
    {
        if ($req->method === 'POST' && $this->checkPost($req)) {
            return Response::redirect(Url::to('/install/finish'));
        }
        $https = $req->https;
        $html = '<p>These are set so the site works with nothing external configured. Each can be changed at any time under <strong>Admin → Services</strong>, where every provider is tested before it is switched on.</p>'
            . '<table class="table">'
            . '<tr><th>Sign-in</th><td>Local accounts. ' . ($https ? 'Auth0, Google, Microsoft and other providers can be added later.' : 'External providers (Auth0, Google, Microsoft…) become available once the site is reached over HTTPS.') . '</td></tr>'
            . '<tr><th>Files</th><td>This host’s own disk (storage/uploads).</td></tr>'
            . '<tr><th>Email</th><td>Not set up yet. Until it is, messages are recorded but not sent, and password resets need an administrator. Set it up first after installing: the host’s own mail server via SMTP is usually the quickest.</td></tr>'
            . '<tr><th>Video</th><td>Not set up yet: add bunny.net, YouTube, Vimeo or another provider when you add your first video.</td></tr>'
            . '<tr><th>Text messages</th><td>Off.</td></tr>'
            . '</table>'
            . '<form method="post"><input type="hidden" name="_csrf" value="' . e($this->csrf($req)) . '"><button class="button primary" type="submit">Continue</button></form>';
        return $this->render('Services', $html, 6);
    }

    // -- 7. Finish -------------------------------------------------------------------

    private function finish(Request $req): Response
    {
        $db = $this->db();
        if ($db->value('SELECT COUNT(*) FROM {{users}} WHERE role = \'ADMIN\'') == 0) {
            return Response::redirect(Url::to('/install/site'));
        }
        $token = json_decode((string) $db->value('SELECT value FROM {{settings}} WHERE name = \'cron.token\''), true);
        $cronUrl = Url::absolute('/cron/run', ['token' => (string) $token]);
        if ($req->method === 'POST' && $this->checkPost($req)) {
            file_put_contents($this->app->paths->installedLock(), gmdate('c') . "\n");
            @unlink($this->storage('install.key'));
            @unlink($this->storage('install-state.json'));
            $response = Response::redirect(Url::to('/auth/login', ['returnTo' => Url::to('/admin')]));
            $response->forgetCookie(self::COOKIE, Url::basePath() === '' ? '/' : Url::basePath() . '/', $req->https);
            return $response;
        }
        $html = '<p>Last step: scheduled jobs (reminders, digests, clean-up) need something to wake them. In your host’s control panel, add a cron job that runs every five minutes:</p>'
            . '<pre class="code">*/5 * * * * curl -fsS "' . e($cronUrl) . '" &gt;/dev/null</pre>'
            . '<p class="small muted">No curl? Use <code>wget -q -O /dev/null "' . e($cronUrl) . '"</code>. With shell access you can also run <code>php bin/cron.php</code>. Keep this address private.</p>'
            . '<p>If you don’t add it, jobs still run — but only when somebody visits the site, at most once a minute. On a quiet site that means a reminder can go out late.</p>'
            . '<form method="post"><input type="hidden" name="_csrf" value="' . e($this->csrf($req)) . '"><button class="button primary" type="submit">Finish and sign in</button></form>';
        return $this->render('Almost done', $html, 7);
    }

    // -- Helpers -------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function state(): array
    {
        $raw = @file_get_contents($this->storage('install-state.json'));
        $state = $raw === false ? null : json_decode($raw, true);
        return is_array($state) ? $state : [];
    }

    /** @param array<string, mixed> $state */
    private function saveState(array $state): void
    {
        @file_put_contents($this->storage('install-state.json'), json_encode($state));
    }

    /**
     * storage/ is closed to the web by .htaccess (for Apache and LiteSpeed)
     * and by an index.php in every directory the app writes to (for a server
     * that ignores .htaccess but would list a directory).
     */
    private function protectStorage(): void
    {
        $storage = $this->storage();
        foreach (['', 'logs', 'cache', 'uploads', 'tmp', 'plugins', 'videos'] as $sub) {
            $dir = $sub === '' ? $storage : "$storage/$sub";
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            if (!is_file("$dir/index.php")) {
                @file_put_contents("$dir/index.php", "<?php\nhttp_response_code(404);\n");
            }
        }
        if (!is_file("$storage/.htaccess")) {
            @file_put_contents("$storage/.htaccess", "# Nothing in storage/ is ever served directly.\nRequire all denied\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\nOptions -Indexes\n");
        }
    }

    private function render(string $title, string $body, int $step = 0): Response
    {
        $dots = '';
        foreach (self::STEPS as $i => $name) {
            $dots .= '<li class="' . ($i + 1 < $step ? 'done' : ($i + 1 === $step ? 'current' : '')) . '">' . e(ucfirst($name)) . '</li>';
        }
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
            . '<title>' . e($title) . ' — install</title><link rel="stylesheet" href="' . e(asset('css/app.css')) . '"></head>'
            . '<body class="auth install"><main class="auth-card wide" id="main"><p class="brand">Marine Team — installation</p>'
            . ($step > 0 ? '<ol class="steps">' . $dots . '</ol>' : '')
            . '<h1>' . e($title) . '</h1>' . $body . '</main></body></html>';
        $response = Response::html($html);
        $response->header('Cache-Control', 'no-store');
        $response->header('X-Robots-Tag', 'noindex');
        return $response;
    }
}
