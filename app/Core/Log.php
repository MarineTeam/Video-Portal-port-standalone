<?php

declare(strict_types=1);

namespace App\Core;

/**
 * A line-per-event log under storage/logs, rotated by size, read by admins at
 * /admin/logs because on shared hosting they have no other way to.
 *
 * Secrets are masked before anything is written: Authorization headers,
 * cookies, tokens, passwords and keys. A log is the file most likely to be
 * pasted into a support forum.
 */
final class Log
{
    public const MAX_BYTES = 2_000_000;
    public const KEEP = 5;

    private static ?string $dir = null;

    /** @var array<string, mixed> */
    private static array $context = [];

    public static function configure(string $dir): void
    {
        self::$dir = rtrim($dir, '/');
    }

    /** @param array<string, mixed> $context added to every line from now on */
    public static function withContext(array $context): void
    {
        self::$context = array_merge(self::$context, $context);
    }

    public static function dir(): ?string
    {
        return self::$dir;
    }

    public static function file(string $channel = 'app'): ?string
    {
        return self::$dir === null ? null : self::$dir . '/' . preg_replace('/[^a-z0-9_-]/', '', $channel) . '.log';
    }

    /** @param array<string, mixed> $context */
    public static function error(string $message, array $context = [], string $channel = 'app'): void
    {
        self::write('ERROR', $message, $context, $channel);
    }

    /** @param array<string, mixed> $context */
    public static function warning(string $message, array $context = [], string $channel = 'app'): void
    {
        self::write('WARNING', $message, $context, $channel);
    }

    /** @param array<string, mixed> $context */
    public static function info(string $message, array $context = [], string $channel = 'app'): void
    {
        self::write('INFO', $message, $context, $channel);
    }

    /** @param array<string, mixed> $context */
    public static function write(string $level, string $message, array $context, string $channel = 'app'): void
    {
        $file = self::file($channel);
        $line = sprintf(
            "[%s] %s %s %s\n",
            gmdate('Y-m-d\TH:i:s\Z'),
            $level,
            self::mask(str_replace(["\r", "\n"], ' ', $message)),
            json_encode(self::maskArray(self::$context + $context), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
        );
        if ($file === null) {
            error_log(rtrim($line));
            return;
        }
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0775, true);
        }
        if (is_file($file) && filesize($file) > self::MAX_BYTES) {
            self::rotate($file);
        }
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    private static function rotate(string $file): void
    {
        for ($i = self::KEEP - 1; $i >= 1; $i--) {
            if (is_file("$file.$i")) {
                @rename("$file.$i", "$file." . ($i + 1));
            }
        }
        @rename($file, "$file.1");
        @unlink("$file." . (self::KEEP + 1));
    }

    private const SECRET_KEYS = '/(pass(word)?|secret|token|api[_-]?key|authorization|cookie|session|private[_-]?key|app_key|code_verifier|client_secret)/i';

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public static function maskArray(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEYS, $key)) {
                $out[$key] = '[masked]';
            } elseif (is_array($value)) {
                $out[$key] = self::maskArray($value);
            } elseif (is_string($value)) {
                $out[$key] = self::mask($value);
            } else {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /** Masks secrets that appear inline in free text. */
    public static function mask(string $text): string
    {
        $text = (string) preg_replace('/(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/i', '$1 [masked]', $text);
        $text = (string) preg_replace('/\b(mt_live_)[A-Za-z0-9_-]+/', '$1[masked]', $text);
        $text = (string) preg_replace('/((?:pass(?:word)?|secret|token|api_?key|key)\s*[=:]\s*)("?)[^\s&",;]+/i', '$1$2[masked]', $text);
        return $text;
    }

    /** @return list<string> the last $lines lines of a channel */
    public static function tail(string $channel = 'app', int $lines = 200): array
    {
        $file = self::file($channel);
        if ($file === null || !is_file($file)) {
            return [];
        }
        $size = filesize($file);
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            return [];
        }
        $chunk = min($size, 256 * 1024);
        fseek($handle, -$chunk, SEEK_END);
        $data = (string) fread($handle, $chunk);
        fclose($handle);
        $all = explode("\n", rtrim($data, "\n"));
        return array_slice($all, -$lines);
    }
}
