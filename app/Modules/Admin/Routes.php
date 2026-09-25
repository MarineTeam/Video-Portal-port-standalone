<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\ApiError;
use App\Core\App;
use App\Core\Cache;
use App\Core\Crypto;
use App\Core\Db;
use App\Core\ErrorPage;
use App\Core\Http;
use App\Core\Log;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Url;
use App\Modules\Audit\Audit;
use App\Modules\Jobs\Jobs;
use App\Modules\Plugins\Features;
use App\Modules\Plugins\PluginStates;
use App\Services\Email\Message;
use App\Services\Registry;
use App\Services\ServiceProvider;
use App\Services\TestContext;

/**
 * The admin area's own pages: the dashboard, the system report, logs,
 * plugins, services, jobs and the email log. Content screens arrive with the
 * modules that own them.
 */
final class Routes
{
    public static function register(Router $r, App $app): void
    {
        $self = new self($app);
        $staff = Middleware::staff($app);
        $admin = Middleware::admin($app);
        $plugins = Middleware::can($app, 'manage_plugins');

        $r->get('/admin', [$self, 'dashboard'], [$staff]);
        $r->get('/admin/system', [$self, 'system'], [$admin]);
        $r->get('/admin/logs', [$self, 'logs'], [$admin]);
        $r->get('/admin/logs/download', [$self, 'logsDownload'], [$admin]);

        $r->get('/admin/plugins', [$self, 'plugins'], [$plugins]);
        $r->get('/api/admin/plugins', [$self, 'apiPlugins'], [$plugins]);
        $r->add('PATCH', '/api/admin/plugins/[slug]', [$self, 'apiPluginToggle'], [$plugins]);
        $r->post('/api/admin/plugins/[slug]/overrides', [$self, 'apiOverrideAdd'], [$plugins]);
        $r->add('DELETE', '/api/admin/plugins/overrides/[id]', [$self, 'apiOverrideDelete'], [$plugins]);
        $r->post('/admin/plugins/[slug]/toggle', [$self, 'pluginToggleForm'], [$plugins]);
        $r->post('/admin/plugins/[slug]/dismiss', [$self, 'pluginDismiss'], [$plugins]);
        $r->post('/admin/plugins/install', [$self, 'pluginInstall'], [$plugins]);
        $r->post('/admin/plugins/[slug]/uninstall', [$self, 'pluginUninstall'], [$plugins]);

        $r->get('/admin/providers', [$self, 'services'], [$admin]);
        $r->get('/admin/providers/[slot]/[provider]', [$self, 'serviceForm'], [$admin]);
        $r->post('/admin/providers/[slot]/[provider]/test', [$self, 'serviceTest'], [$admin]);
        $r->post('/admin/providers/[slot]/[provider]/switch', [$self, 'serviceSwitch'], [$admin]);
        $r->post('/admin/providers/[slot]/off', [$self, 'serviceOff'], [$admin]);

        $r->get('/admin/jobs', [$self, 'jobs'], [$admin]);
        $r->post('/admin/jobs/[name]/run', [$self, 'jobRun'], [$admin]);

        $r->get('/admin/email', [$self, 'email'], [$admin]);
        $r->post('/admin/email/[id]/resend', [$self, 'emailResend'], [$admin]);
    }

    public function __construct(private readonly App $app)
    {
    }

    private function page(string $template, array $vars = [], int $status = 200): Response
    {
        $response = $this->app->page($template, $vars, $status, 'layouts/admin');
        $response->header('Cache-Control', 'no-store');
        $response->header('X-Robots-Tag', 'noindex');
        return $response;
    }

    private function actor(): string
    {
        return (string) $this->app->currentUser()->email();
    }

    private function flash(string $message): void
    {
        $this->app->session()->set('flash', $message);
    }

    // Dashboard ---------------------------------------------------------------

