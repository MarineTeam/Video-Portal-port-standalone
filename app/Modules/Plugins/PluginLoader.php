<?php

declare(strict_types=1);

namespace App\Modules\Plugins;

use App\Core\App;
use App\Core\Cache;
use App\Core\Db;
use App\Core\ErrorPage;
use App\Core\Log;
use App\Core\Migrator;
use App\Core\Url;
use App\Services\Email\Message;

/**
 * Loads active plugins, and takes a broken one out of the way rather than
 * letting it take the site down — on shared hosting there is no shell to
 * disable it from.
 *
 * Three things deactivate a plugin automatically, each recorded on its row
 * with the reason and the first 2 KB of the error:
 *   1. anything thrown while requiring plugin.php or running boot();
 *   2. a fatal error (parse error, memory exhaustion, timeout) during load,
 *      caught by a shutdown function while loading.json names the plugin;
 *   3. a loading.json older than a minute left by a different request — a
 *      process killed mid-load in a way nothing could catch.
 * Hook callbacks that throw after boot are contained by Hooks; ten failures in
 * ten minutes deactivates the plugin the same way.
 */
final class PluginLoader
{
    /** Pages that never load third-party plugins, so an admin can always reach them. */
    public const SAFE_PATHS = ['/admin/plugins', '/admin/logs', '/api/admin/plugins'];

    public const HOOK_FAILURE_LIMIT = 10;
    public const HOOK_FAILURE_WINDOW = 600;

    private ?string $currentlyLoading = null;

    /** @var array<string, Plugin> */
    private array $loaded = [];

    /** @var array<string, array<string, mixed>> slug => header, for every plugin on disk */
    private ?array $available = null;

    private bool $shutdownRegistered = false;

    public function __construct(private readonly App $app)
    {
    }

    public static function isSafePath(string $path): bool
    {
        foreach (self::SAFE_PATHS as $safe) {
            if ($path === $safe || str_starts_with($path, $safe . '/')) {
                return true;
            }
        }
        return false;
    }

    private function marker(): string
    {
        return $this->app->paths->storage('plugins/loading.json');
    }

    /**
     * Every plugin directory with a parseable header whose slug matches its
     * directory name.
     *
     * @return array<string, array<string, mixed>>
     */
    public function available(): array
    {
        if ($this->available !== null) {
            return $this->available;
        }
        $out = [];
        foreach (glob($this->app->paths->plugins . '/*/plugin.php') ?: [] as $file) {
            $dir = basename(dirname($file));
            $header = Header::read($file);
            if ($header === null || $header['slug'] !== $dir) {
                continue;
            }
            $header['dir'] = dirname($file);
            $header['bundled'] = is_file(dirname($file) . '/.bundled');
            $out[$header['slug']] = $header;
        }
        return $this->available = $out;
    }

    /**
     * Seeds a row for every bundled plugin on disk, active by default — the
     * port of ensurePluginsSeeded(). Third-party plugins start inactive.
     */
    public function seed(): void
    {
        $db = $this->app->db();
        $names = [];
        foreach (Features::META as $meta) {
            $names[$meta['slug']] = $meta;
        }
        foreach ($this->available() as $slug => $header) {
            $meta = $names[$slug] ?? null;
            $db->run(
                'INSERT IGNORE INTO {{plugins}} (id, slug, name, description, enabled, bundled, version) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [\App\Core\Id::new(), $slug, $meta['name'] ?? $header['name'], $meta['description'] ?? $header['description'], $header['bundled'] ? 1 : 0, $header['bundled'] ? 1 : 0, $header['version']],
            );
        }
        // A feature listed in the registry whose package isn't on disk yet
        // still gets its row, so the per-category machinery has something to point at.
        foreach (Features::META as $meta) {
            $db->run(
                'INSERT IGNORE INTO {{plugins}} (id, slug, name, description, enabled, bundled) VALUES (?, ?, ?, ?, 1, 1)',
                [\App\Core\Id::new(), $meta['slug'], $meta['name'], $meta['description']],
            );
        }
        PluginStates::forget();
    }

    /**
     * The active plugins, in load order: bundled first, then by declared
     * dependencies, then name.
     *
     * @return list<string>
     */
    public function activeSlugs(): array
    {
        $rows = Cache::remember('plugins-active', 3600, fn () => $this->app->db()->all(
            'SELECT slug, bundled FROM {{plugins}} WHERE enabled = 1 AND slug <> ?',
            [Features::QUERY_MONITOR],
        ));
        $available = $this->available();
        $active = [];
        foreach ($rows as $row) {
            if (isset($available[$row['slug']])) {
                $active[(string) $row['slug']] = (bool) $row['bundled'];
            }
        }
        $ordered = array_keys($active);
        usort($ordered, fn ($a, $b) => [$active[$a] ? 0 : 1, $a] <=> [$active[$b] ? 0 : 1, $b]);
        // Dependencies before dependants; a missing or inactive dependency
        // leaves the dependant out.
        $out = [];
        $visit = function (string $slug, array $path) use (&$visit, &$out, $available, $active): bool {
            if (in_array($slug, $out, true)) {
                return true;
            }
            if (!isset($active[$slug]) || in_array($slug, $path, true)) {
                return false;
            }
            foreach ($available[$slug]['depends'] as $dep) {
                if (!$visit($dep, [...$path, $slug])) {
                    return false;
                }
            }
            $out[] = $slug;
            return true;
        };
        foreach ($ordered as $slug) {
            $visit($slug, []);
        }
        return $out;
    }

