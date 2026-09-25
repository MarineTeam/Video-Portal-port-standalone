<?php

declare(strict_types=1);

namespace App\Modules\Update;

use App\Core\App;
use App\Core\Cache;
use App\Core\Migrator;
use App\Core\Settings;

/**
 * Upgrading in place: the new files arrive (over FTP, or as a release zip on
 * /admin/update), the code's version no longer matches the one the database
 * was last brought up to, and until an administrator finishes the update the
 * site answers everyone else with the maintenance page.
 *
 * Finishing runs pending core and active-plugin migrations one file per
 * request (the installer's runner), records the version, and clears caches.
 * The maintenance file names the administrator doing it; everyone else,
 * other administrators included, sees the maintenance page except on
 * /admin/update itself, so a second administrator can take over.
 */
final class Updater
{
    public const VERSION_SETTING = 'app.version';

    public function __construct(private readonly App $app)
    {
    }

    public static function codeVersion(): string
    {
        return App::VERSION;
    }

    public function dbVersion(): ?string
    {
        $v = $this->app->settings()->get(self::VERSION_SETTING);
        return is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * Whether the files are a different version from the one the database
     * was last brought up to. Read from the cached settings, so it costs
     * nothing on an ordinary request. An install that predates the setting
     * counts as current.
     */
    public static function behind(Settings $settings): bool
    {
        $v = $settings->get(self::VERSION_SETTING);
        return is_string($v) && $v !== '' && $v !== self::codeVersion();
    }

    /** Core migrations and those of every active plugin that ships some. */
    public function migrator(): Migrator
    {
        $migrator = new Migrator($this->app->db(), $this->app->paths->app() . '/Migrations');
        $available = $this->app->plugins()->available();
        foreach ($this->app->plugins()->activeSlugs() as $slug) {
            $dir = ($available[$slug]['dir'] ?? null);
            if (is_string($dir) && is_dir($dir . '/migrations')) {
                $migrator->addSource('plugin-' . $slug, $dir . '/migrations');
            }
        }
        return $migrator;
    }

    /** @return list<string> */
    public function pending(): array
    {
        return array_column($this->migrator()->pending(), 'name');
    }

    public function needed(): bool
    {
        return self::behind($this->app->settings()) || $this->pending() !== [];
    }

    // Maintenance ---------------------------------------------------------------

    /** @return array{by: ?string, email: ?string, at: ?string, reason: string}|null */
    public function maintenance(): ?array
    {
        return self::readMaintenance($this->app->paths->maintenance());
    }

    /** @return array{by: ?string, email: ?string, at: ?string, reason: string}|null */
    public static function readMaintenance(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($file), true);
        $data = is_array($data) ? $data : [];
        return [
            'by' => is_string($data['by'] ?? null) ? $data['by'] : null,
            'email' => is_string($data['email'] ?? null) ? $data['email'] : null,
            'at' => is_string($data['at'] ?? null) ? $data['at'] : null,
            'reason' => is_string($data['reason'] ?? null) ? $data['reason'] : 'update',
        ];
    }

    public function enterMaintenance(string $reason = 'update'): void
    {
        $user = $this->app->currentUser();
        $json = json_encode(['by' => $user->id(), 'email' => $user->email(), 'at' => gmdate('c'), 'reason' => $reason], JSON_UNESCAPED_SLASHES);
        if (@file_put_contents($this->app->paths->maintenance(), (string) $json, LOCK_EX) === false) {
            throw new \RuntimeException('storage/ is not writable, so the site can’t be put into maintenance mode.');
        }
    }

    public function leaveMaintenance(): void
    {
        @unlink($this->app->paths->maintenance());
    }

    /**
     * Who may use the site while it is closed: the administrator doing the
     * update everywhere; any administrator on /admin/update (to take over);
     * anyone on the sign-in pages, so that administrator can get back in.
     */
    public static function bypasses(string $path, ?array $maintenance, ?string $userId, bool $isAdmin): bool
    {
        if (str_starts_with($path, '/auth/')) {
            return true;
        }
        if ($isAdmin && ($path === '/admin/update' || str_starts_with($path, '/api/admin/update') || str_starts_with($path, '/admin/update/'))) {
            return true;
        }
        return $isAdmin && $userId !== null && $maintenance !== null && ($maintenance['by'] ?? null) === $userId;
    }

    // Running -------------------------------------------------------------------

    /** @return array{applied: ?string, remaining: int, total: int} */
    public function step(): array
    {
        if ($this->maintenance() === null) {
            $this->enterMaintenance();
        }
        return $this->migrator()->runNext();
    }

    /** Records the version, clears every cache, reopens the site. */
    public function finish(): void
    {
        if ($this->pending() !== []) {
            throw new \RuntimeException('There are still migrations to apply.');
        }
        $this->app->settings()->set(self::VERSION_SETTING, self::codeVersion());
        self::clearCaches();
        $this->leaveMaintenance();
    }

    public static function clearCaches(): void
    {
        Cache::clear();
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }
}
