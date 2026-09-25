<?php

declare(strict_types=1);

namespace App\Services;

/** Sensible defaults, so a provider states only what is particular to it. */
abstract class BaseProvider implements ServiceProvider
{
    /** @param array<string, mixed> $config */
    public function __construct(protected readonly array $config)
    {
    }

    public static function requiresHttps(): bool
    {
        return false;
    }

    public static function requiresOutboundHttps(): bool
    {
        return false;
    }

    public static function cspSources(): array
    {
        return [];
    }

    public static function limits(): string
    {
        return '';
    }

    public static function configSchema(): array
    {
        return [];
    }

    protected function cfg(string $key, mixed $default = null): mixed
    {
        $value = $this->config[$key] ?? null;
        return $value === null || $value === '' ? $default : $value;
    }

    protected function str(string $key, string $default = ''): string
    {
        $value = $this->cfg($key, $default);
        return is_scalar($value) ? trim((string) $value) : $default;
    }
}
