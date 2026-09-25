<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Site settings that used to be environment variables — the authorization
 * mode, bootstrap administrators, the cron token, the transcription URL — as
 * database rows an admin edits in the browser, because on shared hosting the
 * browser is all they have.
 *
 * Values are JSON. A secret is stored as {"secret": "v1:…"} encrypted under
 * app_key, and read back decrypted only by the code that uses it.
 */
final class Settings
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return array<string, mixed> */
    private function all(): array
    {
        return Cache::remember('settings', 3600, function (): array {
            $out = [];
            foreach ($this->db->all('SELECT name, value FROM {{settings}}') as $row) {
                $out[$row['name']] = json_decode((string) $row['value'], true);
            }
            return $out;
        });
    }

    public function get(string $name, mixed $default = null): mixed
    {
        $value = $this->all()[$name] ?? null;
        if (is_array($value) && array_key_exists('secret', $value) && count($value) === 1) {
            return is_string($value['secret']) ? Crypto::decrypt($value['secret']) : $default;
        }
        return $value ?? $default;
    }

    public function string(string $name, string $default = ''): string
    {
        $value = $this->get($name);
        return is_string($value) ? $value : $default;
    }

    public function bool(string $name, bool $default = false): bool
    {
        $value = $this->get($name);
        return is_bool($value) ? $value : $default;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->all());
    }

    public function set(string $name, mixed $value): void
    {
        $this->write($name, $value);
    }

    public function setSecret(string $name, string $plaintext): void
    {
        $this->write($name, ['secret' => Crypto::encrypt($plaintext)]);
    }

    public function isSecretSet(string $name): bool
    {
        $value = $this->all()[$name] ?? null;
        return is_array($value) && isset($value['secret']);
    }

    public function delete(string $name): void
    {
        $this->db->delete('settings', ['name' => $name]);
        Cache::forget('settings');
    }

    private function write(string $name, mixed $value): void
    {
        if (!preg_match('/^[a-z0-9_.:-]{1,191}$/', $name)) {
            throw new \InvalidArgumentException("Bad setting name: $name");
        }
        $this->db->run(
            'INSERT INTO {{settings}} (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            [$name, json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
        );
        Cache::forget('settings');
    }
}