    /**
     * @param 'all'|'bundled-only'|'auth-only' $mode
     */
    public function boot(string $mode = 'all'): void
    {
        $this->registerShutdown();
        $this->app->hooks->onFailure(function (string $owner, \Throwable $e, string $hook): void {
            if (!isset($this->loaded[$owner])) {
                return;
            }
            $count = Cache::hit("plugin-failures:$owner", self::HOOK_FAILURE_WINDOW);
            if ($count >= self::HOOK_FAILURE_LIMIT) {
                $this->deactivate($owner, 'hook_failures', "Failed $count times in ten minutes; last on $hook: " . $e->getMessage());
                $this->app->hooks->forget($owner);
            }
        });

        try {
            $slugs = $this->activeSlugs();
        } catch (\Throwable $e) {
            Log::error('Could not read the plugin list: ' . $e->getMessage());
            return;
        }
        $available = $this->available();
        foreach ($slugs as $slug) {
            $header = $available[$slug];
            if ($mode === 'bundled-only' && !$header['bundled']) {
                continue;
            }
            if ($mode === 'auth-only' && !$header['bundled'] && !in_array('auth', $header['provides'], true)) {
                continue;
            }
            $this->load($slug, $header);
        }
    }

    /** @param array<string, mixed> $header */
    private function load(string $slug, array $header): void
    {
        if (version_compare(PHP_VERSION, (string) $header['requiresPhp'], '<')) {
            $this->deactivate($slug, 'requirements', "Needs PHP {$header['requiresPhp']}; this host runs " . PHP_VERSION . '.');
            return;
        }
        // The same question about the site itself. A plugin written against a
        // hook that does not exist here fatals on its first request
        // otherwise, on a site somebody has already upgraded halfway.
        if ((string) $header['requiresApp'] !== '' && version_compare(App::VERSION, (string) $header['requiresApp'], '<')) {
            $this->deactivate($slug, 'requirements', "Needs version {$header['requiresApp']} of the site; this is " . App::VERSION . '.');
            return;
        }
        $marker = $this->marker();
        if (!is_dir(dirname($marker))) {
            @mkdir(dirname($marker), 0775, true);
        }
        @file_put_contents($marker, json_encode(['slug' => $slug, 'request_id' => $this->app->request()->id, 'started_at' => time()]));
        $this->currentlyLoading = $slug;
        try {
            $plugin = $this->app->hooks->as($slug, function () use ($header) {
                $result = (static fn (string $__file) => require $__file)($header['dir'] . '/plugin.php');
                return $result instanceof Plugin ? $result : throw new \UnexpectedValueException('plugin.php must return an object implementing ' . Plugin::class . '.');
            });
            $this->app->hooks->as($slug, fn () => $plugin->boot($this->app->hooks, $this->app));
            $this->loaded[$slug] = $plugin;
            $this->runPendingMigration($slug, $plugin, (string) $header['version']);
        } catch (\Throwable $e) {
            $this->app->hooks->forget($slug);
            $this->deactivate($slug, 'load_error', $e::class . ': ' . $e->getMessage() . ' in ' . $this->relative($e->getFile()) . ':' . $e->getLine());
        } finally {
            $this->currentlyLoading = null;
            @unlink($marker);
        }
    }

    /** One pending plugin migration per request, the same resumable runner as the core's. */
    private function runPendingMigration(string $slug, Plugin $plugin, string $version): void
    {
        $dir = $plugin->migrations();
        if ($dir === null) {
            return;
        }
        $migrator = new Migrator($this->app->db(), $this->app->paths->app() . '/Migrations');
        $migrator->addSource('plugin-' . $slug, $dir);
        foreach ($migrator->pending() as $pending) {
            if (str_starts_with($pending['name'], "plugin-$slug/")) {
                $migrator->apply($pending['file']);
                $this->app->db()->run('INSERT INTO {{schema_migrations}} (name, checksum, applied_at) VALUES (?, ?, ?)', [$pending['name'], hash_file('sha256', $pending['file']), Db::now()]);
                return;
            }
        }
        $this->app->db()->run('UPDATE {{plugins}} SET installed_version = ? WHERE slug = ? AND (installed_version IS NULL OR installed_version <> ?)', [$version, $slug, $version]);
    }

