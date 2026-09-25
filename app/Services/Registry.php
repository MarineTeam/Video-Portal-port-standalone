<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Cache;
use App\Core\Crypto;
use App\Core\Db;
use App\Core\Hooks;

/**
 * The service slots and their providers.
 *
 * Providers are registered in code — the core's here, a plugin's through the
 * services.providers hook — and the admin screens read nothing but this
 * registry, so a new provider never touches a core screen. The services table
 * holds one row per (slot, provider) that has ever been configured; the
 * active one is where new work goes, and the others keep serving what they
 * hold (a video library can span three hosts).
 */
final class Registry
{
    /** Slots and the provider used when nothing is configured. */
    public const SLOTS = [
        'auth' => 'local',
        'video' => null,
        'email' => 'none',
        'files' => 'local',
        'sms' => null,
    ];

    /** @var array<string, array<string, class-string<ServiceProvider>>> slot => id => class */
    private array $providers = [];

    /** @var array<string, ServiceProvider> */
    private array $instances = [];

    public function __construct(private readonly Db $db, private readonly Hooks $hooks)
    {
        foreach ([
            Email\NoEmailProvider::class,
            Email\PhpMailProvider::class,
            Email\SmtpProvider::class,
            Email\ResendProvider::class,
            Email\MailgunProvider::class,
            Email\SendGridProvider::class,
            Email\PostmarkProvider::class,
            Email\SesProvider::class,
            Email\BrevoProvider::class,
            Email\GraphProvider::class,
            Files\LocalDiskProvider::class,
            Files\BunnyStorageProvider::class,
            Auth\LocalProvider::class,
            Auth\OidcProvider::class,
            Auth\Auth0Provider::class,
            Video\BunnyStreamProvider::class,
            Video\YouTubeProvider::class,
            Video\VimeoProvider::class,
            Video\DropboxProvider::class,
            Video\GoogleDriveProvider::class,
            Video\OneDriveProvider::class,
            Video\ArchiveProvider::class,
            Video\S3Provider::class,
            Video\DirectProvider::class,
            Video\LocalVideoProvider::class,
        ] as $class) {
            $this->register($class);
        }
        $this->hooks->do('services.providers', $this);
    }

    /** @param class-string<ServiceProvider> $class */
    public function register(string $class): void
    {
        if (!is_subclass_of($class, ServiceProvider::class)) {
            throw new \InvalidArgumentException("$class is not a ServiceProvider.");
        }
        $this->providers[$class::slot()][$class::id()] = $class;
    }

    /** @return array<string, class-string<ServiceProvider>> */
    public function providers(string $slot): array
    {
        return $this->providers[$slot] ?? [];
    }

    /** @return class-string<ServiceProvider>|null */
    public function providerClass(string $slot, string $id): ?string
    {
        return $this->providers[$slot][$id] ?? null;
    }

    /** @return list<string> */
    public function slots(): array
    {
        return array_values(array_unique([...array_keys(self::SLOTS), ...array_keys($this->providers)]));
    }

    /**
     * The active row for a slot.
     *
     * @return array{provider: string, config: array<string, mixed>, updated_at: ?string, updated_by: ?string}|null
     */
    public function activeRow(string $slot): ?array
    {
        $rows = $this->rows();
        foreach ($rows as $row) {
            if ($row['slot'] === $slot && $row['active']) {
                return $row;
            }
        }
        return null;
    }

    public function activeId(string $slot): ?string
    {
        return $this->activeRow($slot)['provider'] ?? self::SLOTS[$slot] ?? null;
    }

    /**
     * The provider new work goes to, or null when the slot is unset.
     */
    public function active(string $slot): ?ServiceProvider
    {
        $id = $this->activeId($slot);
        return $id === null ? null : $this->get($slot, $id);
    }

    /** A specific provider with its saved configuration (it may not be the active one). */
    public function get(string $slot, string $id): ?ServiceProvider
    {
        $key = "$slot/$id";
        if (isset($this->instances[$key])) {
            return $this->instances[$key];
        }
        $class = $this->providerClass($slot, $id);
        if ($class === null) {
            return null;
        }
        $config = [];
        foreach ($this->rows() as $row) {
            if ($row['slot'] === $slot && $row['provider'] === $id) {
                $config = $row['config'];
            }
        }
        return $this->instances[$key] = new $class(self::decryptConfig($class, $config));
    }

    /**
     * The saved configuration of a provider, decrypted, for building a test
     * instance with some fields changed.
     *
     * @return array<string, mixed>
     */
    public function savedConfig(string $slot, string $id): array
    {
        $class = $this->providerClass($slot, $id);
        foreach ($this->rows() as $row) {
            if ($row['slot'] === $slot && $row['provider'] === $id && $class !== null) {
                return self::decryptConfig($class, $row['config']);
            }
        }
        return [];
    }