    public function dashboard(Request $req): Response
    {
        $db = $this->app->db();
        $count = fn (string $table, string $where = '1=1') => (int) $db->value("SELECT COUNT(*) FROM {{{$table}}} WHERE $where");
        return $this->page('admin/dashboard', [
            'title' => 'Admin',
            'counts' => [
                'Categories' => $count('categories', 'deleted_at IS NULL'),
                'Series' => $count('series', 'deleted_at IS NULL'),
                'Videos' => $count('videos', 'deleted_at IS NULL'),
                'Files' => $count('file_assets', 'deleted_at IS NULL'),
                'Members' => $count('users', 'authorized = 1'),
            ],
            'cards' => $this->app->hooks->apply('admin.dashboard.cards', []),
            'cronSeen' => (int) $this->app->settings()->get('cron.last_real_at', 0),
            'emailConfigured' => $this->app->mailer()->isConfigured(),
        ]);
    }

    // System ----------------------------------------------------------------

    /** @return array<string, mixed> everything /admin/system reports */
    public static function systemReport(App $app): array
    {
        $extensions = [];
        foreach (['pdo_mysql' => true, 'mbstring' => true, 'json' => true, 'openssl' => true, 'ctype' => true, 'fileinfo' => true,
            'curl' => false, 'zip' => false, 'gd' => false, 'bcmath' => false, 'gmp' => false, 'intl' => false, 'sodium' => false] as $ext => $required) {
            $extensions[$ext] = ['loaded' => extension_loaded($ext), 'required' => $required];
        }
        $storage = $app->paths->storage;
        $dbVersion = null;
        try {
            $dbVersion = (string) $app->db()->value('SELECT VERSION()');
        } catch (\Throwable) {
        }
        return [
            'php' => PHP_VERSION,
            'appVersion' => App::VERSION,
            'extensions' => $extensions,
            'limits' => [
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'post_max_size' => ini_get('post_max_size'),
                'allow_url_fopen' => ini_get('allow_url_fopen') ? 'On' : 'Off',
                'disable_functions' => (string) ini_get('disable_functions'),
            ],
            'disk' => ['free' => @disk_free_space($storage) ?: null, 'total' => @disk_total_space($storage) ?: null],
            'database' => $dbVersion,
            'writable' => is_writable($storage),
            'storage' => $storage,
            'uploadChunk' => \App\Modules\Uploads\Uploads::chunkSize(),
            'outbound' => Cache::remember('outbound-probe', 3600, fn () => Http::probeOutbound()),
            'https' => $app->request()->https,
            'offload' => \App\Services\Files\RangeStreamer::$offload,
        ];
    }

    public function system(Request $req): Response
    {
        if ($req->query('probe') === '1') {
            Cache::forget('outbound-probe');
        }
        return $this->page('admin/system', ['title' => 'System', 'report' => self::systemReport($this->app)]);
    }

    public function logs(Request $req): Response
    {
        $channel = in_array($req->query('channel'), ['app', 'cron'], true) ? (string) $req->query('channel') : 'app';
        return $this->page('admin/logs', ['title' => 'Logs', 'channel' => $channel, 'lines' => array_reverse(Log::tail($channel, 300))]);
    }

    public function logsDownload(Request $req): Response
    {
        $file = Log::file('app');
        if ($file === null || !is_file($file)) {
            return Response::text('No log yet.', 404);
        }
        return Response::text((string) file_get_contents($file), 200, 'text/plain; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename="marine-team-' . gmdate('Y-m-d') . '.log"');
    }

    // Plugins ---------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    private function pluginRows(): array
    {
        $loader = $this->app->plugins();
        $loader->seed();
        $available = $loader->available();
        $rows = $this->app->db()->all('SELECT * FROM {{plugins}} WHERE slug <> ? ORDER BY bundled DESC, name', [Features::QUERY_MONITOR]);
        $overrides = [];
        foreach ($this->app->db()->all('SELECT o.id, o.plugin_id, o.enabled, c.name AS category_name, c.id AS category_id FROM {{plugin_category_overrides}} o JOIN {{categories}} c ON c.id = o.category_id') as $o) {
            $overrides[$o['plugin_id']][] = $o;
        }
        $out = [];
        foreach ($rows as $row) {
            $header = $available[$row['slug']] ?? null;
            $out[] = $row + [
                'onDisk' => $header !== null,
                'header' => $header,
                'overrides' => $overrides[$row['id']] ?? [],
            ];
        }
        return $out;
    }

