<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Rows out as the original API answered them: camelCase names (Prisma's
 * field names), booleans as booleans, instants as ISO-8601 with milliseconds
 * and a Z (what JSON.stringify(Date) writes), JSON columns decoded.
 *
 * Column types are read from the schema itself — every TINYINT(1) is a
 * boolean, every DATETIME an instant, every DATE a calendar day — so a
 * presenter never carries a hand-kept list that drifts from the tables.
 */
final class Json
{
    /** @var array<string, array<string, string>>|null table => column => kind */
    private static ?array $types = null;

    /** @return array<string, array<string, string>> */
    public static function types(): array
    {
        if (self::$types !== null) {
            return self::$types;
        }
        $types = [];
        foreach (glob(dirname(__DIR__) . '/Migrations/*.sql') ?: [] as $file) {
            $sql = (string) file_get_contents($file);
            if (!preg_match_all('/CREATE TABLE IF NOT EXISTS \{\{([a-z0-9_]+)\}\} \((.*?)\n\) ENGINE/s', $sql, $tables, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($tables as [, $table, $body]) {
                foreach (explode("\n", $body) as $line) {
                    if (!preg_match('/^\s*`?([a-z0-9_]+)`?\s+([A-Z]+)(\(\d+\))?/', $line, $m)) {
                        continue;
                    }
                    $kind = match (true) {
                        $m[2] === 'TINYINT' && ($m[3] ?? '') === '(1)' => 'bool',
                        in_array($m[2], ['INT', 'BIGINT', 'SMALLINT'], true) => 'int',
                        $m[2] === 'DATETIME' => 'datetime',
                        $m[2] === 'DATE' => 'date',
                        $m[2] === 'JSON' => 'json',
                        default => null,
                    };
                    if ($kind !== null && !in_array($m[1], ['PRIMARY', 'UNIQUE', 'KEY', 'CONSTRAINT', 'FULLTEXT'], true)) {
                        $types[$table][$m[1]] = $kind;
                    }
                }
            }
        }
        return self::$types = $types;
    }

    public static function camel(string $snake): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $snake))));
    }

    public static function instant(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * One row of $table, presented.
     *
     * @param array<string, mixed> $row
     * @param list<string> $omit columns never to send (password hashes, tokens)
     * @return array<string, mixed>
     */
    public static function row(string $table, array $row, array $omit = []): array
    {
        $types = self::types()[$table] ?? [];
        $out = [];
        foreach ($row as $column => $value) {
            if (in_array($column, $omit, true)) {
                continue;
            }
            $kind = $types[$column] ?? null;
            $out[self::camel((string) $column)] = match (true) {
                $value === null => null,
                $kind === 'bool' => (bool) $value,
                $kind === 'int' => (int) $value,
                $kind === 'datetime' => self::instant((string) $value),
                $kind === 'date' => substr((string) $value, 0, 10),
                $kind === 'json' => is_string($value) ? json_decode($value, true) : $value,
                default => $value,
            };
        }
        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $omit
     * @return list<array<string, mixed>>
     */
    public static function rows(string $table, array $rows, array $omit = []): array
    {
        return array_map(fn ($r) => self::row($table, $r, $omit), $rows);
    }
}