    /**
     * Saves a provider's configuration and, when $activate, makes it the
     * slot's active one. The caller has already checked the test passed.
     *
     * @param array<string, mixed> $config plaintext
     */
    public function save(string $slot, string $id, array $config, bool $activate, ?string $by): void
    {
        $class = $this->providerClass($slot, $id) ?? throw new \InvalidArgumentException("Unknown provider $slot/$id");
        $stored = self::encryptConfig($class, $config);
        $this->db->transaction(function (Db $db) use ($slot, $id, $stored, $activate, $by) {
            $db->run(
                'INSERT INTO {{services}} (id, slot, provider, active, config, updated_by) VALUES (?, ?, ?, 0, ?, ?)
                 ON DUPLICATE KEY UPDATE config = VALUES(config), updated_by = VALUES(updated_by)',
                [\App\Core\Id::new(), $slot, $id, json_encode($stored, JSON_THROW_ON_ERROR), $by],
            );
            if ($activate) {
                $db->run('UPDATE {{services}} SET active = (provider = ?) WHERE slot = ?', [$id, $slot]);
            }
        });
        Cache::forget('services');
        unset($this->instances["$slot/$id"]);
    }

    /**
     * The origins the page must allow: every video provider's (a library can
     * hold videos on all of them at once) and each other slot's active one.
     *
     * @return array<string, list<string>> directive => origins
     */
    public function cspSources(): array
    {
        $out = [];
        $classes = array_values($this->providers('video'));
        foreach ($this->slots() as $slot) {
            if ($slot === 'video') {
                continue;
            }
            $id = $this->activeId($slot);
            if ($id !== null && ($class = $this->providerClass($slot, $id)) !== null) {
                $classes[] = $class;
            }
        }
        foreach ($classes as $class) {
            foreach ($class::cspSources() as $directive => $origins) {
                foreach ($origins as $origin) {
                    $out[$directive][] = $origin;
                }
            }
        }
        // A bucket's own address, which only its settings know.
        $s3 = $this->savedConfig('video', 's3');
        if (is_string($s3['endpoint'] ?? null) && preg_match('#^(https://[a-z0-9.-]+(:\d+)?)#i', (string) $s3['endpoint'], $m)) {
            $out['connect'][] = $m[1];
            $out['media'][] = $m[1];
        }
        // Signed file links redirect to the storage pull zone: the reader
        // fetches it, audio plays from it, images show from it.
        $bunny = $this->savedConfig('files', 'bunny');
        if (is_string($bunny['pull_zone_host'] ?? null) && preg_match('/^[a-z0-9.-]+$/i', (string) $bunny['pull_zone_host'])) {
            foreach (['connect', 'media', 'img'] as $directive) {
                $out[$directive][] = 'https://' . $bunny['pull_zone_host'];
            }
        }
        return $out;
    }

    /** Unsets a slot (texting "off"): nothing is active. */
    public function deactivate(string $slot): void
    {
        $this->db->run('UPDATE {{services}} SET active = 0 WHERE slot = ?', [$slot]);
        Cache::forget('services');
    }

    /** @return list<array{slot: string, provider: string, active: bool, config: array<string, mixed>, updated_at: ?string, updated_by: ?string}> */
    private function rows(): array
    {
        return Cache::remember('services', 3600, function () {
            try {
                $rows = $this->db->all('SELECT slot, provider, active, config, updated_at, updated_by FROM {{services}}');
            } catch (\Throwable) {
                return [];
            }
            return array_map(fn ($r) => [
                'slot' => (string) $r['slot'],
                'provider' => (string) $r['provider'],
                'active' => (bool) $r['active'],
                'config' => (array) (json_decode((string) $r['config'], true) ?: []),
                'updated_at' => $r['updated_at'],
                'updated_by' => $r['updated_by'],
            ], $rows);
        }) ?? [];
    }

    /**
     * @param class-string<ServiceProvider> $class
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function encryptConfig(string $class, array $config): array
    {
        $out = [];
        foreach ($class::configSchema() as $field) {
            $key = $field['key'];
            if (!array_key_exists($key, $config)) {
                continue;
            }
            $value = $config[$key];
            $out[$key] = ($field['secret'] ?? false) && is_string($value) && $value !== ''
                ? ['secret' => Crypto::encrypt($value)]
                : $value;
        }
        return $out;
    }

    /**
     * @param class-string<ServiceProvider> $class
     * @param array<string, mixed> $stored
     * @return array<string, mixed>
     */
    public static function decryptConfig(string $class, array $stored): array
    {
        $out = [];
        foreach ($stored as $key => $value) {
            if (is_array($value) && isset($value['secret']) && is_string($value['secret'])) {
                $out[$key] = Crypto::decrypt($value['secret']);
            } else {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /**
     * Merges a submitted form over the saved configuration: a secret field
     * left blank keeps its saved value (the form never echoes it back).
     *
     * @param class-string<ServiceProvider> $class
     * @param array<string, mixed> $saved
     * @param array<string, mixed> $submitted
     * @return array<string, mixed>
     */
    public static function mergeSubmitted(string $class, array $saved, array $submitted): array
    {
        $out = [];
        foreach ($class::configSchema() as $field) {
            $key = $field['key'];
            $value = $submitted[$key] ?? null;
            if (($field['secret'] ?? false) && ($value === null || $value === '')) {
                $out[$key] = $saved[$key] ?? null;
                continue;
            }
            if (($field['type'] ?? 'text') === 'toggle') {
                $out[$key] = in_array($value, [true, 1, '1', 'on', 'true'], true);
                continue;
            }
            $out[$key] = is_string($value) ? trim($value) : ($value ?? ($field['default'] ?? null));
        }
        return $out;
    }
}