    public function plugins(Request $req): Response
    {
        return $this->page('admin/plugins', [
            'title' => 'Plugins',
            'plugins' => $this->pluginRows(),
            'categories' => $this->app->db()->all('SELECT id, name FROM {{categories}} WHERE deleted_at IS NULL ORDER BY name'),
            'zip' => extension_loaded('zip'),
            'flash' => $this->app->session()->pull('flash'),
        ]);
    }

    /** GET /api/admin/plugins: PLUGIN_META's slugs only — never the query-monitor row. */
    public function apiPlugins(Request $req): Response
    {
        $slugs = Features::slugs();
        $out = [];
        foreach ($this->pluginRows() as $row) {
            if (!in_array($row['slug'], $slugs, true) && !$row['onDisk']) {
                continue;
            }
            $out[] = [
                'id' => $row['id'],
                'slug' => $row['slug'],
                'name' => $row['name'],
                'description' => $row['description'],
                'enabled' => (bool) $row['enabled'],
                'overrides' => array_map(fn ($o) => ['id' => $o['id'], 'categoryId' => $o['category_id'], 'categoryName' => $o['category_name'], 'enabled' => (bool) $o['enabled']], $row['overrides']),
            ];
        }
        return Response::json($out);
    }

    public function apiPluginToggle(Request $req, array $p): Response
    {
        $enabled = $req->input()['enabled'] ?? null;
        if (!is_bool($enabled)) {
            throw ApiError::invalid('enabled must be true or false.');
        }
        $this->setPluginEnabled($p['slug'], $enabled);
        return Response::json(['slug' => $p['slug'], 'enabled' => $enabled]);
    }

    public function pluginToggleForm(Request $req, array $p): Response
    {
        try {
            $this->setPluginEnabled($p['slug'], ($req->input()['enabled'] ?? '') === '1');
            $this->flash('Saved.');
        } catch (\Throwable $e) {
            $this->flash('Could not change that plugin: ' . ($e instanceof ApiError ? $e->getMessage() : 'its activation failed — ' . $e->getMessage()));
        }
        return Response::redirect(Url::to('/admin/plugins'));
    }

    private function setPluginEnabled(string $slug, bool $enabled): void
    {
        $row = $this->app->db()->one('SELECT * FROM {{plugins}} WHERE slug = ?', [$slug]);
        if ($row === null || $slug === Features::QUERY_MONITOR) {
            throw ApiError::notFound();
        }
        $loader = $this->app->plugins();
        if ($enabled) {
            if (isset($loader->available()[$slug])) {
                $loader->activate($slug);
            } else {
                $this->app->db()->update('plugins', ['enabled' => true, 'deactivated_reason' => null, 'deactivated_error' => null], ['slug' => $slug]);
            }
        } elseif (isset($loader->available()[$slug])) {
            $loader->deactivateByAdmin($slug);
        } else {
            $this->app->db()->update('plugins', ['enabled' => false], ['slug' => $slug]);
        }
        Cache::forget('plugins-active');
        PluginStates::forget();
        Audit::log($this->app->db(), $this->actor(), $enabled ? 'plugin.enable' : 'plugin.disable', 'Plugin', $slug);
    }