    private function registerShutdown(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }
        $this->shutdownRegistered = true;
        register_shutdown_function(function (): void {
            $slug = $this->currentlyLoading;
            if ($slug === null) {
                return;
            }
            $error = error_get_last();
            if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true)) {
                return;
            }
            // Memory may be nearly gone: release what we can before writing.
            $this->loaded = [];
            @ini_set('memory_limit', (string) max(256 * 1024 * 1024, (int) ini_get('memory_limit')));
            try {
                $this->deactivate($slug, 'fatal_error', $error['message'] . ' in ' . $this->relative($error['file']) . ':' . $error['line']);
            } catch (\Throwable) {
                // The next request's stale-marker check will catch it instead.
                return;
            }
            @unlink($this->marker());
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
            if (!headers_sent()) {
                ErrorPage::render(500)->send();
            }
        });
    }

    /**
     * A marker left by another request more than a minute ago means that
     * request died mid-load. Deactivate the plugin it names before anything loads.
     */
    public function recoverFromCrashedLoad(): void
    {
        $marker = $this->marker();
        if (!is_file($marker)) {
            return;
        }
        $data = json_decode((string) @file_get_contents($marker), true);
        if (!is_array($data) || !isset($data['slug'], $data['started_at'])) {
            @unlink($marker);
            return;
        }
        if (($data['request_id'] ?? '') === $this->app->request()->id || (int) $data['started_at'] > time() - 60) {
            return;
        }
        $this->deactivate((string) $data['slug'], 'crashed', 'A request stopped while loading this plugin and never finished (killed by the host, out of memory, or a crash).');
        @unlink($marker);
    }

    /** One column, a cache clear, and one email to the administrators. */
    public function deactivate(string $slug, string $reason, string $error): void
    {
        $db = $this->app->db();
        $changed = $db->run(
            'UPDATE {{plugins}} SET enabled = 0, deactivated_reason = ?, deactivated_at = ?, deactivated_error = ?, notice_dismissed = 0 WHERE slug = ? AND enabled = 1',
            [$reason, Db::now(), mb_strcut(\App\Core\Log::mask($error), 0, 2048), $slug],
        )->rowCount();
        Cache::forget('plugins-active');
        PluginStates::forget();
        unset($this->loaded[$slug]);
        Log::error("Plugin $slug deactivated ($reason): $error", ['plugin' => $slug]);
        if ($changed === 1) {
            try {
                $mailer = $this->app->mailer();
                foreach ($db->column("SELECT email FROM {{users}} WHERE role = 'ADMIN'") as $email) {
                    $mailer->send(Message::plain((string) $email, "Plugin \"$slug\" was switched off", "The plugin \"$slug\" failed and was deactivated automatically so the site stays up.\n\nReason: $reason\n$error", Url::absolute('/admin/plugins'), 'Open the plugin list'));
                }
            } catch (\Throwable) {
                // The notice on every admin page still says so.
            }
        }
    }

    public function activate(string $slug): void
    {
        $header = $this->available()[$slug] ?? throw new \InvalidArgumentException('No such plugin.');
        $plugin = $this->instantiate($slug, $header);
        // A throw here leaves the plugin inactive, with the reason shown.
        $plugin->activate($this->app);
        $this->app->db()->run(
            'INSERT INTO {{plugins}} (id, slug, name, description, enabled, bundled, version) VALUES (?, ?, ?, ?, 1, ?, ?)
             ON DUPLICATE KEY UPDATE enabled = 1, deactivated_reason = NULL, deactivated_at = NULL, deactivated_error = NULL, version = VALUES(version)',
            [\App\Core\Id::new(), $slug, $header['name'], $header['description'], $header['bundled'] ? 1 : 0, $header['version']],
        );
        Cache::forget('plugins-active');
        PluginStates::forget();
    }

    public function deactivateByAdmin(string $slug): void
    {
        $header = $this->available()[$slug] ?? null;
        if ($header !== null) {
            try {
                $this->instantiate($slug, $header)->deactivate($this->app);
            } catch (\Throwable $e) {
                Log::warning("Plugin $slug's deactivate() threw: " . $e->getMessage());
            }
        }
        $this->app->db()->run('UPDATE {{plugins}} SET enabled = 0, deactivated_reason = NULL, deactivated_error = NULL WHERE slug = ?', [$slug]);
        Cache::forget('plugins-active');
        PluginStates::forget();
    }

    /** @param array<string, mixed> $header */
    private function instantiate(string $slug, array $header): Plugin
    {
        if (isset($this->loaded[$slug])) {
            return $this->loaded[$slug];
        }
        $result = (static fn (string $__file) => require $__file)($header['dir'] . '/plugin.php');
        if (!$result instanceof Plugin) {
            throw new \UnexpectedValueException('plugin.php must return a Plugin.');
        }
        return $result;
    }

    public function isLoaded(string $slug): bool
    {
        return isset($this->loaded[$slug]);
    }

    /** @return list<array<string, mixed>> plugins deactivated automatically whose notice hasn't been dismissed */
    public function notices(): array
    {
        try {
            return $this->app->db()->all(
                'SELECT slug, name, deactivated_reason, deactivated_at, deactivated_error FROM {{plugins}}
                 WHERE enabled = 0 AND deactivated_reason IS NOT NULL AND notice_dismissed = 0 ORDER BY deactivated_at DESC',
            );
        } catch (\Throwable) {
            return [];
        }
    }

    private function relative(string $file): string
    {
        $root = $this->app->paths->root . '/';
        return str_starts_with($file, $root) ? substr($file, strlen($root)) : basename($file);
    }
}