    public function apiOverrideAdd(Request $req, array $p): Response
    {
        $input = $req->input();
        $categoryId = $input['categoryId'] ?? null;
        $enabled = $input['enabled'] ?? null;
        if (!is_string($categoryId) || !is_bool($enabled)) {
            throw ApiError::invalid('categoryId and enabled are required.');
        }
        $plugin = $this->app->db()->one('SELECT id FROM {{plugins}} WHERE slug = ?', [$p['slug']]);
        if ($plugin === null || $this->app->db()->value('SELECT id FROM {{categories}} WHERE id = ?', [$categoryId]) === null) {
            throw ApiError::notFound();
        }
        $this->app->db()->run(
            'INSERT INTO {{plugin_category_overrides}} (id, plugin_id, category_id, enabled) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)',
            [\App\Core\Id::new(), $plugin['id'], $categoryId, $enabled],
        );
        PluginStates::forget();
        Audit::log($this->app->db(), $this->actor(), 'plugin.override', 'Plugin', $p['slug'], "$categoryId=" . ($enabled ? 'on' : 'off'));
        return Response::json(['ok' => true], 201);
    }

    public function apiOverrideDelete(Request $req, array $p): Response
    {
        if ($this->app->db()->delete('plugin_category_overrides', ['id' => $p['id']]) === 0) {
            throw ApiError::notFound();
        }
        PluginStates::forget();
        return Response::json(['ok' => true]);
    }

    public function pluginDismiss(Request $req, array $p): Response
    {
        $this->app->db()->update('plugins', ['notice_dismissed' => true], ['slug' => $p['slug']]);
        return Response::redirect(Url::safeReturnTo((string) ($req->input()['returnTo'] ?? ''), '/admin/plugins'));
    }

    public function pluginInstall(Request $req): Response
    {
        $uploadId = (string) ($req->input()['upload'] ?? '');
        try {
            $upload = \App\Modules\Uploads\Uploads::take($this->app, $uploadId, 'plugin');
            $slug = (new \App\Modules\Plugins\PackageInstaller($this->app))->install($upload['path'], 'plugin');
            \App\Modules\Uploads\Uploads::discard($this->app, $uploadId);
            Audit::log($this->app->db(), $this->actor(), 'plugin.install', 'Plugin', $slug);
            $this->flash("Installed $slug. It is not active until you switch it on.");
        } catch (\Throwable $e) {
            $this->flash('That package was refused: ' . ($e instanceof ApiError || $e instanceof \RuntimeException ? $e->getMessage() : 'it could not be read.'));
        }
        return Response::redirect(Url::to('/admin/plugins'));
    }

    public function pluginUninstall(Request $req, array $p): Response
    {
        $slug = $p['slug'];
        $row = $this->app->db()->one('SELECT * FROM {{plugins}} WHERE slug = ?', [$slug]);
        if ($row === null) {
            return ErrorPage::render(404);
        }
        if ((bool) $row['bundled']) {
            $this->flash('Bundled plugins can be switched off but not deleted.');
            return Response::redirect(Url::to('/admin/plugins'));
        }
        (new \App\Modules\Plugins\PackageInstaller($this->app))->uninstall($slug);
        Audit::log($this->app->db(), $this->actor(), 'plugin.uninstall', 'Plugin', $slug);
        $this->flash("Deleted $slug and its data.");
        return Response::redirect(Url::to('/admin/plugins'));
    }

    // Services ----------------------------------------------------------------

    public function services(Request $req): Response
    {
        $registry = $this->app->services();
        $slots = [];
        foreach ($registry->slots() as $slot) {
            $slots[$slot] = [
                'active' => $registry->activeRow($slot),
                'activeId' => $registry->activeId($slot),
                'providers' => $registry->providers($slot),
            ];
        }
        return $this->page('admin/services', [
            'title' => 'Services',
            'slots' => $slots,
            'outbound' => Cache::remember('outbound-probe', 3600, fn () => Http::probeOutbound()),
            'https' => $req->https,
            'flash' => $this->app->session()->pull('flash'),
        ]);
    }

    /** @return class-string<ServiceProvider> */
    private function providerClass(string $slot, string $provider): string
    {
        return $this->app->services()->providerClass($slot, $provider) ?? throw ApiError::notFound('No such provider.');
    }

    public function serviceForm(Request $req, array $p): Response
    {
        $class = $this->providerClass($p['slot'], $p['provider']);
        $saved = $this->app->services()->savedConfig($p['slot'], $p['provider']);
        return $this->page('admin/service-form', [
            'title' => $class::label(),
            'slot' => $p['slot'],
            'provider' => $p['provider'],
            'class' => $class,
            'values' => $saved,
            'result' => null,
            'token' => null,
            'https' => $req->https,
            'isActive' => $this->app->services()->activeId($p['slot']) === $p['provider'],
            'outbound' => Cache::get('outbound-probe'),
        ]);
    }

    /**
     * Runs the provider's test against what was just submitted. A pass is
     * signed, short-lived and bound to that exact configuration, and the
     * configuration itself waits in the session — so a stale pass can't be
     * replayed after a field changes.
     */
    public function serviceTest(Request $req, array $p): Response
    {
        [$slot, $id] = [$p['slot'], $p['provider']];
        $class = $this->providerClass($slot, $id);
        $registry = $this->app->services();
        $config = Registry::mergeSubmitted($class, $registry->savedConfig($slot, $id), $req->input());
        $vars = [
            'title' => $class::label(), 'slot' => $slot, 'provider' => $id, 'class' => $class,
            'values' => $config, 'https' => $req->https,
            'isActive' => $registry->activeId($slot) === $id, 'outbound' => Cache::get('outbound-probe'),
        ];
        if ($class::requiresHttps() && !$req->https) {
            return $this->page('admin/service-form', $vars + ['token' => null, 'result' => ['ok' => false, 'message' => 'This provider can only be used once the site is reached over HTTPS: its redirect addresses must be https://, and tokens must not cross the network in clear. Set up the site’s certificate first.', 'steps' => []]], 400);
        }
        try {
            $provider = new $class($config);
            $result = $provider->test(new TestContext($this->actor(), $req->https, Url::baseUrl(), $req->input()));
        } catch (\Throwable $e) {
            Log::warning("Service test for $slot/$id threw: " . $e->getMessage());
            $result = \App\Services\TestResult::fail('The test failed unexpectedly: ' . $e->getMessage());
        }
        $token = null;
        if ($result->ok) {
            $hash = hash('sha256', json_encode($config, JSON_THROW_ON_ERROR));
            $this->app->session()->set('pending_service', [
                'slot' => $slot, 'provider' => $id, 'hash' => $hash,
                'config' => Crypto::encrypt(json_encode($config, JSON_THROW_ON_ERROR)),
                'code' => $result->expectedCodeHash,
            ]);
            $token = Crypto::sign('service-switch', ['slot' => $slot, 'provider' => $id, 'hash' => $hash, 'by' => $this->actor()], 900);
        }
        return $this->page('admin/service-form', $vars + [
            'result' => ['ok' => $result->ok, 'message' => $result->message, 'steps' => $result->steps, 'needsCode' => $result->needsConfirmation()],
            'token' => $token,
        ], $result->ok ? 200 : 422);
    }

    public function serviceSwitch(Request $req, array $p): Response
    {
        [$slot, $id] = [$p['slot'], $p['provider']];
        $input = $req->input();
        $payload = Crypto::verify('service-switch', (string) ($input['token'] ?? ''));
        $pending = $this->app->session()->get('pending_service');
        $refuse = function (string $why) use ($slot) {
            $this->flash($why);
            return Response::redirect(Url::to('/admin/providers'));
        };
        if ($payload === null || !is_array($pending) || $payload['slot'] !== $slot || $payload['provider'] !== $id
            || $pending['slot'] !== $slot || $pending['provider'] !== $id || !hash_equals((string) $pending['hash'], (string) $payload['hash'])
            || $payload['by'] !== $this->actor()) {
            return $refuse('The switch was refused: its test result was missing, expired, or for different settings. Run the test again.');
        }
        if ($pending['code'] !== null) {
            $code = preg_replace('/\D/', '', (string) ($input['code'] ?? ''));
            if (!hash_equals((string) $pending['code'], hash('sha256', (string) $code))) {
                return $refuse('That code doesn’t match the one sent. Run the test again to get a new one.');
            }
        }
        if ($slot === 'auth' && $id !== 'local') {
            return $refuse('Switching sign-in to an external provider needs the trial sign-in, which arrives with those providers.');
        }
        $config = json_decode((string) Crypto::decrypt((string) $pending['config']), true);
        if (!is_array($config) || !hash_equals((string) $pending['hash'], hash('sha256', json_encode($config, JSON_THROW_ON_ERROR)))) {
            return $refuse('The switch was refused: the tested settings could not be recovered. Run the test again.');
        }
        $this->app->services()->save($slot, $id, $config, activate: ($input['activate'] ?? '1') === '1', by: $this->actor());
        $this->app->session()->forget('pending_service');
        Audit::log($this->app->db(), $this->actor(), 'service.switch', 'Service', "$slot/$id");
        $this->flash('Saved: ' . $this->providerClass($slot, $id)::label() . '.');
        return Response::redirect(Url::to('/admin/providers'));
    }

    public function serviceOff(Request $req, array $p): Response
    {
        if (!in_array($p['slot'], ['sms', 'video'], true)) {
            return ErrorPage::render(404);
        }
        $this->app->services()->deactivate($p['slot']);
        Audit::log($this->app->db(), $this->actor(), 'service.off', 'Service', $p['slot']);
        $this->flash('Switched off.');
        return Response::redirect(Url::to('/admin/providers'));
    }

    // Jobs ------------------------------------------------------------------

    public function jobs(Request $req): Response
    {
        $scheduler = Jobs::scheduler($this->app);
        $scheduler->sync();
        $token = $this->app->settings()->string('cron.token');
        return $this->page('admin/jobs', [
            'title' => 'Scheduled jobs',
            'jobs' => $this->app->db()->all('SELECT * FROM {{jobs}} ORDER BY name'),
            'lastReal' => (int) $this->app->settings()->get('cron.last_real_at', 0),
            'cronUrl' => $token === '' ? null : Url::absolute('/cron/run', ['token' => $token]),
            'flash' => $this->app->session()->pull('flash'),
        ]);
    }

    public function jobRun(Request $req, array $p): Response
    {
        $ran = Jobs::scheduler($this->app)->run($p['name']);
        $this->flash($ran === [] ? 'That job is already running.' : $p['name'] . ': ' . $ran[0]['status']);
        return Response::redirect(Url::to('/admin/jobs'));
    }

    // Email log ---------------------------------------------------------------

    public function email(Request $req): Response
    {
        return $this->page('admin/email', [
            'title' => 'Email',
            'rows' => $this->app->db()->all('SELECT id, to_address, subject, provider, status, error, created_at, text_body IS NOT NULL AS resendable FROM {{email_log}} ORDER BY created_at DESC LIMIT 200'),
            'configured' => $this->app->mailer()->isConfigured(),
            'flash' => $this->app->session()->pull('flash'),
        ]);
    }

    public function emailResend(Request $req, array $p): Response
    {
        $row = $this->app->db()->one('SELECT * FROM {{email_log}} WHERE id = ?', [$p['id']]);
        if ($row === null || $row['text_body'] === null) {
            $this->flash('That message can’t be resent (it carried a one-time link).');
            return Response::redirect(Url::to('/admin/email'));
        }
        $result = $this->app->mailer()->send(new Message((string) $row['to_address'], (string) $row['subject'], (string) $row['text_body'], $row['html_body'], $row['reply_to']));
        $this->flash($result->ok() ? 'Sent again.' : 'Not sent: ' . $result->error);
        return Response::redirect(Url::to('/admin/email'));
    }
}
